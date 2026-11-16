<?php
/**
 * Turns one export filter rule into a SQL fragment.
 *
 * Part of the query compartment. Reached only through OPEX_Query.
 */

// No strict_types here on purpose: this file holds moved code, and strict types would change how arguments are coerced.

defined( 'ABSPATH' ) || exit;

class OPEX_Query_Filters {

	/**
	 * Compile one filter rule into SQL, appending its values to $args.
	 *
	 * Internal to the query compartment: other code calls OPEX_Query.
	 */
	public static function rule_clause( array $src, array $rule, array &$args ): string {
		$field = (string) ( $rule['field'] ?? '' );
		$op    = (string) ( $rule['op'] ?? 'eq' );
		$value = self::resolve_filter_value( (string) ( $rule['value'] ?? '' ) );

		if ( ! in_array( $op, OPEX_Query::OPS, true ) ) {
			return '';
		}

		// A rule on a real date column gets calendar semantics: "on or before
		// 2026-03-31" means through the end of that day, and a value like
		// 20260301 is a date, not a number to cast the column down to.
		$date_defs = OPEX_Query_Dates::date_fields( $src );
		$is_date   = isset( $date_defs[ $field ] );
		if ( $is_date ) {
			$clause = OPEX_Query_Dates::date_condition( $date_defs[ $field ], $op, $value, $args );
			if ( null !== $clause ) {
				return $clause;
			}
			// Not a date we could parse. Compare it as text anyway, so the
			// column is never cast down to a bare year.
			if ( isset( $date_defs[ $field ]['sql'] ) ) {
				return self::value_condition( $date_defs[ $field ]['sql'], $op, $value, $args, true );
			}
		}

		// Friendly WooCommerce field rule, e.g. woo:status.
		if ( 'woo_orders' === $src['type'] && OPEX_Woo::is_woo_field( $field ) ) {
			$map = OPEX_Woo::field_map();
			return self::value_condition( $map[ $field ]['expr'], $op, $value, $args );
		}

		// On a legacy store the same id is a post column or a meta key.
		$field = OPEX_Query::resolve_field( $src, $field );

		// Core column rule, e.g. core:post_status.
		if ( 0 === strpos( $field, 'core:' ) ) {
			$col = substr( $field, 5 );
			if ( ! OPEX_Query::valid_core_col( $src['type'], $col ) ) {
				return '';
			}
			return self::value_condition( OPEX_Query::alias( $src['type'] ) . '.' . $col, $op, $value, $args );
		}

		// Raw table column rule, e.g. col:option_name.
		if ( 0 === strpos( $field, 'col:' ) && 'table' === $src['type'] ) {
			$col = substr( $field, 4 );
			if ( ! OPEX_Query::valid_table_col( $src['table'], $col ) ) {
				return '';
			}
			return self::value_condition( '`' . str_replace( '`', '', $col ) . '`', $op, $value, $args );
		}

		// Meta rule, e.g. meta:_sku; EXISTS / NOT EXISTS subquery.
		if ( 0 === strpos( $field, 'meta:' ) && 'table' !== $src['type'] ) {
			return self::meta_clause( $src, substr( $field, 5 ), $op, $value, $args );
		}

		// Taxonomy rule, e.g. tax:product_cat (posts only).
		if ( 0 === strpos( $field, 'tax:' ) && 'posts' === $src['type'] ) {
			$tax = substr( $field, 4 );
			return taxonomy_exists( $tax ) ? self::tax_clause( $tax, $op, $value, $args ) : '';
		}

		// Role rule for users: matches the serialized capabilities meta.
		if ( 'computed:roles' === $field && 'users' === $src['type'] ) {
			global $wpdb;
			$args[] = $wpdb->get_blog_prefix() . 'capabilities';
			$args[] = '%"' . $wpdb->esc_like( sanitize_key( $value ) ) . '"%';
			$sql    = "EXISTS (SELECT 1 FROM {$wpdb->usermeta} um WHERE um.user_id = u.ID AND um.meta_key = %s AND um.meta_value LIKE %s)";
			return in_array( $op, array( 'neq', 'not_contains', 'not_in' ), true ) ? "NOT {$sql}" : $sql;
		}

		return '';
	}

