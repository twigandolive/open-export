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

	/**
	 * Where each item type keeps its line amounts. A fee has no subtotal, and
	 * shipping, coupon and tax items name their amounts differently.
	 */
	const SUBTOTAL_KEYS     = array( 'line_item' => '_line_subtotal' );
	const SUBTOTAL_TAX_KEYS = array( 'line_item' => '_line_subtotal_tax' );
	const TOTAL_KEYS        = array(
		'line_item' => '_line_total',
		'fee'       => '_line_total',
		'shipping'  => 'cost',
		'coupon'    => 'discount_amount',
		'tax'       => 'tax_amount',
	);
	const TAX_KEYS          = array(
		'line_item' => '_line_tax',
		'fee'       => '_line_tax',
		'shipping'  => 'total_tax',
		'coupon'    => 'discount_amount_tax',
		'tax'       => 'shipping_tax_amount',
	);

	/**
	 * The provider when WooCommerce is active, else null.
	 *
	 * @return OPEX_Source_Provider|null The provider, or null when WooCommerce is not active.
	 */
	public static function resolve(): ?OPEX_Source_Provider {
		return OPEX_Woo::is_active() ? OPEX_Sources::instance( __CLASS__ ) : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string The source id.
	 */
	public function id(): string {
		return self::SOURCE;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string The source type.
	 */
	public function src_type(): string {
		return 'woo_order_items';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array The parsed source.
	 */
	public function src(): array {
		// The storage engine is part of the source, so memoized date columns
		// can never be shared between the two.
		return parent::src() + array( 'storage' => OPEX_Woo::use_hpos() ? 'hpos' : 'legacy' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string A key that changes when the definitions must be built again.
	 */
	protected function defs_key(): string {
		global $wpdb;
		return $wpdb->prefix . ( OPEX_Woo::use_hpos() ? '|hpos' : '|legacy' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool True when the source can be used on this site.
	 */
	public function is_available(): bool {
		global $wpdb;
		return OPEX_Catalog::table_exists( $wpdb->prefix . 'woocommerce_order_items' )
			&& OPEX_Catalog::table_exists( $wpdb->prefix . 'woocommerce_order_itemmeta' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string The FROM clause.
	 */
	public function from(): string {
		global $wpdb;
		$items = "{$wpdb->prefix}woocommerce_order_items oi";
		if ( OPEX_Woo::use_hpos() ) {
			return "{$items} INNER JOIN `" . OPEX_Woo::table( 'orders' ) . '` o ON o.id = oi.order_id';
		}
		return "{$items} INNER JOIN {$wpdb->posts} op ON op.ID = oi.order_id";
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array The base WHERE conditions and the values for their placeholders.
	 */
	public function base_where(): array {
		// The same orders the orders source exports: no refunds, no carts.
		if ( OPEX_Woo::use_hpos() ) {
			return array( array( "o.type = 'shop_order'", 'o.status NOT IN (' . OPEX_Woo::DRAFT_STATUS_SQL . ')' ), array() );
		}
		return array( array( "op.post_type = 'shop_order'", 'op.post_status NOT IN (' . OPEX_Woo::DRAFT_STATUS_SQL . ')' ), array() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string The key column for cursor paging.
	 */
	public function primary_key(): string {
		return 'oi.order_item_id';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string The key property name on a fetched row.
	 */
	public function id_prop(): string {
		return 'order_item_id';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string The SQL for the start of the SELECT list.
	 */
	protected function base_select(): string {
		return 'oi.order_item_id';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string The source name.
	 */
	public function label(): string {
		return __( 'WooCommerce Order Items', 'open-export' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array|null The picker entry, or null when the source has none.
	 */
	public function picker_item(): ?array {
		return array(
			'id'    => self::SOURCE,
			'label' => __( 'WooCommerce Order Items', 'open-export' ),
			'icon'  => 'dashicons-list-view',
			'count' => $this->count_rows(),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array> Date fields by field id, each with a label and how its time is stored.
	 */
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

	/**
	 * {@inheritDoc}
	 *
	 * @param string $field Field id of the rule.
	 * @param string $op    Filter operator.
	 * @param string $value Rule value as typed.
	 * @return string The value the rule compares.
	 */
	public function prepare_filter_value( string $field, string $op, string $value ): string {
		// The order status takes "completed" or "wc-completed", as on the orders source.
		return 'woo:order_status' === $field ? OPEX_Source_Woo_Orders_Rules::filter_value( 'woo:status', $op, $value ) : $value;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array> Field definitions by field id.
	 */
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
		$sku   = "COALESCE(NULLIF((SELECT sv.meta_value FROM {$wpdb->postmeta} sv WHERE sv.post_id = {$variation} AND sv.meta_key = '_sku' ORDER BY sv.meta_id LIMIT 1), ''), " .
			"(SELECT sp.meta_value FROM {$wpdb->postmeta} sp WHERE sp.post_id = {$product} AND sp.meta_key = '_sku' ORDER BY sp.meta_id LIMIT 1))";
		$email = $hpos ? 'o.billing_email' : "(SELECT be.meta_value FROM {$wpdb->postmeta} be WHERE be.post_id = op.ID AND be.meta_key = '_billing_email' ORDER BY be.meta_id LIMIT 1)";
		$stati = function_exists( 'wc_get_order_statuses' ) ? array_keys( wc_get_order_statuses() ) : array();

		return array(
			'woo:item_id'            => array(
				'group' => $item,
				'type'  => 'id',
				'sql'   => 'oi.order_item_id',
			),
			'woo:order_id'           => array(
				'group' => $item,
				'type'  => 'id',
				'sql'   => 'oi.order_id',
			),
			'woo:item_type'          => array(
				'group'  => $item,
				'type'   => 'enum',
				'sql'    => 'oi.order_item_type',
				'values' => array( 'line_item', 'shipping', 'fee', 'coupon', 'tax' ),
			),
			'woo:item_name'          => array(
				'group' => $item,
				'type'  => 'text',
				'sql'   => 'oi.order_item_name',
			),
			'woo:product_id'         => array(
				'group' => $item,
				'type'  => 'id',
				'sql'   => $product,
			),
			'woo:variation_id'       => array(
				'group' => $item,
				'type'  => 'id',
				'sql'   => $variation,
			),
			'woo:sku'                => array(
				'group' => $item,
				'type'  => 'text',
				'sql'   => $sku,
			),
			'woo:quantity'           => array(
				'group' => $item,
				'type'  => 'number',
				'sql'   => $this->item_meta( '_qty' ),
			),
			'woo:line_subtotal'      => array(
				'group'  => $money,
				'type'   => 'number',
				'sql'    => $this->by_type( self::SUBTOTAL_KEYS ),
				'format' => 'money',
			),
			'woo:line_subtotal_tax'  => array(
				'group'  => $money,
				'type'   => 'number',
				'sql'    => $this->by_type( self::SUBTOTAL_TAX_KEYS ),
				'format' => 'money',
			),
			'woo:line_total'         => array(
				'group'  => $money,
				'type'   => 'number',
				'sql'    => $this->by_type( self::TOTAL_KEYS ),
				'format' => 'money',
			),
			'woo:line_tax'           => array(
				'group'  => $money,
				'type'   => 'number',
				'sql'    => $this->by_type( self::TAX_KEYS ),
				'format' => 'money',
			),
			'woo:order_number'       => array(
				'group' => $order,
				'type'  => 'id',
				'sql'   => $hpos ? 'o.id' : 'op.ID',
			),
			'woo:order_date_created' => array(
				'group'  => $order,
				'type'   => 'date',
				'sql'    => $hpos ? 'o.date_created_gmt' : 'op.post_date',
				'format' => $hpos ? 'utc' : 'local',
			),
			'woo:order_status'       => array(
				'group'  => $order,
				'type'   => 'enum',
				'sql'    => $hpos ? 'o.status' : 'op.post_status',
				'values' => $stati,
			),
			'woo:billing_email'      => array(
				'group' => $order,
				'type'  => 'email',
				'sql'   => $email,
			),
		);
	}

	/**
	 * SQL that reads a different meta key for each item type. A type with no
	 * key gives NULL, which prints empty.
	 *
	 * @param array<string, string> $keys Item type => meta key, constants in this class.
	 * @return string The SQL CASE expression.
	 */
	private function by_type( array $keys ): string {
		$sql = 'CASE oi.order_item_type';
		foreach ( $keys as $type => $key ) {
			$sql .= " WHEN '{$type}' THEN " . $this->item_meta( $key );
		}
		return $sql . ' END';
	}

	/**
	 * SQL for the first value of an item meta key.
	 *
	 * @param string $key Meta key, a constant in this class.
	 * @return string The SQL subquery.
	 */
	private function item_meta( string $key ): string {
		global $wpdb;
		return "(SELECT im.meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta im WHERE im.order_item_id = oi.order_item_id AND im.meta_key = '{$key}' ORDER BY im.meta_id LIMIT 1)";
	}
}
