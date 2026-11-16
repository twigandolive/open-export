<?php
/**
 * Field types and stable group ids for the picker.
 *
 * The screen needs to know what a field holds (to offer the right operators
 * and transforms) and needs a group id that stays the same in every language.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Adds `value_type` to each field and `id` to each group of a field list.
 */
class OPEX_Field_Types {

	/** The types the screen knows. */
	const TYPES = array( 'text', 'number', 'date', 'bool', 'json' );

	/**
	 * Adds a stable id to every group and a screen type (`value_type`) to
	 * every field. A field that already carried `type` keeps it unchanged.
	 *
	 * @param string $source_id Source id.
	 * @param array  $groups    Groups from OPEX_Catalog::fields().
	 * @return array
	 */
	public static function decorate( string $source_id, array $groups ): array {
		$src = OPEX_Query::parse_source( $source_id );
		if ( ! $src ) {
			return $groups;
		}
		$dates = OPEX_Query::date_fields( $src );
		$used  = array();
		$out   = array();
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$id = self::group_id( (string) ( $group['label'] ?? '' ) );
			$n  = 2;
			$base = $id;
			while ( isset( $used[ $id ] ) ) {
				$id = $base . '-' . $n;
				$n++;
			}
			$used[ $id ] = true;

			$fields = array();
			foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
				if ( ! is_array( $field ) || ! isset( $field['id'] ) ) {
					continue;
				}
				// `type` shipped in 1.3.1 on the fields that carry it, so it stays as it is.
				$known               = (string) ( $field['type'] ?? '' );
				$field['value_type'] = isset( $dates[ (string) $field['id'] ] ) ? 'date' : self::ui_type( $src, (string) $field['id'], $known );
				$fields[]            = $field;
			}
			$out[] = array_merge( array( 'id' => $id ), $group, array( 'fields' => $fields ) );
		}
		return $out;
	}

	/**
	 * The screen type of one field.
	 *
	 * @param array  $src   Parsed source.
	 * @param string $field Field id.
	 * @param string $known Capability document type when the source already knows it.
	 */
	public static function ui_type( array $src, string $field, string $known = '' ): string {
		$type = '' !== $known ? $known : OPEX_Schema::field_type( $src, $field );
		switch ( $type ) {
			case 'date':
				return 'date';
			case 'number':
			case 'id':
				return 'number';
			case 'boolean':
				return 'bool';
			case 'json':
				return 'json';
		}
		return 'text';
	}

	/**
	 * A group id that does not change with the language. Known labels map to a
	 * fixed id. Labels that are never translated (plugin names such as Yoast
	 * SEO) become a slug of themselves.
	 *
	 * @param string $label Group label as shown.
	 */
	public static function group_id( string $label ): string {
		$id = array_search( $label, self::known_labels(), true );
		if ( false !== $id ) {
			return (string) $id;
		}
		$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $label ) ), '-' );
		return '' !== $slug ? $slug : 'group';
	}

	/**
	 * Every translated group label the plugin uses, by stable id. A new group
	 * label needs a line here (FieldTypesTest checks that none is missing).
	 *
	 * @return array<string, string>
	 */
	public static function known_labels(): array {
		return array(
			'core'                    => __( 'Core Fields', 'open-export' ),
			'computed'                => __( 'Computed', 'open-export' ),
			'taxonomies'              => __( 'Taxonomies', 'open-export' ),
			'columns'                 => __( 'Columns', 'open-export' ),
			'custom-fields'           => __( 'Custom Fields', 'open-export' ),
			'technical'               => __( 'Technical / Hidden', 'open-export' ),
			'order'                   => __( 'Order', 'open-export' ),
			'customer'                => __( 'Customer', 'open-export' ),
			'totals'                  => __( 'Totals', 'open-export' ),
			'payment'                 => __( 'Payment', 'open-export' ),
			'billing-address'         => __( 'Billing address', 'open-export' ),
			'shipping-address'        => __( 'Shipping address', 'open-export' ),
			'fulfilment'              => __( 'Fulfilment', 'open-export' ),
			'order-meta'              => __( 'Order meta', 'open-export' ),
			'item'                    => __( 'Item', 'open-export' ),
			'coupon'                  => __( 'Coupon', 'open-export' ),
			'usage-limits'            => __( 'Usage limits', 'open-export' ),
			'restrictions'            => __( 'Restrictions', 'open-export' ),
			'location'                => __( 'Location', 'open-export' ),
			'orders'                  => __( 'Orders', 'open-export' ),
			'dates'                   => __( 'Dates', 'open-export' ),
			'product'                 => __( 'Product', 'open-export' ),
			'price'                   => __( 'Price', 'open-export' ),
			'inventory'               => __( 'Inventory', 'open-export' ),
			'shipping'                => __( 'Shipping', 'open-export' ),
			'description'             => __( 'Description', 'open-export' ),
			'categories-attributes'   => __( 'Categories and attributes', 'open-export' ),
			'images-links'            => __( 'Images and links', 'open-export' ),
		);
	}
}
