<?php
/**
 * The seam between the query engine and one export source.
 *
 * A provider answers every question the engine used to settle with a branch on
 * the source type: what to select from, which rows count, how to page, which
 * fields exist, which are dates, and how a value is read and shown. The query,
 * catalog and schema code ask the registry (OPEX_Sources) for the provider and
 * never name a source themselves.
 *
 * Two base kinds exist. OPEX_Source_Posts reads wp_posts under an alias, and a
 * friendly field id resolves to a core column or a meta key. OPEX_Source_Table
 * reads a FROM clause with a map of field id to SQL expression.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

// The defaults below take every argument an override may need and ignore the
// ones they do not, so the unused-parameter check does not apply to this file.
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter

/**
 * Base class every source provider extends.
 */
abstract class OPEX_Source_Provider {

	/**
	 * The source id saved configs use, such as `woo:orders`.
	 *
	 * @return string The source id.
	 */
	abstract public function id(): string;

	/**
	 * The parsed source the engine passes around. It carries `type`, plus the
	 * keys the type needs, and the provider id and class so later calls can
	 * find this provider again without asking the store again.
	 *
	 * @return array The parsed source.
	 */
	abstract public function src(): array;

	/**
	 * FROM clause, with its aliases.
	 *
	 * @return string The FROM clause.
	 */
	abstract public function from(): string;

	/**
	 * Conditions every row must meet, and their bound values.
	 *
	 * @return array{0: string[], 1: array} Conditions, then values for their placeholders.
	 */
	abstract public function base_where(): array;

	/**
	 * Qualified integer primary key used for cursor paging, such as `o.id`.
	 *
	 * @return string The key column for cursor paging.
	 */
	abstract public function primary_key(): string;

	/**
	 * Name of the primary key property on a fetched row.
	 *
	 * @return string The key property name on a fetched row.
	 */
	abstract public function id_prop(): string;

	/**
	 * SELECT list for a batch.
	 *
	 * @param array $fields Field ids the export asked for.
	 * @return string The SELECT list.
	 */
	abstract public function select_list( array $fields ): string;

	/**
	 * Whether this source can be used on this site now.
	 *
	 * @return bool True when the source can be used on this site.
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * The table that holds this source's custom fields, as array( table, foreign key, outer id ).
	 * Null when the source has no meta.
	 *
	 * @return array|null The meta table, its foreign key and the outer id, or null when the source has no meta.
	 */
	public function meta_table(): ?array {
		return null;
	}

	/**
	 * The id a field has in the underlying storage (a friendly id may map to a core: or meta: id).
	 *
	 * @param string $field Canonical field id.
	 * @return string The field id that holds the value on this storage.
	 */
	public function resolve_field( string $field ): string {
		return $field;
	}

	/**
	 * Whether this source reads the field itself (a friendly id it owns).
	 *
	 * @param string $field Field id.
	 * @return bool True when the source offers the field.
	 */
	public function has_field( string $field ): bool {
		return false;
	}

	/**
	 * Coarse type of a field it owns, for the capability document.
	 *
	 * @param string $field Field id.
	 * @return string A coarse type, such as text, number, date or bool.
	 */
	public function field_type( string $field ): string {
		return 'text';
	}

	/**
	 * Field groups for the picker.
	 *
	 * @return array[] Field groups, each with a label and its fields.
	 */
	public function field_groups(): array {
		return array();
	}

	/**
	 * The source's name as people read it, without counting rows. Providers
	 * override this. The fallback reads the picker entry, which counts.
	 *
	 * @return string The source name.
	 */
	public function label(): string {
		$item = $this->picker_item();
		return $item ? (string) $item['label'] : $this->id();
	}

	/**
	 * The picker entry (id, label, icon, count), or null when it has none.
	 *
	 * @return array|null The picker entry, or null when the source has none.
	 */
	public function picker_item(): ?array {
		return null;
	}

	/**
	 * A post type this source replaces in the picker, whose raw tile moves to Advanced.
	 *
	 * @return string The post type, or '' when the source replaces none.
	 */
	public function raw_post_type(): string {
		return '';
	}

