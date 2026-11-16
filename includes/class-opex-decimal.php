<?php
/**
 * Exact sums, comparisons and averages of decimal strings.
 *
 * Money must never pass through a float. These functions work on the digits:
 * bcmath when it is loaded, a pure string routine otherwise, and both give
 * the same text. OPEX_Money still does the final rounding and padding.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Decimal string arithmetic for money.
 */
class OPEX_Decimal {

	/**
	 * Plain decimal text for a number, or null when it is not a number.
	 *
	 * Scientific notation, which a database can print for a DOUBLE column, is
	 * turned into plain digits by moving the decimal point in the text.
	 *
	 * @param string $value Number as text.
	 * @return string|null Plain decimal text, or null when the value is not a number.
	 */
	public static function normalize( string $value ): ?string {
		$value = trim( $value );
		if ( 1 !== preg_match( '/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d{1,3}))?$/', $value, $m ) ) {
			return null;
		}
		$int  = $m[2];
		$frac = $m[3] ?? '';
		if ( '' === $int && '' === $frac ) {
			return null;
		}
		$shift = isset( $m[4] ) && '' !== $m[4] ? (int) $m[4] : 0;
		if ( $shift > 0 ) {
			$frac = str_pad( $frac, $shift, '0' );
			$int .= substr( $frac, 0, $shift );
			$frac = substr( $frac, $shift );
		} elseif ( $shift < 0 ) {
			$int  = str_pad( $int, -$shift, '0', STR_PAD_LEFT );
			$frac = substr( $int, $shift ) . $frac;
			$int  = substr( $int, 0, strlen( $int ) + $shift );
		}
		$int  = ltrim( $int, '0' );
		$int  = '' === $int ? '0' : $int;
		$frac = rtrim( $frac, '0' );
		$out  = '' === $frac ? $int : $int . '.' . $frac;
		$zero = 1 !== preg_match( '/[1-9]/', $out );
		return ( '-' === $m[1] && ! $zero ) ? '-' . $out : $out;
	}

	/**
	 * Exact sum of two plain decimal strings.
	 *
	 * @param string $a      Plain decimal.
	 * @param string $b      Plain decimal.
	 * @param bool   $use_bc Allow bcmath. Tests pass false to cover the fallback.
	 * @return string The exact sum as plain decimal text.
	 */
	public static function add( string $a, string $b, bool $use_bc = true ): string {
		$scale = max( self::places( $a ), self::places( $b ) );
		if ( $use_bc && function_exists( 'bcadd' ) ) {
			return (string) self::normalize( bcadd( $a, $b, $scale ) );
		}
		list( $neg_a, $digits_a ) = self::scaled( $a, $scale );
		list( $neg_b, $digits_b ) = self::scaled( $b, $scale );
		if ( $neg_a === $neg_b ) {
			return self::unscale( $neg_a, self::add_digits( $digits_a, $digits_b ), $scale );
		}
		$cmp = self::compare_digits( $digits_a, $digits_b );
		if ( 0 === $cmp ) {
			return '0';
		}
		return $cmp > 0
			? self::unscale( $neg_a, self::sub_digits( $digits_a, $digits_b ), $scale )
			: self::unscale( $neg_b, self::sub_digits( $digits_b, $digits_a ), $scale );
	}

	/**
	 * Compares two plain decimal strings: -1, 0 or 1.
	 *
	 * @param string $a Plain decimal.
	 * @param string $b Plain decimal.
	 * @return int -1 when a is smaller, 0 when equal, 1 when a is larger.
	 */
	public static function compare( string $a, string $b ): int {
		$negated = 0 === strpos( $b, '-' ) ? substr( $b, 1 ) : '-' . $b;
		$diff    = self::add( $a, $negated, false );
		if ( '0' === $diff ) {
			return 0;
		}
		return 0 === strpos( $diff, '-' ) ? -1 : 1;
	}

	/**
	 * A plain decimal divided by a whole number, rounded half away from zero.
	 *
	 * @param string $value    Plain decimal.
	 * @param int    $divisor  Whole number above zero.
	 * @param int    $decimals Places to keep.
	 * @param bool   $use_bc   Allow bcmath. Tests pass false to cover the fallback.
	 * @return string The quotient, rounded to the requested places.
	 */
	public static function divide( string $value, int $divisor, int $decimals, bool $use_bc = true ): string {
		$divisor  = max( 1, $divisor );
		$decimals = max( 0, min( 8, $decimals ) );
		$negative = 0 === strpos( $value, '-' );
		$plain    = ltrim( $value, '+-' );
		// One extra place, cut not rounded, is enough to round the result exactly.
		$keep = $decimals + 1;
		if ( $use_bc && function_exists( 'bcdiv' ) ) {
			$cut = bcdiv( $plain, (string) $divisor, $keep );
		} else {
			$scale            = max( self::places( $plain ), $keep );
			list( , $digits ) = self::scaled( $plain, $scale );
			$quotient         = self::div_digits( $digits, $divisor );
			$cut              = self::unscale( false, $quotient, $scale );
		}
		return OPEX_Money::format( ( $negative ? '-' : '' ) . $cut, $decimals, $use_bc );
	}

