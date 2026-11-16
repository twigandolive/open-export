<?php
/**
 * Recipe import for the 2.0 keys: transforms, custom columns, nested filter
 * groups, CSV encoding and line endings, and XML templates.
 *
 * A recipe without any of those goes straight to OPEX_Schema::recipe_to_config,
 * so a 1.3.1 recipe gives exactly the config it always gave. A recipe that uses
 * them is read in two steps: the 1.3.1 resolver handles the plain fields, plain
 * rules and the date range, and this class adds custom columns and groups to
 * its report, then cleans the whole config once through sanitize_config.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Turns a recipe that may use the 2.0 keys into a config and a report.
 */
class OPEX_Recipe {

	/** Keys a 1.3.1 recipe never had. */
	const NEW_KEYS = array( 'transforms', 'custom_columns', 'csv_encoding', 'csv_eol', 'xml_template', 'network', 'acf_flatten' );

	/** Entries the 1.3.1 resolver reads at the top level of the filters. */
	const MAX_TOP_RULES = 20;

	/**
	 * Validate a recipe into a config the screen can apply, plus a report.
	 * Same return shape as OPEX_Schema::recipe_to_config.
	 *
	 * @param array    $recipe Decoded recipe.
	 * @param string[] $notes  Receives notes.
	 * @return array|WP_Error
	 */
	public static function to_config( array $recipe, array &$notes = array() ) {
		$source = sanitize_text_field( self::text( $recipe['source'] ?? '' ) );
		$src    = OPEX_Query::parse_source( $source );
		if ( ! $src || ! self::uses_new_keys( $recipe ) ) {
			return OPEX_Schema::recipe_to_config( $recipe, $notes );
		}

		// Columns are cleaned here once, so their notes are kept. The second pass
		// through sanitize_config sees only clean columns and adds none.
		$custom_notes = array();
		$custom       = OPEX_Transforms::sanitize_custom_columns(
			$recipe['custom_columns'] ?? array(),
			static function ( $id ) use ( $src ): bool {
				return OPEX_Config::is_referencable( $src, (string) $id );
			},
			$custom_notes
		);
		$custom_ids = wp_list_pluck( $custom, 'id' );

		$filters = is_array( $recipe['filters'] ?? null ) ? $recipe['filters'] : array();
		$top     = array_slice( is_array( $filters['rules'] ?? null ) ? $filters['rules'] : array(), 0, self::MAX_TOP_RULES );
		$plain   = array();
		$map     = array();
		foreach ( $top as $i => $entry ) {
			if ( is_array( $entry ) && OPEX_Filter_Tree::is_group( $entry ) ) {
				continue;
			}
			$map[ count( $plain ) ] = $i;
			$plain[]                = $entry;
		}

		$legacy            = $recipe;
		$legacy['fields']  = array_values( array_filter( (array) ( $recipe['fields'] ?? array() ), static function ( $f ) use ( $custom_ids ): bool {
			return ! self::is_custom( $f, $custom_ids );
		} ) );
		$legacy['filters'] = array( 'relation' => $filters['relation'] ?? 'AND', 'rules' => $plain );
		$legacy_notes      = array();
		$result            = OPEX_Schema::recipe_to_config( $legacy, $legacy_notes );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$report = $result['report'];
		$known  = array_keys( OPEX_Catalog::field_ids( $source ) );
		list( $field_rows, $fields ) = self::merge_fields( (array) ( $recipe['fields'] ?? array() ), (array) $report['fields'], $custom, $custom_ids );
		list( $rule_rows, $rules )   = self::merge_rules( $top, (array) $report['filters'], $map, $src, $known );

		$report['fields']  = $field_rows;
		$report['filters'] = $rule_rows;
		$leaves            = OPEX_Recipe_Rules::count_leaves( $rule_rows );
		$report['summary'] = array(
			'fields_requested' => count( $field_rows ),
			'fields_matched'   => count( $fields ),
			'fields_dropped'   => count( $field_rows ) - count( $fields ),
			'rules_matched'    => $leaves['matched'],
			'rules_dropped'    => $leaves['dropped'],
		);

		$version_note = ( isset( $recipe['opex_recipe'] ) && (int) $recipe['opex_recipe'] > OPEX_Schema::RECIPE_VERSION && $legacy_notes ) ? array( $legacy_notes[0] ) : array();
		$notes        = array_merge( $notes, $version_note, $custom_notes );

		if ( ! $fields ) {
			$report['ok']     = false;
			$report['reason'] = (string) ( $result['report']['reason'] ?? __( 'The recipe lists no fields to export.', 'open-export' ) );
			return array( 'ok' => false, 'report' => $report, 'config' => null, 'name' => '', 'notes' => $notes );
		}

		$raw = array(
			'source'         => $source,
			'fields'         => $fields,
			'filters'        => array(
				'relation' => OPEX_Query::normalize_relation( self::text( $filters['relation'] ?? 'AND' ) ),
				'rules'    => $rules,
			),
			'date_range'     => self::date_range( $recipe, $report ),
			'format'         => self::text( $recipe['format'] ?? 'csv' ),
			'csv_delimiter'  => self::text( $recipe['csv_delimiter'] ?? 'comma' ),
			'column_labels'  => (array) ( $recipe['column_labels'] ?? array() ),
			'custom_columns' => $custom,
		);
		foreach ( array( 'batch_size', 'transforms', 'csv_encoding', 'csv_eol', 'xml_template', 'network', 'acf_flatten' ) as $key ) {
			if ( isset( $recipe[ $key ] ) ) {
				$raw[ $key ] = $recipe[ $key ];
			}
		}

		$config = self::sanitize( $raw, $notes );
		if ( is_wp_error( $config ) ) {
			$report['ok']     = false;
			$report['reason'] = $config->get_error_message();
			return array( 'ok' => false, 'report' => $report, 'config' => null, 'name' => '', 'notes' => $notes );
		}
		$report['ok'] = true;
		return array(
			'ok'     => true,
			'report' => $report,
			'config' => $config,
			'name'   => sanitize_text_field( self::text( $recipe['name'] ?? '' ) ),
			'notes'  => $notes,
		);
	}

