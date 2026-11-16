<?php
/**
 * Codex round 1 fixes in the WooCommerce sources: R1-3.2 (coupon amounts) and
 * R1-3.3 (customer totals cannot be filtered).
 *
 * @package Open_Export
 */

require_once __DIR__ . '/WooSourceCase.php';

class CodexSourceWooTest extends WooSourceCase {

	/** @var int[] Coupon ids. */
	private $coupons = array();

	/** @var mixed Price decimals before the test. */
	private $decimals = null;

	protected function setUp(): void {
		parent::setUp();
		$this->decimals = get_option( 'woocommerce_price_num_decimals' );
	}

	protected function tearDown(): void {
		foreach ( $this->coupons as $id ) {
			wp_delete_post( $id, true );
		}
		$this->coupons = array();
		update_option( 'woocommerce_price_num_decimals', $this->decimals );
		parent::tearDown();
	}

	/** A coupon with this code, type and amount. */
	private function coupon( string $code, string $type, string $amount ): void {
		$coupon = new WC_Coupon();
		$coupon->set_props(
			array(
				'code'          => $code,
				'discount_type' => $type,
				'amount'        => $amount,
			)
		);
		$this->coupons[] = (int) $coupon->save();
	}

	/** Amounts of the test coupons, by code. */
	private function amounts(): array {
		$rows = $this->rows( $this->config( 'woo:coupons', array(
			'fields'  => array( 'woo:code', 'woo:amount' ),
			'filters' => array( 'rules' => array( array( 'field' => 'woo:code', 'op' => 'starts_with', 'value' => 'opexb1-' ) ) ),
		) ) );
		return array_column( $rows, 'woo:amount', 'woo:code' );
	}

	/** R1-3.2: a coupon amount keeps its stored decimals, whatever the price decimals. */
	public function test_coupon_amount_is_never_rounded(): void {
		$this->coupon( 'opexb1-pct', 'percent', '12.5' );
		$this->coupon( 'opexb1-fixed', 'fixed_cart', '5' );
		$this->coupon( 'opexb1-fine', 'fixed_cart', '100.125' );

		update_option( 'woocommerce_price_num_decimals', '0' );
		$this->assertSame(
			array(
				'opexb1-fine'  => '100.125',
				'opexb1-fixed' => '5',
				'opexb1-pct'   => '12.5',
			),
			$this->sorted( $this->amounts() ),
			'no price decimals: 12.5 percent stays 12.5'
		);

		update_option( 'woocommerce_price_num_decimals', '2' );
		$this->assertSame(
			array(
				'opexb1-fine'  => '100.125',
				'opexb1-fixed' => '5.00',
				'opexb1-pct'   => '12.50',
			),
			$this->sorted( $this->amounts() ),
			'two price decimals: padded as before, never cut'
		);
	}

	/** Sorted by key. */
	private function sorted( array $map ): array {
		ksort( $map );
		return $map;
	}

	/** R1-3.3: rules on total spend and average order value are refused, and /fields says so. */
	public function test_customer_totals_are_not_filterable(): void {
		$src = OPEX_Query::parse_source( 'woo:customers' );
		$this->assertIsArray( $src );
		foreach ( array( 'woo:total_spend', 'woo:avg_order_value' ) as $field ) {
			$this->assertTrue( OPEX_Query::is_valid_field( $src, $field ), "$field still exports" );
			$this->assertFalse( OPEX_Query::is_filterable_field( $src, $field ), "$field cannot be filtered" );
			$notes  = array();
			$config = OPEX_Exporter::sanitize_config(
				array(
					'source'  => 'woo:customers',
					'fields'  => array( 'woo:email', $field ),
					'filters' => array( 'rules' => array( array( 'field' => $field, 'op' => 'gte', 'value' => '15' ) ) ),
				),
				$notes
			);
			$this->assertWPErrorCode( $config, 'opex_bad_filter', $field );
		}
		$this->assertTrue( OPEX_Query::is_filterable_field( $src, 'woo:order_count' ), 'the order count still filters' );

		$flags = array();
		foreach ( OPEX_Field_Types::decorate( 'woo:customers', OPEX_Catalog::fields( 'woo:customers' ) ) as $group ) {
			foreach ( $group['fields'] as $field ) {
				$flags[ $field['id'] ] = $field['filterable'];
			}
		}
		$this->assertFalse( $flags['woo:total_spend'] );
		$this->assertFalse( $flags['woo:avg_order_value'] );
		$this->assertTrue( $flags['woo:order_count'] );
	}

	/** Fails unless the value is a WP_Error with this code. */
	private function assertWPErrorCode( $value, string $code, string $message ): void {
		$this->assertInstanceOf( WP_Error::class, $value, $message );
		$this->assertSame( $code, $value->get_error_code(), $message );
	}
}
