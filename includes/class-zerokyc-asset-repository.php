<?php
/**
 * Settlement asset catalog with a resilient cache cascade.
 *
 * The gateway settings dropdown is built from the platform's public
 * GET /v1/assets catalog, so new coins appear without a plugin release.
 * Resolution order (first hit wins):
 *   1. WP transient (12 h TTL) — the common case, zero HTTP;
 *   2. live fetch through the SDK client (base URL follows the key's
 *      sandbox/production prefix); on success the transient AND a
 *      persistent fallback option are written;
 *   3. persistent fallback option (survives expired transients and
 *      object-cache flushes — holds the last known good catalog);
 *   4. FALLBACK_PAYMENT_CURRENCIES seed snapshot compiled into the
 *      plugin (the release-time list; also used before an API key is
 *      configured at all).
 *
 * A failed fetch never blocks checkout: the settings select always has a
 * usable list, and payment_currency is still ultimately validated by the
 * platform when an invoice is created.
 *
 * @package zerokyc-pay
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

final class ZEROKYC_Asset_Repository {

	private const TRANSIENT    = 'zerokyc_assets';
	private const FALLBACK_KEY = 'zerokyc_assets_fallback';
	private const TTL          = 12 * HOUR_IN_SECONDS;

	/**
	 * asset_id => label map, 'any' first — the exact shape the gateway
	 * settings select consumes.
	 *
	 * @return array<string, string>
	 */
	public static function payment_currencies( bool $force_refresh = false ): array {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) && $cached ) {
				return $cached;
			}
		}

		$fetched = self::fetch_from_api();
		if ( array() !== $fetched ) {
			set_transient( self::TRANSIENT, $fetched, self::TTL );
			update_option( self::FALLBACK_KEY, $fetched, false );
			return $fetched;
		}

		$persisted = get_option( self::FALLBACK_KEY, array() );
		if ( is_array( $persisted ) && $persisted ) {
			// short transient so a broken API is not re-hit on every request
			set_transient( self::TRANSIENT, $persisted, 5 * MINUTE_IN_SECONDS );
			return $persisted;
		}

		return ZEROKYC_Gateway::FALLBACK_PAYMENT_CURRENCIES;
	}

	/**
	 * Live catalog via the SDK client. Requires a configured API key:
	 * the base URL (sandbox/production) derives from its prefix.
	 *
	 * @return array<string, string> empty array on any failure
	 */
	private static function fetch_from_api(): array {
		try {
			$assets = ZEROKYC_Gateway::sdk()->assets();
		} catch ( Throwable $e ) {
			return array();
		}
		if ( ! is_array( $assets ) || array() === $assets ) {
			return array();
		}

		$list = array();
		foreach ( $assets as $asset ) {
			if ( empty( $asset['asset_id'] ) ) {
				continue;
			}
			$label                      = $asset['display'] ?? $asset['asset_id'];
			$list[ $asset['asset_id'] ] = $label;
		}
		if ( array() === $list ) {
			return array();
		}

		$tickers = array();
		foreach ( $assets as $asset ) {
			if ( ! empty( $asset['ticker'] ) ) {
				$tickers[] = $asset['ticker'];
			}
		}
		$any_label = 'Let the buyer choose';
		if ( array() !== $tickers ) {
			$any_label .= ' (' . implode( ', ', array_slice( array_unique( $tickers ), 0, 14 ) ) . ')';
		}
		return array( 'any' => $any_label ) + $list;
	}

	/**
	 * Refresh triggers: gateway settings screen, settings save,
	 * plugin activation.
	 */
	public static function refresh_if_stale(): void {
		// a fresh transient means nothing to do; cheap guard for admin pages
		if ( false !== get_transient( self::TRANSIENT ) ) {
			return;
		}
		self::payment_currencies( true );
	}

	public static function flush(): void {
		delete_transient( self::TRANSIENT );
		delete_option( self::FALLBACK_KEY );
	}
}
