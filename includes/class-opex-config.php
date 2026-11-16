<?php
/**
 * Export config: turns a raw config from a browser, a recipe or the command
 * line into the one saved shape the query layer and the writers expect.
 *
 * Part of the export engine. Other code reaches it through OPEX_Exporter.
 */

// No strict_types here on purpose: this file holds code moved from class-opex-exporter.php, and strict types would change how arguments are coerced.

defined( 'ABSPATH' ) || exit;

class OPEX_Config {

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
}
