<?php
/**
 * One shared streamer for finished export files.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Sends an export file to the browser. The work is split so tests can check
 * the headers and the chunk loop without the final exit.
 */
class OPEX_Download {

	public const CHUNK = 1048576;

	/**
	 * Streams a job's file as a download, then exits.
	 *
	 * @param array  $job           Job record.
	 * @param string $download_name File name shown to the person.
	 * @return void
	 */
	public static function stream( array $job, string $download_name ): void {
		$path   = OPEX_Jobs::file_path( $job );
		$size   = is_file( $path ) ? filesize( $path ) : false;
		$out    = false === $size ? false : fopen( 'php://output', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$source = false === $out ? false : fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $source || false === $out ) {
			status_header( 404 );
			exit;
		}
		fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( function_exists( 'header_remove' ) ) {
			header_remove( 'Set-Cookie' );
		}
		foreach ( self::headers( $job, $download_name, (int) $size ) as $line ) {
			header( $line );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- some hosts disable the function and warn. A large export streamed to the browser must not stop at the time limit, and WordPress has no wrapper for it.
		}
		self::send_file( $path, $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * The header lines for a download.
	 *
	 * @param array  $job           Job record.
	 * @param string $download_name File name shown to the person.
	 * @param int    $size          File size in bytes.
	 * @return string[]
	 */
	public static function headers( array $job, string $download_name, int $size ): array {
		return array(
			'Content-Type: ' . self::content_type( $job ),
			'Content-Disposition: ' . self::disposition( $download_name ),
			'Content-Length: ' . $size,
			'X-Content-Type-Options: nosniff',
			'Cache-Control: no-store, private',
			'Pragma: no-cache',
		);
	}

	/**
	 * Writes a file to an open stream in 1 MB chunks.
	 *
	 * @param string   $path  File to read.
	 * @param resource $out   Writable stream.
	 * @param int      $chunk Chunk size in bytes.
	 * @return int Bytes written, or -1 when the file cannot be read.
	 */
	public static function send_file( string $path, $out, int $chunk = self::CHUNK ): int {
		$in = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $in ) {
			return -1;
		}
		$sent = 0;
		while ( ! feof( $in ) ) {
			$data = fread( $in, max( 1, $chunk ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $data || '' === $data ) {
				break;
			}
			$written = fwrite( $out, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $written ) {
				break;
			}
			$sent += $written;
			flush();
			if ( connection_aborted() ) {
				break;
			}
		}
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $sent;
	}

	/**
	 * The name people see: the delivery name or label, with the right
	 * extension, plus ".zip" when the file is compressed.
	 *
	 * @param array $job Job record.
	 * @return string
	 */
	public static function file_name( array $job ): string {
		$name = self::plain_name( $job );
		return OPEX_Compress::is_compressed( $job ) ? $name . OPEX_Compress::SUFFIX : $name;
	}

	/**
	 * The name people see for the export itself, without compression. It is
	 * also the name of the one file inside a compressed export.
	 *
	 * @param array $job Job record.
	 * @return string
	 */
	public static function plain_name( array $job ): string {
		$format = (string) ( $job['config']['format'] ?? 'csv' );
		$known  = OPEX_Writer::formats();
		$ext    = isset( $known[ $format ] ) ? (string) $known[ $format ]['extension'] : $format;
		$base   = (string) ( $job['delivery_name'] ?? '' );
		$base   = '' !== $base ? $base : (string) ( $job['label'] ?? '' );
		$base   = self::clean_name( $base );
		if ( '' === $base ) {
			$base = 'export';
		}
		$tail = '.' . $ext;
		if ( strlen( $base ) <= strlen( $tail ) || 0 !== strcasecmp( substr( $base, -strlen( $tail ) ), $tail ) ) {
			$base .= $tail;
		}
		return $base;
	}

	/**
	 * Content type for a job's format, with a charset for text formats.
	 *
	 * @param array $job Job record.
	 * @return string
	 */
	private static function content_type( array $job ): string {
		if ( OPEX_Compress::is_compressed( $job ) ) {
			return 'application/zip';
		}
		$format = (string) ( $job['config']['format'] ?? 'csv' );
		$known  = OPEX_Writer::formats();
		$mime   = isset( $known[ $format ] ) ? (string) $known[ $format ]['mime'] : 'application/octet-stream';
		if ( 'xlsx' === $format || ! isset( $known[ $format ] ) ) {
			return $mime;
		}
		$charset = 'utf-8';
		if ( 'csv' === $format ) {
			$enc     = (string) ( $job['config']['csv_encoding'] ?? 'utf8_bom' );
			$charset = 'win1252' === $enc ? 'windows-1252' : ( 'utf16le' === $enc ? 'utf-16le' : 'utf-8' );
		}
		return $mime . '; charset=' . $charset;
	}

	/**
	 * Content-Disposition value: a safe ASCII name plus an RFC 5987 name.
	 *
	 * @param string $name Wanted file name.
	 * @return string
	 */
	private static function disposition( string $name ): string {
		$name  = self::clean_name( $name );
		$name  = '' === $name ? 'export' : $name;
		$ascii = preg_replace( '/[^A-Za-z0-9._ -]/', '_', remove_accents( $name ) );
		$ascii = '' === trim( (string) $ascii, '._ ' ) ? 'export' : (string) $ascii;
		$out   = 'attachment; filename="' . $ascii . '"';
		if ( $ascii !== $name ) {
			$out .= "; filename*=UTF-8''" . rawurlencode( $name );
		}
		return $out;
	}

	/**
	 * Removes control characters, path separators and risky characters.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	private static function clean_name( string $name ): string {
		$name = (string) preg_replace( '/[\x00-\x1f\x7f]+/', '', $name );
		$name = str_replace( array( '/', '\\', ':', '*', '?', '"', '<', '>', '|', ';', '%' ), '_', $name );
		$name = trim( $name );
		if ( ! preg_match( '//u', $name ) ) {
			$name = (string) preg_replace( '/[^\x20-\x7e]/', '_', $name );
		}
		return trim( mb_strcut( $name, 0, 150, 'UTF-8' ), ' .' );
	}
}
