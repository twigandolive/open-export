<?php
/**
 * Runs one batch of an export job: takes the job lock, reads the next rows,
 * appends them to the file and saves the cursor with the file checkpoint.
 *
 * Part of the export engine. Other code reaches it through OPEX_Exporter.
 *
 * @package Open_Export
 */

// No strict_types here on purpose: this file holds moved code, and strict types would change how arguments are coerced.

defined( 'ABSPATH' ) || exit;

/**
 * Runs one batch of a job while holding the job's lock.
 */
class OPEX_Export_Run {

	/** Exception code for a worker that lost its lock. */
	private const LOCK_LOST = 7001;

	/**
	 * Process one batch of an existing job.
	 *
	 * A paused job stays paused unless the caller passes $resume, so a late
	 * request cannot undo a pause. A failed job is retried from its saved cursor.
	 *
	 * @param string $id     Job id.
	 * @param bool   $resume True when a person asked to resume a paused job.
	 * @return array|WP_Error The job as the screen shows it, or an error.
	 * @throws OPEX_User_Error Only an unexpected one: the busy error of the store becomes a WP_Error.
	 */
	public static function run( string $id, bool $resume = false ) {
		$job = OPEX_Jobs::get( $id );
		if ( ! $job ) {
			return self::missing();
		}
		if ( 'done' === $job['status'] ) {
			return OPEX_Jobs::expose( $job );
		}
		if ( 'paused' === $job['status'] && ! $resume ) {
			return OPEX_Jobs::expose( $job );
		}

		if ( ! OPEX_Jobs::acquire_lock( $id ) ) {
			return self::locked();
		}
		// The job lock can expire while a slow batch still writes. The file lock
		// cannot, so a worker that took over an expired job lock stops here
		// instead of writing the same file. It is held across the restore, the
		// write, the finish and the save of the checkpoint.
		$path = OPEX_Jobs::file_path( $job );
		$file = OPEX_File_Lock::take( $path );
		if ( OPEX_File_Lock::BUSY === $file || ! OPEX_Jobs::refresh_lock( $id ) ) {
			OPEX_File_Lock::release( $file, $path, false );
			OPEX_Jobs::release_lock( $id );
			return self::locked();
		}
		$ended = false;
		try {
			// No file lock (a filesystem without flock, or a lock file that cannot
			// be opened) does not stop the run. Such a server must still be able
			// to export. The job lock is renewed during the batch and checked
			// around every write, so it still keeps a second worker out. The job
			// says so once, whichever way the file lock failed.
			if ( OPEX_File_Lock::UNSUPPORTED === $file || OPEX_File_Lock::UNAVAILABLE === $file ) {
				self::note_once( $id, __( 'This server cannot lock the export file, so only the job lock keeps two workers apart. Avoid running this export from two places at once.', 'open-export' ) );
			}
			$out   = OPEX_Access::scope(
				! empty( $job['wide'] ),
				static function () use ( $id, $resume ) {
					return self::run_locked( $id, $resume );
				}
			);
			$now   = OPEX_Jobs::get( $id );
			$ended = null === $now || 'done' === $now['status'];
			return $out;
		} catch ( OPEX_User_Error $e ) {
			if ( ! OPEX_Store::is_busy( $e ) ) {
				throw $e;
			}
			return new WP_Error( 'opex_busy', $e->getMessage(), array( 'status' => 503 ) );
		} finally {
			// A finished or deleted job needs no lock file. It goes while still held.
			OPEX_File_Lock::release( $file, $path, $ended );
			OPEX_Jobs::release_lock( $id );
		}
	}

