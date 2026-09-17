<?php
/**
 * Webhook event deduplication store backed by a custom table.
 *
 * The SDK's ReplayGuard marks events processed only after the order update
 * succeeds; this store deliberately inverts that with an atomic claim
 * (unique key INSERT), which the SDK docs bless for strict single-processing:
 * a claimed event that later fails is reconciled by the cron poller.
 *
 * @package zerokyc-pay
 */

defined( 'ABSPATH' ) || exit;

use ZeroKYC\Idempotency\EventStoreInterface;

final class ZKP_Event_Store implements EventStoreInterface {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zerokyc_events';
	}

	/**
	 * Creates the table (activation / lazy admin path).
	 */
	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id VARCHAR(191) NOT NULL DEFAULT '',
			event_type VARCHAR(64) NOT NULL DEFAULT '',
			invoice_id VARCHAR(191) NOT NULL DEFAULT '',
			order_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			processed_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_id (event_id),
			KEY invoice_id (invoice_id),
			KEY order_id (order_id)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Lazy creation on admin requests (e.g. multisite or restored backups).
	 *
	 * SHOW TABLES cannot see temporary tables, so existence is probed with a
	 * cheap SELECT that works everywhere.
	 */
	public static function maybe_create_table(): void {
		global $wpdb;
		$exists = $wpdb->query( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', self::table() ) );
		if ( false === $exists ) {
			self::create_table();
		}
	}

	/**
	 * Atomically claims an event: true when we are the first processor.
	 */
	public static function claim( string $event_id, string $event_type = '', string $invoice_id = '' ): bool {
		global $wpdb;
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'event_id'   => $event_id,
				'event_type' => substr( $event_type, 0, 64 ),
				'invoice_id' => substr( $invoice_id, 0, 191 ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
		return false !== $inserted;
	}

	/**
	 * EventStoreInterface::has().
	 */
	public function has( string $event_id ): bool {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event_id = %s', self::table(), $event_id )
		) > 0;
	}

	/**
	 * EventStoreInterface::markProcessed() — a no-op beyond the timestamp,
	 * because claim() already inserted the row.
	 */
	public function markProcessed( string $event_id ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET processed_at = %s WHERE event_id = %s AND processed_at IS NULL',
				self::table(),
				current_time( 'mysql' ),
				$event_id
			)
		);
	}

	/**
	 * Drops events older than N days (wire to a weekly maintenance cron if the
	 * table grows); returns the number of removed rows.
	 */
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
