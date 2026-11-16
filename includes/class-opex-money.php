<?php
/**
 * Money as plain decimal strings. No floats anywhere: amounts are rounded
 * half away from zero with string math (bcmath when it is loaded, a pure
 * string routine otherwise), so every database and storage engine prints the
 * same text.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Rounds and pads decimal strings.
 */
class OPEX_Money {

	/**
	 * Formats an amount with exactly `$decimals` places.
	 *
	 * Text that is not a plain decimal number comes back unchanged.
	 *
	 * @param string $value    Amount such as "82.5" or "82.50000000".
	 * @param int    $decimals Places to keep, 0 to 8.
	 * @param bool   $use_bc   Allow bcmath. Tests pass false to cover the fallback.
	 */
	public static function format( string $value, int $decimals, bool $use_bc = true ): string {
		$value = trim( $value );
		if ( 1 !== preg_match( '/^[+-]?(\d+)?(\.\d+)?$/', $value ) || '' === $value || '.' === $value || '+' === $value || '-' === $value ) {
			return $value;
		}
		$decimals = max( 0, min( 8, $decimals ) );
		$negative = '-' === $value[0];
		$value    = ltrim( $value, '+-' );
		if ( '' === $value || '.' === $value[0] ) {
			$value = '0' . $value;
		}

		$out = ( $use_bc && function_exists( 'bcadd' ) )
			? self::round_bc( $value, $decimals )
			: self::round_string( $value, $decimals );

		// Never print a negative zero.
		if ( $negative && 1 === preg_match( '/[1-9]/', $out ) ) {
			return '-' . $out;
		}
		return $out;
	}

	/**
	 * Rounds with bcmath. Half is added at the next place, then the result is cut.
	 *
	 * @param string $value    Unsigned decimal text.
	 * @param int    $decimals Places.
	 */
	private static function round_bc( string $value, int $decimals ): string {
		return bcadd( $value, '0.' . str_repeat( '0', $decimals ) . '5', $decimals );
	}

	/**
	 * Rounds with plain string digits.
	 *
	 * @param string $value    Unsigned decimal text.
	 * @param int    $decimals Places.
	 */
	private static function round_string( string $value, int $decimals ): string {
		$parts = explode( '.', $value, 2 );
		$int   = $parts[0];
		$frac  = $parts[1] ?? '';
		$next  = isset( $frac[ $decimals ] ) ? (int) $frac[ $decimals ] : 0;
		$frac  = str_pad( substr( $frac, 0, $decimals ), $decimals, '0' );
		$digits = $int . $frac;
		if ( $next >= 5 ) {
			$digits = self::increment( $digits );
		}
		$int_len = strlen( $digits ) - $decimals;
		$int     = substr( $digits, 0, $int_len );
		$frac    = substr( $digits, $int_len );
		$int     = ltrim( $int, '0' );
		$int     = '' === $int ? '0' : $int;
		return $decimals > 0 ? $int . '.' . $frac : $int;
	}

	/**
	 * Adds one to a string of digits.
	 *
	 * @param string $digits Digits only.
	 */
	private static function increment( string $digits ): string {
		for ( $i = strlen( $digits ) - 1; $i >= 0; $i-- ) {
			if ( '9' === $digits[ $i ] ) {
				$digits[ $i ] = '0';
				continue;
			}
			$digits[ $i ] = (string) ( (int) $digits[ $i ] + 1 );
			return $digits;
		}
		return '1' . $digits;
	}
}
