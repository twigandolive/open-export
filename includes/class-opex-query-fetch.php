<?php
/**
 * Turns an export config into prepared SQL and hands back the row count or
 * one batch of assembled rows. Pagination is keyset-based (WHERE pk > cursor
 * ORDER BY pk) so batch N is as fast as batch 1.
 *
 * Part of the query compartment. Other code reaches it through OPEX_Query.
 *
 * @package Open_Export
 */

// No strict_types here on purpose: this file holds moved code, and strict types would change how arguments are coerced.

defined( 'ABSPATH' ) || exit;

/**
 * Counts rows and fetches batches, paging by primary key.
 */
class OPEX_Query_Fetch {

	/**
	 * Total row count for a config, used for the progress bar.
	 *
	 * @param array $config Clean config.
	 * @return int Number of rows the export would hold.
	 */
	public static function count( array $config ): int {
		global $wpdb;

		$src = OPEX_Query::parse_source( $config['source'] );
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
		OPEX_Query::throw_on_db_error();
		return (int) $total;
	}

	/**
	 * Fetch one batch. Returns:
	 *   items:  list of assoc arrays keyed by field id, in $config['fields'] order
	 *   cursor: opaque int to pass back for the next batch
	 *   done:   true when the source is exhausted
	 *
	 * @param array         $config Export config.
	 * @param int           $cursor Last primary key read, 0 to start.
	 * @param int           $limit  Most rows to read.
	 * @param callable|null $tick   Called now and then while rows are assembled, so a
	 *                              long batch can refresh its lock.
	 * @return array The rows, the cursor for the next call, and whether the source is used up.
	 * @throws RuntimeException When the database reports an error.
	 */
	public static function batch( array $config, int $cursor, int $limit, $tick = null ): array {
		global $wpdb;

		$src = OPEX_Query::parse_source( $config['source'] );
		if ( ! $src ) {
			return array(
				'items'  => array(),
				'cursor' => $cursor,
				'done'   => true,
			);
		}

		$limit = max( 1, min( 5000, $limit ) );

		list( $from, $where, $args, $pk ) = self::build_query_parts( $src, $config );

		$select = self::select_list( $src, $config['fields'] );

		if ( $pk ) {
			$where_full  = ( $where ? "({$where}) AND " : '' ) . "{$pk} > %d";
			$args_full   = array_merge( $args, array( $cursor ) );
			$sql         = "SELECT {$select} FROM {$from} WHERE {$where_full} ORDER BY {$pk} ASC LIMIT %d";
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
		OPEX_Query::throw_on_db_error();
		$rows = is_array( $rows ) ? $rows : array();

		// Strip credential columns even if SELECT * included them, and blank
		// the value of credential rows in the raw usermeta table.
		$usermeta = 'table' === $src['type'] && $wpdb->usermeta === $src['table'];
		foreach ( $rows as $row ) {
			foreach ( OPEX_Query::FORBIDDEN as $col ) {
				unset( $row->$col );
			}
			if ( $usermeta && isset( $row->meta_key ) && OPEX_Query::is_credential_meta( (string) $row->meta_key ) ) {
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

		return array(
			'items'  => $items,
			'cursor' => $new_cursor,
			'done'   => $done,
		);
	}

	/**
	 * FROM clause, WHERE clause (sans pagination), prepare args, and the
	 * qualified primary-key column for keyset paging ('' = offset paging).
	 *
	 * @param array $src    Parsed source.
	 * @param array $config Clean config.
	 * @return array The FROM clause, the WHERE clause, its values, and the key column for keyset paging.
	 */
	private static function build_query_parts( array $src, array $config ): array {
		global $wpdb;

		$filters = (array) ( $config['filters'] ?? array() );

		$provider = OPEX_Sources::for_src( $src );
		$type     = $provider ? 'provider' : $src['type'];
		switch ( $type ) {
			case 'provider':
				$from                = $provider->from();
				list( $base, $args ) = $provider->base_where();
				$pk                  = $provider->primary_key();
				break;
			case 'posts':
				$from = "{$wpdb->posts} p";
				$base = array( 'p.post_type = %s', "p.post_status <> 'auto-draft'" );
				$args = array( $src['post_type'] );
				$pk   = 'p.ID';
				break;
			case 'users':
				$from = "{$wpdb->users} u";
				// On a network, a caller who is not a super admin sees only this site's members.
				list( $base, $args ) = OPEX_Access::users_scope();
				$pk                  = 'u.ID';
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
	 * Single-column primary key of a raw table, backtick-qualified, or ''.
	 *
	 * @param string $table Table name, already checked.
	 * @return string The quoted key column, or '' when the table has no single-column key.
	 */
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
		// Keyset paging compares numbers, so only a numeric primary key can drive it.
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
	 *
	 * @param string $table Table name, already checked.
	 * @return string The ORDER BY list, or '' when the table has no primary key.
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

		$src = OPEX_Query::parse_source( (string) ( $config['source'] ?? '' ) );
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

	/**
	 * A column reference without its table alias, such as post_title for p.post_title.
	 *
	 * @param string $col Column, maybe with an alias.
	 * @return string The column name alone.
	 */
	private static function unqualified( string $col ): string {
		$col = str_replace( '`', '', $col );
		$dot = strrpos( $col, '.' );
		return false === $dot ? $col : substr( $col, $dot + 1 );
	}

	/**
	 * SELECT list: raw tables fetch only requested columns plus the PK.
	 *
	 * @param array    $src    Parsed source.
	 * @param string[] $fields Field ids the export asked for.
	 * @return string The SELECT list.
	 */
	private static function select_list( array $src, array $fields ): string {
		$provider = OPEX_Sources::for_src( $src );
		if ( $provider ) {
			return $provider->select_list( $fields );
		}
		if ( 'table' !== $src['type'] ) {
			$alias = OPEX_Query::alias( $src['type'] );
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
			if ( 0 === strpos( $field, 'col:' ) && OPEX_Query::valid_table_col( $src['table'], substr( $field, 4 ) ) ) {
				$cols[ substr( $field, 4 ) ] = true;
			}
		}
		if ( ! $cols ) {
			return '*';
		}
		// The usermeta table needs its key column to blank credential values.
		global $wpdb;
		if ( $wpdb->usermeta === $src['table'] && isset( $cols['meta_value'] ) ) {
			$cols['meta_key'] = true; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a column list entry, not a meta query.
		}

		$parts = array();
		foreach ( array_keys( $cols ) as $col ) {
			$parts[] = '`' . str_replace( '`', '', $col ) . '`';
		}
		return implode( ', ', $parts );
	}
}
