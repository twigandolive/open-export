<?php
/**
 * Meta keys for the field picker: samples the keys a source carries, spots
 * the ones ACF owns, and sorts them into groups by the plugin that wrote them.
 *
 * Part of the catalog compartment. Other code reaches it through OPEX_Catalog.
 */

// No strict_types here on purpose: this file holds moved code, and strict types would change how arguments are coerced.

defined( 'ABSPATH' ) || exit;

class OPEX_Catalog_Meta {

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
		'wp_capabilities', 'wp_user_level', 'nickname', 'first_name',
		'last_name', 'description', 'rich_editing', 'syntax_highlighting', 'comment_shortcuts',
		'admin_color', 'use_ssl', 'show_admin_bar_front', 'locale', 'dismissed_wp_pointers',
		'show_welcome_panel', 'last_update', 'community-events-location',
	);

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

		// Credential keys are never offered, whatever they are attached to.
		$keys = array_values( array_diff( array_filter( array_map( 'strval', (array) $keys ) ), OPEX_Query::CREDENTIAL_META_KEYS ) );
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
	public static function grouped_meta( array $keys, array $acf_keys ): array {
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
