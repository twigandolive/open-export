<?php
/**
 * Job lifecycle: create() writes the file header and counts rows; each run()
 * processes exactly one batch and returns. The client paces run() calls, so
 * no PHP worker is held longer than one batch.
 */

defined( 'ABSPATH' ) || exit;

class OPEX_Exporter {

	/**
	 * Validate a raw config and reduce it to the saved shape. See OPEX_Config.
	 */
	public static function sanitize_config( array $raw, array &$notes = array() ) {
		return OPEX_Config::sanitize_config( $raw, $notes );
	}

	/** Turn a relative date range into fixed dates. See OPEX_Config. */
	public static function freeze_date_range( array $config ): array {
		return OPEX_Config::freeze_date_range( $config );
	}

	/** Column headers for the chosen fields. See OPEX_Config. */
	public static function header_map( array $fields, array $custom = array() ): array {
		return OPEX_Config::header_map( $fields, $custom );
	}

	/** The delimiter character for a delimiter key. See OPEX_Config. */
	public static function csv_delimiter_char( string $key ): string {
		return OPEX_Config::csv_delimiter_char( $key );
	}

	/** Writer options taken from a config. See OPEX_Config. */
	public static function writer_options( array $config ): array {
		return OPEX_Config::writer_options( $config );
	}

	/** Create a job: count, open the file, write the format header. */
	public static function create( array $config, string $label ) {
		$config = self::freeze_date_range( $config );
		try {
			$total = OPEX_Query::count( $config );
		} catch ( Throwable $e ) {
			self::log_exception( $e );
			return new WP_Error( 'opex_db', self::public_error_message( $e ), array( 'status' => 500 ) );
		}

		$slug     = sanitize_file_name( sanitize_title( $label ) );
		$slug     = $slug ? $slug : 'export';
		$id       = 'j' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 12 );
		$filename = $slug . '-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 12, false ) . '.' . $config['format'];

		$job = array(
			'id'         => $id,
			'label'      => $label,
			'config'     => $config,
			'headers'    => self::header_map( $config['fields'], $config['column_labels'] ?? array() ),
			'status'     => 'running',
			'total'      => $total,
			'processed'  => 0,
			'cursor'     => 0,
			'created'    => time(),
			'filename'   => $filename,
			'filesize'   => 0,
			'error'      => '',
			'checkpoint' => array(),
			'notes'      => OPEX_Query::export_notes( $config ),
		);

		try {
			$writer = OPEX_Writer::make( $config['format'], OPEX_Jobs::file_path( $job ), $job['headers'], self::writer_options( $config ) );
			$writer->start();
			if ( 0 === $total ) {
				$writer->finish();
				$job['status']   = 'done';
				$job['filesize'] = self::output_size( $job );
			}
			$job['checkpoint'] = $writer->checkpoint();
		} catch ( Throwable $e ) {
			self::log_exception( $e );
			return new WP_Error( 'opex_fs', self::public_error_message( $e ), array( 'status' => 500 ) );
		}

		OPEX_Jobs::save( $job );

