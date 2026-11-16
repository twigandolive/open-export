<?php
/**
 * Provider for WooCommerce order items: one row per item of an order.
 *
 * Items live in woocommerce_order_items on both order storage engines, with
 * their numbers in woocommerce_order_itemmeta. The order they belong to lives
 * in wc_orders under High-Performance Order Storage, or in wp_posts on classic
 * storage, so only the join and the order columns change between the two.
 * Item meta is read with one small subquery per field, never a join, so a
 * repeated meta key can never turn one item into two rows and break paging.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Order items joined to their order on either storage engine.
 */
class OPEX_Source_Woo_Order_Items extends OPEX_Source_Table {

	use OPEX_Source_Mapped;

	/** The source id saved configs use. */
	const SOURCE = 'woo:order_items';

	/** The provider when WooCommerce is active, else null. */
	public static function resolve(): ?OPEX_Source_Provider {
		return OPEX_Woo::is_active() ? OPEX_Sources::instance( __CLASS__ ) : null;
	}

	/** @inheritDoc */
	public function id(): string {
		return self::SOURCE;
	}

	/** @inheritDoc */
	public function src_type(): string {
		return 'woo_order_items';
	}

	/** @inheritDoc */
	public function src(): array {
		// The storage engine is part of the source, so memoized date columns
		// can never be shared between the two.
		return parent::src() + array( 'storage' => OPEX_Woo::use_hpos() ? 'hpos' : 'legacy' );
	}

	/** @inheritDoc */
	protected function defs_key(): string {
		global $wpdb;
		return $wpdb->prefix . ( OPEX_Woo::use_hpos() ? '|hpos' : '|legacy' );
	}

	/** @inheritDoc */
	public function is_available(): bool {
		global $wpdb;
		return OPEX_Catalog::table_exists( $wpdb->prefix . 'woocommerce_order_items' )
			&& OPEX_Catalog::table_exists( $wpdb->prefix . 'woocommerce_order_itemmeta' );
	}

	/** @inheritDoc */
	public function from(): string {
		global $wpdb;
		$items = "{$wpdb->prefix}woocommerce_order_items oi";
		if ( OPEX_Woo::use_hpos() ) {
			return "{$items} INNER JOIN `" . OPEX_Woo::table( 'orders' ) . '` o ON o.id = oi.order_id';
		}
		return "{$items} INNER JOIN {$wpdb->posts} op ON op.ID = oi.order_id";
	}

	/** @inheritDoc */
	public function base_where(): array {
		// The same orders the orders source exports: no refunds, no carts.
		if ( OPEX_Woo::use_hpos() ) {
			return array( array( "o.type = 'shop_order'", "o.status NOT IN ('auto-draft', 'checkout-draft')" ), array() );
		}
		return array( array( "op.post_type = 'shop_order'", "op.post_status NOT IN ('auto-draft', 'checkout-draft')" ), array() );
	}

	/** @inheritDoc */
	public function primary_key(): string {
		return 'oi.order_item_id';
	}

	/** @inheritDoc */
	public function id_prop(): string {
		return 'order_item_id';
	}

	/** @inheritDoc */
	protected function base_select(): string {
		return 'oi.order_item_id';
	}

	/** @inheritDoc */
	public function picker_item(): ?array {
		return array(
			'id'    => self::SOURCE,
			'label' => __( 'WooCommerce Order Items', 'open-export' ),
			'icon'  => 'dashicons-list-view',
			'count' => $this->count_rows(),
		);
	}

	/** @inheritDoc */
	public function date_fields(): array {
		$hpos = OPEX_Woo::use_hpos();
		return array(
			'woo:order_date_created' => array(
				'label' => __( 'Order date (created)', 'open-export' ),
				'tz'    => $hpos ? OPEX_Dates::TZ_UTC : OPEX_Dates::TZ_LOCAL,
				'kind'  => 'column',
				'sql'   => $hpos ? 'o.date_created_gmt' : 'op.post_date',
			),
		);
	}

	/** @inheritDoc */
	public function prepare_filter_value( string $field, string $op, string $value ): string {
		// The order status takes "completed" or "wc-completed", as on the orders source.
		return 'woo:order_status' === $field ? OPEX_Source_Woo_Orders_Rules::filter_value( 'woo:status', $op, $value ) : $value;
	}

