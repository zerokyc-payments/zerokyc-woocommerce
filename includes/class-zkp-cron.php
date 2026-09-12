<?php
/**
 * Safety-net polling for orders whose webhooks were lost.
 *
 * @package zerokyc-pay
 */

defined( 'ABSPATH' ) || exit;

final class ZKP_Cron {

	public const HOOK    = 'zkp_poll_pending';
	public const SLUG    = 'zkp_15min';
	public const WINDOW  = 24 * HOUR_IN_SECONDS;
	private const BATCH  = 50;
	private const STATUS = array( 'pending', 'on-hold', 'failed' );

	/**
	 * Registers the 15-minute interval with WP-Cron.
	 *
	 * @param array<string, array<string,mixed>> $schedules Existing cron schedules.
	 * @return array<string, array<string,mixed>>
	 */
	public static function add_schedule( array $schedules ): array {
		$schedules[ self::SLUG ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (ZeroKYC Pay)', 'zerokyc-pay' ),
		);
		return $schedules;
	}

	/**
	 * Keeps the schedule alive while the gateway is enabled.
	 */
	public static function ensure_schedule(): void {
		$settings = (array) get_option( 'woocommerce_zerokyc_pay_settings', array() );
		if ( ( $settings['enabled'] ?? 'no' ) !== 'yes' ) {
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::SLUG, self::HOOK );
		}
	}

	/**
	 * Polls open invoices and applies status transitions, mirroring webhooks.
	 */
	public static function poll(): void {
		$candidates = ZKP_Invoice_Map::order_ids_since( gmdate( 'Y-m-d H:i:s', time() - self::WINDOW ) );
		if ( array() === $candidates ) {
			return;
		}
		$candidates = array_slice( $candidates, 0 - self::BATCH );

		try {
			$zkp = ZKP_Gateway::sdk();
		} catch ( RuntimeException $e ) {
			ZKP_Logger::warning( 'cron: cannot build SDK client - ' . $e->getMessage() );
			return;
		}

		foreach ( $candidates as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order || ! $order->has_status( self::STATUS ) ) {
				continue;
			}
			$invoice_id = (string) $order->get_meta( '_zkp_invoice_id' );
			if ( '' === $invoice_id ) {
				continue;
			}

			try {
				ZKP_Order_Service::apply_invoice( $order, $zkp->getInvoice( $invoice_id ) );
			} catch ( ZeroKYC\Exception\NetworkException $e ) {
				continue; // next tick retries
			} catch ( ZeroKYC\Exception\ZeroKYCException $e ) {
				ZKP_Logger::warning( sprintf( 'cron: invoice %s - %s', $invoice_id, $e->getMessage() ) );
			}
		}
	}
}
