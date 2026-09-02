<?php
/**
 * WooCommerce orders as a first-class source.
 *
 * WooCommerce stores orders in one of two places. Legacy sites keep them in
 * wp_posts as the "shop_order" post type with everything else in postmeta.
 * Sites on High-Performance Order Storage keep them in wp_wc_orders with
 * addresses and operational data in sibling tables, and wp_posts holds nothing.
 * That is why picking "Orders" from the post-type list on an HPOS store exports
 * an empty file: the rows simply are not there any more.
 *
 * This class hides that split. Both storage modes present the same friendly
 * field names, so a saved template keeps working if a store migrates.
 *
 * Refunds (shop_order_refund) are excluded from both modes: an order report
 * that silently interleaved refund rows with order rows would misreport totals.
 */

defined( 'ABSPATH' ) || exit;

class OPEX_Woo {

	/** The source id the UI and saved configs use. */
	public const SOURCE = 'woo:orders';

	/** Post type backing legacy order storage. */
	public const LEGACY_POST_TYPE = 'shop_order';

	/** True when WooCommerce is present and loaded. */
	public static function is_active(): bool {
		return class_exists( 'WooCommerce' ) || defined( 'WC_VERSION' );
	}

	/**
	 * True when the custom order tables are the authoritative store.
	 *
	 * OrderUtil::custom_orders_table_usage_is_enabled() arrived in WooCommerce
	 * 6.8 and is the only supported way to ask. Older WooCommerce predates HPOS
	 * entirely, so "not available" correctly means legacy. In compatibility mode
	 * both stores are kept in sync and this returns whichever WooCommerce treats
	 * as canonical, which is the one we want to read.
	 */
	public static function hpos_enabled(): bool {
		if ( ! self::is_active() ) {
			return false;
		}

		$util = '\Automattic\WooCommerce\Utilities\OrderUtil';
		if ( class_exists( $util ) && method_exists( $util, 'custom_orders_table_usage_is_enabled' ) ) {
			return (bool) call_user_func( array( $util, 'custom_orders_table_usage_is_enabled' ) );
		}

		return false;
	}

	/** Fully-qualified HPOS table names. */
	public static function table( string $which ): string {
		global $wpdb;

		$map = array(
			'orders'      => 'wc_orders',
			'addresses'   => 'wc_order_addresses',
			'operational' => 'wc_order_operational_data',
			'meta'        => 'wc_orders_meta',
		);

		return $wpdb->prefix . ( $map[ $which ] ?? 'wc_orders' );
	}

	/**
	 * True when the HPOS tables actually exist. HPOS can be switched on while a
	 * migration is still pending, and a missing table would otherwise surface as
	 * a raw SQL error mid-export.
	 */
	public static function hpos_tables_ready(): bool {
		foreach ( array( 'orders', 'addresses', 'operational' ) as $which ) {
			if ( ! OPEX_Catalog::table_exists( self::table( $which ) ) ) {
				return false;
			}
		}
		return true;
	}

	/** Whether the joined HPOS query should be used for this request. */
	public static function use_hpos(): bool {
		return self::hpos_enabled() && self::hpos_tables_ready();
	}