	/**
	 * Places after the decimal point.
	 *
	 * @param string $value Plain decimal.
	 * @return int Number of digits after the decimal point.
	 */
	private static function places( string $value ): int {
		$dot = strpos( $value, '.' );
		return false === $dot ? 0 : strlen( $value ) - $dot - 1;
	}

	/**
	 * Sign and digits of a plain decimal with exactly `$scale` implied places.
	 *
	 * @param string $value Plain decimal.
	 * @param int    $scale Places.
	 * @return array{0: bool, 1: string}
	 */
	private static function scaled( string $value, int $scale ): array {
		$negative = 0 === strpos( $value, '-' );
		$parts    = explode( '.', ltrim( $value, '+-' ), 2 );
		$digits   = ltrim( $parts[0] . str_pad( $parts[1] ?? '', $scale, '0' ), '0' );
		return array( $negative, '' === $digits ? '0' : $digits );
	}

	/**
	 * Plain decimal text from digits with `$scale` implied places.
	 *
	 * @param bool   $negative Sign.
	 * @param string $digits   Digits.
	 * @param int    $scale    Places.
	 * @return string Plain decimal text.
	 */
	private static function unscale( bool $negative, string $digits, int $scale ): string {
		$digits = str_pad( $digits, $scale + 1, '0', STR_PAD_LEFT );
		$int    = substr( $digits, 0, strlen( $digits ) - $scale );
		$text   = $scale > 0 ? $int . '.' . substr( $digits, -$scale ) : $int;
		return (string) self::normalize( ( $negative ? '-' : '' ) . $text );
	}

	/**
	 * Sum of two digit strings.
	 *
	 * @param string $a Digits.
	 * @param string $b Digits.
	 * @return string The sum as a digit string.
	 */
	private static function add_digits( string $a, string $b ): string {
		$len   = max( strlen( $a ), strlen( $b ) );
		$a     = str_pad( $a, $len, '0', STR_PAD_LEFT );
		$b     = str_pad( $b, $len, '0', STR_PAD_LEFT );
		$out   = '';
		$carry = 0;
		for ( $i = $len - 1; $i >= 0; $i-- ) {
			$sum   = (int) $a[ $i ] + (int) $b[ $i ] + $carry;
			$out   = ( $sum % 10 ) . $out;
			$carry = intdiv( $sum, 10 );
		}
		return $carry ? $carry . $out : $out;
	}

	/**
	 * Difference of two digit strings, the first not smaller than the second.
	 *
	 * @param string $a Digits.
	 * @param string $b Digits.
	 * @return string The difference as a digit string.
	 */
	private static function sub_digits( string $a, string $b ): string {
		$len    = strlen( $a );
		$b      = str_pad( $b, $len, '0', STR_PAD_LEFT );
		$out    = '';
		$borrow = 0;
		for ( $i = $len - 1; $i >= 0; $i-- ) {
			$diff   = (int) $a[ $i ] - (int) $b[ $i ] - $borrow;
			$borrow = $diff < 0 ? 1 : 0;
			$out    = ( $diff + 10 * $borrow ) . $out;
		}
		$out = ltrim( $out, '0' );
		return '' === $out ? '0' : $out;
	}

	/**
	 * Compares two digit strings by value.
	 *
	 * @param string $a Digits.
	 * @param string $b Digits.
	 * @return int -1 when a is smaller, 0 when equal, 1 when a is larger.
	 */
	private static function compare_digits( string $a, string $b ): int {
		$a = ltrim( $a, '0' );
		$b = ltrim( $b, '0' );
		if ( strlen( $a ) !== strlen( $b ) ) {
			return strlen( $a ) <=> strlen( $b );
		}
		return strcmp( $a, $b ) <=> 0;
	}

	/**
	 * Long division of a digit string by a whole number, cut toward zero.
	 *
	 * @param string $digits  Digits.
	 * @param int    $divisor Whole number above zero.
	 * @return string The quotient as a digit string.
	 */
	private static function div_digits( string $digits, int $divisor ): string {
		$out  = '';
		$rest = 0;
		$len  = strlen( $digits );
		for ( $i = 0; $i < $len; $i++ ) {
			$rest = $rest * 10 + (int) $digits[ $i ];
			$out .= (string) intdiv( $rest, $divisor );
			$rest = $rest % $divisor;
		}
		$out = ltrim( $out, '0' );
		return '' === $out ? '0' : $out;
	}
}
