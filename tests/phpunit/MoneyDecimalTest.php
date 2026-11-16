<?php
/**
 * OPEX_Decimal: exact sums, comparisons and averages, on bcmath and on the
 * pure string fallback, which must agree digit for digit.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class MoneyDecimalTest extends TestCase {

	/** Plain text, scientific notation and junk. */
	public function test_normalize(): void {
		$cases = array(
			'19.99'       => '19.99',
			'0019.9900'   => '19.99',
			'-0.000'      => '0',
			'+5'          => '5',
			'.5'          => '0.5',
			'5.'          => '5',
			'1.5e3'       => '1500',
			'1.5E-3'      => '0.0015',
			'123e-1'      => '12.3',
			'-2.5e-7'     => '-0.00000025',
			'1e20'        => '100000000000000000000',
			''            => null,
			'.'           => null,
			'abc'         => null,
			'1,5'         => null,
			'1e'          => null,
		);
		foreach ( $cases as $in => $out ) {
			$this->assertSame( $out, OPEX_Decimal::normalize( (string) $in ), (string) $in );
		}
	}

	/** Sums with mixed signs and places agree on both paths. */
	public function test_add(): void {
		$cases = array(
			array( '19.99', '0.01', '20' ),
			array( '0.1', '0.2', '0.3' ),
			array( '15.03', '-5', '10.03' ),
			array( '-5', '4.99', '-0.01' ),
			array( '-1.5', '-2.25', '-3.75' ),
			array( '5', '-5', '0' ),
			array( '0', '0', '0' ),
			array( '99999999999999999999.99', '0.01', '100000000000000000000' ),
			array( '0.30000000000000004', '-0.3', '0.00000000000000004' ),
		);
		foreach ( $cases as $case ) {
			$this->assertSame( $case[2], OPEX_Decimal::add( $case[0], $case[1] ), "bc {$case[0]} + {$case[1]}" );
			$this->assertSame( $case[2], OPEX_Decimal::add( $case[0], $case[1], false ), "string {$case[0]} + {$case[1]}" );
		}
	}

	/** Order of decimal strings by value. */
	public function test_compare(): void {
		$this->assertSame( -1, OPEX_Decimal::compare( '9.995', '20' ) );
		$this->assertSame( 1, OPEX_Decimal::compare( '10', '9.9999' ) );
		$this->assertSame( 0, OPEX_Decimal::compare( '1.50', '1.5' ) );
		$this->assertSame( -1, OPEX_Decimal::compare( '-3', '2' ) );
		$this->assertSame( 1, OPEX_Decimal::compare( '-0.01', '-0.02' ) );
	}

	/** Averages round half away from zero at the requested places. */
	public function test_divide(): void {
		$cases = array(
			array( '15.02', 3, 2, '5.01' ),
			array( '10', 4, 2, '2.50' ),
			array( '10', 3, 2, '3.33' ),
			array( '0.05', 2, 2, '0.03' ),
			array( '-0.05', 2, 2, '-0.03' ),
			array( '-0.004', 1, 2, '0.00' ),
			array( '33.335', 1, 2, '33.34' ),
			array( '1', 3, 0, '0' ),
			array( '2', 3, 0, '1' ),
			array( '100000000000000000000.01', 1, 1, '100000000000000000000.0' ),
		);
		foreach ( $cases as $case ) {
			$this->assertSame( $case[3], OPEX_Decimal::divide( $case[0], $case[1], $case[2] ), "bc {$case[0]} / {$case[1]}" );
			$this->assertSame( $case[3], OPEX_Decimal::divide( $case[0], $case[1], $case[2], false ), "string {$case[0]} / {$case[1]}" );
		}
	}
}
