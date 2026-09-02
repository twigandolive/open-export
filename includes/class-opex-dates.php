<?php
/**
 * Timezone-correct date boundaries for the date-range filter.
 *
 * WordPress forces the PHP process timezone to UTC, but stores post_date,
 * comment_date and post_modified in SITE-LOCAL time, alongside *_gmt twins in
 * UTC. WooCommerce stores every HPOS date column in UTC. A range filter has to
 * know which of those it is comparing against, or it silently returns the wrong
 * rows: a shop in America/Los_Angeles asking for "today" after 5pm local gets
 * zero rows, because UTC has already rolled over to tomorrow.
 *
 * Everything here works in whole calendar days as the user perceives them, in
 * the site's timezone, and emits a half-open interval [from, before) so the last
 * day of a range is never clipped.
 */

defined( 'ABSPATH' ) || exit;

class OPEX_Dates {

	/** A date column is stored either in site-local time or in UTC. */
	public const TZ_LOCAL = 'local';
	public const TZ_UTC   = 'utc';

	/** Presets offered in the UI, in display order. */
	public const PRESETS = array(
		'all_time', 'today', 'yesterday', 'last_7_days', 'last_30_days', 'last_90_days',
		'this_week', 'last_week', 'this_month', 'last_month',
		'this_quarter', 'last_quarter', 'this_year', 'last_year', 'custom',
	);

	/**
	 * The site's timezone as configured in Settings → General, DST-aware.
	 * wp_timezone() has existed since WP 5.3; the fallback covers a raw
	 * gmt_offset with no timezone_string (a legacy WordPress setting).
	 */
	public static function site_timezone(): DateTimeZone {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}

		$tz_string = (string) get_option( 'timezone_string' );
		if ( '' !== $tz_string ) {
			try {
				return new DateTimeZone( $tz_string );
			} catch ( Exception $e ) {
				// Fall through to the offset form below.
				unset( $e );
			}
		}

		$offset  = (float) get_option( 'gmt_offset', 0 );
		$hours   = (int) $offset;
		$minutes = (int) round( ( abs( $offset ) - abs( $hours ) ) * 60 );

