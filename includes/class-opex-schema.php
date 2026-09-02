<?php
/**
 * Machine-readable handoff between this site and an AI assistant.
 *
 * Two halves of one round trip:
 *
 *   build()            describes what this site can export — sources, fields,
 *                      filter operators, date columns, and the legal values for
 *                      the fields where a wrong guess would silently return
 *                      nothing. The user hands that JSON to an assistant.
 *
 *   recipe_to_config() takes the export the assistant asks for and turns it
 *                      into a validated config, reporting exactly what it could
 *                      not honour rather than quietly dropping it.
 *
 * A recipe is only ever a *request*. It selects from what already exists: it
 * cannot name a column, table, or operator the plugin would not otherwise
 * expose, so nothing here widens what an administrator could already export.
 */

defined( 'ABSPATH' ) || exit;

class OPEX_Schema {

	/** Bumped when the document shape changes incompatibly. */
	public const SCHEMA_VERSION = 1;
	public const RECIPE_VERSION = 1;

	/** Values are only enumerated up to this many per field. */
	private const MAX_VALUES = 100;

	/**
	 * Meta fields listed per source when describing several at once. A single
	 * source is always described in full; this only bounds the overview, which
	 * says so in the payload and points at the per-source call.
	 */
	private const MAX_META_IN_OVERVIEW = 120;

	/**
	 * Describe this site's export surface.
	 *
	 * @param string $source_id Source to describe in full. Empty means none.
	 * @param bool   $all       Describe every source in full. Large sites will
	 *                          produce a big document; the caller opts in.
	 */
	public static function build( string $source_id = '', bool $all = false ): array {
		$groups = OPEX_Catalog::sources();

		$index = array();
		foreach ( $groups as $group ) {
			$tier = ( 'advanced' === ( $group['tier'] ?? 'standard' ) ) ? 'advanced' : 'common';
			foreach ( $group['items'] as $item ) {
				$index[] = array(
					'id'    => $item['id'],
					'label' => $item['label'],
					'group' => $group['label'],
					'tier'  => $tier,
					'rows'  => (int) $item['count'],
				);
			}
		}

		$detailed = array();

		if ( '' !== $source_id ) {
			// One source, described in full, with nothing trimmed.
			$described = self::describe_source( $source_id, $index, false );
			if ( $described ) {
				$detailed[] = $described;
			}
		}

		// The default document has to be usable on its own: an assistant told to
		// use only the ids listed here cannot compose anything from an empty
		// list. So every Common source is inlined, and the Advanced raw tables
		// are inlined only when explicitly asked for.
		foreach ( $index as $entry ) {
			if ( $entry['id'] === $source_id ) {
				continue;
			}
			if ( ! $all && 'common' !== $entry['tier'] ) {
				continue;
			}
			$described = self::describe_source( $entry['id'], $index, true );
			if ( $described ) {
				$detailed[] = $described;
			}
		}

		return array(
			'opex_schema'    => self::SCHEMA_VERSION,
			'plugin'         => 'Open Export',
			'plugin_version' => OPEX_VERSION,
			'generated'      => gmdate( 'c' ),
			'site'           => self::site_info(),
			'how_to_use'     => self::instructions(),
			'recipe_format'  => self::recipe_format(),
			'operators'      => self::operators(),
			'date_presets'   => self::date_presets(),
			'formats'        => array( 'csv', 'json', 'xml' ),
			'csv_delimiters' => array( 'comma', 'semicolon', 'tab' ),
			'all_sources'    => $index,
			'sources'        => $detailed,
		);
	}

	private static function site_info(): array {
		$info = array(
			'timezone'  => function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC',
			'wordpress' => get_bloginfo( 'version' ),
			'locale'    => get_locale(),
		);

		$info['woocommerce'] = array(
			'active' => OPEX_Woo::is_active(),
			'hpos'   => OPEX_Woo::use_hpos(),
		);

		return $info;
	}

