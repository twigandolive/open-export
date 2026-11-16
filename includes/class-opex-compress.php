<?php
/**
 * Optional zip compression of a finished export file.
 *
 * The export is written as a plain file first, as always. When the job is
 * done, the plain file is packed into a zip next to it ("orders.csv.zip.opex"
 * for "orders.csv.opex"), under the name people see, and the job's file name
 * moves to the zip in the same save as the done status. Only then is the
 * plain file removed. So a crash at any point leaves either a running job
 * whose plain file can be finished again, or a done job whose zip is complete.
 *
 * The zip always gets the on-disk ending, even for a plain file that 2.0.0
 * started without it: the zip is a new file, and a .zip ending is one that
 * some web servers serve straight from disk.
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

	/** What a compressed file name ends with, before the on-disk ending. */
	const SUFFIX = '.zip';

	/** What a zip being built ends with, before the on-disk ending, until it is complete. */
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
		$name = OPEX_Jobs::real_name( (string) ( $job['filename'] ?? '' ) );
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
			throw new OPEX_User_Error( esc_html__( 'Open Export could not compress the export file because the PHP zip extension is missing.', 'open-export' ) );
		}
		if ( ! is_file( $plain ) ) {
			throw new RuntimeException( 'Open Export could not compress the export file because the file is missing.' );
		}
		$part = self::part_of( $plain );
		if ( file_exists( $part ) ) {
			wp_delete_file( $part );
		}
		$zip = new ZipArchive();
		$ok  = true === $zip->open( $part, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		// ZipArchive reads the plain file and writes the zip at close(), so close() reports both.
		$ok = $ok && $zip->addFile( $plain, OPEX_Download::plain_name( $job ) ) && self::close( $zip );
		$ok = $ok && self::complete( $part ) && rename( $part, self::zip_of( $plain ) );
		if ( ! $ok ) {
			if ( file_exists( $part ) ) {
				wp_delete_file( $part );
			}
			throw new OPEX_User_Error( esc_html__( 'Open Export could not compress the export file. The disk may be full.', 'open-export' ) );
		}
		return self::zip_of( (string) $job['filename'] );
	}

	/**
	 * The zip of a plain export file: "orders.csv.zip.opex" for
	 * "orders.csv.opex", and for a 2.0.0 "orders.csv" too.
	 *
	 * @param string $plain Plain file path or name.
	 * @return string Zip path or name.
	 */
	private static function zip_of( string $plain ): string {
		return OPEX_Jobs::disk_name( OPEX_Jobs::real_name( $plain ) . self::SUFFIX );
	}

	/**
	 * The zip being built for a plain export file: "orders.csv.zip.part.opex".
	 *
	 * @param string $plain Plain file path or name.
	 * @return string Path or name of the zip being built.
	 */
	private static function part_of( string $plain ): string {
		return OPEX_Jobs::disk_name( OPEX_Jobs::real_name( $plain ) . self::PART );
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
		if ( '' !== (string) ( $job['filename'] ?? '' ) && is_file( $plain ) && is_file( self::zip_of( $plain ) ) ) {
			wp_delete_file( $plain );
		}
	}

	/**
	 * Files that belong with an export file because of compression: the zip
	 * and a half-built zip next to a plain file, or the plain file and a
	 * half-built zip next to a zip. A 2.0.0 file also lists the 2.0.0 names,
	 * and a zip lists the 2.0.0 plain file it may have been made from.
	 *
	 * @param string $path The job's file path.
	 * @return string[]
	 */
	public static function related_files( string $path ): array {
		$real = OPEX_Jobs::real_name( $path );
		$old  = $real === $path;
		if ( self::ends_with_suffix( $real ) ) {
			$plain = substr( $real, 0, -strlen( self::SUFFIX ) );
			if ( $old ) {
				return array( $plain, $plain . self::PART );
			}
			// The plain file has the on-disk ending, or its 2.0.0 name when 2.0.0 started the job.
			// A 2.0.0 job that crashed while zipping may also have left its own zip and half-built
			// zip without the ending, and a web server could serve those, so they go too.
			return array_values(
				array_unique(
					array( OPEX_Jobs::disk_name( $plain ), $plain, self::part_of( $plain ), $plain . self::SUFFIX, $plain . self::PART )
				)
			);
		}
		$files = array( self::zip_of( $path ), self::part_of( $path ) );
		// 2.0.0 may have left its own zip or half-built zip next to a 2.0.0 plain file.
		return $old ? array_merge( $files, array( $path . self::SUFFIX, $path . self::PART ) ) : $files;
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
