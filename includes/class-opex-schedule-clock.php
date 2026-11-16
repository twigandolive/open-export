<?php
/**
 * Next-run math for schedules. Pure: no options, no cron, no globals except
 * the site timezone as a default.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Works out when a schedule runs next. It has no side effects, so tests can check the time zone edge cases directly.
 */
class OPEX_Schedule_Clock {

	/**
	 * Next run as a UTC timestamp, strictly after $now. 0 for manual or disabled.
	 * Wall-clock time is kept across daylight-saving changes.
	 *
	 * @param array             $schedule Schedule record (frequency, time, weekday, monthday, enabled).
	 * @param int               $now      UTC timestamp.
	 * @param DateTimeZone|null $tz       Timezone, defaults to the site timezone.
	 * @return int The next run as a UTC timestamp, or 0 when the schedule does not run by itself.
	 */
	public static function next_run( array $schedule, int $now, ?DateTimeZone $tz = null ): int {
		$freq = (string) ( $schedule['frequency'] ?? 'manual' );
		if ( empty( $schedule['enabled'] ) || ! in_array( $freq, array( 'hourly', 'every_6_hours', 'twicedaily', 'daily', 'weekly', 'monthly' ), true ) ) {
			return 0;
		}
		$tz = $tz ? $tz : wp_timezone();

		$parts = explode( ':', (string) ( $schedule['time'] ?? '00:00' ) );
		$hour  = max( 0, min( 23, (int) ( $parts[0] ?? 0 ) ) );
		$min   = max( 0, min( 59, (int) ( $parts[1] ?? 0 ) ) );

		$local = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $tz );
		// Start one day back so a zone change at midnight cannot hide a candidate.
		$day = new DateTimeImmutable( $local->format( 'Y-m-d' ) . ' 00:00:00', new DateTimeZone( 'UTC' ) );
		$day = $day->modify( '-1 day' );

		// Monthly can need a year of lookahead at most, the rest far less.
		for ( $i = 0; $i < 400; $i++ ) {
			$best = 0;
			foreach ( self::candidates( $freq, $day, $schedule, $hour, $min ) as $wall ) {
				$ts = self::wall_to_ts( $wall, $tz );
				if ( $ts > $now && ( 0 === $best || $ts < $best ) ) {
					$best = $ts;
				}
			}
			if ( $best > 0 ) {
				return $best;
			}
			$day = $day->modify( '+1 day' );
		}
		return 0;
	}

	/**
	 * Wall-clock candidates ('Y-m-d H:i') on one calendar day.
	 *
	 * @param string            $freq     Frequency.
	 * @param DateTimeImmutable $day      The calendar day (UTC midnight used as a plain date).
	 * @param array             $schedule Schedule record.
	 * @param int               $hour     Hour.
	 * @param int               $min      Minute.
	 * @return string[]
	 */
	private static function candidates( string $freq, DateTimeImmutable $day, array $schedule, int $hour, int $min ): array {
		$date = $day->format( 'Y-m-d' );
		// Hourly, every 6 hours and twice a day repeat through the day, keeping
		// the minutes of the saved time. The two longer steps are anchored on its
		// hour, so 03:00 every 6 hours means 03:00, 09:00, 15:00 and 21:00.
		$steps = array(
			'hourly'        => 1,
			'every_6_hours' => 6,
			'twicedaily'    => 12,
		);
		if ( isset( $steps[ $freq ] ) ) {
			$out = array();
			for ( $h = $hour % $steps[ $freq ]; $h < 24; $h += $steps[ $freq ] ) {
				$out[] = sprintf( '%s %02d:%02d', $date, $h, $min );
			}
			return $out;
		}
		if ( 'weekly' === $freq && (int) $day->format( 'w' ) !== (int) ( $schedule['weekday'] ?? 0 ) ) {
			return array();
		}
		if ( 'monthly' === $freq ) {
			$want = $schedule['monthday'] ?? 1;
			$dom  = (int) $day->format( 'j' );
			$last = (int) $day->format( 't' );
			$ok   = ( 'last' === $want ) ? ( $last === $dom ) : ( min( (int) $want, $last ) === $dom );
			if ( ! $ok ) {
				return array();
			}
		}
		return array( sprintf( '%s %02d:%02d', $date, $hour, $min ) );
	}

	/**
	 * Convert a wall-clock time to a UTC timestamp.
	 *
	 * A time that does not exist (the hour skipped when clocks go forward)
	 * becomes the first valid minute after the gap. A time that happens twice
	 * (the hour repeated when clocks go back) resolves to its first
	 * occurrence, the earlier UTC moment, still on summer time. So the run
	 * happens once and never an hour late. PHP alone does not do this: it
	 * picks the first occurrence in some zones (America/New_York) and the
	 * second in others (Europe/Berlin), so every offset in use around the
	 * time is tried and the earliest match wins.
	 *
	 * @param string       $wall 'Y-m-d H:i'.
	 * @param DateTimeZone $tz   Timezone.
	 * @return int The UTC timestamp.
	 */
	private static function wall_to_ts( string $wall, DateTimeZone $tz ): int {
		$dt = new DateTimeImmutable( $wall . ':00', $tz );
		$ts = $dt->getTimestamp();
		// The wall time read as if it were UTC. Less an offset, it is a real moment.
		$plain   = ( new DateTimeImmutable( $wall . ':00', new DateTimeZone( 'UTC' ) ) )->getTimestamp();
		$offsets = array( $dt->getOffset() );
		// A fixed offset such as +02:00 has no transitions, and PHP returns false then.
		$list = $tz->getTransitions( $plain - 2 * DAY_IN_SECONDS, $plain + 2 * DAY_IN_SECONDS );
		foreach ( is_array( $list ) ? $list : array() as $t ) {
			$offsets[] = (int) $t['offset'];
		}
		$best = 0;
		foreach ( array_unique( $offsets ) as $offset ) {
			$try = $plain - (int) $offset;
			if ( ( 0 === $best || $try < $best ) && ( new DateTimeImmutable( '@' . $try ) )->setTimezone( $tz )->format( 'Y-m-d H:i' ) === $wall ) {
				$best = $try;
			}
		}
		if ( $best > 0 ) {
			return $best;
		}
		// No offset gives this wall time, so it falls in a gap. PHP moved it
		// forward out of the gap. The gap ends at the transition.
		$found = $ts;
		foreach ( $tz->getTransitions( $ts - 2 * DAY_IN_SECONDS, $ts + 2 * DAY_IN_SECONDS ) as $t ) {
			if ( (int) $t['ts'] <= $ts && (int) $t['ts'] > $ts - 2 * DAY_IN_SECONDS ) {
				$found = (int) $t['ts'];
			}
		}
		return $found;
	}
}
