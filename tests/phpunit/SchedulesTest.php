<?php
/**
 * Schedule store, cron arming and start.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class SchedulesTest extends TestCase {

	protected function setUp(): void {
		delete_option( 'opex_schedules' );
	}

	protected function tearDown(): void {
		foreach ( array_keys( OPEX_Schedules::all() ) as $id ) {
			OPEX_Schedules::delete( $id );
		}
		delete_option( 'opex_schedules' );
		foreach ( OPEX_Jobs::all() as $job ) {
			if ( '' !== (string) ( $job['schedule_id'] ?? '' ) ) {
				OPEX_Jobs::delete( $job['id'] );
			}
		}
	}

	private function raw( array $over = array() ): array {
		return array_merge(
			array(
				'name'      => 'Nightly posts',
				'config'    => array( 'source' => 'posts:post', 'fields' => array( 'core:ID', 'core:post_title' ), 'batch_size' => 10 ),
				'frequency' => 'daily',
				'time'      => '03:30',
				'enabled'   => true,
			),
			$over
		);
	}

	public function test_round_trip() {
		$rec = OPEX_Schedules::save( $this->raw() );
		$this->assertIsArray( $rec );
		$this->assertMatchesRegularExpression( '/^s[0-9a-f]{12}$/', $rec['id'] );
		$this->assertSame( 40, strlen( $rec['trigger_key'] ) );
		$this->assertGreaterThan( time(), $rec['next_run'] );
		$this->assertSame( $rec, OPEX_Schedules::get( $rec['id'] ) );
		$this->assertSame( 'posts:post', $rec['config']['source'] );
		$this->assertSame( '{label}-{date}', $rec['filename'] );
	}

	public function test_validation_failures() {
		$bad = array(
			array( 'name' => '' ),
			array( 'name' => str_repeat( 'x', 101 ) ),
			array( 'time' => '25:00' ),
			array( 'time' => '9:00' ),
			array( 'frequency' => 'yearly' ),
			array( 'weekday' => 7 ),
			array( 'monthday' => 29 ),
			array( 'monthday' => 'first' ),
			array( 'config' => array( 'source' => 'nope:x', 'fields' => array( 'core:ID' ) ) ),
			array( 'incremental' => array( 'enabled' => true, 'field' => 'bad' ) ),
		);
		foreach ( $bad as $i => $over ) {
			$this->assertWPError( OPEX_Schedules::save( $this->raw( $over ) ), "case $i" );
		}
		$this->assertSame( array(), OPEX_Schedules::all() );
		$this->assertSame( 'opex_missing', OPEX_Schedules::save( $this->raw(), 'sdoesnotexist' )->get_error_code() );
	}

	private function assertWPError( $v, string $msg = '' ): void {
		$this->assertInstanceOf( WP_Error::class, $v, $msg );
	}

	public function test_monthday_last_and_numeric() {
		$a = OPEX_Schedules::save( $this->raw( array( 'frequency' => 'monthly', 'monthday' => 'last' ) ) );
		$b = OPEX_Schedules::save( $this->raw( array( 'frequency' => 'monthly', 'monthday' => '15' ) ) );
		$this->assertSame( 'last', $a['monthday'] );
		$this->assertSame( 15, $b['monthday'] );
	}

	public function test_defaults_on_partial_record() {
		update_option( 'opex_schedules', array( 'sold' => array( 'name' => 'Old' ) ), false );
		$rec = OPEX_Schedules::get( 'sold' );
		$this->assertSame( 'sold', $rec['id'] );
		$this->assertSame( 'manual', $rec['frequency'] );
		$this->assertSame( array(), $rec['config'] );
		$this->assertSame( 0, $rec['last_run']['time'] );
		$this->assertFalse( $rec['incremental']['enabled'] );
		$this->assertFalse( $rec['delivery']['email']['enabled'] );
		$this->assertSame( '', $rec['watermark'] );
		$exposed = OPEX_Schedules::expose( $rec );
		$this->assertArrayHasKey( 'trigger_url', $exposed );
		$this->assertArrayNotHasKey( 'trigger_key', $exposed );
	}

	public function test_key_kept_on_update_and_rotated_on_request() {
		$rec = OPEX_Schedules::save( $this->raw() );
		$upd = OPEX_Schedules::save( array( 'name' => 'Renamed' ), $rec['id'] );
		$this->assertSame( 'Renamed', $upd['name'] );
		$this->assertSame( $rec['trigger_key'], $upd['trigger_key'] );
		$this->assertSame( $rec['config'], $upd['config'] );
		$rot = OPEX_Schedules::save( array( 'rotate_key' => true ), $rec['id'] );
		$this->assertNotSame( $rec['trigger_key'], $rot['trigger_key'] );
		$this->assertSame( 40, strlen( $rot['trigger_key'] ) );
	}

	public function test_cap_of_50() {
		for ( $i = 0; $i < 50; $i++ ) {
			$this->assertIsArray( OPEX_Schedules::save( $this->raw( array( 'enabled' => false, 'frequency' => 'manual' ) ) ) );
		}
		$this->assertSame( 'opex_limit', OPEX_Schedules::save( $this->raw() )->get_error_code() );
	}

	public function test_arm_and_disarm() {
		$rec = OPEX_Schedules::save( $this->raw() );
		$this->assertSame( $rec['next_run'], wp_next_scheduled( 'opex_run_schedule', array( $rec['id'] ) ) );
		// Saving again replaces, not duplicates.
		OPEX_Schedules::save( $this->raw( array( 'time' => '04:45' ) ), $rec['id'] );
		$next = wp_next_scheduled( 'opex_run_schedule', array( $rec['id'] ) );
		$this->assertSame( OPEX_Schedules::get( $rec['id'] )['next_run'], $next );
		OPEX_Schedules::disarm( $rec['id'] );
		$this->assertFalse( wp_next_scheduled( 'opex_run_schedule', array( $rec['id'] ) ) );
		OPEX_Schedules::arm( OPEX_Schedules::get( $rec['id'] ) );
		$this->assertNotFalse( wp_next_scheduled( 'opex_run_schedule', array( $rec['id'] ) ) );
		OPEX_Schedules::delete( $rec['id'] );
		$this->assertFalse( wp_next_scheduled( 'opex_run_schedule', array( $rec['id'] ) ) );
		$this->assertNull( OPEX_Schedules::get( $rec['id'] ) );
	}

	public function test_disabled_or_manual_arms_nothing() {
		$rec = OPEX_Schedules::save( $this->raw( array( 'enabled' => false ) ) );
		$this->assertSame( 0, $rec['next_run'] );
		$this->assertFalse( wp_next_scheduled( 'opex_run_schedule', array( $rec['id'] ) ) );
	}

	public function test_start_creates_job_and_blocks_a_second_run() {
		$rec = OPEX_Schedules::save( $this->raw() );
		$job = OPEX_Schedules::start( $rec['id'], 'manual' );
		$this->assertIsArray( $job );
		$stored = ( (array) get_option( 'opex_jobs', array() ) )[ $job['id'] ] ?? null; // As stored: OPEX_Jobs::get adds defaults.
		$this->assertNotNull( $stored );
		$after = OPEX_Schedules::get( $rec['id'] );
		$this->assertSame( $job['id'], $after['last_run']['job_id'] );
		$this->assertNotFalse( wp_next_scheduled( 'opex_run_schedule', array( $rec['id'] ) ) );
		$this->assertNotFalse( wp_next_scheduled( 'opex_run_job', array( $job['id'] ) ) );

		wp_clear_scheduled_hook( 'opex_run_job', array( $job['id'] ) );
		if ( ! isset( $stored['schedule_id'] ) ) {
			$this->markTestIncomplete( 'Needs the wave 2 engine: create() must store schedule_id, mode, origin, delivery_name from $extra, so the busy check can see the job.' );
		}
		$busy = OPEX_Schedules::start( $rec['id'], 'manual' );
		$this->assertInstanceOf( WP_Error::class, $busy );
		$this->assertSame( 'opex_busy', $busy->get_error_code() );
		$this->assertSame( 409, $busy->get_error_data()['status'] );

		$this->assertSame( $rec['id'], $stored['schedule_id'] );
	}

	public function test_watermark_advances_only_on_success_to_job_start() {
		$rec = OPEX_Schedules::save( $this->raw( array( 'incremental' => array( 'enabled' => true, 'field' => 'core:post_date' ) ) ) );
		$job = array( 'id' => 'jx', 'schedule_id' => $rec['id'], 'status' => 'error', 'created' => 1780000000, 'processed' => 3 );
		OPEX_Schedules::on_job_done( $job );
		$s = OPEX_Schedules::get( $rec['id'] );
		$this->assertSame( '', $s['watermark'] );
		$this->assertSame( 'error', $s['last_run']['status'] );
		$job['status'] = 'done';
		OPEX_Schedules::on_job_done( $job );
		$s = OPEX_Schedules::get( $rec['id'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', 1780000000 ), $s['watermark'] );
		$this->assertSame( 3, $s['last_run']['rows'] );
		OPEX_Schedules::on_job_done( array( 'id' => 'jy', 'status' => 'done' ) ); // No schedule: ignored.
	}

	private function hook( array $over = array() ): array {
		return array( 'delivery' => array( 'webhook' => array_merge( array( 'enabled' => true, 'url' => 'https://example.com/hook', 'secret' => 'plain-secret' ), $over ) ) );
	}

	public function test_webhook_secret_is_stored_encrypted_and_never_exposed() {
		$rec = OPEX_Schedules::save( $this->raw( $this->hook() ) );
		$this->assertIsArray( $rec );
		$stored = $rec['delivery']['webhook']['secret'];
		$this->assertNotSame( 'plain-secret', $stored );
		$this->assertSame( 'plain-secret', OPEX_Secrets::decrypt( $stored ) );
		$this->assertStringNotContainsString( 'plain-secret', wp_json_encode( get_option( 'opex_schedules' ) ) );

		$out = OPEX_Schedules::expose( $rec );
		$this->assertTrue( $out['delivery']['webhook']['has_secret'] );
		$this->assertArrayNotHasKey( 'secret', $out['delivery']['webhook'] );
		$this->assertStringNotContainsString( 'plain-secret', wp_json_encode( $out ) );
		$this->assertStringNotContainsString( $stored, wp_json_encode( $out ) );
	}

	public function test_blank_secret_keeps_the_stored_one_and_clear_secret_removes_it() {
		$rec  = OPEX_Schedules::save( $this->raw( $this->hook() ) );
		$kept = OPEX_Schedules::save( $this->raw( $this->hook( array( 'secret' => '' ) ) ), $rec['id'] );
		$this->assertSame( $rec['delivery']['webhook']['secret'], $kept['delivery']['webhook']['secret'] );

		// Saving without a delivery block keeps it too.
		$again = OPEX_Schedules::save( $this->raw(), $rec['id'] );
		$this->assertSame( $rec['delivery']['webhook']['secret'], $again['delivery']['webhook']['secret'] );

		$cleared = OPEX_Schedules::save( $this->raw( $this->hook( array( 'secret' => '' ) ) + array( 'clear_secret' => true ) ), $rec['id'] );
		$this->assertSame( '', $cleared['delivery']['webhook']['secret'] );
		$this->assertFalse( OPEX_Schedules::expose( $cleared )['delivery']['webhook']['has_secret'] );
	}

	public function test_plain_http_webhook_to_another_host_is_stored_disabled() {
		$rec = OPEX_Schedules::save( $this->raw( $this->hook( array( 'url' => 'http://example.com/hook' ) ) ) );
		$this->assertFalse( $rec['delivery']['webhook']['enabled'] );
	}
}
