<?php
/**
 * Filter rules that test related rows: post meta and taxonomy terms.
 *
 * Part of the query compartment. Reached only through OPEX_Query_Filters.
 *
 * @package Open_Export
 */

// No strict_types here on purpose: strict types would change how this code coerces arguments.

defined( 'ABSPATH' ) || exit;

/**
 * Builds the EXISTS subqueries for meta and taxonomy rules. Values always go through placeholders.
 */
class OPEX_Query_Relations {

	/**
	 * Meta comparisons run as EXISTS subqueries. Negative operators become
	 * NOT EXISTS of the positive form, so rows without the meta key also match.
	 *
	 * @param array  $src   Parsed source, which names the meta table.
	 * @param string $key   Meta key.
	 * @param string $op    Filter operator.
	 * @param string $value Filter value.
	 * @param array  $args  Receives the values for the SQL placeholders.
	 * @return string The SQL condition, or '' when the source has no meta.
	 *
	 * @internal Shared with OPEX_Query_Filters only.
	 */
	public static function meta_clause( array $src, string $key, string $op, string $value, array &$args ): string {
		global $wpdb;

		$map      = array(
			'posts'    => array( $wpdb->postmeta, 'post_id', 'p.ID' ),
			'users'    => array( $wpdb->usermeta, 'user_id', 'u.ID' ),
			'comments' => array( $wpdb->commentmeta, 'comment_id', 'c.comment_ID' ),
			'terms'    => array( $wpdb->termmeta, 'term_id', 't.term_id' ),
		);
		$provider = OPEX_Sources::for_src( $src );
		$found    = $provider ? $provider->meta_table() : ( $map[ $src['type'] ] ?? null );
		if ( null === $found ) {
			return '';
		}
		list( $table, $fk, $outer ) = $found;

		// A missing key and an empty value both read as empty, like a NULL column.
		if ( '' === $value && 'eq' === $op ) {
			$op = 'empty';
		} elseif ( '' === $value && 'neq' === $op ) {
			$op = 'not_empty';
		}

		$negatives = array(
			'neq'          => 'eq',
			'not_contains' => 'contains',
			'not_in'       => 'in',
		);
		$negate    = isset( $negatives[ $op ] );
		$inner_op  = $negate ? $negatives[ $op ] : $op;

		if ( 'empty' === $op ) {
			$args[] = $key;
			return "NOT EXISTS (SELECT 1 FROM {$table} m WHERE m.{$fk} = {$outer} AND m.meta_key = %s AND m.meta_value <> '')";
		}
		if ( 'not_empty' === $op ) {
			$args[] = $key;
			return "EXISTS (SELECT 1 FROM {$table} m WHERE m.{$fk} = {$outer} AND m.meta_key = %s AND m.meta_value <> '')";
		}

		$args[] = $key;
		$cond   = OPEX_Query_Filters::value_condition( 'm.meta_value', $inner_op, $value, $args );
		if ( ! $cond ) {
			array_pop( $args );
			return '';
		}

		$sql = "EXISTS (SELECT 1 FROM {$table} m WHERE m.{$fk} = {$outer} AND m.meta_key = %s AND {$cond})";
		return $negate ? "NOT {$sql}" : $sql;
	}

	/**
	 * Taxonomy membership tests match term slug or name.
	 *
	 * @param string $tax        Taxonomy name.
	 * @param string $op         Filter operator.
	 * @param string $value      Term slug or name, or a comma-separated list.
	 * @param array  $args       Receives the values for the SQL placeholders.
	 * @param string $object_sql Column that holds the object id the terms belong to.
	 * @return string The SQL condition.
	 *
	 * @internal Shared with OPEX_Query_Filters only.
	 */
	public static function tax_clause( string $tax, string $op, string $value, array &$args, string $object_sql = 'p.ID' ): string {
		global $wpdb;

		$exists_open = "EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr" .
			" INNER JOIN {$wpdb->term_taxonomy} xt ON xt.term_taxonomy_id = tr.term_taxonomy_id" .
			" INNER JOIN {$wpdb->terms} xtm ON xtm.term_id = xt.term_id" .
			" WHERE tr.object_id = {$object_sql} AND xt.taxonomy = %s";

		if ( 'empty' === $op ) {
			$args[] = $tax;
			return 'NOT ' . $exists_open . ')';
		}
		if ( 'not_empty' === $op ) {
			$args[] = $tax;
			return $exists_open . ')';
		}

		$list = ( 'in' === $op || 'not_in' === $op )
			? array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' )
			: array( $value );
		if ( ! $list ) {
			return 'in' === $op ? OPEX_Query_Filters::MATCH_NOTHING : OPEX_Query_Filters::MATCH_ALL;
		}

		$args[] = $tax;

		if ( 'contains' === $op || 'not_contains' === $op ) {
			$args[] = '%' . $wpdb->esc_like( $value ) . '%';
			$sql    = $exists_open . ' AND xtm.name LIKE %s)';
			return 'not_contains' === $op ? "NOT {$sql}" : $sql;
		}

		// These operators compare the term name, like "contains" does.
		$name_tests = array(
			'starts_with' => 'xtm.name LIKE %s',
			'ends_with'   => 'xtm.name LIKE %s',
			'gt'          => 'xtm.name > %s',
			'gte'         => 'xtm.name >= %s',
			'lt'          => 'xtm.name < %s',
			'lte'         => 'xtm.name <= %s',
		);
		if ( isset( $name_tests[ $op ] ) ) {
			if ( 'starts_with' === $op ) {
				$args[] = $wpdb->esc_like( $value ) . '%';
			} elseif ( 'ends_with' === $op ) {
				$args[] = '%' . $wpdb->esc_like( $value );
			} else {
				$args[] = $value;
			}
			return $exists_open . ' AND ' . $name_tests[ $op ] . ')';
		}

		$holders = implode( ',', array_fill( 0, count( $list ), '%s' ) );
		foreach ( $list as $item ) {
			$args[] = $item;
		}
		foreach ( $list as $item ) {
			$args[] = $item;
		}
		$sql = $exists_open . " AND (xtm.slug IN ({$holders}) OR xtm.name IN ({$holders})))";

		return in_array( $op, array( 'neq', 'not_in' ), true ) ? "NOT {$sql}" : $sql;
	}
}
