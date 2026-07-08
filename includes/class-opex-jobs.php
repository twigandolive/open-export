<?php
/**
 * Job persistence. A job is small (config + cursor + counters), so the whole
 * registry lives in one non-autoloaded option. History is capped and files
 * older than 30 days are swept whenever the app screen loads.
 */

defined( 'ABSPATH' ) || exit;

class OPEX_Jobs {

	private const OPTION      = 'opex_jobs';
	private const MAX_HISTORY = 50;
	private const MAX_AGE     = 30 * DAY_IN_SECONDS;
	private const LOCK_TTL    = 120;

	public static function all(): array {
		$jobs = get_option( self::OPTION, array() );
		return is_array( $jobs ) ? $jobs : array();
	}

	public static function get( string $id ): ?array {
		$jobs = self::all();
		return $jobs[ $id ] ?? null;
	}

	public static function save( array $job ): void {
		$jobs               = self::all();
		$jobs[ $job['id'] ] = $job;

		// Trim history oldest-first, removing files with their jobs.
		if ( count( $jobs ) > self::MAX_HISTORY ) {
			uasort( $jobs, static function ( $a, $b ) {
				return $a['created'] <=> $b['created'];
			} );
			while ( count( $jobs ) > self::MAX_HISTORY ) {
				$oldest = array_key_first( $jobs );
				self::remove_file( $jobs[ $oldest ] );
				unset( $jobs[ $oldest ] );
			}
		}

		update_option( self::OPTION, $jobs, false );
	}

	public static function delete( string $id ): void {
		self::release_lock( $id );
		$jobs = self::all();
		if ( isset( $jobs[ $id ] ) ) {
			self::remove_file( $jobs[ $id ] );
			unset( $jobs[ $id ] );
			update_option( self::OPTION, $jobs, false );
		}
	}

	public static function sweep(): void {
		$jobs    = self::all();
		$changed = false;
		foreach ( $jobs as $id => $job ) {
			if ( time() - (int) $job['created'] > self::MAX_AGE ) {
				self::remove_file( $job );
				unset( $jobs[ $id ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( self::OPTION, $jobs, false );
		}
	}

	/** The public shape sent to the UI — no server paths leak out. */
	public static function expose( array $job ): array {
		return array(
			'id'        => $job['id'],
			'label'     => $job['label'],
			'format'    => $job['config']['format'],
			'status'    => $job['status'],
			'total'     => (int) $job['total'],
			'processed' => (int) $job['processed'],
			'created'   => (int) $job['created'],
			'filename'  => $job['filename'],
			'filesize'  => (int) ( $job['filesize'] ?? 0 ),
			'error'     => (string) ( $job['error'] ?? '' ),
		);
	}

	public static function file_path( array $job ): string {
		return trailingslashit( opex_export_dir() ) . $job['filename'];
	}

	/** Prevent two workers from processing the same job at once. */
	public static function acquire_lock( string $id ): bool {
		$key = self::lock_key( $id );
		if ( get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, time(), self::LOCK_TTL );
		return true;
	}

	public static function release_lock( string $id ): void {
		delete_transient( self::lock_key( $id ) );
	}

	private static function lock_key( string $id ): string {
		return 'opex_lock_' . $id;
	}

	private static function remove_file( array $job ): void {
		$path = self::file_path( $job );
		if ( $job['filename'] && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
}