	/**
	 * Date columns a range or rule may target, keyed by field id.
	 *
	 * @return array<string, array>
	 */
	public function date_fields(): array {
		return array();
	}

	/**
	 * SQL expression a rule on this friendly field compares against, or null to use the generic paths.
	 *
	 * @param string $field Field id.
	 * @return string|null The SQL to filter on, or null when the field has none.
	 */
	public function filter_expr( string $field ): ?string {
		return null;
	}

	/**
	 * Lets a rule value be written the way people say it. Returns the value the rule compares.
	 *
	 * @param string $field Field id of the rule.
	 * @param string $op    Filter operator.
	 * @param string $value Rule value as typed.
	 * @return string The value the rule compares.
	 */
	public function prepare_filter_value( string $field, string $op, string $value ): string {
		return $value;
	}

	/**
	 * SQL that is true when a yes or no flag field is yes, or null when the field is not such a flag.
	 *
	 * @param string $field Field id.
	 * @return string|null SQL that is true when the flag is yes, or null when the field is not a flag.
	 */
	public function flag_sql( string $field ): ?string {
		return null;
	}

	/**
	 * Name of the row property that holds a friendly field, or null to read it the generic way.
	 *
	 * @param string $field Field id.
	 * @return string|null The column alias in the SELECT list, or null when the field is not selected there.
	 */
	public function row_alias( string $field ): ?string {
		return null;
	}

	/**
	 * A value the provider builds in PHP from the row, for a field no column holds.
	 * Null when the field is not one of those.
	 *
	 * @param object $row   Fetched row.
	 * @param string $field Field id.
	 * @param int    $id    Row id.
	 * @return string|null The cell text, or null when the field is not built in PHP.
	 */
	public function compute_value( object $row, string $field, int $id ): ?string {
		return null;
	}

	/**
	 * A whole rule the provider writes itself, such as term membership on a
	 * parent row. Null leaves the rule to the shared paths.
	 *
	 * @param string $field Field id of the rule.
	 * @param string $op    Operator.
	 * @param string $value Rule value.
	 * @param array  $args  Receives values for the placeholders in the clause.
	 * @return string|null The SQL condition, or null to use the plain comparison.
	 */
	public function filter_clause( string $field, string $op, string $value, array &$args ): ?string {
		return null;
	}

	/**
	 * Whether a rule may filter on this field. Values built in PHP cannot be filtered.
	 *
	 * @param string $field Field id.
	 * @return bool True when a rule may filter on the field.
	 */
	public function is_filterable( string $field ): bool {
		return true;
	}

	/**
	 * Warms caches for a batch before its values are read.
	 *
	 * @param int[] $ids Row ids of the batch.
	 */
	public function prime( array $ids ): void {
	}

	/**
	 * Turns the stored text of a field into the value the field means (a customer id into a guest flag).
	 *
	 * @param string $field Field id.
	 * @param string $value Stored text.
	 * @return string The value the field means.
	 */
	public function derive_value( string $field, string $value ): string {
		return $value;
	}

	/**
	 * The final text of a cell: money, dates and flags in one format on every storage.
	 *
	 * @param string $field Field id.
	 * @param string $value Raw cell text.
	 * @return string The cell text people see.
	 */
	public function format_value( string $field, string $value ): string {
		return $value;
	}

	/**
	 * Legal values of a field, for the capability document. Empty when the provider has no list.
	 *
	 * @param string $field Field id.
	 * @return string[] The allowed values, or an empty array when the field is open.
	 */
	public function field_values( string $field ): array {
		return array();
	}

	/**
	 * Rows the source holds now, counted with its own FROM and base conditions, for the picker.
	 *
	 * @return int Number of rows.
	 */
	protected function count_rows(): int {
		global $wpdb;
		list( $where, $args ) = $this->base_where();
		$sql                  = 'SELECT COUNT(*) FROM ' . $this->from() . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' );
		if ( $args ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the clause holds only provider constants and placeholders.
			$sql = $wpdb->prepare( $sql, $args );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above when it has values; one cheap COUNT for the picker.
		return (int) $wpdb->get_var( $sql );
	}
}
