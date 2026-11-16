<?php
/**
 * Job lifecycle: create() writes the file header and counts rows, and each run()
 * processes exactly one batch and returns. The client paces run() calls, so
 * no PHP worker is held longer than one batch.
 *
 * The batch run itself lives in OPEX_Export_Run. Other code calls it through
 * this class.
 *
 * @package Open_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates, runs, pauses and previews export jobs: the front door of the export engine.
 */
class OPEX_Exporter {

	/**
	 * Validate a raw config and reduce it to the saved shape. See OPEX_Config.
	 *
	 * @param array    $raw   Config from the browser, a recipe or the command line.
	 * @param string[] $notes Receives a note for each value that was changed or dropped.
	 * @return array|WP_Error The clean config, or an error that says what is wrong.
	 */
	public static function sanitize_config( array $raw, array &$notes = array() ) {
		return OPEX_Config::sanitize_config( $raw, $notes );
	}

	/**
	 * Turn a relative date range into fixed dates. See OPEX_Config.
	 *
	 * @param array $config Clean config.
	 * @return array The config with fixed from and to dates.
	 */
	public static function freeze_date_range( array $config ): array {
		return OPEX_Config::freeze_date_range( $config );
	}

	/**
	 * Column headers for the chosen fields. See OPEX_Config.
	 *
	 * @param string[] $fields Field ids in output order.
	 * @param array[]  $custom Custom columns, each with an id and label.
	 * @return array<string, string> Unique column labels by field id.
	 */
	public static function header_map( array $fields, array $custom = array() ): array {
		return OPEX_Config::header_map( $fields, $custom );
	}

	/**
	 * The delimiter character for a delimiter key. See OPEX_Config.
	 *
	 * @param string $key Delimiter key: comma, semicolon or tab.
	 * @return string The delimiter character.
	 */
	public static function csv_delimiter_char( string $key ): string {
		return OPEX_Config::csv_delimiter_char( $key );
	}

	/**
	 * Writer options taken from a config. See OPEX_Config.
	 *
	 * @param array $config Clean config.
	 * @return array Options for OPEX_Writer::make().
	 */
	public static function writer_options( array $config ): array {
		return OPEX_Config::writer_options( $config );
	}

	/**
	 * Create a job: count, open the file, write the format header.
	 *
	 * @param array         $config Sanitized export config.
	 * @param string        $label  Name shown in the job list.
	 * @param array         $extra  Optional: mode, schedule_id, origin, delivery, since, delivery_name, notes, generation.
	 * @param callable|null $guard  Optional. Renews the caller's lock and returns false once it is lost.
	 *                              It runs while the rows are counted and once more right before the
	 *                              job is saved. When it returns false then, no job is saved.
	 * @return array|WP_Error The exposed job, or an error.
	 * @throws OPEX_User_Error Only an unexpected one: the busy error of the store becomes a WP_Error.
	 */
	public static function create( array $config, string $label, array $extra = array(), ?callable $guard = null ) {
		// On a network, decide once whether this job may reach shared data. See OPEX_Access::job_wide.
		$extra['wide'] = OPEX_Access::job_wide( (string) ( $config['source'] ?? '' ), $extra );
		if ( is_wp_error( $extra['wide'] ) ) {
			return $extra['wide'];
		}
		try {
			return OPEX_Access::scope(
				$extra['wide'],
				static function () use ( $config, $label, $extra, $guard ) {
					return self::start( $config, $label, $extra, $guard );
				}
			);
		} catch ( OPEX_User_Error $e ) {
			if ( ! OPEX_Store::is_busy( $e ) ) {
				throw $e;
			}
			return new WP_Error( 'opex_busy', $e->getMessage(), array( 'status' => 503 ) );
		}
	}

