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

	/** Whether a column name is blocked from every export path (core, col, filters). */
	public static function is_forbidden_column( string $col ): bool {
		return in_array( $col, self::FORBIDDEN, true );
	}

	/**
	 * Validate and decompose a source id like "posts:product" or "table:wp_options".
	 * Returns null for unknown sources; downstream code never trusts raw input.
	 */
	public static function parse_source( string $source_id ): ?array {
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

		list( $from, $where, $args, ) = self::build_query_parts( $src, $config['filters'] ?? array() );

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

		list( $from, $where, $args, $pk ) = self::build_query_parts( $src, $config['filters'] ?? array() );

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
	private static function build_query_parts( array $src, array $filters ): array {
		global $wpdb;

		switch ( $src['type'] ) {
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

		return array( $from, implode( ' AND ', $base ), $args, $pk );
	}

	/** Compile one filter rule into SQL, appending its values to $args. */
	private static function rule_clause( array $src, array $rule, array &$args ): string {
		$field = (string) ( $rule['field'] ?? '' );
		$op    = (string) ( $rule['op'] ?? 'eq' );
		$value = self::resolve_filter_value( (string) ( $rule['value'] ?? '' ) );

		if ( ! in_array( $op, self::OPS, true ) ) {
			return '';
		}

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
	 */
	private static function resolve_filter_value( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return $value;
		}

		$lower = strtolower( $value );

		if ( preg_match( '/^last\s+(\d+)\s+(day|days|week|weeks|month|months|year|years)$/i', $value, $matches ) ) {
			$n    = (int) $matches[1];
			$unit = rtrim( strtolower( $matches[2] ), 's' ) . 's';
			$ts   = strtotime( '-' . $n . ' ' . $unit );
			return false !== $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : $value;
		}

		$phrases = array(
			'today'      => 'today',
			'yesterday'  => 'yesterday',
			'this month' => 'first day of this month',
			'last month' => 'first day of last month',
			'this year'  => 'first day of january this year',
			'last year'  => 'first day of january last year',
		);

		if ( isset( $phrases[ $lower ] ) ) {
			$ts = strtotime( $phrases[ $lower ] );
			return false !== $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : $value;
		}

		if ( preg_match( '/^-\d+\s+(day|days|week|weeks|month|months|year|years)$/i', $value ) ) {
			$ts = strtotime( $value );
			return false !== $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : $value;
		}

		return $value;
	}

	/** Direct comparison against a validated column reference. */
	private static function value_condition( string $lhs, string $op, string $value, array &$args ): string {
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
				if ( is_numeric( $value ) ) {
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
			'posts'    => array( $wpdb->postmeta, 'post_id', 'p.ID' ),
			'users'    => array( $wpdb->usermeta, 'user_id', 'u.ID' ),
			'comments' => array( $wpdb->commentmeta, 'comment_id', 'c.comment_ID' ),
			'terms'    => array( $wpdb->termmeta, 'term_id', 't.term_id' ),
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
		return array( 'posts' => 'p', 'users' => 'u', 'comments' => 'c', 'terms' => 't' )[ $type ] ?? 'p';
	}

	private static function valid_core_col( string $type, string $col ): bool {
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

		$id_prop = array( 'posts' => 'ID', 'users' => 'ID', 'comments' => 'comment_ID', 'terms' => 'term_id' )[ $src['type'] ] ?? '';
		$ids     = array();
		if ( $id_prop ) {
			foreach ( $rows as $row ) {
				$ids[] = (int) $row->{$id_prop};
			}
		}

		$meta_keys = array();
		$tax_names = array();
		foreach ( $fields as $field ) {
			if ( 0 === strpos( $field, 'meta:' ) ) {
				$meta_keys[] = substr( $field, 5 );
			} elseif ( 0 === strpos( $field, 'tax:' ) ) {
				$tax_names[] = substr( $field, 4 );
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
			'posts'    => array( $wpdb->postmeta, 'post_id' ),
			'users'    => array( $wpdb->usermeta, 'user_id' ),
			'comments' => array( $wpdb->commentmeta, 'comment_id' ),
			'terms'    => array( $wpdb->termmeta, 'term_id' ),
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
