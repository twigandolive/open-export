<?php
/**
 * Provider for WooCommerce orders kept in wp_posts (classic storage).
 *
 * Orders are shop_order posts, so every generic post path applies. A friendly
 * woo: field id resolves to the post column or meta key that holds it.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Orders read from wp_posts and postmeta.
 */
class OPEX_Source_Woo_Orders_Legacy extends OPEX_Source_Posts {

	/** {@inheritDoc} */
	public function id(): string {
		return OPEX_Woo::SOURCE;
	}

	/** {@inheritDoc} */
	public function post_type(): string {
		return OPEX_Woo::LEGACY_POST_TYPE;
	}

	/** {@inheritDoc} */
	protected function extra_where(): array {
		return array( 'p.post_status NOT IN (' . OPEX_Woo::DRAFT_STATUS_SQL . ')' );
	}

	/** {@inheritDoc} */
	public function resolve_field( string $field ): string {
		$legacy = OPEX_Woo::legacy_field( $field );
		return '' !== $legacy ? $legacy : $field;
	}

	/** {@inheritDoc} */
	public function field_type( string $field ): string {
		return OPEX_Woo::field_type( $field );
	}

	/** {@inheritDoc} */
	public function field_groups(): array {
		return OPEX_Woo::fields();
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'WooCommerce Orders', 'open-export' );
	}

	/** {@inheritDoc} */
	public function picker_item(): ?array {
		return OPEX_Woo::source_item();
	}

	/** {@inheritDoc} */
	public function raw_post_type(): string {
		return OPEX_Woo::LEGACY_POST_TYPE;
	}

	/** {@inheritDoc} */
	public function date_fields(): array {
		// Orders name their dates by what they mean, plus the raw
		// post columns for anyone who wants them.
		return OPEX_Woo::legacy_date_fields() + array(
			'core:post_date'     => array(
				'label' => __( 'Order date (site time)', 'open-export' ),
				'tz'    => OPEX_Dates::TZ_LOCAL,
				'sql'   => 'p.post_date',
			),
			'core:post_date_gmt' => array(
				'label' => __( 'Order date (UTC)', 'open-export' ),
				'tz'    => OPEX_Dates::TZ_UTC,
				'sql'   => 'p.post_date_gmt',
			),
		);
	}

	/** {@inheritDoc} */
	public function prepare_filter_value( string $field, string $op, string $value ): string {
		return OPEX_Source_Woo_Orders_Rules::filter_value( $field, $op, $value );
	}

	/** {@inheritDoc} */
	public function flag_sql( string $field ): ?string {
		global $wpdb;
		if ( 'woo:is_guest' !== $field ) {
			return null;
		}
		return "NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} gm WHERE gm.post_id = p.ID AND gm.meta_key = '_customer_user' AND gm.meta_value NOT IN ('', '0'))";
	}

	/** {@inheritDoc} */
	public function derive_value( string $field, string $value ): string {
		return OPEX_Source_Woo_Orders_Rules::derive( $field, $value );
	}

	/** {@inheritDoc} */
	public function format_value( string $field, string $value ): string {
		return 0 === strpos( $field, 'woo:' ) ? OPEX_Woo_Format::value( $field, $value, false ) : $value;
	}

	/** {@inheritDoc} */
	public function field_values( string $field ): array {
		return OPEX_Source_Woo_Orders_Rules::values( $field );
	}
}
