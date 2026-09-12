<?php
/**
 * @package zerokyc-pay
 */

class EventStoreTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		ZKP_Event_Store::create_table();
	}

	public function test_claim_is_atomic_first_writer_wins(): void {
		$this->assertTrue( ZKP_Event_Store::claim( 'evt_001', 'payment.confirmed', 'inv_001' ) );
		$this->assertFalse( ZKP_Event_Store::claim( 'evt_001', 'payment.confirmed', 'inv_001' ) );
		$this->assertTrue( ZKP_Event_Store::claim( 'evt_002', 'payment.confirmed', 'inv_001' ) );
	}

	public function test_replayguard_interface_methods(): void {
		$store = new ZKP_Event_Store();
		$this->assertFalse( $store->has( 'evt_010' ) );

		ZKP_Event_Store::claim( 'evt_010', 'payment.detected', 'inv_010' );
		$this->assertTrue( $store->has( 'evt_010' ) );

		$store->markProcessed( 'evt_010' );
		$this->assertTrue( $store->has( 'evt_010' ) );

		global $wpdb;
		$processed_at = $wpdb->get_var(
			$wpdb->prepare( 'SELECT processed_at FROM ' . ZKP_Event_Store::table() . ' WHERE event_id = %s', 'evt_010' )
		);
		$this->assertNotNull( $processed_at );
	}

	public function test_table_is_queryable_after_activation(): void {
		// SHOW TABLES cannot see the temporary tables the test framework
		// creates; probe with a SELECT instead.
		global $wpdb;
		$probe = $wpdb->query( 'SELECT 1 FROM ' . ZKP_Event_Store::table() . ' LIMIT 1' );
		$this->assertNotFalse( $probe );
	}

	public function test_cleanup_removes_old_rows_only(): void {
		global $wpdb;

		ZKP_Event_Store::claim( 'evt_old', 'invoice.expired', 'inv_x' );
		ZKP_Event_Store::claim( 'evt_new', 'invoice.expired', 'inv_x' );

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . ZKP_Event_Store::table() . ' SET created_at = %s WHERE event_id = %s',
				gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ),
				'evt_old'
			)
		);

		$this->assertSame( 1, ZKP_Event_Store::cleanup( 30 ) );
		$store = new ZKP_Event_Store();
		$this->assertFalse( $store->has( 'evt_old' ) );
		$this->assertTrue( $store->has( 'evt_new' ) );
	}
}