	/**
	 * The error for a job another worker is running.
	 *
	 * @return WP_Error The 409 error.
	 */
	private static function locked(): WP_Error {
		return new WP_Error(
			'opex_locked',
			__( 'This export is already running in another window.', 'open-export' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Adds a note to a job unless it has it already.
	 *
	 * @param string $id   Job id.
	 * @param string $note The note.
	 * @return void
	 */
	private static function note_once( string $id, string $note ): void {
		OPEX_Jobs::update(
			$id,
			static function ( array $stored ) use ( $note ): ?array {
				if ( in_array( $note, (array) $stored['notes'], true ) ) {
					return null;
				}
				$stored['notes'] = array_merge( (array) $stored['notes'], array( $note ) );
				return $stored;
			}
		);
	}

	/**
	 * The batch itself. Runs only while this process holds the job's lock.
	 *
	 * @param string $id     Job id.
	 * @param bool   $resume True when a person asked to resume a paused job.
	 * @return array|WP_Error The job as the screen shows it, or an error.
	 */
	private static function run_locked( string $id, bool $resume ) {
		// Read again: the job may have changed while we waited for the lock.
		$job = OPEX_Jobs::get( $id );
		if ( ! $job ) {
			return self::missing();
		}
		if ( 'done' === $job['status'] || ( 'paused' === $job['status'] && ! $resume ) ) {
			return OPEX_Jobs::expose( $job );
		}
		if ( 'running' !== $job['status'] ) {
			$job = OPEX_Jobs::update(
				$id,
				static function ( array $stored ) use ( $resume ): ?array {
					if ( 'done' === $stored['status'] || ( 'paused' === $stored['status'] && ! $resume ) ) {
						return null;
					}
					$stored['status'] = 'running';
					$stored['error']  = '';
					return $stored;
				}
			);
			if ( ! $job ) {
				return self::missing();
			}
			if ( 'running' !== $job['status'] ) {
				return OPEX_Jobs::expose( $job );
			}
		}

		$config = $job['config'];
		$query  = OPEX_Exporter::query_config( $config, (array) $job['since'] );
		// A worker that lost its lock must stop at once: the new holder owns the job.
		$guard = static function () use ( $id ): void {
			if ( ! OPEX_Jobs::refresh_lock( $id ) ) {
				throw new RuntimeException( 'The export lock was lost.', self::LOCK_LOST ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal signal, caught below and never shown.
			}
		};

		try {
			$writer = OPEX_Writer::make( $config['format'], OPEX_Jobs::file_path( $job ), $job['headers'], OPEX_Exporter::writer_options( $config ) );
			// Undo anything a failed or interrupted batch left in the file.
			if ( $job['checkpoint'] ) {
				$writer->restore( (array) $job['checkpoint'] );
			}
			if ( OPEX_Network::is_network( $config ) ) {
				// Read from the site the job is on. The rows come back transformed, with the site columns.
				$batch = OPEX_Network::batch( $job, $query, $guard );
				$rows  = $batch['items'];
			} else {
				// A raw table's cursor may be digit text (zero, negative or very large keys). Kept as stored.
				$cursor = is_string( $job['cursor'] ) ? $job['cursor'] : (int) $job['cursor'];
				$batch  = OPEX_Query::batch( $query, $cursor, (int) $config['batch_size'], $guard );
				$rows   = OPEX_Acf_Spread::output( $batch['items'], $config, $job['headers'] );
			}
			$guard();
			$writer->append( $rows, 0 === (int) $job['processed'] );
			$guard();

			// Save the cursor and the file checkpoint together, before anything else can fail.
			// Moving to the next site lands in the same write, so a replay reads the same rows again.
			$changes = array(
				'cursor'     => $batch['cursor'],
				'processed'  => (int) $job['processed'] + count( $rows ),
				'checkpoint' => $writer->checkpoint(),
			);
			if ( ! empty( $batch['next_site'] ) ) {
				$changes['cursor']     = 0;
				$changes['site_index'] = (int) $job['site_index'] + 1;
			}
			if ( ! empty( $batch['note'] ) && ! in_array( $batch['note'], (array) $job['notes'], true ) ) {
				$changes['notes'] = array_merge( (array) $job['notes'], array( $batch['note'] ) );
			}
			$saved = self::save_progress( $id, $job, $changes );
			if ( ! $saved ) {
				return self::missing();
			}

			if ( $batch['done'] && empty( $batch['next_site'] ) ) {
				$guard();
				$writer->finish();
				$plain = $saved;
				// A scheduled job owes its schedule's bookkeeping from this same write (see OPEX_Schedule_Owed).
				$done = array(
					'status'        => 'done',
					'total'         => max( (int) $saved['total'], (int) $saved['processed'] ),
					'finished'      => time(),
					'deliveries'    => OPEX_Delivery::plan( (array) $saved['delivery'] ),
					'schedule_owed' => OPEX_Schedule_Owed::owed_by( $saved ),
				);
				// The zip gets its file name in the same save as the done status. Until
				// then the plain file stays, so a replay can finish and zip it again.
				if ( OPEX_Compress::wanted( $config ) ) {
					$done['filename'] = OPEX_Compress::apply( $saved );
				}
				$done['filesize'] = OPEX_Exporter::output_size( array_merge( $saved, $done ) );
				$guard();
				$saved = self::save_progress( $id, $saved, $done );
				if ( ! $saved ) {
					return self::missing();
				}
				if ( $plain['filename'] !== $saved['filename'] ) {
					OPEX_Compress::discard_plain( $plain );
				}
				// The job is saved as done. A listener that throws must not turn it into an error.
				OPEX_Exporter::announce_done( $saved );
			}
			return OPEX_Jobs::expose( $saved );
		} catch ( Throwable $e ) {
			if ( $e instanceof RuntimeException && self::LOCK_LOST === $e->getCode() ) {
				// A deleted job also loses its lock. Then its file goes too.
				if ( ! OPEX_Jobs::get( $id ) ) {
					OPEX_Jobs::discard_files( $job );
					return self::missing();
				}
				return self::locked();
			}
			OPEX_Exporter::log_exception( $e );
			$message = OPEX_Exporter::public_error_message( $e );
			$fresh   = OPEX_Jobs::update(
				$id,
				static function ( array $stored ) use ( $message ): array {
					$stored['status'] = 'error';
					$stored['error']  = $message;
					return $stored;
				}
			);
			if ( ! $fresh ) {
				OPEX_Jobs::discard_files( $job );
				return self::missing();
			}
			do_action( 'opex_job_failed', $fresh );
			return OPEX_Jobs::expose( $fresh );
		}
	}

	/**
	 * Merge changes into the stored job and save it. The job is read again
	 * first, so a pause that landed during the batch is kept and a job that
	 * was deleted stays deleted: its file is removed and null comes back.
	 *
	 * @param string $id      Job id.
	 * @param array  $job     The job as this batch saw it.
	 * @param array  $changes Keys to set.
	 * @return array|null The saved job, or null when the job was deleted.
	 */
	private static function save_progress( string $id, array $job, array $changes ): ?array {
		$fresh = OPEX_Jobs::update(
			$id,
			static function ( array $stored ) use ( $changes ): array {
				return array_merge( $stored, $changes );
			}
		);
		if ( ! $fresh ) {
			OPEX_Jobs::discard_files( $job );
			return null;
		}
		return $fresh;
	}

	/**
	 * The error for a job that does not exist (or no longer does).
	 *
	 * @return WP_Error The not found error.
	 */
	private static function missing(): WP_Error {
		return new WP_Error( 'opex_missing', __( 'Export job not found.', 'open-export' ), array( 'status' => 404 ) );
	}
}
