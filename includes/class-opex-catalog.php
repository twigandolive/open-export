<?php
/**
 * Discovers what can be exported (sources) and what data each source
 * carries (fields), grouping meta keys by the plugin that owns them.
 */

defined( 'ABSPATH' ) || exit;

class OPEX_Catalog {

	/**
	 * Meta-key prefixes mapped to a human group label. Longest match wins.
	 */
	private const PREFIX_GROUPS = array(
		'_yoast_'      => 'Yoast SEO',
		'rank_math_'   => 'Rank Math',
		'_aioseo_'     => 'All in One SEO',
		'_seopress_'   => 'SEOPress',
		'_elementor_'  => 'Elementor',
		'_et_'         => 'Divi',
		'_wpb_'        => 'WPBakery',
		'vc_'          => 'WPBakery',
		'_jetpack_'    => 'Jetpack',
		'_wpml_'       => 'WPML',
		'wpml_'        => 'WPML',
		'pll_'         => 'Polylang',
		'_wc_'         => 'WooCommerce',
		'_billing_'    => 'WooCommerce',
		'_shipping_'   => 'WooCommerce',
		'billing_'     => 'WooCommerce',
		'shipping_'    => 'WooCommerce',
		'_menu_item_'  => 'WordPress Core',
		'_wp_'         => 'WordPress Core',
		'_edit_'       => 'WordPress Core',
		'_oembed_'     => 'WordPress Core',
	);

	/**
	 * Exact WooCommerce product/order meta keys that carry no _wc_ prefix.
	 */
	private const WOO_KEYS = array(
		'_sku', '_price', '_regular_price', '_sale_price', '_sale_price_dates_from',
		'_sale_price_dates_to', '_stock', '_stock_status', '_manage_stock', '_backorders',
		'_low_stock_amount', '_sold_individually', '_weight', '_length', '_width', '_height',
		'_tax_status', '_tax_class', '_virtual', '_downloadable', '_download_limit',
		'_download_expiry', '_downloadable_files', '_purchase_note', '_product_attributes',
		'_default_attributes', '_product_version', '_product_url', '_button_text',
		'total_sales', '_product_image_gallery', '_children', '_upsell_ids', '_crosssell_ids',
		'_variation_description', '_order_total', '_order_tax', '_order_currency',
		'_payment_method', '_payment_method_title', '_customer_user', '_order_key',
	);

	private const CORE_KEYS = array(
		'_thumbnail_id', '_pingme', '_encloseme', '_customize_changeset_uuid',
		'wp_capabilities', 'wp_user_level', 'session_tokens', 'nickname', 'first_name',
		'last_name', 'description', 'rich_editing', 'syntax_highlighting', 'comment_shortcuts',
		'admin_color', 'use_ssl', 'show_admin_bar_front', 'locale', 'dismissed_wp_pointers',
		'show_welcome_panel', 'last_update', 'community-events-location',
	);

	private const POST_TYPE_SKIP = array(
		'revision', 'customize_changeset', 'oembed_cache', 'user_request', 'auto-draft',
	);

	/** Everything that can be exported, grouped for the picker UI. */
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cheap COUNTs for the picker UI.
		$user_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
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

