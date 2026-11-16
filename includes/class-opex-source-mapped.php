<?php
/**
 * A provider driven by one map of field definitions.
 *
 * Each WooCommerce source beyond orders lists its fields once, and this trait
 * answers the engine's questions from that list. A field is read one of three
 * ways:
 * - `read`: a core: or meta: id, so the shared post code reads and filters it.
 * - `sql`: an expression over the source's own aliases, selected under a
 *   private alias and compared as is by a rule.
 * - `php`: a value the provider builds per row (an image URL, a JSON list).
 *   Such a field filters only when it also names a `filter` expression or the
 *   `terms` it is made of.
 *
 * Other keys: `group` (picker group), `type` (capability document type),
 * `format` (money, decimal, flag, local, utc, epoch or list), `values` (legal values),
 * and `terms` (the taxonomy a term-list field is made of).
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Field-map behavior for a source provider.
 */
trait OPEX_Source_Mapped {

	/**
	 * Field definitions, by defs_key().
	 *
	 * @var array<string, array<string, array>>
	 */
	private $mapped_defs = array();

	/**
	 * Field definitions keyed by field id, in picker order.
	 *
	 * @return array<string, array>
	 */
	abstract protected function field_defs(): array;

	/**
	 * The part of the SELECT list every batch needs, before the mapped expressions.
	 *
	 * @return string The SQL for the start of the SELECT list.
	 */
	abstract protected function base_select(): string;

	/**
	 * Groups listed after the mapped ones, such as custom fields.
	 *
	 * @return array[] Field groups, each with a label and its fields.
	 */
	protected function extra_groups(): array {
		return array();
	}

	/**
	 * SQL for the row id a term-list field reads its terms from.
	 *
	 * @return string The SQL column.
	 */
	protected function term_object(): string {
		return 'p.ID';
	}

	/**
	 * What the definitions depend on. They name tables, so a switch to another
	 * site of a network must build them again.
	 *
	 * @return string A key that changes when the definitions must be built again.
	 */
	protected function defs_key(): string {
		global $wpdb;
		return (string) $wpdb->prefix;
	}

	/**
	 * Every definition, built once per defs_key().
	 *
	 * @return array<string, array>
	 */
	protected function defs(): array {
		$key = $this->defs_key();
		if ( ! isset( $this->mapped_defs[ $key ] ) ) {
			$this->mapped_defs[ $key ] = $this->field_defs();
		}
		return $this->mapped_defs[ $key ];
	}

