<?php
/**
 * Plugin Name:       ZeroKYC Pay
 * Plugin URI:        https://zerokyc-payments.com
 * Description:       Accept crypto payments (USDT, USDC, BTC, XMR, TON) in WooCommerce through the ZeroKYC Pay hosted checkout. No KYC, non-custodial option, webhooks verified by HMAC signature.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            ZeroKYC Payments
 * Author URI:        https://zerokyc-payments.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       zerokyc-pay
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'ZKP_VERSION', '1.0.0' );
define( 'ZKP_PLUGIN_FILE', __FILE__ );
define( 'ZKP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once __DIR__ . '/includes/class-zkp-plugin.php';

register_activation_hook( __FILE__, array( 'ZKP_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ZKP_Plugin', 'deactivate' ) );

ZKP_Plugin::boot();
