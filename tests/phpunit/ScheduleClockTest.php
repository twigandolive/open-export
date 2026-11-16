<?php
/**
 * Next-run math.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class ScheduleClockTest extends TestCase {

	private function ts( string $wall, string $zone ): int {
		return ( new DateTimeImmutable( $wall, new DateTimeZone( $zone ) ) )->getTimestamp();
	}

	private function next( array $s, int $now, string $zone ): int {
		$s = array_merge( array( 'enabled' => true, 'time' => '09:00', 'weekday' => 0, 'monthday' => 1 ), $s );
		return OPEX_Schedule_Clock::next_run( $s, $now, new DateTimeZone( $zone ) );
	}

	public function test_manual_and_disabled_return_zero() {
		$this->assertSame( 0, $this->next( array( 'frequency' => 'manual' ), 1000, 'UTC' ) );
		$this->assertSame( 0, $this->next( array( 'frequency' => 'daily', 'enabled' => false ), 1000, 'UTC' ) );
	}

	public function test_hourly_uses_minute_and_is_strictly_after() {
		$s = array( 'frequency' => 'hourly', 'time' => '00:15' );
		$this->assertSame( $this->ts( '2026-01-01 10:15', 'UTC' ), $this->next( $s, $this->ts( '2026-01-01 10:14:59', 'UTC' ), 'UTC' ) );
		$this->assertSame( $this->ts( '2026-01-01 11:15', 'UTC' ), $this->next( $s, $this->ts( '2026-01-01 10:15:00', 'UTC' ), 'UTC' ) );
	}

	public function test_daily_strictly_after_now() {
		$s = array( 'frequency' => 'daily' );
		$this->assertSame( $this->ts( '2026-01-02 09:00', 'UTC' ), $this->next( $s, $this->ts( '2026-01-01 09:00:00', 'UTC' ), 'UTC' ) );
		$this->assertSame( $this->ts( '2026-01-01 09:00', 'UTC' ), $this->next( $s, $this->ts( '2026-01-01 08:59:59', 'UTC' ), 'UTC' ) );
	}

	public function test_weekly_and_wrap_around() {
		$s = array( 'frequency' => 'weekly', 'weekday' => 1 );
		$this->assertSame( $this->ts( '2026-01-05 09:00', 'UTC' ), $this->next( $s, $this->ts( '2026-01-04 10:00', 'UTC' ), 'UTC' ) );
		$this->assertSame( $this->ts( '2026-01-12 09:00', 'UTC' ), $this->next( $s, $this->ts( '2026-01-05 09:00:00', 'UTC' ), 'UTC' ) );
		$sun = array( 'frequency' => 'weekly', 'weekday' => 0 );
		$this->assertSame( $this->ts( '2026-01-11 09:00', 'UTC' ), $this->next( $sun, $this->ts( '2026-01-10 10:00', 'UTC' ), 'UTC' ) );
		$thu = array( 'frequency' => 'weekly', 'weekday' => 4 );
		$this->assertSame( $this->ts( '2027-01-07 09:00', 'UTC' ), $this->next( $thu, $this->ts( '2026-12-31 10:00', 'UTC' ), 'UTC' ) );
	}

	public function test_monthly_month_ends() {
		$d28 = array( 'frequency' => 'monthly', 'monthday' => 28 );
		$this->assertSame( $this->ts( '2026-02-28 09:00', 'UTC' ), $this->next( $d28, $this->ts( '2026-01-31 10:00', 'UTC' ), 'UTC' ) );
		$last = array( 'frequency' => 'monthly', 'monthday' => 'last' );
		$this->assertSame( $this->ts( '2026-01-31 09:00', 'UTC' ), $this->next( $last, $this->ts( '2026-01-31 08:00', 'UTC' ), 'UTC' ) );
		$this->assertSame( $this->ts( '2026-02-28 09:00', 'UTC' ), $this->next( $last, $this->ts( '2026-01-31 10:00', 'UTC' ), 'UTC' ) );
		$this->assertSame( $this->ts( '2028-02-29 09:00', 'UTC' ), $this->next( $last, $this->ts( '2028-01-31 10:00', 'UTC' ), 'UTC' ) );
		$this->assertSame( $this->ts( '2027-01-31 09:00', 'UTC' ), $this->next( $last, $this->ts( '2026-12-31 10:00', 'UTC' ), 'UTC' ) );
	}

	public function test_dst_spring_forward_chicago() {
		$z = 'America/Chicago';
		// 02:30 does not exist on March 8: runs at 03:00 CDT (08:00 UTC).
		$this->assertSame( 1772956800, $this->next( array( 'frequency' => 'daily', 'time' => '02:30' ), $this->ts( '2026-03-07 12:00', $z ), $z ) );
		// Wall clock is kept: 09:00 CDT is 14:00 UTC.
		$this->assertSame( $this->ts( '2026-03-08 14:00', 'UTC' ), $this->next( array( 'frequency' => 'daily' ), $this->ts( '2026-03-07 10:00', $z ), $z ) );
		// Hourly at :30, 02:30 missing: next is 03:00 CDT.
		$this->assertSame( $this->ts( '2026-03-08 08:00', 'UTC' ), $this->next( array( 'frequency' => 'hourly', 'time' => '00:30' ), $this->ts( '2026-03-08 07:45', 'UTC' ), $z ) );
	}

	public function test_dst_fall_back_chicago_runs_once() {
		$z = 'America/Chicago';
		$s = array( 'frequency' => 'daily', 'time' => '01:30' );
		$first = $this->ts( '2026-11-01 06:30', 'UTC' ); // 01:30 CDT.
		$this->assertSame( $first, $this->next( $s, $this->ts( '2026-10-31 12:00', $z ), $z ) );
		// After the first 01:30 the repeated 01:30 CST (07:30 UTC) is skipped.
		$tomorrow = $this->ts( '2026-11-02 07:30', 'UTC' );
		$this->assertSame( $tomorrow, $this->next( $s, $first, $z ) );
		$this->assertSame( $tomorrow, $this->next( $s, $this->ts( '2026-11-01 06:45', 'UTC' ), $z ) );
		// 09:00 on the day itself is CST (15:00 UTC).
		$this->assertSame( $this->ts( '2026-11-01 15:00', 'UTC' ), $this->next( array( 'frequency' => 'daily' ), $this->ts( '2026-11-01 05:00', 'UTC' ), $z ) );
		// Hourly at :30 skips the repeated hour.
		$this->assertSame( $this->ts( '2026-11-01 08:30', 'UTC' ), $this->next( array( 'frequency' => 'hourly', 'time' => '00:30' ), $this->ts( '2026-11-01 06:45', 'UTC' ), $z ) );
	}

	public function test_result_is_always_after_now_over_a_year() {
		$z = new DateTimeZone( 'America/Chicago' );
		$s = array( 'enabled' => true, 'frequency' => 'daily', 'time' => '02:30' );
		$t = $this->ts( '2026-01-01 00:00', 'UTC' );
		for ( $i = 0; $i < 365; $i++, $t += 86400 ) {
			$this->assertGreaterThan( $t, OPEX_Schedule_Clock::next_run( $s, $t, $z ) );
		}
	}
}
