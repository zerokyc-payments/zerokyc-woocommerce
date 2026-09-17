<?php
/**
 * Plugin bootstrap: autoloading, hook wiring, activation lifecycle.
 *
 * @package zerokyc-pay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bootstraps the plugin and owns the autoloader.
 *
 * Autoloading rules (no Composer at runtime):
 *  - ZKP_Foo_Bar            -> includes/class-zkp-foo-bar.php
 *  - ZeroKYC\... (SDK, PSR-4) -> vendor/zerokyc/zkp-sdk-php/src/
 */
final class ZKP_Plugin {

	public const TEXT_DOMAIN = 'zerokyc-pay';

	public const CRON_HOOK = 'zerokyc_poll_pending';

	/**
	 * Hook everything. Called once from the main plugin file.
	 */
	public static function boot(): void {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );

		// HPOS / custom order tables compatibility must be declared early.
		add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_wc_compatibility' ) );

		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			// WooCommerce is required (header Requires Plugins guards installs,
			// but the class may not be loaded yet on early requests).
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="error"><p>';
					printf(
						/* translators: %s: plugin name */
						esc_html__( '%s requires WooCommerce to be installed and activated.', 'zerokyc-pay' ),
						'<strong>ZeroKYC Pay</strong>'
					);
					echo '</p></div>';
				}
			);
			return;
		}

		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_gateway' ) );
		add_action( 'rest_api_init', array( 'ZKP_Webhook_Controller', 'register_routes' ) );
		add_filter( 'cron_schedules', array( 'ZKP_Cron', 'add_schedule' ) );
		add_action( self::CRON_HOOK, array( 'ZKP_Cron', 'poll' ) );
		add_action( 'wp_ajax_zerokyc_ping', array( 'ZKP_Gateway', 'ajax_ping' ) );

		// Keep the schedule alive if the gateway is enabled (self-healing, also
		// covers sites that never ran the activation hook).
		add_action( 'init', array( 'ZKP_Cron', 'ensure_schedule' ) );

		// Lazily create our tables on admin requests if they are missing
		// (e.g. multisite or restored-from-backup installs).
		add_action( 'admin_init', array( 'ZKP_Event_Store', 'maybe_create_table' ) );
		add_action( 'admin_init', array( 'ZKP_Invoice_Map', 'maybe_create_table' ) );
	}

	/**
	 * Class autoloader. See the class docblock for the mapping rules.
	 *
	 * @param string $class Fully qualified class name.
	 */
	public static function autoload( string $class_name ): void {
		if ( str_starts_with( $class_name, 'ZeroKYC\\' ) ) {
			$relative = str_replace( '\\', '/', substr( $class_name, strlen( 'ZeroKYC\\' ) ) );
			$file     = ZKP_PLUGIN_DIR . 'vendor/zerokyc/zkp-sdk-php/src/' . $relative . '.php';
			if ( is_file( $file ) ) {
				require_once $file;
			}
			return;
		}

		if ( str_starts_with( $class_name, 'ZKP_' ) ) {
			$slug = strtolower( str_replace( '_', '-', substr( $class_name, strlen( 'ZKP_' ) ) ) );
			$file = ZKP_PLUGIN_DIR . 'includes/class-zkp-' . $slug . '.php';
			if ( is_file( $file ) ) {
				require_once $file;
			}
		}
	}

	/**
	 * Declare WooCommerce feature compatibility (HPOS).
	 */
	public static function declare_wc_compatibility(): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ZKP_PLUGIN_FILE, true );
		}
	}

	/**
	 * @return array<int, class-string>
	 */
	public static function register_gateway( array $gateways ): array {
		$gateways[] = 'ZKP_Gateway';
		return $gateways;
	}

	/**
	 * Activation: create the webhook events and invoice mapping tables.
	 */
	public static function activate(): void {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
		ZKP_Event_Store::create_table();
		ZKP_Invoice_Map::create_table();
	}

	/**
	 * Deactivation: drop the polling schedule (data is kept).
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}
}
