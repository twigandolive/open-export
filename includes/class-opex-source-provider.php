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

/**
 * Base class every source provider extends.
 */
abstract class OPEX_Source_Provider {

	/** The source id saved configs use, such as `woo:orders`. */
	abstract public function id(): string;

	/**
	 * The parsed source the engine passes around. It carries `type`, plus the
	 * keys the type needs, and the provider id and class so later calls can
	 * find this provider again without asking the store again.
	 */
	abstract public function src(): array;

	/** FROM clause, with its aliases. */
	abstract public function from(): string;

	/**
	 * Conditions every row must meet, and their bound values.
	 *
	 * @return array{0: string[], 1: array} Conditions, then values for their placeholders.
	 */
	abstract public function base_where(): array;

	/** Qualified integer primary key used for cursor paging, such as `o.id`. */
	abstract public function primary_key(): string;

	/** Name of the primary key property on a fetched row. */
	abstract public function id_prop(): string;

	/**
	 * SELECT list for a batch.
	 *
	 * @param array $fields Field ids the export asked for.
	 */
	abstract public function select_list( array $fields ): string;

	/** Whether this source can be used on this site now. */
	public function is_available(): bool {
		return true;
	}

	/**
	 * The table that holds this source's custom fields, as array( table, foreign key, outer id ).
	 * Null when the source has no meta.
	 */
	public function meta_table(): ?array {
		return null;
	}

	/** The id a field has in the underlying storage (a friendly id may map to a core: or meta: id). */
	public function resolve_field( string $field ): string {
		return $field;
	}

	/** Whether this source reads the field itself (a friendly id it owns). */
	public function has_field( string $field ): bool {
		return false;
	}

	/** Coarse type of a field it owns, for the capability document. */
	public function field_type( string $field ): string {
		return 'text';
	}

	/** Field groups for the picker. */
	public function field_groups(): array {
		return array();
	}

	/** The picker entry (id, label, icon, count), or null when it has none. */
	public function picker_item(): ?array {
		return null;
	}

	/** A post type this source replaces in the picker, whose raw tile moves to Advanced. */
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

	/** SQL expression a rule on this friendly field compares against, or null to use the generic paths. */
	public function filter_expr( string $field ): ?string {
		return null;
	}

	/** Lets a rule value be written the way people say it. Returns the value the rule compares. */
	public function prepare_filter_value( string $field, string $op, string $value ): string {
		return $value;
	}

	/** SQL that is true when a yes or no flag field is yes, or null when the field is not such a flag. */
	public function flag_sql( string $field ): ?string {
		return null;
	}

	/** Name of the row property that holds a friendly field, or null to read it the generic way. */
	public function row_alias( string $field ): ?string {
		return null;
	}

	/** Turns the stored text of a field into the value the field means (a customer id into a guest flag). */
	public function derive_value( string $field, string $value ): string {
		return $value;
	}

	/** The final text of a cell: money, dates and flags in one format on every storage. */
	public function format_value( string $field, string $value ): string {
		return $value;
	}

	/** Legal values of a field, for the capability document. Empty when the provider has no list. */
	public function field_values( string $field ): array {
		return array();
	}
}
