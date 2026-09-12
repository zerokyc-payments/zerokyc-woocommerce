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

echo "WooCommerce + zerokyc-pay loaded\n";