	/**
	 * Canonical order fields.
	 *
	 * Each entry carries how to read it in both storage modes:
	 *   hpos   - a SQL expression over the joined aliases (o, ba, sa, od)
	 *   legacy - an existing Open Export field id (core:… or meta:…)
	 *
	 * The alias is the row property the HPOS SELECT list produces, and is
	 * derived from the field id so the two can never drift apart.
	 *
	 * @return array<string, array<string, string>> keyed by field id.
	 */
	public static function field_map(): array {
		static $map = null;
		if ( null !== $map ) {
			return $map;
		}

		$order = __( 'Order', 'open-export' );
		$cust  = __( 'Customer', 'open-export' );
		$bill  = __( 'Billing address', 'open-export' );
		$ship  = __( 'Shipping address', 'open-export' );
		$money = __( 'Totals', 'open-export' );
		$pay   = __( 'Payment', 'open-export' );
		$admin = __( 'Fulfilment', 'open-export' );

		$def = array(
			// --- Order ------------------------------------------------------
			'order_id'          => array( 'o.id', 'core:ID', $order ),
			'order_number'      => array( 'o.id', 'core:ID', $order ),
			'status'            => array( 'o.status', 'core:post_status', $order ),
			'date_created'      => array( 'o.date_created_gmt', 'core:post_date', $order ),
			'date_modified'     => array( 'o.date_updated_gmt', 'core:post_modified', $order ),
			'currency'          => array( 'o.currency', 'meta:_order_currency', $order ),
			'customer_note'     => array( 'o.customer_note', 'core:post_excerpt', $order ),
			'order_key'         => array( 'od.order_key', 'meta:_order_key', $order ),
			'parent_order_id'   => array( 'o.parent_order_id', 'core:post_parent', $order ),

			// --- Customer ---------------------------------------------------
			'customer_id'       => array( 'o.customer_id', 'meta:_customer_user', $cust ),
			'customer_email'    => array( 'o.billing_email', 'meta:_billing_email', $cust ),
			'customer_ip'       => array( 'o.ip_address', 'meta:_customer_ip_address', $cust ),
			'customer_agent'    => array( 'o.user_agent', 'meta:_customer_user_agent', $cust ),

			// --- Billing ----------------------------------------------------
			'billing_first_name' => array( 'ba.first_name', 'meta:_billing_first_name', $bill ),
			'billing_last_name'  => array( 'ba.last_name', 'meta:_billing_last_name', $bill ),
			'billing_company'    => array( 'ba.company', 'meta:_billing_company', $bill ),
			'billing_address_1'  => array( 'ba.address_1', 'meta:_billing_address_1', $bill ),
			'billing_address_2'  => array( 'ba.address_2', 'meta:_billing_address_2', $bill ),
			'billing_city'       => array( 'ba.city', 'meta:_billing_city', $bill ),
			'billing_state'      => array( 'ba.state', 'meta:_billing_state', $bill ),
			'billing_postcode'   => array( 'ba.postcode', 'meta:_billing_postcode', $bill ),
			'billing_country'    => array( 'ba.country', 'meta:_billing_country', $bill ),
			'billing_email'      => array( 'ba.email', 'meta:_billing_email', $bill ),
			'billing_phone'      => array( 'ba.phone', 'meta:_billing_phone', $bill ),

			// --- Shipping ---------------------------------------------------
			'shipping_first_name' => array( 'sa.first_name', 'meta:_shipping_first_name', $ship ),
			'shipping_last_name'  => array( 'sa.last_name', 'meta:_shipping_last_name', $ship ),
			'shipping_company'    => array( 'sa.company', 'meta:_shipping_company', $ship ),
			'shipping_address_1'  => array( 'sa.address_1', 'meta:_shipping_address_1', $ship ),
			'shipping_address_2'  => array( 'sa.address_2', 'meta:_shipping_address_2', $ship ),
			'shipping_city'       => array( 'sa.city', 'meta:_shipping_city', $ship ),
			'shipping_state'      => array( 'sa.state', 'meta:_shipping_state', $ship ),
			'shipping_postcode'   => array( 'sa.postcode', 'meta:_shipping_postcode', $ship ),
			'shipping_country'    => array( 'sa.country', 'meta:_shipping_country', $ship ),
			'shipping_phone'      => array( 'sa.phone', 'meta:_shipping_phone', $ship ),

			// --- Totals -----------------------------------------------------
			'total'             => array( 'o.total_amount', 'meta:_order_total', $money ),
			'tax_total'         => array( 'o.tax_amount', 'meta:_order_tax', $money ),
			'shipping_total'    => array( 'od.shipping_total_amount', 'meta:_order_shipping', $money ),
			'shipping_tax'      => array( 'od.shipping_tax_amount', 'meta:_order_shipping_tax', $money ),
			'discount_total'    => array( 'od.discount_total_amount', 'meta:_cart_discount', $money ),
			'discount_tax'      => array( 'od.discount_tax_amount', 'meta:_cart_discount_tax', $money ),
			'prices_include_tax' => array( 'od.prices_include_tax', 'meta:_prices_include_tax', $money ),

			// --- Payment ----------------------------------------------------
			'payment_method'       => array( 'o.payment_method', 'meta:_payment_method', $pay ),
			'payment_method_title' => array( 'o.payment_method_title', 'meta:_payment_method_title', $pay ),
			'transaction_id'       => array( 'o.transaction_id', 'meta:_transaction_id', $pay ),
			'date_paid'            => array( 'od.date_paid_gmt', 'meta:_date_paid', $pay ),

			// --- Fulfilment -------------------------------------------------
			'date_completed'   => array( 'od.date_completed_gmt', 'meta:_date_completed', $admin ),
			'created_via'      => array( 'od.created_via', 'meta:_created_via', $admin ),
			'cart_hash'        => array( 'od.cart_hash', 'meta:_cart_hash', $admin ),
			'stock_reduced'    => array( 'od.order_stock_reduced', 'meta:_order_stock_reduced', $admin ),
			'woo_version'      => array( 'od.woocommerce_version', 'meta:_order_version', $admin ),
		);

		$map = array();
		foreach ( $def as $name => $parts ) {
			$map[ 'woo:' . $name ] = array(
				'label'  => $name,
				'expr'   => $parts[0],
				'alias'  => 'wf_' . $name,
				'legacy' => $parts[1],
				'group'  => $parts[2],
			);
		}

		return $map;
	}

