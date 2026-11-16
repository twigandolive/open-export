<?php
/**
 * Job lifecycle: create() writes the file header and counts rows; each run()
 * processes exactly one batch and returns. The client paces run() calls, so
 * no PHP worker is held longer than one batch.
 */

defined( 'ABSPATH' ) || exit;

class OPEX_Exporter {

	/** Exception code for a worker that lost its lock. */
	private const LOCK_LOST = 7001;

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

	/**
	 * Create a job: count, open the file, write the format header.
	 *
	 * @param array  $config Sanitized export config.
	 * @param string $label  Name shown in the job list.
	 * @param array  $extra  Optional: mode, schedule_id, origin, delivery, since, delivery_name, notes.
	 * @return array|WP_Error The exposed job, or an error.
	 */
	public static function create( array $config, string $label, array $extra = array() ) {
		// On a network, decide once whether this job may reach shared data. See OPEX_Access::job_wide.
		$extra['wide'] = OPEX_Access::job_wide( (string) ( $config['source'] ?? '' ), $extra );
		if ( is_wp_error( $extra['wide'] ) ) {
			return $extra['wide'];
		}
		return OPEX_Access::scope( $extra['wide'], static function () use ( $config, $label, $extra ) {
			return self::start( $config, $label, $extra );
		} );
	}

	/**
	 * The body of create(), run inside the job's access scope.
	 *
	 * @param array  $config Sanitized export config.
	 * @param string $label  Name shown in the job list.
	 * @param array  $extra  See create().
	 * @return array|WP_Error The exposed job, or an error.
	 */
	private static function start( array $config, string $label, array $extra ) {
		$config = self::freeze_date_range( $config );
		$since  = self::clean_since( $extra['since'] ?? array(), $config );
		if ( is_wp_error( $since ) ) {
			return $since;
		}
		$origin  = in_array( $extra['origin'] ?? '', array( 'manual', 'cron', 'trigger', 'cli' ), true ) ? (string) $extra['origin'] : 'manual';
		$network = OPEX_Network::is_network( $config );
		$refused = $network ? OPEX_Network::check_create( $config, $origin ) : null;
		if ( $refused ) {
			return $refused;
		}
		$plan = array( 'sites' => array(), 'total' => 0, 'notes' => array() );
		try {
			if ( $network ) {
				$plan = OPEX_Network::plan( self::query_config( $config, $since ) );
			} else {
				$plan['total'] = OPEX_Query::count( self::query_config( $config, $since ) );
			}
			$total = $plan['total'];
			// Spread ACF columns are counted now, so every batch writes the same columns.
			$headers = self::headers( $config, $plan['notes'] );
		} catch ( Throwable $e ) {
			self::log_exception( $e );
			return new WP_Error( 'opex_db', self::public_error_message( $e ), array( 'status' => 500 ) );
		}

		$slug     = sanitize_file_name( sanitize_title( $label ) );
		$slug     = $slug ? $slug : 'export';
		$id       = 'j' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 12 );
		$filename = $slug . '-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 12, false ) . '.' . $config['format'];

		$mode     = 'background' === ( $extra['mode'] ?? '' ) ? 'background' : 'browser';
		$notes    = array_merge( OPEX_Query::export_notes( $config ), array_map( 'strval', array_values( (array) ( $extra['notes'] ?? array() ) ) ), $plan['notes'] );
		$delivery = self::clean_delivery( $extra['delivery'] ?? array(), $notes );