	/** Prose the assistant reads before writing a recipe. */
	private static function instructions(): string {
		return implode(
			' ',
			array(
				'This document describes what a WordPress site can export through the Open Export plugin.',
				'To request an export, reply with a JSON object matching "recipe_format" below.',
				'Use only field ids that appear in this document under sources[].fields[].id — a field id that is not listed will be dropped and reported.',
				'Field ids are prefixed by where the value comes from: "core:" is a column on the main table, "meta:" is a custom field, "tax:" is a taxonomy, "computed:" is derived, "col:" is a raw table column, and "woo:" is a WooCommerce order field.',
				'Filters are a flat list of rules joined by a single relation, either AND or OR; there is no nesting.',
				'The date range is separate from the filter rules and is always combined with AND, so it narrows the export even when the rules use OR.',
				'Dates are whole calendar days in the site timezone given under site.timezone, written as YYYY-MM-DD, and the "to" date is included in full.',
				'Prefer a named preset from "date_presets" over explicit dates when the user asked for a relative period such as last month, because a preset stays relative when the export is saved and re-run.',
				'Where a field lists "values", use one of those exactly; anything else will match no rows.',
				'Sources are tiered. Prefer a source whose tier is "common"; the "advanced" tier is raw database tables, which are a power-user surface and have no meta, taxonomy or computed fields — only their own columns.',
				'For WooCommerce orders always use the woo:orders source and its woo: field ids. Those ids mean the same thing whether the store keeps orders in wp_posts or in the High-Performance Order Storage tables, so a recipe written against them works on either.',
				'If a source shows "fields_truncated": true, its custom fields were shortened to keep this document small; fetch the url in "to_see_all_fields" for that source\'s complete list.',
				'The recipe only selects from what is listed here. It cannot reach data the administrator running it could not already export.',
			)
		);
	}

	/** The shape of the object the assistant should send back. */
	private static function recipe_format(): array {
		return array(
			'opex_recipe'   => self::RECIPE_VERSION,
			'name'          => 'string — a short label for this export, shown to the user',
			'source'        => 'string — one id from all_sources[].id',
			'fields'        => array( 'string — field ids from sources[].fields[].id, in the column order you want' ),
			'filters'       => array(
				'relation' => 'string — "AND" (all rules must match) or "OR" (any rule)',
				'rules'    => array(
					array(
						'field' => 'string — a field id',
						'op'    => 'string — one id from operators[].id',
						'value' => 'string — omit for the empty and not_empty operators; comma-separated for in and not_in',
					),
				),
			),
			'date_range'    => array(
				'field'  => 'string — one id from sources[].date_fields[].id',
				'preset' => 'string — one id from date_presets[].id; use "custom" with from/to, or "all_time" for no range',
				'from'   => 'string — YYYY-MM-DD, only when preset is "custom"',
				'to'     => 'string — YYYY-MM-DD inclusive, only when preset is "custom"',
			),
			'format'        => 'string — csv, json, or xml',
			'csv_delimiter' => 'string — comma, semicolon, or tab',
			'column_labels' => array( 'field id' => 'string — a friendlier column header for that field' ),
		);
	}

	private static function operators(): array {
		$labels = array(
			'eq'           => 'equals',
			'neq'          => 'does not equal',
			'contains'     => 'contains',
			'not_contains' => 'does not contain',
			'starts_with'  => 'starts with',
			'ends_with'    => 'ends with',
			'gt'           => 'greater than',
			'gte'          => 'greater than or equal to',
			'lt'           => 'less than',
			'lte'          => 'less than or equal to',
			'in'           => 'is one of (comma-separated list)',
			'not_in'       => 'is not one of (comma-separated list)',
			'empty'        => 'is empty or missing',
			'not_empty'    => 'has any value',
		);

		$out = array();
		foreach ( OPEX_Query::ops() as $op ) {
			$out[] = array(
				'id'          => $op,
				'means'       => $labels[ $op ] ?? $op,
				'needs_value' => ! in_array( $op, array( 'empty', 'not_empty' ), true ),
			);
		}
		return $out;
	}

	private static function date_presets(): array {
		$out = array();
		foreach ( OPEX_Dates::PRESETS as $preset ) {
			$out[] = array( 'id' => $preset, 'label' => OPEX_Dates::preset_label( $preset ) );
		}
		return $out;
	}

