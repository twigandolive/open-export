<?php
/**
 * Turns an export config (source + filter rules + fields) into prepared SQL
 * and hands back batches of assembled rows. Pagination is keyset-based
 * (WHERE pk > cursor ORDER BY pk) so batch N is as fast as batch 1.
 */

defined( 'ABSPATH' ) || exit;

class OPEX_Query {

	private const OPS = array(
		'eq', 'neq', 'contains', 'not_contains', 'starts_with', 'ends_with',
		'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'empty', 'not_empty',
	);

	private const POST_COLS = array(
		'ID', 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name',
		'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt', 'post_author',
		'post_parent', 'menu_order', 'comment_status', 'ping_status', 'comment_count',
		'guid', 'post_mime_type', 'post_type',
	);

	private const USER_COLS = array(
		'ID', 'user_login', 'user_email', 'user_nicename', 'user_url', 'user_registered', 'display_name',
	);

	private const COMMENT_COLS = array(
		'comment_ID', 'comment_post_ID', 'comment_author', 'comment_author_email',
		'comment_author_url', 'comment_author_IP', 'comment_date', 'comment_date_gmt',
		'comment_content', 'comment_approved', 'comment_type', 'comment_parent', 'user_id',
		'comment_agent', 'comment_karma',
	);

	private const TERM_COLS = array( 'term_id', 'name', 'slug', 'description', 'parent', 'count' );

	/** Columns that must never leave the database, no matter what is asked for. */
	private const FORBIDDEN = array( 'user_pass', 'user_activation_key' );

	/** The filter operators this plugin understands. */
	public static function ops(): array {
		return self::OPS;
	}

	public static function is_valid_op( string $op ): bool {
		return in_array( $op, self::OPS, true );
	}

	/**
	 * Translate a canonical woo: field id into the core:/meta: id that holds
	 * the same value when orders live in wp_posts.
	 *
	 * This is what lets one recipe work on either storage engine: the ids in
	 * the capability document never change, only where they are read from.
	 */
	private static function resolve_field( array $src, string $field ): string {
		if ( 0 !== strpos( $field, 'woo:' ) ) {
			return $field;
		}
		if ( 'posts' === $src['type'] && ! empty( $src['woo'] ) ) {
			$legacy = OPEX_Woo::legacy_field( $field );
			return '' !== $legacy ? $legacy : $field;
		}
		return $field;
	}

	/**
	 * Whether a field id can actually resolve to a value for this source.
	 *
	 * This mirrors exactly what field_value() will do at read time, so it is the
	 * authoritative answer — not the picker catalog, which samples meta keys
	 * under a LIMIT and lists a slightly narrower set of core columns.
	 *
	 * Meta keys always pass: any key may exist on any row, and absence is a
	 * blank cell rather than a mistake.
	 */
	public static function is_valid_field( array $src, string $field ): bool {
		$field = self::resolve_field( $src, $field );

		if ( 0 === strpos( $field, 'meta:' ) ) {
			return 'table' !== $src['type'] && '' !== substr( $field, 5 );
		}

		if ( 0 === strpos( $field, 'core:' ) ) {
			return self::valid_core_col( $src['type'], substr( $field, 5 ) );
		}

		if ( 0 === strpos( $field, 'col:' ) ) {
			return 'table' === $src['type'] && self::valid_table_col( $src['table'], substr( $field, 4 ) );
		}

		if ( 0 === strpos( $field, 'tax:' ) ) {
			return 'posts' === $src['type'] && taxonomy_exists( substr( $field, 4 ) );
		}

		if ( 0 === strpos( $field, 'woo:' ) ) {
			return 'woo_orders' === $src['type'] && OPEX_Woo::is_woo_field( $field );
		}

		if ( 0 === strpos( $field, 'computed:' ) ) {
			$what  = substr( $field, 9 );
			$known = array(
				'posts' => array( 'permalink', 'attachment_url', 'featured_image_url', 'author_display_name', 'author_email' ),
				'users' => array( 'roles' ),
			);
			return in_array( $what, $known[ $src['type'] ] ?? array(), true );
		}

		return false;
	}

