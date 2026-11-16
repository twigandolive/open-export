<?php
/**
 * Starts a schedule's run now: checks it may run, creates the job under the
 * start lock, stores the next run and hands a background job to the runner.
 *
 * Part of the schedules compartment. Other code reaches it through OPEX_Schedules.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Starts schedule runs.
 */
class OPEX_Schedule_Start {

	/** Seconds the start lock lives between renewals. */
	private const START_LOCK = 60;

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
		$s = OPEX_Schedules::get( $id );
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
		OPEX_Schedules::rearm( $id, $last_run );

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
		OPEX_Schedules::rearm(
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
}
