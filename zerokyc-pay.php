<?php
/**
 * Plugin Name:       ZeroKYC – Crypto Payments for WooCommerce
 * Plugin URI:        https://github.com/zerokyc-payments/zerokyc-woocommerce
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

define( 'ZEROKYC_VERSION', '1.0.0' );
define( 'ZEROKYC_PLUGIN_FILE', __FILE__ );
define( 'ZEROKYC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once __DIR__ . '/includes/class-zerokyc-plugin.php';

register_activation_hook( __FILE__, array( 'ZEROKYC_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ZEROKYC_Plugin', 'deactivate' ) );

ZEROKYC_Plugin::boot();
