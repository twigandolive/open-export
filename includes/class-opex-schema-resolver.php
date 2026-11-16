<?php
/**
 * Resolves a version 1 recipe into a validated config, with a report
 * that names every id that did and did not survive and the nearest match
 * for each miss.
 *
 * Part of the schema compartment. Other code reaches it through OPEX_Schema.
 *
 * @package Open_Export
 */

// No strict_types here on purpose: this file holds moved code, and strict types would change how arguments are coerced.

defined( 'ABSPATH' ) || exit;

/**
 * Turns a version 1 recipe into a config and a report of what was kept.
 */
class OPEX_Schema_Resolver {

	/**
	 * Validate a recipe into a config the UI can apply, plus a resolution
	 * report naming every id that did and did not survive.
	 *
	 * An assistant writing a recipe is guessing from a document, and a wrong
	 * guess must never turn into a silently empty selection or a blank column.
	 * So every id is resolved here first, each result is reported with a reason
	 * and a nearest match, and a recipe where nothing resolves is refused
	 * outright rather than applied as an empty export.
	 *
	 * Returns array( ok, report, config, name ). Only a malformed request
	 * (no source, or a source this site does not have) is a WP_Error.
	 */
	public static function recipe_to_config( array $recipe, array &$notes = array() ) {
		$report = array(
			'ok'         => false,
			'fields'     => array(),
			'filters'    => array(),
			'date_range' => null,
			'summary'    => array(),
		);

		if ( isset( $recipe['opex_recipe'] ) && (int) $recipe['opex_recipe'] > OPEX_Schema::RECIPE_VERSION ) {
			$notes[] = sprintf(
				/* translators: 1: the recipe's version, 2: the version this plugin understands. */
				__( 'This recipe declares format version %1$d but this plugin understands version %2$d. Reading it anyway.', 'open-export' ),
				(int) $recipe['opex_recipe'],
				OPEX_Schema::RECIPE_VERSION
			);
		}

		$source = sanitize_text_field( self::scalar_text( $recipe['source'] ?? '' ) );
		if ( '' === $source ) {
			return new WP_Error( 'opex_recipe_source', __( 'The recipe does not say which source to export. Add a "source" value.', 'open-export' ), array( 'status' => 400 ) );
		}

		$src = OPEX_Query::parse_source( $source );
		if ( ! $src ) {
			return new WP_Error(
				'opex_recipe_source',
				sprintf(
					/* translators: %s: the source id from the recipe. */
					__( 'This site has no source called "%s". Check all_sources in the capability document.', 'open-export' ),
					$source
				),
				array( 'status' => 400 )
			);
		}

		$known      = array_keys( OPEX_Catalog::field_ids( $source ) );
		$date_known = array_keys( OPEX_Query::date_fields( $src ) );

		// --- fields ------------------------------------------------------
		$fields = array();
		foreach ( array_slice( (array) ( $recipe['fields'] ?? array() ), 0, 300 ) as $raw_field ) {
			if ( ! is_scalar( $raw_field ) ) {
				continue;
			}
			$field = sanitize_text_field( (string) $raw_field );
			$row   = array( 'id' => $field );

			if ( '' === $field ) {
				continue;
			}
			if ( in_array( $field, $fields, true ) ) {
				$row['status'] = 'dropped';
				$row['reason'] = __( 'Listed more than once; the first one is kept.', 'open-export' );
			} elseif ( ! OPEX_Query::is_field_id( $field ) ) {
				$row['status']     = 'dropped';
				$row['reason']     = __( 'Not a field id. Ids look like "woo:total" or "core:post_title".', 'open-export' );
				$row['suggestion'] = self::suggest( $field, $known );
			} elseif ( preg_match( '/^col:(.+)$/', $field, $m ) && OPEX_Query::is_forbidden_column( $m[1] ) ) {
				$row['status'] = 'dropped';
				$row['reason'] = __( 'That column can never be exported.', 'open-export' );
			} elseif ( ! OPEX_Query::is_valid_field( $src, $field ) ) {
				$row['status']     = 'dropped';
				$row['reason']     = __( 'This source does not have that field.', 'open-export' );
				$row['suggestion'] = self::suggest( $field, $known );
			} else {
				$row['status'] = 'matched';
				$row['label']  = self::label_for( $source, $field );
				$fields[]      = $field;
			}

			$report['fields'][] = $row;
		}

		// --- filter rules -------------------------------------------------
		$rules      = array();
		$recipe_f   = is_array( $recipe['filters'] ?? null ) ? $recipe['filters'] : array();
		$rule_input = is_array( $recipe_f['rules'] ?? null ) ? $recipe_f['rules'] : array();
		foreach ( array_slice( $rule_input, 0, 20 ) as $i => $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$rf  = sanitize_text_field( self::scalar_text( $rule['field'] ?? '' ) );
			$op  = sanitize_key( self::scalar_text( $rule['op'] ?? 'eq' ) );
			$row = array(
				'index' => (int) $i,
				'field' => $rf,
				'op'    => $op,
			);

			if ( '' === $rf ) {
				$row['status'] = 'dropped';
				$row['reason'] = __( 'The rule names no field.', 'open-export' );
			} elseif ( ! OPEX_Query::is_valid_op( $op ) ) {
				$row['status']     = 'dropped';
				$row['reason']     = __( 'Unsupported operator.', 'open-export' );
				$row['suggestion'] = self::suggest( $op, OPEX_Query::ops() );
			} elseif ( ! OPEX_Query::is_filterable_field( $src, $rf ) ) {
				$row['status']     = 'dropped';
				$row['reason']     = __( 'This source has no field by that id to filter on.', 'open-export' );
				$row['suggestion'] = self::suggest( $rf, $known );
			} else {
				$row['status'] = 'matched';
				$rules[]       = array(
					'field' => $rf,
					'op'    => $op,
					'value' => self::scalar_text( $rule['value'] ?? '' ),
				);
			}

			$report['filters'][] = $row;
		}

		// --- date range ----------------------------------------------------
		$date_raw = (array) ( $recipe['date_range'] ?? array() );
		$preset   = sanitize_key( self::scalar_text( $date_raw['preset'] ?? 'all_time' ) );
		if ( $date_raw && 'all_time' !== $preset ) {
			$df  = sanitize_text_field( self::scalar_text( $date_raw['field'] ?? '' ) );
			$row = array(
				'field'  => $df,
				'preset' => $preset,
			);

			if ( ! $date_known ) {
				$row['status'] = 'dropped';
				$row['reason'] = __( 'This source has no date column to filter on.', 'open-export' );
				$date_raw      = array();
			} elseif ( ! in_array( $df, $date_known, true ) ) {
				$fallback            = OPEX_Query::date_field_or_default( $src, $df );
				$row['status']       = 'substituted';
				$row['reason']       = __( 'That is not a date column on this source.', 'open-export' );
				$row['suggestion']   = self::suggest( $df, $date_known );
				$row['used_instead'] = $fallback;
				$date_raw['field']   = $fallback;
			} else {
				$row['status'] = 'matched';
			}

			$report['date_range'] = $row;
		}

		$matched           = count( $fields );
		$report['summary'] = array(
			'fields_requested' => count( $report['fields'] ),
			'fields_matched'   => $matched,
			'fields_dropped'   => count( $report['fields'] ) - $matched,
			'rules_matched'    => count( $rules ),
			'rules_dropped'    => count( $report['filters'] ) - count( $rules ),
		);

		// Nothing usable: refuse, so an empty selection is never presented as
		// if the recipe had been applied.
		if ( ! $matched ) {
			$report['ok']     = false;
			$report['reason'] = count( $report['fields'] )
				? __( 'None of the fields in this recipe exist on this source, so there is nothing to export. Give the assistant the capability document for this site and ask it to use only the field ids listed there.', 'open-export' )
				: __( 'The recipe lists no fields to export.', 'open-export' );

			return array(
				'ok'     => false,
				'report' => $report,
				'config' => null,
				'name'   => '',
				'notes'  => $notes,
			);
		}

		$raw = array(
			'source'        => $source,
			'fields'        => $fields,
			'filters'       => array(
				'relation' => OPEX_Query::normalize_relation( self::scalar_text( $recipe_f['relation'] ?? 'AND' ) ),
				'rules'    => $rules,
			),
			'date_range'    => $date_raw,
			'format'        => self::scalar_text( $recipe['format'] ?? 'csv' ),
			'csv_delimiter' => self::scalar_text( $recipe['csv_delimiter'] ?? 'comma' ),
			'column_labels' => (array) ( $recipe['column_labels'] ?? array() ),
		);
		if ( isset( $recipe['batch_size'] ) ) {
			$raw['batch_size'] = $recipe['batch_size'];
		}

		$config = OPEX_Exporter::sanitize_config( $raw, $notes );
		if ( is_wp_error( $config ) ) {
			$report['reason'] = $config->get_error_message();
			return array(
				'ok'     => false,
				'report' => $report,
				'config' => null,
				'name'   => '',
				'notes'  => $notes,
			);
		}

		$report['ok'] = true;

		return array(
			'ok'     => true,
			'report' => $report,
			'config' => $config,
			'name'   => sanitize_text_field( self::scalar_text( $recipe['name'] ?? '' ) ),
			'notes'  => $notes,
		);
	}

