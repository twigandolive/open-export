<?php
/**
 * The 2.0 parts of the capability document: formats and their options,
 * transforms, custom columns, nested filter groups, XML templates and
 * network exports.
 *
 * Everything here is added to the document OPEX_Schema builds. No key it
 * already had changes meaning, so opex_schema stays 1 and an assistant that
 * only knows the 1.3.1 document still reads it correctly.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Adds the newer export options to the capability document.
 */
class OPEX_Schema_Document {

	/**
	 * Adds the new keys to a built document.
	 *
	 * @param array $doc Document from OPEX_Schema::build.
	 * @return array
	 */
	public static function extend( array $doc ): array {
		$formats = OPEX_Writer::formats();

		$doc['formats']       = array_keys(
			array_filter(
				$formats,
				static function ( $f ): bool {
					return ! empty( $f['available'] );
				}
			)
		);
		$doc['how_to_use']    = (string) ( $doc['how_to_use'] ?? '' ) . ' ' . self::instructions();
		$doc['recipe_format'] = array_merge( (array) ( $doc['recipe_format'] ?? array() ), self::recipe_keys() );

		$doc['format_options'] = self::format_options( $formats );
		$doc['csv_encodings']  = array( 'utf8_bom', 'utf8', 'win1252', 'utf16le' );
		$doc['csv_eols']       = array( 'lf', 'crlf' );
		$doc['transforms']     = array(
			'max_steps_per_field' => OPEX_Transforms::MAX_STEPS,
			'max_text_length'     => OPEX_Transforms::MAX_STRING,
			'max_map_pairs'       => OPEX_Transforms::MAX_PAIRS,
			'types'               => self::transform_types(),
		);
		$doc['custom_columns'] = array(
			'max_columns'         => OPEX_Custom_Columns::MAX_COLUMNS,
			'max_label_length'    => OPEX_Custom_Columns::MAX_LABEL,
			'max_template_length' => OPEX_Custom_Columns::MAX_TEMPLATE,
			'id_pattern'          => 'custom:[a-z0-9-]+',
			'means'               => 'A column built from literal text and {field id} placeholders, for example "{woo:billing_first_name} {woo:billing_last_name}". A placeholder must be a field id of the same source. Anything else is literal text. There is no code and there are no expressions.',
		);
		$doc['filter_groups']  = array(
			'max_depth' => OPEX_Filter_Tree::MAX_DEPTH,
			'max_rules' => OPEX_Filter_Tree::MAX_RULES,
			'means'     => 'filters.rules may hold rules and groups. A group is { "relation": "AND" or "OR", "rules": [ ... ] } and has no "field" key. The top level counts as depth 1, so groups nest at most two levels below it. At most 50 rules count across the whole tree, and a recipe reads at most 20 entries at the top level.',
		);
		$doc['xml_template']   = array(
			'max_part_length' => OPEX_Config::XML_PART_MAX,
			'means'           => 'With format "xml" and a non-empty "row", the export writes "header" once, "row" once per record and "footer" once. In "row", {field id} becomes the XML-escaped value and {field id|cdata} becomes a CDATA section. Every placeholder must name a field listed in "fields" (a custom column id counts). The header and footer are written as they are, with no placeholders. Header, one row and footer together must be well-formed XML, and no DOCTYPE or entity declaration is allowed.',
		);
		$doc['network']        = array(
			'available'   => OPEX_Network::may_request(),
			'columns'     => array_keys( OPEX_Network::columns() ),
			'not_sources' => array( 'users', 'table:*' ),
			'means'       => 'On a multisite network, "network": true runs the same export on every public site that is not archived or deleted, in site id order, into one file. Every row then starts with the columns site_id and site_url, and an XML template may use {site_id} and {site_url}. Only a network super admin may ask for it, and it does not work with the users source or raw tables. A site without the source adds no rows and the job says so.',
		);
		return $doc;
	}

	/**
	 * Extra prose for how_to_use.
	 *
	 * @return string
	 */
	private static function instructions(): string {
		return implode(
			' ',
			array(
				'A recipe may also set: "transforms" (per-field steps from "transforms.types"), "custom_columns" (see "custom_columns"), "csv_encoding" and "csv_eol" for CSV files, "xml_template" for XML files (see "xml_template"), and "network" on a multisite network (see "network").',
				'CSV files may also set "csv_enclosure" and "csv_quote_all", and every format except xlsx may set "compress" to "zip" when "format_options" lists it.',
				'Formats are listed under "formats", and "format_options" says which recipe keys each one reads.',
				'Anything in a recipe that this site cannot honor is dropped and reported, never applied silently.',
			)
		);
	}

