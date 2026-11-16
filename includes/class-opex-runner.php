<?php
/**
 * Background work: runs export batches without a browser, restarts stalled
 * jobs, and handles the plugin's cron hooks.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Runs exports and deliveries from cron, without a browser.
 */
class OPEX_Runner {

	public const STALL_AFTER   = 600;
	private const DELIVER_LOCK = 300;
	private const HOOKS        = array( 'opex_run_schedule', 'opex_run_job', 'opex_deliver', 'opex_maintenance' );

	/** Add the cron callbacks and the hourly maintenance event. */
	public static function register(): void {
		add_action( 'opex_run_schedule', array( __CLASS__, 'on_schedule' ) );
		add_action( 'opex_run_job', array( __CLASS__, 'on_cron' ) );
		add_action( 'opex_deliver', array( __CLASS__, 'deliver' ) );
		add_action( 'opex_maintenance', array( __CLASS__, 'maintenance' ) );
		add_action( 'opex_job_done', array( __CLASS__, 'on_job_done' ), 20 );
		if ( ! wp_next_scheduled( 'opex_maintenance' ) ) {
			wp_schedule_event( time() + 60, 'hourly', 'opex_maintenance' );
		}
	}

	/** Clear every opex_* cron event (for deactivation). */
	public static function unregister(): void {
		$cron = _get_cron_array();
		foreach ( is_array( $cron ) ? $cron : array() as $ts => $hooks ) {
			foreach ( (array) $hooks as $hook => $events ) {
				if ( 0 !== strpos( (string) $hook, 'opex_' ) ) {
					continue;
				}
				foreach ( (array) $events as $event ) {
					wp_unschedule_event( (int) $ts, (string) $hook, (array) ( $event['args'] ?? array() ) );
				}
			}
		}
	}

	/**
	 * Cron entry for a schedule event.
	 *
	 * @param string $id Schedule id from the cron event.
	 */
	public static function on_schedule( $id ): void {
		OPEX_Schedules::start( (string) $id, 'cron' );
	}

	/**
	 * Run batches until done, errored, paused or out of time. At least one batch
	 * runs so a tiny budget still makes progress.
	 *
	 * @param string $job_id  Job id.
	 * @param int    $seconds Time budget in seconds.
	 * @return array|WP_Error Exposed job or error.
	 */
	public static function work( string $job_id, int $seconds = 20 ) {
		$deadline = microtime( true ) + max( 0, $seconds );
		$first    = true;
		while ( true ) {
			$job = OPEX_Jobs::get( $job_id );
			if ( ! $job ) {
				return new WP_Error( 'opex_missing', __( 'Export job not found.', 'open-export' ), array( 'status' => 404 ) );
			}
			// Check before each batch: run() turns a paused job back into running.
			if ( 'running' !== $job['status'] || ( ! $first && microtime( true ) >= $deadline ) ) {
				return OPEX_Jobs::expose( $job );
			}
			$first  = false;
			$result = OPEX_Exporter::run( $job_id );
			if ( is_wp_error( $result ) ) {
				if ( 'opex_locked' === $result->get_error_code() ) {
					$job = OPEX_Jobs::get( $job_id );
					return $job ? OPEX_Jobs::expose( $job ) : $result;
				}
				return $result;
			}
			self::stamp( $job_id );
		}
	}

	/**
	 * Records when the last batch ran, so a stalled background job can be spotted.
	 *
	 * @param string $job_id Job id.
	 */
	private static function stamp( string $job_id ): void {
		OPEX_Jobs::update(
			$job_id,
			static function ( array $job ): array {
				$job['heartbeat'] = time();
				return $job;
			}
		);
	}

	/**
	 * Schedule an immediate background run of a job, once.
	 *
	 * @param string $job_id Job id.
	 */
	public static function dispatch( string $job_id ): void {
		if ( ! wp_next_scheduled( 'opex_run_job', array( $job_id ) ) ) {
			wp_schedule_single_event( time(), 'opex_run_job', array( $job_id ) );
		}
		spawn_cron();
	}

