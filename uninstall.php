<?php
/**
 * Uninstall cleanup: removes plugin options and the cron schedule.
 *
 * Order meta (_zkp_*) and the zerokyc_events / zerokyc_invoices tables are
 * financial bookkeeping records and are intentionally kept.
 *
 * @package zerokyc-pay
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || ! defined( 'ABSPATH' ) ) {
	exit;
}

delete_option( 'woocommerce_zerokyc_pay_settings' );
wp_clear_scheduled_hook( 'zerokyc_poll_pending' );
