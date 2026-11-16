<?php
/**
 * Discovers what can be exported (sources) and what data each source
 * carries (fields), grouping meta keys by the plugin that owns them.
 *
 * Meta key sampling and grouping live in OPEX_Catalog_Meta. Other code calls
 * them through this class.
 *
 * @package Open_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lists the sources and the fields each one offers.
 */
class OPEX_Catalog {

	private const POST_TYPE_SKIP = array(
		'revision',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'auto-draft',
	);

	/**
	 * Everything that can be exported, grouped for the picker UI.
	 *
	 * @return array[] Source groups, each with an id, tier, label and items.
	 */
	public static function sources(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one cheap grouped count for the picker UI.
		$post_counts = $wpdb->get_results( "SELECT post_type, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_status <> 'auto-draft' GROUP BY post_type", OBJECT_K );

		$content = array();
		foreach ( get_post_types( array(), 'objects' ) as $type ) {
			if ( in_array( $type->name, self::POST_TYPE_SKIP, true ) ) {
				continue;
			}
			if ( ! $type->public && ! $type->show_ui ) {
				continue;
			}
			$content[] = array(
				'id'    => 'posts:' . $type->name,
				'label' => $type->labels->name,
				'icon'  => is_string( $type->menu_icon ) && 0 === strpos( (string) $type->menu_icon, 'dashicons-' ) ? $type->menu_icon : 'dashicons-admin-post',
				'count' => isset( $post_counts[ $type->name ] ) ? (int) $post_counts[ $type->name ]->n : 0,
			);
		}

		// WooCommerce orders lead the Content group, and the raw shop_order post
		// type moves out of it: two tiles with identical row counts, one of them
		// empty under HPOS, is a trap rather than a choice. The raw post type
		// stays available under Advanced for anyone who wants it.
		$lead       = array();
		$raw_orders = array();
		foreach ( OPEX_Sources::all() as $provider ) {
			$entry = $provider->picker_item();
			if ( ! $entry ) {
				continue;
			}
			$replaces = $provider->raw_post_type();
			if ( '' !== $replaces ) {
				foreach ( $content as $i => $item ) {
					if ( 'posts:' . $replaces === $item['id'] ) {
						$item['label'] = sprintf(
							/* translators: %s: the post type name, e.g. "Orders". */
							__( '%s (raw post type)', 'open-export' ),
							$item['label']
						);
						$raw_orders[] = $item;
						unset( $content[ $i ] );
					}
				}
				$content = array_values( $content );
			}
			$lead[] = $entry;
		}
		$content = array_merge( $lead, $content );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cheap COUNTs for the picker UI.
		$user_count    = OPEX_Access::user_count();
		$comment_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" );
		$term_counts   = $wpdb->get_results( "SELECT taxonomy, COUNT(*) AS n FROM {$wpdb->term_taxonomy} GROUP BY taxonomy", OBJECT_K );
		// phpcs:enable

		$taxes = array();
		foreach ( get_taxonomies( array(), 'objects' ) as $tax ) {
			if ( ! $tax->public && ! $tax->show_ui ) {
				continue;
			}
			$taxes[] = array(
				'id'    => 'terms:' . $tax->name,
				'label' => $tax->labels->name,
				'icon'  => 'dashicons-tag',
				'count' => isset( $term_counts[ $tax->name ] ) ? (int) $term_counts[ $tax->name ]->n : 0,
			);
		}

		$tables = $raw_orders;
		foreach ( OPEX_Access::visible_tables( self::table_status() ) as $table ) {
			$tables[] = array(
				'id'    => 'table:' . $table['name'],
				'label' => $table['name'],
				'icon'  => 'dashicons-database',
				'count' => $table['rows'],
			);
		}

		return array(
			array(
				'id'    => 'content',
				'tier'  => 'standard',
				'label' => __( 'Content', 'open-export' ),
				'items' => $content,
			),
			array(
				'id'    => 'people',
				'tier'  => 'standard',
				'label' => __( 'People & Discussion', 'open-export' ),
				'items' => array(
					array(
						'id'    => 'users',
						'label' => __( 'Users', 'open-export' ),
						'icon'  => 'dashicons-admin-users',
						'count' => $user_count,
					),
					array(
						'id'    => 'comments',
						'label' => __( 'Comments', 'open-export' ),
						'icon'  => 'dashicons-admin-comments',
						'count' => $comment_count,
					),
				),
			),
			array(
				'id'    => 'taxonomies',
				'tier'  => 'standard',
				'label' => __( 'Taxonomies', 'open-export' ),
				'items' => $taxes,
			),
			array(
				'id'    => 'tables',
				'tier'  => 'advanced',
				'label' => __( 'Raw Database Tables', 'open-export' ),
				'items' => $tables,
			),
		);
	}

