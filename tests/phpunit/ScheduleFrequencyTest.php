<?php
/**
 * The every_6_hours and twicedaily frequencies: next-run math anchored on the
 * schedule's time, daylight saving changes, saving and the /options list.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Every 6 hours and twice a day.
 */
class ScheduleFrequencyTest extends TestCase {

	/** @var mixed Schedules stored before the test. */
	private $saved = null;

	protected function setUp(): void {
		wp_set_current_user( 1 );
		$this->saved = get_option( 'opex_schedules', null );
	}

	protected function tearDown(): void {
		foreach ( array_keys( OPEX_Schedules::all() ) as $id ) {
			if ( null === $this->saved || ! isset( $this->saved[ $id ] ) ) {
				OPEX_Schedules::delete( $id );
			}
		}
		if ( null === $this->saved ) {
			delete_option( 'opex_schedules' );
		} else {
			update_option( 'opex_schedules', $this->saved, false );
		}
	}

	/**
	 * A timestamp for a wall-clock time in a zone.
	 *
	 * @param string $wall Wall time.
	 * @param string $zone Zone name.
	 */
	private function ts( string $wall, string $zone ): int {
		return ( new DateTimeImmutable( $wall, new DateTimeZone( $zone ) ) )->getTimestamp();
	}

	/**
	 * Next run for a schedule.
	 *
	 * @param array  $s    Schedule keys.
	 * @param int    $now  Now.
	 * @param string $zone Zone name.
	 */
	private function next( array $s, int $now, string $zone ): int {
		$s = array_merge( array( 'enabled' => true, 'weekday' => 0, 'monthday' => 1 ), $s );
		return OPEX_Schedule_Clock::next_run( $s, $now, new DateTimeZone( $zone ) );
	}

