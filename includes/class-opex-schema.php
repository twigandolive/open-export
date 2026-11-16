<?php
/**
 * Machine-readable handoff between this site and an AI assistant.
 *
 * Two halves of one round trip:
 *
 *   build()            describes what this site can export: sources, fields,
 *                      filter operators, date columns, and the legal values for
 *                      the fields where a wrong guess would silently return
 *                      nothing. The user hands that JSON to an assistant.
 *
 *   recipe_to_config() takes the export the assistant asks for and turns it
 *                      into a validated config, reporting exactly what it could
 *                      not honor rather than quietly dropping it.
 *
 * A recipe is only ever a *request*. It selects from what already exists: it
 * cannot name a column, table, or operator the plugin would not otherwise
 * expose, so nothing here widens what an administrator could already export.
 *
 * The per-source description lives in OPEX_Schema_Sources and the recipe
 * resolver in OPEX_Schema_Resolver. Other code calls them through this class.
 *
 * @package Open_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the capability document and resolves recipes against it.
 */
class OPEX_Schema {

	/** Bumped when the document shape changes incompatibly. */
	public const SCHEMA_VERSION = 1;
	public const RECIPE_VERSION = 1;

	/**
	 * Describe this site's export surface.
	 *
	 * @param string $source_id Source to describe in full. Empty means none.
	 * @param bool   $all       Describe every source in full. Large sites will
	 *                          produce a big document, so the caller opts in.
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
			$described = OPEX_Schema_Sources::describe_source( $source_id, $index, false );
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
			$described = OPEX_Schema_Sources::describe_source( $entry['id'], $index, true );
			if ( $described ) {
				$detailed[] = $described;
			}
		}

		return OPEX_Schema_Document::extend(
			array(
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
			)
		);
	}

	/**
	 * The site's name, address, time zone and versions, so a recipe is written for this site.
	 */
	private static function site_info(): array {
		$info = array(
			'timezone'  => wp_timezone_string(),
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
				'Field ids are prefixed by where the value comes from: "core:" is a column on the main table, "meta:" is a custom field, "tax:" is a taxonomy, "computed:" is derived, "col:" is a raw table column, "woo:" is a WooCommerce field (orders, order items, customers, products or coupons), and "acf:" is an ACF repeater or flexible content field, exported as JSON (it cannot be filtered on).',
				'Filters are a group of rules joined by one relation, AND or OR. A rule may itself be a group with its own relation, as described under "filter_groups".',
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

	/**
	 * The filter operators with a short meaning for each, for the assistant.
	 */
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

	/**
	 * The date range presets with their meanings.
	 */
	private static function date_presets(): array {
		$out = array();
		foreach ( OPEX_Dates::PRESETS as $preset ) {
			$out[] = array(
				'id'    => $preset,
				'label' => OPEX_Dates::preset_label( $preset ),
			);
		}
		return $out;
	}

	/**
	 * Coarse type hint so an assistant knows what a field holds. See OPEX_Schema_Sources.
	 *
	 * @param array  $src   Parsed source.
	 * @param string $field Field id.
	 */
	public static function field_type( array $src, string $field ): string {
		return OPEX_Schema_Sources::field_type( $src, $field );
	}

	/**
	 * Validate a recipe into a config the UI can apply, plus a resolution
	 * report. See OPEX_Schema_Resolver.
	 *
	 * @param array $recipe The recipe an assistant wrote.
	 * @param array $notes  Receives notes.
	 */
	public static function recipe_to_config( array $recipe, array &$notes = array() ) {
		return OPEX_Schema_Resolver::recipe_to_config( $recipe, $notes );
	}

	/**
	 * Nearest usable id for something the assistant got wrong. See OPEX_Schema_Resolver.
	 *
	 * @param string $wanted     The id the assistant wrote.
	 * @param array  $candidates Ids that exist.
	 */
	public static function suggest( string $wanted, array $candidates ): string {
		return OPEX_Schema_Resolver::suggest( $wanted, $candidates );
	}
}
