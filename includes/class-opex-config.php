<?php
/**
 * Export config: turns a raw config from a browser, a recipe or the command
 * line into the one saved shape the query layer and the writers expect.
 *
 * Part of the export engine. Other code reaches it through OPEX_Exporter.
 *
 * @package Open_Export
 */

// No strict_types here on purpose: strict types would change how this code coerces arguments.

defined( 'ABSPATH' ) || exit;

/**
 * Cleans an export config into the one shape that is saved and run.
 */
class OPEX_Config {

	private const FORMATS       = array( 'csv', 'json', 'xml', 'html' );
	private const CSV_ENCODINGS = array( 'utf8_bom', 'utf8', 'win1252', 'utf16le' );
	private const CSV_EOLS      = array( 'lf', 'crlf' );
	public const XML_PART_MAX   = 20000;

	/**
	 * Validate a raw config from the client and reduce it to exactly the
	 * shape the query layer expects. Returns WP_Error on bad input.
	 *
	 * @param array    $raw   Config from the browser, a recipe or the command line.
	 * @param string[] $notes Receives a note for each value that was changed or dropped.
	 * @return array|WP_Error The clean config, or an error that says what is wrong.
	 */
	public static function sanitize_config( array $raw, array &$notes = array() ) {
		$source = sanitize_text_field( self::text( $raw['source'] ?? '' ) );
		$src    = OPEX_Query::parse_source( $source );
		if ( ! $src ) {
			return new WP_Error( 'opex_bad_source', __( 'Unknown data source.', 'open-export' ), array( 'status' => 400 ) );
		}

		// A network export reads every site, so only a super admin of a network may ask for one.
		$network = ! empty( $raw['network'] ) && rest_sanitize_boolean( $raw['network'] );
		if ( $network && ! OPEX_Network::may_request() ) {
			return new WP_Error( 'opex_bad_network', __( 'Only a network super admin can export from every site, and only on a multisite network.', 'open-export' ), array( 'status' => 400 ) );
		}
		$refusal = $network ? OPEX_Network::source_refusal( $src ) : null;
		if ( $refusal ) {
			return $refusal;
		}

		$fields = array();

		// Custom columns come first: their ids may be listed in `fields` to set the column order.
		$custom     = OPEX_Transforms::sanitize_custom_columns(
			$raw['custom_columns'] ?? array(),
			static function ( $id ) use ( $src ): bool {
				return OPEX_Config::is_referencable( $src, (string) $id );
			},
			$notes
		);
		$custom_ids = wp_list_pluck( $custom, 'id' );

		foreach ( array_slice( (array) ( $raw['fields'] ?? array() ), 0, 300 ) as $field ) {
			$field = self::text( $field );
			if ( in_array( $field, $custom_ids, true ) ) {
				$fields[] = $field;
				continue;
			}
			if ( ! OPEX_Query::is_field_id( $field ) ) {
				$notes[] = sprintf(
					/* translators: %s: the rejected field id. */
					__( 'Open Export left out the field "%s" because it does not know that field id.', 'open-export' ),
					$field
				);
				continue;
			}
			if ( preg_match( '/^col:(.+)$/', $field, $matches ) && OPEX_Query::is_forbidden_column( $matches[1] ) ) {
				$notes[] = sprintf(
					/* translators: %s: the rejected field id. */
					__( 'Open Export left out the field "%s" because it never exports that column.', 'open-export' ),
					$field
				);
				continue;
			}
			if ( 0 === strpos( $field, 'meta:' ) && OPEX_Query::is_credential_meta( substr( $field, 5 ) ) ) {
				$notes[] = sprintf(
					/* translators: %s: the rejected field id. */
					__( 'Open Export left out the field "%s" because it never exports login credentials.', 'open-export' ),
					$field
				);
				continue;
			}
			// A raw table has no meta, no taxonomies and no computed values, so
			// asking for them would give silent empty columns.
			if ( 'table' === $src['type'] && 0 !== strpos( $field, 'col:' ) ) {
				$notes[] = sprintf(
					/* translators: %s: the rejected field id. */
					__( 'Open Export left out the field "%s" because a raw database table has only its own columns.', 'open-export' ),
					$field
				);
				continue;
			}
			if ( ! OPEX_Query::is_valid_field( $src, $field ) ) {
				$notes[] = sprintf(
					/* translators: %s: the rejected field id. */
					__( 'Open Export left out the field "%s" because this source does not have it.', 'open-export' ),
					$field
				);
				continue;
			}
			$fields[] = $field;
		}
		// A custom column that is not listed goes last, so defining one is enough to export it.
		$fields = array_values( array_unique( array_merge( $fields, $custom_ids ) ) );
		$config = array(
			'source'         => $source,
			'fields'         => $fields,
			'custom_columns' => $custom,
		);
		if ( ! $fields || ! self::fetch_fields( $config ) ) {
			return new WP_Error( 'opex_no_fields', __( 'Choose at least one field to export.', 'open-export' ), array( 'status' => 400 ) );
		}

		$filters = OPEX_Filter_Tree::sanitize( (array) ( $raw['filters'] ?? array() ), $src, $notes );
		if ( is_wp_error( $filters ) ) {
			return $filters;
		}

		$raw_range  = (array) ( $raw['date_range'] ?? array() );
		$date_range = self::sanitize_date_range( $src, $raw_range );

		$format = in_array( $raw['format'] ?? '', self::FORMATS, true ) ? $raw['format'] : 'csv';
		if ( 'xlsx' === ( $raw['format'] ?? '' ) ) {
			if ( ! OPEX_Compress::available() ) {
				// Name the formats this server can write, so the advice never lists a format it cannot offer.
				$writable = array();
				foreach ( OPEX_Writer::formats() as $info ) {
					if ( ! empty( $info['available'] ) ) {
						$writable[] = $info['label'];
					}
				}
				return new WP_Error(
					'opex_no_zip',
					sprintf(
						/* translators: %s: the formats this server can write, as a comma-separated list such as "CSV, JSON, XML, HTML table". */
						__( 'Excel export needs the PHP zip extension, which is not installed on this server. Choose another format: %s.', 'open-export' ),
						implode( ', ', $writable )
					),
					array( 'status' => 400 )
				);
			}
			$format = 'xlsx';
		}
		// Anything that is not a number falls back to the default size.
		$batch_raw = $raw['batch_size'] ?? 500;
		$batch     = max( 10, min( 5000, is_numeric( $batch_raw ) ? (int) $batch_raw : 500 ) );

		$delim_key = sanitize_key( self::text( $raw['csv_delimiter'] ?? 'comma' ) );
		if ( ! in_array( $delim_key, array( 'comma', 'semicolon', 'tab' ), true ) ) {
			$delim_key = 'comma';
		}

		if ( ! $date_range['field'] && ! empty( $raw_range ) && 'all_time' !== self::text( $raw_range['preset'] ?? 'all_time' ) ) {
			$notes[] = __( 'Open Export dropped the date range because this source has no date column to filter on.', 'open-export' );
		}

		$encoding = sanitize_key( self::text( $raw['csv_encoding'] ?? 'utf8_bom' ) );
		$encoding = in_array( $encoding, self::CSV_ENCODINGS, true ) ? $encoding : 'utf8_bom';
		$eol      = sanitize_key( self::text( $raw['csv_eol'] ?? 'lf' ) );
		$eol      = in_array( $eol, self::CSV_EOLS, true ) ? $eol : 'lf';

		$template = self::sanitize_xml_template( $raw['xml_template'] ?? array() );
		if ( 'xml' === $format && '' !== $template['row'] ) {
			$checked = OPEX_Writer_XML_Template::validate( $template, array_merge( $network ? array_keys( OPEX_Network::columns() ) : array(), $fields ) );
			if ( is_wp_error( $checked ) ) {
				return new WP_Error( $checked->get_error_code(), $checked->get_error_message(), array( 'status' => 400 ) );
			}
		}

		$output = OPEX_Config_Output::sanitize( $raw, $format, $notes );
		if ( is_wp_error( $output ) ) {
			return $output;
		}

		$transforms = OPEX_Transforms::sanitize( $raw['transforms'] ?? array(), $fields, $notes );
		$flatten    = OPEX_Acf_Spread::sanitize( $raw['acf_flatten'] ?? array(), $fields );

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

		$config = array(
			'source'        => $source,
			'fields'        => $fields,
			'filters'       => $filters,
			'date_range'    => $date_range,
			'format'        => $format,
			'batch_size'    => $batch,
			'csv_delimiter' => $delim_key,
			'column_labels' => $labels,
		);

		// Newer keys are saved only when they differ from their default, so a
		// plain export keeps exactly the shape version 1.3.1 saved.
		$optional = array(
			'csv_encoding'   => $encoding,
			'csv_eol'        => $eol,
			'xml_template'   => $template,
			'transforms'     => $transforms,
			'custom_columns' => $custom,
			'network'        => $network,
			'acf_flatten'    => $flatten,
		) + $output;
		foreach ( $optional as $key => $value ) {
			if ( OPEX_Jobs::CONFIG_DEFAULTS[ $key ] !== $value ) {
				$config[ $key ] = $value;
			}
		}
		return $config;
	}

