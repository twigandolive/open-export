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

	/** Lock owner tokens held by this process, by job id. */
	private static array $tokens = array();

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
		'wide'          => false,
	);

	/** Config keys an old record might lack, with their defaults. */
	public const CONFIG_DEFAULTS = array(
		'source'        => '',
		'fields'        => array(),
		'filters'       => array( 'relation' => 'AND', 'rules' => array() ),
		'date_range'    => array( 'field' => '', 'preset' => 'all_time', 'from' => '', 'to' => '' ),
		'format'        => 'csv',
		'batch_size'    => 500,
		'csv_delimiter' => 'comma',
		'column_labels' => array(),
		'csv_encoding'   => 'utf8_bom',
		'csv_eol'        => 'lf',
		'xml_template'   => array( 'header' => '', 'row' => '', 'footer' => '' ),
		'transforms'     => array(),
		'custom_columns' => array(),
		'network'        => false,
		'acf_flatten'    => array(),
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
		// The mode, origin and schedule are always written: they say where a job came from.
		foreach ( array_slice( array_keys( self::DEFAULTS ), 12 ) as $key ) {
			if ( in_array( $key, array( 'mode', 'origin', 'schedule_id' ), true ) ) {
				continue;
			}
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
		unset( self::$tokens[ $id ] );
		OPEX_Lock::force_release( $id );
		$jobs = self::stored();
		if ( isset( $jobs[ $id ] ) ) {
			self::remove_file( $jobs[ $id ] );
			unset( $jobs[ $id ] );
			update_option( self::OPTION, $jobs, false );
		}
	}

	/**
	 * Remove jobs and files older than the retention setting. The setting
	 * means "keep finished exports for N days", so age counts from when a job
	 * finished. A job that never finished (or a record from before finish
	 * times were saved) counts from when it was created.
	 */
	public static function sweep(): void {
		$jobs    = self::stored();
		$changed = false;
		$max_age = max( 1, (int) OPEX_Settings::get( 'retention_days' ) ) * DAY_IN_SECONDS;
		foreach ( $jobs as $id => $job ) {
			$since = (int) ( $job['finished'] ?? 0 ) > 0 ? (int) $job['finished'] : (int) ( $job['created'] ?? 0 );
			if ( time() - $since > $max_age ) {
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
			'mode'          => (string) ( $job['mode'] ?? 'browser' ),
			'origin'        => (string) ( $job['origin'] ?? 'manual' ),
			'schedule_id'   => (string) ( $job['schedule_id'] ?? '' ),
			'deliveries'    => self::expose_deliveries( (array) ( $job['deliveries'] ?? array() ) ),
			'notes'         => array_map( 'strval', array_values( (array) ( $job['notes'] ?? array() ) ) ),
			'delivery_name' => (string) ( $job['delivery_name'] ?? '' ),
			'finished'      => (int) ( $job['finished'] ?? 0 ),
			'heartbeat'     => (int) ( $job['heartbeat'] ?? 0 ),
			'schedule_name' => self::schedule_name( (string) ( $job['schedule_id'] ?? '' ) ),
			'source'        => (string) ( $job['config']['source'] ?? '' ),
			'source_label'  => class_exists( 'OPEX_Source_Labels' ) ? OPEX_Source_Labels::of( (string) ( $job['config']['source'] ?? '' ) ) : '',
			// A network job shows how many sites it reads, never the list itself.
			'sites'         => count( (array) ( $job['sites'] ?? array() ) ),
			'site_index'    => (int) ( $job['site_index'] ?? 0 ),
		);
	}

	/**
	 * Name of the schedule that started a job, or '' when there is none or it was deleted.
	 *
	 * @param string $id Schedule id.
	 */
	private static function schedule_name( string $id ): string {
		if ( '' === $id || ! class_exists( 'OPEX_Schedules' ) ) {
			return '';
		}
		$schedule = OPEX_Schedules::get( $id );
		return $schedule ? (string) $schedule['name'] : '';
	}

	/**
	 * The delivery list as people see it: channel, status, attempts, message and
	 * time. Nothing else is copied, so no setting can leak through it.
	 *
	 * @param array $items Stored delivery items.
	 */
	private static function expose_deliveries( array $items ): array {
		$out = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$out[] = array(
				'channel'  => (string) ( $item['channel'] ?? '' ),
				'status'   => (string) ( $item['status'] ?? 'pending' ),
				'attempts' => (int) ( $item['attempts'] ?? 0 ),
				'message'  => (string) ( $item['message'] ?? '' ),
				'time'     => (int) ( $item['time'] ?? 0 ),
			);
		}
		return $out;
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

	/**
	 * Prevent two workers from processing the same job at once. The owner
	 * token stays in this process, so refresh and release act only on a lock
	 * this process still owns.
	 */
	public static function acquire_lock( string $id ): bool {
		$token = OPEX_Lock::acquire( $id, self::LOCK_TTL );
		if ( false === $token ) {
			return false;
		}
		self::$tokens[ $id ] = $token;
		return true;
	}

	/**
	 * Push the lock's expiry forward while a long batch runs.
	 *
	 * @return bool False when the lock was lost to another worker.
	 */
	public static function refresh_lock( string $id ): bool {
		return isset( self::$tokens[ $id ] ) && OPEX_Lock::refresh( $id, self::$tokens[ $id ], self::LOCK_TTL );
	}

	/** Give the lock up. With no token held here (cleanup), remove it outright. */
	public static function release_lock( string $id ): void {
		if ( isset( self::$tokens[ $id ] ) ) {
			OPEX_Lock::release( $id, self::$tokens[ $id ] );
			unset( self::$tokens[ $id ] );
			return;
		}
		OPEX_Lock::force_release( $id );
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