	/** @inheritDoc */
	protected function field_defs(): array {
		global $wpdb;
		$item  = __( 'Item', 'open-export' );
		$money = __( 'Totals', 'open-export' );
		$order = __( 'Order', 'open-export' );
		$hpos  = OPEX_Woo::use_hpos();

		$variation = $this->item_meta( '_variation_id' );
		$product   = $this->item_meta( '_product_id' );
		// The product's SKU today. A variation without its own SKU shows its
		// parent's, as WooCommerce does.
		$sku = "COALESCE(NULLIF((SELECT sv.meta_value FROM {$wpdb->postmeta} sv WHERE sv.post_id = {$variation} AND sv.meta_key = '_sku' ORDER BY sv.meta_id LIMIT 1), ''), " .
			"(SELECT sp.meta_value FROM {$wpdb->postmeta} sp WHERE sp.post_id = {$product} AND sp.meta_key = '_sku' ORDER BY sp.meta_id LIMIT 1))";
		$email = $hpos ? 'o.billing_email' : "(SELECT be.meta_value FROM {$wpdb->postmeta} be WHERE be.post_id = op.ID AND be.meta_key = '_billing_email' ORDER BY be.meta_id LIMIT 1)";
		$stati = function_exists( 'wc_get_order_statuses' ) ? array_keys( wc_get_order_statuses() ) : array();

		return array(
			'woo:item_id'            => array( 'group' => $item, 'type' => 'id', 'sql' => 'oi.order_item_id' ),
			'woo:order_id'           => array( 'group' => $item, 'type' => 'id', 'sql' => 'oi.order_id' ),
			'woo:item_type'          => array( 'group' => $item, 'type' => 'enum', 'sql' => 'oi.order_item_type', 'values' => array( 'line_item', 'shipping', 'fee', 'coupon', 'tax' ) ),
			'woo:item_name'          => array( 'group' => $item, 'type' => 'text', 'sql' => 'oi.order_item_name' ),
			'woo:product_id'         => array( 'group' => $item, 'type' => 'id', 'sql' => $product ),
			'woo:variation_id'       => array( 'group' => $item, 'type' => 'id', 'sql' => $variation ),
			'woo:sku'                => array( 'group' => $item, 'type' => 'text', 'sql' => $sku ),
			'woo:quantity'           => array( 'group' => $item, 'type' => 'number', 'sql' => $this->item_meta( '_qty' ) ),
			'woo:line_subtotal'      => array( 'group' => $money, 'type' => 'number', 'sql' => $this->item_meta( '_line_subtotal' ), 'format' => 'money' ),
			'woo:line_subtotal_tax'  => array( 'group' => $money, 'type' => 'number', 'sql' => $this->item_meta( '_line_subtotal_tax' ), 'format' => 'money' ),
			'woo:line_total'         => array( 'group' => $money, 'type' => 'number', 'sql' => $this->item_meta( '_line_total' ), 'format' => 'money' ),
			'woo:line_tax'           => array( 'group' => $money, 'type' => 'number', 'sql' => $this->item_meta( '_line_tax' ), 'format' => 'money' ),
			'woo:order_number'       => array( 'group' => $order, 'type' => 'id', 'sql' => $hpos ? 'o.id' : 'op.ID' ),
			'woo:order_date_created' => array( 'group' => $order, 'type' => 'date', 'sql' => $hpos ? 'o.date_created_gmt' : 'op.post_date', 'format' => $hpos ? 'utc' : 'local' ),
			'woo:order_status'       => array( 'group' => $order, 'type' => 'enum', 'sql' => $hpos ? 'o.status' : 'op.post_status', 'values' => $stati ),
			'woo:billing_email'      => array( 'group' => $order, 'type' => 'email', 'sql' => $email ),
		);
	}

	/**
	 * SQL for the first value of an item meta key.
	 *
	 * @param string $key Meta key, a constant in this class.
	 */
	private function item_meta( string $key ): string {
		global $wpdb;
		return "(SELECT im.meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta im WHERE im.order_item_id = oi.order_item_id AND im.meta_key = '{$key}' ORDER BY im.meta_id LIMIT 1)";
	}
}