	/**
	 * The body of create(), run inside the job's access scope.
	 *
	 * @param array         $config Sanitized export config.
	 * @param string        $label  Name shown in the job list.
	 * @param array         $extra  See create().
	 * @param callable|null $guard  See create().
	 * @return array|WP_Error The exposed job, or an error.
	 * @throws OPEX_User_Error When the job could not be saved (see OPEX_Store::change).
	 */
	private static function start( array $config, string $label, array $extra, ?callable $guard = null ) {
		// The start time is the job's created time and the next watermark. It is
		// taken before any source query, so a row added while the rows are
		// counted is after the watermark and the next run still finds it.
		$started = time();
		$config  = self::freeze_date_range( $config );
		$since   = self::clean_since( $extra['since'] ?? array(), $config );
		if ( is_wp_error( $since ) ) {
			return $since;
		}
		$origin  = in_array( $extra['origin'] ?? '', array( 'manual', 'cron', 'trigger', 'cli' ), true ) ? (string) $extra['origin'] : 'manual';
		$network = OPEX_Network::is_network( $config );
		$refused = $network ? OPEX_Network::check_create( $config, $origin ) : null;
		if ( $refused ) {
			return $refused;
		}
		$plan = array(
			'sites' => array(),
			'total' => 0,
			'notes' => array(),
		);
		try {
			if ( $network ) {
				$plan = OPEX_Network::plan( self::query_config( $config, $since ), $guard );
			} else {
				$plan['total'] = OPEX_Query::count( self::query_config( $config, $since ) );
			}
			if ( null !== $guard ) {
				$guard(); // Renews the lock after a slow count. Whether it still holds is checked before the save.
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
			'created'       => $started,
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
			'generation'    => preg_match( '/^g[a-f0-9]{12}$/', (string) ( $extra['generation'] ?? '' ) ) ? (string) $extra['generation'] : '',
		);

		$plain = $job;
		try {
			// The HTML page takes its title from the export name.
			$options = self::writer_options( $config ) + array( 'title' => $label );
			$writer  = OPEX_Writer::make( $config['format'], OPEX_Jobs::file_path( $job ), $job['headers'], $options );
			$writer->start();
			if ( 0 === $total ) {
				$writer->finish();
				$job = self::completed( $job );
				if ( OPEX_Compress::wanted( $config ) ) {
					$job['filename'] = OPEX_Compress::apply( $job );
				}
				$job['filesize'] = self::output_size( $job );
			}
			$job['checkpoint'] = $writer->checkpoint();
		} catch ( Throwable $e ) {
			self::log_exception( $e );
			return new WP_Error( 'opex_fs', self::public_error_message( $e ), array( 'status' => 500 ) );
		}

		// A caller whose lock ran out while the rows were counted no longer
		// owns the start: another request may be starting the same thing.
		if ( null !== $guard && ! $guard() ) {
			OPEX_Jobs::discard_files( $job );
			OPEX_Jobs::discard_files( $plain );
			return new WP_Error( 'opex_busy', __( 'This export was started again elsewhere while it was being prepared.', 'open-export' ), array( 'status' => 409 ) );
		}
		try {
			OPEX_Jobs::save( $job );
		} catch ( OPEX_User_Error $e ) {
			// Nothing was saved, so no job will ever own the file.
			OPEX_Jobs::discard_files( $job );
			OPEX_Jobs::discard_files( $plain );
			throw $e;
		}
		if ( $plain['filename'] !== $job['filename'] ) {
			OPEX_Compress::discard_plain( $plain );
		}
		if ( 'done' === $job['status'] ) {
			self::announce_done( OPEX_Jobs::get( $id ) );
		}

		return OPEX_Jobs::expose( OPEX_Jobs::get( $id ) );
	}

	/**
	 * Fires `opex_job_done` for a job that is saved as done. A listener that
	 * throws cannot undo that: the job stays done and the error goes to the
	 * debug log, so a resume never runs a finished (and maybe zipped) job again.
	 *
	 * @param array|null $job The saved job.
	 * @return void
	 *
	 * @internal Shared with OPEX_Export_Run only.
	 */
	public static function announce_done( ?array $job ): void {
		if ( null === $job ) {
			return;
		}
		try {
			do_action( 'opex_job_done', $job );
		} catch ( Throwable $e ) {
			self::log_exception( $e );
		}
	}

	/**
	 * Column headers for a config: spread ACF columns in place of their field,
	 * and the site columns first on a network export.
	 *
	 * @param array    $config Export config.
	 * @param string[] $notes  Receives notes.
	 * @return array<string, string> Column labels by column key, in output order.
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
	 * @return array The job, marked done, with its deliveries planned.
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
	 * @return array The delivery settings to store, or an empty array when every channel is off.
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
		return array(
			'field' => $field,
			'after' => $after,
		);
	}

	/**
	 * The config the query layer reads: it fetches the real fields (including
	 * those custom columns read) and carries the job's since term. This copy
	 * is never saved.
	 *
	 * @param array $config Job config.
	 * @param array $since  Job since term.
	 * @return array The config for the query layer.
	 *
	 * @internal Shared with OPEX_Export_Run only.
	 */
	public static function query_config( array $config, array $since = array() ): array {
		if ( is_array( $config['fields'] ?? null ) ) {
			$config['fields'] = OPEX_Config::fetch_fields( $config );
		}
		if ( $since ) {
			$config['since'] = $since;
		}
		return $config;
	}

	/**
	 * Process one batch of an existing job. See OPEX_Export_Run.
	 *
	 * @param string $id     Job id.
	 * @param bool   $resume True when a person asked to resume a paused job.
	 * @return array|WP_Error The job as the screen shows it, or an error.
	 */
	public static function run( string $id, bool $resume = false ) {
		return OPEX_Export_Run::run( $id, $resume );
	}

	/**
	 * Create a new export job from a previous job's stored config.
	 *
	 * @param string $id Id of the job to copy.
	 * @return array|WP_Error The new job, or an error when the old one is gone.
	 */
	public static function rerun( string $id ) {
		$job = OPEX_Jobs::get( $id );
		if ( ! $job || empty( $job['config'] ) ) {
			return new WP_Error( 'opex_missing', __( 'Export job not found.', 'open-export' ), array( 'status' => 404 ) );
		}

		$label = sanitize_text_field( (string) ( $job['label'] ?? '' ) );
		$label = $label ? $label . ' (rerun)' : __( 'Export rerun', 'open-export' );

		return self::create( $job['config'], $label );
	}

	/**
	 * Pause a running job so it can be resumed later.
	 *
	 * @param string $id Id of the job to pause.
	 * @return array|WP_Error The job as the screen shows it, or an error when it is gone.
	 */
	public static function pause( string $id ) {
		$job = OPEX_Jobs::update(
			$id,
			static function ( array $stored ): ?array {
				if ( 'running' !== $stored['status'] ) {
					return null;
				}
				$stored['status'] = 'paused';
				return $stored;
			}
		);
		if ( ! $job ) {
			return new WP_Error( 'opex_missing', __( 'Export job not found.', 'open-export' ), array( 'status' => 404 ) );
		}
		return OPEX_Jobs::expose( $job );
	}

	/**
	 * Size of the finished export file, without PHP warnings if it vanished.
	 *
	 * @param array $job Job record.
	 * @return int Size of the file in bytes, or 0 when it is missing.
	 * @internal Shared with OPEX_Export_Run only.
	 */
	public static function output_size( array $job ): int {
		$path = OPEX_Jobs::file_path( $job );
		return file_exists( $path ) ? (int) filesize( $path ) : 0;
	}

	/**
	 * The error text a person sees and the job stores. Raw exception text
	 * never goes out, debug mode or not, because a PHP error can hold server
	 * paths. Only an OPEX_User_Error, written for people, passes through.
	 * Detail goes to the debug log (see log_exception).
	 *
	 * @param Throwable $e What stopped the export.
	 * @return string The message to show and store.
	 * @internal Shared with OPEX_Export_Run only.
	 */
	public static function public_error_message( Throwable $e ): string {
		if ( $e instanceof OPEX_User_Error ) {
			return $e->getMessage();
		}
		if ( false !== stripos( $e->getMessage(), 'export file' ) ) {
			return __( 'Could not write the export file. Check that the uploads directory is writable.', 'open-export' );
		}
		return __( 'Export failed. Try resuming or starting a new export.', 'open-export' );
	}

	/**
	 * Writes the exception detail to the PHP error log, only when WP_DEBUG and
	 * WP_DEBUG_LOG are both on.
	 *
	 * @internal Shared with OPEX_Export_Run, OPEX_Rest_Guard and OPEX_Runner only.
	 *
	 * @param Throwable $e What stopped the export.
	 * @return void
	 */
	public static function log_exception( Throwable $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- server-side detail, only with debug logging on.
			error_log( 'Open Export: ' . get_class( $e ) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
		}
	}

	/**
	 * First rows and total count for the preview step.
	 *
	 * @param array $config Clean config.
	 * @return array|WP_Error The first rows and the total count, or an error when the query fails.
	 */
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
