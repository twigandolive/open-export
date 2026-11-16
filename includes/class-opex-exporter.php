<?php
/**
 * Job lifecycle: create() writes the file header and counts rows; each run()
 * processes exactly one batch and returns. The client paces run() calls, so
 * no PHP worker is held longer than one batch.
 */

defined( 'ABSPATH' ) || exit;

class OPEX_Exporter {

	private const FORMATS = array( 'csv', 'json', 'xml' );

	/**
	 * Validate a raw config from the client and reduce it to exactly the
	 * shape the query layer expects. Returns WP_Error on bad input.
	 */
	public static function sanitize_config( array $raw, array &$notes = array() ) {
		$source = sanitize_text_field( self::text( $raw['source'] ?? '' ) );
		$src    = OPEX_Query::parse_source( $source );
		if ( ! $src ) {
			return new WP_Error( 'opex_bad_source', __( 'Unknown data source.', 'open-export' ), array( 'status' => 400 ) );
		}

		$fields = array();

		foreach ( array_slice( (array) ( $raw['fields'] ?? array() ), 0, 300 ) as $field ) {
			$field = self::text( $field );
			if ( ! OPEX_Query::is_field_id( $field ) ) {
				$notes[] = sprintf(
					/* translators: %s: the rejected field id. */
					__( 'Field "%s" was ignored: it is not a recognised field id.', 'open-export' ),
					$field
				);
				continue;
			}
			if ( preg_match( '/^col:(.+)$/', $field, $matches ) && OPEX_Query::is_forbidden_column( $matches[1] ) ) {
				$notes[] = sprintf(
					/* translators: %s: the rejected field id. */
					__( 'Field "%s" was ignored: that column can never be exported.', 'open-export' ),
					$field
				);
				continue;
			}
			if ( 0 === strpos( $field, 'meta:' ) && OPEX_Query::is_credential_meta( substr( $field, 5 ) ) ) {
				$notes[] = sprintf(
					/* translators: %s: the rejected field id. */
					__( 'Field "%s" was ignored: login credentials can never be exported.', 'open-export' ),
					$field
				);
				continue;
			}
			// A raw table has no meta, no taxonomies and no computed values, so
			// asking for them used to produce silent empty columns.
			if ( 'table' === $src['type'] && 0 !== strpos( $field, 'col:' ) ) {
				$notes[] = sprintf(
					/* translators: %s: the rejected field id. */
					__( 'Field "%s" was ignored: a raw database table only has its own columns.', 'open-export' ),
					$field
				);
				continue;
			}
			if ( ! OPEX_Query::is_valid_field( $src, $field ) ) {
				$notes[] = sprintf(
					/* translators: %s: the rejected field id. */
					__( 'Field "%s" was ignored: this source does not have it.', 'open-export' ),
					$field
				);
				continue;
			}
			$fields[] = $field;
		}
		$fields = array_values( array_unique( $fields ) );
		if ( ! $fields ) {
			return new WP_Error( 'opex_no_fields', __( 'Pick at least one field to export.', 'open-export' ), array( 'status' => 400 ) );
		}

		$filters = array( 'relation' => 'AND', 'rules' => array() );
		$raw_f   = (array) ( $raw['filters'] ?? array() );
		$filters['relation'] = OPEX_Query::normalize_relation( $raw_f['relation'] ?? 'AND' );
		foreach ( array_slice( (array) ( $raw_f['rules'] ?? array() ), 0, 20 ) as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$rule_field = sanitize_text_field( self::text( $rule['field'] ?? '' ) );
			$rule_op    = sanitize_key( self::text( $rule['op'] ?? 'eq' ) );

			// A rule on a field this source does not have would be dropped at
			// query time, and the export would quietly widen. Refuse it instead.
			if ( ! OPEX_Query::is_filterable_field( $src, $rule_field ) ) {
				return new WP_Error(
					'opex_bad_filter',
					sprintf(
						/* translators: %s: the field id a filter rule names. */
						__( 'The filter uses a field this source cannot filter on: "%s". Remove the rule or pick another field.', 'open-export' ),
						$rule_field
					),
					array( 'status' => 400 )
				);
			}

			if ( ! OPEX_Query::is_valid_op( $rule_op ) ) {
				$notes[] = sprintf(
					/* translators: 1: the operator, 2: the field it was used on. */
					__( 'Filter operator "%1$s" on "%2$s" is not supported and was dropped.', 'open-export' ),
					$rule_op,
					$rule_field
				);
				continue;
			}

			$filters['rules'][] = array(
				'field' => $rule_field,
				'op'    => $rule_op,
				'value' => self::text( $rule['value'] ?? '' ),
			);
		}

		$raw_range  = (array) ( $raw['date_range'] ?? array() );
		$date_range = self::sanitize_date_range( $src, $raw_range );

		$format = in_array( $raw['format'] ?? '', self::FORMATS, true ) ? $raw['format'] : 'csv';
		// Anything that is not a number falls back to the default size.
		$batch_raw = $raw['batch_size'] ?? 500;
		$batch     = max( 10, min( 5000, is_numeric( $batch_raw ) ? (int) $batch_raw : 500 ) );

		$delim_key = sanitize_key( self::text( $raw['csv_delimiter'] ?? 'comma' ) );
		if ( ! in_array( $delim_key, array( 'comma', 'semicolon', 'tab' ), true ) ) {
			$delim_key = 'comma';
		}

		if ( ! $date_range['field'] && ! empty( $raw_range ) && 'all_time' !== self::text( $raw_range['preset'] ?? 'all_time' ) ) {
			$notes[] = __( 'The date range was dropped: this source has no date column to filter on.', 'open-export' );
		}

		$labels = array();
		foreach ( (array) ( $raw['column_labels'] ?? array() ) as $field_id => $label ) {
			$field_id = (string) $field_id;
			if ( ! in_array( $field_id, $fields, true ) ) {
				continue;
			}
			$label = sanitize_text_field( self::text( $label ) );
			if ( '' !== $label ) {
				$labels[ $field_id ] = $label;
			}
		}

		return array(
			'source'        => $source,
			'fields'        => $fields,
			'filters'       => $filters,
			'date_range'    => $date_range,
			'format'        => $format,
			'batch_size'    => $batch,
			'csv_delimiter' => $delim_key,
			'column_labels' => $labels,
		);
	}

