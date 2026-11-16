<?php
/**
 * Characterization: WooCommerce orders, in either storage mode.
 *
 * The suite runs in High-Performance Order Storage mode by default. Run
 * `tests/bin/test.sh --legacy-orders` for the posts-table mode: it switches the
 * site, runs these tests and switches back. Each mode has its own golden files.
 *
 * @package Open_Export
 */

require_once __DIR__ . '/CharacterizationCase.php';

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Orders through the real engine.
 */
class OrdersTest extends CharacterizationCase {

	/**
	 * Golden name with the storage mode in it.
	 *
	 * @param string $name Base name.
	 * @return string
	 */
	private function g( string $name ): string {
		return 'orders-' . $this->storage_mode() . '-' . $name;
	}

	/**
	 * Fail early if the site is not in the mode the run asked for.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->assertTrue( OPEX_Woo::is_active(), 'WooCommerce must be active' );
		$this->assertSame( 'hpos' === $this->storage_mode(), OPEX_Woo::use_hpos(), 'site storage mode matches OPEX_ORDER_STORAGE' );
	}

	/**
	 * Orders with the core fields, in each format.
	 *
	 * @return array
	 */
	public static function formats(): array {
		return array( 'csv' => array( 'csv' ), 'json' => array( 'json' ), 'xml' => array( 'xml' ) );
	}

	/**
	 * Fields for the format tests.
	 *
	 * @return string[]
	 */
	private function fields(): array {
		return array( 'woo:order_id', 'woo:order_number', 'woo:status', 'woo:date_created', 'woo:total', 'woo:discount_total', 'woo:customer_id', 'woo:is_guest', 'woo:billing_first_name', 'woo:billing_last_name', 'woo:billing_email', 'woo:billing_country' );
	}

	/**
	 * Orders in every format, several batches.
	 *
	 * @param string $format Format.
	 */
	#[DataProvider( 'formats' )]
	public function test_orders_formats( string $format ): void {
		$res = $this->export( array( 'source' => 'woo:orders', 'fields' => $this->fields(), 'format' => $format, 'batch_size' => 10 ), 'orders' );
		$this->assertSame( 40, $res['total'] );
		$this->assert_golden( $this->g( 'formats-' . $format ), $res );
	}

	/**
	 * Every order field the catalog offers, minus the free-form meta keys.
	 */
	public function test_all_woo_fields(): void {
		$fields = array();
		foreach ( array_keys( OPEX_Catalog::field_ids( 'woo:orders' ) ) as $id ) {
			if ( 0 === strpos( $id, 'woo:' ) ) {
				$fields[] = $id;
			}
		}
		$this->assertGreaterThan( 30, count( $fields ) );
		$res = $this->export( array( 'source' => 'woo:orders', 'fields' => $fields, 'batch_size' => 15 ), 'orders-all' );
		$this->assert_golden( $this->g( 'all-woo-fields' ), $res );
	}

	/**
	 * Order meta and the first/last rows through the preview call.
	 */
	public function test_preview(): void {
		$config  = $this->config( array( 'source' => 'woo:orders', 'fields' => array( 'woo:order_id', 'woo:status', 'woo:total' ) ) );
		$preview = OPEX_Exporter::preview( $config );
		$this->assertSame( 40, $preview['total'] );
		$this->assertCount( 5, $preview['rows'] );
		$this->assertSame( array( 'order_id', 'status', 'total' ), $preview['headers'] );
		$this->assert_golden_json( $this->g( 'preview' ), $preview );
	}

