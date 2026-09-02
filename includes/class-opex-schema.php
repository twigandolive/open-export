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
			foreach ( $group['items'] as $item ) {
				$index[] = array(
					'id'    => $item['id'],
					'label' => $item['label'],
					'group' => $group['label'],
					'rows'  => (int) $item['count'],
				);
			}
		}

		$detailed = array();
		if ( $all ) {
			foreach ( $index as $entry ) {
				$described = self::describe_source( $entry['id'] );
				if ( $described ) {
					$detailed[] = $described;
				}
			}
		} elseif ( '' !== $source_id ) {
			$described = self::describe_source( $source_id );
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

	/** Full description of one source: its fields, date columns, and value hints. */
	private static function describe_source( string $source_id ): ?array {
		$src = OPEX_Query::parse_source( $source_id );
		if ( ! $src ) {
			return null;
		}

		$fields = array();
		foreach ( OPEX_Catalog::fields( $source_id ) as $group ) {
			foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
				$entry = array(
					'id'    => $field['id'],
					'label' => $field['label'],
					'group' => $group['label'],
				);

				$values = self::field_values( $src, (string) $field['id'] );
				if ( $values ) {
					$entry['values'] = $values;
				}

				$fields[] = $entry;
			}
		}

		$dates = array();
		foreach ( OPEX_Query::date_fields( $src ) as $id => $def ) {
			$dates[] = array(
				'id'       => $id,
				'label'    => $def['label'],
				'stored_in' => OPEX_Dates::TZ_UTC === $def['tz'] ? 'UTC' : 'site timezone',
			);
		}

		return array(
			'id'          => $source_id,
			'label'       => self::source_label( $source_id ),
			'kind'        => $src['type'],
			'fields'      => $fields,
			'date_fields' => $dates,
		);
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
	 * Validate a recipe into a config the UI can apply.
	 *
	 * Returns WP_Error only when the request is unusable — bad JSON shape, a
	 * source that does not exist, or nothing left to export. Everything else is
	 * reported through $notes so the user can see what the assistant got wrong
	 * instead of receiving a silently different export.
	 */
	public static function recipe_to_config( array $recipe, array &$notes = array() ) {
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
		if ( ! OPEX_Query::parse_source( $source ) ) {
			return new WP_Error(
				'opex_recipe_source',
				sprintf(
					/* translators: %s: the source id from the recipe. */
					__( 'This site has no source called "%s". Check all_sources in the schema.', 'open-export' ),
					$source
				),
				array( 'status' => 400 )
			);
		}

		$raw = array(
			'source'        => $source,
			'fields'        => (array) ( $recipe['fields'] ?? array() ),
			'filters'       => (array) ( $recipe['filters'] ?? array() ),
			'date_range'    => (array) ( $recipe['date_range'] ?? array() ),
			'format'        => $recipe['format'] ?? 'csv',
			'csv_delimiter' => $recipe['csv_delimiter'] ?? 'comma',
			'column_labels' => (array) ( $recipe['column_labels'] ?? array() ),
		);

		if ( isset( $recipe['batch_size'] ) ) {
			$raw['batch_size'] = $recipe['batch_size'];
		}

		$config = OPEX_Exporter::sanitize_config( $raw, $notes );
		if ( is_wp_error( $config ) ) {
			// Explain WHY nothing survived, or the user just sees "pick a field"
			// with no clue that the assistant named fields this site lacks.
			if ( $notes ) {
				$config->add_data(
					array(
						'status' => 400,
						'notes'  => $notes,
					),
					$config->get_error_code()
				);
				return new WP_Error(
					$config->get_error_code(),
					$config->get_error_message() . ' ' . implode( ' ', $notes ),
					array( 'status' => 400, 'notes' => $notes )
				);
			}
			return $config;
		}

		return array(
			'config' => $config,
			'name'   => sanitize_text_field( (string) ( $recipe['name'] ?? '' ) ),
			'notes'  => $notes,
		);
	}
}