	/** Whether a column name is blocked from every export path (core, col, filters). */
	public static function is_forbidden_column( string $col ): bool {
		return in_array( $col, self::FORBIDDEN, true );
	}

	/**
	 * Validate and decompose a source id like "posts:product" or "table:wp_options".
	 * Returns null for unknown sources; downstream code never trusts raw input.
	 */
	public static function parse_source( string $source_id ): ?array {
		if ( OPEX_Woo::SOURCE === $source_id ) {
			if ( ! OPEX_Woo::is_active() ) {
				return null;
			}
			// One source id, two storage engines. Under HPOS it becomes a
			// joined query over the custom order tables; otherwise it is just
			// the shop_order post type, so every existing code path applies.
			return OPEX_Woo::use_hpos()
				? array( 'type' => 'woo_orders' )
				: array( 'type' => 'posts', 'post_type' => OPEX_Woo::LEGACY_POST_TYPE, 'woo' => true );
		}
		if ( 'users' === $source_id ) {
			return array( 'type' => 'users' );
		}
		if ( 'comments' === $source_id ) {
			return array( 'type' => 'comments' );
		}
		if ( 0 === strpos( $source_id, 'posts:' ) ) {
			$post_type = substr( $source_id, 6 );
			return post_type_exists( $post_type ) ? array( 'type' => 'posts', 'post_type' => $post_type ) : null;
		}
		if ( 0 === strpos( $source_id, 'terms:' ) ) {
			$tax = substr( $source_id, 6 );
			return taxonomy_exists( $tax ) ? array( 'type' => 'terms', 'taxonomy' => $tax ) : null;
		}
		if ( 0 === strpos( $source_id, 'table:' ) ) {
			$table = substr( $source_id, 6 );
			return OPEX_Catalog::table_exists( $table ) ? array( 'type' => 'table', 'table' => $table ) : null;
		}
		return null;
	}

