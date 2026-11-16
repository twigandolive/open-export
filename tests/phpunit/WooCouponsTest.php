<?php
/**
 * The woo:coupons source.
 *
 * Fixtures: two coupons made in setUp and deleted in tearDown.
 *
 * @package Open_Export
 */

require_once __DIR__ . '/WooSourceCase.php';

/**
 * Coupons through the real engine.
 */
class WooCouponsTest extends WooSourceCase {

	/** @var int[] Coupon ids by code. */
	private $ids = array();

	/** Makes the fixtures. */
	protected function setUp(): void {
		parent::setUp();
		$full = new WC_Coupon();
		$full->set_props( array(
			'code'                 => 'opex-full',
			'description'          => 'Spring sale',
			'discount_type'        => 'fixed_cart',
			'amount'               => '5.5',
			'date_expires'         => '2030-06-30 00:00:00',
			'usage_count'          => 3,
			'usage_limit'          => 10,
			'usage_limit_per_user' => 2,
			'individual_use'       => true,
			'free_shipping'        => false,
			'minimum_amount'       => '25',
			'maximum_amount'       => '100.125',
			'product_ids'          => array( 80, 81 ),
			'excluded_product_ids' => array( 82 ),
			'email_restrictions'   => array( 'a@example.test', 'b@example.test' ),
		) );
		$this->ids['opex-full'] = (int) $full->save();

		$plain = new WC_Coupon();
		$plain->set_props( array(
			'code'          => 'opex-plain',
			'discount_type' => 'percent',
			'amount'        => '15',
			'free_shipping' => true,
		) );
		$this->ids['opex-plain'] = (int) $plain->save();
		wp_update_post( array( 'ID' => $this->ids['opex-plain'], 'post_date' => '2019-05-05 08:00:00', 'post_date_gmt' => get_gmt_from_date( '2019-05-05 08:00:00' ) ) );
	}

	/** Deletes the fixtures. */
	protected function tearDown(): void {
		foreach ( $this->ids as $id ) {
			wp_delete_post( $id, true );
		}
		$this->ids = array();
		parent::tearDown();
	}

	/**
	 * Codes of the fixture coupons a rule keeps.
	 *
	 * @param array $rule Rule or group.
	 * @return string[]
	 */
	private function codes( array $rule ): array {
		$only = array( 'field' => 'woo:code', 'op' => 'starts_with', 'value' => 'opex-' );
		return $this->pluck( 'woo:coupons', 'woo:code', array( 'relation' => 'AND', 'rules' => array( $only, $rule ) ) );
	}