	/** Whether a field id is one of ours. */
	public static function is_woo_field( string $field ): bool {
		return isset( self::field_map()[ $field ] );
	}

	/**
	 * Date columns offered to the range filter under HPOS. Every HPOS date
	 * column is stored in UTC.
	 *
	 * Legacy storage needs nothing here: those orders are ordinary posts, so
	 * OPEX_Query::date_fields() already offers post_date and its GMT twin.
	 * Legacy _date_paid / _date_completed live in postmeta as UNIX timestamps,
	 * which cannot be compared against a datetime literal, so they are omitted
	 * rather than silently mismatched.
	 *
	 * @return array<string, array{label: string, tz: string, sql: string}>
	 */
	public static function hpos_date_fields(): array {
		return array(
			'woo:date_created'   => array(
				'label' => __( 'Order date (created)', 'open-export' ),
				'tz'    => OPEX_Dates::TZ_UTC,
				'sql'   => 'o.date_created_gmt',
			),
			'woo:date_paid'      => array(
				'label' => __( 'Date paid', 'open-export' ),
				'tz'    => OPEX_Dates::TZ_UTC,
				'sql'   => 'od.date_paid_gmt',
			),
			'woo:date_completed' => array(
				'label' => __( 'Date completed', 'open-export' ),
				'tz'    => OPEX_Dates::TZ_UTC,
				'sql'   => 'od.date_completed_gmt',
			),
			'woo:date_modified'  => array(
				'label' => __( 'Last modified', 'open-export' ),
				'tz'    => OPEX_Dates::TZ_UTC,
				'sql'   => 'o.date_updated_gmt',
			),
		);
	}

	/**
	 * FROM clause for the HPOS query. Every join is many-to-one — one billing
	 * row, one shipping row, one operational row per order — so the result is
	 * exactly one row per order and keyset paging on o.id stays sound.
	 */
	public static function from_clause(): string {
		$orders    = self::table( 'orders' );
		$addresses = self::table( 'addresses' );
		$oper      = self::table( 'operational' );

		return "`{$orders}` o" .
			" LEFT JOIN `{$addresses}` ba ON ba.order_id = o.id AND ba.address_type = 'billing'" .
			" LEFT JOIN `{$addresses}` sa ON sa.order_id = o.id AND sa.address_type = 'shipping'" .
			" LEFT JOIN `{$oper}` od ON od.order_id = o.id";
	}

