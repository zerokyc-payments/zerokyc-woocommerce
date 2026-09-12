<?php
/**
 * @package zerokyc-pay
 */

use ZeroKYC\Invoice\Invoice;
use ZeroKYC\Webhook\WebhookEvent;

class OrderServiceTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		ZKP_Event_Store::create_table();
		ZKP_Invoice_Map::create_table();
		update_option(
			'woocommerce_zerokyc_pay_settings',
			array(
				'enabled'      => 'yes',
				'api_key'      => 'pk_test_key_order_service',
				'auto_cancel'  => 'yes',
				'double_check' => 'no',
			)
		);
	}

	private function make_order( string $invoice_id = 'inv_os_001' ): WC_Order {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '10.00' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->set_currency( 'USD' );
		$order->calculate_totals();
		$order->update_status( 'on-hold', 'setup' );
		$order->update_meta_data( '_zkp_invoice_id', $invoice_id );
		$order->save();

		ZKP_Invoice_Map::remember( $invoice_id, $order->get_id() );

		return $order;
	}

	private function event( string $type, string $invoice_id, array $data = array() ): WebhookEvent {
		return WebhookEvent::fromArray(
			array(
				'id'         => 'evt_os_' . uniqid( '', false ),
				'type'       => $type,
				'invoice_id' => $invoice_id,
				'data'       => $data + array( 'invoice_id' => $invoice_id ),
			)
		);
	}

	private static function invoice( string $status, string $invoice_id = 'inv_os_001', array $overrides = array() ): Invoice {
		return Invoice::fromArray(
			$overrides + array(
				'id'            => $invoice_id,
				'status'        => $status,
				'amount'        => '10.00',
				'base_currency' => 'USD',
				'checkout_url'  => 'https://checkout.example.test/i/' . $invoice_id,
				'expires_at'    => gmdate( 'c', time() + 3600 ),
				'created_at'    => gmdate( 'c', time() ),
			)
		);
	}

	public function test_find_by_invoice(): void {
		$order = $this->make_order( 'inv_find_me' );

		$found = ZKP_Order_Service::find_by_invoice( 'inv_find_me' );
		$this->assertInstanceOf( WC_Order::class, $found );
		$this->assertSame( $order->get_id(), $found->get_id() );

		$this->assertNull( ZKP_Order_Service::find_by_invoice( 'inv_unknown' ) );
	}

	public function test_underpaid_event_keeps_order_on_hold_and_notes(): void {
		$order = $this->make_order();

		ZKP_Order_Service::handle_event(
			$this->event( 'payment.underpaid', 'inv_os_001', array( 'amount' => '9.0', 'asset' => 'USDT_TRON' ) )
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->has_status( 'on-hold' ) );
		$this->assertFalse( $order->is_paid() );
	}

	public function test_detected_event_notes_once(): void {
		$order = $this->make_order( 'inv_os_detect' );

		ZKP_Order_Service::handle_event( $this->event( 'payment.detected', 'inv_os_detect' ) );
		ZKP_Order_Service::handle_event( $this->event( 'payment.detected', 'inv_os_detect' ) );

		$notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static fn( $note ) => str_contains( $note->content, 'awaiting confirmations' )
		);
		$this->assertCount( 1, $notes );
	}

	public function test_confirmed_event_with_unknown_invoice_logs_and_skips(): void {
		$order = $this->make_order();

		ZKP_Order_Service::handle_event(
			$this->event( 'payment.confirmed', 'inv_someone_else', array( 'amount' => '10.0', 'asset' => 'USDT_TRON' ) )
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertFalse( $order->is_paid() );
	}

	public function test_double_check_blocks_when_invoice_not_paid_server_side(): void {
		update_option(
			'woocommerce_zerokyc_pay_settings',
			array(
				'enabled'      => 'yes',
				'api_key'      => 'pk_test_key_order_service',
				'double_check' => 'yes',
			)
		);

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'headers'  => array( 'Content-Type' => 'application/json' ),
					'body'     => (string) wp_json_encode(
						array(
							'id'            => 'inv_os_001',
							'status'        => 'detecting',
							'amount'        => '10.00',
							'base_currency' => 'USD',
						)
					),
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);

		$order = $this->make_order();
		ZKP_Order_Service::handle_event(
			$this->event( 'payment.confirmed', 'inv_os_001', array( 'amount' => '10.0', 'asset' => 'USDT_TRON' ) )
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertFalse( $order->is_paid() );
	}

	public function test_double_check_passes_when_invoice_paid_server_side(): void {
		update_option(
			'woocommerce_zerokyc_pay_settings',
			array(
				'enabled'      => 'yes',
				'api_key'      => 'pk_test_key_order_service',
				'double_check' => 'yes',
			)
		);

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'headers'  => array( 'Content-Type' => 'application/json' ),
					'body'     => (string) wp_json_encode(
						array(
							'id'            => 'inv_os_001',
							'status'        => 'confirmed',
							'amount'        => '10.00',
							'base_currency' => 'USD',
						)
					),
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);

		$order = $this->make_order();
		ZKP_Order_Service::handle_event(
			$this->event( 'payment.confirmed', 'inv_os_001', array( 'amount' => '10.0', 'asset' => 'USDT_TRON' ) )
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->is_paid() );
	}

	public function test_auto_cancel_disabled_keeps_expired_order_open(): void {
		update_option(
			'woocommerce_zerokyc_pay_settings',
			array(
				'enabled'     => 'yes',
				'api_key'     => 'pk_test_key_order_service',
				'auto_cancel' => 'no',
			)
		);

		$order = $this->make_order();
		ZKP_Order_Service::handle_event( $this->event( 'invoice.expired', 'inv_os_001' ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->has_status( 'on-hold' ) );
	}

	public function test_apply_invoice_confirmed_completes_order(): void {
		$order = $this->make_order();

		ZKP_Order_Service::apply_invoice(
			$order,
			self::invoice( 'confirmed', 'inv_os_001', array( 'paid_amount' => '10.0', 'paid_asset' => 'USDT_TRON' ) )
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->is_paid() );
	}

	public function test_apply_invoice_expired_cancels_order(): void {
		$order = $this->make_order();

		ZKP_Order_Service::apply_invoice( $order, self::invoice( 'expired', 'inv_os_001' ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->has_status( 'cancelled' ) );
	}
}
