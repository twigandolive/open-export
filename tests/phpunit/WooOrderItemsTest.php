<?php
/**
 * The woo:order_items source, on whichever order storage the run uses.
 *
 * Fixtures: two products, a completed order with five items of every kind, a
 * checkout draft and a refund (both must be left out). The site timezone is
 * New York while they are made, so a UTC order date (custom tables) and a
 * site-time one (classic posts) must print the same text. All of it is made in
 * setUp and removed in tearDown.
 *
 * @package Open_Export
 */

require_once __DIR__ . '/WooSourceCase.php';

/**
 * Order items through the real engine.
 */
class WooOrderItemsTest extends WooSourceCase {

	/** @var array<string, int> Fixture ids by name. */
	private $ids = array();

	/** @var string Timezone before the test. */
	private $timezone = '';

	/** Makes the fixtures. */
	protected function setUp(): void {
		parent::setUp();
		$this->timezone = (string) get_option( 'timezone_string' );
		update_option( 'timezone_string', 'America/New_York' );

		$simple = new WC_Product_Simple();
		$simple->set_props( array( 'name' => 'OPEX Item Simple', 'sku' => 'OPEX-IT-1', 'regular_price' => '10' ) );
		$this->ids['simple'] = (int) $simple->save();

		$size = new WC_Product_Attribute();
		$size->set_name( 'Size' );
		$size->set_options( array( 'Small' ) );
		$size->set_variation( true );
		$parent = new WC_Product_Variable();
		$parent->set_props( array( 'name' => 'OPEX Item Var', 'sku' => 'OPEX-IT-VAR' ) );
		$parent->set_attributes( array( $size ) );
		$this->ids['variable'] = (int) $parent->save();
		$variation = new WC_Product_Variation();
		$variation->set_props( array( 'parent_id' => $this->ids['variable'], 'regular_price' => '7' ) );
		$variation->set_attributes( array( 'size' => 'Small' ) );
		$this->ids['variation'] = (int) $variation->save();

		$order = new WC_Order();
		$order->set_status( 'completed' );
		$order->set_billing_email( 'opex-items@example.test' );
		$order->set_date_created( '2021-03-04T05:06:07+00:00' );
		$line = new WC_Order_Item_Product();
		$line->set_props( array( 'product' => wc_get_product( $this->ids['simple'] ), 'quantity' => 2, 'subtotal' => '20', 'subtotal_tax' => '1.6', 'total' => '18.5', 'total_tax' => '1.235' ) );
		$order->add_item( $line );
		$var_line = new WC_Order_Item_Product();
		$var_line->set_props( array( 'product' => wc_get_product( $this->ids['variation'] ), 'quantity' => 1, 'subtotal' => '7', 'total' => '7' ) );
		$order->add_item( $var_line );
		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_props( array( 'method_title' => 'Flat rate', 'total' => '4.99' ) );
		$order->add_item( $shipping );
		$fee = new WC_Order_Item_Fee();
		$fee->set_props( array( 'name' => 'Gift wrap', 'total' => '2.5' ) );
		$order->add_item( $fee );
		$coupon = new WC_Order_Item_Coupon();
		$coupon->set_props( array( 'code' => 'opex-items', 'discount' => '1.5' ) );
		$order->add_item( $coupon );
		$this->ids['order'] = (int) $order->save();
		$this->ids['line']  = (int) $line->get_id();

		$draft = new WC_Order();
		$draft->set_status( 'checkout-draft' );
		$draft_line = new WC_Order_Item_Product();
		$draft_line->set_props( array( 'product' => wc_get_product( $this->ids['simple'] ), 'quantity' => 9, 'total' => '90' ) );
		$draft->add_item( $draft_line );
		$this->ids['draft'] = (int) $draft->save();
		// WooCommerce saves this status as "wc-checkout-draft". This fixture stores
		// the plain spelling, which older code wrote. WooDraftOrdersTest covers the real one.
		global $wpdb;
		if ( OPEX_Woo::use_hpos() ) {
			$wpdb->update( OPEX_Woo::table( 'orders' ), array( 'status' => 'checkout-draft' ), array( 'id' => $this->ids['draft'] ) );
		} else {
			$wpdb->update( $wpdb->posts, array( 'post_status' => 'checkout-draft' ), array( 'ID' => $this->ids['draft'] ) );
		}

		// Built by hand so no refund email or stock change runs.
		$refund = new WC_Order_Refund();
		$refund->set_parent_id( $this->ids['order'] );
		$refund->set_amount( '5' );
		$back = new WC_Order_Item_Product();
		$back->set_props( array( 'product' => wc_get_product( $this->ids['simple'] ), 'quantity' => -1, 'total' => '-5' ) );
		$refund->add_item( $back );
		$this->ids['refund'] = (int) $refund->save();
	}

