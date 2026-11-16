<?php
/**
 * Two rules for orders, on whichever order storage the run uses:
 * a real checkout draft (saved by WooCommerce as "wc-checkout-draft") is left
 * out of woo:orders, woo:order_items and their picker counts, and the item
 * line amounts read the meta key each item type keeps them in.
 *
 * Fixtures are made through the WooCommerce order API in setUp and removed in
 * tearDown. The classic order storage test run covers this file too (its name
 * matches "Orders" and "Woo").
 *
 * @package Open_Export
 */

require_once __DIR__ . '/WooSourceCase.php';

/**
 * Checkout drafts and item amounts by type.
 */
class WooOrderRulingsTest extends WooSourceCase {

	/** @var int[] Order ids made by a test. */
	private $orders = array();

	/** Removes the orders. */
	protected function tearDown(): void {
		foreach ( $this->orders as $id ) {
			$order = wc_get_order( $id );
			if ( $order ) {
				$order->delete( true );
			}
		}
		$this->orders = array();
		parent::tearDown();
	}

	/**
	 * Saves an order with one line item.
	 *
	 * @param string $status Order status.
	 * @return WC_Order
	 */
	private function order( string $status ): WC_Order {
		$order = new WC_Order();
		$order->set_status( $status );
		$line = new WC_Order_Item_Product();
		$line->set_props( array( 'name' => 'OPEX ruling line', 'quantity' => 1, 'subtotal' => '20', 'total' => '18.5' ) );
		$order->add_item( $line );
		$this->orders[] = (int) $order->save();
		return $order;
	}

	/**
	 * The status text the storage holds for an order.
	 *
	 * @param int $id Order id.
	 */
	private function stored_status( int $id ): string {
		global $wpdb;
		if ( OPEX_Woo::use_hpos() ) {
			return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM `' . OPEX_Woo::table( 'orders' ) . '` WHERE id = %d', $id ) );
		}
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $id ) );
	}

	/** A real checkout draft is not an order: no row, no item rows, no count. */
	public function test_real_checkout_draft_is_left_out(): void {
		$items_source  = OPEX_Sources::get( 'woo:order_items' );
		$orders_before = (int) OPEX_Woo::source_item()['count'];
		$items_before  = (int) $items_source->picker_item()['count'];

		$draft = $this->order( 'checkout-draft' );
		$this->assertSame( 'wc-checkout-draft', $this->stored_status( $draft->get_id() ), 'WooCommerce stores the prefixed spelling' );
		$done = $this->order( 'completed' );

		$this->assertSame( $orders_before + 1, (int) OPEX_Woo::source_item()['count'], 'the orders picker counts only the completed order' );
		$this->assertSame( $items_before + 1, (int) $items_source->picker_item()['count'], 'the items picker counts only its line' );

		$ids    = array( (string) $draft->get_id(), (string) $done->get_id() );
		$only   = array( 'rules' => array( array( 'field' => 'woo:order_id', 'op' => 'in', 'value' => implode( ',', $ids ) ) ) );
		$orders = array_column( $this->rows( $this->config( 'woo:orders', array( 'fields' => array( 'woo:order_id' ), 'filters' => $only ) ) ), 'woo:order_id' );
		$items  = array_column( $this->rows( $this->config( 'woo:order_items', array( 'fields' => array( 'woo:order_id' ), 'filters' => $only ) ) ), 'woo:order_id' );
		$this->assertSame( array( (string) $done->get_id() ), $orders );
		$this->assertSame( array( (string) $done->get_id() ), $items );

		// A rule asking for drafts by name still finds none, because the base conditions come first.
		$asked = $this->rows( $this->config( 'woo:orders', array( 'fields' => array( 'woo:order_id' ), 'filters' => array( 'rules' => array( array( 'field' => 'woo:status', 'op' => 'eq', 'value' => 'checkout-draft' ) ) ) ) ) );
		$this->assertNotContains( (string) $draft->get_id(), array_column( $asked, 'woo:order_id' ) );
	}

	/** Each item type reads its own amount keys, through OPEX_Money. */
	public function test_line_amounts_by_item_type(): void {
		$order    = $this->order( 'completed' );
		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_props( array( 'method_title' => 'Ruling shipping', 'total' => '4.99' ) );
		$shipping->set_taxes( array( 'total' => array( 1 => '0.5' ) ) );
		$order->add_item( $shipping );
		$fee = new WC_Order_Item_Fee();
		$fee->set_props( array( 'name' => 'Ruling fee', 'total' => '2.5' ) );
		$fee->set_taxes( array( 'total' => array( 1 => '0.25' ) ) );
		$order->add_item( $fee );
		$coupon = new WC_Order_Item_Coupon();
		$coupon->set_props( array( 'code' => 'ruling-coupon', 'discount' => '1.5', 'discount_tax' => '0.12' ) );
		$order->add_item( $coupon );
		$tax = new WC_Order_Item_Tax();
		$tax->set_props( array( 'rate_id' => 1, 'label' => 'Ruling tax', 'tax_total' => '1.77', 'shipping_tax_total' => '0.5' ) );
		$order->add_item( $tax );
		$order->save();

		$fields = array( 'woo:item_type', 'woo:line_subtotal', 'woo:line_subtotal_tax', 'woo:line_total', 'woo:line_tax' );
		$only   = array( 'rules' => array( array( 'field' => 'woo:order_id', 'op' => 'eq', 'value' => (string) $order->get_id() ) ) );
		$rows   = array();
		foreach ( $this->rows( $this->config( 'woo:order_items', array( 'fields' => $fields, 'filters' => $only ) ) ) as $row ) {
			$rows[ $row['woo:item_type'] ] = array( $row['woo:line_subtotal'], $row['woo:line_subtotal_tax'], $row['woo:line_total'], $row['woo:line_tax'] );
		}
		$this->assertSame( array( '20.00', '0.00', '18.50', '0.00' ), $rows['line_item'] );
		$this->assertSame( array( '', '', '4.99', '0.50' ), $rows['shipping'], 'cost and total_tax' );
		$this->assertSame( array( '', '', '2.50', '0.25' ), $rows['fee'], 'a fee has no subtotal' );
		$this->assertSame( array( '', '', '1.50', '0.12' ), $rows['coupon'], 'discount_amount and discount_amount_tax' );
		$this->assertSame( array( '', '', '1.77', '0.50' ), $rows['tax'], 'tax_amount and shipping_tax_amount' );

		// Rules compare the same per-type amounts on SQLite and MariaDB.
		$names = $this->pluck(
			'woo:order_items',
			'woo:item_name',
			array( 'relation' => 'AND', 'rules' => array( $only['rules'][0], array( 'field' => 'woo:line_total', 'op' => 'gt', 'value' => '2' ) ) )
		);
		$this->assertSame( array( 'OPEX ruling line', 'Ruling shipping', 'Ruling fee' ), $names );
	}
}