	/** Total row count for a config, used for the progress bar. */
	public static function count( array $config ): int {
		global $wpdb;

		$src = self::parse_source( $config['source'] );
		if ( ! $src ) {
			return 0;
		}

		list( $from, $where, $args, ) = self::build_query_parts( $src, $config );

		$sql = "SELECT COUNT(*) FROM {$from}" . ( $where ? " WHERE {$where}" : '' );
		if ( $args ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql contains only validated identifiers and %-placeholders; all values travel through $args.
			$sql = $wpdb->prepare( $sql, $args );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above when placeholders exist; export counts are one-shot.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Fetch one batch. Returns:
	 *   items:  list of assoc arrays keyed by field id, in $config['fields'] order
	 *   cursor: opaque int to pass back for the next batch
	 *   done:   true when the source is exhausted
	 */
	public static function batch( array $config, int $cursor, int $limit ): array {
		global $wpdb;

		$src = self::parse_source( $config['source'] );
		if ( ! $src ) {
			return array( 'items' => array(), 'cursor' => $cursor, 'done' => true );
		}

		$limit = max( 1, min( 5000, $limit ) );

		list( $from, $where, $args, $pk ) = self::build_query_parts( $src, $config );

		$select = self::select_list( $src, $config['fields'] );

		if ( $pk ) {
			$where_full = ( $where ? "({$where}) AND " : '' ) . "{$pk} > %d";
			$args_full  = array_merge( $args, array( $cursor ) );
			$sql        = "SELECT {$select} FROM {$from} WHERE {$where_full} ORDER BY {$pk} ASC LIMIT %d";
			$args_full[] = $limit;
		} else {
			// No usable single-column primary key: fall back to offset paging.
			$sql       = "SELECT {$select} FROM {$from}" . ( $where ? " WHERE {$where}" : '' ) . ' LIMIT %d OFFSET %d';
			$args_full = array_merge( $args, array( $limit, $cursor ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql contains only validated identifiers and %-placeholders; all values travel through $args_full.
		$sql = $wpdb->prepare( $sql, $args_full );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above; streaming export batches are inherently uncached.
		$rows = $wpdb->get_results( $sql );
		$rows = is_array( $rows ) ? $rows : array();

		// Strip credential columns even if SELECT * included them.
		foreach ( $rows as $row ) {
			foreach ( self::FORBIDDEN as $col ) {
				unset( $row->$col );
			}
		}

		$done       = count( $rows ) < $limit;
		$pk_prop    = $pk ? self::unqualified( $pk ) : '';
		$new_cursor = $cursor;

		if ( $pk && $rows ) {
			$last       = end( $rows );
			$new_cursor = (int) $last->{$pk_prop};
		} elseif ( ! $pk ) {
			$new_cursor = $cursor + count( $rows );
		}

		$items = self::assemble( $src, $rows, $config['fields'] );

		return array( 'items' => $items, 'cursor' => $new_cursor, 'done' => $done );
	}

	/**
	 * FROM clause, WHERE clause (sans pagination), prepare args, and the
	 * qualified primary-key column for keyset paging ('' = offset paging).
	 */
	private static function build_query_parts( array $src, array $config ): array {
		global $wpdb;

		$filters = (array) ( $config['filters'] ?? array() );

		switch ( $src['type'] ) {
			case 'woo_orders':
				$from = OPEX_Woo::from_clause();
				// Refunds share the orders table; excluding them keeps one row
				// per order and stops refund rows corrupting reported totals.
				$base = array( "o.type = 'shop_order'" );
				$args = array();
				$pk   = 'o.id';
				break;
			case 'posts':
				$from  = "{$wpdb->posts} p";
				$base  = array( 'p.post_type = %s', "p.post_status <> 'auto-draft'" );
				$args  = array( $src['post_type'] );
				$pk    = 'p.ID';
				break;
			case 'users':
				$from = "{$wpdb->users} u";
				$base = array();
				$args = array();
				$pk   = 'u.ID';
				break;
			case 'comments':
				$from = "{$wpdb->comments} c";
				$base = array();
				$args = array();
				$pk   = 'c.comment_ID';
				break;
			case 'terms':
				$from = "{$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id";
				$base = array( 'tt.taxonomy = %s' );
				$args = array( $src['taxonomy'] );
				$pk   = 't.term_id';
				break;
			case 'table':
			default:
				$safe = str_replace( '`', '', $src['table'] );
				$from = "`{$safe}`";
				$base = array();
				$args = array();
				$pk   = self::table_pk( $src['table'] );
				break;
		}

		$relation = ( isset( $filters['relation'] ) && 'OR' === strtoupper( (string) $filters['relation'] ) ) ? 'OR' : 'AND';
		$clauses  = array();

		foreach ( (array) ( $filters['rules'] ?? array() ) as $rule ) {
			$clause = self::rule_clause( $src, $rule, $args );
			if ( $clause ) {
				$clauses[] = $clause;
			}
		}

		if ( $clauses ) {
			$base[] = '(' . implode( " {$relation} ", $clauses ) . ')';
		}

		// The date range is deliberately not a rule. It is ANDed on its own so
		// that choosing "match ANY rule (OR)" cannot widen it back out to every
		// row, which is the whole point of asking for a range.
		$date = self::date_range_clause( $src, (array) ( $config['date_range'] ?? array() ), $args );
		if ( '' !== $date ) {
			$base[] = $date;
		}

		return array( $from, implode( ' AND ', $base ), $args, $pk );
	}

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

	/** @see date_fields() — split out so the memo above wraps every branch. */
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
					if ( self::is_forbidden_column( $col['name'] ) ) {
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
	 */
	private static function date_range_clause( array $src, array $range, array &$args ): string {
		$field  = (string) ( $range['field'] ?? '' );
		$preset = (string) ( $range['preset'] ?? 'all_time' );
		$from   = (string) ( $range['from'] ?? '' );
		$to     = (string) ( $range['to'] ?? '' );

		// A relative preset that has not been pinned yet — a preview, or a
		// template loaded straight from storage — resolves against now.
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

	/** Compile one filter rule into SQL, appending its values to $args. */
	private static function rule_clause( array $src, array $rule, array &$args ): string {
		$field = (string) ( $rule['field'] ?? '' );
		$op    = (string) ( $rule['op'] ?? 'eq' );
		$value = self::resolve_filter_value( (string) ( $rule['value'] ?? '' ) );

		if ( ! in_array( $op, self::OPS, true ) ) {
			return '';
		}

		// A rule on a real date column gets calendar semantics: "on or before
		// 2026-03-31" means through the end of that day, and a value like
		// 20260301 is a date, not a number to cast the column down to.
		$date_defs = self::date_fields( $src );
		$is_date   = isset( $date_defs[ $field ] );
		if ( $is_date ) {
			$clause = self::date_condition( $date_defs[ $field ], $op, $value, $args );
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
		$field = self::resolve_field( $src, $field );

		// Core column rule, e.g. core:post_status.
		if ( 0 === strpos( $field, 'core:' ) ) {
			$col = substr( $field, 5 );
			if ( ! self::valid_core_col( $src['type'], $col ) ) {
				return '';
			}
			return self::value_condition( self::alias( $src['type'] ) . '.' . $col, $op, $value, $args );
		}

		// Raw table column rule, e.g. col:option_name.
		if ( 0 === strpos( $field, 'col:' ) && 'table' === $src['type'] ) {
			$col = substr( $field, 4 );
			if ( ! self::valid_table_col( $src['table'], $col ) ) {
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
	 * For a *_gmt column, use the date-range control instead — it knows which
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
	 */
	private static function date_condition( array $def, string $op, string $value, array &$args ): ?string {
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

	private static function alias( string $type ): string {
		return array( 'posts' => 'p', 'users' => 'u', 'comments' => 'c', 'terms' => 't', 'woo_orders' => 'o' )[ $type ] ?? 'p';
	}

	private static function valid_core_col( string $type, string $col ): bool {
		if ( self::is_forbidden_column( $col ) ) {
			return false;
		}
		switch ( $type ) {
			case 'posts':
				return in_array( $col, self::POST_COLS, true );
			case 'users':
				return in_array( $col, self::USER_COLS, true );
			case 'comments':
				return in_array( $col, self::COMMENT_COLS, true );
			case 'terms':
				return in_array( $col, self::TERM_COLS, true );
		}
		return false;
	}

	private static function valid_table_col( string $table, string $col ): bool {
		if ( self::is_forbidden_column( $col ) ) {
			return false;
		}
		foreach ( OPEX_Catalog::table_columns( $table ) as $c ) {
			if ( $c['name'] === $col ) {
				return true;
			}
		}
		return false;
	}

	/** Single-column primary key of a raw table, backtick-qualified, or ''. */
	private static function table_pk( string $table ): string {
		$pks = array();
		foreach ( OPEX_Catalog::table_columns( $table ) as $col ) {
			if ( $col['pk'] ) {
				$pks[] = $col['name'];
			}
		}
		if ( 1 !== count( $pks ) ) {
			return '';
		}
		// Keyset paging needs integer comparison semantics; only use numeric PKs.
		foreach ( OPEX_Catalog::table_columns( $table ) as $col ) {
			if ( $col['name'] === $pks[0] && ! preg_match( '/int/i', $col['type'] ) ) {
				return '';
			}
		}
		return '`' . str_replace( '`', '', $pks[0] ) . '`';
	}

	private static function unqualified( string $col ): string {
		$col = str_replace( '`', '', $col );
		$dot = strrpos( $col, '.' );
		return false === $dot ? $col : substr( $col, $dot + 1 );
	}

	/** SELECT list: raw tables fetch only requested columns plus the PK. */
	private static function select_list( array $src, array $fields ): string {
		if ( 'woo_orders' === $src['type'] ) {
			return OPEX_Woo::select_list();
		}
		if ( 'table' !== $src['type'] ) {
			$alias = self::alias( $src['type'] );
			if ( 'terms' === $src['type'] ) {
				return 't.term_id, t.name, t.slug, tt.description, tt.parent, tt.count, tt.taxonomy';
			}
			return "{$alias}.*";
		}

		$cols = array();
		$pk   = self::unqualified( self::table_pk( $src['table'] ) );
		if ( $pk ) {
			$cols[ $pk ] = true;
		}
		foreach ( $fields as $field ) {
			if ( 0 === strpos( $field, 'col:' ) && self::valid_table_col( $src['table'], substr( $field, 4 ) ) ) {
				$cols[ substr( $field, 4 ) ] = true;
			}
		}
		if ( ! $cols ) {
			return '*';
		}

		$parts = array();
		foreach ( array_keys( $cols ) as $col ) {
			$parts[] = '`' . str_replace( '`', '', $col ) . '`';
		}
		return implode( ', ', $parts );
	}

	/**
	 * Turn raw DB rows into export items keyed by field id, prefetching all
	 * meta and taxonomy values for the batch in one query each.
	 */
	private static function assemble( array $src, array $rows, array $fields ): array {
		if ( ! $rows ) {
			return array();
		}

		$id_prop = array( 'posts' => 'ID', 'users' => 'ID', 'comments' => 'comment_ID', 'terms' => 'term_id', 'woo_orders' => 'id' )[ $src['type'] ] ?? '';
		$ids     = array();
		if ( $id_prop ) {
			foreach ( $rows as $row ) {
				$ids[] = (int) $row->{$id_prop};
			}
		}

		$meta_keys = array();
		$tax_names = array();
		foreach ( $fields as $field ) {
			$resolved = self::resolve_field( $src, $field );
			if ( 0 === strpos( $resolved, 'meta:' ) ) {
				$meta_keys[] = substr( $resolved, 5 );
			} elseif ( 0 === strpos( $resolved, 'tax:' ) ) {
				$tax_names[] = substr( $resolved, 4 );
			}
		}

		$meta = $meta_keys && $ids ? self::prefetch_meta( $src['type'], $ids, $meta_keys ) : array();
		$tax  = array();
		foreach ( $tax_names as $tax_name ) {
			$tax[ $tax_name ] = self::prefetch_terms( $ids, $tax_name );
		}

		$items = array();
		foreach ( $rows as $row ) {
			$id   = $id_prop ? (int) $row->{$id_prop} : 0;
			$item = array();
			foreach ( $fields as $field ) {
				$item[ $field ] = self::field_value( $src, $row, $field, $id, $meta, $tax );
			}
			$items[] = $item;
		}
		return $items;
	}

	private static function field_value( array $src, object $row, string $field, int $id, array $meta, array $tax ): string {
		$is_guest_field = 'woo:is_guest' === $field;

		if ( 'woo_orders' === $src['type'] && OPEX_Woo::is_woo_field( $field ) ) {
			$alias = OPEX_Woo::field_map()[ $field ]['alias'];
			$value = self::flatten( $row->{$alias} ?? '' );
			return $is_guest_field ? self::guest_flag( $value ) : $value;
		}

		// A woo: id on a legacy store reads from the post column or meta key
		// that actually holds it.
		$field = self::resolve_field( $src, $field );
		if ( $is_guest_field ) {
			$raw = 0 === strpos( $field, 'meta:' )
				? ( isset( $meta[ $id ][ substr( $field, 5 ) ] ) ? self::flatten_many( $meta[ $id ][ substr( $field, 5 ) ] ) : '' )
				: '';
			return self::guest_flag( $raw );
		}
		if ( 0 === strpos( $field, 'core:' ) ) {
			$col = substr( $field, 5 );
			return in_array( $col, self::FORBIDDEN, true ) ? '' : self::flatten( $row->{$col} ?? '' );
		}
		if ( 0 === strpos( $field, 'col:' ) ) {
			$col = substr( $field, 4 );
			return self::is_forbidden_column( $col ) ? '' : self::flatten( $row->{$col} ?? '' );
		}
		if ( 0 === strpos( $field, 'meta:' ) ) {
			$key = substr( $field, 5 );
			return isset( $meta[ $id ][ $key ] ) ? self::flatten_many( $meta[ $id ][ $key ] ) : '';
		}
		if ( 0 === strpos( $field, 'tax:' ) ) {
			$name = substr( $field, 4 );
			return isset( $tax[ $name ][ $id ] ) ? implode( ' | ', $tax[ $name ][ $id ] ) : '';
		}
		if ( 0 === strpos( $field, 'computed:' ) ) {
			return self::computed_value( $src, $row, substr( $field, 9 ), $id );
		}
		return '';
	}

	/** WooCommerce writes customer id 0 (or nothing) for a guest checkout. */
	private static function guest_flag( string $customer_id ): string {
		$customer_id = trim( $customer_id );
		return ( '' === $customer_id || '0' === $customer_id ) ? 'yes' : 'no';
	}

	private static function computed_value( array $src, object $row, string $what, int $id ): string {
		switch ( $what ) {
			case 'permalink':
				return (string) get_permalink( $id );
			case 'attachment_url':
				return (string) wp_get_attachment_url( $id );
			case 'featured_image_url':
				$thumb = get_post_thumbnail_id( $id );
				return $thumb ? (string) wp_get_attachment_url( $thumb ) : '';
			case 'author_display_name':
			case 'author_email':
				$user = get_userdata( (int) ( $row->post_author ?? 0 ) );
				if ( ! $user ) {
					return '';
				}
				return 'author_email' === $what ? (string) $user->user_email : (string) $user->display_name;
			case 'roles':
				$user = get_userdata( $id );
				return $user ? implode( ' | ', $user->roles ) : '';
		}
		return '';
	}

	/** One query fetches every requested meta value for the whole batch. */
	private static function prefetch_meta( string $type, array $ids, array $keys ): array {
		global $wpdb;

		$map = array(
			'posts'      => array( $wpdb->postmeta, 'post_id' ),
			'users'      => array( $wpdb->usermeta, 'user_id' ),
			'comments'   => array( $wpdb->commentmeta, 'comment_id' ),
			'terms'      => array( $wpdb->termmeta, 'term_id' ),
			'woo_orders' => array( OPEX_Woo::table( 'meta' ), 'order_id' ),
		);
		if ( ! isset( $map[ $type ] ) ) {
			return array();
		}
		list( $table, $fk ) = $map[ $type ];

		$keys = array_values( array_diff( array_unique( $keys ), self::FORBIDDEN, array( 'session_tokens' ) ) );
		if ( ! $keys ) {
			return array();
		}

		$id_holders  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$key_holders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder lists built above; every value is bound.
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column names come from the fixed map above.
			"SELECT {$fk} AS oid, meta_key, meta_value FROM {$table} WHERE {$fk} IN ({$id_holders}) AND meta_key IN ({$key_holders})",
			array_merge( $ids, $keys )
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above; streaming export batches are inherently uncached.
		$rows = $wpdb->get_results( $sql );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->oid ][ $row->meta_key ][] = $row->meta_value;
		}
		return $out;
	}

	/** Term names per object id for one taxonomy, one query per batch. */
	private static function prefetch_terms( array $ids, string $taxonomy ): array {
		if ( ! $ids || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$terms = wp_get_object_terms( $ids, $taxonomy, array( 'fields' => 'all_with_object_id' ) );
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$out = array();
		foreach ( $terms as $term ) {
			$out[ (int) $term->object_id ][] = $term->name;
		}
		return $out;
	}

	/** Decode serialized values to JSON for readable output. */
	private static function flatten( $value ): string {
		if ( is_null( $value ) ) {
			return '';
		}
		$maybe = maybe_unserialize( (string) $value );
		if ( is_array( $maybe ) || is_object( $maybe ) ) {
			return (string) wp_json_encode( $maybe );
		}
		return (string) $value;
	}

	private static function flatten_many( array $values ): string {
		return implode( ' | ', array_map( array( __CLASS__, 'flatten' ), $values ) );
	}
}