	/** A scalar as text. Anything else (an array in a bad request) becomes ''. */
	private static function text( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Validate the date range. The field is checked against the source's own
	 * allowlist of real date columns, so nothing caller-supplied can reach SQL;
	 * an unrecognised field simply disables the range rather than failing the
	 * export. Relative presets are stored unresolved so a saved template still
	 * means "last 30 days" the next time it runs, not the 30 days it meant when
	 * it was saved.
	 */
	private static function sanitize_date_range( array $src, array $raw ): array {
		$empty = array( 'field' => '', 'preset' => 'all_time', 'from' => '', 'to' => '' );

		$preset = sanitize_key( self::text( $raw['preset'] ?? 'all_time' ) );
		if ( ! in_array( $preset, OPEX_Dates::PRESETS, true ) ) {
			$preset = 'all_time';
		}
		if ( 'all_time' === $preset ) {
			return $empty;
		}

		// Fall back to the source's primary date column rather than silently
		// exporting everything. A source with no date column has no range.
		$field = OPEX_Query::date_field_or_default( $src, sanitize_text_field( self::text( $raw['field'] ?? '' ) ) );
		if ( '' === $field ) {
			return $empty;
		}

		$from = 'custom' === $preset ? OPEX_Dates::normalize_date( self::text( $raw['from'] ?? '' ) ) : '';
		$to   = 'custom' === $preset ? OPEX_Dates::normalize_date( self::text( $raw['to'] ?? '' ) ) : '';

		if ( 'custom' === $preset ) {
			if ( '' === $from && '' === $to ) {
				return $empty;
			}
			// A backwards range would match nothing at all; swapping is what
			// the user meant and is cheaper than an error round-trip.
			if ( '' !== $from && '' !== $to && $from > $to ) {
				list( $from, $to ) = array( $to, $from );
			}
		}

		return array( 'field' => $field, 'preset' => $preset, 'from' => $from, 'to' => $to );
	}

	/**
	 * Pin a relative preset to concrete dates for the life of one job, so a
	 * long export that crosses midnight keeps counting and streaming the same
	 * rows it started with.
	 */
	public static function freeze_date_range( array $config ): array {
		$range = (array) ( $config['date_range'] ?? array() );
		$preset = (string) ( $range['preset'] ?? 'all_time' );

		if ( 'all_time' === $preset || 'custom' === $preset ) {
			return $config;
		}

		list( $from, $to ) = OPEX_Dates::preset_range( $preset );

		$range['from']         = $from;
		$range['to']           = $to;
		$config['date_range']  = $range;

		return $config;
	}

	/**
	 * Unique, human-readable output keys for the chosen fields:
	 * "meta:_sku" → "_sku", colliding names get a numeric suffix.
	 */
	public static function header_map( array $fields, array $custom = array() ): array {
		$headers = array();
		$used    = array();
		foreach ( $fields as $field ) {
			if ( isset( $custom[ $field ] ) && '' !== trim( (string) $custom[ $field ] ) ) {
				$label = sanitize_text_field( (string) $custom[ $field ] );
			} else {
				$label = OPEX_Query::field_name( $field );
			}
			$base = $label;
			$n    = 2;
			while ( isset( $used[ $label ] ) ) {
				$label = $base . '_' . $n;
				$n++;
			}
			$used[ $label ]    = true;
			$headers[ $field ] = $label;
		}
		return $headers;
	}

	/** Map delimiter key to the character used by fputcsv. */
	public static function csv_delimiter_char( string $key ): string {
		$map = array(
			'comma'     => ',',
			'semicolon' => ';',
			'tab'       => "\t",
		);
		return $map[ $key ] ?? ',';
	}

	/** Writer options derived from a sanitized config. */
	public static function writer_options( array $config ): array {
		return array(
			'csv_delimiter' => self::csv_delimiter_char( $config['csv_delimiter'] ?? 'comma' ),
		);
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
