<?php
/**
 * invoice_id -> order_id mapping.
 *
 * Custom-order-meta lookups via wc_get_orders() behave differently on the
 * posts and HPOS datastores; a tiny dedicated table keeps invoice resolution
 * portable, index-friendly and independent of WooCommerce internals. An order
 * can have several invoices over its lifetime (retries) - each invoice maps to
 * the same order.
 *
 * @package zerokyc-pay
 */

defined( 'ABSPATH' ) || exit;

final class ZKP_Invoice_Map {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zerokyc_invoices';
	}

	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id VARCHAR(191) NOT NULL DEFAULT '',
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY invoice_id (invoice_id),
			KEY order_id (order_id)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Lazy creation; existence probed with a SELECT because SHOW TABLES cannot
	 * see temporary tables.
	 */
	public static function maybe_create_table(): void {
		global $wpdb;
		$exists = $wpdb->query( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', self::table() ) );
		if ( false === $exists ) {
			self::create_table();
		}
	}

	/**
	 * Remember which order an invoice belongs to (idempotent).
	 */
	public static function remember( string $invoice_id, int $order_id ): void {
		global $wpdb;
		if ( '' === $invoice_id ) {
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (invoice_id, order_id, created_at)
				 VALUES (%s, %d, %s)
				 ON DUPLICATE KEY UPDATE order_id = VALUES(order_id)',
				self::table(),
				$invoice_id,
				$order_id,
				current_time( 'mysql' )
			)
		);
	}

	public static function order_id_for( string $invoice_id ): ?int {
		global $wpdb;
		$found = $wpdb->get_var(
			$wpdb->prepare( 'SELECT order_id FROM %i WHERE invoice_id = %s LIMIT 1', self::table(), $invoice_id )
		);
		return null === $found ? null : (int) $found;
	}

	/**
	 * Open invoice candidates for the cron poller.
	 *
	 * @return array<int,int> order ids
	 */
	public static function order_ids_since( string $mysql_datetime ): array {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare( 'SELECT DISTINCT order_id FROM %i WHERE created_at >= %s', self::table(), $mysql_datetime )
		);
		return array_map( 'intval', (array) $rows );
	}

	public static function cleanup( int $days ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < DATE_SUB(%s, INTERVAL %d DAY)',
				self::table(),
				current_time( 'mysql' ),
				$days
			)
		);
	}
}