	/** Removes the fixtures and the timezone. */
	protected function tearDown(): void {
		foreach ( array( 'refund', 'draft', 'order' ) as $name ) {
			$order = isset( $this->ids[ $name ] ) ? wc_get_order( $this->ids[ $name ] ) : null;
			if ( $order ) {
				$order->delete( true );
			}
		}
		foreach ( array( 'variation', 'variable', 'simple' ) as $name ) {
			$product = isset( $this->ids[ $name ] ) ? wc_get_product( $this->ids[ $name ] ) : null;
			if ( $product ) {
				$product->delete( true );
			}
		}
		update_option( 'timezone_string', $this->timezone );
		$this->ids = array();
		parent::tearDown();
	}

	/**
	 * Item names of one order's items that a rule keeps.
	 *
	 * @param array  $rule  Rule or group.
	 * @param string $order Fixture order name.
	 * @return string[]
	 */
	private function names( array $rule, string $order = 'order' ): array {
		$only = array( 'field' => 'woo:order_id', 'op' => 'eq', 'value' => (string) $this->ids[ $order ] );
		return $this->pluck( 'woo:order_items', 'woo:item_name', array( 'relation' => 'AND', 'rules' => array( $only, $rule ) ) );
	}

	/** Every field of every item kind, with the order's columns alongside. */
	public function test_rows_hold_every_field(): void {
		$fields = array_keys( OPEX_Catalog::field_ids( 'woo:order_items' ) );
		$this->assertCount( 16, $fields );
		$rows = $this->rows( $this->config( 'woo:order_items', array(
			'fields'  => $fields,
			'filters' => array( 'rules' => array( array( 'field' => 'woo:order_id', 'op' => 'eq', 'value' => (string) $this->ids['order'] ) ) ),
		) ) );
		$this->assertSame( array( 'line_item', 'line_item', 'shipping', 'fee', 'coupon' ), array_column( $rows, 'woo:item_type' ) );
		$order = (string) $this->ids['order'];
		foreach ( $rows as $row ) {
			$this->assertSame( $order, $row['woo:order_id'] );
			$this->assertSame( $order, $row['woo:order_number'] );
			$this->assertSame( '2021-03-04 00:06:07', $row['woo:order_date_created'], 'site time on either storage' );
			$this->assertSame( 'wc-completed', $row['woo:order_status'] );
			$this->assertSame( 'opex-items@example.test', $row['woo:billing_email'] );
		}

		$line = $rows[0];
		$this->assertSame( (string) $this->ids['line'], $line['woo:item_id'] );
		$this->assertSame( 'OPEX Item Simple', $line['woo:item_name'] );
		$this->assertSame( (string) $this->ids['simple'], $line['woo:product_id'] );
		$this->assertSame( '0', $line['woo:variation_id'] );
		$this->assertSame( 'OPEX-IT-1', $line['woo:sku'] );
		$this->assertSame( '2', $line['woo:quantity'] );
		$this->assertSame( '20.00', $line['woo:line_subtotal'] );
		$this->assertSame( '1.60', $line['woo:line_subtotal_tax'] );
		$this->assertSame( '18.50', $line['woo:line_total'] );
		$this->assertSame( '1.24', $line['woo:line_tax'], 'string math rounds 1.235 up' );

		$var = $rows[1];
		$this->assertSame( (string) $this->ids['variable'], $var['woo:product_id'] );
		$this->assertSame( (string) $this->ids['variation'], $var['woo:variation_id'] );
		$this->assertSame( 'OPEX-IT-VAR', $var['woo:sku'], 'a variation without a SKU shows its parent SKU' );
		$this->assertSame( '7.00', $var['woo:line_total'] );

		$this->assertSame( 'Flat rate', $rows[2]['woo:item_name'] );
		$this->assertSame( '', $rows[2]['woo:product_id'] );
		$this->assertSame( '4.99', $rows[2]['woo:line_total'], 'a shipping item reads its cost (ruling 3.6)' );
		$this->assertSame( '2.50', $rows[3]['woo:line_total'] );
		$this->assertSame( 'opex-items', $rows[4]['woo:item_name'] );
	}