	/**
	 * The definition of a field, or null when the map does not have it.
	 *
	 * @param string $field Field id.
	 * @return array|null The definition, or null when the field is not mapped.
	 */
	protected function def( string $field ): ?array {
		return $this->defs()[ $field ] ?? null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $field Field id.
	 * @return bool True when the source offers the field.
	 */
	public function has_field( string $field ): bool {
		return null !== $this->def( $field );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $field Field id.
	 * @return string A coarse type, such as text, number, date or bool.
	 */
	public function field_type( string $field ): string {
		return (string) ( $this->def( $field )['type'] ?? 'text' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $field Canonical field id.
	 * @return string The field id that holds the value on this storage.
	 */
	public function resolve_field( string $field ): string {
		$def = $this->def( $field );
		return isset( $def['read'] ) ? (string) $def['read'] : $field;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $field Field id.
	 * @return string|null The SQL to filter on, or null when the field has none.
	 */
	public function filter_expr( string $field ): ?string {
		$def = $this->def( $field );
		if ( isset( $def['filter'] ) ) {
			return (string) $def['filter'];
		}
		return isset( $def['sql'] ) ? (string) $def['sql'] : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $field Field id.
	 * @return bool True when a rule may filter on the field.
	 */
	public function is_filterable( string $field ): bool {
		$def = $this->def( $field );
		return ! isset( $def['php'] ) || isset( $def['filter'] ) || isset( $def['terms'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $field Field id.
	 * @param string $op    Filter operator.
	 * @param string $value Filter value.
	 * @param array  $args  Receives the values for the SQL placeholders.
	 * @return string|null The SQL condition, or null to use the plain comparison.
	 */
	public function filter_clause( string $field, string $op, string $value, array &$args ): ?string {
		$def = $this->def( $field );
		if ( null === $def ) {
			return null;
		}
		if ( isset( $def['terms'] ) ) {
			return OPEX_Query_Filters::term_clause( (string) $def['terms'], $op, $value, $args, $this->term_object() );
		}
		if ( 'epoch' === ( $def['format'] ?? '' ) ) {
			$dates = $this->date_fields();
			return isset( $dates[ $field ] ) ? OPEX_Query_Dates::epoch_condition( $dates[ $field ], $op, $value, $args ) : null;
		}
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $field Field id.
	 * @return string|null SQL that is true when the flag is yes, or null when the field is not a flag.
	 */
	public function flag_sql( string $field ): ?string {
		$def = $this->def( $field );
		if ( null === $def || 'boolean' !== ( $def['type'] ?? '' ) ) {
			return null;
		}
		if ( isset( $def['sql'] ) ) {
			// A SQL flag yields the text 1 or 0, which compares the same on every database.
			return '(' . $def['sql'] . ") = '1'";
		}
		$meta = $this->meta_table();
		if ( null === $meta || ! isset( $def['read'] ) || 0 !== strpos( (string) $def['read'], 'meta:' ) ) {
			return null;
		}
		global $wpdb;
		list( $table, $fk, $outer ) = $meta;
		// Stored flags come as yes, 1, true or on, so a rule matches every spelling.
		return (string) $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and columns come from the provider's fixed meta table.
			"EXISTS (SELECT 1 FROM {$table} fm WHERE fm.{$fk} = {$outer} AND fm.meta_key = %s AND LOWER(fm.meta_value) IN ('yes', '1', 'true', 'on'))",
			substr( (string) $def['read'], 5 )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $field Field id.
	 * @return string|null The column alias in the SELECT list, or null when the field is not selected there.
	 */
	public function row_alias( string $field ): ?string {
		$def = $this->def( $field );
		return isset( $def['sql'] ) ? 'wf_' . substr( $field, 4 ) : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string[] $fields Field ids the export asked for.
	 * @return string The SELECT list.
	 */
	public function select_list( array $fields ): string {
		$parts = array( $this->base_select() );
		$seen  = array();
		foreach ( $fields as $field ) {
			$field = (string) $field;
			$def   = $this->def( $field );
			if ( ! isset( $def['sql'] ) || isset( $seen[ $field ] ) ) {
				continue;
			}
			// Only the expressions an export asks for run, so a narrow export stays cheap.
			$seen[ $field ] = true;
			$parts[]        = $def['sql'] . ' AS `wf_' . substr( $field, 4 ) . '`';
		}
		return implode( ', ', $parts );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $field Field id.
	 * @param string $value Raw cell text.
	 * @return string The cell text people see.
	 */
	public function format_value( string $field, string $value ): string {
		$def = $this->def( $field );
		if ( null === $def ) {
			return $value;
		}
		$format = $def['format'] ?? ( 'boolean' === ( $def['type'] ?? '' ) ? 'flag' : '' );
		switch ( $format ) {
			case 'money':
				return OPEX_Money::format( $value, OPEX_Woo_Format::price_decimals() );
			case 'decimal':
				return self::decimal_text( $value );
			case 'flag':
				return OPEX_Woo_Format::flag( $value );
			case 'local':
			case 'epoch':
				return OPEX_Woo_Format::site_date( $value, false );
			case 'utc':
				return OPEX_Woo_Format::site_date( $value, true );
			case 'list':
				return self::list_text( $value );
		}
		return $value;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array[] Field groups, each with a label and its fields.
	 */
	public function field_groups(): array {
		$buckets = array();
		foreach ( $this->defs() as $id => $def ) {
			$buckets[ (string) $def['group'] ][] = array(
				'id'    => $id,
				'label' => (string) ( $def['label'] ?? substr( $id, 4 ) ),
				'type'  => (string) ( $def['type'] ?? 'text' ),
			);
		}
		$groups = array();
		foreach ( $buckets as $label => $fields ) {
			$groups[] = array(
				'label'  => $label,
				'fields' => $fields,
			);
		}
		return array_merge( $groups, $this->extra_groups() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $field Field id.
	 * @return string[] The allowed values, or an empty array when the field is open.
	 */
	public function field_values( string $field ): array {
		return array_values( (array) ( $this->def( $field )['values'] ?? array() ) );
	}

	/**
	 * A stored decimal padded to the price decimals like money, but never
	 * rounded: a value with more places keeps all of them.
	 *
	 * @param string $value Stored text.
	 * @return string The number as text.
	 */
	private static function decimal_text( string $value ): string {
		$places = preg_match( '/\.(\d+)$/', trim( $value ), $m ) ? strlen( $m[1] ) : 0;
		// OPEX_Money rounds past 8 places, so such a value is left as stored.
		if ( $places > 8 ) {
			return $value;
		}
		return OPEX_Money::format( $value, max( OPEX_Woo_Format::price_decimals(), $places ) );
	}

	/**
	 * A stored list (comma text or a JSON array from a serialized value) as names joined with ", ".
	 *
	 * @param string $value Stored text.
	 * @return string The names, or '' when the list is empty.
	 */
	private static function list_text( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$items = null;
		if ( '[' === $value[0] ) {
			$decoded = json_decode( $value, true );
			$items   = is_array( $decoded ) ? array_filter( $decoded, 'is_scalar' ) : null;
		}
		if ( null === $items ) {
			$items = explode( ',', $value );
		}
		$items = array_filter( array_map( 'trim', array_map( 'strval', $items ) ), 'strlen' );
		return implode( ', ', $items );
	}
}
