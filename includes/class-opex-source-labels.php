<?php
/**
 * Source names as people read them, for job lists.
 *
 * The job list is read often while an export runs, so this never counts rows
 * the way the source picker does.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Translated source names by source id.
 */
class OPEX_Source_Labels {

	/** @var array<string, string> Names found in this request, by source id. */
	private static $memo = array();

	/**
	 * The translated name of a source, or '' when the source no longer exists
	 * (a post type or taxonomy that was removed, a dropped table, WooCommerce off).
	 *
	 * @param string $source_id Source id.
	 * @return string
	 */
	public static function of( string $source_id ): string {
		if ( ! isset( self::$memo[ $source_id ] ) ) {
			self::$memo[ $source_id ] = self::find( $source_id );
		}
		return self::$memo[ $source_id ];
	}

	/** Forget names found so far, for tests that remove a source. */
	public static function reset(): void {
		self::$memo = array();
	}

	/**
	 * Looks the name up.
	 *
	 * @param string $source_id Source id.
	 * @return string
	 */
	private static function find( string $source_id ): string {
		if ( '' === $source_id ) {
			return '';
		}
		$src = OPEX_Query::parse_source( $source_id );
		if ( ! $src ) {
			return '';
		}
		$provider = OPEX_Sources::for_src( $src );
		if ( $provider ) {
			return $provider->label();
		}
		switch ( $src['type'] ) {
			case 'users':
				return __( 'Users', 'open-export' );
			case 'comments':
				return __( 'Comments', 'open-export' );
			case 'posts':
				$type = get_post_type_object( (string) $src['post_type'] );
				return $type ? (string) $type->labels->name : '';
			case 'terms':
				$tax = get_taxonomy( (string) $src['taxonomy'] );
				return $tax ? (string) $tax->labels->name : '';
			case 'table':
				return (string) $src['table'];
		}
		return '';
	}
}