	/** Items appear for exactly the orders the orders source exports: no drafts, no refunds. */
	public function test_drafts_and_refunds_are_left_out(): void {
		$this->assertSame( array(), $this->names( array( 'field' => 'woo:quantity', 'op' => 'not_empty', 'value' => '' ), 'draft' ) );
		$this->assertSame( array(), $this->names( array( 'field' => 'woo:quantity', 'op' => 'not_empty', 'value' => '' ), 'refund' ) );

		$orders = array_column( $this->rows( $this->config( 'woo:orders', array( 'fields' => array( 'woo:order_id' ), 'batch_size' => 500 ) ) ), 'woo:order_id' );
		$items  = array_unique( array_column( $this->rows( $this->config( 'woo:order_items', array( 'fields' => array( 'woo:order_id' ) ) ) ), 'woo:order_id' ) );
		$this->assertContains( (string) $this->ids['order'], $items );
		$this->assertSame( array(), array_values( array_diff( $items, $orders ) ), 'every item belongs to an exported order' );
		$this->assertNotContains( (string) $this->ids['draft'], $orders );
		$this->assertNotContains( (string) $this->ids['refund'], $orders );
	}

	/** Rules on item columns, item meta, the product SKU and the order. */
	public function test_filters(): void {
		$all   = array( 'OPEX Item Simple', 'OPEX Item Var - Small', 'Flat rate', 'Gift wrap', 'opex-items' );
		$cases = array(
			array( 'woo:item_type', 'in', 'shipping,fee', array( 'Flat rate', 'Gift wrap' ) ),
			array( 'woo:item_type', 'eq', 'line_item', array( 'OPEX Item Simple', 'OPEX Item Var - Small' ) ),
			array( 'woo:order_status', 'eq', 'completed', $all ),
			array( 'woo:order_status', 'in', 'wc-completed,processing', $all ),
			array( 'woo:order_status', 'neq', 'completed', array() ),
			array( 'woo:line_total', 'gt', '15', array( 'OPEX Item Simple' ) ),
			array( 'woo:sku', 'eq', 'OPEX-IT-VAR', array( 'OPEX Item Var - Small' ) ),
			array( 'woo:sku', 'starts_with', 'OPEX-IT', array( 'OPEX Item Simple', 'OPEX Item Var - Small' ) ),
			array( 'woo:billing_email', 'ends_with', '@example.test', $all ),
			array( 'woo:quantity', 'gte', '2', array( 'OPEX Item Simple' ) ),
			array( 'woo:variation_id', 'empty', '', array( 'Flat rate', 'Gift wrap', 'opex-items' ) ),
			array( 'woo:product_id', 'eq', (string) $this->ids['variable'], array( 'OPEX Item Var - Small' ) ),
			array( 'woo:order_date_created', 'eq', '2021-03-04', $all ),
			array( 'woo:order_date_created', 'gt', '2021-03-04', array() ),
			array( 'woo:item_name', 'contains', 'item s', array( 'OPEX Item Simple' ) ),
			array( 'woo:item_name', 'not_contains', 'OPEX', array( 'Flat rate', 'Gift wrap' ) ),
			array( 'woo:item_id', 'eq', (string) $this->ids['line'], array( 'OPEX Item Simple' ) ),
			array( 'woo:order_number', 'eq', (string) $this->ids['order'], $all ),
		);
		foreach ( $cases as $case ) {
			list( $field, $op, $value, $expect ) = $case;
			$this->assertSame( $expect, $this->names( array( 'field' => $field, 'op' => $op, 'value' => $value ) ), "$field $op $value" );
		}
		$nested = array( 'relation' => 'OR', 'rules' => array(
			array( 'field' => 'woo:item_type', 'op' => 'eq', 'value' => 'coupon' ),
			array( 'relation' => 'AND', 'rules' => array(
				array( 'field' => 'woo:item_type', 'op' => 'eq', 'value' => 'line_item' ),
				array( 'field' => 'woo:quantity', 'op' => 'lt', 'value' => '2' ),
			) ),
		) );
		$this->assertSame( array( 'OPEX Item Var - Small', 'opex-items' ), $this->names( $nested ) );
		$this->assertNull( OPEX_Sources::get( 'woo:order_items' )->meta_table(), 'items take no meta: fields' );
	}

