<?php
/**
 * An exclusive lock on an export file, held by the worker that writes it.
 *
 * The job lock in the options table expires when a batch outlasts it. A
 * second worker can then take the job over while the first one still writes.
 * This lock is the operating system's own (flock) on a small file next to the
 * export, "<file>.lock". It lasts exactly as long as the worker holds it, so
 * it never expires during a long append, finish or zip, and it goes away by
 * itself when the process ends. Each batch can be a new PHP process: the
 * lock is taken again at the start of every batch.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- flock needs a real file handle, which WP_Filesystem does not give.

/**
 * Takes and gives up the lock file of an export file.
 */
class OPEX_File_Lock {

	/** What take() returns when another process holds the lock. */
	public const BUSY = 'busy';

	/** What take() returns when this filesystem cannot lock files. */
	public const UNSUPPORTED = 'unsupported';

	/** What take() returns when the lock file cannot be opened at all. */
	public const UNAVAILABLE = 'unavailable';

	/** What a lock file name ends with. */
	public const SUFFIX = '.lock';

	/**
	 * True to act as if this filesystem could not lock. Only tests set it, through reflection.
	 *
	 * @var bool
	 */
	private static $off = false;

	/**
	 * The lock file of an export file.
	 *
	 * @param string $file Full path of the export file.
	 * @return string Full path of its lock file.
	 */
	public static function path( string $file ): string {
		return $file . self::SUFFIX;
	}

	/**
	 * Takes the lock without waiting. A worker that finds it held stops: the
	 * other worker is still writing, and waiting could hold a web request for
	 * minutes.
	 *
	 * @param string $file Full path of the export file.
	 * @return resource|string The open, locked handle, or BUSY, UNSUPPORTED or UNAVAILABLE.
	 */
	public static function take( string $file ) {
		$lock = self::path( $file );
		// A worker that just finished removes the lock file. A handle opened on
		// the removed file locks nothing anyone else sees, so try the new one.
		for ( $try = 0; $try < 3; $try++ ) {
			$handle = @fopen( $lock, 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing folder is reported by the return value.
			if ( false === $handle ) {
				return self::UNAVAILABLE;
			}
			$blocked = 0;
			$locked  = ! self::$off && flock( $handle, LOCK_EX | LOCK_NB, $blocked );
			if ( ! $locked ) {
				fclose( $handle );
				return ( ! self::$off && $blocked ) ? self::BUSY : self::UNSUPPORTED;
			}
			clearstatcache( true, $lock );
			$open = fstat( $handle );
			$now  = @stat( $lock ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a removed file is reported by the return value.
			if ( is_array( $open ) && is_array( $now ) && $open['ino'] === $now['ino'] && $open['dev'] === $now['dev'] ) {
				return $handle;
			}
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
		return self::BUSY;
	}

	/**
	 * Gives the lock up. With $remove the lock file is deleted first, while
	 * the lock is still held, so no other worker is inside when it goes.
	 *
	 * @param mixed  $handle What take() returned. Anything but a handle is ignored.
	 * @param string $file   Full path of the export file.
	 * @param bool   $remove True when the job has ended and needs no lock file any more.
	 * @return void
	 */
	public static function release( $handle, string $file, bool $remove ): void {
		if ( ! is_resource( $handle ) ) {
			return;
		}
		if ( $remove && file_exists( self::path( $file ) ) ) {
			wp_delete_file( self::path( $file ) );
		}
		flock( $handle, LOCK_UN );
		fclose( $handle );
	}
}
