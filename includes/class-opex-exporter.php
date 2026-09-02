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
		$source = sanitize_text_field( (string) ( $raw['source'] ?? '' ) );
		$src    = OPEX_Query::parse_source( $source );
		if ( ! $src ) {
			return new WP_Error( 'opex_bad_source', __( 'Unknown data source.', 'open-export' ), array( 'status' => 400 ) );
		}

		$fields = array();

		foreach ( array_slice( (array) ( $raw['fields'] ?? array() ), 0, 300 ) as $field ) {
			$field = (string) $field;
			if ( ! preg_match( '/^(core|meta|tax|computed|col|woo):[\S]/', $field ) ) {
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
		if ( isset( $raw_f['relation'] ) && 'OR' === strtoupper( (string) $raw_f['relation'] ) ) {
			$filters['relation'] = 'OR';
		}
		foreach ( array_slice( (array) ( $raw_f['rules'] ?? array() ), 0, 20 ) as $rule ) {
			$rule_field = sanitize_text_field( (string) ( $rule['field'] ?? '' ) );
			$rule_op    = sanitize_key( (string) ( $rule['op'] ?? 'eq' ) );

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
				'value' => (string) ( $rule['value'] ?? '' ),
			);
		}

		$date_range = self::sanitize_date_range( $src, (array) ( $raw['date_range'] ?? array() ) );

		$format = in_array( $raw['format'] ?? '', self::FORMATS, true ) ? $raw['format'] : 'csv';
		$batch  = max( 10, min( 5000, (int) ( $raw['batch_size'] ?? 500 ) ) );

		$delim_key = sanitize_key( (string) ( $raw['csv_delimiter'] ?? 'comma' ) );
		if ( ! in_array( $delim_key, array( 'comma', 'semicolon', 'tab' ), true ) ) {
			$delim_key = 'comma';
		}

		if ( ! $date_range['field'] && ! empty( $raw['date_range'] ) && 'all_time' !== ( $raw['date_range']['preset'] ?? 'all_time' ) ) {
			$notes[] = __( 'The date range was dropped: this source has no date column to filter on.', 'open-export' );
		}

		$labels = array();
		foreach ( (array) ( $raw['column_labels'] ?? array() ) as $field_id => $label ) {
			$field_id = (string) $field_id;
			if ( ! in_array( $field_id, $fields, true ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) $label );
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

		$preset = sanitize_key( (string) ( $raw['preset'] ?? 'all_time' ) );
		if ( ! in_array( $preset, OPEX_Dates::PRESETS, true ) ) {
			$preset = 'all_time';
		}
		if ( 'all_time' === $preset ) {
			return $empty;
		}

		$defs  = OPEX_Query::date_fields( $src );
		if ( ! $defs ) {
			return $empty;
		}

		$field = sanitize_text_field( (string) ( $raw['field'] ?? '' ) );
		if ( ! isset( $defs[ $field ] ) ) {
			// Fall back to the source's primary date column rather than
			// silently exporting everything.
			$keys  = array_keys( $defs );
			$field = (string) reset( $keys );
		}

		$from = 'custom' === $preset ? OPEX_Dates::normalize_date( (string) ( $raw['from'] ?? '' ) ) : '';
		$to   = 'custom' === $preset ? OPEX_Dates::normalize_date( (string) ( $raw['to'] ?? '' ) ) : '';

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
				$label = (string) preg_replace( '/^(core|meta|tax|computed|col|woo):/', '', $field );
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
		$total  = OPEX_Query::count( $config );

		$slug     = sanitize_file_name( sanitize_title( $label ) );
		$slug     = $slug ? $slug : 'export';
		$id       = 'j' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 12 );
		$filename = $slug . '-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 12, false ) . '.' . $config['format'];

		$job = array(
			'id'        => $id,
			'label'     => $label,
			'config'    => $config,
			'headers'   => self::header_map( $config['fields'], $config['column_labels'] ?? array() ),
			'status'    => 'running',
			'total'     => $total,
			'processed' => 0,
			'cursor'    => 0,
			'created'   => time(),
			'filename'  => $filename,
			'filesize'  => 0,
			'error'     => '',
		);

		try {
			$writer = OPEX_Writer::make( $config['format'], OPEX_Jobs::file_path( $job ), $job['headers'], self::writer_options( $config ) );
			$writer->start();
		} catch ( Exception $e ) {
			self::log_exception( $e );
			return new WP_Error( 'opex_fs', self::public_error_message( $e ), array( 'status' => 500 ) );
		}

		if ( 0 === $total ) {
			$writer->finish();
			$job['status']   = 'done';
			$job['filesize'] = self::output_size( $job );
		}

		OPEX_Jobs::save( $job );

		return OPEX_Jobs::expose( $job );
	}

	/** Process one batch of an existing job. */
	public static function run( string $id ) {
		$job = OPEX_Jobs::get( $id );
		if ( ! $job ) {
			return new WP_Error( 'opex_missing', __( 'Export job not found.', 'open-export' ), array( 'status' => 404 ) );
		}
		if ( 'done' === $job['status'] ) {
			return OPEX_Jobs::expose( $job );
		}

		if ( 'paused' === $job['status'] ) {
			$job['status'] = 'running';
			OPEX_Jobs::save( $job );
		}

		if ( 'error' === $job['status'] ) {
			$job['status'] = 'running';
			$job['error']  = '';
			OPEX_Jobs::save( $job );
		}

		if ( ! OPEX_Jobs::acquire_lock( $id ) ) {
			return new WP_Error(
				'opex_locked',
				__( 'This export is already running in another window.', 'open-export' ),
				array( 'status' => 409 )
			);
		}

		$config = $job['config'];
		$writer = OPEX_Writer::make( $config['format'], OPEX_Jobs::file_path( $job ), $job['headers'], self::writer_options( $config ) );

		try {
			$batch = OPEX_Query::batch( $config, (int) $job['cursor'], (int) $config['batch_size'] );
			$writer->append( $batch['items'], 0 === (int) $job['processed'] );
		} catch ( Exception $e ) {
			self::log_exception( $e );
			$job['status'] = 'error';
			$job['error']  = self::public_error_message( $e );
			OPEX_Jobs::save( $job );
			return OPEX_Jobs::expose( $job );
		} finally {
			OPEX_Jobs::release_lock( $id );
		}

		$job['cursor']     = $batch['cursor'];
		$job['processed'] += count( $batch['items'] );

		if ( $batch['done'] ) {
			$writer->finish();
			$job['status']   = 'done';
			$job['total']    = max( (int) $job['total'], (int) $job['processed'] );
			$job['filesize'] = self::output_size( $job );
		}

		// A pause request may have landed while this batch was processing.
		$fresh = OPEX_Jobs::get( $id );
		if ( $fresh && 'paused' === $fresh['status'] && 'done' !== $job['status'] ) {
			$fresh['cursor']     = $job['cursor'];
			$fresh['processed']  = $job['processed'];
			$fresh['total']      = $job['total'];
			OPEX_Jobs::save( $fresh );
			return OPEX_Jobs::expose( $fresh );
		}

		OPEX_Jobs::save( $job );

		return OPEX_Jobs::expose( $job );
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
	private static function public_error_message( Exception $e ): string {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return $e->getMessage();
		}
		if ( false !== stripos( $e->getMessage(), 'export file' ) ) {
			return __( 'Could not write the export file. Check that the uploads directory is writable.', 'open-export' );
		}
		return __( 'Export failed. Try resuming or starting a new export.', 'open-export' );
	}

	/** Write exception detail to the PHP error log when debugging is enabled. */
	private static function log_exception( Exception $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional server-side logging when debug is on.
			error_log( 'Open Export: ' . $e->getMessage() );
		}
	}

	/** First rows and total count for the preview step. */
	public static function preview( array $config ): array {
		$headers = self::header_map( $config['fields'], $config['column_labels'] ?? array() );
		$batch   = OPEX_Query::batch( $config, 0, 5 );

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
			'total'   => OPEX_Query::count( $config ),
		);
	}
}