	/**
	 * Full description of one source: its fields, date columns, and value hints.
	 *
	 * @param string $source_id Source to describe.
	 * @param array  $index     all_sources entries, for the label, tier and count.
	 * @param bool   $overview  Trim the custom-field list. A single-source
	 *                          request never trims; the overview says when it did.
	 */
	private static function describe_source( string $source_id, array $index = array(), bool $overview = false ): ?array {
		$src = OPEX_Query::parse_source( $source_id );
		if ( ! $src ) {
			return null;
		}

		$fields     = array();
		$meta_shown = 0;
		$meta_total = 0;

		foreach ( OPEX_Catalog::fields( $source_id ) as $group ) {
			foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
				$id = (string) $field['id'];

				if ( 0 === strpos( $id, 'meta:' ) ) {
					++$meta_total;
					if ( $overview && $meta_shown >= self::MAX_META_IN_OVERVIEW ) {
						continue;
					}
					++$meta_shown;
				}

				$entry = array(
					'id'    => $id,
					'label' => $field['label'],
					'group' => $group['label'],
					'type'  => $field['type'] ?? self::field_type( $src, $id ),
				);

				$values = self::field_values( $src, $id );
				if ( $values ) {
					$entry['values'] = $values;
				}

				$fields[] = $entry;
			}
		}

		$dates = array();
		foreach ( OPEX_Query::date_fields( $src ) as $id => $def ) {
			$dates[] = array(
				'id'        => $id,
				'label'     => $def['label'],
				'stored_in' => OPEX_Dates::TZ_UTC === $def['tz'] ? 'UTC' : 'site timezone',
			);
		}

		$entry_meta = array();
		foreach ( $index as $row ) {
			if ( $row['id'] === $source_id ) {
				$entry_meta = $row;
				break;
			}
		}

		$out = array(
			'id'          => $source_id,
			'label'       => $entry_meta['label'] ?? self::source_label( $source_id ),
			'group'       => $entry_meta['group'] ?? '',
			'tier'        => $entry_meta['tier'] ?? 'common',
			'kind'        => $src['type'],
			'rows'        => (int) ( $entry_meta['rows'] ?? 0 ),
			'fields'      => $fields,
			'date_fields' => $dates,
		);

		if ( $overview && $meta_total > $meta_shown ) {
			$out['fields_truncated']   = true;
			$out['custom_field_count'] = $meta_total;
			$out['to_see_all_fields']  = '/wp-json/open-export/v1/schema?source=' . rawurlencode( $source_id );
		}

