<?php
/**
 * Turns an export config (source + filter rules + fields) into prepared SQL
 * and hands back batches of assembled rows. Pagination is keyset-based
 * (WHERE pk > cursor ORDER BY pk) so batch N is as fast as batch 1.
 */

defined( 'ABSPATH' ) || exit;

class OPEX_Query {

	public const OPS = array(
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
	public const FORBIDDEN = array( 'user_pass', 'user_activation_key' );

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
	 *
	 * Internal to the query compartment: other code calls OPEX_Query.
	 */
	public static function resolve_field( array $src, string $field ): string {
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
	 * authoritative answer - not the picker catalog, which samples meta keys
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

		$items = OPEX_Query_Rows::assemble( $src, $rows, $config['fields'] );

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
			$clause = OPEX_Query_Filters::rule_clause( $src, $rule, $args );
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
		$date = OPEX_Query_Dates::date_range_clause( $src, (array) ( $config['date_range'] ?? array() ), $args );
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
		return OPEX_Query_Dates::date_fields( $src );
	}

	/**
	 * Internal to the query compartment: other code calls OPEX_Query.
	 */
	public static function alias( string $type ): string {
		return array( 'posts' => 'p', 'users' => 'u', 'comments' => 'c', 'terms' => 't', 'woo_orders' => 'o' )[ $type ] ?? 'p';
	}

	/**
	 * Internal to the query compartment: other code calls OPEX_Query.
	 */
	public static function valid_core_col( string $type, string $col ): bool {
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

	/**
	 * Internal to the query compartment: other code calls OPEX_Query.
	 */
	public static function valid_table_col( string $table, string $col ): bool {
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
}
