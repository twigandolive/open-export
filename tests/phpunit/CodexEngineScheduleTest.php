<?php
/**
 * Schedule fixes from the Codex review: R1-1.3 (the start time is taken
 * before the count), R1-1.4 (the watermark follows its source, field and
 * generation), R1-1.6 (a start that lost its lock saves no job), R1-1.10
 * (daylight-saving times in both directions) and R1-1.11 (the incremental
 * field must be a date field of the source).
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class CodexEngineScheduleTest extends TestCase {

	/** The query filter in use, if any. */
	private $filter = null;

	protected function tearDown(): void {
		if ( null !== $this->filter ) {
			remove_filter( 'query', $this->filter );
			$this->filter = null;
		}
		foreach ( OPEX_Jobs::all() as $job ) {
			if ( 0 === strpos( (string) $job['label'], 'Codex sched' ) ) {
				OPEX_Jobs::delete( $job['id'] );
			}
		}
		foreach ( OPEX_Schedules::all() as $id => $s ) {
			if ( 0 === strpos( (string) $s['name'], 'Codex sched' ) ) {
				OPEX_Lock::force_release( 'sched_' . $id );
				OPEX_Schedules::delete( (string) $id );
			}
		}
	}

	/**
	 * A schedule input with incremental exports on.
	 *
	 * @param array $over Keys to change.
	 * @return array The input.
	 */
	private function raw( array $over = array() ): array {
		return array_merge(
			array(
				'name'        => 'Codex sched',
				'config'      => array(
					'source'     => 'posts:post',
					'fields'     => array( 'core:ID' ),
					'batch_size' => 50,
				),
				'frequency'   => 'manual',
				'incremental' => array(
					'enabled' => true,
					'field'   => 'core:post_date_gmt',
				),
			),
			$over
		);
	}

	/**
	 * Saves a schedule and returns it.
	 *
	 * @param array $over Keys to change.
	 * @return array The record.
	 */
	private function saved( array $over = array() ): array {
		$rec = OPEX_Schedules::save( $this->raw( $over ) );
		$this->assertIsArray( $rec );
		return $rec;
	}

	/**
	 * Next run of a daily schedule at a wall time.
	 *
	 * @param string $time Wall time, HH:MM.
	 * @param string $now  UTC time to plan from.
	 * @param string $zone Time zone.
	 * @param string $freq Frequency.
	 * @return string The next run in UTC, Y-m-d H:i.
	 */
	private function next( string $time, string $now, string $zone, string $freq = 'daily' ): string {
		$ts = OPEX_Schedule_Clock::next_run(
			array(
				'frequency' => $freq,
				'time'      => $time,
				'enabled'   => true,
			),
			strtotime( $now . ' UTC' ),
			new DateTimeZone( $zone )
		);
		return gmdate( 'Y-m-d H:i', $ts );
	}

	public function test_a_time_that_happens_twice_runs_at_its_first_occurrence() {
		// Clocks go back at 03:00 CEST in Berlin: 02:30 happens at 00:30 and 01:30 UTC.
		$this->assertSame( '2026-10-25 00:30', $this->next( '02:30', '2026-10-24 12:00', 'Europe/Berlin' ) );
		// In New York 01:30 happens at 05:30 (EDT) and 06:30 UTC (EST).
		$this->assertSame( '2026-11-01 05:30', $this->next( '01:30', '2026-10-31 12:00', 'America/New_York' ) );
		// The day after, the plain time is back.
		$this->assertSame( '2026-10-26 01:30', $this->next( '02:30', '2026-10-25 00:30', 'Europe/Berlin' ) );
		$this->assertSame( '2026-11-02 06:30', $this->next( '01:30', '2026-11-01 05:30', 'America/New_York' ) );
	}

	public function test_the_repeated_hour_runs_once_on_an_hourly_schedule() {
		// Hourly at :30 in Berlin on the night clocks go back: 02:30 once, then 03:30 CET.
		$this->assertSame( '2026-10-25 00:30', $this->next( '00:30', '2026-10-25 00:00', 'Europe/Berlin', 'hourly' ) );
		$this->assertSame( '2026-10-25 02:30', $this->next( '00:30', '2026-10-25 00:30', 'Europe/Berlin', 'hourly' ) );
		$this->assertSame( '2026-11-01 05:30', $this->next( '00:30', '2026-11-01 05:00', 'America/New_York', 'hourly' ) );
		$this->assertSame( '2026-11-01 07:30', $this->next( '00:30', '2026-11-01 05:30', 'America/New_York', 'hourly' ) );
	}

	public function test_a_time_that_does_not_exist_moves_to_the_end_of_the_gap() {
		// Clocks go forward at 02:00 in Berlin: 02:30 does not exist, 03:00 CEST is 01:00 UTC.
		$this->assertSame( '2026-03-29 01:00', $this->next( '02:30', '2026-03-28 12:00', 'Europe/Berlin' ) );
		// In New York 02:30 does not exist on 8 March: 03:00 EDT is 07:00 UTC.
		$this->assertSame( '2026-03-08 07:00', $this->next( '02:30', '2026-03-07 12:00', 'America/New_York' ) );
	}

	public function test_a_fixed_offset_zone_still_plans() {
		$this->assertSame( '2026-10-25 00:30', $this->next( '02:30', '2026-10-24 12:00', '+02:00' ) );
	}

	public function test_incremental_needs_a_date_field_of_the_source() {
		foreach ( array( '', 'core:ID', 'core:user_registered', 'meta:not_a_date' ) as $field ) {
			$out = OPEX_Schedules::save(
				$this->raw(
					array(
						'incremental' => array(
							'enabled' => true,
							'field'   => $field,
						),
					)
				)
			);
			$this->assertInstanceOf( WP_Error::class, $out, "field '$field'" );
			$this->assertSame( 'opex_invalid', $out->get_error_code() );
		}
		$this->assertIsArray( OPEX_Schedules::save( $this->raw() ) );
		$this->assertIsArray(
			OPEX_Schedules::save(
				$this->raw(
					array(
						'incremental' => array(
							'enabled' => false,
							'field'   => '',
						),
					)
				)
			),
			'With Changes only off, no field is needed.'
		);
	}

	public function test_a_source_change_clears_the_watermark_with_a_note() {
		$rec = $this->saved();
		OPEX_Schedules::on_job_done( $this->done_job( $rec ) );
		$this->assertNotSame( '', OPEX_Schedules::get( $rec['id'] )['watermark'] );

		$notes = array();
		$same  = OPEX_Schedules::save( array( 'name' => 'Codex sched renamed' ), $rec['id'], $notes );
		$this->assertNotSame( '', $same['watermark'], 'A rename keeps the watermark.' );
		$this->assertSame( array(), $notes );

		$notes = array();
		$moved = OPEX_Schedules::save(
			array(
				'config' => array(
					'source' => 'posts:page',
					'fields' => array( 'core:ID' ),
				),
			),
			$rec['id'],
			$notes
		);
		$this->assertIsArray( $moved );
		$this->assertSame( '', $moved['watermark'] );
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'watermark was cleared', $notes[0] );
		$this->assertArrayNotHasKey( 'generation', OPEX_Schedules::expose( $moved ), 'The generation is never shown.' );
	}

	public function test_a_field_change_clears_the_watermark() {
		$rec = $this->saved();
		OPEX_Schedules::on_job_done( $this->done_job( $rec ) );
		$notes = array();
		$moved = OPEX_Schedules::save(
			array(
				'incremental' => array(
					'enabled' => true,
					'field'   => 'core:post_modified_gmt',
				),
			),
			$rec['id'],
			$notes
		);
		$this->assertSame( '', $moved['watermark'] );
		$this->assertCount( 1, $notes );
	}

	public function test_a_run_that_started_before_a_reset_does_not_set_the_watermark() {
		$rec = $this->saved();
		$job = OPEX_Schedules::start( $rec['id'], 'manual' );
		$this->assertIsArray( $job );
		$stored = OPEX_Jobs::get( $job['id'] );
		$this->assertSame( (string) OPEX_Schedules::get( $rec['id'] )['generation'], (string) $stored['generation'], 'The job carries the generation it started under.' );

		OPEX_Schedules::reset_watermark( $rec['id'] );
		$stored['status'] = 'done';
		OPEX_Schedules::on_job_done( $stored );

		$after = OPEX_Schedules::get( $rec['id'] );
		$this->assertSame( '', $after['watermark'], 'The reset must stay.' );
		$this->assertSame( $job['id'], $after['last_run']['job_id'], 'The run still shows as the last run.' );

		// A run that starts after the reset moves the watermark again.
		$next = $this->done_job( $after );
		OPEX_Schedules::on_job_done( $next );
		$this->assertNotSame( '', OPEX_Schedules::get( $rec['id'] )['watermark'] );
	}

	public function test_a_run_that_started_before_a_source_change_does_not_set_the_watermark() {
		$rec = $this->saved();
		$old = $this->done_job( $rec );
		OPEX_Schedules::save(
			array(
				'config' => array(
					'source' => 'posts:page',
					'fields' => array( 'core:ID' ),
				),
			),
			$rec['id']
		);
		OPEX_Schedules::on_job_done( $old );
		$this->assertSame( '', OPEX_Schedules::get( $rec['id'] )['watermark'] );
	}

	public function test_a_record_without_a_generation_still_moves_its_watermark() {
		$rec = $this->saved();
		$this->assertSame( '', (string) $rec['generation'] );
		$job = $this->done_job( $rec );
		unset( $job['generation'] );
		OPEX_Schedules::on_job_done( $job );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $job['created'] ), OPEX_Schedules::get( $rec['id'] )['watermark'] );
	}

	public function test_a_start_whose_lock_was_lost_during_the_count_saves_no_job() {
		$rec = $this->saved( array( 'incremental' => array( 'enabled' => false ) ) );
		$id  = $rec['id'];
		$this->during_count(
			static function () use ( $id ) {
				// The count outlasted the lock: another start took it over.
				OPEX_Lock::force_release( 'sched_' . $id );
				OPEX_Lock::acquire( 'sched_' . $id, 60 );
			}
		);

		$out = OPEX_Schedules::start( $id, 'manual' );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'opex_busy', $out->get_error_code() );
		foreach ( OPEX_Jobs::all() as $job ) {
			$this->assertNotSame( $id, $job['schedule_id'], 'No job may be saved by a start that lost its lock.' );
		}
		$files = glob( trailingslashit( opex_export_dir() ) . 'codex-sched-*' );
		$this->assertSame( array(), (array) $files, 'The file the lost start wrote is removed.' );
	}

	public function test_the_start_time_is_taken_before_the_rows_are_counted() {
		$counted = 0;
		$this->during_count(
			static function () use ( &$counted ) {
				$counted = time();
				sleep( 2 );
			}
		);
		$config = OPEX_Exporter::sanitize_config(
			array(
				'source' => 'posts:post',
				'fields' => array( 'core:ID' ),
			)
		);
		$job    = OPEX_Exporter::create( $config, 'Codex sched start time' );
		$this->assertIsArray( $job );
		$this->assertGreaterThan( 0, $counted );
		$this->assertLessThanOrEqual( $counted, (int) $job['created'], 'A row added during the count must be after the watermark.' );
	}

	/**
	 * A done job of a schedule, with the schedule's generation.
	 *
	 * @param array $rec Schedule record.
	 * @return array The job.
	 */
	private function done_job( array $rec ): array {
		return array(
			'id'          => 'jcodexsched' . wp_rand( 10, 99 ),
			'schedule_id' => $rec['id'],
			'status'      => 'done',
			'created'     => time() - 60,
			'processed'   => 1,
			'generation'  => (string) ( OPEX_Schedules::get( $rec['id'] )['generation'] ?? '' ),
		);
	}

	/**
	 * Runs $fn once, when the next row count reaches the database.
	 *
	 * @param callable $fn What happens during the count.
	 */
	private function during_count( callable $fn ): void {
		global $wpdb;
		$fired        = false;
		$posts        = $wpdb->posts;
		$this->filter = static function ( $sql ) use ( &$fired, $fn, $posts ) {
			if ( ! $fired && false !== stripos( (string) $sql, 'COUNT(' ) && false !== strpos( (string) $sql, $posts ) ) {
				$fired = true;
				$fn();
			}
			return $sql;
		};
		add_filter( 'query', $this->filter );
	}
}
