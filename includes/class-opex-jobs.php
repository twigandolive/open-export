<?php
/**
 * Job persistence. A job is small (config + cursor + counters), so the whole
 * registry lives in one non-autoloaded option. History is capped, and files
 * older than the retention setting are swept by the hourly maintenance run.
 *
 * @package Open_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores job records in one option and guards each running job with a lock.
 */
class OPEX_Jobs {

	private const OPTION   = 'opex_jobs';
	private const LOCK_TTL = 120;

	/** Most jobs the history keeps. Running and paused jobs never count against it. */
	public const MAX_HISTORY = 200;

	/** What every file in the export folder ends with on disk (see disk_name). 2.0.0 files lack it. */
	public const DISK_SUFFIX = '.opex';

	/**
	 * Lock owner tokens held by this process, by job id.
	 *
	 * @var array<string, string>
	 */
	private static array $tokens = array();

	/**
	 * Every key a job record can have, with the value an older record gets
	 * when it lacks the key. The first twelve are the original record, and the
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
		'cursor'        => 0, // An int, or digit text for a raw table key that is zero, negative or too large.
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
		'generation'    => '',
		// True while the schedule's completion bookkeeping of this finished job could not be saved yet.
		'schedule_owed' => false,
	);

	/** Config keys an old record might lack, with their defaults. */
	public const CONFIG_DEFAULTS = array(
		'source'         => '',
		'fields'         => array(),
		'filters'        => array(
			'relation' => 'AND',
			'rules'    => array(),
		),
		'date_range'     => array(
			'field'  => '',
			'preset' => 'all_time',
			'from'   => '',
			'to'     => '',
		),
		'format'         => 'csv',
		'batch_size'     => 500,
		'csv_delimiter'  => 'comma',
		'column_labels'  => array(),
		'csv_encoding'   => 'utf8_bom',
		'csv_eol'        => 'lf',
		'xml_template'   => array(
			'header' => '',
			'row'    => '',
			'footer' => '',
		),
		'transforms'     => array(),
		'custom_columns' => array(),
		'network'        => false,
		'acf_flatten'    => array(),
		'csv_enclosure'  => 'double',
		'csv_quote_all'  => false,
		'compress'       => 'none',
	);

	/**
	 * The stored registry as the database holds it now, never a copy cached earlier in the request.
	 *
	 * @return array The jobs as stored, keyed by id.
	 */
	private static function stored(): array {
		return OPEX_Store::read( self::OPTION );
	}

	/**
	 * Fill every missing key of one record.
	 *
	 * @param array $job Job record as stored.
	 * @return array The job with every key present.
	 */
	private static function with_defaults( array $job ): array {
		$job           = array_merge( self::DEFAULTS, $job );
		$job['config'] = array_merge( self::CONFIG_DEFAULTS, (array) $job['config'] );
		return $job;
	}

	/**
	 * All jobs, each with every key present.
	 *
	 * @return array Every job keyed by id.
	 */
	public static function all(): array {
		return array_map( array( __CLASS__, 'with_defaults' ), array_filter( self::stored(), 'is_array' ) );
	}

	/**
	 * One job with every key present, or null.
	 *
	 * @param string $id Job id.
	 * @return array|null The job, or null when there is none.
	 */
	public static function get( string $id ): ?array {
		$jobs = self::stored();
		return isset( $jobs[ $id ] ) && is_array( $jobs[ $id ] ) ? self::with_defaults( $jobs[ $id ] ) : null;
	}

