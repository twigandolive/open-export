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
	 * True to make the close step fail, as on a full disk. Only tests set it, through reflection.
	 *
	 * @var bool
	 */
	private static $fail_close = false;

	/**
	 * Whether this server can make zip files: Excel files and zip compression
	 * both need it. The opex_zip_available filter can turn zip output off
	 * (tests use it to reach the refusals), but never on without the extension.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return class_exists( 'ZipArchive' ) && (bool) apply_filters( 'opex_zip_available', true );
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
	 * Every step is checked: opening the zip, adding the file, and close(),
	 * where ZipArchive really writes and where a full disk shows up. The zip
	 * must then open again with one entry before it takes the zip's name. On
	 * any failure the half-built zip goes and the plain file stays, so the job
	 * keeps its plain file name and finishing again retries.
	 *
	 * @param array $job Job record whose file name is still the plain file.
	 * @return string The file name the job should save: the zip's.
	 * @throws OPEX_User_Error When this server has no zip extension, or the zip cannot be made.
	 * @throws RuntimeException When the plain file is missing.
	 */
	public static function apply( array $job ): string {
		$plain = OPEX_Jobs::file_path( $job );
		if ( ! self::available() ) {
			throw new OPEX_User_Error( esc_html__( 'Could not compress the export file: the PHP zip extension is missing.', 'open-export' ) );
		}
		if ( ! is_file( $plain ) ) {
			throw new RuntimeException( 'Could not compress the export file: it is missing.' );
		}
		$part = $plain . self::PART;
		if ( file_exists( $part ) ) {
			wp_delete_file( $part );
		}
		$zip = new ZipArchive();
		$ok  = true === $zip->open( $part, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		// ZipArchive reads the plain file and writes the zip at close(), so close() reports both.
		$ok = $ok && $zip->addFile( $plain, OPEX_Download::plain_name( $job ) ) && self::close( $zip );
		$ok = $ok && self::complete( $part ) && rename( $part, $plain . self::SUFFIX );
		if ( ! $ok ) {
			if ( file_exists( $part ) ) {
				wp_delete_file( $part );
			}
			throw new OPEX_User_Error( esc_html__( 'The export file could not be compressed. The disk may be full.', 'open-export' ) );
		}
		return (string) $job['filename'] . self::SUFFIX;
	}

	/**
	 * Closes the zip, which is when it is written.
	 *
	 * @param ZipArchive $zip The open zip.
	 * @return bool False when the write failed.
	 */
	private static function close( ZipArchive $zip ): bool {
		$closed = $zip->close();
		return $closed && ! self::$fail_close;
	}

	/**
	 * Whether a written zip is whole: not empty, and it opens with its one entry.
	 *
	 * @param string $path The zip being built.
	 * @return bool True when the zip can be used.
	 */
	private static function complete( string $path ): bool {
		clearstatcache( true, $path );
		if ( ! is_file( $path ) || filesize( $path ) <= 0 ) {
			return false;
		}
		$check = new ZipArchive();
		if ( true !== $check->open( $path, ZipArchive::CHECKCONS ) ) {
			return false;
		}
		$whole = 1 === $check->count();
		$check->close();
		return $whole;
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
