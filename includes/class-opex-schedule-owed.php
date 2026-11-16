<?php
/**
 * Schedule bookkeeping that a finished run still owes: its last_run and
 * watermark are not saved yet.
 *
 * A scheduled job is saved as done with the mark "schedule_owed" in the
 * same write, so the debt cannot be lost even when every later write
 * fails. The mark is cleared only after the bookkeeping was saved. When
 * the bookkeeping at the end of the run could not be saved, the hourly
 * maintenance run, or the next start of the schedule, whichever comes
 * first, does it and clears the mark. Redoing it is safe: the watermark
 * never moves back and opex_job_done does not fire again.
 *
 * Part of the schedules compartment. Reached through OPEX_Schedules and OPEX_Runner.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Marks and settles owed schedule bookkeeping.
 */
class OPEX_Schedule_Owed {

	/**
	 * Whether a job that is being saved as done owes its schedule
	 * bookkeeping: true for every job that belongs to a schedule.
	 *
	 * @param array $job Job record.
	 * @return bool True for a scheduled job.
	 */
	public static function owed_by( array $job ): bool {
		return '' !== (string) ( $job['schedule_id'] ?? '' );
	}

	/**
	 * Clears the mark after the bookkeeping was saved. When the store is
	 * busy the mark stays, and settle() redoes the bookkeeping later, which
	 * is safe. The error goes to the debug log.
	 *
	 * @param string $job_id Job id.
	 * @return void
	 */
	public static function clear( string $job_id ): void {
		if ( '' === $job_id ) {
			return;
		}
		try {
			OPEX_Jobs::update(
				$job_id,
				static function ( array $stored ): ?array {
					if ( empty( $stored['schedule_owed'] ) ) {
						return null;
					}
					$stored['schedule_owed'] = false;
					return $stored;
				}
			);
		} catch ( OPEX_User_Error $e ) {
			OPEX_Exporter::log_exception( $e );
		}
	}

	/**
	 * Marks a done job as owing its schedule bookkeeping. Only for a record
	 * saved as done without the mark, by an older version. When even that
	 * cannot be saved, the error goes to the debug log.
	 *
	 * @param string $job_id Job id.
	 * @return void
	 */
	public static function mark( string $job_id ): void {
		if ( '' === $job_id ) {
			return;
		}
		try {
			OPEX_Jobs::update(
				$job_id,
				static function ( array $stored ): ?array {
					if ( 'done' !== $stored['status'] ) {
						return null;
					}
					$stored['schedule_owed'] = true;
					return $stored;
				}
			);
		} catch ( OPEX_User_Error $e ) {
			OPEX_Exporter::log_exception( $e );
		}
	}

	/**
	 * Does the bookkeeping of every marked done job: sets last_run and moves
	 * the watermark as that run would have, then clears the mark. A mark is
	 * cleared only after its bookkeeping was saved. opex_job_done does not
	 * fire again.
	 *
	 * @param string $schedule_id Only the jobs of this schedule, or '' for every schedule.
	 * @return void
	 * @throws OPEX_User_Error With the code BUSY when the store is busy. The mark stays for the next try.
	 */
	public static function settle( string $schedule_id = '' ): void {
		foreach ( OPEX_Jobs::all() as $job ) {
			if ( empty( $job['schedule_owed'] ) || 'done' !== $job['status'] ) {
				continue;
			}
			if ( '' !== $schedule_id && (string) $job['schedule_id'] !== $schedule_id ) {
				continue;
			}
			if ( '' !== (string) $job['schedule_id'] ) {
				OPEX_Schedules::book_done( $job, true );
			}
			OPEX_Jobs::update(
				(string) $job['id'],
				static function ( array $stored ): array {
					$stored['schedule_owed'] = false;
					return $stored;
				}
			);
		}
	}
}