	/**
	 * The new keys of recipe_format.
	 *
	 * @return array
	 */
	private static function recipe_keys(): array {
		return array(
			'format'         => 'string: one id from formats',
			'filters'        => array(
				'relation' => 'string: "AND" (all rules must match) or "OR" (any rule)',
				'rules'    => array(
					array(
						'field' => 'string: a field id',
						'op'    => 'string: one id from operators[].id',
						'value' => 'string: omit for the empty and not_empty operators, comma-separated for in and not_in',
					),
					array(
						'relation' => 'string: "AND" or "OR", for a nested group (see filter_groups)',
						'rules'    => array( 'rules or groups, in the same shape' ),
					),
				),
			),
			'csv_encoding'   => 'string: one id from csv_encodings (default utf8_bom)',
			'csv_eol'        => 'string: one id from csv_eols (default lf)',
			'xml_template'   => array(
				'header' => 'string: written once at the start',
				'row'    => 'string: written once per record, with {field id} placeholders',
				'footer' => 'string: written once at the end',
			),
			'transforms'     => array( 'field id' => array( array( 'type' => 'string: one id from transforms.types, plus that type\'s args' ) ) ),
			'custom_columns' => array(
				array(
					'id'       => 'string: custom:<slug>, optional (made from the label when missing)',
					'label'    => 'string: the column header',
					'template' => 'string: literal text with {field id} placeholders',
				),
			),
			'network'        => 'boolean: true to export from every site of a multisite network (see network), default false',
			'acf_flatten'    => array( 'acf: field ids from fields, each spread into "<label> <n> <sub>" columns (at most 20 rows) instead of one JSON column' ),
			'csv_enclosure'  => 'string: "double" (default) or "single", the character that wraps a quoted CSV cell',
			'csv_quote_all'  => 'boolean: true to wrap every CSV cell in the quote character, default false (only cells that need it)',
			'compress'       => 'string: "none" (default) or "zip", which packs the finished file into a zip. Not for xlsx, which is a zip already',
		);
	}

	/**
	 * Each format with its label, extension, whether this server can write it,
	 * and the recipe keys it reads.
	 *
	 * @param array $formats From OPEX_Writer::formats().
	 * @return array
	 */
	private static function format_options( array $formats ): array {
		$reads = array(
			'csv'  => array( 'csv_delimiter', 'csv_encoding', 'csv_eol', 'csv_enclosure', 'csv_quote_all' ),
			'json' => array(),
			'xml'  => array( 'xml_template' ),
			'xlsx' => array(),
			'html' => array(),
		);
		$zip   = OPEX_Compress::available();
		$out   = array();
		foreach ( $formats as $id => $format ) {
			$options = $reads[ $id ] ?? array();
			if ( $zip && 'xlsx' !== $id ) {
				$options[] = 'compress';
			}
			$out[ $id ] = array(
				'label'     => (string) ( $format['label'] ?? $id ),
				'extension' => (string) ( $format['extension'] ?? $id ),
				'available' => ! empty( $format['available'] ),
				'options'   => $options,
			);
		}
		return $out;
	}

	/**
	 * Transform types for the document: the screen's list without anything
	 * only a form needs to render.
	 *
	 * @return array
	 */
	private static function transform_types(): array {
		$out = array();
		foreach ( OPEX_Transforms::types() as $type => $def ) {
			$args = array();
			foreach ( (array) ( $def['args'] ?? array() ) as $name => $arg ) {
				$entry = array( 'type' => (string) ( $arg['type'] ?? 'text' ) );
				foreach ( array( 'min', 'max', 'optional' ) as $key ) {
					if ( isset( $arg[ $key ] ) ) {
						$entry[ $key ] = $arg[ $key ];
					}
				}
				if ( isset( $arg['options'] ) && is_array( $arg['options'] ) ) {
					$entry['values'] = array_keys( $arg['options'] );
				}
				$args[ $name ] = $entry;
			}
			$out[ $type ] = array(
				'label' => (string) ( $def['label'] ?? $type ),
				'args'  => (object) $args,
			);
		}
		return $out;
	}
}