		return OPEX_Jobs::expose( $job );
	}

	/**
	 * Process one batch of an existing job.
	 *
	 * A paused job stays paused unless the caller passes $resume, so a late
	 * request cannot undo a pause. A failed job is retried from its saved cursor.
	 *
	 * @param string $id     Job id.
	 * @param bool   $resume True when a person asked to resume a paused job.
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
			return new WP_Error(
				'opex_locked',
				__( 'This export is already running in another window.', 'open-export' ),
				array( 'status' => 409 )
			);
		}
		try {
			return self::run_locked( $id, $resume );
		} finally {
			OPEX_Jobs::release_lock( $id );
		}
	}

	/** The batch itself. Runs only while this process holds the job's lock. */
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
			$job['status'] = 'running';
			$job['error']  = '';
			OPEX_Jobs::save( $job );
		}

		$config = $job['config'];
		$tick   = static function () use ( $id ): void {
			OPEX_Jobs::refresh_lock( $id );
		};

		try {
			$writer = OPEX_Writer::make( $config['format'], OPEX_Jobs::file_path( $job ), $job['headers'], self::writer_options( $config ) );
			// Undo anything a failed or interrupted batch left in the file.
			if ( $job['checkpoint'] ) {
				$writer->restore( (array) $job['checkpoint'] );
			}
			$batch = OPEX_Query::batch( $config, (int) $job['cursor'], (int) $config['batch_size'], $tick );
			$writer->append( $batch['items'], 0 === (int) $job['processed'] );
			$tick();

			// Save the cursor and the file checkpoint together, before anything else can fail.
			$saved = self::save_progress( $id, $job, array(
				'cursor'     => $batch['cursor'],
				'processed'  => (int) $job['processed'] + count( $batch['items'] ),
				'checkpoint' => $writer->checkpoint(),
			) );
			if ( ! $saved ) {
				return self::missing();
			}

			if ( $batch['done'] ) {
				$writer->finish();
				$saved = self::save_progress( $id, $saved, array(
					'status'   => 'done',
					'total'    => max( (int) $saved['total'], (int) $saved['processed'] ),
					'filesize' => self::output_size( $saved ),
					'finished' => time(),
				) );
				if ( ! $saved ) {
					return self::missing();
				}
			}
			return OPEX_Jobs::expose( $saved );
		} catch ( Throwable $e ) {
			self::log_exception( $e );
			$fresh = OPEX_Jobs::get( $id );
			if ( ! $fresh ) {
				OPEX_Jobs::discard_files( $job );
				return self::missing();
			}
			$fresh['status'] = 'error';
			$fresh['error']  = self::public_error_message( $e );
			OPEX_Jobs::save( $fresh );
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
	 */
	private static function save_progress( string $id, array $job, array $changes ): ?array {
		$fresh = OPEX_Jobs::get( $id );
		if ( ! $fresh ) {
			OPEX_Jobs::discard_files( $job );
			return null;
		}
		$fresh = array_merge( $fresh, $changes );
		OPEX_Jobs::save( $fresh );
		return $fresh;
	}

	/** The error for a job that does not exist (or no longer does). */
	private static function missing(): WP_Error {
		return new WP_Error( 'opex_missing', __( 'Export job not found.', 'open-export' ), array( 'status' => 404 ) );
	}

	/** Create a new export job from a previous job's stored config. */
	public static function rerun( string $id ) {
		$job = OPEX_Jobs::get( $id );
		if ( ! $job || empty( $job['config'] ) ) {
			return new WP_Error( 'opex_missing', __( 'Export job not found.', 'open-export' ), array( 'status' => 404 ) );
		}

		$label = sanitize_text_field( (string) ( $job['label'] ?? '' ) );
		$label = $label ? $label . ' (rerun)' : __( 'Export rerun', 'open-export' );

		return self::create( $job['config'], $label );
	}

	/** Pause a running job so it can be resumed later. */
	public static function pause( string $id ) {
		$job = OPEX_Jobs::get( $id );
		if ( ! $job ) {
			return new WP_Error( 'opex_missing', __( 'Export job not found.', 'open-export' ), array( 'status' => 404 ) );
		}
		if ( 'running' === $job['status'] ) {
			$job['status'] = 'paused';
			OPEX_Jobs::save( $job );
		}
		return OPEX_Jobs::expose( $job );
	}

	/** Size of the finished export file, without PHP warnings if it vanished. */
	private static function output_size( array $job ): int {
		$path = OPEX_Jobs::file_path( $job );
		return file_exists( $path ) ? (int) filesize( $path ) : 0;
	}

	/** User-safe export error text; full detail is logged server-side. */
	private static function public_error_message( Throwable $e ): string {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return $e->getMessage();
		}
		if ( false !== stripos( $e->getMessage(), 'export file' ) ) {
			return __( 'Could not write the export file. Check that the uploads directory is writable.', 'open-export' );
		}
		return __( 'Export failed. Try resuming or starting a new export.', 'open-export' );
	}

	/** Write exception detail to the PHP error log when debugging is enabled. */
	private static function log_exception( Throwable $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional server-side logging when debug is on.
			error_log( 'Open Export: ' . $e->getMessage() );
		}
	}

	/** First rows and total count for the preview step. */
	public static function preview( array $config ) {
		$headers = self::header_map( $config['fields'], $config['column_labels'] ?? array() );
		try {
			$batch = OPEX_Query::batch( $config, 0, 5 );
			$total = OPEX_Query::count( $config );
		} catch ( Throwable $e ) {
			self::log_exception( $e );
			return new WP_Error( 'opex_db', self::public_error_message( $e ), array( 'status' => 500 ) );
		}

		$rows = array();
		foreach ( $batch['items'] as $item ) {
			$row = array();
			foreach ( array_keys( $headers ) as $field ) {
				$value = (string) ( $item[ $field ] ?? '' );
				if ( function_exists( 'mb_strimwidth' ) ) {
					$value = mb_strimwidth( $value, 0, 200, '…', 'UTF-8' );
				} elseif ( strlen( $value ) > 200 ) {
					$value = substr( $value, 0, 200 ) . '…';
				}
				$row[] = $value;
			}
			$rows[] = $row;
		}

		return array(
			'headers' => array_values( $headers ),
			'rows'    => $rows,
			'total'   => $total,
		);
	}
}