	/**
	 * Operators on woo fields.
	 */
	public function test_filters(): void {
		$out   = array();
		$cases = array(
			'woo:status'              => array( 'eq' => 'completed', 'neq' => 'completed', 'contains' => 'pro', 'not_contains' => 'pro', 'starts_with' => 'c', 'ends_with' => 'd', 'gt' => 'completed', 'gte' => 'completed', 'lt' => 'completed', 'lte' => 'completed', 'in' => 'completed,failed', 'not_in' => 'completed,failed' ),
			'woo:total'               => array( 'eq' => '0', 'neq' => '0', 'contains' => '.', 'not_contains' => '.', 'starts_with' => '1', 'ends_with' => '0', 'gt' => '50', 'gte' => '50', 'lt' => '50', 'lte' => '50', 'in' => '0', 'not_in' => '0' ),
			'woo:billing_last_name'   => array( 'eq' => 'Buyer7', 'neq' => 'Buyer7', 'contains' => 'Buyer1', 'not_contains' => 'Buyer1', 'starts_with' => 'Buyer2', 'ends_with' => '5', 'gt' => 'Buyer5', 'gte' => 'Buyer5', 'lt' => 'Buyer5', 'lte' => 'Buyer5', 'in' => 'Buyer1,Buyer2', 'not_in' => 'Buyer1,Buyer2' ),
			'woo:customer_id'         => array( 'eq' => '2', 'neq' => '2', 'gt' => '5', 'gte' => '5', 'lt' => '5', 'lte' => '5', 'in' => '2,3', 'not_in' => '2,3' ),
			'woo:billing_company'     => array(),
		);
		foreach ( $cases as $field => $values ) {
			foreach ( OPEX_Query::ops() as $op ) {
				$config = $this->config( array( 'source' => 'woo:orders', 'fields' => array( 'woo:order_id' ), 'filters' => array( 'relation' => 'AND', 'rules' => array( array( 'field' => $field, 'op' => $op, 'value' => $values[ $op ] ?? '' ) ) ) ) );
				$ids    = $this->ids_for( $config );
				$this->assertSame( OPEX_Query::count( $config ), count( $ids ), "$field $op" );
				$out[ $field ][ $op ] = array( 'count' => count( $ids ), 'sha1' => sha1( implode( ',', $ids ) ) );
			}
		}
		$this->assert_golden_json( $this->g( 'filters' ), $out );
	}

	/**
	 * AND and OR across order fields.
	 */
	public function test_relations(): void {
		$rules = array(
			array( 'field' => 'woo:status', 'op' => 'eq', 'value' => 'completed' ),
			array( 'field' => 'woo:billing_last_name', 'op' => 'contains', 'value' => 'Buyer1' ),
		);
		$out = array();
		foreach ( array( 'AND', 'OR' ) as $rel ) {
			$out[ $rel ] = $this->count_for( 'woo:orders', $rules, $rel );
		}
		$this->assertLessThan( $out['OR'], $out['AND'] );
		$this->assert_golden_json( $this->g( 'relations' ), $out );
	}

	/**
	 * Date ranges on orders: orders are one per day at 12:00 UTC.
	 */
	public function test_date_ranges(): void {
		$out = array();
		foreach ( array( 'woo:date_created', 'woo:date_modified', 'woo:date_paid', 'woo:date_completed' ) as $field ) {
			foreach ( array( array( '2026-06-01', '2026-06-10' ), array( '2026-06-10', '2026-06-10' ), array( '2026-06-10', '' ), array( '', '2026-06-10' ) ) as $r ) {
				$out[ "$field {$r[0]}..{$r[1]}" ] = $this->count_for( 'woo:orders', array(), 'AND', array( 'field' => $field, 'preset' => 'custom', 'from' => $r[0], 'to' => $r[1] ) );
			}
		}
		$this->assertSame( 10, $out['woo:date_created 2026-06-01..2026-06-10'] );
		$this->assertSame( 1, $out['woo:date_created 2026-06-10..2026-06-10'], 'the end day is inclusive' );
		foreach ( array( 'today', 'last_30_days', 'this_year', 'last_year' ) as $p ) {
			$config = $this->config( array( 'source' => 'woo:orders', 'fields' => array( 'woo:order_id' ), 'date_range' => array( 'field' => 'woo:date_created', 'preset' => $p ) ) );
			$out[ 'preset ' . $p ] = OPEX_Query::count( $config );
		}
		$this->assert_golden_json( $this->g( 'date-ranges' ), $out );
	}

	/**
	 * A date column filter on orders.
	 */
	public function test_date_filter(): void {
		$out = array();
		foreach ( array( 'eq', 'gt', 'gte', 'lt', 'lte', 'neq' ) as $op ) {
			$out[ $op ] = $this->count_for( 'woo:orders', array( array( 'field' => 'woo:date_created', 'op' => $op, 'value' => '2026-06-10' ) ) );
		}
		$this->assert_golden_json( $this->g( 'date-filter' ), $out );
	}

	/**
	 * Meta fields on orders.
	 */
	public function test_order_meta(): void {
		$res = $this->export( array( 'source' => 'woo:orders', 'fields' => array( 'woo:order_id', 'meta:_billing_address_index', 'meta:_refund_amount' ), 'batch_size' => 20 ), 'orders-meta' );
		$this->assert_golden( $this->g( 'meta' ), $res );
	}
}