	/**
	 * Explicit aliased SELECT list. The joined tables are narrow and there is
	 * one row per order, so selecting the full set costs little and keeps the
	 * SQL free of any per-request string building.
	 */
	public static function select_list(): string {
		// Aliased as `id` so the shared keyset-paging code can read the cursor
		// off the row with its usual unqualified-column rule.
		$parts = array( 'o.id AS id' );
		$seen  = array();

		foreach ( self::field_map() as $field ) {
			if ( isset( $seen[ $field['alias'] ] ) ) {
				continue;
			}
			$seen[ $field['alias'] ] = true;
			$parts[]                 = $field['expr'] . ' AS `' . $field['alias'] . '`';
		}

		return implode( ', ', $parts );
	}

	/** Field groups for the picker, in a sensible reading order. */
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

		$hpos    = self::use_hpos();
		$buckets = array();

		foreach ( self::field_map() as $id => $field ) {
			// Under legacy storage the friendly id resolves to an existing
			// core:/meta: id, so saved configs stay portable either way.
			$buckets[ $field['group'] ][] = array(
				'id'    => $hpos ? $id : $field['legacy'],
				'label' => $field['label'],
			);
		}

		$groups = array();
		foreach ( $order_of_groups as $label ) {
			if ( ! empty( $buckets[ $label ] ) ) {
				$groups[] = array( 'label' => $label, 'fields' => $buckets[ $label ] );
				unset( $buckets[ $label ] );
			}
		}
		foreach ( $buckets as $label => $fields ) {
			$groups[] = array( 'label' => $label, 'fields' => $fields );
		}

		// Anything the store or its extensions wrote as order meta.
		$groups = array_merge( $groups, self::meta_groups() );

		return $groups;
	}

	/**
	 * Order meta keys, sampled and cached like every other source's keys.
	 * HPOS keeps them in wc_orders_meta; legacy keeps them in postmeta.
	 */
	private static function meta_groups(): array {
		global $wpdb;

		if ( ! self::use_hpos() ) {
			return OPEX_Catalog::grouped_meta(
				OPEX_Catalog::sample_meta_keys( 'posts', self::LEGACY_POST_TYPE ),
				OPEX_Catalog::acf_keys()
			);
		}

		$cache_key = 'opex_keys_woo_orders';
		$keys      = get_transient( $cache_key );

		if ( ! is_array( $keys ) ) {
			$table = self::table( 'meta' );
			if ( ! OPEX_Catalog::table_exists( $table ) ) {
				return array();
			}

			$safe = str_replace( '`', '', $table );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- identifier validated against SHOW TABLE STATUS; key sampling cached below.
			$keys = $wpdb->get_col( "SELECT DISTINCT meta_key FROM `{$safe}` LIMIT 800" );
			$keys = array_values( array_filter( array_map( 'strval', (array) $keys ) ) );
			sort( $keys, SORT_NATURAL | SORT_FLAG_CASE );

			set_transient( $cache_key, $keys, 5 * MINUTE_IN_SECONDS );
		}

		if ( ! $keys ) {
			return array();
		}

		$fields = array();
		foreach ( $keys as $key ) {
			$fields[] = array( 'id' => 'meta:' . $key, 'label' => $key );
		}

		return array(
			array( 'label' => __( 'Order meta', 'open-export' ), 'fields' => $fields ),
		);
	}

	/** Source-picker entry, with a live order count. */
	public static function source_item(): ?array {
		global $wpdb;

		if ( ! self::is_active() ) {
			return null;
		}

		if ( self::use_hpos() ) {
			$table = self::table( 'orders' );
			$safe  = str_replace( '`', '', $table );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- identifier validated above; one cheap COUNT for the picker.
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$safe}` WHERE type = %s", 'shop_order' ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one cheap COUNT for the picker.
			$count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'auto-draft'",
				self::LEGACY_POST_TYPE
			) );
		}

		return array(
			'id'    => self::SOURCE,
			'label' => __( 'WooCommerce Orders', 'open-export' ),
			'icon'  => 'dashicons-cart',
			'count' => $count,
		);
	}
}