	/**
	 * Turn relative date tokens into concrete datetimes for filter values.
	 * Supports: last N days/weeks/months, today, yesterday, this/last month/year, -N days.
	 *
	 * These resolve in the SITE's timezone, matching post_date, comment_date and
	 * post_modified. They used to resolve in UTC, which WordPress sets as the PHP
	 * process timezone: on a UTC-8 site every evening after 4pm local, "today"
	 * produced tomorrow's UTC date and the filter matched nothing at all.
	 *
	 * For a *_gmt column, use the date-range control instead - it knows which
	 * columns are UTC and converts accordingly.
	 */
	private static function resolve_filter_value( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return $value;
		}

		$lower = strtolower( $value );

		try {
			$now = new DateTimeImmutable( 'now', OPEX_Dates::site_timezone() );
		} catch ( Exception $e ) {
			unset( $e );
			return $value;
		}

		$out = static function ( $moment ) use ( $value ) {
			return $moment instanceof DateTimeImmutable ? $moment->format( 'Y-m-d H:i:s' ) : $value;
		};

		if ( preg_match( '/^last\s+(\d+)\s+(day|days|week|weeks|month|months|year|years)$/i', $value, $matches ) ) {
			$n    = (int) $matches[1];
			$unit = rtrim( strtolower( $matches[2] ), 's' ) . 's';
			return $out( $now->modify( '-' . $n . ' ' . $unit ) );
		}

		// Each of these names a day, so they start at midnight rather than at
		// whatever time of day the export happens to run.
		$phrases = array(
			'today'      => 'today',
			'yesterday'  => '-1 day',
			'this month' => 'first day of this month',
			'last month' => 'first day of last month',
			'this year'  => 'first day of january this year',
			'last year'  => 'first day of january last year',
		);

		if ( isset( $phrases[ $lower ] ) ) {
			$moment = 'today' === $phrases[ $lower ] ? $now : $now->modify( $phrases[ $lower ] );
			return $out( $moment instanceof DateTimeImmutable ? $moment->setTime( 0, 0, 0 ) : $moment );
		}

		if ( preg_match( '/^-\d+\s+(day|days|week|weeks|month|months|year|years)$/i', $value ) ) {
			return $out( $now->modify( $value ) );
		}

