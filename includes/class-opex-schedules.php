<?php
/**
 * Saved export schedules. Stored in one non-autoloaded option. The next-run
 * math lives in OPEX_Schedule_Clock, the cron work in OPEX_Runner, and the
 * record defaults and input checks in OPEX_Schedule_Rules.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Reads, saves and starts schedules.
 */
class OPEX_Schedules {

	private const OPTION = 'opex_schedules';
	public const MAX     = 50;
	private const HOOK   = 'opex_run_schedule';

	/** Seconds the start lock lives between renewals. */
	private const START_LOCK = 60;

	/**
	 * Default values for every key of a schedule record. See OPEX_Schedule_Rules.
	 *
	 * @return array A schedule record with every key at its default.
	 */
	public static function defaults(): array {
		return OPEX_Schedule_Rules::defaults();
	}

	/**
	 * All schedules keyed by id, defaults filled.
	 *
	 * @return array Every schedule keyed by id.
	 */
	public static function all(): array {
		return self::filled( OPEX_Store::read( self::OPTION ) );
	}

	/**
	 * Every usable record of a stored registry, defaults filled.
	 *
	 * @param array $raw The option as stored.
	 * @return array Every usable schedule keyed by id.
	 */
	private static function filled( array $raw ): array {
		$out = array();
		foreach ( $raw as $id => $rec ) {
			if ( is_array( $rec ) ) {
				$out[ (string) $id ] = self::fill( $rec, (string) $id );
			}
		}
		return $out;
	}

