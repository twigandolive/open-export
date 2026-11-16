<?php
/**
 * Background work: runs export batches without a browser, restarts stalled
 * jobs, and handles the plugin's cron hooks.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class OPEX_Runner {

	private const STALL_AFTER = 600;
	private const HOOKS       = array( 'opex_run_schedule', 'opex_run_job', 'opex_deliver', 'opex_maintenance' );

	/** Add the cron callbacks and the hourly maintenance event. */
	public static function register(): void {
		add_action( 'opex_run_schedule', array( __CLASS__, 'on_schedule' ) );
		add_action( 'opex_run_job', array( __CLASS__, 'on_cron' ) );
		add_action( 'opex_deliver', array( __CLASS__, 'deliver' ) );
		add_action( 'opex_maintenance', array( __CLASS__, 'maintenance' ) );
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

	/** Cron entry for a schedule event. */
	public static function on_schedule( $id ): void {
		OPEX_Schedules::start( (string) $id, 'cron' );
	}

	/**
	 * Run batches until done, errored, paused or out of time. At least one batch
	 * runs so a tiny budget still makes progress.
	 *
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

	/** Record that a batch just ran. */
	private static function stamp( string $job_id ): void {
		$job = OPEX_Jobs::get( $job_id );
		if ( $job ) {
			$job['heartbeat'] = time();
			OPEX_Jobs::save( $job );
		}
	}

	/** Schedule an immediate background run of a job, once. */
	public static function dispatch( string $job_id ): void {
		if ( ! wp_next_scheduled( 'opex_run_job', array( $job_id ) ) ) {
			wp_schedule_single_event( time(), 'opex_run_job', array( $job_id ) );
		}
		spawn_cron();
	}

	/** Cron entry for a job. Only background jobs run here. */
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

	/** Whether a job has a delivery item that can still be tried. */
	private static function has_pending( ?array $job ): bool {
		foreach ( (array) ( $job['deliveries'] ?? array() ) as $item ) {
			if ( 'pending' === ( $item['status'] ?? '' ) && (int) ( $item['attempts'] ?? 0 ) < 3 ) {
				return true;
			}
		}
		return false;
	}

	/** Cron entry: run the pending deliveries of one job. */
	public static function deliver( $job_id ): void {
		$job_id = (string) $job_id;
		$job    = OPEX_Jobs::get( $job_id );
		if ( ! class_exists( 'OPEX_Delivery' ) || ! $job || ! self::has_pending( $job ) ) {
			return;
		}
		$list  = OPEX_Delivery::process( $job, (array) ( $job['delivery'] ?? array() ) );
		$fresh = OPEX_Jobs::get( $job_id );
		if ( $fresh ) {
			$fresh['deliveries'] = $list;
			OPEX_Jobs::save( $fresh );
		}
	}
}
