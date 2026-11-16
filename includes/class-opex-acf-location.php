<?php
/**
 * Decides which export source an ACF field group belongs to, from the
 * group's own location rules.
 *
 * ACF's matcher answers "show this group on this edit screen", which needs
 * a real post or term. An export asks a wider question: "can records of
 * this source hold this group's data". So a group belongs to a source when
 * any of its rule sets names that kind of record.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Reads ACF location rules for export sources.
 */
class OPEX_Acf_Location {

	/** Post rules that do not name a post type: they can apply to any post type. */
	private const ANY_POST = array( 'post_status', 'post_format', 'post_category', 'post_taxonomy', 'post_template' );

	/** Page rules: they apply to pages only. */
	private const PAGE = array( 'page', 'page_type', 'page_parent', 'page_template' );

	/** User rules. */
	private const USER = array( 'user_form', 'user_role' );

	/**
	 * Whether a field group's location applies to a source.
	 *
	 * @param array $location ACF location: a list of rule sets, each a list of rules.
	 * @param array $src      Parsed source.
	 */
	public static function applies( array $location, array $src ): bool {
		foreach ( $location as $rules ) {
			foreach ( (array) $rules as $rule ) {
				if ( is_array( $rule ) && self::rule_applies( $rule, $src ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether one rule names records of the source.
	 *
	 * @param array $rule ACF rule: param, operator, value.
	 * @param array $src  Parsed source.
	 */
	private static function rule_applies( array $rule, array $src ): bool {
		$param = (string) ( $rule['param'] ?? '' );
		$value = (string) ( $rule['value'] ?? '' );
		$is    = '!=' !== (string) ( $rule['operator'] ?? '==' );
		switch ( (string) ( $src['type'] ?? '' ) ) {
			case 'posts':
				$type = (string) $src['post_type'];
				if ( 'post_type' === $param ) {
					$named = ( $type === $value || 'all' === $value );
					return $is ? $named : ! $named;
				}
				if ( 'post' === $param ) {
					return $is && get_post_type( (int) $value ) === $type;
				}
				if ( 'attachment' === $param ) {
					return 'attachment' === $type;
				}
				return in_array( $param, self::ANY_POST, true ) || ( 'page' === $type && in_array( $param, self::PAGE, true ) );
			case 'terms':
				$named = ( (string) $src['taxonomy'] === $value || 'all' === $value );
				return 'taxonomy' === $param && ( $is ? $named : ! $named );
			case 'users':
				return in_array( $param, self::USER, true );
		}
		return false;
	}
}
