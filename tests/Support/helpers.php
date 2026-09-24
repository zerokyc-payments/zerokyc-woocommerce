<?php
/**
 * Shared test helpers.
 *
 * @package zerokyc-pay
 */

if ( ! function_exists( 'zerokyc_test_gateway' ) ) {
	/**
	 * WC()->payment_gateways()->payment_gateways is a plain list, not keyed by
	 * gateway id; find ours by scanning.
	 */
	function zerokyc_test_gateway(): ZEROKYC_Gateway {
		foreach ( WC()->payment_gateways()->payment_gateways as $gateway ) {
			if ( $gateway instanceof ZEROKYC_Gateway ) {
				return $gateway;
			}
		}
		throw new RuntimeException( 'ZEROKYC_Gateway not registered.' );
	}
}