		return $value;
	}

	/**
	 * Direct comparison against a validated column reference.
	 *
	 * $textual forbids the numeric branch below. Casting a DATETIME column to
	 * DECIMAL yields the year and nothing else, so a date column must never
	 * take that path however numeric the value happens to look.
	 */
	private static function value_condition( string $lhs, string $op, string $value, array &$args, bool $textual = false ): string {
		global $wpdb;

		switch ( $op ) {
			case 'eq':
				$args[] = $value;
				return "{$lhs} = %s";
			case 'neq':
				$args[] = $value;
				return "{$lhs} <> %s";
			case 'contains':
				$args[] = '%' . $wpdb->esc_like( $value ) . '%';
				return "{$lhs} LIKE %s";
			case 'not_contains':
				$args[] = '%' . $wpdb->esc_like( $value ) . '%';
				return "{$lhs} NOT LIKE %s";
			case 'starts_with':
				$args[] = $wpdb->esc_like( $value ) . '%';
				return "{$lhs} LIKE %s";
			case 'ends_with':
				$args[] = '%' . $wpdb->esc_like( $value );
				return "{$lhs} LIKE %s";
			case 'gt':
			case 'gte':
			case 'lt':
			case 'lte':
				$sym = array( 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=' )[ $op ];
				if ( ! $textual && is_numeric( $value ) ) {
					$args[] = (float) $value;
					return "CAST({$lhs} AS DECIMAL(24,6)) {$sym} %f";
				}
				$args[] = $value;
				return "{$lhs} {$sym} %s";
			case 'in':
			case 'not_in':
				$list = array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' );
				if ( ! $list ) {
					return '';
				}
				$holders = implode( ',', array_fill( 0, count( $list ), '%s' ) );
				foreach ( $list as $item ) {
					$args[] = $item;
				}
				return "{$lhs} " . ( 'in' === $op ? 'IN' : 'NOT IN' ) . " ({$holders})";
			case 'empty':
				return "({$lhs} = '' OR {$lhs} IS NULL)";
			case 'not_empty':
				return "({$lhs} <> '' AND {$lhs} IS NOT NULL)";
		}
		return '';
	}

	/**
	 * Meta comparisons run as EXISTS subqueries. Negative operators become
	 * NOT EXISTS of the positive form, so rows without the meta key also match.
	 */
	private static function meta_clause( array $src, string $key, string $op, string $value, array &$args ): string {
		global $wpdb;

		$map = array(
			'posts'      => array( $wpdb->postmeta, 'post_id', 'p.ID' ),
			'users'      => array( $wpdb->usermeta, 'user_id', 'u.ID' ),
			'comments'   => array( $wpdb->commentmeta, 'comment_id', 'c.comment_ID' ),
			'terms'      => array( $wpdb->termmeta, 'term_id', 't.term_id' ),
			'woo_orders' => array( OPEX_Woo::table( 'meta' ), 'order_id', 'o.id' ),
		);
		if ( ! isset( $map[ $src['type'] ] ) ) {
			return '';
		}
		list( $table, $fk, $outer ) = $map[ $src['type'] ];

		$negatives = array( 'neq' => 'eq', 'not_contains' => 'contains', 'not_in' => 'in' );
		$negate    = isset( $negatives[ $op ] );
		$inner_op  = $negate ? $negatives[ $op ] : $op;

		if ( 'empty' === $op ) {
			$args[] = $key;
			return "NOT EXISTS (SELECT 1 FROM {$table} m WHERE m.{$fk} = {$outer} AND m.meta_key = %s AND m.meta_value <> '')";
		}
		if ( 'not_empty' === $op ) {
			$args[] = $key;
			return "EXISTS (SELECT 1 FROM {$table} m WHERE m.{$fk} = {$outer} AND m.meta_key = %s AND m.meta_value <> '')";
		}

		$args[] = $key;
		$cond   = self::value_condition( 'm.meta_value', $inner_op, $value, $args );
		if ( ! $cond ) {
			array_pop( $args );
			return '';
		}

		$sql = "EXISTS (SELECT 1 FROM {$table} m WHERE m.{$fk} = {$outer} AND m.meta_key = %s AND {$cond})";
		return $negate ? "NOT {$sql}" : $sql;
	}

	/** Taxonomy membership tests match term slug or name. */
	private static function tax_clause( string $tax, string $op, string $value, array &$args ): string {
		global $wpdb;

		$exists_open = "EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr" .
			" INNER JOIN {$wpdb->term_taxonomy} xt ON xt.term_taxonomy_id = tr.term_taxonomy_id" .
			" INNER JOIN {$wpdb->terms} xtm ON xtm.term_id = xt.term_id" .
			' WHERE tr.object_id = p.ID AND xt.taxonomy = %s';

		if ( 'empty' === $op ) {
			$args[] = $tax;
			return 'NOT ' . $exists_open . ')';
		}
		if ( 'not_empty' === $op ) {
			$args[] = $tax;
			return $exists_open . ')';
		}

		$list = ( 'in' === $op || 'not_in' === $op )
			? array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' )
			: array( $value );
		if ( ! $list ) {
			return '';
		}

		$args[] = $tax;

		if ( 'contains' === $op || 'not_contains' === $op ) {
			$args[] = '%' . $wpdb->esc_like( $value ) . '%';
			$sql    = $exists_open . ' AND xtm.name LIKE %s)';
			return 'not_contains' === $op ? "NOT {$sql}" : $sql;
		}

		$holders = implode( ',', array_fill( 0, count( $list ), '%s' ) );
		foreach ( $list as $item ) {
			$args[] = $item;
		}
		foreach ( $list as $item ) {
			$args[] = $item;
		}
		$sql = $exists_open . " AND (xtm.slug IN ({$holders}) OR xtm.name IN ({$holders})))";

		return in_array( $op, array( 'neq', 'not_in' ), true ) ? "NOT {$sql}" : $sql;
	}
}