	/**
	 * Cron entry for a job. Only background jobs run here.
	 *
	 * @param string $job_id Job id from the cron event.
	 */
	public static function on_cron( $job_id ): void {
		$job_id = (string) $job_id;
		$job    = OPEX_Jobs::get( $job_id );
		if ( ! $job || 'background' !== ( $job['mode'] ?? 'browser' ) ) {
			return;
		}
		$result = self::work( $job_id );
		if ( is_wp_error( $result ) ) {
			return;
		}
		if ( 'running' === $result['status'] ) {
			self::dispatch( $job_id );
		} elseif ( 'done' === $result['status'] && self::has_pending( OPEX_Jobs::get( $job_id ) ) && ! wp_next_scheduled( 'opex_deliver', array( $job_id ) ) ) {
			wp_schedule_single_event( time(), 'opex_deliver', array( $job_id ) );
		}
	}

	/** Hourly housekeeping. */
	public static function maintenance(): void {
		self::sweep_jobs();
		self::restart_stalled();
		self::rearm_schedules();
		self::process_deliveries();
	}

	/** Remove old jobs and their files. */
	private static function sweep_jobs(): void {
		OPEX_Jobs::sweep();
	}

	/** Restart background jobs whose last batch is over 10 minutes old. */
	private static function restart_stalled(): void {
		foreach ( OPEX_Jobs::all() as $id => $job ) {
			if ( 'background' !== ( $job['mode'] ?? '' ) || 'running' !== $job['status'] ) {
				continue;
			}
			$last = (int) ( $job['heartbeat'] ?? 0 );
			$last = $last > 0 ? $last : (int) $job['created'];
			if ( time() - $last > self::STALL_AFTER ) {
				self::dispatch( (string) $id );
			}
		}
	}

	/** Give enabled schedules that lost their cron event a new one. */
	private static function rearm_schedules(): void {
		foreach ( OPEX_Schedules::all() as $id => $s ) {
			if ( $s['enabled'] && 'manual' !== $s['frequency'] && ! wp_next_scheduled( 'opex_run_schedule', array( $id ) ) ) {
				OPEX_Schedules::refresh( $s );
			}
		}
	}

	/** Send deliveries that are still pending. */
	private static function process_deliveries(): void {
		if ( ! class_exists( 'OPEX_Delivery' ) ) {
			return;
		}
		foreach ( OPEX_Jobs::all() as $id => $job ) {
			if ( 'done' === $job['status'] && self::has_pending( $job ) ) {
				self::deliver( (string) $id );
			}
		}
	}

