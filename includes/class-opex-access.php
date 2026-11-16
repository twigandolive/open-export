<?php
/**
 * What a caller may reach on a multisite network.
 *
 * On a network, the users table, the user meta table and the network tables
 * are shared by every site. A person who is not a super admin may reach only
 * the current site's data: its member users, its own tables, and user meta
 * that does not belong to another site. A single site, a super admin and
 * WP-CLI are not restricted.
 *
 * Cron and the trigger URL have no current user, so they are restricted too,
 * unless the job carries `wide`. A job gets it when a caller who is not
 * restricted creates it, or from a schedule that carries it. A schedule gets
 * it only when such a caller saves a config that needs it, so a super
 * admin's schedule keeps working from cron. It is never read from input.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Decides and applies the multisite data boundary.
 */
class OPEX_Access {

	/**
	 * True while a job whose config carries `wide` runs.
	 *
	 * @var bool
	 */
	private static $wide = false;

	/**
	 * Whether the caller itself may reach the whole network: not multisite, a super admin, or WP-CLI.
	 *
	 * @return bool True when the caller may reach every site of the network.
	 */
	public static function caller_unrestricted(): bool {
		return ! is_multisite() || is_super_admin() || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Whether the current request must stay inside the current site.
	 *
	 * @return bool True when the request must stay inside the current site.
	 */
	public static function restricted(): bool {
		return ! self::$wide && ! self::caller_unrestricted();
	}

	/**
	 * Run a callback with the restriction lifted when $wide is true. The
	 * previous state always comes back, also when the callback throws.
	 *
	 * @param bool     $wide     Whether the job may reach the whole network.
	 * @param callable $callback Work to run.
	 * @return mixed What the callback returns.
	 */
	public static function scope( bool $wide, callable $callback ) {
		$before     = self::$wide;
		self::$wide = $before || $wide;
		try {
			return $callback();
		} finally {
			self::$wide = $before;
		}
	}

	/**
	 * The `wide` flag sanitize_config stores: true only on a network, for a
	 * caller who is not restricted, when the source reaches shared data.
	 *
	 * @param array $src Parsed source.
	 * @return bool True when the export may reach data shared across the network.
	 */
	public static function wide_for( array $src ): bool {
		if ( ! is_multisite() || ! self::caller_unrestricted() ) {
			return false;
		}
		$type = (string) ( $src['type'] ?? '' );
		return 'users' === $type || ( 'table' === $type && ! self::site_table( (string) $src['table'] ) );
	}

	/**
	 * Whether a new job may reach shared data. A caller who is not restricted
	 * gets it when the source needs it. Otherwise only a schedule a super
	 * admin saved (it carries `wide`) gets it, and only when cron, the trigger
	 * URL or WP-CLI starts it, never a person. A source this caller cannot
	 * reach is then an unknown source.
	 *
	 * @param string $source_id Source id of the config.
	 * @param array  $extra     The job's extra settings: origin, wide.
	 * @return bool|WP_Error
	 */
	public static function job_wide( string $source_id, array $extra ) {
		$src = OPEX_Query::parse_source( $source_id );
		if ( self::caller_unrestricted() ) {
			return $src && self::wide_for( $src );
		}
		$wide = ! empty( $extra['wide'] ) && in_array( $extra['origin'] ?? '', array( 'cron', 'trigger', 'cli' ), true );
		if ( ! $wide && ! $src ) {
			return new WP_Error( 'opex_bad_source', __( 'Unknown data source.', 'open-export' ), array( 'status' => 400 ) );
		}
		return $wide;
	}

	/**
	 * Extra WHERE terms for the users source: members of the current site
	 * only, when restricted. A member has the site's capabilities row, which
	 * is what WordPress itself checks.
	 *
	 * @return array{0: string[], 1: array} Terms and their values.
	 */
	public static function users_scope(): array {
		global $wpdb;
		if ( ! self::restricted() ) {
			return array( array(), array() );
		}
		return array(
			array( "EXISTS (SELECT 1 FROM {$wpdb->usermeta} opex_member WHERE opex_member.user_id = u.ID AND opex_member.meta_key = %s)" ),
			array( $wpdb->get_blog_prefix() . 'capabilities' ),
		);
	}

	/**
	 * The users tile count, with the same scope as the export.
	 *
	 * @return int Number of users the export would include.
	 */
	public static function user_count(): int {
		global $wpdb;
		list( $terms, $args ) = self::users_scope();
		$sql                  = "SELECT COUNT(*) FROM {$wpdb->users} u" . ( $terms ? ' WHERE ' . implode( ' AND ', $terms ) : '' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the table comes from $wpdb, the one value is bound; a cheap count for the picker.
		return (int) $wpdb->get_var( $args ? $wpdb->prepare( $sql, $args ) : $sql );
	}

	/**
	 * Whether a raw table may be exported now. The table list and source
	 * validation both ask here.
	 *
	 * @param string $table Table name.
	 * @return bool True when the caller may export this table.
	 */
	public static function table_allowed( string $table ): bool {
		return ! self::restricted() || self::site_table( $table );
	}

	/**
	 * Table status rows the caller may see.
	 *
	 * @param array $rows Rows with a name key.
	 * @return array The rows for tables the caller may export, in their original order.
	 */
	public static function visible_tables( array $rows ): array {
		return array_values(
			array_filter(
				$rows,
				static function ( $row ): bool {
					return self::table_allowed( (string) ( $row['name'] ?? '' ) );
				}
			)
		);
	}

	/**
	 * Whether a user meta key may be offered and exported for the users source.
	 *
	 * @param array  $src Parsed source.
	 * @param string $key Meta key.
	 * @return bool True when the key may be offered and exported.
	 */
	public static function meta_allowed( array $src, string $key ): bool {
		return 'users' !== ( $src['type'] ?? '' ) || ! self::restricted() || ! self::other_site_key( $key );
	}

	/**
	 * User meta keys the caller may see.
	 *
	 * @param string[] $keys Meta keys.
	 * @return string[]
	 */
	public static function user_meta_keys( array $keys ): array {
		return array_values(
			array_filter(
				$keys,
				static function ( $key ): bool {
					return self::meta_allowed( array( 'type' => 'users' ), (string) $key );
				}
			)
		);
	}

	/**
	 * Whether a table belongs to the current site: it starts with the site's
	 * prefix, is not a shared table, and on the main site is not another
	 * site's table (the main prefix is the base that "wp_2_" also starts with).
	 *
	 * @param string $table Table name.
	 * @return bool True when the table belongs to the current site.
	 */
	private static function site_table( string $table ): bool {
		global $wpdb;
		$shared = array_merge( array( $wpdb->users, $wpdb->usermeta ), array_values( $wpdb->tables( 'global' ) ), array_values( $wpdb->tables( 'ms_global' ) ) );
		if ( in_array( $table, $shared, true ) || 0 !== strpos( $table, (string) $wpdb->prefix ) ) {
			return false;
		}
		return $wpdb->prefix !== $wpdb->base_prefix || ! preg_match( '/^' . preg_quote( (string) $wpdb->base_prefix, '/' ) . '\d+_/', $table );
	}

	/**
	 * Whether a user meta key is another site's per-site key, such as
	 * "wp_3_capabilities" seen from site 2, or "wp_capabilities" (the main
	 * site's) seen from a sub-site.
	 *
	 * @param string $key Meta key.
	 * @return bool True when the key belongs to another site of the network.
	 */
	private static function other_site_key( string $key ): bool {
		global $wpdb;
		$base = (string) $wpdb->base_prefix;
		$own  = (string) $wpdb->get_blog_prefix();
		if ( 0 !== strpos( $key, $base ) ) {
			return false;
		}
		if ( $own !== $base ) {
			return 0 !== strpos( $key, $own );
		}
		return 1 === preg_match( '/^' . preg_quote( $base, '/' ) . '\d+_/', $key );
	}
}
