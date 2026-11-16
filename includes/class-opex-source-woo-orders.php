<?php
/**
 * Provider for WooCommerce orders on High-Performance Order Storage.
 *
 * The code that knows the tables, the field map and the formats stays in
 * OPEX_Woo and OPEX_Woo_Format. This class only answers the engine's questions
 * with it, so the engine no longer names the order source.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Orders read from the custom order tables.
 */
class OPEX_Source_Woo_Orders extends OPEX_Source_Table {

	/**
	 * Picks the provider for the store's current order storage.
	 * Null when WooCommerce is not active.
	 */
	public static function resolve(): ?OPEX_Source_Provider {
		if ( ! OPEX_Woo::is_active() ) {
			return null;
		}
		return OPEX_Woo::use_hpos() ? OPEX_Sources::instance( __CLASS__ ) : OPEX_Sources::instance( 'OPEX_Source_Woo_Orders_Legacy' );
	}

	/** {@inheritDoc} */
	public function id(): string {
		return OPEX_Woo::SOURCE;
	}

	/** {@inheritDoc} */
	public function src_type(): string {
		return 'woo_orders';
	}

	/** {@inheritDoc} */
	public function from(): string {
		return OPEX_Woo::from_clause();
	}

	/** {@inheritDoc} */
	public function base_where(): array {
		// Refunds share the orders table. Excluding them keeps one row
		// per order and stops refund rows corrupting reported totals.
		// Auto-drafts and checkout drafts are carts, not orders.
		return array( array( "o.type = 'shop_order'", 'o.status NOT IN (' . OPEX_Woo::DRAFT_STATUS_SQL . ')' ), array() );
	}

	/** {@inheritDoc} */
	public function primary_key(): string {
		return 'o.id';
	}

	/** {@inheritDoc} */
	public function id_prop(): string {
		return 'id';
	}

	/** {@inheritDoc} */
	public function select_list( array $fields ): string {
		return OPEX_Woo::select_list();
	}

	/** {@inheritDoc} */
	public function meta_table(): ?array {
		return array( OPEX_Woo::table( 'meta' ), 'order_id', 'o.id' );
	}

	/** {@inheritDoc} */
	public function has_field( string $field ): bool {
		return OPEX_Woo::is_woo_field( $field );
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
		return OPEX_Woo::hpos_date_fields();
	}

	/** {@inheritDoc} */
	public function filter_expr( string $field ): ?string {
		return OPEX_Woo::is_woo_field( $field ) ? OPEX_Woo::field_map()[ $field ]['expr'] : null;
	}

	/** {@inheritDoc} */
	public function prepare_filter_value( string $field, string $op, string $value ): string {
		return OPEX_Source_Woo_Orders_Rules::filter_value( $field, $op, $value );
	}

	/** {@inheritDoc} */
	public function flag_sql( string $field ): ?string {
		return 'woo:is_guest' === $field ? '(o.customer_id = 0 OR o.customer_id IS NULL)' : null;
	}

	/** {@inheritDoc} */
	public function row_alias( string $field ): ?string {
		return OPEX_Woo::is_woo_field( $field ) ? OPEX_Woo::field_map()[ $field ]['alias'] : null;
	}

	/** {@inheritDoc} */
	public function derive_value( string $field, string $value ): string {
		return OPEX_Source_Woo_Orders_Rules::derive( $field, $value );
	}

	/** {@inheritDoc} */
	public function format_value( string $field, string $value ): string {
		return 0 === strpos( $field, 'woo:' ) ? OPEX_Woo_Format::value( $field, $value, true ) : $value;
	}

	/** {@inheritDoc} */
	public function field_values( string $field ): array {
		return OPEX_Source_Woo_Orders_Rules::values( $field );
	}
}