		$job = array(
			'id'            => $id,
			'label'         => $label,
			'config'        => $config,
			'headers'       => $headers,
			'status'        => 'running',
			'total'         => $total,
			'processed'     => 0,
			'cursor'        => 0,
			'created'       => time(),
			'filename'      => $filename,
			'filesize'      => 0,
			'error'         => '',
			'checkpoint'    => array(),
			'notes'         => $notes,
			'mode'          => $mode,
			'origin'        => $origin,
			'schedule_id'   => preg_match( '/^s[a-f0-9]{12}$/', (string) ( $extra['schedule_id'] ?? '' ) ) ? (string) $extra['schedule_id'] : '',
			'heartbeat'     => 'background' === $mode ? time() : 0,
			'delivery_name' => mb_substr( sanitize_text_field( (string) ( $extra['delivery_name'] ?? '' ) ), 0, 150 ),
			'delivery'      => $delivery,
			'since'         => $since,
			'sites'         => $plan['sites'],
			'site_index'    => 0,
			'wide'          => ! empty( $extra['wide'] ),
		);

		try {
			$writer = OPEX_Writer::make( $config['format'], OPEX_Jobs::file_path( $job ), $job['headers'], self::writer_options( $config ) );
			$writer->start();
			if ( 0 === $total ) {
				$writer->finish();
				$job             = self::completed( $job );
				$job['filesize'] = self::output_size( $job );
			}
			$job['checkpoint'] = $writer->checkpoint();
		} catch ( Throwable $e ) {
			self::log_exception( $e );
			return new WP_Error( 'opex_fs', self::public_error_message( $e ), array( 'status' => 500 ) );
		}

		OPEX_Jobs::save( $job );
		if ( 'done' === $job['status'] ) {
			do_action( 'opex_job_done', OPEX_Jobs::get( $id ) );
		}

