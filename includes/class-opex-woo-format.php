<?php
/**
 * One output format for WooCommerce order values, whatever the storage.
 *
 * High-performance order storage keeps money as long decimals, dates in UTC and
 * flags as 0 or 1. Classic storage keeps money as text, dates in site time or as
 * UNIX timestamps, and flags as "yes" or "no". This class turns both into the
 * same text, so an export looks the same on either store and on any database:
 * money as a plain decimal string with the store's price decimals, dates as
 * site-time `Y-m-d H:i:s`, flags as yes or no.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Formats money, date and flag fields of an order.
 */
class OPEX_Woo_Format {

	/** Fields that hold an amount of money. */
	const MONEY = array( 'woo:total', 'woo:tax_total', 'woo:shipping_total', 'woo:shipping_tax', 'woo:discount_total', 'woo:discount_tax' );

	/** Fields that hold a date. Classic storage keeps two as site time and two as UNIX timestamps. */
	const DATES = array( 'woo:date_created', 'woo:date_modified', 'woo:date_paid', 'woo:date_completed' );

	/** Fields that hold a yes or no flag. */
	const FLAGS = array( 'woo:prices_include_tax', 'woo:stock_reduced' );

	/**
	 * Formats one value.
	 *
	 * @param string $field Canonical field id, such as `woo:total`.
	 * @param string $value Value as read from the database.
	 * @param bool   $hpos  True when it came from the custom order tables.
	 * @return string The text people see.
	 */
	public static function value( string $field, string $value, bool $hpos ): string {
		if ( in_array( $field, self::MONEY, true ) ) {
			return OPEX_Money::format( $value, self::price_decimals() );
		}
		if ( in_array( $field, self::DATES, true ) ) {
			return self::site_date( $value, $hpos );
		}
		if ( in_array( $field, self::FLAGS, true ) ) {
			return self::flag( $value );
		}
		return $value;
	}

	/**
	 * Decimals the store shows for prices.
	 *
	 * @return int Number of decimals.
	 */
	public static function price_decimals(): int {
		return function_exists( 'wc_get_price_decimals' ) ? max( 0, (int) wc_get_price_decimals() ) : 2;
	}

	/**
	 * Turns a stored flag into yes or no. Empty means no.
	 *
	 * @param string $value Stored text.
	 * @return string yes or no.
	 */
	public static function flag( string $value ): string {
		return in_array( strtolower( trim( $value ) ), array( '1', 'yes', 'true', 'on' ), true ) ? 'yes' : 'no';
	}

	/**
	 * Turns a stored date into site-time text. The other WooCommerce sources
	 * share it, so every Woo date prints the same way.
	 *
	 * @param string $value  Stored text or UNIX timestamp.
	 * @param bool   $is_utc True when a datetime string is in UTC.
	 * @return string The date in site time, or the stored text when it is not a date.
	 */
	public static function site_date( string $value, bool $is_utc ): string {
		$value = trim( $value );
		if ( '' === $value || '0' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
			return '';
		}
		$site = OPEX_Dates::site_timezone();
		try {
			if ( 1 === preg_match( '/^\d{9,}$/', $value ) ) {
				return ( new DateTimeImmutable( '@' . $value ) )->setTimezone( $site )->format( 'Y-m-d H:i:s' );
			}
			if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $value ) ) {
				return $value;
			}
			$moment = new DateTimeImmutable( $value, $is_utc ? new DateTimeZone( 'UTC' ) : $site );
			return $moment->setTimezone( $site )->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			unset( $e );
			return $value;
		}
	}
}