	/**
	 * Clean the XML template: three text parts, each capped, no control characters.
	 *
	 * @param mixed $raw Raw template.
	 * @return array The header, row and footer text.
	 */
	private static function sanitize_xml_template( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();
		foreach ( array( 'header', 'row', 'footer' ) as $part ) {
			$text         = self::text( $raw[ $part ] ?? '' );
			$text         = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text );
			$out[ $part ] = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::XML_PART_MAX ) : substr( $text, 0, self::XML_PART_MAX );
		}
		return $out;
	}

	/**
	 * Whether a custom column may read this field: a real field of the source
	 * that is not a credential or a blocked column.
	 *
	 * @param array  $src   Parsed source.
	 * @param string $field Field id.
	 * @return bool True when a custom column may read the field.
	 */
	public static function is_referencable( array $src, string $field ): bool {
		if ( ! OPEX_Query::is_field_id( $field ) ) {
			return false;
		}
		if ( 0 === strpos( $field, 'col:' ) && OPEX_Query::is_forbidden_column( substr( $field, 4 ) ) ) {
			return false;
		}
		return OPEX_Query::is_valid_field( $src, $field );
	}

	/**
	 * Fields the query must fetch: the selected fields that are not custom,
	 * then the real fields custom columns read.
	 *
	 * @param array $config Sanitized config.
	 * @return string[]
	 */
	public static function fetch_fields( array $config ): array {
		$src    = OPEX_Query::parse_source( (string) ( $config['source'] ?? '' ) );
		$custom = (array) ( $config['custom_columns'] ?? array() );
		$ids    = wp_list_pluck( $custom, 'id' );
		$out    = array();
		foreach ( (array) ( $config['fields'] ?? array() ) as $field ) {
			if ( ! in_array( $field, $ids, true ) ) {
				$out[] = (string) $field;
			}
		}
		if ( $src ) {
			foreach ( OPEX_Transforms::referenced_fields( $custom ) as $field ) {
				if ( self::is_referencable( $src, $field ) ) {
					$out[] = $field;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Header labels: the saved column labels plus the name of each custom column.
	 *
	 * @param array $config Sanitized config.
	 * @return array Field id => label.
	 */
	public static function label_map( array $config ): array {
		$labels = array();
		foreach ( (array) ( $config['custom_columns'] ?? array() ) as $col ) {
			$labels[ (string) $col['id'] ] = (string) $col['label'];
		}
		return array_merge( $labels, (array) ( $config['column_labels'] ?? array() ) );
	}

	/**
	 * A scalar as text. Anything else (an array in a bad request) becomes ''.
	 *
	 * @param mixed $value Value from a request.
	 * @return string The value as text, or '' when it is not a scalar.
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Validate the date range. The field is checked against the source's own
	 * allowlist of real date columns, so nothing caller-supplied can reach SQL.
	 * An unrecognized field turns the range off rather than failing the
	 * export. Relative presets are stored unresolved so a saved template still
	 * means "last 30 days" the next time it runs, not the 30 days it meant when
	 * it was saved.
	 *
	 * @param array $src Parsed source, which decides the allowed date fields.
	 * @param array $raw Date range from the request.
	 * @return array The clean range: field, preset, from and to.
	 */
	private static function sanitize_date_range( array $src, array $raw ): array {
		$empty = array(
			'field'  => '',
			'preset' => 'all_time',
			'from'   => '',
			'to'     => '',
		);

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
			// A backwards range would match nothing at all. Swapping is what
			// the user meant and is cheaper than an error round-trip.
			if ( '' !== $from && '' !== $to && $from > $to ) {
				list( $from, $to ) = array( $to, $from );
			}
		}

		return array(
			'field'  => $field,
			'preset' => $preset,
			'from'   => $from,
			'to'     => $to,
		);
	}

	/**
	 * Pin a relative preset to concrete dates for the life of one job, so a
	 * long export that crosses midnight keeps counting and streaming the same
	 * rows it started with.
	 *
	 * @param array $config Clean config.
	 * @return array The config with fixed from and to dates.
	 */
	public static function freeze_date_range( array $config ): array {
		$range  = (array) ( $config['date_range'] ?? array() );
		$preset = (string) ( $range['preset'] ?? 'all_time' );

		if ( 'all_time' === $preset || 'custom' === $preset ) {
			return $config;
		}

		list( $from, $to ) = OPEX_Dates::preset_range( $preset );

		$range['from']        = $from;
		$range['to']          = $to;
		$config['date_range'] = $range;

		return $config;
	}

	/**
	 * Unique, human-readable output keys for the chosen fields:
	 * "meta:_sku" → "_sku", colliding names get a numeric suffix.
	 *
	 * @param string[] $fields Field ids in output order.
	 * @param array[]  $custom Custom columns, each with an id and label.
	 * @return array<string, string> Unique column labels by field id.
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
				++$n;
			}
			$used[ $label ]    = true;
			$headers[ $field ] = $label;
		}
		return $headers;
	}

	/**
	 * Map delimiter key to the character used by fputcsv.
	 *
	 * @param string $key Delimiter key: comma, semicolon or tab.
	 * @return string The delimiter character. A comma for an unknown key.
	 */
	public static function csv_delimiter_char( string $key ): string {
		$map = array(
			'comma'     => ',',
			'semicolon' => ';',
			'tab'       => "\t",
		);
		return $map[ $key ] ?? ',';
	}

	/**
	 * Writer options derived from a sanitized config.
	 *
	 * @param array $config Clean config.
	 * @return array Options for OPEX_Writer::make().
	 */
	public static function writer_options( array $config ): array {
		return array(
			'csv_delimiter' => self::csv_delimiter_char( $config['csv_delimiter'] ?? 'comma' ),
			'csv_encoding'  => (string) ( $config['csv_encoding'] ?? 'utf8_bom' ),
			'csv_eol'       => (string) ( $config['csv_eol'] ?? 'lf' ),
			'xml_template'  => (array) ( $config['xml_template'] ?? array() ),
		) + OPEX_Config_Output::writer_options( $config );
	}
}