		return OPEX_Jobs::expose( OPEX_Jobs::get( $id ) );
	}

	/**
	 * Column headers for a config: spread ACF columns in place of their field,
	 * and the site columns first on a network export.
	 *
	 * @param array    $config Export config.
	 * @param string[] $notes  Receives notes.
	 * @throws RuntimeException When the database reports an error.
	 */
	private static function headers( array $config, array &$notes = array() ): array {
		$headers = OPEX_Acf_Spread::headers( $config, self::header_map( $config['fields'], OPEX_Config::label_map( $config ) ), $notes );
		return OPEX_Network::is_network( $config ) ? OPEX_Network::with_columns( $headers ) : $headers;
	}

	/**
	 * Mark a job done and plan its deliveries. The caller saves it, so the
	 * status and the plan land in one write.
	 *
	 * @param array $job Job record.
	 */
	private static function completed( array $job ): array {
		$job['status']     = 'done';
		$job['finished']   = time();
		$job['deliveries'] = OPEX_Delivery::plan( (array) ( $job['delivery'] ?? array() ) );
		return $job;
	}

	/**
	 * Clean the delivery settings sent with a job. Nothing is stored unless a
	 * channel is on, so a job never carries a webhook secret for no reason.
	 *
	 * @param mixed    $raw   Raw delivery settings.
	 * @param string[] $notes Receives notes.
	 */
	private static function clean_delivery( $raw, array &$notes ): array {
		if ( ! is_array( $raw ) || ! $raw ) {
			return array();
		}
		$clean = OPEX_Delivery::sanitize( $raw, $notes );
		return OPEX_Delivery::plan( $clean ) ? $clean : array();
	}

	/**
	 * Check the "since" term of an incremental run.
	 *
	 * @param mixed $raw    Array with field and after (UTC Y-m-d H:i:s), or empty.
	 * @param array $config Export config.
	 * @return array|WP_Error Clean since, or array() for none.
	 */
	private static function clean_since( $raw, array $config ) {
		if ( ! is_array( $raw ) || ! $raw ) {
			return array();
		}
		$field = (string) ( $raw['field'] ?? '' );
		$after = (string) ( $raw['after'] ?? '' );
		$src   = OPEX_Query::parse_source( (string) ( $config['source'] ?? '' ) );
		$ok    = $src && isset( OPEX_Query::date_fields( $src )[ $field ] ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $after );
		if ( ! $ok ) {
			return new WP_Error( 'opex_bad_since', __( 'The incremental date field is not a date field of this source.', 'open-export' ), array( 'status' => 400 ) );
		}
		return array( 'field' => $field, 'after' => $after );
	}

	/**
	 * The config the query layer reads: it fetches the real fields (including
	 * those custom columns read) and carries the job's since term. This copy
	 * is never saved.
	 *
	 * @param array $config Job config.
	 * @param array $since  Job since term.
	 */
	private static function query_config( array $config, array $since = array() ): array {
		if ( is_array( $config['fields'] ?? null ) ) {
			$config['fields'] = OPEX_Config::fetch_fields( $config );
		}
		if ( $since ) {
			$config['since'] = $since;
		}
		return $config;
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
			return OPEX_Access::scope( ! empty( $job['wide'] ), static function () use ( $id, $resume ) {
				return self::run_locked( $id, $resume );
			} );
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
		$query  = self::query_config( $config, (array) $job['since'] );
		// A worker that lost its lock must stop at once: the new holder owns the job.
		$guard  = static function () use ( $id ): void {
			if ( ! OPEX_Jobs::refresh_lock( $id ) ) {
				throw new RuntimeException( 'The export lock was lost.', self::LOCK_LOST );
			}
		};

		try {
			$writer = OPEX_Writer::make( $config['format'], OPEX_Jobs::file_path( $job ), $job['headers'], self::writer_options( $config ) );
			// Undo anything a failed or interrupted batch left in the file.
			if ( $job['checkpoint'] ) {
				$writer->restore( (array) $job['checkpoint'] );
			}
			if ( OPEX_Network::is_network( $config ) ) {
				// Read from the site the job is on. The rows come back transformed, with the site columns.
				$batch = OPEX_Network::batch( $job, $query, $guard );
				$rows  = $batch['items'];
			} else {
				$batch = OPEX_Query::batch( $query, (int) $job['cursor'], (int) $config['batch_size'], $guard );
				$rows  = OPEX_Acf_Spread::output( $batch['items'], $config, $job['headers'] );
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
				$guard();
				$saved = self::save_progress( $id, $saved, array(
					'status'     => 'done',
					'total'      => max( (int) $saved['total'], (int) $saved['processed'] ),
					'filesize'   => self::output_size( $saved ),
					'finished'   => time(),
					'deliveries' => OPEX_Delivery::plan( (array) $saved['delivery'] ),
				) );
				if ( ! $saved ) {
					return self::missing();
				}
				do_action( 'opex_job_done', $saved );
			}
			return OPEX_Jobs::expose( $saved );
		} catch ( Throwable $e ) {
			if ( $e instanceof RuntimeException && self::LOCK_LOST === $e->getCode() ) {
				// A deleted job also loses its lock. Then its file goes too.
				if ( ! OPEX_Jobs::get( $id ) ) {
					OPEX_Jobs::discard_files( $job );
					return self::missing();
				}
				return new WP_Error(
					'opex_locked',
					__( 'This export is already running in another window.', 'open-export' ),
					array( 'status' => 409 )
				);
			}
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
		$notes = array();
		try {
			$headers = self::headers( $config, $notes );
			$batch   = OPEX_Query::batch( self::query_config( $config ), 0, 5 );
			$total   = OPEX_Query::count( $config );
		} catch ( Throwable $e ) {
			self::log_exception( $e );
			return new WP_Error( 'opex_db', self::public_error_message( $e ), array( 'status' => 500 ) );
		}

		// A network preview reads this site only, and says so.
		$network = OPEX_Network::is_network( $config );
		$items   = OPEX_Acf_Spread::output( $batch['items'], $config, $headers );
		$items   = $network ? OPEX_Network::with_site_columns( $items, get_current_blog_id() ) : $items;
		$rows    = array();
		foreach ( $items as $item ) {
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

		$out = array(
			'headers' => array_values( $headers ),
			'rows'    => $rows,
			'total'   => $total,
		);
		if ( $network ) {
			$notes[] = OPEX_Network::preview_note();
		}
		if ( $notes ) {
			$out['notes'] = $notes;
		}
		return $out;
	}
}
