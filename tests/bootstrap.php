<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite, WooCommerce, and the plugin.
 *
 * @package zerokyc-pay
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	echo "\nWP_TESTS_DIR is not set. Run tests via: docker compose run --rm tooling phpunit\n";
	exit( 1 );
}

// Give access to tests_add_filter() and the WP factory.
require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		// WooCommerce first: the plugin's boot() requires WC_Payment_Gateway.
		require '/opt/plugins/woocommerce/woocommerce.php';

		// The plugin under test (its main file boots the hooks).
		require dirname( __DIR__ ) . '/zerokyc-pay.php';
	}
);

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';

// Install WooCommerce (tables, pages, roles) once the test WP is loaded.
if ( class_exists( 'WC_Install' ) ) {
	WC_Install::install();

	// Deterministic shop currency for tests; individual tests override as needed.
	update_option( 'woocommerce_currency', 'USD' );
}

// wc_add_notice()/wc_clear_notices() write into WC()->session, which is null
// under the WP test suite.
require_once __DIR__ . '/Support/class-zkp-test-session.php';
require_once __DIR__ . '/Support/helpers.php';
if ( isset( $GLOBALS['woocommerce'] ) && null === WC()->session ) {
	WC()->session = new ZKP_Test_Session();
}

echo "WooCommerce + zerokyc-pay loaded\n";
