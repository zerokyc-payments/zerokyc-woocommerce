<?php
/**
 * Shared test helpers.
 *
 * @package zerokyc-pay
 */

if ( ! function_exists( 'zkp_test_gateway' ) ) {
	/**
	 * WC()->payment_gateways()->payment_gateways is a plain list, not keyed by
	 * gateway id; find ours by scanning.
	 */
	function zkp_test_gateway(): ZKP_Gateway {
		foreach ( WC()->payment_gateways()->payment_gateways as $gateway ) {
			if ( $gateway instanceof ZKP_Gateway ) {
				return $gateway;
			}
		}
		throw new RuntimeException( 'ZKP_Gateway not registered.' );
	}
}
