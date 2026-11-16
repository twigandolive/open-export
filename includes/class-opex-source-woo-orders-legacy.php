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

	/**
	 * {@inheritDoc}
	 *
	 * @return string The source id.
	 */
	public function id(): string {
		return OPEX_Woo::SOURCE;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string The post type.
	 */
	public function post_type(): string {
		return OPEX_Woo::LEGACY_POST_TYPE;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string[] Extra SQL conditions.
	 */
	protected function extra_where(): array {
		return array( 'p.post_status NOT IN (' . OPEX_Woo::DRAFT_STATUS_SQL . ')' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $field Canonical field id.
	 * @return string The field id that holds the value on this storage.
	 */
	public function resolve_field( string $field ): string {
		$legacy = OPEX_Woo::legacy_field( $field );
		return '' !== $legacy ? $legacy : $field;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $field Field id.
	 * @return string A coarse type, such as text, number, date or bool.
	 */
	public function field_type( string $field ): string {
		return OPEX_Woo::field_type( $field );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array[] Field groups, each with a label and its fields.
	 */
	public function field_groups(): array {
		return OPEX_Woo::fields();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string The source name.
	 */
	public function label(): string {
		return __( 'WooCommerce Orders', 'open-export' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array|null The picker entry, or null when the source has none.
	 */
	public function picker_item(): ?array {
		return OPEX_Woo::source_item();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string The post type, or '' when the source replaces none.
	 */
	public function raw_post_type(): string {
		return OPEX_Woo::LEGACY_POST_TYPE;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array> Date fields by field id, each with a label and how its time is stored.
	 */
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

	/**
	 * {@inheritDoc}
	 *
	 * @param string $field Field id of the rule.
	 * @param string $op    Filter operator.
	 * @param string $value Rule value as typed.
	 * @return string The value the rule compares.
	 */
	public function prepare_filter_value( string $field, string $op, string $value ): string {
		return OPEX_Source_Woo_Orders_Rules::filter_value( $field, $op, $value );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $field Field id.
	 * @return string|null SQL that is true when the flag is yes, or null when the field is not a flag.
	 */
	public function flag_sql( string $field ): ?string {
		global $wpdb;
		if ( 'woo:is_guest' !== $field ) {
			return null;
		}
		return "NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} gm WHERE gm.post_id = p.ID AND gm.meta_key = '_customer_user' AND gm.meta_value NOT IN ('', '0'))";
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $field Field id.
	 * @param string $value Stored text.
	 * @return string The value the field means.
	 */
	public function derive_value( string $field, string $value ): string {
		return OPEX_Source_Woo_Orders_Rules::derive( $field, $value );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $field Field id.
	 * @param string $value Raw cell text.
	 * @return string The cell text people see.
	 */
	public function format_value( string $field, string $value ): string {
		return 0 === strpos( $field, 'woo:' ) ? OPEX_Woo_Format::value( $field, $value, false ) : $value;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $field Field id.
	 * @return string[] The allowed values, or an empty array when the field is open.
	 */
	public function field_values( string $field ): array {
		return OPEX_Source_Woo_Orders_Rules::values( $field );
	}
}