	/**
	 * One schedule or null.
	 *
	 * @param string $id Schedule id.
	 * @return array|null The schedule, or null when there is none.
	 */
	public static function get( string $id ): ?array {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	/**
	 * Fill missing keys so an old or partial record never causes a notice.
	 *
	 * @param array  $rec Schedule record as stored.
	 * @param string $id  Id to use when the record has none.
	 * @return array The record with every key present.
	 */
	private static function fill( array $rec, string $id ): array {
		$def = self::defaults();
		$out = array_merge( $def, $rec );
		foreach ( array( 'incremental', 'last_run' ) as $k ) {
			$out[ $k ] = array_merge( $def[ $k ], is_array( $rec[ $k ] ?? null ) ? $rec[ $k ] : array() );
		}
		$delivery = is_array( $rec['delivery'] ?? null ) ? $rec['delivery'] : array();
		foreach ( $def['delivery'] as $ch => $d ) {
			$delivery[ $ch ] = array_merge( $d, is_array( $delivery[ $ch ] ?? null ) ? $delivery[ $ch ] : array() );
		}
		$out['delivery'] = $delivery;
		$out['config']   = is_array( $out['config'] ) ? $out['config'] : array();
		$out['id']       = '' !== (string) $out['id'] ? (string) $out['id'] : $id;
		return $out;
	}

	/**
	 * Changes one stored schedule under the registry lock, starting from the
	 * record as stored right now. A run finishing and a person editing the
	 * schedule at the same moment therefore both keep their changes.
	 *
	 * @param string   $id     Schedule id.
	 * @param callable $change Receives the filled record and returns the record to store.
	 * @return array|null The stored record, or null when there is no such schedule.
	 */
	private static function patch( string $id, callable $change ): ?array {
		return OPEX_Store::change(
			self::OPTION,
			static function ( array &$stored ) use ( $id, $change ): ?array {
				if ( ! isset( $stored[ $id ] ) || ! is_array( $stored[ $id ] ) ) {
					return null;
				}
				$stored[ $id ] = $change( self::fill( $stored[ $id ], $id ) );
				return $stored[ $id ];
			}
		);
	}

	/**
	 * Validate and save. Returns the record or a WP_Error.
	 *
	 * @param array    $raw   Untrusted input. Missing keys keep their stored value on update.
	 * @param string   $id    Existing schedule id, or '' to create.
	 * @param string[] $notes Receives what the config and delivery cleaning changed or dropped.
	 * @return array|WP_Error
	 */
	public static function save( array $raw, string $id = '', array &$notes = array() ) {
		$rec = OPEX_Store::change(
			self::OPTION,
			static function ( array &$stored ) use ( $raw, $id, &$notes ) {
				$all = self::filled( $stored );
				$rec = OPEX_Schedule_Rules::build( $all, $raw, $id, $notes );
				if ( is_array( $rec ) ) {
					$all[ $rec['id'] ] = $rec;
					$stored            = $all;
				}
				return $rec;
			}
		);
		if ( is_array( $rec ) ) {
			self::arm( $rec );
		}
		return $rec;
	}

	/**
	 * Clear the watermark, so the next incremental run exports every row again.
	 * The generation changes too, so a run still going cannot set it back when it ends.
	 *
	 * @param string $id Schedule id.
	 * @return array|null The saved record, or null when there is no such schedule.
	 */
	public static function reset_watermark( string $id ): ?array {
		return self::patch(
			$id,
			static function ( array $rec ): array {
				$rec['watermark']  = '';
				$rec['updated']    = time();
				$rec['generation'] = OPEX_Schedule_Rules::new_generation();
				return $rec;
			}
		);
	}

	/**
	 * Delete a schedule and its cron event.
	 *
	 * @param string $id Schedule id.
	 */
	public static function delete( string $id ): void {
		self::disarm( $id );
		OPEX_Store::change(
			self::OPTION,
			static function ( array &$stored ) use ( $id ): void {
				unset( $stored[ $id ] );
			}
		);
	}

	/**
	 * Public shape: no key, no webhook secret, plus the trigger URL.
	 *
	 * @param array $schedule Schedule record.
	 * @return array The schedule as the screen shows it.
	 */
	public static function expose( array $schedule ): array {
		$s   = self::fill( $schedule, (string) ( $schedule['id'] ?? '' ) );
		$key = (string) $s['trigger_key'];
		// The generation is internal bookkeeping, not a setting.
		unset( $s['trigger_key'], $s['wide'], $s['generation'] );
		$s['delivery']['webhook']['has_secret'] = '' !== (string) $s['delivery']['webhook']['secret'];
		unset( $s['delivery']['webhook']['secret'] );
		$s['trigger_url'] = add_query_arg( 'key', $key, rest_url( 'open-export/v1/schedules/' . $s['id'] . '/trigger' ) );
		return $s;
	}

	/**
	 * Next run as a UTC timestamp. See OPEX_Schedule_Clock.
	 *
	 * @param array             $schedule Schedule record.
	 * @param int               $now      Current time as a UTC timestamp.
	 * @param DateTimeZone|null $tz       Timezone to plan in. The site's timezone when null.
	 * @return int The next run as a UTC timestamp, or 0 when the schedule does not run by itself.
	 */
	public static function next_run( array $schedule, int $now, ?DateTimeZone $tz = null ): int {
		return OPEX_Schedule_Clock::next_run( $schedule, $now, $tz );
	}

	/**
	 * Schedule one cron event at next_run, replacing any existing one.
	 *
	 * @param array $schedule Schedule record, with its next_run set.
	 */
	public static function arm( array $schedule ): void {
		$id = (string) ( $schedule['id'] ?? '' );
		if ( '' === $id ) {
			return;
		}
		self::disarm( $id );
		$at = (int) ( $schedule['next_run'] ?? 0 );
		if ( $at > 0 ) {
			wp_schedule_single_event( $at, self::HOOK, array( $id ) );
		}
	}

	/**
	 * Remove the cron event for a schedule.
	 *
	 * @param string $id Schedule id.
	 */
	public static function disarm( string $id ): void {
		wp_clear_scheduled_hook( self::HOOK, array( $id ) );
	}

	/**
	 * Recompute next_run from now, store it and re-arm. Returns the record.
	 *
	 * @param array $schedule Schedule record.
	 * @return array The record with its new next_run.
	 */
	public static function refresh( array $schedule ): array {
		$saved = self::rearm( (string) ( $schedule['id'] ?? '' ), null );
		if ( null !== $saved ) {
			return $saved;
		}
		$schedule['next_run'] = self::next_run( $schedule, time() );
		return $schedule;
	}

	/**
	 * Stores a new next_run (and last_run when given) on the stored record, then arms it.
	 *
	 * @param string     $id       Schedule id.
	 * @param array|null $last_run New last_run, or null to keep the stored one.
	 * @return array|null The stored record, or null when there is no such schedule.
	 */
	private static function rearm( string $id, ?array $last_run ): ?array {
		$saved = self::patch(
			$id,
			static function ( array $rec ) use ( $last_run ): array {
				if ( null !== $last_run ) {
					$rec['last_run'] = $last_run;
				}
				$rec['next_run'] = self::next_run( $rec, time() );
				return $rec;
			}
		);
		if ( null !== $saved ) {
			self::arm( $saved );
		}
		return $saved;
	}

	/**
	 * Start a run now.
	 *
	 * @param string $id     Schedule id.
	 * @param string $origin 'cron', 'trigger', 'manual' or 'cli'.
	 * @return array|WP_Error Exposed job or error.
	 */
	public static function start( string $id, string $origin ) {
		// A run whose bookkeeping is still owed moves the watermark first, so
		// this run does not export its rows again.
		OPEX_Schedule_Owed::settle( $id );
		$s = self::get( $id );
		if ( ! $s ) {
			return new WP_Error( 'opex_missing', __( 'Schedule not found.', 'open-export' ), array( 'status' => 404 ) );
		}
		if ( 'cron' === $origin && ! $s['enabled'] ) {
			return new WP_Error( 'opex_disabled', __( 'This schedule is turned off.', 'open-export' ), array( 'status' => 409 ) );
		}
		// The check and the job creation happen under one lock, so two starts
		// (the trigger URL, cron, a person) cannot both see "idle". Counting the
		// rows can outlast the lock, so create() renews it as it goes and saves
		// no job when it was lost: another start owns the schedule then.
		$token = OPEX_Lock::acquire( 'sched_' . $id, self::START_LOCK );
		if ( false === $token ) {
			return self::busy();
		}
		$guard = static function () use ( $id, $token ): bool {
			return OPEX_Lock::refresh( 'sched_' . $id, $token, self::START_LOCK );
		};
		try {
			foreach ( OPEX_Jobs::all() as $job ) {
				if ( ( $job['schedule_id'] ?? '' ) === $id && 'running' === $job['status'] ) {
					return self::busy();
				}
			}
			$job = OPEX_Exporter::create( $s['config'], $s['name'], self::job_extra( $s, $origin ), $guard );
		} finally {
			OPEX_Lock::release( 'sched_' . $id, $token );
		}
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		// A job that finished inside create() already set last_run through
		// opex_job_done, so only a job still running writes it here.
		$last_run = null;
		if ( 'running' === $job['status'] ) {
			$last_run = array(
				'time'   => time(),
				'job_id' => (string) $job['id'],
				'status' => (string) $job['status'],
				'rows'   => (int) $job['processed'],
			);
		}
		self::rearm( $id, $last_run );

		if ( 'running' === $job['status'] ) {
			OPEX_Runner::dispatch( (string) $job['id'] );
		}
		return $job;
	}

	/**
	 * Records that a cron start of this schedule was given up after the store
	 * stayed busy, so its last run shows an error, and queues its next
	 * regular run.
	 *
	 * @param string $id Schedule id.
	 * @return void
	 * @throws OPEX_User_Error With the code BUSY when the store is still busy.
	 */
	public static function start_failed( string $id ): void {
		self::rearm(
			$id,
			array(
				'time'   => time(),
				'job_id' => '',
				'status' => 'error',
				'rows'   => 0,
			)
		);
	}

	/**
	 * The error for a schedule that is already running.
	 *
	 * @return WP_Error The error to return.
	 */
	private static function busy(): WP_Error {
		return new WP_Error( 'opex_busy', __( 'This schedule is still running.', 'open-export' ), array( 'status' => 409 ) );
	}

	/**
	 * What a schedule hands to OPEX_Exporter::create().
	 *
	 * @param array  $s      Schedule record.
	 * @param string $origin Who started it.
	 * @return array Extra job settings: mode, origin, delivery, file name, generation and since.
	 */
	private static function job_extra( array $s, string $origin ): array {
		$extra = array(
			'mode'          => 'background',
			'schedule_id'   => (string) $s['id'],
			'origin'        => in_array( $origin, array( 'manual', 'cron', 'trigger', 'cli' ), true ) ? $origin : 'manual',
			'delivery'      => $s['delivery'],
			'wide'          => ! empty( $s['wide'] ),
			'generation'    => (string) $s['generation'],
			'delivery_name' => OPEX_Filename::render(
				(string) $s['filename'],
				array(
					'label'  => $s['name'],
					'source' => (string) ( $s['config']['source'] ?? '' ),
					'id'     => (string) $s['id'],
				)
			),
		);
		$field = (string) $s['incremental']['field'];
		if ( $s['incremental']['enabled'] && '' !== $field && '' !== (string) $s['watermark'] ) {
			$extra['since'] = array(
				'field' => $field,
				'after' => (string) $s['watermark'],
			);
		}
		return $extra;
	}

	/**
	 * Hook for `opex_job_done`. Updates last_run and, on success, the watermark
	 * to the job's start time so rows changed during the run are picked up next time.
	 * Only a job of the schedule's current generation moves the watermark: a
	 * run that started before a reset or a change of source or date field
	 * leaves it alone.
	 *
	 * When the store is busy the job is marked as owing this bookkeeping
	 * instead, and OPEX_Schedule_Owed::settle() does it later. The listener
	 * does not throw then, so the other opex_job_done listeners still run.
	 *
	 * @param array $job Finished job record.
	 * @return void
	 * @throws OPEX_User_Error Only an unexpected one: the busy error of the store is handled here.
	 */
	public static function on_job_done( array $job ): void {
		$sid = (string) ( $job['schedule_id'] ?? '' );
		if ( '' === $sid ) {
			return;
		}
		try {
			self::book_done( $job, false );
		} catch ( OPEX_User_Error $e ) {
			if ( ! OPEX_Store::is_busy( $e ) ) {
				throw $e;
			}
			OPEX_Exporter::log_exception( $e );
			OPEX_Schedule_Owed::mark( (string) ( $job['id'] ?? '' ) );
		}
	}

	/**
	 * Sets last_run and moves the watermark for a finished job. See on_job_done().
	 *
	 * A late run of it (from OPEX_Schedule_Owed) leaves last_run alone when a
	 * newer run is shown there already. The watermark only ever moves
	 * forward, so a late run never moves it back past a newer run's.
	 *
	 * @param array $job  Finished job record.
	 * @param bool  $late True when the bookkeeping is done after the fact.
	 * @return void
	 * @throws OPEX_User_Error With the code BUSY when the store is busy.
	 *
	 * @internal Shared with OPEX_Schedule_Owed only.
	 */
	public static function book_done( array $job, bool $late ): void {
		self::patch(
			(string) $job['schedule_id'],
			static function ( array $s ) use ( $job, $late ): array {
				$shown = (string) $s['last_run']['job_id'];
				if ( ! $late || (string) ( $job['id'] ?? '' ) === $shown || (int) $s['last_run']['time'] < (int) ( $job['created'] ?? 0 ) ) {
					$s['last_run'] = self::last_run_of( $job );
				}
				if ( 'done' === ( $job['status'] ?? '' ) && (string) ( $job['generation'] ?? '' ) === (string) $s['generation'] ) {
					$mark = gmdate( 'Y-m-d H:i:s', (int) ( $job['created'] ?? time() ) );
					if ( $mark > (string) $s['watermark'] ) {
						$s['watermark'] = $mark;
					}
				}
				return $s;
			}
		);
	}

	/**
	 * Hook for `opex_job_failed`. Without it a failed scheduled run would show
	 * "Running" for good. The watermark stays, so the next run tries the same rows again.
	 *
	 * @param array $job Failed job record.
	 */
	public static function on_job_failed( $job ): void {
		$job = is_array( $job ) ? $job : array();
		$sid = (string) ( $job['schedule_id'] ?? '' );
		if ( '' === $sid ) {
			return;
		}
		self::patch(
			$sid,
			static function ( array $s ) use ( $job ): array {
				// An older run failing late must not hide a newer run.
				$current = (string) $s['last_run']['job_id'];
				if ( '' === $current || (string) ( $job['id'] ?? '' ) === $current ) {
					$s['last_run'] = array_merge( self::last_run_of( $job ), array( 'status' => 'error' ) );
				}
				return $s;
			}
		);
	}

	/**
	 * The last_run block for a job.
	 *
	 * @param array $job Job record.
	 * @return array The time, job id, status and row count of the run.
	 */
	private static function last_run_of( array $job ): array {
		return array(
			'time'   => (int) ( $job['created'] ?? time() ),
			'job_id' => (string) ( $job['id'] ?? '' ),
			'status' => (string) ( $job['status'] ?? '' ),
			'rows'   => (int) ( $job['processed'] ?? 0 ),
		);
	}
}