		$tables = array();
		foreach ( self::table_status() as $table ) {
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
					array( 'id' => 'users', 'label' => __( 'Users', 'open-export' ), 'icon' => 'dashicons-admin-users', 'count' => $user_count ),
					array( 'id' => 'comments', 'label' => __( 'Comments', 'open-export' ), 'icon' => 'dashicons-admin-comments', 'count' => $comment_count ),
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

	/** All tables in the database with approximate row counts. */
	public static function table_status(): array {
		global $wpdb;

		$cached = get_transient( 'opex_table_status' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table inventory, cached in a transient below.
		$status = $wpdb->get_results( 'SHOW TABLE STATUS' );
		$tables = array();
		foreach ( (array) $status as $row ) {
			$tables[] = array(
				'name' => (string) $row->Name,
				'rows' => (int) $row->Rows,
			);
		}

		set_transient( 'opex_table_status', $tables, 5 * MINUTE_IN_SECONDS );

		return $tables;
	}

	/** True when the named table actually exists in this database. */
	public static function table_exists( string $table ): bool {
		foreach ( self::table_status() as $row ) {
			if ( $row['name'] === $table ) {
				return true;
			}
		}
		return false;
	}

	/** Column definitions for a validated table name. */
	public static function table_columns( string $table ): array {
		global $wpdb;

		if ( ! self::table_exists( $table ) ) {
			return array();
		}

		$safe = str_replace( '`', '', $table );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- identifier validated against SHOW TABLE STATUS above; identifiers cannot be parameterized.
		$cols = $wpdb->get_results( "DESCRIBE `{$safe}`" );

		$out = array();
		foreach ( (array) $cols as $col ) {
			$out[] = array(
				'name' => (string) $col->Field,
				'type' => (string) $col->Type,
				'pk'   => 'PRI' === $col->Key,
			);
		}
		return $out;
	}

	/**
	 * Field groups for a source, ready for the picker UI. Each field:
	 * { id: "core:post_title" | "meta:_sku" | "tax:category" | "computed:permalink" | "col:option_name", label }
	 */
	public static function fields( string $source_id ): array {
		$src = OPEX_Query::parse_source( $source_id );
		if ( ! $src ) {
			return array();
		}

		switch ( $src['type'] ) {
			case 'posts':
				return self::post_fields( $src['post_type'] );
			case 'users':
				return self::user_fields();
			case 'comments':
				return self::comment_fields();
			case 'terms':
				return self::term_fields( $src['taxonomy'] );
			case 'table':
				return self::table_fields( $src['table'] );
		}
		return array();
	}

	private static function post_fields( string $post_type ): array {
		$core = array(
			'ID', 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name',
			'post_date', 'post_date_gmt', 'post_modified', 'post_author', 'post_parent',
			'menu_order', 'comment_status', 'ping_status', 'comment_count', 'guid',
		);
		if ( 'attachment' === $post_type ) {
			$core[] = 'post_mime_type';
		}

		$groups   = array();
		$groups[] = array(
			'label'  => __( 'Core Fields', 'open-export' ),
			'fields' => array_map( array( __CLASS__, 'core_field' ), $core ),
		);

		$computed = array(
			array( 'id' => 'computed:permalink', 'label' => 'permalink' ),
			array( 'id' => 'computed:featured_image_url', 'label' => 'featured_image_url' ),
			array( 'id' => 'computed:author_display_name', 'label' => 'author_display_name' ),
			array( 'id' => 'computed:author_email', 'label' => 'author_email' ),
		);
		if ( 'attachment' === $post_type ) {
			$computed[] = array( 'id' => 'computed:attachment_url', 'label' => 'attachment_url' );
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

	private static function user_fields(): array {
		$core = array( 'ID', 'user_login', 'user_email', 'user_nicename', 'user_url', 'user_registered', 'display_name' );

		$groups   = array();
		$groups[] = array(
			'label'  => __( 'Core Fields', 'open-export' ),
			'fields' => array_map( array( __CLASS__, 'core_field' ), $core ),
		);
		$groups[] = array(
			'label'  => __( 'Computed', 'open-export' ),
			'fields' => array( array( 'id' => 'computed:roles', 'label' => 'roles' ) ),
		);

		return array_merge( $groups, self::grouped_meta( self::sample_meta_keys( 'users' ), self::acf_keys() ) );
	}

	private static function comment_fields(): array {
		$core = array(
			'comment_ID', 'comment_post_ID', 'comment_author', 'comment_author_email',
			'comment_author_url', 'comment_author_IP', 'comment_date', 'comment_content',
			'comment_approved', 'comment_type', 'comment_parent', 'user_id', 'comment_agent',
		);

		$groups   = array();
		$groups[] = array(
			'label'  => __( 'Core Fields', 'open-export' ),
			'fields' => array_map( array( __CLASS__, 'core_field' ), $core ),
		);

		return array_merge( $groups, self::grouped_meta( self::sample_meta_keys( 'comments' ), array() ) );
	}

	private static function term_fields( string $taxonomy ): array {
		$core = array( 'term_id', 'name', 'slug', 'description', 'parent', 'count' );

		$groups   = array();
		$groups[] = array(
			'label'  => __( 'Core Fields', 'open-export' ),
			'fields' => array_map( array( __CLASS__, 'core_field' ), $core ),
		);

		return array_merge( $groups, self::grouped_meta( self::sample_meta_keys( 'terms', $taxonomy ), self::acf_keys() ) );
	}

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

	private static function core_field( string $col ): array {
		return array( 'id' => 'core:' . $col, 'label' => $col );
	}

	/**
	 * Distinct meta keys for a source, sampled with a hard LIMIT so a
	 * 10M-row postmeta table can't stall the picker. Cached 5 minutes.
	 */
	public static function sample_meta_keys( string $type, string $scope = '' ): array {
		global $wpdb;

		$cache_key = 'opex_keys_' . md5( $type . '|' . $scope );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- key sampling, cached in a transient below.
		switch ( $type ) {
			case 'posts':
				$keys = $wpdb->get_col( $wpdb->prepare(
					"SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s LIMIT 800",
					$scope
				) );
				break;
			case 'users':
				$keys = $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} LIMIT 800" );
				$keys = array_diff( $keys, array( 'session_tokens' ) );
				break;
			case 'comments':
				$keys = $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->commentmeta} LIMIT 400" );
				break;
			case 'terms':
				$keys = $wpdb->get_col( $wpdb->prepare(
					"SELECT DISTINCT tm.meta_key FROM {$wpdb->termmeta} tm INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id WHERE tt.taxonomy = %s LIMIT 400",
					$scope
				) );
				break;
			default:
				$keys = array();
		}
		// phpcs:enable

		$keys = array_values( array_filter( array_map( 'strval', (array) $keys ) ) );
		sort( $keys, SORT_NATURAL | SORT_FLAG_CASE );

		set_transient( $cache_key, $keys, 5 * MINUTE_IN_SECONDS );

		return $keys;
	}

	/**
	 * ACF stores every value twice: `field_name` and `_field_name` where the
	 * underscored twin holds a `field_abc123` reference. Any key whose twin
	 * looks like that is an ACF field.
	 */
	public static function acf_keys(): array {
		global $wpdb;

		$cached = get_transient( 'opex_acf_keys' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- ACF detection sample, cached in a transient below.
		$refs = $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_%' AND meta_value LIKE 'field\\_%' LIMIT 800" );

		$keys = array();
		foreach ( (array) $refs as $ref ) {
			$keys[ substr( (string) $ref, 1 ) ] = true;
		}

		set_transient( 'opex_acf_keys', $keys, 5 * MINUTE_IN_SECONDS );

		return $keys;
	}

	/**
	 * Sort raw meta keys into labelled groups: known plugins first, then
	 * Custom Fields, with underscored/technical keys tucked in last.
	 */
	private static function grouped_meta( array $keys, array $acf_keys ): array {
		$buckets = array();

		foreach ( $keys as $key ) {
			$group = self::classify_key( $key, $acf_keys );
			$buckets[ $group ][] = array( 'id' => 'meta:' . $key, 'label' => $key );
		}

		$order  = array( 'WooCommerce', 'ACF', 'Yoast SEO', 'Rank Math', 'All in One SEO', 'SEOPress', 'Elementor', 'Divi', 'WPBakery', 'Jetpack', 'WPML', 'Polylang', 'WordPress Core' );
		$groups = array();

		foreach ( $order as $label ) {
			if ( ! empty( $buckets[ $label ] ) ) {
				$groups[] = array( 'label' => $label, 'fields' => $buckets[ $label ] );
				unset( $buckets[ $label ] );
			}
		}

		if ( ! empty( $buckets['Custom Fields'] ) ) {
			$groups[] = array( 'label' => __( 'Custom Fields', 'open-export' ), 'fields' => $buckets['Custom Fields'] );
			unset( $buckets['Custom Fields'] );
		}
		if ( ! empty( $buckets['Technical'] ) ) {
			$groups[] = array( 'label' => __( 'Technical / Hidden', 'open-export' ), 'fields' => $buckets['Technical'] );
			unset( $buckets['Technical'] );
		}

		// Anything classify_key invented that we did not order explicitly.
		foreach ( $buckets as $label => $fields ) {
			$groups[] = array( 'label' => $label, 'fields' => $fields );
		}

		return $groups;
	}

	private static function classify_key( string $key, array $acf_keys ): string {
		if ( isset( $acf_keys[ $key ] ) ) {
			return 'ACF';
		}
		if ( in_array( $key, self::WOO_KEYS, true ) ) {
			return 'WooCommerce';
		}
		if ( in_array( $key, self::CORE_KEYS, true ) ) {
			return 'WordPress Core';
		}

		global $wpdb;
		if ( 0 === strpos( $key, $wpdb->get_blog_prefix() . 'capabilities' ) || 0 === strpos( $key, $wpdb->get_blog_prefix() . 'user' ) ) {
			return 'WordPress Core';
		}

		$best       = '';
		$best_group = '';
		foreach ( self::PREFIX_GROUPS as $prefix => $group ) {
			if ( 0 === strpos( $key, $prefix ) && strlen( $prefix ) > strlen( $best ) ) {
				$best       = $prefix;
				$best_group = $group;
			}
		}
		if ( $best_group ) {
			return $best_group;
		}

		if ( '' === $key ) {
			return 'Custom Fields';
		}

		return '_' === $key[0] ? 'Technical' : 'Custom Fields';
	}
}
