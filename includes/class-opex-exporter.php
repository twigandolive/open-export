<?php
/**
 * Job lifecycle: create writes the file header and counts rows; each run()
 * processes exactly one batch and returns, so a single PHP worker is never
 * held longer than one batch. The browser paces the calls — that's the
 * host-friendly throttle.
 */

defined( 'ABSPATH' ) || exit;

class OPEX_Exporter {

	private const FORMATS = array( 'csv', 'json', 'xml' );

	/**
	 * Validate a raw config from the client and reduce it to exactly the
	 * shape the query layer expects. Returns WP_Error on bad input.
	 */
	public static function sanitize_config( array $raw ) {
		$source = sanitize_text_field( (string) ( $raw['source'] ?? '' ) );
		if ( ! OPEX_Query::parse_source( $source ) ) {
			return new WP_Error( 'opex_bad_source', __( 'Unknown data source.', 'open-export' ), array( 'status' => 400 ) );
		}

		$fields = array();
		foreach ( array_slice( (array) ( $raw['fields'] ?? array() ), 0, 300 ) as $field ) {
			$field = (string) $field;
			if ( ! preg_match( '/^(core|meta|tax|computed|col):[\S]/', $field ) ) {
				continue;
			}
			if ( preg_match( '/^col:(.+)$/', $field, $matches ) && OPEX_Query::is_forbidden_column( $matches[1] ) ) {
				continue;
			}
			$fields[] = wp_unslash( $field );
		}
		if ( ! $fields ) {
			return new WP_Error( 'opex_no_fields', __( 'Pick at least one field to export.', 'open-export' ), array( 'status' => 400 ) );
		}

		$filters = array( 'relation' => 'AND', 'rules' => array() );
		$raw_f   = (array) ( $raw['filters'] ?? array() );
		if ( isset( $raw_f['relation'] ) && 'OR' === strtoupper( (string) $raw_f['relation'] ) ) {
			$filters['relation'] = 'OR';
		}
		foreach ( array_slice( (array) ( $raw_f['rules'] ?? array() ), 0, 20 ) as $rule ) {
			$filters['rules'][] = array(
				'field' => wp_unslash( sanitize_text_field( (string) ( $rule['field'] ?? '' ) ) ),
				'op'    => sanitize_key( (string) ( $rule['op'] ?? 'eq' ) ),
				'value' => wp_unslash( (string) ( $rule['value'] ?? '' ) ),
			);
		}

		$format = in_array( $raw['format'] ?? '', self::FORMATS, true ) ? $raw['format'] : 'csv';
		$batch  = max( 10, min( 5000, (int) ( $raw['batch_size'] ?? 500 ) ) );

		return array(
			'source'     => $source,
			'fields'     => $fields,
			'filters'    => $filters,
			'format'     => $format,
			'batch_size' => $batch,
		);
	}

	/**
	 * Unique, human-readable output keys for the chosen fields:
	 * "meta:_sku" → "_sku", colliding names get a numeric suffix.
	 */
	public static function header_map( array $fields ): array {
		$headers = array();
		$used    = array();
		foreach ( $fields as $field ) {
			$label = (string) preg_replace( '/^(core|meta|tax|computed|col):/', '', $field );
			$base  = $label;
			$n     = 2;
			while ( isset( $used[ $label ] ) ) {
				$label = $base . '_' . $n;
				$n++;
			}
			$used[ $label ]     = true;
			$headers[ $field ]  = $label;
		}
		return $headers;
	}

	/** Create a job: count, open the file, write the format header. */
	public static function create( array $config, string $label ) {
		$total = OPEX_Query::count( $config );

		$slug     = sanitize_file_name( sanitize_title( $label ) );
		$slug     = $slug ? $slug : 'export';
		$id       = 'j' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 12 );
		$filename = $slug . '-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 12, false ) . '.' . $config['format'];

		$job = array(
			'id'        => $id,
			'label'     => $label,
			'config'    => $config,
			'headers'   => self::header_map( $config['fields'] ),
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
			$writer = OPEX_Writer::make( $config['format'], OPEX_Jobs::file_path( $job ), $job['headers'] );
			$writer->start();
		} catch ( Exception $e ) {
			self::log_exception( $e );
			return new WP_Error( 'opex_fs', self::public_error_message( $e ), array( 'status' => 500 ) );
		}

		if ( 0 === $total ) {
			$writer->finish();
			$job['status']   = 'done';
			$job['filesize'] = (int) @filesize( OPEX_Jobs::file_path( $job ) );
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
		$writer = OPEX_Writer::make( $config['format'], OPEX_Jobs::file_path( $job ), $job['headers'] );

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
			$job['filesize'] = (int) @filesize( OPEX_Jobs::file_path( $job ) );
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

	/** First rows + total, so people see their data before committing. */
	public static function preview( array $config ): array {
		$headers = self::header_map( $config['fields'] );
		$batch   = OPEX_Query::batch( $config, 0, 5 );

		$rows = array();
		foreach ( $batch['items'] as $item ) {
			$row = array();
			foreach ( $headers as $field => $label ) {
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
