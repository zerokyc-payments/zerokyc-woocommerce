<?php
/**
 * @package zerokyc-pay
 */

class CronTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		ZKP_Event_Store::create_table();
		ZKP_Invoice_Map::create_table();
		update_option(
			'woocommerce_zerokyc_pay_settings',
			array( 'enabled' => 'yes', 'api_key' => 'pk_test_key_cron' )
		);
	}

	private function make_onhold_order( string $invoice_id ): WC_Order {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '50.00' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->set_currency( 'USD' );
		$order->set_address( array( 'email' => 'buyer@example.test' ), 'billing' );
		$order->calculate_totals();
		$order->update_status( 'on-hold', 'setup' );
		$order->update_meta_data( '_zkp_invoice_id', $invoice_id );
		$order->update_meta_data( '_zkp_created', time() - 5 * MINUTE_IN_SECONDS );
		$order->save();

		ZKP_Invoice_Map::remember( $invoice_id, $order->get_id() );

		return $order;
	}

	public function test_add_schedule_registers_interval(): void {
		$schedules = ZKP_Cron::add_schedule( array() );
		$this->assertArrayHasKey( 'zkp_15min', $schedules );
		$this->assertSame( 15 * MINUTE_IN_SECONDS, $schedules['zkp_15min']['interval'] );
	}

	public function test_ensure_schedule_schedules_when_enabled(): void {
		wp_clear_scheduled_hook( ZKP_Cron::HOOK );

		ZKP_Cron::ensure_schedule();

		$this->assertNotFalse( wp_next_scheduled( ZKP_Cron::HOOK ) );
	}

	public function test_ensure_schedule_skips_when_disabled(): void {
		update_option( 'woocommerce_zerokyc_pay_settings', array( 'enabled' => 'no' ) );
		wp_clear_scheduled_hook( ZKP_Cron::HOOK );

		ZKP_Cron::ensure_schedule();

		$this->assertFalse( wp_next_scheduled( ZKP_Cron::HOOK ) );
	}

	public function test_poll_completes_order_when_invoice_confirmed(): void {
		$order = $this->make_onhold_order( 'inv_cron_001' );

		add_filter(
			'pre_http_request',
			static function ( $short, array $args, string $url ) {
				if ( str_contains( $url, '/v1/invoices/inv_cron_001' ) ) {
					return array(
						'headers'  => array( 'Content-Type' => 'application/json' ),
						'body'     => (string) wp_json_encode(
							array(
								'id'            => 'inv_cron_001',
								'status'        => 'confirmed',
								'amount'        => '50.00',
								'base_currency' => 'USD',
								'paid_amount'   => '50.0',
								'paid_asset'    => 'USDT_TRON',
							)
						),
						'response' => array( 'code' => 200, 'message' => 'OK' ),
						'cookies'  => array(),
						'filename' => null,
					);
				}
				return $short;
			},
			10,
			3
		);

		ZKP_Cron::poll();

		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->is_paid() );
	}

	public function test_poll_swallows_network_errors(): void {
		$order = $this->make_onhold_order( 'inv_cron_down' );

		add_filter( 'pre_http_request', static fn() => new WP_Error( 'http_request_failed', 'dns' ) );

		ZKP_Cron::poll(); // must not throw

		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->has_status( 'on-hold' ) );
	}
}
