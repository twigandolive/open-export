<?php
/**
 * Custom (template) columns for Open Export.
 *
 * A template is literal text with {field id} placeholders. No code, no
 * expressions. Used through OPEX_Transforms, which holds the public API.
 *
 * @package OpenExport
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Sanitizes, scans and renders custom column templates.
 */
class OPEX_Custom_Columns {

	const MAX_COLUMNS = 50;
	const MAX_LABEL   = 100;
	const MAX_TEMPLATE = 500;

	/**
	 * Matches a placeholder. Custom ids are matched too so they can be reported.
	 */
	const PLACEHOLDER = '/\{((?:core|meta|tax|computed|col|woo|custom):[^\s{}]+)\}/';

	/**
	 * Matches a placeholder that may be filled (never a custom column).
	 */
	const FILLABLE = '/\{((?:core|meta|tax|computed|col|woo):[^\s{}]+)\}/';

	/**
	 * Clean the saved list of custom columns.
	 *
	 * @param mixed    $raw            Raw list from a request or a saved config.
	 * @param callable $is_valid_field Receives a field id, returns bool.
	 * @param string[] $notes          Receives plain-language notes.
	 * @return array[] List of array( id, label, template ).
	 */
	public static function sanitize( $raw, callable $is_valid_field, array &$notes ): array {
		$out  = array();
		$used = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( array_values( $raw ) as $item ) {
			if ( count( $out ) >= self::MAX_COLUMNS ) {
				$notes[] = __( 'Only the first 50 custom columns were kept.', 'open-export' );
				break;
			}
			if ( ! is_array( $item ) ) {
				continue;
			}
			$label    = self::clean_label( $item['label'] ?? '' );
			$template = OPEX_Transforms::clean_string( $item['template'] ?? '', self::MAX_TEMPLATE );
			if ( '' === $label || '' === $template ) {
				$notes[] = __( 'A custom column was ignored: it needs a name and a template.', 'open-export' );
				continue;
			}

			$slug = sanitize_title( $label );
			$slug = '' === $slug ? 'column' : $slug;
			$id   = 'custom:' . $slug;
			for ( $n = 2; isset( $used[ $id ] ); $n++ ) {
				$id = 'custom:' . $slug . '-' . $n;
			}
			$used[ $id ] = true;

			self::report_placeholders( $label, $template, $is_valid_field, $notes );
			$out[] = array(
				'id'       => $id,
				'label'    => $label,
				'template' => $template,
			);
		}
		return $out;
	}

	/**
	 * Add a note for each placeholder that will stay literal text.
	 *
	 * @param string   $label          Column label.
	 * @param string   $template       Template text.
	 * @param callable $is_valid_field Field check.
	 * @param string[] $notes          Notes.
	 * @return void
	 */
	private static function report_placeholders( string $label, string $template, callable $is_valid_field, array &$notes ): void {
		if ( ! preg_match_all( self::PLACEHOLDER, $template, $found ) ) {
			return;
		}
		foreach ( array_unique( $found[1] ) as $id ) {
			if ( 0 === strpos( $id, 'custom:' ) ) {
				$notes[] = sprintf(
					/* translators: 1: custom column name, 2: placeholder. */
					__( 'Custom column "%1$s": {%2$s} stays as plain text, a template cannot use another custom column.', 'open-export' ),
					$label,
					$id
				);
			} elseif ( ! $is_valid_field( $id ) ) {
				$notes[] = sprintf(
					/* translators: 1: custom column name, 2: placeholder. */
					__( 'Custom column "%1$s": {%2$s} is not a field of this source and stays as plain text.', 'open-export' ),
					$label,
					$id
				);
			}
		}
	}

	/**
	 * Field ids named in the templates, in first-seen order.
	 *
	 * Some may not be real fields. The caller checks them against the source.
	 *
	 * @param array[] $columns Clean custom columns.
	 * @return string[]
	 */
	public static function referenced( array $columns ): array {
		$ids = array();
		foreach ( $columns as $col ) {
			if ( is_array( $col ) && preg_match_all( self::FILLABLE, (string) ( $col['template'] ?? '' ), $found ) ) {
				foreach ( $found[1] as $id ) {
					$ids[ $id ] = true;
				}
			}
		}
		return array_map( 'strval', array_keys( $ids ) );
	}

	/**
	 * Split every template once, so rendering a row is a plain loop.
	 *
	 * @param array[] $columns Clean custom columns.
	 * @return array<string,array> Column id => list of array( is_field, text ).
	 */
	public static function compile( array $columns ): array {
		$compiled = array();
		foreach ( $columns as $col ) {
			if ( ! is_array( $col ) || ! isset( $col['id'] ) ) {
				continue;
			}
			$pieces = preg_split( self::FILLABLE, (string) ( $col['template'] ?? '' ), -1, PREG_SPLIT_DELIM_CAPTURE );
			$parts  = array();
			foreach ( (array) $pieces as $i => $piece ) {
				if ( '' !== $piece ) {
					$parts[] = array( 1 === $i % 2, (string) $piece );
				}
			}
			$compiled[ (string) $col['id'] ] = $parts;
		}
		return $compiled;
	}

	/**
	 * Fill one compiled template from one row. A field the row lacks stays literal.
	 *
	 * @param array $parts One entry of compile().
	 * @param array $row   Row keyed by field id.
	 * @return string
	 */
	public static function render( array $parts, array $row ): string {
		$out = '';
		foreach ( $parts as $part ) {
			if ( ! $part[0] ) {
				$out .= $part[1];
			} elseif ( array_key_exists( $part[1], $row ) ) {
				$out .= is_scalar( $row[ $part[1] ] ) ? (string) $row[ $part[1] ] : '';
			} else {
				$out .= '{' . $part[1] . '}';
			}
		}
		return $out;
	}

	/**
	 * Clean a label.
	 *
	 * @param mixed $label Raw label.
	 * @return string
	 */
	private static function clean_label( $label ): string {
		$label = is_scalar( $label ) ? sanitize_text_field( (string) $label ) : '';
		return mb_substr( $label, 0, self::MAX_LABEL );
	}
}
