<?php
/**
 * @package zerokyc-pay
 */

class GatewayTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		update_option(
			'woocommerce_zerokyc_pay_settings',
			array(
				'enabled'   => 'yes',
				'api_key'   => 'pk_test_key_gateway_test',
			)
		);
		update_option( 'woocommerce_currency', 'USD' );
	}

	public function test_gateway_is_registered_with_woocommerce(): void {
		$this->assertInstanceOf( ZKP_Gateway::class, zkp_test_gateway() );
	}

	public function test_unavailable_without_api_key(): void {
		update_option( 'woocommerce_zerokyc_pay_settings', array( 'enabled' => 'yes' ) );

		// A fresh instance: registered instances cache settings at construction.
		$gateway = new ZKP_Gateway();
		$this->assertFalse( $gateway->is_available() );
	}

	/**
	 * @dataProvider currency_provider
	 */
	public function test_availability_depends_on_shop_currency( string $currency, bool $expected ): void {
		update_option( 'woocommerce_currency', $currency );

		$gateway = new ZKP_Gateway();
		$this->assertSame( $expected, $gateway->is_available() );
	}

	public static function currency_provider(): array {
		return array(
			'usd' => array( 'USD', true ),
			'eur' => array( 'EUR', true ),
			'rub' => array( 'RUB', true ),
			'kzt' => array( 'KZT', false ),
			'uah' => array( 'UAH', false ),
		);
	}

	public function test_webhook_url_falls_back_without_pretty_permalinks(): void {
		$this->set_permalink_structure( '' );
		$url = ZKP_Gateway::webhook_url();
		$this->assertStringContainsString( 'rest_route=/zkp/v1/webhook', $url );
	}

	public function test_webhook_url_pretty(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$url = ZKP_Gateway::webhook_url();
		$this->assertStringEndsWith( '/wp-json/zkp/v1/webhook', $url );
	}

	public function test_sdk_derives_sandbox_from_key_prefix(): void {
		$zkp = ZKP_Gateway::sdk();
		$this->assertTrue( $zkp->config->isSandbox() );
		$this->assertSame( 'https://api.zerokyc-payments.com', $zkp->config->baseUrl );
	}

	public function test_sdk_rejects_environment_mismatch(): void {
		update_option(
			'woocommerce_zerokyc_pay_settings',
			array( 'enabled' => 'yes', 'api_key' => 'pk_live_key_gateway_test' )
		);

		$zkp = ZKP_Gateway::sdk();
		$this->assertFalse( $zkp->config->isSandbox() );
	}
}
