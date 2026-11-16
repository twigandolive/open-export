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
		global $wpdb;

		$field = (string) ( $rule['field'] ?? '' );
		$op    = (string) ( $rule['op'] ?? 'eq' );
		$value = trim( (string) ( $rule['value'] ?? '' ) );

		if ( ! in_array( $op, OPEX_Query::OPS, true ) ) {
			return '';
		}

		// A rule on a real date column gets calendar semantics: "on or before
		// 2026-03-31" means through the end of that day, and a value like
		// 20260301 is a date, not a number to cast the column down to. Only a
		// date column reads relative tokens such as "today": in a text field
		// the word is just text.
		$date_defs = OPEX_Query_Dates::date_fields( $src );
		$is_date   = isset( $date_defs[ $field ] );
		if ( $is_date ) {
			$value  = self::resolve_filter_value( $value );
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

		$provider = OPEX_Sources::for_src( $src );
		if ( $provider ) {
			$value = $provider->prepare_filter_value( $field, $op, $value );

			// A rule the provider writes whole, such as term membership on a parent row.
			$own = $provider->filter_clause( $field, $op, $value, $args );
			if ( null !== $own ) {
				return $own;
			}

			$flag = $provider->flag_sql( $field );
			if ( null !== $flag ) {
				$clause = self::flag_clause( $flag, $op, $value );
				if ( null !== $clause ) {
					return $clause;
				}
			}

			// A friendly field the provider maps to a SQL expression, e.g. woo:status.
			$expr = $provider->filter_expr( $field );
			if ( null !== $expr ) {
				return self::value_condition( $expr, $op, $value, $args );
			}
		}

		// On a legacy store the same id is a post column or a meta key.
		$field = OPEX_Query::resolve_field( $src, $field );

		// Core column rule, e.g. core:post_status.
		if ( 0 === strpos( $field, 'core:' ) ) {
			$col = substr( $field, 5 );
			if ( ! OPEX_Query::valid_core_col( $src['type'], $col ) ) {
				return self::MATCH_NOTHING;
			}
			return self::value_condition( OPEX_Query::alias( $src['type'] ) . '.' . $col, $op, $value, $args );
		}

		// Raw table column rule, e.g. col:option_name.
		if ( 0 === strpos( $field, 'col:' ) && 'table' === $src['type'] ) {
			$col = substr( $field, 4 );
			if ( ! OPEX_Query::valid_table_col( $src['table'], $col ) ) {
				return self::MATCH_NOTHING;
			}
			$lhs = '`' . str_replace( '`', '', $col ) . '`';
			if ( $wpdb->usermeta === $src['table'] && 'meta_value' === $col ) {
				// Never let a filter probe the value of a credential row.
				$holders = implode( ',', array_fill( 0, count( OPEX_Query::CREDENTIAL_META_KEYS ), '%s' ) );
				foreach ( OPEX_Query::CREDENTIAL_META_KEYS as $key ) {
					$args[] = $key;
				}
				$inner = self::value_condition( $lhs, $op, $value, $args );
				return "(`meta_key` NOT IN ({$holders}) AND {$inner})";
			}
			return self::value_condition( $lhs, $op, $value, $args );
		}

		// Meta rule, e.g. meta:_sku; EXISTS / NOT EXISTS subquery.
		if ( 0 === strpos( $field, 'meta:' ) && 'table' !== $src['type'] ) {
			$key = substr( $field, 5 );
			if ( OPEX_Query::is_credential_meta( $key ) ) {
				return self::MATCH_NOTHING;
			}
			return self::meta_clause( $src, $key, $op, $value, $args );
		}

		// Taxonomy rule, e.g. tax:product_cat (posts only).
		if ( 0 === strpos( $field, 'tax:' ) && 'posts' === $src['type'] ) {
			$tax = substr( $field, 4 );
			return taxonomy_exists( $tax ) ? self::tax_clause( $tax, $op, $value, $args ) : self::MATCH_NOTHING;
		}

		// Role rule for users: matches the serialized capabilities meta.
		if ( 'computed:roles' === $field && 'users' === $src['type'] ) {
			return self::roles_clause( $op, $value, $args );
		}

		// A field this source does not have. Matching nothing is safer than
		// dropping the rule, which would export more than was asked for.
		return self::MATCH_NOTHING;
	}

	/** SQL that is true for no row. */
	private const MATCH_NOTHING = '1=0';

	/** SQL that is true for every row. */
	private const MATCH_ALL = '1=1';

	/**
	 * Role rule for users. The list operators take several roles.
	 */
	private static function roles_clause( string $op, string $value, array &$args ): string {
		global $wpdb;

		$is_list = in_array( $op, array( 'in', 'not_in' ), true );
		$roles   = $is_list
			? array_values( array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $value ) ) ), 'strlen' ) )
			: array( sanitize_key( $value ) );
		if ( ! $roles ) {
			return 'in' === $op ? self::MATCH_NOTHING : self::MATCH_ALL;
		}

		$args[] = $wpdb->get_blog_prefix() . 'capabilities';
		$likes  = array();
		foreach ( $roles as $role ) {
			$args[]  = '%"' . $wpdb->esc_like( $role ) . '"%';
			$likes[] = 'um.meta_value LIKE %s';
		}
		$sql = "EXISTS (SELECT 1 FROM {$wpdb->usermeta} um WHERE um.user_id = u.ID AND um.meta_key = %s AND (" . implode( ' OR ', $likes ) . '))';
		return in_array( $op, array( 'neq', 'not_contains', 'not_in' ), true ) ? "NOT {$sql}" : $sql;
	}

	/**
	 * A yes or no flag takes yes or no. Returns null when the value is not one of
	 * those, so the rule compares the stored value as before.
	 *
	 * @param string $flag_sql SQL that is true when the flag is yes.
	 */
	private static function flag_clause( string $flag_sql, string $op, string $value ): ?string {
		if ( ! in_array( $op, array( 'eq', 'neq', 'in', 'not_in' ), true ) ) {
			return null;
		}
		$items = in_array( $op, array( 'in', 'not_in' ), true )
			? array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' ) )
			: array( $value );
		$flags = array();
		foreach ( $items as $item ) {
			$item = strtolower( $item );
			if ( in_array( $item, array( 'yes', 'true' ), true ) ) {
				$flags['yes'] = true;
			} elseif ( in_array( $item, array( 'no', 'false' ), true ) ) {
				$flags['no'] = true;
			} else {
				return null;
			}
		}
		$positive = in_array( $op, array( 'eq', 'in' ), true );
		if ( ! $flags ) {
			return $positive ? self::MATCH_NOTHING : self::MATCH_ALL;
		}
		if ( 2 === count( $flags ) ) {
			return $positive ? self::MATCH_ALL : self::MATCH_NOTHING;
		}

		// "eq yes" and "neq no" both mean yes.
		return isset( $flags['yes'] ) === $positive ? $flag_sql : "NOT ({$flag_sql})";
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
				// A NULL text column counts as empty.
				if ( '' === $value ) {
					return "({$lhs} = '' OR {$lhs} IS NULL)";
				}
				$args[] = $value;
				return "{$lhs} = %s";
			case 'neq':
				if ( '' === $value ) {
					return "({$lhs} <> '' AND {$lhs} IS NOT NULL)";
				}
				$args[] = $value;
				return "({$lhs} <> %s OR {$lhs} IS NULL)";
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
				$sym = array(
					'gt'  => '>',
					'gte' => '>=',
					'lt'  => '<',
					'lte' => '<=',
				)[ $op ];
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
					// Nothing is in an empty list, and everything is outside it.
					return 'in' === $op ? self::MATCH_NOTHING : self::MATCH_ALL;
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

		$map      = array(
			'posts'    => array( $wpdb->postmeta, 'post_id', 'p.ID' ),
			'users'    => array( $wpdb->usermeta, 'user_id', 'u.ID' ),
			'comments' => array( $wpdb->commentmeta, 'comment_id', 'c.comment_ID' ),
			'terms'    => array( $wpdb->termmeta, 'term_id', 't.term_id' ),
		);
		$provider = OPEX_Sources::for_src( $src );
		$found    = $provider ? $provider->meta_table() : ( $map[ $src['type'] ] ?? null );
		if ( null === $found ) {
			return '';
		}
		list( $table, $fk, $outer ) = $found;

		// A missing key and an empty value both read as empty, like a NULL column.
		if ( '' === $value && 'eq' === $op ) {
			$op = 'empty';
		} elseif ( '' === $value && 'neq' === $op ) {
			$op = 'not_empty';
		}

		$negatives = array(
			'neq'          => 'eq',
			'not_contains' => 'contains',
			'not_in'       => 'in',
		);
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

	/**
	 * Term membership for a provider field whose terms sit on another row, such
	 * as a variation that takes its parent product's categories.
	 *
	 * Internal to the query compartment: other code calls OPEX_Query.
	 *
	 * @param string $tax    Taxonomy, from a provider constant.
	 * @param string $op     Operator.
	 * @param string $value  Rule value.
	 * @param array  $args   Receives prepare() values.
	 * @param string $object SQL for the object id the terms belong to, from a provider constant.
	 */
	public static function term_clause( string $tax, string $op, string $value, array &$args, string $object ): string {
		return self::tax_clause( $tax, $op, $value, $args, $object );
	}

	/** Taxonomy membership tests match term slug or name. */
	private static function tax_clause( string $tax, string $op, string $value, array &$args, string $object = 'p.ID' ): string {
		global $wpdb;

		$exists_open = "EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr" .
			" INNER JOIN {$wpdb->term_taxonomy} xt ON xt.term_taxonomy_id = tr.term_taxonomy_id" .
			" INNER JOIN {$wpdb->terms} xtm ON xtm.term_id = xt.term_id" .
			" WHERE tr.object_id = {$object} AND xt.taxonomy = %s";

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
			return 'in' === $op ? self::MATCH_NOTHING : self::MATCH_ALL;
		}

		$args[] = $tax;

		if ( 'contains' === $op || 'not_contains' === $op ) {
			$args[] = '%' . $wpdb->esc_like( $value ) . '%';
			$sql    = $exists_open . ' AND xtm.name LIKE %s)';
			return 'not_contains' === $op ? "NOT {$sql}" : $sql;
		}

		// These operators compare the term name, like "contains" does.
		$name_tests = array(
			'starts_with' => 'xtm.name LIKE %s',
			'ends_with'   => 'xtm.name LIKE %s',
			'gt'          => 'xtm.name > %s',
			'gte'         => 'xtm.name >= %s',
			'lt'          => 'xtm.name < %s',
			'lte'         => 'xtm.name <= %s',
		);
		if ( isset( $name_tests[ $op ] ) ) {
			if ( 'starts_with' === $op ) {
				$args[] = $wpdb->esc_like( $value ) . '%';
			} elseif ( 'ends_with' === $op ) {
				$args[] = '%' . $wpdb->esc_like( $value );
			} else {
				$args[] = $value;
			}
			return $exists_open . ' AND ' . $name_tests[ $op ] . ')';
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
