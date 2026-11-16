<?php
/**
 * Nested filter groups in a recipe: resolved rule by rule with a report row
 * for each, the way the 1.3.1 resolver reports flat rules.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Resolves recipe filter groups and counts report rows.
 */
class OPEX_Recipe_Rules {

	/**
	 * Resolves one group and the groups inside it.
	 *
	 * @param array    $group Raw group.
	 * @param int      $depth Depth of this group (the top level of the filters is 1).
	 * @param array    $src   Parsed source.
	 * @param string[] $known Field ids of the source, for suggestions.
	 * @return array array( report row, clean group or null when nothing in it survived )
	 */
	public static function group( array $group, int $depth, array $src, array $known ): array {
		$relation = OPEX_Query::normalize_relation( self::text( $group['relation'] ?? 'AND' ) );
		$row      = array(
			'group'    => true,
			'relation' => $relation,
			'rules'    => array(),
		);
		if ( $depth > OPEX_Filter_Tree::MAX_DEPTH ) {
			$row['status'] = 'dropped';
			$row['reason'] = __( 'Filter groups can nest only three levels deep. This one is deeper.', 'open-export' );
			return array( $row, null );
		}

		$kept = array();
		foreach ( array_slice( (array) ( $group['rules'] ?? array() ), 0, OPEX_Filter_Tree::MAX_RULES ) as $i => $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			if ( OPEX_Filter_Tree::is_group( $rule ) ) {
				list( $child, $clean ) = self::group( $rule, $depth + 1, $src, $known );
				$row['rules'][]        = array( 'index' => (int) $i ) + $child;
				if ( $clean ) {
					$kept[] = $clean;
				}
				continue;
			}
			list( $child, $clean ) = self::rule( $rule, $src, $known );
			$row['rules'][]        = array( 'index' => (int) $i ) + $child;
			if ( $clean ) {
				$kept[] = $clean;
			}
		}

		if ( ! $kept ) {
			$row['status'] = 'dropped';
			$row['reason'] = __( 'Open Export left out this group because none of its rules can be used.', 'open-export' );
			return array( $row, null );
		}
		$row['status'] = 'matched';
		return array(
			$row,
			array(
				'relation' => $relation,
				'rules'    => $kept,
			),
		);
	}

	/**
	 * Resolves one rule with the same checks and wording as the 1.3.1 resolver.
	 *
	 * @param array    $rule  Raw rule.
	 * @param array    $src   Parsed source.
	 * @param string[] $known Field ids, for suggestions.
	 * @return array array( report row, clean rule or null )
	 */
	private static function rule( array $rule, array $src, array $known ): array {
		$field = sanitize_text_field( self::text( $rule['field'] ?? '' ) );
		$op    = sanitize_key( self::text( $rule['op'] ?? 'eq' ) );
		$row   = array(
			'field' => $field,
			'op'    => $op,
		);

		if ( '' === $field ) {
			$row['status'] = 'dropped';
			$row['reason'] = __( 'The rule names no field.', 'open-export' );
			return array( $row, null );
		}
		if ( ! OPEX_Query::is_valid_op( $op ) ) {
			$row['status']     = 'dropped';
			$row['reason']     = __( 'Open Export does not know this operator.', 'open-export' );
			$row['suggestion'] = OPEX_Schema::suggest( $op, OPEX_Query::ops() );
			return array( $row, null );
		}
		if ( ! OPEX_Query::is_filterable_field( $src, $field ) ) {
			$row['status']     = 'dropped';
			$row['reason']     = __( 'This source has no field by that id to filter on.', 'open-export' );
			$row['suggestion'] = OPEX_Schema::suggest( $field, $known );
			return array( $row, null );
		}
		$row['status'] = 'matched';
		return array(
			$row,
			array(
				'field' => $field,
				'op'    => $op,
				'value' => self::text( $rule['value'] ?? '' ),
			),
		);
	}

	/**
	 * Counts matched and dropped rules in report rows, inside groups too. A
	 * group row itself is not a rule.
	 *
	 * @param array $rows Report rows.
	 * @return array{matched:int, dropped:int}
	 */
	public static function count_leaves( array $rows ): array {
		$count = array(
			'matched' => 0,
			'dropped' => 0,
		);
		foreach ( $rows as $row ) {
			if ( ! empty( $row['group'] ) ) {
				$inner             = self::count_leaves( (array) ( $row['rules'] ?? array() ) );
				$count['matched'] += $inner['matched'];
				$count['dropped'] += $inner['dropped'];
				continue;
			}
			$key = 'matched' === ( $row['status'] ?? '' ) ? 'matched' : 'dropped';
			++$count[ $key ];
		}
		return $count;
	}

	/**
	 * A scalar as text. Anything else becomes ''.
	 *
	 * @param mixed $value Raw value.
	 * @return string The value as text, or '' when it is not a scalar.
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
