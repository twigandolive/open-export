<?php
/**
 * Export config: the output options added after 2.0's first keys. These are
 * the CSV quote character, quoting every cell, and zip compression of the
 * finished file.
 *
 * Part of the export engine. OPEX_Config calls it while it cleans a config.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Cleans the CSV quoting and compression keys and turns them into writer options.
 */
class OPEX_Config_Output {

	/** Quote character keys and the character each one writes. */
	const ENCLOSURES = array(
		'double' => '"',
		'single' => "'",
	);

	/** Compression choices. */
	const COMPRESS = array( 'none', 'zip' );

	/**
	 * Cleans the output keys of a raw config.
	 *
	 * @param array    $raw    Raw config.
	 * @param string   $format The clean format id.
	 * @param string[] $notes  Receives notes.
	 * @return array|WP_Error Keys csv_enclosure, csv_quote_all and compress, or an error.
	 */
	public static function sanitize( array $raw, string $format, array &$notes ) {
		$enclosure = sanitize_key( is_scalar( $raw['csv_enclosure'] ?? null ) ? (string) $raw['csv_enclosure'] : 'double' );
		$enclosure = isset( self::ENCLOSURES[ $enclosure ] ) ? $enclosure : 'double';
		$quote_all = ! empty( $raw['csv_quote_all'] ) && rest_sanitize_boolean( $raw['csv_quote_all'] );

		$compress = sanitize_key( is_scalar( $raw['compress'] ?? null ) ? (string) $raw['compress'] : 'none' );
		$compress = in_array( $compress, self::COMPRESS, true ) ? $compress : 'none';
		if ( 'zip' === $compress && 'xlsx' === $format ) {
			// An Excel file is already a zip, so packing it again only adds a step for the person.
			$compress = 'none';
			$notes[]  = __( 'Open Export turned zip compression off because an Excel file is already compressed.', 'open-export' );
		}
		if ( 'zip' === $compress && ! OPEX_Compress::available() ) {
			return new WP_Error( 'opex_no_zip', __( 'Zip compression needs the PHP zip extension, which is not installed on this server. Turn compression off.', 'open-export' ), array( 'status' => 400 ) );
		}

		return array(
			'csv_enclosure' => $enclosure,
			'csv_quote_all' => $quote_all,
			'compress'      => $compress,
		);
	}

	/**
	 * Writer options for the CSV quoting keys of a sanitized config.
	 *
	 * @param array $config Sanitized config.
	 * @return array Keys csv_enclosure (the character) and csv_quote_all.
	 */
	public static function writer_options( array $config ): array {
		$key = (string) ( $config['csv_enclosure'] ?? 'double' );
		return array(
			'csv_enclosure' => self::ENCLOSURES[ $key ] ?? '"',
			'csv_quote_all' => ! empty( $config['csv_quote_all'] ),
		);
	}
}