	/** Human label for a field id, for echoing back in the report. */
	private static function label_for( string $source_id, string $field ): string {
		$ids = OPEX_Catalog::field_ids( $source_id );
		return $ids[ $field ] ?? $field;
	}

	/** The part of an id after its last colon ("woo:total" gives "total"). */
	private static function tail_after_colon( string $id ): string {
		return false !== strpos( $id, ':' ) ? substr( $id, strrpos( $id, ':' ) + 1 ) : $id;
	}

	/** A scalar as text. Anything else (an array from a bad recipe) becomes ''. */
	private static function scalar_text( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Nearest usable id for something the assistant got wrong.
	 *
	 * An id with the right name but the wrong prefix ("billing_state" for
	 * "woo:billing_state") is the common miss and is matched exactly, and anything
	 * else falls back to edit distance, and only when it is close enough to be
	 * worth showing.
	 */
	public static function suggest( string $wanted, array $candidates ): string {
		$wanted = strtolower( trim( $wanted ) );
		if ( '' === $wanted || ! $candidates ) {
			return '';
		}

		$tail = self::tail_after_colon( $wanted );

		foreach ( $candidates as $candidate ) {
			if ( strtolower( self::tail_after_colon( $candidate ) ) === $tail ) {
				return $candidate;
			}
		}

		// Compare the NAME rather than the whole id: "billing.state" is one
		// edit from "billing_state" but five from "woo:billing_state", and the
		// prefix is exactly the part an assistant is most likely to get wrong.
		$best      = '';
		$best_dist = PHP_INT_MAX;
		foreach ( $candidates as $candidate ) {
			$c_tail = strtolower( self::tail_after_colon( $candidate ) );
			if ( strlen( $tail ) > 250 || strlen( $c_tail ) > 250 ) {
				continue; // levenshtein() refuses long strings.
			}
			$dist = levenshtein( $tail, $c_tail );
			if ( $dist < $best_dist ) {
				$best_dist = $dist;
				$best      = $candidate;
			}
		}

		// Only offer a match that is actually close, or it reads as noise.
		return $best_dist <= max( 2, (int) ceil( strlen( $tail ) / 3 ) ) ? $best : '';
	}
}