	/**
	 * Saves one job record. Other jobs are read fresh under the registry lock, so another
	 * process's changes to them are kept. To change a job that may have changed
	 * meanwhile, use update().
	 *
	 * @param array $job Job record.
	 */
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
		OPEX_Store::change(
			self::OPTION,
			static function ( array &$jobs ) use ( $job ): void {
				$jobs[ $job['id'] ] = $job;
				$jobs               = self::trim_history( $jobs, (string) $job['id'] );
			}
		);
	}

	/**
	 * Changes one stored job under the registry lock. The callback gets the
	 * job as stored right now, so a pause, a delete or a finish that another
	 * process wrote is never undone by an older copy.
	 *
	 * @param string   $id     Job id.
	 * @param callable $change Receives the job with defaults and returns the
	 *                         job to save, or null to leave it as it is.
	 * @return array|null The job as stored afterward, or null when it does not exist.
	 */
	public static function update( string $id, callable $change ): ?array {
		$current = null;
		$next    = null;
		OPEX_Store::change(
			self::OPTION,
			static function ( array &$jobs ) use ( $id, $change, &$current, &$next ): void {
				if ( ! isset( $jobs[ $id ] ) || ! is_array( $jobs[ $id ] ) ) {
					return;
				}
				$current = self::with_defaults( $jobs[ $id ] );
				$next    = $change( $current );
				if ( is_array( $next ) ) {
					self::save( $next );
				}
			}
		);
		if ( null === $current ) {
			return null;
		}
		return is_array( $next ) ? self::with_defaults( $next ) : $current;
	}

	/**
	 * Removes a job, its file and its lock.
	 *
	 * @param string $id Job id.
	 */
	public static function delete( string $id ): void {
		unset( self::$tokens[ $id ] );
		OPEX_Lock::force_release( $id );
		OPEX_Store::change(
			self::OPTION,
			static function ( array &$jobs ) use ( $id ): void {
				if ( isset( $jobs[ $id ] ) ) {
					self::remove_file( (array) $jobs[ $id ] );
					unset( $jobs[ $id ] );
				}
			}
		);
	}

	/**
	 * Remove jobs and files older than the retention setting. The setting
	 * means "keep finished exports for N days", so age counts from when a job
	 * finished. A job that never finished (or a record from before finish
	 * times were saved) counts from when it was created.
	 */
	public static function sweep(): void {
		$max_age = max( 1, (int) OPEX_Settings::get( 'retention_days' ) ) * DAY_IN_SECONDS;
		OPEX_Store::change(
			self::OPTION,
			static function ( array &$jobs ) use ( $max_age ): void {
				foreach ( $jobs as $id => $job ) {
					$job   = (array) $job;
					$since = (int) ( $job['finished'] ?? 0 ) > 0 ? (int) $job['finished'] : (int) ( $job['created'] ?? 0 );
					if ( time() - $since > $max_age ) {
						self::remove_file( $job );
						unset( $jobs[ $id ] );
					}
				}
			}
		);
	}

	/**
	 * Public job shape for the UI. It leaves out server paths.
	 *
	 * @param array $job Job record.
	 * @return array The job as the screen shows it.
	 */
	public static function expose( array $job ): array {
		return array(
			'id'            => $job['id'],
			'label'         => $job['label'],
			'format'        => $job['config']['format'],
			'status'        => $job['status'],
			'total'         => (int) $job['total'],
			'processed'     => (int) $job['processed'],
			'created'       => (int) $job['created'],
			// Callers have always read a name with the real extension here, so the
			// on-disk ".opex" ending stays inside the stored record.
			'filename'      => self::real_name( (string) $job['filename'] ),
			'filesize'      => (int) ( $job['filesize'] ?? 0 ),
			'error'         => (string) ( $job['error'] ?? '' ),
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
	 * @return string The schedule name, or ''.
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
	 * @return array[] One entry per delivery, safe to show.
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

	/**
	 * Trims the history to MAX_HISTORY, oldest first, removing files with
	 * their jobs. A running or paused job is never removed to make room,
	 * because someone is still waiting on it, and neither is the job being
	 * saved. When every job is still in progress the list may stay over the
	 * cap until some finish.
	 *
	 * @param array  $jobs Stored jobs by id.
	 * @param string $keep Id of the job being saved.
	 * @return array
	 */
	private static function trim_history( array $jobs, string $keep ): array {
		if ( count( $jobs ) <= self::MAX_HISTORY ) {
			return $jobs;
		}
		$ended = array();
		foreach ( $jobs as $id => $job ) {
			$status = is_array( $job ) ? (string) ( $job['status'] ?? 'running' ) : '';
			if ( $keep !== (string) $id && ! in_array( $status, array( 'running', 'paused' ), true ) ) {
				$ended[ $id ] = is_array( $job ) ? (int) ( $job['created'] ?? 0 ) : 0;
			}
		}
		asort( $ended );
		foreach ( array_keys( $ended ) as $id ) {
			if ( count( $jobs ) <= self::MAX_HISTORY ) {
				break;
			}
			if ( is_array( $jobs[ $id ] ) ) {
				self::remove_file( $jobs[ $id ] );
			}
			unset( $jobs[ $id ] );
		}
		return $jobs;
	}

	/**
	 * Job record including full export config for load / re-run.
	 *
	 * @param array $job Job record.
	 * @return array The job as the screen shows it, plus its config.
	 */
	public static function expose_detail( array $job ): array {
		return array_merge(
			self::expose( $job ),
			array(
				'config' => $job['config'],
			)
		);
	}

	/**
	 * Full path of a job's file.
	 *
	 * @param array $job Job record.
	 * @return string Full path of the export file.
	 */
	public static function file_path( array $job ): string {
		return trailingslashit( opex_export_dir() ) . $job['filename'];
	}

	/**
	 * The on-disk name of a file Open Export writes into the export folder.
	 * nginx in front of Apache serves known endings such as .html and .zip
	 * from disk, so the deny rule never runs. It passes an unknown ending to
	 * Apache, which applies the deny rule.
	 *
	 * @param string $name Name with its real ending, such as "orders-a1b2.csv".
	 * @return string The name to save on disk, such as "orders-a1b2.csv.opex".
	 */
	public static function disk_name( string $name ): string {
		return $name . self::DISK_SUFFIX;
	}

	/**
	 * A name or path without the on-disk ending, so its real ending shows.
	 * A 2.0.0 name has no such ending and comes back unchanged.
	 *
	 * @param string $name On-disk name or path.
	 * @return string The name or path with its real ending last.
	 */
	public static function real_name( string $name ): string {
		$len = strlen( self::DISK_SUFFIX );
		return strlen( $name ) > $len && self::DISK_SUFFIX === substr( $name, -$len ) ? substr( $name, 0, -$len ) : $name;
	}

	/**
	 * A work file next to an export file, such as the Excel rows or the lock:
	 * "orders.csv.rows.opex" for "orders.csv.opex". A 2.0.0 file keeps 2.0.0
	 * work file names, so a job running across the update finds its rows.
	 *
	 * @param string $path Export file path (or name).
	 * @param string $tag  What the work file adds, such as ".rows".
	 * @return string The work file path (or name).
	 */
	public static function beside( string $path, string $tag ): string {
		$real = self::real_name( $path );
		return $real === $path ? $path . $tag : self::disk_name( $real . $tag );
	}

	/**
	 * Prevent two workers from processing the same job at once. The owner
	 * token stays in this process, so refresh and release act only on a lock
	 * this process still owns.
	 *
	 * @param string $id Job id.
	 * @return bool True when this process now holds the lock.
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
	 * @param string $id Job id.
	 * @return bool False when the lock was lost to another worker.
	 */
	public static function refresh_lock( string $id ): bool {
		return isset( self::$tokens[ $id ] ) && OPEX_Lock::refresh( $id, self::$tokens[ $id ], self::LOCK_TTL );
	}

	/**
	 * Give the lock up. With no token held here (cleanup), remove it outright.
	 *
	 * @param string $id Job id.
	 */
	public static function release_lock( string $id ): void {
		if ( isset( self::$tokens[ $id ] ) ) {
			OPEX_Lock::release( $id, self::$tokens[ $id ] );
			unset( self::$tokens[ $id ] );
			return;
		}
		OPEX_Lock::force_release( $id );
	}

	/**
	 * Delete the output file of a job that no longer has a record.
	 *
	 * @param array $job Job record whose file should go.
	 */
	public static function discard_files( array $job ): void {
		self::remove_file( $job );
	}

	/**
	 * Deletes a job's file and any sidecar a writer or the zip step left.
	 *
	 * @param array $job Job record.
	 */
	private static function remove_file( array $job ): void {
		$job  = self::with_defaults( $job );
		$path = self::file_path( $job );
		if ( ! $job['filename'] ) {
			return;
		}
		// The Excel writer keeps its work files until it finishes, and a crash can leave them.
		$files = array_merge( array( $path ), OPEX_Writer_XLSX::work_files( $path ) );
		// Zip compression leaves the plain file next to the zip if it stops
		// before the plain file is removed, and a half-built zip if it stops sooner.
		$files = array_merge( $files, OPEX_Compress::related_files( $path ) );
		// The lock file a batch keeps next to the plain file (see OPEX_File_Lock).
		foreach ( array_merge( array( $path ), OPEX_Compress::related_files( $path ) ) as $file ) {
			$files[] = OPEX_File_Lock::path( $file );
		}
		foreach ( $files as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}
}