		try {
			return new DateTimeZone( sprintf( '%+03d:%02d', $hours, $minutes ) );
		} catch ( Exception $e ) {
			unset( $e );
			return new DateTimeZone( 'UTC' );
		}
	}

	/**
	 * Accept only a plain Y-m-d calendar date that really exists.
	 * Returns '' for anything else, so a bad value disables that end of the
	 * range instead of silently shifting it.
	 */
	public static function normalize_date( string $value ): string {
		$value = trim( $value );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return '';
		}
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * Turn a calendar date into the SQL boundary string for a column.
	 *
	 * @param string $date        Y-m-d in the site's timezone.
	 * @param string $tz_mode     TZ_LOCAL or TZ_UTC: how the column is stored.
	 * @param int    $day_offset  Days to add before taking midnight. Pass 1 for
	 *                            an inclusive end date, producing the exclusive
	 *                            upper bound of a half-open interval.
	 * @return string 'Y-m-d H:i:s', or '' when the date is unusable.
	 */
	public static function boundary( string $date, string $tz_mode, int $day_offset = 0 ): string {
		$date = self::normalize_date( $date );
		if ( '' === $date ) {
			return '';
		}

		try {
			// Midnight in the site's timezone. DateTimeImmutable resolves DST
			// for us, including days where 00:00 does not exist locally.
			$moment = new DateTimeImmutable( $date . ' 00:00:00', self::site_timezone() );
		} catch ( Exception $e ) {
			unset( $e );
			return '';
		}

		if ( 0 !== $day_offset ) {
			// Add days, not 86400-second blocks, so DST transitions stay whole.
			$moment = $moment->modify( sprintf( '%+d days', $day_offset ) );
			if ( ! $moment instanceof DateTimeImmutable ) {
				return '';
			}
		}

		if ( self::TZ_UTC === $tz_mode ) {
			$moment = $moment->setTimezone( new DateTimeZone( 'UTC' ) );
		}

		return $moment->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Re-express a site-local datetime string in a column's own timezone.
	 * Values a user types, and the datetimes relative tokens resolve to, are
	 * always site-local; a *_gmt column needs them shifted.
	 */
	public static function to_column( string $local_datetime, string $tz_mode ): string {
		$local_datetime = trim( $local_datetime );
		if ( '' === $local_datetime ) {
			return '';
		}

		try {
			$moment = new DateTimeImmutable( $local_datetime, self::site_timezone() );
		} catch ( Exception $e ) {
			unset( $e );
			return '';
		}

		if ( self::TZ_UTC === $tz_mode ) {
			$moment = $moment->setTimezone( new DateTimeZone( 'UTC' ) );
		}

		return $moment->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Split a filter value into a comparable moment.
	 * Returns array( sql_value, is_whole_day ), or null when the value is not a
	 * date at all and should be left to ordinary string comparison.
	 */
	public static function parse_value( string $value, string $tz_mode ): ?array {
		$value = trim( $value );

		// A bare calendar date means the whole of that day.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			if ( '' === self::normalize_date( $value ) ) {
				return null;
			}
			return array( $value, true );
		}

		// The compact form people type out of habit, e.g. 20260301.
		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $value, $m ) ) {
			$dashed = $m[1] . '-' . $m[2] . '-' . $m[3];
			return '' === self::normalize_date( $dashed ) ? null : array( $dashed, true );
		}

		// A datetime, with or without seconds, and tolerating an ISO "T".
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(:\d{2})?$/', $value, $m ) ) {
			if ( '' === self::normalize_date( $m[1] ) ) {
				return null;
			}
			$stamp = self::to_column( $m[1] . ' ' . $m[2] . ( $m[3] ?? ':00' ), $tz_mode );
			return '' === $stamp ? null : array( $stamp, false );
		}

		return null;
	}

	/**
	 * Resolve a preset to a pair of inclusive site-local calendar dates.
	 * Returns array( from, to ); either may be '' for an open end, and
	 * 'all_time' returns two empty strings.
	 */
	public static function preset_range( string $preset ): array {
		if ( 'all_time' === $preset || 'custom' === $preset || ! in_array( $preset, self::PRESETS, true ) ) {
			return array( '', '' );
		}

		$tz = self::site_timezone();

		try {
			// "Now" as a wall clock in the site's timezone, not in UTC.
			$today = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )
				->setTimezone( $tz )
				->setTime( 0, 0, 0 );
		} catch ( Exception $e ) {
			unset( $e );
			return array( '', '' );
		}

		$fmt = static function ( DateTimeImmutable $d ): string {
			return $d->format( 'Y-m-d' );
		};

		switch ( $preset ) {
			case 'today':
				return array( $fmt( $today ), $fmt( $today ) );

			case 'yesterday':
				$y = $today->modify( '-1 day' );
				return array( $fmt( $y ), $fmt( $y ) );

			case 'last_7_days':
				return array( $fmt( $today->modify( '-6 days' ) ), $fmt( $today ) );

			case 'last_30_days':
				return array( $fmt( $today->modify( '-29 days' ) ), $fmt( $today ) );

			case 'last_90_days':
				return array( $fmt( $today->modify( '-89 days' ) ), $fmt( $today ) );

			case 'this_week':
				return array( $fmt( self::week_start( $today ) ), $fmt( $today ) );

			case 'last_week':
				$start = self::week_start( $today )->modify( '-7 days' );
				return array( $fmt( $start ), $fmt( $start->modify( '+6 days' ) ) );

			case 'this_month':
				return array( $fmt( $today->modify( 'first day of this month' ) ), $fmt( $today ) );

			case 'last_month':
				$start = $today->modify( 'first day of last month' );
				return array( $fmt( $start ), $fmt( $start->modify( 'last day of this month' ) ) );

			case 'this_quarter':
				$start = self::quarter_start( $today );
				return array( $fmt( $start ), $fmt( $today ) );

			case 'last_quarter':
				$start = self::quarter_start( $today )->modify( '-3 months' );
				return array( $fmt( $start ), $fmt( $start->modify( '+3 months' )->modify( '-1 day' ) ) );

			case 'this_year':
				return array( $fmt( $today->modify( 'first day of january this year' ) ), $fmt( $today ) );

			case 'last_year':
				$start = $today->modify( 'first day of january last year' );
				return array( $fmt( $start ), $fmt( $start->modify( 'last day of december this year' ) ) );
		}

		return array( '', '' );
	}

	/**
	 * Start of the week containing $day, honouring the site's
	 * "Week Starts On" setting rather than assuming Monday.
	 */
	private static function week_start( DateTimeImmutable $day ): DateTimeImmutable {
		$start_of_week = (int) get_option( 'start_of_week', 1 ); // 0 = Sunday.
		$weekday       = (int) $day->format( 'w' );              // 0 = Sunday.
		$back          = ( $weekday - $start_of_week + 7 ) % 7;

		$start = $day->modify( sprintf( '-%d days', $back ) );
		return $start instanceof DateTimeImmutable ? $start : $day;
	}

	/** First day of the calendar quarter containing $day. */
	private static function quarter_start( DateTimeImmutable $day ): DateTimeImmutable {
		$month = (int) $day->format( 'n' );
		$first = $month - ( ( $month - 1 ) % 3 );

		$start = $day->setDate( (int) $day->format( 'Y' ), $first, 1 );
		return $start instanceof DateTimeImmutable ? $start : $day;
	}

	/**
	 * Human label for a preset. Kept here so PHP and the REST payload agree
	 * on the wording the JS renders.
	 */
	public static function preset_label( string $preset ): string {
		$labels = array(
			'all_time'     => __( 'All time', 'open-export' ),
			'today'        => __( 'Today', 'open-export' ),
			'yesterday'    => __( 'Yesterday', 'open-export' ),
			'last_7_days'  => __( 'Last 7 days', 'open-export' ),
			'last_30_days' => __( 'Last 30 days', 'open-export' ),
			'last_90_days' => __( 'Last 90 days', 'open-export' ),
			'this_week'    => __( 'This week', 'open-export' ),
			'last_week'    => __( 'Last week', 'open-export' ),
			'this_month'   => __( 'This month', 'open-export' ),
			'last_month'   => __( 'Last month', 'open-export' ),
			'this_quarter' => __( 'This quarter', 'open-export' ),
			'last_quarter' => __( 'Last quarter', 'open-export' ),
			'this_year'    => __( 'This year', 'open-export' ),
			'last_year'    => __( 'Last year', 'open-export' ),
			'custom'       => __( 'Custom range…', 'open-export' ),
		);

		return $labels[ $preset ] ?? $preset;
	}
}