	/**
	 * The next $count runs as local wall times.
	 *
	 * @param array  $s     Schedule keys.
	 * @param string $start Local start time.
	 * @param string $zone  Zone name.
	 * @param int    $count How many runs.
	 * @return string[]
	 */
	private function runs( array $s, string $start, string $zone, int $count ): array {
		$tz  = new DateTimeZone( $zone );
		$now = $this->ts( $start, $zone );
		$out = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$next = $this->next( $s, $now, $zone );
			$this->assertGreaterThan( $now, $next, 'strictly after now' );
			$out[] = ( new DateTimeImmutable( '@' . $next ) )->setTimezone( $tz )->format( 'Y-m-d H:i T' );
			$now   = $next;
		}
		return $out;
	}

	/** Every 6 hours from 03:00 runs at 03:00, 09:00, 15:00 and 21:00. */
	public function test_every_6_hours_is_anchored_on_the_time(): void {
		$s = array(
			'frequency' => 'every_6_hours',
			'time'      => '03:00',
		);
		$this->assertSame(
			array( '2026-01-01 09:00 UTC', '2026-01-01 15:00 UTC', '2026-01-01 21:00 UTC', '2026-01-02 03:00 UTC', '2026-01-02 09:00 UTC' ),
			$this->runs( $s, '2026-01-01 03:00', 'UTC', 5 )
		);
		// The anchor can be any hour: 21:30 gives 03:30, 09:30, 15:30 and 21:30.
		$late = array(
			'frequency' => 'every_6_hours',
			'time'      => '21:30',
		);
		$this->assertSame( $this->ts( '2026-01-01 03:30', 'UTC' ), $this->next( $late, $this->ts( '2026-01-01 00:00', 'UTC' ), 'UTC' ) );
		$this->assertSame( $this->ts( '2026-01-01 15:30', 'UTC' ), $this->next( $late, $this->ts( '2026-01-01 09:30', 'UTC' ), 'UTC' ) );
	}

	/** Twice a day at 03:00 runs at 03:00 and 15:00. */
	public function test_twice_a_day(): void {
		$s = array(
			'frequency' => 'twicedaily',
			'time'      => '15:00',
		);
		$this->assertSame(
			array( '2026-01-01 15:00 UTC', '2026-01-02 03:00 UTC', '2026-01-02 15:00 UTC' ),
			$this->runs( $s, '2026-01-01 04:00', 'UTC', 3 )
		);
		$this->assertSame( 0, $this->next( $s + array( 'enabled' => false ), 1000, 'UTC' ) );
	}

	/** Spring forward in New York: a run in the missing hour moves to 03:00, the others keep their wall time. */
	public function test_dst_spring_forward(): void {
		$z = 'America/New_York';
		$this->assertSame(
			array( '2026-03-07 20:00 EST', '2026-03-08 03:00 EDT', '2026-03-08 08:00 EDT', '2026-03-08 14:00 EDT', '2026-03-08 20:00 EDT' ),
			$this->runs(
				array(
					'frequency' => 'every_6_hours',
					'time'      => '02:00',
				),
				'2026-03-07 15:00',
				$z,
				5
			)
		);
	}

	/** Fall back in New York: the repeated hour runs once. */
	public function test_dst_fall_back_runs_once(): void {
		$z = 'America/New_York';
		$this->assertSame(
			array( '2026-11-01 01:30 EDT', '2026-11-01 13:30 EST', '2026-11-02 01:30 EST' ),
			$this->runs(
				array(
					'frequency' => 'twicedaily',
					'time'      => '13:30',
				),
				'2026-10-31 20:00',
				$z,
				3
			)
		);
		$this->assertSame(
			array( '2026-11-01 01:15 EDT', '2026-11-01 07:15 EST', '2026-11-01 13:15 EST' ),
			$this->runs(
				array(
					'frequency' => 'every_6_hours',
					'time'      => '07:15',
				),
				'2026-10-31 20:00',
				$z,
				3
			)
		);
	}

	/** Over a year in Chicago every result is after now and at most the step away in wall time. */
	public function test_always_after_now_over_a_year(): void {
		$z = new DateTimeZone( 'America/Chicago' );
		foreach ( array( 'every_6_hours' => 6, 'twicedaily' => 12 ) as $freq => $hours ) {
			$s = array(
				'enabled'   => true,
				'frequency' => $freq,
				'time'      => '02:30',
			);
			$t = $this->ts( '2026-01-01 00:00', 'UTC' );
			for ( $i = 0; $i < 365 * 2; $i++, $t += 43200 ) {
				$next = OPEX_Schedule_Clock::next_run( $s, $t, $z );
				$this->assertGreaterThan( $t, $next );
				$this->assertLessThanOrEqual( $t + ( $hours + 1 ) * HOUR_IN_SECONDS, $next, $freq );
			}
		}
	}

	/** Schedules save with the new frequencies, get a next run and arm cron. */
	public function test_save_and_arm(): void {
		foreach ( array( 'every_6_hours', 'twicedaily' ) as $freq ) {
			$rec = OPEX_Schedules::save(
				array(
					'name'      => 'Freq ' . $freq,
					'config'    => array(
						'source' => 'posts:post',
						'fields' => array( 'core:ID' ),
					),
					'frequency' => $freq,
					'time'      => '03:00',
					'enabled'   => true,
				)
			);
			$this->assertIsArray( $rec, is_wp_error( $rec ) ? $rec->get_error_message() : '' );
			$this->assertSame( $freq, $rec['frequency'] );
			$this->assertGreaterThan( time(), $rec['next_run'] );
			$this->assertLessThanOrEqual( time() + 12 * HOUR_IN_SECONDS + HOUR_IN_SECONDS, $rec['next_run'] );
			$this->assertSame( $rec['next_run'], wp_next_scheduled( 'opex_run_schedule', array( $rec['id'] ) ) );
		}
	}

	/** GET /options lists both, with labels, between hourly and daily. */
	public function test_options_list_the_frequencies(): void {
		$list = OPEX_Rest_Delivery::options()->get_data()['frequencies'];
		$this->assertSame( array( 'manual', 'hourly', 'every_6_hours', 'twicedaily', 'daily', 'weekly', 'monthly' ), array_column( $list, 'id' ) );
		$labels = array_column( $list, 'label', 'id' );
		$this->assertSame( 'Every 6 hours', $labels['every_6_hours'] );
		$this->assertSame( 'Twice a day', $labels['twicedaily'] );
	}
}