	/**
	 * Flat map of field id => label for a source, for validating a config
	 * against what the source actually offers. Meta keys are sampled with a
	 * LIMIT, so this list is complete for core, computed, taxonomy and column
	 * fields but NOT for meta. Callers must treat a missing meta: id as
	 * "unknown", never as "invalid".
	 *
	 * @param string $source_id Source to list the fields of.
	 * @return array<string, string> Field labels by field id. Empty for an unknown source.
	 */
	public static function field_ids( string $source_id ): array {
		$out = array();
		foreach ( self::fields( $source_id ) as $group ) {
			foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
				if ( isset( $field['id'] ) ) {
					$out[ (string) $field['id'] ] = (string) ( $field['label'] ?? $field['id'] );
				}
			}
		}
		return $out;
	}

	/**
	 * All tables in the database with approximate row counts.
	 *
	 * @return array[] One row per table, with its name and approximate row count.
	 */
	public static function table_status(): array {
		global $wpdb;

		$cached = get_transient( 'opex_table_status' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table inventory, cached in a transient below.
		$status = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
		$tables = array();
		foreach ( (array) $status as $row ) {
			$tables[] = array(
				'name' => (string) ( $row['Name'] ?? '' ),
				'rows' => (int) ( $row['Rows'] ?? 0 ),
			);
		}

		set_transient( 'opex_table_status', $tables, 5 * MINUTE_IN_SECONDS );

		return $tables;
	}

	/**
	 * Per-request memo of table names (name => true). Null until first use.
	 *
	 * @var array<string, bool>|null
	 */
	private static $table_names = null;
	/**
	 * Per-request memo of column definitions, by table name.
	 *
	 * @var array<string, array>
	 */
	private static $column_memo = array();

	/**
	 * Forget the table list and every column list. A site switch keeps the per-site transient.
	 *
	 * @param bool $memory_only True to keep the stored table list and clear only what this request holds.
	 */
	public static function reset_cache( bool $memory_only = false ): void {
		self::$table_names = null;
		self::$column_memo = array();
		if ( ! $memory_only ) {
			delete_transient( 'opex_table_status' );
		}
	}

	/**
	 * True when the named table actually exists in this database.
	 *
	 * @param string $table Table name to look up.
	 * @return bool True when the table exists and the caller may export it.
	 */
	public static function table_exists( string $table ): bool {
		if ( null === self::$table_names ) {
			self::$table_names = array();
			foreach ( self::table_status() as $row ) {
				self::$table_names[ $row['name'] ] = true;
			}
		}
		return isset( self::$table_names[ $table ] ) && OPEX_Access::table_allowed( $table );
	}

	/**
	 * Column definitions for a validated table name. Read once per request.
	 *
	 * @param string $table Table name, already checked.
	 * @return array[] One entry per column, with its name and type.
	 */
	public static function table_columns( string $table ): array {
		global $wpdb;

		if ( isset( self::$column_memo[ $table ] ) ) {
			return self::$column_memo[ $table ];
		}
		if ( ! self::table_exists( $table ) ) {
			return array();
		}

		$safe = str_replace( '`', '', $table );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a table name cannot be a placeholder; it is checked against SHOW TABLE STATUS above.
		$cols = $wpdb->get_results( "DESCRIBE `{$safe}`", ARRAY_A );

		$out = array();
		foreach ( (array) $cols as $col ) {
			$out[] = array(
				'name' => (string) ( $col['Field'] ?? '' ),
				'type' => (string) ( $col['Type'] ?? '' ),
				'pk'   => 'PRI' === ( $col['Key'] ?? '' ),
			);
		}
		self::$column_memo[ $table ] = $out;
		return $out;
	}

	/**
	 * Field groups for a source, ready for the picker UI. Each field:
	 * { id: "core:post_title" | "meta:_sku" | "tax:category" | "computed:permalink" | "col:option_name", label }
	 *
	 * @param string $source_id Source to list the fields of.
	 * @return array[] Field groups for the picker. Empty for an unknown source.
	 */
	public static function fields( string $source_id ): array {
		$src = OPEX_Query::parse_source( $source_id );
		if ( ! $src ) {
			return array();
		}

		$provider = OPEX_Sources::for_src( $src );
		if ( $provider ) {
			return $provider->field_groups();
		}

		switch ( $src['type'] ) {
			case 'posts':
				return OPEX_Acf::add_group( $src, self::post_fields( $src['post_type'] ) );
			case 'users':
				return OPEX_Acf::add_group( $src, self::user_fields() );
			case 'comments':
				return self::comment_fields();
			case 'terms':
				return OPEX_Acf::add_group( $src, self::term_fields( $src['taxonomy'] ) );
			case 'table':
				return self::table_fields( $src['table'] );
		}
		return array();
	}

	/**
	 * Field groups of a post type: core columns, computed fields, taxonomies and meta.
	 *
	 * @param string $post_type Post type.
	 * @return array[] Field groups for the picker.
	 */
	private static function post_fields( string $post_type ): array {
		$core = OPEX_Core_Columns::for_type( 'posts' );

		$groups   = array();
		$groups[] = array(
			'label'  => __( 'Core Fields', 'open-export' ),
			'fields' => array_map( array( __CLASS__, 'core_field' ), $core ),
		);

		$computed = array(
			array(
				'id'    => 'computed:permalink',
				'label' => 'permalink',
			),
			array(
				'id'    => 'computed:featured_image_url',
				'label' => 'featured_image_url',
			),
			array(
				'id'    => 'computed:author_display_name',
				'label' => 'author_display_name',
			),
			array(
				'id'    => 'computed:author_email',
				'label' => 'author_email',
			),
		);
		if ( 'attachment' === $post_type ) {
			$computed[] = array(
				'id'    => 'computed:attachment_url',
				'label' => 'attachment_url',
			);
		}
		$groups[] = array(
			'label'  => __( 'Computed', 'open-export' ),
			'fields' => $computed,
		);

		$tax_fields = array();
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $tax ) {
			$tax_fields[] = array(
				'id'    => 'tax:' . $tax->name,
				'label' => $tax->name,
			);
		}
		if ( $tax_fields ) {
			$groups[] = array(
				'label'  => __( 'Taxonomies', 'open-export' ),
				'fields' => $tax_fields,
			);
		}

		return array_merge( $groups, self::grouped_meta( self::sample_meta_keys( 'posts', $post_type ), self::acf_keys() ) );
	}

	/**
	 * Field groups of users. Credential columns are left out.
	 *
	 * @return array[] Field groups for the picker.
	 */
	private static function user_fields(): array {
		$core = OPEX_Core_Columns::for_type( 'users' );

		$groups   = array();
		$groups[] = array(
			'label'  => __( 'Core Fields', 'open-export' ),
			'fields' => array_map( array( __CLASS__, 'core_field' ), $core ),
		);
		$groups[] = array(
			'label'  => __( 'Computed', 'open-export' ),
			'fields' => array(
				array(
					'id'    => 'computed:roles',
					'label' => 'roles',
				),
			),
		);

		return array_merge( $groups, self::grouped_meta( OPEX_Access::user_meta_keys( self::sample_meta_keys( 'users' ) ), self::acf_keys() ) );
	}

	/**
	 * Field groups of comments.
	 *
	 * @return array[] Field groups for the picker.
	 */
	private static function comment_fields(): array {
		$core = OPEX_Core_Columns::for_type( 'comments' );

		$groups   = array();
		$groups[] = array(
			'label'  => __( 'Core Fields', 'open-export' ),
			'fields' => array_map( array( __CLASS__, 'core_field' ), $core ),
		);

		return array_merge( $groups, self::grouped_meta( self::sample_meta_keys( 'comments' ), array() ) );
	}

	/**
	 * Field groups of a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array[] Field groups for the picker.
	 */
	private static function term_fields( string $taxonomy ): array {
		$core = OPEX_Core_Columns::for_type( 'terms' );

		$groups   = array();
		$groups[] = array(
			'label'  => __( 'Core Fields', 'open-export' ),
			'fields' => array_map( array( __CLASS__, 'core_field' ), $core ),
		);

		return array_merge( $groups, self::grouped_meta( self::sample_meta_keys( 'terms', $taxonomy ), self::acf_keys() ) );
	}

	/**
	 * The one field group of a raw table: its columns.
	 *
	 * @param string $table Table name, already checked.
	 * @return array[] Field groups for the picker.
	 */
	private static function table_fields( string $table ): array {
		$fields = array();
		foreach ( self::table_columns( $table ) as $col ) {
			if ( OPEX_Query::is_forbidden_column( $col['name'] ) ) {
				continue;
			}
			$fields[] = array(
				'id'    => 'col:' . $col['name'],
				'label' => $col['name'] . ' (' . $col['type'] . ')',
			);
		}
		return array(
			array(
				'label'  => __( 'Columns', 'open-export' ),
				'fields' => $fields,
			),
		);
	}

	/**
	 * A picker entry for a core column.
	 *
	 * @param string $col Column name.
	 * @return array Picker entry with the field id and label.
	 */
	private static function core_field( string $col ): array {
		return array(
			'id'    => 'core:' . $col,
			'label' => $col,
		);
	}

	/**
	 * Distinct meta keys for a source, sampled and cached. See OPEX_Catalog_Meta.
	 *
	 * @param string $type  Source type: posts, users, comments or terms.
	 * @param string $scope Post type or taxonomy, where the type has one.
	 * @return string[] Distinct meta keys, sorted.
	 */
	public static function sample_meta_keys( string $type, string $scope = '' ): array {
		return OPEX_Catalog_Meta::sample_meta_keys( $type, $scope );
	}

	/**
	 * Meta keys that belong to ACF fields. See OPEX_Catalog_Meta.
	 *
	 * @return string[] Meta keys that hold ACF values.
	 */
	public static function acf_keys(): array {
		return OPEX_Catalog_Meta::acf_keys();
	}

	/**
	 * Sort raw meta keys into labelled groups. See OPEX_Catalog_Meta.
	 *
	 * @param array $keys     Raw meta keys.
	 * @param array $acf_keys Keys that belong to ACF fields.
	 * @return array[] Field groups, each with a label and its fields.
	 */
	public static function grouped_meta( array $keys, array $acf_keys ): array {
		return OPEX_Catalog_Meta::grouped_meta( $keys, $acf_keys );
	}
}
