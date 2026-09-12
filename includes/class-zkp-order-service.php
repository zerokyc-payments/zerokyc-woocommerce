<?php
/**
 * Order state transitions driven by webhook events and invoice polls.
 *
 * @package zerokyc-pay
 */

defined( 'ABSPATH' ) || exit;

use ZeroKYC\Invoice\Invoice;
use ZeroKYC\Invoice\InvoiceStatus;
use ZeroKYC\Webhook\ReplayGuard;
use ZeroKYC\Webhook\WebhookEvent;

final class ZKP_Order_Service {

	/**
	 * Finds the local order for an invoice id via the mapping table.
	 */
	public static function find_by_invoice( string $invoice_id ): ?WC_Order {
		if ( '' === $invoice_id ) {
			return null;
		}
		$order_id = ZKP_Invoice_Map::order_id_for( $invoice_id );
		if ( null === $order_id ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Dispatches a verified webhook event to the order state machine.
	 */
	public static function handle_event( WebhookEvent $event ): void {
		switch ( $event->type ) {
			case 'payment.confirmed':
				self::confirm_from_event( $event );
				return;

			case 'payment.underpaid':
				$order = self::find_by_invoice( (string) $event->invoiceId );
				if ( null !== $order && ! $order->is_paid() ) {
					self::note(
						$order,
						sprintf(
							/* translators: 1: paid amount, 2: asset */
							__( 'ZeroKYC: UNDERPAID — received %1$s %2$s, less than invoiced. Order kept on-hold; resolve manually or ask the buyer to top up.', 'zerokyc-pay' ),
							(string) ( $event->paidAmount() ?? '?' ),
							(string) ( $event->paidAsset() ?? '' )
						)
					);
				}
				return;

			case 'payment.detected':
				$order = self::find_by_invoice( (string) $event->invoiceId );
				if ( null !== $order ) {
					self::note(
						$order,
						__( 'ZeroKYC: payment detected on-chain, awaiting confirmations.', 'zerokyc-pay' ),
						'detected_' . $event->invoiceId
					);
				}
				return;

			case 'invoice.expired':
			case 'invoice.canceled':
				self::maybe_cancel( (string) $event->invoiceId );
				return;

			default:
				ZKP_Logger::debug( sprintf( 'event %s (%s) ignored', $event->id, $event->type ) );
		}
	}

	/**
	 * Applies an invoice snapshot (cron poller / checkout re-entry) to the order.
	 */
	public static function apply_invoice( WC_Order $order, Invoice $invoice ): void {
		switch ( $invoice->status ) {
			case InvoiceStatus::PAID:
				if ( ! $order->is_paid() ) {
					self::complete( $order, (string) ( $invoice->paidAsset ?? '' ), (string) ( $invoice->paidAmount ?? '' ), null );
				}
				return;

			case InvoiceStatus::CONFIRMING:
				self::note(
					$order,
					__( 'ZeroKYC: payment detected on-chain, awaiting confirmations.', 'zerokyc-pay' ),
					'detected_' . $invoice->id
				);
				return;

			case InvoiceStatus::UNDERPAID:
				if ( ! $order->is_paid() && ! $order->has_status( 'on-hold' ) ) {
					$order->update_status( 'on-hold', __( 'ZeroKYC: underpaid, kept on-hold.', 'zerokyc-pay' ) );
				}
				return;

			case InvoiceStatus::EXPIRED:
			case InvoiceStatus::CANCELLED:
				if ( $order->get_meta( '_zkp_invoice_id' ) === $invoice->id ) {
					self::maybe_cancel( $invoice->id );
				}
				return;

			case InvoiceStatus::PENDING:
			default:
				return;
		}
	}

	/**
	 * Marks the order paid after the event passes invoice/amount/asset matching
	 * (and the optional server-side double check).
	 */
	private static function confirm_from_event( WebhookEvent $event ): void {
		$order = self::find_by_invoice( (string) $event->invoiceId );
		if ( null === $order ) {
			ZKP_Logger::alert( sprintf( 'event %s: no local order for invoice %s', $event->id, (string) $event->invoiceId ) );
			return;
		}
		if ( $order->is_paid() ) {
			ZKP_Logger::debug( sprintf( 'event %s: order %d already paid', $event->id, $order->get_id() ) );
			return;
		}

		$settings   = ZKP_Gateway::settings();
		$min_amount = (string) $order->get_meta( '_zkp_amount_crypto' );
		$asset      = (string) $order->get_meta( '_zkp_asset' );

		$guard = new ReplayGuard( new ZKP_Event_Store() );
		if ( ! $guard->matchesOrder(
			$event,
			(string) $event->invoiceId,
			'' !== $min_amount ? $min_amount : null,
			'' !== $asset ? $asset : null
		) ) {
			ZKP_Logger::alert(
				sprintf(
					'event %s: invoice/amount/asset mismatch for order %d — NOT marked paid, investigate',
					$event->id,
					$order->get_id()
				)
			);
			return;
		}

		if ( 'yes' === ( $settings['double_check'] ?? 'yes' ) ) {
			try {
				$invoice = ZKP_Gateway::sdk()->getInvoice( (string) $event->invoiceId );
			} catch ( ZeroKYC\Exception\ZeroKYCException $e ) {
				ZKP_Logger::alert(
					sprintf( 'event %s: double-check fetch failed (%s) — order %d left on-hold', $event->id, $e->getMessage(), $order->get_id() )
				);
				return;
			}
			if ( ! $invoice->isPaid() ) {
				ZKP_Logger::alert(
					sprintf( 'event %s: invoice %s not confirmed server-side — order %d left on-hold', $event->id, (string) $event->invoiceId, $order->get_id() )
				);
				return;
			}
		}

		$txid = null;
		if ( isset( $event->data['txid'] ) && is_string( $event->data['txid'] ) ) {
			$txid = $event->data['txid'];
		} elseif ( isset( $event->data['tx_hash'] ) && is_string( $event->data['tx_hash'] ) ) {
			$txid = $event->data['tx_hash'];
		}

		self::complete( $order, (string) ( $event->paidAsset() ?? '' ), (string) ( $event->paidAmount() ?? '' ), $txid );
	}

	/**
	 * Final paid transition + evidence metadata.
	 */
	private static function complete( WC_Order $order, string $asset, string $amount, ?string $txid ): void {
		if ( '' !== $asset ) {
			$order->update_meta_data( '_zkp_paid_asset', $asset );
		}
		if ( '' !== $amount ) {
			$order->update_meta_data( '_zkp_paid_amount', $amount );
		}
		if ( null !== $txid && '' !== $txid ) {
			$order->update_meta_data( '_zkp_paid_txid', $txid );
		}

		$note = sprintf(
			/* translators: 1: amount, 2: asset */
			__( 'ZeroKYC: payment confirmed — %1$s %2$s received.', 'zerokyc-pay' ),
			( '' !== $amount ? $amount : '?' ),
			$asset
		);
		if ( null !== $txid && '' !== $txid ) {
			$note .= ' ' . sprintf( 'tx: %s', $txid );
		}

		$order->add_order_note( $note );
		$order->payment_complete( $txid );
		$order->save();
	}

	/**
	 * Cancels the unpaid order for an expired/cancelled invoice when the
	 * auto-cancel setting is on.
	 */
	private static function maybe_cancel( string $invoice_id ): void {
		$settings = ZKP_Gateway::settings();
		if ( ( $settings['auto_cancel'] ?? 'yes' ) !== 'yes' ) {
			return;
		}

		$order = self::find_by_invoice( $invoice_id );
		if ( null === $order || $order->is_paid() || $order->has_status( array( 'cancelled', 'completed', 'processing', 'refunded' ) ) ) {
			return;
		}

		$order->update_status( 'cancelled', __( 'ZeroKYC: invoice expired or was cancelled.', 'zerokyc-pay' ) );
	}

	/**
	 * Adds an order note once per dedupe key.
	 */
	private static function note( WC_Order $order, string $message, ?string $dedupe_key = null ): void {
		if ( null !== $dedupe_key && '' !== $dedupe_key ) {
			$meta_key = '_zkp_noted_' . md5( $dedupe_key );
			if ( $order->get_meta( $meta_key ) ) {
				return;
			}
			$order->update_meta_data( $meta_key, 1 );
		}
		$order->add_order_note( $message );
		$order->save();
	}
}
