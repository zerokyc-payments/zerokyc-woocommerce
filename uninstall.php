<?php
/**
 * Uninstall cleanup: removes plugin options and the cron schedule.
 *
 * Order meta (_zerokyc_*) and the zerokyc_events / zerokyc_invoices tables are
 * financial bookkeeping records and are intentionally kept.
 *
 * @package zerokyc-pay
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || ! defined( 'ABSPATH' ) ) {
	exit;
}

delete_option( 'woocommerce_zerokyc_pay_settings' );
delete_option( 'zerokyc_assets_fallback' );
wp_clear_scheduled_hook( 'zerokyc_poll_pending' );
