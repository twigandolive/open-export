<?php
/**
 * Order rules shared by both order providers (custom tables and classic posts).
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Status wording, the guest flag and status values for orders.
 */
class OPEX_Source_Woo_Orders_Rules {

	/**
	 * Lets an order status filter name a status with or without the "wc-" prefix.
	 * Only the exact-match operators change: "contains" still searches the stored text.
	 *
	 * @param string $field Field id of the rule.
	 * @param string $op    Operator.
	 * @param string $value Rule value.
	 * @return string The value the rule compares.
	 */
	public static function filter_value( string $field, string $op, string $value ): string {
		if ( 'woo:status' !== $field ) {
			return $value;
		}
		if ( ! in_array( $op, array( 'eq', 'neq', 'in', 'not_in' ), true ) ) {
			return $value;
		}
		$plain = array( 'trash', 'auto-draft', 'draft', 'publish', 'private', 'checkout-draft' );
		$items = in_array( $op, array( 'in', 'not_in' ), true ) ? explode( ',', $value ) : array( $value );
		$out   = array();
		foreach ( $items as $item ) {
			$item  = trim( $item );
			$out[] = ( '' === $item || 0 === strpos( $item, 'wc-' ) || in_array( $item, $plain, true ) ) ? $item : 'wc-' . $item;
		}
		return implode( ',', $out );
	}

	/**
	 * WooCommerce writes customer id 0 (or nothing) for a guest checkout.
	 *
	 * @param string $field Field id.
	 * @param string $value Stored text.
	 * @return string yes or no for the guest flag, else the stored text.
	 */
	public static function derive( string $field, string $value ): string {
		if ( 'woo:is_guest' !== $field ) {
			return $value;
		}
		$value = trim( $value );
		return ( '' === $value || '0' === $value ) ? 'yes' : 'no';
	}

	/**
	 * Legal values of the status field, on either storage engine.
	 *
	 * @param string $field Field id.
	 * @return string[] The status ids, or an empty array for any other field.
	 */
	public static function values( string $field ): array {
		if ( ( 'woo:status' === $field || 'core:post_status' === $field ) && function_exists( 'wc_get_order_statuses' ) ) {
			return array_keys( wc_get_order_statuses() );
		}
		return array();
	}
}
