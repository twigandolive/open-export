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
	 *
	 * @return OPEX_Source_Provider|null The provider for the current order storage, or null when WooCommerce is not active.
	 */
	public static function resolve(): ?OPEX_Source_Provider {
		if ( ! OPEX_Woo::is_active() ) {
			return null;
		}
		return OPEX_Woo::use_hpos() ? OPEX_Sources::instance( __CLASS__ ) : OPEX_Sources::instance( 'OPEX_Source_Woo_Orders_Legacy' );
	}

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
	 * @return string The source type.
	 */
	public function src_type(): string {
		return 'woo_orders';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string The FROM clause.
	 */
	public function from(): string {
		return OPEX_Woo::from_clause();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array The base WHERE conditions and the values for their placeholders.
	 */
	public function base_where(): array {
		// Refunds share the orders table. Excluding them keeps one row
		// per order and stops refund rows corrupting reported totals.
		// Auto-drafts and checkout drafts are carts, not orders.
		return array( array( "o.type = 'shop_order'", 'o.status NOT IN (' . OPEX_Woo::DRAFT_STATUS_SQL . ')' ), array() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string The key column for cursor paging.
	 */
	public function primary_key(): string {
		return 'o.id';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string The key property name on a fetched row.
	 */
	public function id_prop(): string {
		return 'id';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string[] $fields Field ids the export asked for.
	 * @return string The SELECT list.
	 */
	public function select_list( array $fields ): string {
		return OPEX_Woo::select_list();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array|null The meta table, its foreign key and the outer id, or null when the source has no meta.
	 */
	public function meta_table(): ?array {
		return array( OPEX_Woo::table( 'meta' ), 'order_id', 'o.id' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $field Field id.
	 * @return bool True when the source offers the field.
	 */
	public function has_field( string $field ): bool {
		return OPEX_Woo::is_woo_field( $field );
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
		return OPEX_Woo::hpos_date_fields();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $field Field id.
	 * @return string|null The SQL to filter on, or null when the field has none.
	 */
	public function filter_expr( string $field ): ?string {
		return OPEX_Woo::is_woo_field( $field ) ? OPEX_Woo::field_map()[ $field ]['expr'] : null;
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
		return 'woo:is_guest' === $field ? '(o.customer_id = 0 OR o.customer_id IS NULL)' : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $field Field id.
	 * @return string|null The column alias in the SELECT list, or null when the field is not selected there.
	 */
	public function row_alias( string $field ): ?string {
		return OPEX_Woo::is_woo_field( $field ) ? OPEX_Woo::field_map()[ $field ]['alias'] : null;
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
		return 0 === strpos( $field, 'woo:' ) ? OPEX_Woo_Format::value( $field, $value, true ) : $value;
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
