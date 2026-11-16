<?php
/**
 * Turns an export config (source + filter rules + fields) into prepared SQL
 * and hands back batches of assembled rows. Pagination is keyset-based
 * (WHERE pk > cursor ORDER BY pk) so batch N is as fast as batch 1.
 *
 * The SQL building, counting and batch fetching live in OPEX_Query_Fetch.
 * Other code calls them through this class.
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
	public const FIELD_ID_PATTERN = '/^(core|meta|tax|computed|col|woo|acf):[\S]/';

	/** Pattern that strips the prefix from a field id. */
	private const FIELD_PREFIX_PATTERN = '/^(core|meta|tax|computed|col|woo|acf):/';

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
			return 'table' !== $src['type'] && '' !== substr( $field, 5 ) && ! self::is_credential_meta( substr( $field, 5 ) ) && OPEX_Access::meta_allowed( $src, substr( $field, 5 ) );
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

		return 0 === strpos( $field, 'acf:' ) && OPEX_Acf::is_field( $src, $field );
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
		return ( 0 !== strpos( $resolved, 'computed:' ) || 'computed:roles' === $resolved ) && 0 !== strpos( $resolved, 'acf:' );
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

	/**
	 * Total row count for a config, used for the progress bar. See OPEX_Query_Fetch.
	 *
	 * @param array $config Export config.
	 */
	public static function count( array $config ): int {
		return OPEX_Query_Fetch::count( $config );
	}

	/**
	 * Fetch one batch of rows. See OPEX_Query_Fetch.
	 *
	 * @param array         $config Export config.
	 * @param int           $cursor Cursor from the last batch, 0 to start.
	 * @param int           $limit  Rows to fetch.
	 * @param callable|null $tick   Called now and then while rows are assembled, so a
	 *                              long batch can refresh its lock.
	 * @throws RuntimeException When the database reports an error.
	 */
	public static function batch( array $config, int $cursor, int $limit, $tick = null ): array {
		return OPEX_Query_Fetch::batch( $config, $cursor, $limit, $tick );
	}

	/**
	 * Plain notes to store on a new job about how this export behaves. See OPEX_Query_Fetch.
	 *
	 * @param array $config Sanitized config.
	 * @return string[]
	 */
	public static function export_notes( array $config ): array {
		return OPEX_Query_Fetch::export_notes( $config );
	}
}
