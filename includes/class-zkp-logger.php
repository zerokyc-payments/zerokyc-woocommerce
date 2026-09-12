<?php
/**
 * Thin wrapper over WC_Logger. Secrets must never reach this class.
 *
 * @package zerokyc-pay
 */

defined( 'ABSPATH' ) || exit;

final class ZKP_Logger {

	private const SOURCE = 'zerokyc-pay';

	public static function debug( string $message ): void {
		if ( ! self::debug_enabled() ) {
			return;
		}
		self::wc_logger()->debug( $message, array( 'source' => self::SOURCE ) );
	}

	public static function alert( string $message ): void {
		self::wc_logger()->alert( $message, array( 'source' => self::SOURCE ) );
	}

	public static function warning( string $message ): void {
		self::wc_logger()->warning( $message, array( 'source' => self::SOURCE ) );
	}

	private static function wc_logger(): WC_Logger {
		return wc_get_logger();
	}

	private static function debug_enabled(): bool {
		$settings = (array) get_option( 'woocommerce_zerokyc_pay_settings', array() );

		return ! empty( $settings['debug'] ?? '' ) && 'yes' === $settings['debug'];
	}
}
