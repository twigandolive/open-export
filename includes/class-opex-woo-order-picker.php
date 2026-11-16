<?php
/**
 * What the pickers show for WooCommerce orders: the source entry with its
 * live count, the field groups including order meta, and each field's coarse type.
 *
 * Part of the WooCommerce orders compartment. Other code reaches it through OPEX_Woo.
 *
 * @package Open_Export
 */

// No strict_types here on purpose: this file holds moved code, and strict types would change how arguments are coerced.

defined( 'ABSPATH' ) || exit;

/**
 * Builds the picker entries for WooCommerce orders on either storage.
 */
class OPEX_Woo_Order_Picker {

	/**
	 * Coarse type hint for the capability document.
	 *
	 * @param string $field Field id.
	 * @return string A coarse type, such as text, number, date, boolean or enum.
	 */
	public static function field_type( string $field ): string {
		$name = substr( $field, 4 );

		if ( 0 === strpos( $name, 'date_' ) ) {
			return 'date';
		}
		if ( 'is_guest' === $name ) {
			return 'boolean';
		}
		if ( 'status' === $name ) {
			return 'enum';
		}
		if ( in_array( $name, array( 'order_id', 'order_number', 'customer_id', 'parent_order_id' ), true ) ) {
			return 'id';
		}
		if ( in_array( $name, array( 'total', 'tax_total', 'shipping_total', 'shipping_tax', 'discount_total', 'discount_tax' ), true ) ) {
			return 'number';
		}
		return 'text';
	}

	/**
	 * Field groups for the picker, in a sensible reading order.
	 *
	 * @return array[] Field groups, each with a label and its fields.
	 */
	public static function fields(): array {
		$order_of_groups = array(
			__( 'Order', 'open-export' ),
			__( 'Customer', 'open-export' ),
			__( 'Totals', 'open-export' ),
			__( 'Payment', 'open-export' ),
			__( 'Billing address', 'open-export' ),
			__( 'Shipping address', 'open-export' ),
			__( 'Fulfilment', 'open-export' ),
		);

		$buckets = array();

		foreach ( OPEX_Woo::field_map() as $id => $field ) {
			// The SAME id on both engines. The query layer translates woo: ids
			// to their core:/meta: equivalents when orders live in wp_posts, so
			// one recipe works on any store and a migration changes nothing.
			$buckets[ $field['group'] ][] = array(
				'id'    => $id,
				'label' => $field['label'],
				'type'  => self::field_type( $id ),
			);
		}

		$groups = array();
		foreach ( $order_of_groups as $label ) {
			if ( ! empty( $buckets[ $label ] ) ) {
				$groups[] = array(
					'label'  => $label,
					'fields' => $buckets[ $label ],
				);
				unset( $buckets[ $label ] );
			}
		}
		foreach ( $buckets as $label => $fields ) {
			$groups[] = array(
				'label'  => $label,
				'fields' => $fields,
			);
		}

		// Anything the store or its extensions wrote as order meta.
		$groups = array_merge( $groups, self::meta_groups() );

		return $groups;
	}

	/**
	 * Order meta keys, sampled and cached like every other source's keys.
	 * HPOS keeps them in wc_orders_meta. Legacy storage keeps them in postmeta.
	 *
	 * @return array[] Field groups for order meta, or an empty array when there is none.
	 */
	private static function meta_groups(): array {
		global $wpdb;

		if ( ! OPEX_Woo::use_hpos() ) {
			return OPEX_Catalog::grouped_meta(
				OPEX_Catalog::sample_meta_keys( 'posts', OPEX_Woo::LEGACY_POST_TYPE ),
				OPEX_Catalog::acf_keys()
			);
		}

		$cache_key = 'opex_keys_woo_orders';
		$keys      = get_transient( $cache_key );

		if ( ! is_array( $keys ) ) {
			$table = OPEX_Woo::table( 'meta' );
			if ( ! OPEX_Catalog::table_exists( $table ) ) {
				return array();
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the table was checked against SHOW TABLE STATUS above, and the sample is cached below.
			$keys = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT meta_key FROM %i LIMIT 800', $table ) );
			$keys = array_values( array_filter( array_map( 'strval', (array) $keys ) ) );
			sort( $keys, SORT_NATURAL | SORT_FLAG_CASE );

			set_transient( $cache_key, $keys, 5 * MINUTE_IN_SECONDS );
		}

		if ( ! $keys ) {
			return array();
		}

		$fields = array();
		foreach ( $keys as $key ) {
			$fields[] = array(
				'id'    => 'meta:' . $key,
				'label' => $key,
			);
		}

		return array(
			array(
				'label'  => __( 'Order meta', 'open-export' ),
				'fields' => $fields,
			),
		);
	}

	/**
	 * Source-picker entry, with a live order count.
	 *
	 * @return array|null The picker entry, or null when the source is not available.
	 */
	public static function source_item(): ?array {
		global $wpdb;

		if ( ! OPEX_Woo::is_active() ) {
			return null;
		}

		if ( OPEX_Woo::use_hpos() ) {
			$table = OPEX_Woo::table( 'orders' );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the table name comes from $wpdb->prefix and %i quotes it, the status list is a class constant, and the type is bound. One cheap COUNT for the picker.
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE type = %s AND status NOT IN (' . OPEX_Woo::DRAFT_STATUS_SQL . ')', $table, 'shop_order' ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one cheap COUNT for the picker.
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN (" . OPEX_Woo::DRAFT_STATUS_SQL . ')', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the status list is a class constant of quoted literals.
					OPEX_Woo::LEGACY_POST_TYPE
				)
			);
		}

		return array(
			'id'    => OPEX_Woo::SOURCE,
			'label' => __( 'WooCommerce Orders', 'open-export' ),
			'icon'  => 'dashicons-cart',
			'count' => $count,
		);
	}
}