		return $out;
	}

	/** Coarse type hint so an assistant knows what a field holds. */
	private static function field_type( array $src, string $field ): string {
		if ( 0 === strpos( $field, 'woo:' ) ) {
			return OPEX_Woo::field_type( $field );
		}
		if ( 0 === strpos( $field, 'tax:' ) ) {
			return 'enum';
		}
		if ( 0 === strpos( $field, 'computed:' ) ) {
			$what = substr( $field, 9 );
			return in_array( $what, array( 'permalink', 'attachment_url', 'featured_image_url' ), true ) ? 'url' : 'text';
		}
		if ( 0 === strpos( $field, 'col:' ) ) {
			$name = substr( $field, 4 );
			foreach ( OPEX_Catalog::table_columns( $src['table'] ?? '' ) as $col ) {
				if ( $col['name'] !== $name ) {
					continue;
				}
				if ( preg_match( '/^(datetime|timestamp|date)/i', $col['type'] ) ) {
					return 'date';
				}
				if ( preg_match( '/(int|decimal|float|double|numeric)/i', $col['type'] ) ) {
					return $col['pk'] ? 'id' : 'number';
				}
				return 'text';
			}
			return 'text';
		}
		if ( 0 === strpos( $field, 'core:' ) ) {
			$col = substr( $field, 5 );
			if ( preg_match( '/(_date|_date_gmt|_modified|_modified_gmt|_registered)$/', $col ) ) {
				return 'date';
			}
			if ( in_array( $col, array( 'ID', 'comment_ID', 'term_id', 'comment_post_ID', 'post_author', 'post_parent', 'user_id', 'comment_parent' ), true ) ) {
				return 'id';
			}
			if ( in_array( $col, array( 'menu_order', 'comment_count', 'comment_karma', 'count', 'parent' ), true ) ) {
				return 'number';
			}
			if ( in_array( $col, array( 'post_status', 'post_type', 'comment_approved', 'comment_type', 'comment_status', 'ping_status', 'post_mime_type' ), true ) ) {
				return 'enum';
			}
			if ( in_array( $col, array( 'user_email', 'comment_author_email' ), true ) ) {
				return 'email';
			}
			if ( in_array( $col, array( 'guid', 'user_url', 'comment_author_url' ), true ) ) {
				return 'url';
			}
			return 'text';
		}
		return 'text';
	}

	private static function source_label( string $source_id ): string {
		foreach ( OPEX_Catalog::sources() as $group ) {
			foreach ( $group['items'] as $item ) {
				if ( $item['id'] === $source_id ) {
					return (string) $item['label'];
				}
			}
		}
		return $source_id;
	}

	/**
	 * Legal values for the handful of fields where guessing wrong yields an
	 * empty export — statuses, roles, taxonomy terms. Everything else returns
	 * an empty array rather than paying for a DISTINCT over a large column.
	 */
	private static function field_values( array $src, string $field ): array {
		// Order status, on either storage engine.
		if ( ( 'woo:status' === $field || 'core:post_status' === $field ) && self::is_order_source( $src ) ) {
			if ( function_exists( 'wc_get_order_statuses' ) ) {
				return array_keys( wc_get_order_statuses() );
			}
		}

		if ( 'core:post_status' === $field && 'posts' === $src['type'] ) {
			$stati = get_post_stati();
			return array_values( array_slice( array_keys( (array) $stati ), 0, self::MAX_VALUES ) );
		}

		if ( 'computed:roles' === $field && 'users' === $src['type'] ) {
			$roles = wp_roles();
			return $roles ? array_keys( (array) $roles->get_names() ) : array();
		}

		if ( 0 === strpos( $field, 'tax:' ) && 'posts' === $src['type'] ) {
			$terms = get_terms( array(
				'taxonomy'   => substr( $field, 4 ),
				'fields'     => 'slugs',
				'hide_empty' => false,
				'number'     => self::MAX_VALUES,
			) );
			return is_wp_error( $terms ) ? array() : array_values( (array) $terms );
		}

		if ( 'core:post_type' === $field && 'posts' === $src['type'] ) {
			return array( $src['post_type'] );
		}

		return array();
	}

	private static function is_order_source( array $src ): bool {
		if ( 'woo_orders' === $src['type'] ) {
			return true;
		}
		return 'posts' === $src['type'] && OPEX_Woo::LEGACY_POST_TYPE === ( $src['post_type'] ?? '' );
	}

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
	 * Returns array( ok, report, config, name ). Only a malformed request —
	 * no source, or a source this site does not have — is a WP_Error.
	 */
	public static function recipe_to_config( array $recipe, array &$notes = array() ) {
		$report = array(
			'ok'         => false,
			'fields'     => array(),
			'filters'    => array(),
			'date_range' => null,
			'summary'    => array(),
		);

		if ( isset( $recipe['opex_recipe'] ) && (int) $recipe['opex_recipe'] > self::RECIPE_VERSION ) {
			$notes[] = sprintf(
				/* translators: 1: the recipe's version, 2: the version this plugin understands. */
				__( 'This recipe declares format version %1$d but this plugin understands version %2$d. Reading it anyway.', 'open-export' ),
				(int) $recipe['opex_recipe'],
				self::RECIPE_VERSION
			);
		}

		$source = sanitize_text_field( (string) ( $recipe['source'] ?? '' ) );
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
			$field = sanitize_text_field( (string) $raw_field );
			$row   = array( 'id' => $field );

			if ( '' === $field ) {
				continue;
			}
			if ( in_array( $field, $fields, true ) ) {
				$row['status'] = 'dropped';
				$row['reason'] = __( 'Listed more than once; the first one is kept.', 'open-export' );
			} elseif ( ! preg_match( '/^(core|meta|tax|computed|col|woo):[\S]/', $field ) ) {
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
		$rules = array();
		foreach ( array_slice( (array) ( ( $recipe['filters']['rules'] ?? array() ) ), 0, 20 ) as $i => $rule ) {
			$rf  = sanitize_text_field( (string) ( $rule['field'] ?? '' ) );
			$op  = sanitize_key( (string) ( $rule['op'] ?? 'eq' ) );
			$row = array( 'index' => (int) $i, 'field' => $rf, 'op' => $op );

			if ( '' === $rf ) {
				$row['status'] = 'dropped';
				$row['reason'] = __( 'The rule names no field.', 'open-export' );
			} elseif ( ! OPEX_Query::is_valid_op( $op ) ) {
				$row['status']     = 'dropped';
				$row['reason']     = __( 'Unsupported operator.', 'open-export' );
				$row['suggestion'] = self::suggest( $op, OPEX_Query::ops() );
			} elseif ( ! OPEX_Query::is_valid_field( $src, $rf ) ) {
				$row['status']     = 'dropped';
				$row['reason']     = __( 'This source has no field by that id to filter on.', 'open-export' );
				$row['suggestion'] = self::suggest( $rf, $known );
			} else {
				$row['status'] = 'matched';
				$rules[]       = array( 'field' => $rf, 'op' => $op, 'value' => (string) ( $rule['value'] ?? '' ) );
			}

			$report['filters'][] = $row;
		}

		// --- date range ----------------------------------------------------
		$date_raw = (array) ( $recipe['date_range'] ?? array() );
		$preset   = sanitize_key( (string) ( $date_raw['preset'] ?? 'all_time' ) );
		if ( $date_raw && 'all_time' !== $preset ) {
			$df  = sanitize_text_field( (string) ( $date_raw['field'] ?? '' ) );
			$row = array( 'field' => $df, 'preset' => $preset );

			if ( ! $date_known ) {
				$row['status'] = 'dropped';
				$row['reason'] = __( 'This source has no date column to filter on.', 'open-export' );
				$date_raw      = array();
			} elseif ( ! in_array( $df, $date_known, true ) ) {
				$fallback              = (string) reset( $date_known );
				$row['status']         = 'substituted';
				$row['reason']         = __( 'That is not a date column on this source.', 'open-export' );
				$row['suggestion']     = self::suggest( $df, $date_known );
				$row['used_instead']   = $fallback;
				$date_raw['field']     = $fallback;
			} else {
				$row['status'] = 'matched';
			}

			$report['date_range'] = $row;
		}

		$matched = count( $fields );
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

			return array( 'ok' => false, 'report' => $report, 'config' => null, 'name' => '', 'notes' => $notes );
		}

		$raw = array(
			'source'        => $source,
			'fields'        => $fields,
			'filters'       => array(
				'relation' => ( 'OR' === strtoupper( (string) ( $recipe['filters']['relation'] ?? 'AND' ) ) ) ? 'OR' : 'AND',
				'rules'    => $rules,
			),
			'date_range'    => $date_raw,
			'format'        => $recipe['format'] ?? 'csv',
			'csv_delimiter' => $recipe['csv_delimiter'] ?? 'comma',
			'column_labels' => (array) ( $recipe['column_labels'] ?? array() ),
		);
		if ( isset( $recipe['batch_size'] ) ) {
			$raw['batch_size'] = $recipe['batch_size'];
		}

		$config = OPEX_Exporter::sanitize_config( $raw, $notes );
		if ( is_wp_error( $config ) ) {
			$report['reason'] = $config->get_error_message();
			return array( 'ok' => false, 'report' => $report, 'config' => null, 'name' => '', 'notes' => $notes );
		}

		$report['ok'] = true;

		return array(
			'ok'     => true,
			'report' => $report,
			'config' => $config,
			'name'   => sanitize_text_field( (string) ( $recipe['name'] ?? '' ) ),
			'notes'  => $notes,
		);
	}

	/** Human label for a field id, for echoing back in the report. */
	private static function label_for( string $source_id, string $field ): string {
		$ids = OPEX_Catalog::field_ids( $source_id );
		return $ids[ $field ] ?? $field;
	}

	/**
	 * Nearest usable id for something the assistant got wrong.
	 *
	 * An id with the right name but the wrong prefix ("billing_state" for
	 * "woo:billing_state") is the common miss and is matched exactly; anything
	 * else falls back to edit distance, and only when it is close enough to be
	 * worth showing.
	 */
	private static function suggest( string $wanted, array $candidates ): string {
		$wanted = strtolower( trim( $wanted ) );
		if ( '' === $wanted || ! $candidates ) {
			return '';
		}

		$tail = false !== strpos( $wanted, ':' ) ? substr( $wanted, strrpos( $wanted, ':' ) + 1 ) : $wanted;

		foreach ( $candidates as $candidate ) {
			$c_tail = false !== strpos( $candidate, ':' ) ? substr( $candidate, strrpos( $candidate, ':' ) + 1 ) : $candidate;
			if ( strtolower( $c_tail ) === $tail ) {
				return $candidate;
			}
		}

		// Compare the NAME rather than the whole id: "billing.state" is one
		// edit from "billing_state" but five from "woo:billing_state", and the
		// prefix is exactly the part an assistant is most likely to get wrong.
		$best      = '';
		$best_dist = PHP_INT_MAX;
		foreach ( $candidates as $candidate ) {
			$c_tail = false !== strpos( $candidate, ':' ) ? substr( $candidate, strrpos( $candidate, ':' ) + 1 ) : $candidate;
			$c_tail = strtolower( $c_tail );
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
