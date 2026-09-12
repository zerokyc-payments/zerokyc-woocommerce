<?php
/**
 * @package zerokyc-pay
 */

use ZeroKYC\Webhook\WebhookEvent;
use ZeroKYC\Webhook\WebhookVerifier;

class WebhookTest extends WP_UnitTestCase {

	private const SECRET = 'whsec_test_secret_webhook';

	protected function setUp(): void {
		parent::setUp();
		ZKP_Event_Store::create_table();
		ZKP_Invoice_Map::create_table();
		update_option(
			'woocommerce_zerokyc_pay_settings',
			array(
				'enabled'        => 'yes',
				'api_key'        => 'pk_test_key_webhook',
				'webhook_secret' => self::SECRET,
				'double_check'   => 'no',
			)
		);

		// Route registration must go through the proper action.
		do_action( 'rest_api_init', rest_get_server() );
	}

	private function make_order( string $invoice_id, string $asset = '', string $amount_crypto = '' ): WC_Order {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '19.90' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->set_currency( 'USD' );
		$order->set_address( array( 'email' => 'buyer@example.test' ), 'billing' );
		$order->calculate_totals();
		$order->update_status( 'on-hold', 'awaiting crypto' );
		ZKP_Invoice_Map::remember( $invoice_id, $order->get_id() );
		$order->update_meta_data( '_zkp_invoice_id', $invoice_id );
		if ( '' !== $asset ) {
			$order->update_meta_data( '_zkp_asset', $asset );
		}
		if ( '' !== $amount_crypto ) {
			$order->update_meta_data( '_zkp_amount_crypto', $amount_crypto );
		}
		$order->save();

		return $order;
	}

	private function dispatch( string $raw_body, string $signature ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/zkp/v1/webhook' );
		$request->set_header( 'X-Zkp-Signature', $signature );
		$request->set_body( $raw_body );

		return rest_get_server()->dispatch( $request );
	}

	private static function confirmed_body( string $invoice_id, string $asset = 'USDT_TRON', string $amount = '20.0' ): string {
		return (string) wp_json_encode(
			array(
				'id'         => 'evt_' . uniqid( '', false ),
				'type'       => 'payment.confirmed',
				'invoice_id' => $invoice_id,
				'data'       => array(
					'invoice_id' => $invoice_id,
					'amount'     => $amount,
					'asset'      => $asset,
				),
			)
		);
	}

	public function test_signed_confirmed_event_completes_order(): void {
		$order      = $this->make_order( 'inv_hook_001' );
		$body       = self::confirmed_body( 'inv_hook_001' );
		$signature  = ( new WebhookVerifier( self::SECRET ) )->sign( $body );

		$response = $this->dispatch( $body, $signature );

		$this->assertSame( 200, $response->get_status() );
		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( 'USDT_TRON', $order->get_meta( '_zkp_paid_asset' ) );
	}

	public function test_duplicate_event_id_is_skipped(): void {
		$order = $this->make_order( 'inv_hook_002' );
		$body  = str_replace( 'evt_', 'evt_dedup_', self::confirmed_body( 'inv_hook_002' ) );
		$sig   = ( new WebhookVerifier( self::SECRET ) )->sign( $body );

		$first  = $this->dispatch( $body, $sig );
		$second = $this->dispatch( $body, $sig );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 200, $second->get_status() );

		// payment_complete ran exactly once: only one confirmation note.
		$notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static fn( $note ) => str_contains( $note->content, 'payment confirmed' )
		);
		$this->assertCount( 1, $notes );
	}

	public function test_bad_signature_rejected_with_400(): void {
		$order     = $this->make_order( 'inv_hook_003' );
		$body      = self::confirmed_body( 'inv_hook_003' );
		$signature = 't=' . time() . ',v1=' . str_repeat( 'a', 64 );

		$response = $this->dispatch( $body, $signature );

		$this->assertSame( 400, $response->get_status() );
		$order = wc_get_order( $order->get_id() );
		$this->assertFalse( $order->is_paid() );
	}

	public function test_missing_signature_header_rejected(): void {
		$this->make_order( 'inv_hook_004' );

		$request = new WP_REST_Request( 'POST', '/zkp/v1/webhook' );
		$request->set_body( self::confirmed_body( 'inv_hook_004' ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_missing_secret_returns_503(): void {
		update_option( 'woocommerce_zerokyc_pay_settings', array( 'enabled' => 'yes' ) );
		$this->make_order( 'inv_hook_005' );

		$body = self::confirmed_body( 'inv_hook_005' );
		$sig  = ( new WebhookVerifier( 'whatever' ) )->sign( $body );

		$response = $this->dispatch( $body, $sig );
		$this->assertSame( 503, $response->get_status() );
	}

	public function test_pinned_asset_mismatch_does_not_complete_order(): void {
		// Order pinned to USDT_TRON with a minimum crypto amount.
		$order = $this->make_order( 'inv_hook_006', 'USDT_TRON', '20.0' );
		// ...but the payment arrives in TON.
		$body = self::confirmed_body( 'inv_hook_006', 'TON', '15.0' );
		$sig  = ( new WebhookVerifier( self::SECRET ) )->sign( $body );

		$response = $this->dispatch( $body, $sig );

		$this->assertSame( 200, $response->get_status() );
		$order = wc_get_order( $order->get_id() );
		$this->assertFalse( $order->is_paid() );
	}

	public function test_expired_event_cancels_onhold_order(): void {
		$order = $this->make_order( 'inv_hook_007' );

		$body = (string) wp_json_encode(
			array(
				'id'         => 'evt_expire_' . uniqid( '', false ),
				'type'       => 'invoice.expired',
				'invoice_id' => 'inv_hook_007',
				'data'       => array( 'invoice_id' => 'inv_hook_007' ),
			)
		);
		$sig = ( new WebhookVerifier( self::SECRET ) )->sign( $body );

		$response = $this->dispatch( $body, $sig );

		$this->assertSame( 200, $response->get_status() );
		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->has_status( 'cancelled' ) );
	}
}
