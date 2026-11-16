<?php
/**
 * File name patterns for exports. Turns a pattern like {label}-{date} into a
 * safe name with no path, no leading dot and no extension.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Renders a file name pattern into a safe file name.
 */
class OPEX_Filename {

	private const MAX_LENGTH = 120;
	private const FALLBACK   = 'export';

	/**
	 * Render a pattern.
	 *
	 * @param string $pattern Pattern with tokens {label} {source} {date} {time} {datetime} {id} {site}.
	 * @param array  $context Values: label, source, id, site, and optional time (UTC timestamp, for tests).
	 */
	public static function render( string $pattern, array $context ): string {
		$now = isset( $context['time'] ) ? (int) $context['time'] : time();
		$map = array(
			'label'    => (string) ( $context['label'] ?? '' ),
			// The colon in a source id such as posts:post would vanish in sanitize_file_name.
			'source'   => str_replace( ':', '-', (string) ( $context['source'] ?? '' ) ),
			'id'       => (string) ( $context['id'] ?? '' ),
			'site'     => (string) ( $context['site'] ?? self::site_host() ),
			'date'     => wp_date( 'Y-m-d', $now ),
			'time'     => wp_date( 'His', $now ),
			'datetime' => wp_date( 'Y-m-d-His', $now ),
		);

		// Unknown tokens are removed, known ones replaced in a single pass.
		$name = (string) preg_replace_callback(
			'/\{([A-Za-z_]+)\}/',
			static function ( array $m ) use ( $map ): string {
				$key = strtolower( $m[1] );
				return isset( $map[ $key ] ) ? $map[ $key ] : '';
			},
			$pattern
		);

		// Path separators and dots become hyphens: no folders, no extension, no hidden file.
		$name = str_replace( array( '/', '\\', '.', "\0" ), '-', $name );
		$name = sanitize_file_name( $name );
		$name = trim( $name, " -_\t\n\r" );

		if ( mb_strlen( $name ) > self::MAX_LENGTH ) {
			$name = trim( mb_substr( $name, 0, self::MAX_LENGTH ), ' -_' );
		}
		return '' === $name ? self::FALLBACK : $name;
	}

	/** Host name of the site, for the {site} token. */
	private static function site_host(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $host ) ? $host : '';
	}
}