	/** A date range and since on the order's created date. */
	public function test_date_range_and_since(): void {
		$only  = array( 'rules' => array( array( 'field' => 'woo:order_id', 'op' => 'eq', 'value' => (string) $this->ids['order'] ) ) );
		$range = function ( string $from, string $to ) use ( $only ): int {
			return count( $this->rows( $this->config( 'woo:order_items', array(
				'fields'     => array( 'woo:item_id' ),
				'filters'    => $only,
				'date_range' => array( 'field' => 'woo:order_date_created', 'preset' => 'custom', 'from' => $from, 'to' => $to ),
			) ) ) );
		};
		$this->assertSame( 5, $range( '2021-03-04', '2021-03-04' ) );
		$this->assertSame( 0, $range( '2021-03-05', '' ) );
		$this->assertSame( 0, $range( '', '2021-03-03' ) );

		$config = $this->config( 'woo:order_items', array( 'fields' => array( 'woo:item_id' ), 'filters' => $only ) );
		$this->assertSame( 5, (int) $this->export( $config, array( 'since' => array( 'field' => 'woo:order_date_created', 'after' => '2021-03-04 05:00:00' ) ) )['processed'] );
		$this->assertSame( 0, (int) $this->export( $config, array( 'since' => array( 'field' => 'woo:order_date_created', 'after' => '2021-03-04 06:00:00' ) ) )['processed'] );
	}

	/** Every format, transforms, custom columns and preview. */
	public function test_formats(): void {
		$this->assert_all_formats(
			'woo:order_items',
			array( 'woo:item_name', 'woo:sku', 'woo:line_tax', 'woo:order_date_created', 'woo:order_status' ),
			array( 'rules' => array( array( 'field' => 'woo:item_id', 'op' => 'eq', 'value' => (string) $this->ids['line'] ) ) )
		);
	}

	/** The picker, the capability document and recipe import. */
	public function test_surface(): void {
		$this->assert_surface( 'woo:order_items', array(
			'woo:item_id'            => 'id',
			'woo:item_type'          => 'enum',
			'woo:sku'                => 'text',
			'woo:quantity'           => 'number',
			'woo:line_total'         => 'number',
			'woo:order_date_created' => 'date',
			'woo:order_status'       => 'enum',
			'woo:billing_email'      => 'email',
		) );
	}
}
