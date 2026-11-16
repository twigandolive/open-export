<?php
/**
 * Schedule bookkeeping that a finished run still owes: its last_run and
 * watermark could not be saved when it finished, because the store was busy.
 *
 * The job stays done and carries the mark "schedule_owed". The hourly
 * maintenance run, or the next start of the schedule, whichever comes
 * first, does the bookkeeping and clears the mark.
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
	 * Marks a done job as owing its schedule bookkeeping. When even that
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
