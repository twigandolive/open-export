<?php
/**
 * Date columns per source and the SQL for date ranges and date rules.
 *
 * Part of the query compartment. Reached only through OPEX_Query.
 *
 * @package Open_Export
 */

// No strict_types here on purpose: strict types would change how this code coerces arguments.

defined( 'ABSPATH' ) || exit;

/**
 * Knows each source's date columns and turns date ranges and date rules into SQL.
 */
class OPEX_Query_Dates {

	/**
	 * Date columns a range filter may target for this source, with how each one
	 * is stored. This doubles as the allowlist: a range naming anything not in
	 * here is dropped, so no caller-supplied string ever reaches the SQL.
	 *
	 * @param array $src Parsed source.
	 * @return array<string, array{label: string, tz: string, sql: string}>
	 */
	public static function date_fields( array $src ): array {
		static $memo = array();
		// Keyed by site too: some entries name a site's own meta table.
		$key = md5( (string) wp_json_encode( $src ) . '|' . get_current_blog_id() );
		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}

		$memo[ $key ] = self::build_date_fields( $src );
		return $memo[ $key ];
	}

	/**
	 * Builds the date fields of a source. Kept apart from date_fields() so its
	 * memo wraps every branch.
	 *
	 * @param array $src Parsed source.
	 * @return array<string, array> Date fields by field id, each with a label and how its time is stored.
	 */
	private static function build_date_fields( array $src ): array {
		$provider = OPEX_Sources::for_src( $src );
		if ( $provider ) {
			return $provider->date_fields();
		}

		switch ( $src['type'] ) {
			case 'posts':
				return array(
					'core:post_date'         => array(
						'label' => __( 'Date published (site time)', 'open-export' ),
						'tz'    => OPEX_Dates::TZ_LOCAL,
						'sql'   => 'p.post_date',
					),
					'core:post_date_gmt'     => array(
						'label' => __( 'Date published (UTC)', 'open-export' ),
						'tz'    => OPEX_Dates::TZ_UTC,
						'sql'   => 'p.post_date_gmt',
					),
					'core:post_modified'     => array(
						'label' => __( 'Last modified (site time)', 'open-export' ),
						'tz'    => OPEX_Dates::TZ_LOCAL,
						'sql'   => 'p.post_modified',
					),
					'core:post_modified_gmt' => array(
						'label' => __( 'Last modified (UTC)', 'open-export' ),
						'tz'    => OPEX_Dates::TZ_UTC,
						'sql'   => 'p.post_modified_gmt',
					),
				);

			case 'users':
				// WordPress writes user_registered in UTC.
				return array(
					'core:user_registered' => array(
						'label' => __( 'Registered', 'open-export' ),
						'tz'    => OPEX_Dates::TZ_UTC,
						'sql'   => 'u.user_registered',
					),
				);

			case 'comments':
				return array(
					'core:comment_date'     => array(
						'label' => __( 'Comment date (site time)', 'open-export' ),
						'tz'    => OPEX_Dates::TZ_LOCAL,
						'sql'   => 'c.comment_date',
					),
					'core:comment_date_gmt' => array(
						'label' => __( 'Comment date (UTC)', 'open-export' ),
						'tz'    => OPEX_Dates::TZ_UTC,
						'sql'   => 'c.comment_date_gmt',
					),
				);

			case 'table':
				$out = array();
				foreach ( OPEX_Catalog::table_columns( $src['table'] ) as $col ) {
					if ( ! preg_match( '/^(datetime|timestamp|date)\b/i', $col['type'] ) ) {
						continue;
					}
					if ( OPEX_Query::is_forbidden_column( $col['name'] ) ) {
						continue;
					}
					// A bare table says nothing about its timezone, so go by
					// the naming convention every WordPress and WooCommerce table
					// follows and treat the rest as site-local.
					$utc = (bool) preg_match( '/(_gmt|_utc)$/i', $col['name'] );
					$def = array(
						'label' => $col['name'] . ( $utc ? ' (UTC)' : '' ),
						'tz'    => $utc ? OPEX_Dates::TZ_UTC : OPEX_Dates::TZ_LOCAL,
						'sql'   => '`' . str_replace( '`', '', $col['name'] ) . '`',
					);
					// A DATE column holds no time. It is compared with plain
					// Y-m-d days: SQLite compares text, and 2026-03-31 sorts
					// before 2026-03-31 00:00:00, which would drop the first day.
					if ( preg_match( '/^date\b/i', $col['type'] ) ) {
						$def['date_only'] = true;
					}
					$out[ 'col:' . $col['name'] ] = $def;
				}
				return $out;
		}

		return array();
	}

	/**
	 * Compile the date range into a half-open interval, [start, end).
	 *
	 * Using "< the day after" rather than "<= the end date" is what makes an
	 * inclusive end date actually inclusive: a plain "<= 2026-03-31" is widened
	 * by MySQL to 2026-03-31 00:00:00 and drops that entire day's rows.
	 *
	 * Internal to the query compartment: other code calls OPEX_Query.
	 *
	 * @param array $src   Parsed source.
	 * @param array $range Clean date range: field, preset, from and to.
	 * @param array $args  Receives the values for the SQL placeholders.
	 * @return string The SQL condition, or '' when the range is off.
	 */
	public static function date_range_clause( array $src, array $range, array &$args ): string {
		$field  = (string) ( $range['field'] ?? '' );
		$preset = (string) ( $range['preset'] ?? 'all_time' );
		$from   = (string) ( $range['from'] ?? '' );
		$to     = (string) ( $range['to'] ?? '' );

		// A relative preset that has not been pinned yet (a preview, or a
		// template loaded straight from storage) resolves against now.
		if ( '' === $from && '' === $to && 'all_time' !== $preset && 'custom' !== $preset ) {
			list( $from, $to ) = OPEX_Dates::preset_range( $preset );
		}

		if ( '' === $from && '' === $to ) {
			return '';
		}

		$defs = self::date_fields( $src );
		if ( ! isset( $defs[ $field ] ) ) {
			return '';
		}
		$def = $defs[ $field ];

		// Some order dates are not columns at all: legacy WooCommerce keeps
		// _date_paid and _date_completed in postmeta as UNIX timestamps.
		if ( 'meta_epoch' === ( $def['kind'] ?? 'column' ) ) {
			return self::meta_epoch_range( $def, $from, $to, $args );
		}

		$parts = array();

		$start = self::day_bound( $def, $from, 0 );
		if ( '' !== $start ) {
			$args[]  = $start;
			$parts[] = "{$def['sql']} >= %s";
		}

		// +1 day turns the user's inclusive end date into the exclusive bound.
		$end = self::day_bound( $def, $to, 1 );
		if ( '' !== $end ) {
			$args[]  = $end;
			$parts[] = "{$def['sql']} < %s";
		}

		if ( ! $parts ) {
			return '';
		}

		// A NULL date (an unpaid order has no date_paid_gmt) must fall outside
		// every range rather than being swept in by a one-sided comparison.
		$parts[] = "{$def['sql']} IS NOT NULL";

		return '(' . implode( ' AND ', $parts ) . ')';
	}

	/**
	 * The "since" term of an incremental run: rows dated strictly after a UTC
	 * moment. The field must be one of the source's own date fields. A bad
	 * field matches nothing, so a broken incremental run never exports
	 * everything again.
	 *
	 * Internal to the query compartment: other code calls OPEX_Query.
	 *
	 * @param array $src   Parsed source.
	 * @param array $since Array with field and after (a UTC Y-m-d H:i:s).
	 * @param array $args  Receives prepare() values.
	 * @return string The condition, or '' when there is no since term.
	 */
	public static function since_clause( array $src, array $since, array &$args ): string {
		$field = (string) ( $since['field'] ?? '' );
		$after = (string) ( $since['after'] ?? '' );
		if ( '' === $field && '' === $after ) {
			return '';
		}
		$defs = self::date_fields( $src );
		$utc  = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $after, new DateTimeZone( 'UTC' ) );
		if ( ! isset( $defs[ $field ] ) || ! $utc ) {
			return '1=0';
		}
		$def = $defs[ $field ];

		if ( 'meta_epoch' === ( $def['kind'] ?? 'column' ) ) {
			$args[] = $def['key'];
			$args[] = $utc->getTimestamp();
			return "EXISTS (SELECT 1 FROM {$def['table']} m WHERE m.{$def['fk']} = {$def['outer']} AND m.meta_key = %s AND m.meta_value <> '' AND CAST(m.meta_value AS UNSIGNED) > %d)";
		}

		$moment = OPEX_Dates::TZ_UTC === $def['tz'] ? $utc : $utc->setTimezone( OPEX_Dates::site_timezone() );
		// A DATE column is later than the moment when its day is later than the moment's day.
		$args[] = $moment->format( empty( $def['date_only'] ) ? 'Y-m-d H:i:s' : 'Y-m-d' );
		return "({$def['sql']} > %s AND {$def['sql']} IS NOT NULL)";
	}

	/**
	 * The start of a calendar day, moved by whole days, in the form the column
	 * compares: a datetime in the column's timezone, or a plain Y-m-d for a
	 * DATE column, which has no time and no timezone to shift into.
	 *
	 * @param array  $def        Date field definition.
	 * @param string $date       Calendar date as Y-m-d.
	 * @param int    $day_offset Days to add. 1 gives the exclusive end of that day.
	 * @return string The bound, or '' when the date is unusable.
	 */
	private static function day_bound( array $def, string $date, int $day_offset ): string {
		if ( ! empty( $def['date_only'] ) ) {
			return OPEX_Dates::calendar_day( $date, $day_offset );
		}
		return OPEX_Dates::boundary( $date, $def['tz'], $day_offset );
	}

	/**
	 * Range over a meta value holding a UNIX timestamp, as legacy WooCommerce
	 * stores _date_paid and _date_completed.
	 *
	 * The epochs are computed in PHP from the site's timezone, so the result
	 * does not depend on the database session timezone the way UNIX_TIMESTAMP()
	 * would. EXISTS also means an order with no such meta row is excluded,
	 * which is right: an unpaid order has no paid date to fall inside a range.
	 *
	 * @param array  $def  Date field definition, which names the meta table and key.
	 * @param string $from Start date as Y-m-d, or '' for an open start.
	 * @param string $to   End date as Y-m-d, or '' for an open end.
	 * @param array  $args Receives the values for the SQL placeholders.
	 * @return string The SQL condition, or '' when neither end is set.
	 */
	private static function meta_epoch_range( array $def, string $from, string $to, array &$args ): string {
		$inner = array( 'm.meta_key = %s', "m.meta_value <> ''" );
		$vals  = array( $def['key'] );

		$start = OPEX_Dates::epoch( $from, 0 );
		if ( null !== $start ) {
			$inner[] = 'CAST(m.meta_value AS UNSIGNED) >= %d';
			$vals[]  = $start;
		}

		$end = OPEX_Dates::epoch( $to, 1 );
		if ( null !== $end ) {
			$inner[] = 'CAST(m.meta_value AS UNSIGNED) < %d';
			$vals[]  = $end;
		}

		if ( count( $vals ) < 2 ) {
			return '';
		}

		foreach ( $vals as $value ) {
			$args[] = $value;
		}

		return "EXISTS (SELECT 1 FROM {$def['table']} m WHERE m.{$def['fk']} = {$def['outer']} AND " .
			implode( ' AND ', $inner ) . ')';
	}

	/**
	 * Comparison against a column known to hold dates.
	 *
	 * A bare Y-m-d names a whole day, so the comparison is built from that day's
	 * boundaries rather than from midnight alone: "<= 2026-03-31" becomes
	 * "< 2026-04-01", which is what a person means by an end date. Values are
	 * read as site-local and shifted into the column's own timezone.
	 *
	 * Returns null when the value is not a date at all, so the caller can fall
	 * back to ordinary string comparison.
	 *
	 * Internal to the query compartment: other code calls OPEX_Query.
	 *
	 * @param array  $def   Date field definition with its SQL column and how it stores time.
	 * @param string $op    Filter operator.
	 * @param string $value Filter value.
	 * @param array  $args  Receives the values for the SQL placeholders.
	 * @return string|null The SQL condition, or null when the value is not a date.
	 */
	public static function date_condition( array $def, string $op, string $value, array &$args ): ?string {
		if ( ! in_array( $op, array( 'eq', 'neq', 'gt', 'gte', 'lt', 'lte' ), true ) ) {
			return null;
		}
		if ( 'meta_epoch' === ( $def['kind'] ?? 'column' ) ) {
			// Epoch meta has no column to compare against. Ranges handle these.
			return null;
		}

		$parsed = OPEX_Dates::parse_value( $value, $def['tz'] );
		if ( null === $parsed ) {
			return null;
		}

		list( $stamp, $whole_day ) = $parsed;
		$col                       = $def['sql'];

		if ( ! $whole_day ) {
			// An explicit time compares literally, with no day rounding.
			$sym    = array(
				'eq'  => '=',
				'neq' => '<>',
				'gt'  => '>',
				'gte' => '>=',
				'lt'  => '<',
				'lte' => '<=',
			)[ $op ];
			$args[] = $stamp;
			return "{$col} {$sym} %s";
		}

		$day_start  = self::day_bound( $def, $stamp, 0 );
		$next_start = self::day_bound( $def, $stamp, 1 );
		if ( '' === $day_start || '' === $next_start ) {
			return null;
		}

		switch ( $op ) {
			case 'eq':
				$args[] = $day_start;
				$args[] = $next_start;
				return "({$col} >= %s AND {$col} < %s)";
			case 'neq':
				$args[] = $day_start;
				$args[] = $next_start;
				return "({$col} < %s OR {$col} >= %s)";
			case 'gte':
				$args[] = $day_start;
				return "{$col} >= %s";
			case 'gt':
				// Strictly after that day.
				$args[] = $next_start;
				return "{$col} >= %s";
			case 'lt':
				$args[] = $day_start;
				return "{$col} < %s";
			case 'lte':
				// Through the end of that day.
				$args[] = $next_start;
				return "{$col} < %s";
		}

		return null;
	}

	/**
	 * Comparison against a meta value that holds a UNIX timestamp, for the
	 * providers that opt in (a product's sale dates, a coupon's expiry).
	 * A bare Y-m-d names the whole site-time day, as in date_condition().
	 *
	 * Returns null when the operator or value is not a date comparison, so the
	 * caller can fall back to the ordinary meta rule.
	 *
	 * Internal to the query compartment: other code calls OPEX_Query.
	 *
	 * @param array  $def   A meta_epoch date definition (key, table, fk, outer).
	 * @param string $op    Operator.
	 * @param string $value Rule value, relative tokens already resolved.
	 * @param array  $args  Receives prepare() values.
	 * @return string|null The SQL condition, or null when the value is not a date.
	 */
	public static function epoch_condition( array $def, string $op, string $value, array &$args ): ?string {
		if ( 'meta_epoch' !== ( $def['kind'] ?? '' ) || ! in_array( $op, array( 'eq', 'neq', 'gt', 'gte', 'lt', 'lte' ), true ) ) {
			return null;
		}
		$parsed = OPEX_Dates::parse_value( $value, OPEX_Dates::TZ_UTC );
		if ( null === $parsed ) {
			return null;
		}
		list( $stamp, $whole_day ) = $parsed;
		if ( $whole_day ) {
			$start = OPEX_Dates::epoch( $stamp, 0 );
			$next  = OPEX_Dates::epoch( $stamp, 1 );
		} else {
			$utc   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $stamp, new DateTimeZone( 'UTC' ) );
			$start = $utc ? $utc->getTimestamp() : null;
			$next  = null === $start ? null : $start + 1;
		}
		if ( null === $start || null === $next ) {
			return null;
		}

		$col                   = 'CAST(m.meta_value AS UNSIGNED)';
		$tests                 = array(
			'eq'  => array( "{$col} >= %d AND {$col} < %d", array( $start, $next ) ),
			'neq' => array( "({$col} < %d OR {$col} >= %d)", array( $start, $next ) ),
			'gt'  => array( "{$col} >= %d", array( $next ) ),
			'gte' => array( "{$col} >= %d", array( $start ) ),
			'lt'  => array( "{$col} < %d", array( $start ) ),
			'lte' => array( "{$col} < %d", array( $next ) ),
		);
		list( $test, $values ) = $tests[ $op ];

		// A row with no timestamp has no date to compare, so it never matches.
		$args[] = $def['key'];
		foreach ( $values as $v ) {
			$args[] = $v;
		}
		return "EXISTS (SELECT 1 FROM {$def['table']} m WHERE m.{$def['fk']} = {$def['outer']} AND m.meta_key = %s AND m.meta_value <> '' AND {$test})";
	}
}
