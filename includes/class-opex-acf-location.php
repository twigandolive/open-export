<?php
/**
 * Decides which export source an ACF field group belongs to, from the
 * group's own location rules.
 *
 * ACF's matcher answers "show this group on this edit screen", which needs
 * a real post or term. An export asks a wider question: "can records of
 * this source hold this group's data". ACF joins the rules inside one rule
 * set with AND and the rule sets with OR, and so does this class: a rule set
 * belongs to a source when one of its rules names that kind of record and
 * no rule in it excludes that kind.
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

	/** Rules that name records no export source reads, such as menus or options pages. */
	private const OTHER = array( 'comment', 'nav_menu', 'nav_menu_item', 'widget', 'options_page', 'block' );

	/** A rule names records of the source. */
	private const YES = 1;

	/** A rule says nothing about the kind of record, such as a rule about the person viewing. */
	private const NEUTRAL = 0;

	/** A rule rules the source out. */
	private const NO = -1;

	/**
	 * Whether a field group's location applies to a source.
	 *
	 * @param array $location ACF location: a list of rule sets, each a list of rules.
	 * @param array $src      Parsed source.
	 * @return bool True when any rule set of the location matches the source.
	 */
	public static function applies( array $location, array $src ): bool {
		foreach ( $location as $rules ) {
			if ( self::set_applies( (array) $rules, $src ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether one rule set (rules joined with AND) applies to a source: one
	 * rule names it and no rule rules it out.
	 *
	 * @param array $rules Rules of one set.
	 * @param array $src   Parsed source.
	 * @return bool True when the set matches the source.
	 */
	private static function set_applies( array $rules, array $src ): bool {
		$named = false;
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$state = self::rule_state( $rule, $src );
			if ( self::NO === $state ) {
				return false;
			}
			$named = $named || self::YES === $state;
		}
		return $named;
	}

	/**
	 * What one rule says about the source: it names its records, rules them
	 * out, or says nothing about the kind of record.
	 *
	 * @param array $rule ACF rule: param, operator, value.
	 * @param array $src  Parsed source.
	 * @return int self::YES, self::NO or self::NEUTRAL.
	 */
	private static function rule_state( array $rule, array $src ): int {
		$param = (string) ( $rule['param'] ?? '' );
		$value = (string) ( $rule['value'] ?? '' );
		$is    = '!=' !== (string) ( $rule['operator'] ?? '==' );
		$kind  = self::kind( $param );
		$type  = (string) ( $src['type'] ?? '' );
		if ( '' === $kind ) {
			return self::NEUTRAL;
		}
		if ( $kind !== $type ) {
			return self::NO;
		}
		switch ( $type ) {
			case 'posts':
				return self::post_rule_state( $param, $value, $is, (string) $src['post_type'] );
			case 'terms':
				$named = ( (string) $src['taxonomy'] === $value || 'all' === $value );
				return ( $is ? $named : ! $named ) ? self::YES : self::NO;
		}
		return self::YES;
	}

	/**
	 * What one post rule says about a post type.
	 *
	 * @param string $param Rule param.
	 * @param string $value Rule value.
	 * @param bool   $is    True for ==, false for !=.
	 * @param string $type  Post type of the source.
	 * @return int self::YES, self::NO or self::NEUTRAL.
	 */
	private static function post_rule_state( string $param, string $value, bool $is, string $type ): int {
		if ( 'post_type' === $param ) {
			$named = ( $type === $value || 'all' === $value );
			return ( $is ? $named : ! $named ) ? self::YES : self::NO;
		}
		if ( 'post' === $param ) {
			// "Not this one post" leaves the rest of the post type in.
			if ( ! $is ) {
				return self::NEUTRAL;
			}
			return get_post_type( (int) $value ) === $type ? self::YES : self::NO;
		}
		if ( 'attachment' === $param ) {
			return 'attachment' === $type ? self::YES : self::NO;
		}
		if ( in_array( $param, self::PAGE, true ) ) {
			return 'page' === $type ? self::YES : self::NO;
		}
		return self::YES;
	}

	/**
	 * The source type a rule param names records of: posts, terms or users,
	 * 'other' for records no source reads, '' for a rule about something else.
	 *
	 * @param string $param Rule param.
	 * @return string The source type, 'other', or ''.
	 */
	private static function kind( string $param ): string {
		if ( in_array( $param, array( 'post_type', 'post', 'attachment' ), true ) || in_array( $param, self::ANY_POST, true ) || in_array( $param, self::PAGE, true ) ) {
			return 'posts';
		}
		if ( 'taxonomy' === $param ) {
			return 'terms';
		}
		if ( in_array( $param, self::USER, true ) ) {
			return 'users';
		}
		return in_array( $param, self::OTHER, true ) ? 'other' : '';
	}
}
