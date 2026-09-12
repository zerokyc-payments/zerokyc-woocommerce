<?php
/**
 * @package zerokyc-pay
 */

class ProcessPaymentTest extends WP_UnitTestCase {

	private const API = 'https://api.zerokyc-payments.com';

	protected function setUp(): void {
		parent::setUp();
		ZKP_Event_Store::create_table();
		ZKP_Invoice_Map::create_table();
		update_option(
			'woocommerce_zerokyc_pay_settings',
			array(
				'enabled'    => 'yes',
				'api_key'    => 'pk_test_key_process_payment',
				'ttl_minutes' => 60,
			)
		);
		update_option( 'woocommerce_currency', 'USD' );
		WC()->session = WC()->session ?: new ZKP_Test_Session();
	}

	private function make_order(): WC_Order {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '19.90' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->set_currency( 'USD' );
		$order->set_address( array( 'email' => 'buyer@example.test' ), 'billing' );
		$order->calculate_totals();
		$order->save();

		return $order;
	}

	/**
	 * @var array<int,array<string,mixed>> Outbound requests captured by the mock.
	 */
	private array $captured = array();

	/**
	 * Intercepts outbound HTTP with a canned invoice response.
	 */
	private function mock_invoice_response( array $invoice_overrides = array(), array $response_overrides = array() ): void {
		add_filter(
			'pre_http_request',
			function ( $short, array $args, string $url ) use ( $invoice_overrides, $response_overrides ) {
				$this->captured[] = array( 'args' => $args, 'url' => $url );

				$invoice = array_merge(
					array(
						'id'               => 'inv_test_001',
						'order_id'         => 'example.org-' . (string) ( $GLOBALS['zkp_expected_order_id'] ?? 0 ),
						'amount'           => '19.90',
						'base_currency'    => 'USD',
						'payment_currency' => 'any',
						'status'           => 'created',
						'ttl_minutes'      => 60,
						'expires_at'       => gmdate( 'c', time() + 3600 ),
						'created_at'       => gmdate( 'c', time() ),
						'checkout_url'     => 'https://checkout.zerokyc-payments.test/i/inv_test_001',
						'options'          => array(),
					),
					$invoice_overrides
				);

				// GET /v1/invoices/{id} must answer 200; POST /v1/invoices is 201.
				$code = ( 'GET' === $args['method'] ) ? 200 : 201;

				return array_merge(
					array(
						'headers'  => array( 'Content-Type' => 'application/json' ),
						'body'     => (string) wp_json_encode( $invoice ),
						'response' => array( 'code' => $code, 'message' => 'OK' ),
						'cookies'  => array(),
						'filename' => null,
					),
					$response_overrides
				);
			},
			10,
			3
		);
	}

	public function test_creates_invoice_and_redirects(): void {
		$this->mock_invoice_response();
		$order    = $this->make_order();
		$GLOBALS['zkp_expected_order_id'] = $order->get_id();

		$gateway = zkp_test_gateway();
		$result  = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'https://checkout.zerokyc-payments.test/i/inv_test_001', $result['redirect'] );

		// One POST to the invoices endpoint with an idempotency key.
		$posts = array_filter(
			$this->captured,
			static fn( $c ) => str_contains( $c['url'], '/v1/invoices' ) && 'POST' === $c['args']['method']
		);
		$this->assertCount( 1, $posts );
		$first = array_values( $posts )[0];
		$this->assertStringStartsWith( 'zerokyc:woocommerce:order:', (string) ( $first['args']['headers']['Idempotency-Key'] ?? '' ) );

		// Order state + metadata.
		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->has_status( 'on-hold' ) );
		$this->assertSame( 'inv_test_001', $order->get_meta( '_zkp_invoice_id' ) );
		$this->assertSame( 'https://checkout.zerokyc-payments.test/i/inv_test_001', $order->get_meta( '_zkp_checkout_url' ) );
		$this->assertSame( 1, (int) $order->get_meta( '_zkp_seq' ) );

		$body = json_decode( (string) $first['args']['body'], true );
		$this->assertSame( '19.90', $body['amount'] );
		$this->assertSame( 'USD', $body['base_currency'] );
		$this->assertSame( 'any', $body['payment_currency'] );
		$this->assertArrayHasKey( 'webhook_url', $body );
		$this->assertArrayHasKey( 'success_url', $body );

		unset( $GLOBALS['zkp_expected_order_id'] );
	}

	public function test_network_failure_returns_failure_result(): void {
		add_filter(
			'pre_http_request',
			static fn() => new WP_Error( 'http_request_failed', 'Connection refused.' )
		);

		$order = $this->make_order();

		$gateway = zkp_test_gateway();
		$result  = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertNotEmpty( wc_get_notices( 'error' ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'pending', $order->get_status() );
	}

	public function test_reuses_live_invoice_without_second_post(): void {
		$this->mock_invoice_response(
			array(
				'id'           => 'inv_test_reuse',
				'status'       => 'pending',
				'checkout_url' => 'https://checkout.zerokyc-payments.test/i/inv_test_reuse',
			)
		);
		$order = $this->make_order();
		$GLOBALS['zkp_expected_order_id'] = $order->get_id();

		$gateway = zkp_test_gateway();

		$first = $gateway->process_payment( $order->get_id() );
		$this->assertSame( 'success', $first['result'] );

		// Second call: the canned response stays a GET-shaped pending invoice,
		// so the gateway must reuse it and NOT issue another POST.
		$second = $gateway->process_payment( $order->get_id() );
		$this->assertSame( 'https://checkout.zerokyc-payments.test/i/inv_test_reuse', $second['redirect'] );

		$posts = array_filter(
			$this->captured,
			static fn( $c ) => str_contains( $c['url'], '/v1/invoices' ) && 'POST' === $c['args']['method']
		);
		$this->assertCount( 1, $posts );

		$gets = array_filter(
			$this->captured,
			static fn( $c ) => str_contains( $c['url'], '/v1/invoices/inv_test_reuse' ) && 'GET' === $c['args']['method']
		);
		$this->assertCount( 1, $gets );

		unset( $GLOBALS['zkp_expected_order_id'] );
	}

	public function test_expired_invoice_leads_to_new_seq(): void {
		$this->mock_invoice_response(
			array(
				'id'     => 'inv_test_expired',
				'status' => 'expired',
				'expires_at' => gmdate( 'c', time() - 60 ),
			)
		);
		$order = $this->make_order();
		$GLOBALS['zkp_expected_order_id'] = $order->get_id();

		$order->update_meta_data( '_zkp_invoice_id', 'inv_test_expired' );
		$order->update_meta_data( '_zkp_seq', 1 );
		$order->update_status( 'on-hold', 'old invoice', true );

		$gateway = zkp_test_gateway();
		$result  = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 2, (int) $order->get_meta( '_zkp_seq' ) );

		unset( $GLOBALS['zkp_expected_order_id'] );
	}
}
