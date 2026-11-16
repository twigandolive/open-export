<?php
/**
 * Optional zip compression of a finished export file.
 *
 * The export is written as a plain file first, as always. When the job is
 * done, the plain file is packed into "<file>.zip" next to it, under the name
 * people see, and the job's file name moves to the zip in the same save as
 * the done status. Only then is the plain file removed. So a crash at any
 * point leaves either a running job whose plain file can be finished again,
 * or a done job whose zip is complete.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- export files can be larger than WP_Filesystem handles well.

/**
 * Packs finished export files into a zip.
 */
class OPEX_Compress {

	/** What a compressed file name ends with. */
	const SUFFIX = '.zip';

	/** What a zip being built ends with, until it is complete. */
	const PART = '.zip.part';

	/**
	 * Whether this server can make zip files.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * Whether a config asks for a zip. An Excel file is a zip already, so it never is.
	 *
	 * @param array $config Export config.
	 * @return bool
	 */
	public static function wanted( array $config ): bool {
		return 'zip' === ( $config['compress'] ?? 'none' ) && 'xlsx' !== ( $config['format'] ?? '' );
	}

	/**
	 * Whether a job's file on disk is the zip.
	 *
	 * @param array $job Job record.
	 * @return bool
	 */
	public static function is_compressed( array $job ): bool {
		$name = (string) ( $job['filename'] ?? '' );
		return self::wanted( (array) ( $job['config'] ?? array() ) ) && self::ends_with_suffix( $name );
	}

	/**
	 * Packs a job's finished plain file into a zip next to it. Calling it
	 * again rebuilds the same zip, so a replayed finish is safe.
	 *
	 * @param array $job Job record whose file name is still the plain file.
	 * @return string The file name the job should save: the zip's.
	 * @throws RuntimeException When the zip cannot be made.
	 */
	public static function apply( array $job ): string {
		$plain = OPEX_Jobs::file_path( $job );
		if ( ! self::available() ) {
			throw new RuntimeException( 'Could not compress the export file: the PHP zip extension is missing.' );
		}
		if ( ! is_file( $plain ) ) {
			throw new RuntimeException( 'Could not compress the export file: it is missing.' );
		}
		$part = $plain . self::PART;
		if ( file_exists( $part ) ) {
			wp_delete_file( $part );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $part, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'Could not compress the export file.' );
		}
		// ZipArchive reads the plain file at close(), so close() reports a read failure too.
		$ok = $zip->addFile( $plain, OPEX_Download::plain_name( $job ) ) && $zip->close();
		if ( ! $ok || ! rename( $part, $plain . self::SUFFIX ) ) {
			if ( file_exists( $part ) ) {
				wp_delete_file( $part );
			}
			throw new RuntimeException( 'Could not compress the export file.' );
		}
		return (string) $job['filename'] . self::SUFFIX;
	}

	/**
	 * Removes the plain file once the job's saved file name points at the zip.
	 *
	 * @param array $job The job as it was before compression (plain file name).
	 * @return void
	 */
	public static function discard_plain( array $job ): void {
		$plain = OPEX_Jobs::file_path( $job );
		if ( '' !== (string) ( $job['filename'] ?? '' ) && is_file( $plain ) && is_file( $plain . self::SUFFIX ) ) {
			wp_delete_file( $plain );
		}
	}

	/**
	 * Files that belong with an export file because of compression: the zip
	 * and a half-built zip next to a plain file, or the plain file next to a zip.
	 *
	 * @param string $path The job's file path.
	 * @return string[]
	 */
	public static function related_files( string $path ): array {
		if ( self::ends_with_suffix( $path ) ) {
			$plain = substr( $path, 0, -strlen( self::SUFFIX ) );
			return array( $plain, $plain . self::PART );
		}
		return array( $path . self::SUFFIX, $path . self::PART );
	}

	/**
	 * Whether a name ends with ".zip".
	 *
	 * @param string $name File name or path.
	 * @return bool
	 */
	private static function ends_with_suffix( string $name ): bool {
		$len = strlen( self::SUFFIX );
		return strlen( $name ) > $len && self::SUFFIX === substr( $name, -$len );
	}
}

// phpcs:enable