	/**
	 * Whether a recipe uses anything the 1.3.1 resolver cannot read.
	 *
	 * @param array $recipe Recipe.
	 */
	private static function uses_new_keys( array $recipe ): bool {
		foreach ( self::NEW_KEYS as $key ) {
			if ( array_key_exists( $key, $recipe ) ) {
				return true;
			}
		}
		$rules = $recipe['filters']['rules'] ?? array();
		foreach ( is_array( $rules ) ? $rules : array() as $rule ) {
			if ( is_array( $rule ) && OPEX_Filter_Tree::is_group( $rule ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a listed field is a custom column id the recipe defines.
	 *
	 * @param mixed    $field      Listed field.
	 * @param string[] $custom_ids Clean custom column ids.
	 */
	private static function is_custom( $field, array $custom_ids ): bool {
		return is_scalar( $field ) && in_array( sanitize_text_field( (string) $field ), $custom_ids, true );
	}

	/**
	 * Puts custom column rows back among the resolver's field rows, in the
	 * recipe's order, and lists the fields that survived.
	 *
	 * @param array    $listed     The recipe's fields.
	 * @param array    $rows       Field rows from the 1.3.1 resolver.
	 * @param array    $custom     Clean custom columns.
	 * @param string[] $custom_ids Their ids.
	 * @return array array( rows, fields )
	 */
	private static function merge_fields( array $listed, array $rows, array $custom, array $custom_ids ): array {
		$labels = wp_list_pluck( $custom, 'label', 'id' );
		$out    = array();
		$fields = array();
		$next   = 0;
		$plain  = 0;
		foreach ( $listed as $entry ) {
			if ( self::is_custom( $entry, $custom_ids ) ) {
				$id = sanitize_text_field( (string) $entry );
				if ( in_array( $id, $fields, true ) ) {
					$out[] = array( 'id' => $id, 'status' => 'dropped', 'reason' => __( 'Listed more than once; the first one is kept.', 'open-export' ) );
					continue;
				}
				$out[]    = array( 'id' => $id, 'status' => 'matched', 'label' => (string) $labels[ $id ], 'custom' => true );
				$fields[] = $id;
				continue;
			}
			// The resolver read the first 300 plain entries and skipped blanks and non-text.
			$position = $plain++;
			if ( $position >= 300 || ! is_scalar( $entry ) || '' === sanitize_text_field( (string) $entry ) || ! isset( $rows[ $next ] ) ) {
				continue;
			}
			$row   = $rows[ $next++ ];
			$out[] = $row;
			if ( 'matched' === ( $row['status'] ?? '' ) ) {
				$fields[] = (string) $row['id'];
			}
		}
		// A defined custom column that is not listed still exports, last, as sanitize_config does.
		foreach ( $custom_ids as $id ) {
			if ( ! in_array( $id, $fields, true ) ) {
				$out[]    = array( 'id' => $id, 'status' => 'matched', 'label' => (string) $labels[ $id ], 'custom' => true );
				$fields[] = $id;
			}
		}
		return array( $out, $fields );
	}

	/**
	 * Puts group rows back among the resolver's rule rows, in the recipe's
	 * order, and builds the rule list.
	 *
	 * @param array $top   The recipe's top-level entries (at most 20).
	 * @param array $rows  Rule rows from the 1.3.1 resolver, indexed by plain entry.
	 * @param array $map   Plain entry position => position in $top.
	 * @param array $src   Parsed source.
	 * @param array $known Field ids, for suggestions.
	 * @return array array( rows, rules )
	 */
	private static function merge_rules( array $top, array $rows, array $map, array $src, array $known ): array {
		$by_index = array();
		foreach ( $rows as $row ) {
			$original           = $map[ (int) $row['index'] ] ?? (int) $row['index'];
			$row['index']       = $original;
			$by_index[ $original ] = $row;
		}
		$out   = array();
		$rules = array();
		foreach ( $top as $i => $entry ) {
			if ( is_array( $entry ) && OPEX_Filter_Tree::is_group( $entry ) ) {
				list( $row, $clean ) = OPEX_Recipe_Rules::group( $entry, 2, $src, $known );
				$out[] = array( 'index' => (int) $i ) + $row;
				if ( $clean ) {
					$rules[] = $clean;
				}
				continue;
			}
			if ( ! isset( $by_index[ $i ] ) ) {
				continue;
			}
			$row   = $by_index[ $i ];
			$out[] = $row;
			if ( 'matched' === ( $row['status'] ?? '' ) ) {
				$rules[] = array( 'field' => (string) $row['field'], 'op' => (string) $row['op'], 'value' => self::text( $entry['value'] ?? '' ) );
			}
		}
		return array( $out, $rules );
	}

	/**
	 * The date range as the 1.3.1 resolver left it: dropped when the source
	 * has no date column, with the field swapped when it substituted one.
	 *
	 * @param array $recipe Recipe.
	 * @param array $report Report.
	 * @return array
	 */
	private static function date_range( array $recipe, array $report ): array {
		$range = (array) ( $recipe['date_range'] ?? array() );
		$row   = is_array( $report['date_range'] ?? null ) ? $report['date_range'] : null;
		if ( $row && 'dropped' === ( $row['status'] ?? '' ) ) {
			return array();
		}
		if ( $row && 'substituted' === ( $row['status'] ?? '' ) ) {
			$range['field'] = (string) $row['used_instead'];
		}
		return $range;
	}

	/**
	 * Cleans the config. A broken XML template, an Excel request on a server
	 * without zip, or a network export this caller or source cannot have is
	 * dropped with a note instead of failing the whole recipe.
	 *
	 * @param array    $raw   Raw config.
	 * @param string[] $notes Receives notes.
	 * @return array|WP_Error
	 */
	private static function sanitize( array $raw, array &$notes ) {
		for ( $try = 0; $try < 3; $try++ ) {
			$attempt = array();
			$config  = OPEX_Exporter::sanitize_config( $raw, $attempt );
			if ( ! is_wp_error( $config ) ) {
				$notes = array_merge( $notes, $attempt );
				return $config;
			}
			$code = $config->get_error_code();
			if ( 0 === strpos( $code, 'opex_template_' ) && isset( $raw['xml_template'] ) ) {
				/* translators: %s: why the template was refused. */
				$notes[] = sprintf( __( 'The XML template was dropped: %s', 'open-export' ), $config->get_error_message() );
				unset( $raw['xml_template'] );
				continue;
			}
			if ( 'opex_bad_network' === $code && isset( $raw['network'] ) ) {
				/* translators: %s: why a network export is not possible. */
				$notes[] = sprintf( __( 'The export runs on this site only: %s', 'open-export' ), $config->get_error_message() );
				unset( $raw['network'] );
				continue;
			}
			if ( 'opex_no_zip' === $code ) {
				$notes[]       = __( 'Excel export needs the PHP zip extension, which this server does not have, so the format was set to CSV.', 'open-export' );
				$raw['format'] = 'csv';
				continue;
			}
			return $config;
		}
		return $config;
	}

	/**
	 * A scalar as text. Anything else becomes ''.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