	/**
	 * Whether a job has a delivery item that can still be tried (new, or failed with attempts left).
	 *
	 * @param array|null $job Job record, or null when there is none.
	 * @return bool True when a delivery can still be tried.
	 */
	private static function has_pending( ?array $job ): bool {
		foreach ( (array) ( $job['deliveries'] ?? array() ) as $item ) {
			if ( in_array( $item['status'] ?? '', array( 'pending', 'failed' ), true ) && (int) ( $item['attempts'] ?? 0 ) < OPEX_Delivery::MAX_ATTEMPTS ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Hook for `opex_job_done`: queue the job's deliveries so they run soon,
	 * whatever mode the job ran in.
	 *
	 * @param array $job Finished job record.
	 */
	public static function on_job_done( $job ): void {
		$id = is_array( $job ) ? (string) ( $job['id'] ?? '' ) : '';
		if ( '' === $id || ! self::has_pending( $job ) || wp_next_scheduled( 'opex_deliver', array( $id ) ) ) {
			return;
		}
		wp_schedule_single_event( time(), 'opex_deliver', array( $id ) );
		spawn_cron();
	}

	/**
	 * Cron entry: run the pending deliveries of one job. A lock keeps cron and
	 * the command line from both sending. The job is read again under the lock,
	 * and the next try is queued for when the earliest failed item is due.
	 *
	 * @param string $job_id Job id from the cron event.
	 */
	public static function deliver( $job_id ): void {
		$job_id = (string) $job_id;
		if ( ! class_exists( 'OPEX_Delivery' ) || ! self::has_pending( OPEX_Jobs::get( $job_id ) ) ) {
			return;
		}
		$token = OPEX_Lock::acquire( 'deliver_' . $job_id, self::DELIVER_LOCK );
		if ( false === $token ) {
			return;
		}
		try {
			$job = OPEX_Jobs::get( $job_id );
			if ( ! $job || ! self::has_pending( $job ) ) {
				return;
			}
			$deliveries = OPEX_Delivery::process( $job, (array) ( $job['delivery'] ?? array() ) );
			if ( self::save_deliveries( $job_id, $deliveries ) ) {
				self::queue_retry( $job_id, $deliveries );
			}
		} finally {
			OPEX_Lock::release( 'deliver_' . $job_id, $token );
		}
	}

	/**
	 * Run a done job's pending and failed deliveries now, for the Retry button.
	 * It holds the same lock as deliver(), skips the wait between attempts,
	 * never sends an item marked sent, and never goes past the attempt limit.
	 *
	 * @param string $job_id Job id.
	 * @return array|WP_Error The exposed job, or an error.
	 */
	public static function deliver_now( string $job_id ) {
		$job = OPEX_Jobs::get( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'opex_missing', __( 'Export job not found.', 'open-export' ), array( 'status' => 404 ) );
		}
		if ( 'done' !== $job['status'] ) {
			return new WP_Error( 'opex_not_done', __( 'Deliveries run only after the export has finished.', 'open-export' ), array( 'status' => 409 ) );
		}
		$token = OPEX_Lock::acquire( 'deliver_' . $job_id, self::DELIVER_LOCK );
		if ( false === $token ) {
			return new WP_Error( 'opex_busy', __( 'These deliveries are being sent right now. Try again in a minute.', 'open-export' ), array( 'status' => 409 ) );
		}
		try {
			$job = OPEX_Jobs::get( $job_id );
			if ( $job && self::has_pending( $job ) ) {
				$deliveries = OPEX_Delivery::process( $job, (array) ( $job['delivery'] ?? array() ), true );
				if ( self::save_deliveries( $job_id, $deliveries ) ) {
					self::queue_retry( $job_id, $deliveries );
				}
			}
		} finally {
			OPEX_Lock::release( 'deliver_' . $job_id, $token );
		}
		$job = OPEX_Jobs::get( $job_id );
		return $job ? OPEX_Jobs::expose( $job ) : new WP_Error( 'opex_missing', __( 'Export job not found.', 'open-export' ), array( 'status' => 404 ) );
	}

	/**
	 * Store the delivery list on the job as it is stored now.
	 *
	 * @param string $job_id Job id.
	 * @param array  $deliveries   The job's deliveries after this pass.
	 * @return bool False when the job was deleted meanwhile.
	 */
	private static function save_deliveries( string $job_id, array $deliveries ): bool {
		$saved = OPEX_Jobs::update(
			$job_id,
			static function ( array $job ) use ( $deliveries ): array {
				$job['deliveries'] = $deliveries;
				return $job;
			}
		);
		return null !== $saved;
	}

	/**
	 * Schedule the next delivery attempt, if any item still has one coming.
	 *
	 * @param string $job_id Job id.
	 * @param array  $deliveries   The job's deliveries after this pass.
	 */
	private static function queue_retry( string $job_id, array $deliveries ): void {
		wp_clear_scheduled_hook( 'opex_deliver', array( $job_id ) );
		$next = OPEX_Delivery::next_attempt_at( $deliveries );
		if ( $next > 0 ) {
			wp_schedule_single_event( max( $next, time() + 1 ), 'opex_deliver', array( $job_id ) );
		}
	}
}
