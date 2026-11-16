<?php
/**
 * Job persistence. A job is small (config + cursor + counters), so the whole
 * registry lives in one non-autoloaded option. History is capped, and files
 * older than the retention setting are swept by the hourly maintenance run.
 */

defined( 'ABSPATH' ) || exit;

class OPEX_Jobs {

	private const OPTION      = 'opex_jobs';
	private const MAX_HISTORY = 50;
	private const LOCK_TTL    = 120;

	/**
	 * Every key a job record can have, with the value an older record gets
	 * when it lacks the key. The first twelve are the original record; the
	 * rest were added later and are always optional on read.
	 */
	private const DEFAULTS = array(
		'id'            => '',
		'label'         => '',
		'config'        => array(),
		'headers'       => array(),
		'status'        => 'running',
		'total'         => 0,
		'processed'     => 0,
		'cursor'        => 0,
		'created'       => 0,
		'filename'      => '',
		'filesize'      => 0,
		'error'         => '',
		'mode'          => 'browser',
		'schedule_id'   => '',
		'origin'        => 'manual',
		'heartbeat'     => 0,
		'finished'      => 0,
		'delivery_name' => '',
		'delivery'      => array(),
		'deliveries'    => array(),
		'tokens'        => array(),
		'since'         => array(),
		'sites'         => array(),
		'site_index'    => 0,
		'notes'         => array(),
		'checkpoint'    => array(),
	);

	/** Config keys an old record might lack, with their defaults. */
	private const CONFIG_DEFAULTS = array(
		'source'        => '',
		'fields'        => array(),
		'filters'       => array( 'relation' => 'AND', 'rules' => array() ),
		'date_range'    => array( 'field' => '', 'preset' => 'all_time', 'from' => '', 'to' => '' ),
		'format'        => 'csv',
		'batch_size'    => 500,
		'csv_delimiter' => 'comma',
		'column_labels' => array(),
	);

	/** The stored registry, exactly as saved. */
	private static function stored(): array {
		$jobs = get_option( self::OPTION, array() );
		return is_array( $jobs ) ? $jobs : array();
	}

	/** Fill every missing key of one record. */
	private static function with_defaults( array $job ): array {
		$job           = array_merge( self::DEFAULTS, $job );
		$job['config'] = array_merge( self::CONFIG_DEFAULTS, (array) $job['config'] );
		return $job;
	}

	/** All jobs, each with every key present. */
	public static function all(): array {
		return array_map( array( __CLASS__, 'with_defaults' ), array_filter( self::stored(), 'is_array' ) );
	}

	/** One job with every key present, or null. */
	public static function get( string $id ): ?array {
		$jobs = self::stored();
		return isset( $jobs[ $id ] ) && is_array( $jobs[ $id ] ) ? self::with_defaults( $jobs[ $id ] ) : null;
	}

	public static function save( array $job ): void {
		// Keep records lean: an optional key at its default is left out and read back by get().
		foreach ( array_slice( array_keys( self::DEFAULTS ), 12 ) as $key ) {
			if ( array_key_exists( $key, $job ) && self::DEFAULTS[ $key ] === $job[ $key ] ) {
				unset( $job[ $key ] );
			}
		}
		$jobs               = self::stored();
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
		$jobs = self::stored();
		if ( isset( $jobs[ $id ] ) ) {
			self::remove_file( $jobs[ $id ] );
			unset( $jobs[ $id ] );
			update_option( self::OPTION, $jobs, false );
		}
	}

	/** Remove jobs and files older than the retention setting. */
	public static function sweep(): void {
		$jobs    = self::stored();
		$changed = false;
		$max_age = max( 1, (int) OPEX_Settings::get( 'retention_days' ) ) * DAY_IN_SECONDS;
		foreach ( $jobs as $id => $job ) {
			if ( time() - (int) ( $job['created'] ?? 0 ) > $max_age ) {
				self::remove_file( $job );
				unset( $jobs[ $id ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( self::OPTION, $jobs, false );
		}
	}

	/** Public job shape for the UI; excludes server paths. */
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

	/** Job record including full export config for load / re-run. */
	public static function expose_detail( array $job ): array {
		return array_merge(
			self::expose( $job ),
			array(
				'config' => $job['config'],
			)
		);
	}

	public static function file_path( array $job ): string {
		return trailingslashit( opex_export_dir() ) . $job['filename'];
	}

	/** Prevent two workers from processing the same job at once. */
	public static function acquire_lock( string $id ): bool {
		return OPEX_Lock::acquire( $id, self::LOCK_TTL );
	}

	/** Push the lock's expiry forward while a long batch runs. */
	public static function refresh_lock( string $id ): void {
		OPEX_Lock::refresh( $id, self::LOCK_TTL );
	}

	public static function release_lock( string $id ): void {
		OPEX_Lock::release( $id );
	}

	/** Delete the output file of a job that no longer has a record. */
	public static function discard_files( array $job ): void {
		self::remove_file( $job );
	}

	private static function remove_file( array $job ): void {
		$job  = self::with_defaults( $job );
		$path = self::file_path( $job );
		if ( ! $job['filename'] ) {
			return;
		}
		// The Excel writer keeps two sidecar files until it finishes.
		foreach ( array( $path, $path . '.rows', $path . '.rows.n' ) as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}
}
