<?php
/**
 * Filter groups: cleans a filter tree and turns it into SQL.
 *
 * A tree is `{ relation: AND|OR, rules: [ rule | group ] }`. The flat shape
 * of version 1.3.1 (a list of rules under one relation) is a valid tree.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Sanitizes and compiles nested filter groups.
 */
class OPEX_Filter_Tree {

	public const MAX_DEPTH = 3;
	public const MAX_RULES = 50;

	/**
	 * Cleans a raw filter tree.
	 *
	 * @param array    $raw   Raw filters from a request, a recipe or a saved config.
	 * @param array    $src   Parsed source.
	 * @param string[] $notes Receives plain-language notes.
	 * @return array|WP_Error The clean tree, or an error for a rule on a field the source cannot filter on.
	 */
	public static function sanitize( array $raw, array $src, array &$notes ) {
		$count = 0;
		return self::clean_group( $raw, 1, $src, $notes, $count );
	}

	/**
	 * Compiles a clean tree into one SQL fragment, appending its values to $args.
	 *
	 * @param array $src  Parsed source.
	 * @param array $tree Clean filter tree.
	 * @param array $args Receives prepare() values, in placeholder order.
	 * @return string The fragment in parentheses, or '' when nothing is left to filter.
	 */
	public static function compile( array $src, array $tree, array &$args ): string {
		$relation = OPEX_Query::normalize_relation( $tree['relation'] ?? 'AND' );
		$clauses  = array();
		foreach ( (array) ( $tree['rules'] ?? array() ) as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$clause = self::is_group( $rule ) ? self::compile( $src, $rule, $args ) : OPEX_Query_Filters::rule_clause( $src, $rule, $args );
			if ( '' !== $clause ) {
				$clauses[] = $clause;
			}
		}
		return $clauses ? '(' . implode( " {$relation} ", $clauses ) . ')' : '';
	}

	/**
	 * Whether a node is a group rather than a rule.
	 *
	 * @param array $node Rule or group.
	 * @return bool True when the node is a group.
	 */
	public static function is_group( array $node ): bool {
		return isset( $node['rules'] ) && is_array( $node['rules'] ) && ! isset( $node['field'] );
	}

	/**
	 * Cleans one group and the groups inside it.
	 *
	 * @param array    $raw   Raw group.
	 * @param int      $depth 1 for the top group.
	 * @param array    $src   Parsed source.
	 * @param string[] $notes Notes.
	 * @param int      $count Rules kept so far, across the whole tree.
	 * @return array|WP_Error
	 */
	private static function clean_group( array $raw, int $depth, array $src, array &$notes, int &$count ) {
		$group = array(
			'relation' => OPEX_Query::normalize_relation( $raw['relation'] ?? 'AND' ),
			'rules'    => array(),
		);
		foreach ( (array) ( $raw['rules'] ?? array() ) as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			if ( self::is_group( $rule ) ) {
				if ( $depth >= self::MAX_DEPTH ) {
					$notes[] = __( 'Open Export dropped a filter group because groups can nest only three levels deep.', 'open-export' );
					continue;
				}
				$inner = self::clean_group( $rule, $depth + 1, $src, $notes, $count );
				if ( is_wp_error( $inner ) ) {
					return $inner;
				}
				if ( $inner['rules'] ) {
					$group['rules'][] = $inner;
				}
				continue;
			}
			if ( $count >= self::MAX_RULES ) {
				$notes[] = __( 'Open Export kept only the first 50 filter rules.', 'open-export' );
				break;
			}
			$leaf = self::clean_rule( $rule, $src, $notes );
			if ( is_wp_error( $leaf ) ) {
				return $leaf;
			}
			if ( $leaf ) {
				$group['rules'][] = $leaf;
				++$count;
			}
		}
		return $group;
	}

	/**
	 * Cleans one rule.
	 *
	 * @param array    $rule  Raw rule.
	 * @param array    $src   Parsed source.
	 * @param string[] $notes Notes.
	 * @return array|WP_Error Empty array when the rule is dropped.
	 */
	private static function clean_rule( array $rule, array $src, array &$notes ) {
		$rule_field = sanitize_text_field( self::text( $rule['field'] ?? '' ) );
		$rule_op    = sanitize_key( self::text( $rule['op'] ?? 'eq' ) );

		// A rule on a field this source does not have would be dropped at
		// query time, and the export would quietly widen. Refuse it instead.
		if ( ! OPEX_Query::is_filterable_field( $src, $rule_field ) ) {
			return new WP_Error(
				'opex_bad_filter',
				sprintf(
					/* translators: %s: the field id a filter rule names. */
					__( 'The filter uses a field this source cannot filter on: "%s". Remove the rule or choose another field.', 'open-export' ),
					$rule_field
				),
				array( 'status' => 400 )
			);
		}

		if ( ! OPEX_Query::is_valid_op( $rule_op ) ) {
			$notes[] = sprintf(
				/* translators: 1: the operator, 2: the field it was used on. */
				__( 'Open Export dropped the filter operator "%1$s" on "%2$s" because it does not know that operator.', 'open-export' ),
				$rule_op,
				$rule_field
			);
			return array();
		}

		return array(
			'field' => $rule_field,
			'op'    => $rule_op,
			'value' => self::text( $rule['value'] ?? '' ),
		);
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