	/** Every field, with money, dates, flags and lists in one format. */
	public function test_rows_hold_every_field(): void {
		$fields = array_values( array_filter( array_keys( OPEX_Catalog::field_ids( 'woo:coupons' ) ), static function ( $id ) {
			return 0 === strpos( $id, 'woo:' );
		} ) );
		$this->assertCount( 18, $fields );
		$rows = $this->rows( $this->config( 'woo:coupons', array(
			'fields'  => $fields,
			'filters' => array( 'rules' => array( array( 'field' => 'woo:code', 'op' => 'starts_with', 'value' => 'opex-' ) ) ),
		) ) );
		$this->assertCount( 2, $rows );
		$full = $rows[0];
		$this->assertSame( (string) $this->ids['opex-full'], $full['woo:id'] );
		$this->assertSame( 'opex-full', $full['woo:code'] );
		$this->assertSame( 'Spring sale', $full['woo:description'] );
		$this->assertSame( 'fixed_cart', $full['woo:discount_type'] );
		$this->assertSame( '5.50', $full['woo:amount'] );
		$this->assertSame( '2030-06-30 00:00:00', $full['woo:date_expires'] );
		$this->assertSame( '3', $full['woo:usage_count'] );
		$this->assertSame( '10', $full['woo:usage_limit'] );
		$this->assertSame( '2', $full['woo:usage_limit_per_user'] );
		$this->assertSame( 'yes', $full['woo:individual_use'] );
		$this->assertSame( 'no', $full['woo:free_shipping'] );
		$this->assertSame( '25.00', $full['woo:minimum_amount'] );
		$this->assertSame( '100.13', $full['woo:maximum_amount'] );
		$this->assertSame( '80, 81', $full['woo:product_ids'] );
		$this->assertSame( '82', $full['woo:excluded_product_ids'] );
		$this->assertSame( 'a@example.test, b@example.test', $full['woo:email_restrictions'] );
		$this->assertSame( 'publish', $full['woo:status'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $full['woo:date_created'] );

		$plain = $rows[1];
		$this->assertSame( '15.00', $plain['woo:amount'] );
		$this->assertSame( '', $plain['woo:date_expires'] );
		$this->assertSame( 'yes', $plain['woo:free_shipping'] );
		$this->assertSame( '', $plain['woo:minimum_amount'] );
		$this->assertSame( '', $plain['woo:product_ids'] );
		$this->assertSame( '', $plain['woo:email_restrictions'] );
		$this->assertSame( '2019-05-05 08:00:00', $plain['woo:date_created'] );
	}

	/** Rules on text, numbers, flags, lists and the expiry timestamp. */
	public function test_filters(): void {
		$cases = array(
			array( 'woo:discount_type', 'eq', 'percent', array( 'opex-plain' ) ),
			array( 'woo:discount_type', 'not_in', 'percent', array( 'opex-full' ) ),
			array( 'woo:amount', 'gt', '10', array( 'opex-plain' ) ),
			array( 'woo:amount', 'lte', '5.5', array( 'opex-full' ) ),
			array( 'woo:individual_use', 'eq', 'yes', array( 'opex-full' ) ),
			array( 'woo:free_shipping', 'in', 'yes', array( 'opex-plain' ) ),
			array( 'woo:free_shipping', 'neq', 'yes', array( 'opex-full' ) ),
			array( 'woo:email_restrictions', 'contains', 'b@example', array( 'opex-full' ) ),
			array( 'woo:email_restrictions', 'empty', '', array( 'opex-plain' ) ),
			array( 'woo:date_expires', 'gte', '2030-06-30', array( 'opex-full' ) ),
			array( 'woo:date_expires', 'eq', '2030-06-30', array( 'opex-full' ) ),
			array( 'woo:date_expires', 'lt', '2030-06-30', array() ),
			array( 'woo:date_expires', 'not_empty', '', array( 'opex-full' ) ),
			array( 'woo:date_created', 'lt', '2020-01-01', array( 'opex-plain' ) ),
			array( 'woo:usage_limit', 'gte', '10', array( 'opex-full' ) ),
			array( 'woo:description', 'starts_with', 'Spring', array( 'opex-full' ) ),
			array( 'woo:id', 'eq', (string) $this->ids['opex-plain'], array( 'opex-plain' ) ),
			array( 'woo:status', 'eq', 'publish', array( 'opex-full', 'opex-plain' ) ),
		);
		foreach ( $cases as $case ) {
			list( $field, $op, $value, $expect ) = $case;
			$this->assertSame( $expect, $this->codes( array( 'field' => $field, 'op' => $op, 'value' => $value ) ), "$field $op $value" );
		}
		$nested = array( 'relation' => 'OR', 'rules' => array(
			array( 'field' => 'woo:amount', 'op' => 'gt', 'value' => '100' ),
			array( 'relation' => 'AND', 'rules' => array(
				array( 'field' => 'woo:individual_use', 'op' => 'eq', 'value' => 'yes' ),
				array( 'field' => 'woo:minimum_amount', 'op' => 'gte', 'value' => '25' ),
			) ),
		) );
		$this->assertSame( array( 'opex-full' ), $this->codes( $nested ) );
	}

	/** Date ranges on the created column and the expiry timestamp, and since. */
	public function test_date_range_and_since(): void {
		$only  = array( 'rules' => array( array( 'field' => 'woo:code', 'op' => 'starts_with', 'value' => 'opex-' ) ) );
		$range = function ( string $field, string $from, string $to ) use ( $only ): array {
			return array_column( $this->rows( $this->config( 'woo:coupons', array(
				'fields'     => array( 'woo:code' ),
				'filters'    => $only,
				'date_range' => array( 'field' => $field, 'preset' => 'custom', 'from' => $from, 'to' => $to ),
			) ) ), 'woo:code' );
		};
		$this->assertSame( array( 'opex-plain' ), $range( 'woo:date_created', '2019-05-01', '2019-05-31' ) );
		$this->assertSame( array( 'opex-full' ), $range( 'woo:date_expires', '2030-06-30', '2030-06-30' ) );
		$this->assertSame( array(), $range( 'woo:date_expires', '2030-07-01', '' ) );

		$job = $this->export( $this->config( 'woo:coupons', array( 'fields' => array( 'woo:code' ), 'filters' => $only ) ), array( 'since' => array( 'field' => 'woo:date_created', 'after' => '2020-01-01 00:00:00' ) ) );
		$this->assertSame( 1, (int) $job['processed'] );
	}

	/** Every format, transforms, custom columns and preview. */
	public function test_formats(): void {
		$this->assert_all_formats(
			'woo:coupons',
			array( 'woo:code', 'woo:amount', 'woo:date_expires', 'woo:email_restrictions', 'woo:individual_use' ),
			array( 'rules' => array( array( 'field' => 'woo:code', 'op' => 'eq', 'value' => 'opex-full' ) ) )
		);
	}

	/** The picker, the capability document and recipe import. */
	public function test_surface(): void {
		$this->assert_surface( 'woo:coupons', array(
			'woo:code'             => 'text',
			'woo:discount_type'    => 'enum',
			'woo:amount'           => 'number',
			'woo:date_expires'     => 'date',
			'woo:individual_use'   => 'boolean',
			'woo:usage_limit'      => 'number',
			'woo:id'               => 'id',
		) );
	}
}
