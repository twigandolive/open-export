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

	/** Columns that must never leave the database, no matter what is asked for. */
	public const FORBIDDEN = array( 'user_pass', 'user_activation_key' );

	/**
	 * Meta keys that hold login credentials. Their values never leave the
	 * database, on any path: the meta prefetch, filters, and the raw usermeta table.
	 */
	public const CREDENTIAL_META_KEYS = array( 'session_tokens', '_application_passwords' );

	/** Pattern a field id must match: a known prefix, then at least one character. */
	public const FIELD_ID_PATTERN = '/^(core|meta|tax|computed|col|woo):[\S]/';

	/** Pattern that strips the prefix from a field id. */
	private const FIELD_PREFIX_PATTERN = '/^(core|meta|tax|computed|col|woo):/';

	/** The filter operators this plugin understands. */
	public static function ops(): array {
		return self::OPS;
	}

	public static function is_valid_op( string $op ): bool {
		return in_array( $op, self::OPS, true );
	}

	/** Whether text looks like a field id (a known prefix and a name). */
	public static function is_field_id( string $field ): bool {
		return 1 === preg_match( self::FIELD_ID_PATTERN, $field );
	}

	/** A field id without its prefix: "meta:_sku" becomes "_sku". */
	public static function field_name( string $field ): string {
		return (string) preg_replace( self::FIELD_PREFIX_PATTERN, '', $field );
	}

	/** Reduce any relation value to "OR" or "AND". */
	public static function normalize_relation( $relation ): string {
		return 'OR' === strtoupper( (string) $relation ) ? 'OR' : 'AND';
	}

	/** Whether a meta key holds credentials that must never be exported or filtered on. */
	public static function is_credential_meta( string $key ): bool {
		return in_array( $key, self::CREDENTIAL_META_KEYS, true );
	}

	/**
	 * The date column to use for a range: the one asked for when the source
	 * has it, else the source's first date column, else ''.
	 *
	 * @param array  $src   Parsed source.
	 * @param string $field Field id asked for.
	 */
	public static function date_field_or_default( array $src, string $field ): string {
		$defs = self::date_fields( $src );
		if ( isset( $defs[ $field ] ) ) {
			return $field;
		}
		$keys = array_keys( $defs );
		return (string) reset( $keys );
	}

	/**
	 * Fail closed when the last query ran into a database error. The detail
	 * goes to the debug log only; the exception text stays generic.
	 *
	 * @throws RuntimeException When the database reported an error.
	 */
	public static function throw_on_db_error(): void {
		global $wpdb;

		$detail = (string) $wpdb->last_error;
		if ( '' === $detail ) {
			return;
		}
		$wpdb->last_error = '';
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional server-side logging when debug is on.
			error_log( 'Open Export: database error: ' . $detail );
		}
		throw new RuntimeException( 'The database could not read the export data.' );
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
		$provider = OPEX_Sources::for_src( $src );
		return $provider ? $provider->resolve_field( $field ) : $field;
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
			$provider = OPEX_Sources::for_src( $src );
			if ( $provider && null === $provider->meta_table() ) {
				return false;
			}
			return 'table' !== $src['type'] && '' !== substr( $field, 5 ) && ! self::is_credential_meta( substr( $field, 5 ) );
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
			$provider = OPEX_Sources::for_src( $src );
			return $provider && $provider->has_field( $field );
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

	/**
	 * Whether a rule may filter on this field. Computed values (a permalink,
	 * an author name) are not stored anywhere, so only the role list qualifies.
	 */
	public static function is_filterable_field( array $src, string $field ): bool {
		if ( ! self::is_valid_field( $src, $field ) ) {
			return false;
		}
		$provider = OPEX_Sources::for_src( $src );
		if ( $provider && ! $provider->is_filterable( $field ) ) {
			return false;
		}
		$resolved = self::resolve_field( $src, $field );
		return 0 !== strpos( $resolved, 'computed:' ) || 'computed:roles' === $resolved;
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
		// A registered provider serves its own ids. The order provider keeps
		// one source id over two storage engines: under HPOS a joined query
		// over the custom order tables, otherwise the shop_order post type.
		$provided = OPEX_Sources::parse( $source_id );
		if ( $provided ) {
			return $provided;
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

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above when placeholders exist; export counts are one-shot.
		$total = $wpdb->get_var( $sql );
		self::throw_on_db_error();
		return (int) $total;
	}

	/**
	 * Fetch one batch. Returns:
	 *   items:  list of assoc arrays keyed by field id, in $config['fields'] order
	 *   cursor: opaque int to pass back for the next batch
	 *   done:   true when the source is exhausted
	 *
	 * @param callable|null $tick Called now and then while rows are assembled, so a
	 *                            long batch can refresh its lock.
	 * @throws RuntimeException When the database reports an error.
	 */
	public static function batch( array $config, int $cursor, int $limit, $tick = null ): array {
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
			// No usable single-column integer key: offset paging, ordered by the
			// primary key columns when the table has any so pages never overlap.
			$order     = 'table' === $src['type'] ? self::table_order( $src['table'] ) : '';
			$sql       = "SELECT {$select} FROM {$from}" . ( $where ? " WHERE {$where}" : '' ) . ( $order ? " ORDER BY {$order}" : '' ) . ' LIMIT %d OFFSET %d';
			$args_full = array_merge( $args, array( $limit, $cursor ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql contains only validated identifiers and %-placeholders; all values travel through $args_full.
		$sql = $wpdb->prepare( $sql, $args_full );

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above; streaming export batches are inherently uncached.
		$rows = $wpdb->get_results( $sql );
		self::throw_on_db_error();
		$rows = is_array( $rows ) ? $rows : array();

		// Strip credential columns even if SELECT * included them, and blank
		// the value of credential rows in the raw usermeta table.
		$usermeta = 'table' === $src['type'] && $wpdb->usermeta === $src['table'];
		foreach ( $rows as $row ) {
			foreach ( self::FORBIDDEN as $col ) {
				unset( $row->$col );
			}
			if ( $usermeta && isset( $row->meta_key ) && self::is_credential_meta( (string) $row->meta_key ) ) {
				$row->meta_value = '';
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

		$items = OPEX_Query_Rows::assemble( $src, $rows, $config['fields'], $tick );

		return array( 'items' => $items, 'cursor' => $new_cursor, 'done' => $done );
	}

	/**
	 * FROM clause, WHERE clause (sans pagination), prepare args, and the
	 * qualified primary-key column for keyset paging ('' = offset paging).
	 */
	private static function build_query_parts( array $src, array $config ): array {
		global $wpdb;

		$filters = (array) ( $config['filters'] ?? array() );

		$provider = OPEX_Sources::for_src( $src );
		$type     = $provider ? 'provider' : $src['type'];
		switch ( $type ) {
			case 'provider':
				$from = $provider->from();
				list( $base, $args ) = $provider->base_where();
				$pk   = $provider->primary_key();
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

		// A flat 1.3.1 filter list is a one-level tree, so it compiles the same way.
		$tree = OPEX_Filter_Tree::compile( $src, $filters, $args );
		if ( '' !== $tree ) {
			$base[] = $tree;
		}

		// The date range is deliberately not a rule. It is ANDed on its own so
		// that choosing "match ANY rule (OR)" cannot widen it back out to every
		// row, which is the whole point of asking for a range.
		$date = OPEX_Query_Dates::date_range_clause( $src, (array) ( $config['date_range'] ?? array() ), $args );
		if ( '' !== $date ) {
			$base[] = $date;
		}

		// "Since" is the third independent AND term, for incremental runs. It is
		// set by the job, never by the saved config.
		$since = OPEX_Query_Dates::since_clause( $src, (array) ( $config['since'] ?? array() ), $args );
		if ( '' !== $since ) {
			$base[] = $since;
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
		return array( 'posts' => 'p', 'users' => 'u', 'comments' => 'c', 'terms' => 't' )[ $type ] ?? 'p';
	}

	/**
	 * Internal to the query compartment: other code calls OPEX_Query.
	 */
	public static function valid_core_col( string $type, string $col ): bool {
		if ( self::is_forbidden_column( $col ) ) {
			return false;
		}
		return in_array( $col, OPEX_Core_Columns::for_type( $type ), true );
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

	/**
	 * Primary key columns of a raw table, backtick-quoted and comma separated,
	 * for ORDER BY when keyset paging is not possible. '' when it has none.
	 */
	private static function table_order( string $table ): string {
		$cols = array();
		foreach ( OPEX_Catalog::table_columns( $table ) as $col ) {
			if ( $col['pk'] ) {
				$cols[] = '`' . str_replace( '`', '', $col['name'] ) . '`';
			}
		}
		return implode( ', ', $cols );
	}

	/**
	 * Plain notes to store on a new job about how this export behaves.
	 *
	 * @param array $config Sanitized config.
	 * @return string[]
	 */
	public static function export_notes( array $config ): array {
		global $wpdb;

		$src = self::parse_source( (string) ( $config['source'] ?? '' ) );
		if ( ! $src || 'table' !== $src['type'] ) {
			return array();
		}
		$notes = array();
		if ( '' === self::table_pk( $src['table'] ) && '' === self::table_order( $src['table'] ) ) {
			$notes[] = __( 'This table has no primary key, so the order of its rows is not guaranteed. If the table changes while the export runs, a row can be missed or repeated.', 'open-export' );
		}
		if ( $wpdb->usermeta === $src['table'] && in_array( 'col:meta_value', (array) $config['fields'], true ) ) {
			$notes[] = __( 'Login credentials (session tokens and application passwords) are never exported. Their values are left empty.', 'open-export' );
		}
		return $notes;
	}

	private static function unqualified( string $col ): string {
		$col = str_replace( '`', '', $col );
		$dot = strrpos( $col, '.' );
		return false === $dot ? $col : substr( $col, $dot + 1 );
	}

	/** SELECT list: raw tables fetch only requested columns plus the PK. */
	private static function select_list( array $src, array $fields ): string {
		$provider = OPEX_Sources::for_src( $src );
		if ( $provider ) {
			return $provider->select_list( $fields );
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
		// The usermeta table needs its key column to blank credential values.
		global $wpdb;
		if ( $wpdb->usermeta === $src['table'] && isset( $cols['meta_value'] ) ) {
			$cols['meta_key'] = true;
		}

		$parts = array();
		foreach ( array_keys( $cols ) as $col ) {
			$parts[] = '`' . str_replace( '`', '', $col ) . '`';
		}
		return implode( ', ', $parts );
	}
}
