<?php

declare( strict_types=1 );

namespace ZeroKYC\Tests\WP;

use WP_UnitTestCase;
use ZEROKYC_Asset_Repository;
use ZEROKYC_Gateway;

final class AssetRepositoryTest extends WP_UnitTestCase {

	private int $http_calls = 0;

	protected function setUp(): void {
		parent::setUp();
		delete_transient( 'zerokyc_assets' );
		delete_option( 'zerokyc_assets_fallback' );
		$this->http_calls = 0;
		update_option(
			'woocommerce_zerokyc_pay_settings',
			array( 'enabled' => 'yes', 'api_key' => 'pk_test_demo', 'payment_currency' => 'any' ),
		);
	}

	private function mock_assets_endpoint( ?array $payload ): void {
		add_filter(
			'pre_http_request',
			function ( $short, array $args, string $url ) use ( $payload ) {
				if ( false === strpos( $url, '/v1/assets' ) ) {
					return $short;
				}
				++$this->http_calls;
				if ( null === $payload ) {
					return array( 'response' => array( 'code' => 503 ), 'body' => '{}' );
				}
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'data' => $payload ) ),
				);
			},
			10,
			3
		);
	}

	public function test_select_is_built_from_the_api_catalog(): void {
		$this->mock_assets_endpoint( array(
			array( 'asset_id' => 'USDT_TRON', 'ticker' => 'USDT', 'display' => 'USDT (TRC-20)' ),
			array( 'asset_id' => 'XRP', 'ticker' => 'XRP', 'display' => 'XRP' ),
			// a coin the plugin snapshot has never heard of must still appear
			array( 'asset_id' => 'FUTURE_COIN', 'ticker' => 'FUTURE', 'display' => 'Future Coin (Test)' ),
		) );

		$list = ZEROKYC_Asset_Repository::payment_currencies( true );

		self::assertSame( 'any', array_key_first( $list ) );
		self::assertSame( 'USDT (TRC-20)', $list['USDT_TRON'] );
		self::assertSame( 'Future Coin (Test)', $list['FUTURE_COIN'] );
		self::assertStringContainsString( 'FUTURE', $list['any'] );
		self::assertSame( 1, $this->http_calls );
	}

	public function test_transient_caches_the_catalog(): void {
		$this->mock_assets_endpoint( array(
			array( 'asset_id' => 'XRP', 'ticker' => 'XRP', 'display' => 'XRP' ),
		) );

		ZEROKYC_Asset_Repository::payment_currencies( true );
		ZEROKYC_Asset_Repository::payment_currencies();
		ZEROKYC_Asset_Repository::payment_currencies();

		self::assertSame( 1, $this->http_calls, 'cached reads must not hit HTTP' );
	}

	public function test_api_failure_falls_back_to_last_known_good_then_seed(): void {
		// seed the persistent fallback with a previous good fetch…
		update_option(
			'zerokyc_assets_fallback',
			array( 'any' => 'Let the buyer choose', 'XRP' => 'XRP' ),
			false,
		);
		$this->mock_assets_endpoint( null ); // 503

		$list = ZEROKYC_Asset_Repository::payment_currencies( true );
		self::assertSame( 'XRP', $list['XRP'] );

		// …and with no persisted history at all, the compiled snapshot answers
		delete_transient( 'zerokyc_assets' );
		delete_option( 'zerokyc_assets_fallback' );
		$list = ZEROKYC_Asset_Repository::payment_currencies( true );
		self::assertArrayHasKey( 'any', $list );
		self::assertArrayHasKey( 'USDT_TRON', $list );
		self::assertSame( ZEROKYC_Gateway::FALLBACK_PAYMENT_CURRENCIES['USDT_TRON'], $list['USDT_TRON'] );
	}

	public function test_sanitize_resets_disappeared_asset_to_any(): void {
		$this->mock_assets_endpoint( array(
			array( 'asset_id' => 'USDT_TRON', 'ticker' => 'USDT', 'display' => 'USDT (TRC-20)' ),
		) );
		update_option(
			'woocommerce_zerokyc_pay_settings',
			array( 'enabled' => 'yes', 'api_key' => 'pk_test_demo', 'payment_currency' => 'GONE_COIN' ),
		);

		$gateway = new ZEROKYC_Gateway();
		// emulate the WC save flow enough for the sanitize hook to run
		$_POST['woocommerce_zerokyc_pay_enabled'] = 'yes';
		$gateway->process_admin_options();

		$settings = ZEROKYC_Gateway::settings();
		self::assertSame( 'any', $settings['payment_currency'] );
	}
}
