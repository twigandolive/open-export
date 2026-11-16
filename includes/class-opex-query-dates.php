<?php
/**
 * Date columns per source and the SQL for date ranges and date rules.
 *
 * Part of the query compartment. Reached only through OPEX_Query.
 */

// No strict_types here on purpose: this file holds moved code, and strict types would change how arguments are coerced.

defined( 'ABSPATH' ) || exit;

class OPEX_Query_Dates {

	/**
	 * Date columns a range filter may target for this source, with how each one
	 * is stored. This doubles as the allowlist: a range naming anything not in
	 * here is dropped, so no caller-supplied string ever reaches the SQL.
	 *
	 * @return array<string, array{label: string, tz: string, sql: string}>
	 */
	public static function date_fields( array $src ): array {
		static $memo = array();
		$key = md5( (string) wp_json_encode( $src ) );
		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}

		$memo[ $key ] = self::build_date_fields( $src );
		return $memo[ $key ];
	}

	/** @see date_fields() - split out so the memo above wraps every branch. */
	private static function build_date_fields( array $src ): array {
		switch ( $src['type'] ) {
			case 'woo_orders':
				return OPEX_Woo::hpos_date_fields();

			case 'posts':
				if ( ! empty( $src['woo'] ) ) {
					// Orders name their dates by what they mean, plus the raw
					// post columns for anyone who wants them.
					return OPEX_Woo::legacy_date_fields() + array(
						'core:post_date'     => array( 'label' => __( 'Order date (site time)', 'open-export' ), 'tz' => OPEX_Dates::TZ_LOCAL, 'sql' => 'p.post_date' ),
						'core:post_date_gmt' => array( 'label' => __( 'Order date (UTC)', 'open-export' ), 'tz' => OPEX_Dates::TZ_UTC, 'sql' => 'p.post_date_gmt' ),
					);
				}
				return array(
					'core:post_date'         => array( 'label' => __( 'Date published (site time)', 'open-export' ), 'tz' => OPEX_Dates::TZ_LOCAL, 'sql' => 'p.post_date' ),
					'core:post_date_gmt'     => array( 'label' => __( 'Date published (UTC)', 'open-export' ), 'tz' => OPEX_Dates::TZ_UTC, 'sql' => 'p.post_date_gmt' ),
					'core:post_modified'     => array( 'label' => __( 'Last modified (site time)', 'open-export' ), 'tz' => OPEX_Dates::TZ_LOCAL, 'sql' => 'p.post_modified' ),
					'core:post_modified_gmt' => array( 'label' => __( 'Last modified (UTC)', 'open-export' ), 'tz' => OPEX_Dates::TZ_UTC, 'sql' => 'p.post_modified_gmt' ),
				);

			case 'users':
				// WordPress writes user_registered in UTC.
				return array(
					'core:user_registered' => array( 'label' => __( 'Registered', 'open-export' ), 'tz' => OPEX_Dates::TZ_UTC, 'sql' => 'u.user_registered' ),
				);

			case 'comments':
				return array(
					'core:comment_date'     => array( 'label' => __( 'Comment date (site time)', 'open-export' ), 'tz' => OPEX_Dates::TZ_LOCAL, 'sql' => 'c.comment_date' ),
					'core:comment_date_gmt' => array( 'label' => __( 'Comment date (UTC)', 'open-export' ), 'tz' => OPEX_Dates::TZ_UTC, 'sql' => 'c.comment_date_gmt' ),
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
					// A bare table tells us nothing about its timezone, so go by
					// the naming convention every WordPress and WooCommerce table
					// follows and treat the rest as site-local.
					$utc = (bool) preg_match( '/(_gmt|_utc)$/i', $col['name'] );
					$out[ 'col:' . $col['name'] ] = array(
						'label' => $col['name'] . ( $utc ? ' (UTC)' : '' ),
						'tz'    => $utc ? OPEX_Dates::TZ_UTC : OPEX_Dates::TZ_LOCAL,
						'sql'   => '`' . str_replace( '`', '', $col['name'] ) . '`',
					);
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
	 */
	public static function date_range_clause( array $src, array $range, array &$args ): string {
		$field  = (string) ( $range['field'] ?? '' );
		$preset = (string) ( $range['preset'] ?? 'all_time' );
		$from   = (string) ( $range['from'] ?? '' );
		$to     = (string) ( $range['to'] ?? '' );

		// A relative preset that has not been pinned yet - a preview, or a
		// template loaded straight from storage - resolves against now.
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

		$start = OPEX_Dates::boundary( $from, $def['tz'], 0 );
		if ( '' !== $start ) {
			$args[]  = $start;
			$parts[] = "{$def['sql']} >= %s";
		}

		// +1 day turns the user's inclusive end date into the exclusive bound.
		$end = OPEX_Dates::boundary( $to, $def['tz'], 1 );
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
	 * Range over a meta value holding a UNIX timestamp, as legacy WooCommerce
	 * stores _date_paid and _date_completed.
	 *
	 * The epochs are computed in PHP from the site's timezone, so the result
	 * does not depend on the database session timezone the way UNIX_TIMESTAMP()
	 * would. EXISTS also means an order with no such meta row is excluded,
	 * which is right: an unpaid order has no paid date to fall inside a range.
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
	 */
	public static function date_condition( array $def, string $op, string $value, array &$args ): ?string {
		if ( ! in_array( $op, array( 'eq', 'neq', 'gt', 'gte', 'lt', 'lte' ), true ) ) {
			return null;
		}
		if ( 'meta_epoch' === ( $def['kind'] ?? 'column' ) ) {
			// Epoch meta has no column to compare against; ranges handle these.
			return null;
		}

		$parsed = OPEX_Dates::parse_value( $value, $def['tz'] );
		if ( null === $parsed ) {
			return null;
		}

		list( $stamp, $whole_day ) = $parsed;
		$col = $def['sql'];

		if ( ! $whole_day ) {
			// An explicit time compares literally, with no day rounding.
			$sym    = array( 'eq' => '=', 'neq' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=' )[ $op ];
			$args[] = $stamp;
			return "{$col} {$sym} %s";
		}

		$day_start  = OPEX_Dates::boundary( $stamp, $def['tz'], 0 );
		$next_start = OPEX_Dates::boundary( $stamp, $def['tz'], 1 );
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
}
