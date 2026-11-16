<?php
/**
 * XML writer driven by a user template: header, a row template and a footer.
 *
 * @package OpenExport
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- streamed export output across many requests.

/**
 * Replaces {field id} in the row template with the escaped value, and
 * {field id|cdata} with a CDATA section.
 */
class OPEX_Writer_XML_Template extends OPEX_Writer {

	/**
	 * Matches the text between braces. An id may hold pipes, as spread ACF
	 * columns do ("acf:team|1|name"). See placeholder() for how the text
	 * splits into an id and the "|cdata" modifier.
	 */
	const PLACEHOLDER = '/\{([^{}\r\n]+)\}/';

	/** The modifier that writes a value as a CDATA section. */
	const CDATA = '|cdata';

	/** A spread ACF column key: "<field id>|<row number>|<sub field>". */
	const SPREAD = '/^([^|]+)\|[1-9][0-9]*\|[^|]+$/';

	/**
	 * Written once, before the first row.
	 *
	 * @var string
	 */
	private string $header;

	/**
	 * Written once per record, with its placeholders filled.
	 *
	 * @var string
	 */
	private string $row;

	/**
	 * Written once, after the last row.
	 *
	 * @var string
	 */
	private string $footer;

	/**
	 * Builds the writer.
	 *
	 * @param string $path     Output file.
	 * @param array  $headers  Field id => label.
	 * @param array  $template Keys header, row, footer.
	 */
	public function __construct( string $path, array $headers, array $template ) {
		parent::__construct( $path, $headers );
		$this->header = self::clean( (string) ( $template['header'] ?? '' ) );
		$this->row    = self::clean( (string) ( $template['row'] ?? '' ) );
		$this->footer = self::clean( (string) ( $template['footer'] ?? '' ) );
	}

	/**
	 * Writes the header.
	 */
	public function start(): void {
		$this->write( self::terminate( $this->header ) );
	}

	/**
	 * Writes the row template once per record.
	 *
	 * @param array $rows        Rows keyed by field id.
	 * @param bool  $first_batch Unused.
	 */
	public function append( array $rows, bool $first_batch ): void {
		$out = '';
		foreach ( $rows as $row ) {
			$out .= self::terminate( self::render( $this->row, $row, $this->headers ) );
		}
		$this->write( $out );
	}

	/**
	 * Writes the footer.
	 */
	public function finish(): void {
		$this->write( self::terminate( $this->footer ) );
	}

	/**
	 * Checks a template: known fields, no DOCTYPE or entities, well-formed XML
	 * with no rows, one row and two rows, as an export of any size writes it.
	 *
	 * A spread ACF column such as {acf:team|1|name} counts as known when its
	 * field is chosen, because its columns are only counted when the export
	 * starts. Other text with a pipe in braces that names no chosen field,
	 * such as {a|b}, is left as text, as it always was.
	 *
	 * @param array $template  Keys header, row, footer.
	 * @param array $field_ids Allowed field ids.
	 * @return true|WP_Error True, or the first problem found.
	 */
	public static function validate( array $template, array $field_ids ) {
		$parts = array();
		foreach ( array( 'header', 'row', 'footer' ) as $key ) {
			$parts[ $key ] = self::clean( (string) ( $template[ $key ] ?? '' ) );
		}
		$all     = implode( "\n", $parts );
		$unknown = array();
		$samples = array();
		foreach ( $field_ids as $id ) {
			$samples[ (string) $id ] = 'sample';
		}
		if ( preg_match_all( self::PLACEHOLDER, $all, $found ) ) {
			foreach ( $found[1] as $inner ) {
				$id = self::placeholder( $inner, $samples )[0];
				if ( in_array( $id, $field_ids, true ) ) {
					continue;
				}
				$base   = false !== strpos( $id, '|' ) ? strstr( $id, '|', true ) : $id;
				$spread = $base !== $id && 1 === preg_match( self::SPREAD, $id );
				if ( $spread && in_array( $base, $field_ids, true ) ) {
					$samples[ $id ] = 'sample';
					continue;
				}
				if ( $base !== $id && ! in_array( $base, $field_ids, true ) && ! ( $spread && 0 === strpos( $id, OPEX_Acf::PREFIX ) ) ) {
					continue; // Text with a pipe that names no chosen field stays text.
				}
				$unknown[ $id ] = true;
			}
		}
		if ( $unknown ) {
			return new WP_Error(
				'opex_template_unknown_field',
				sprintf(
					/* translators: %s: comma separated field ids */
					__( 'The template uses fields that do not exist: %s', 'open-export' ),
					implode( ', ', array_keys( $unknown ) )
				)
			);
		}
		if ( preg_match( '/<!\s*(DOCTYPE|ENTITY)/i', $all ) ) {
			return new WP_Error( 'opex_template_doctype', __( 'The template cannot contain a DOCTYPE or entity declaration.', 'open-export' ) );
		}
		$row = self::render( $parts['row'], $samples, $samples );
		foreach ( array( 0, 1, 2 ) as $count ) {
			$doc = $parts['header'] . "\n" . str_repeat( $row . "\n", $count ) . $parts['footer'];
			if ( ! self::well_formed( $doc ) ) {
				return new WP_Error( 'opex_template_malformed', __( 'The header, row and footer together are not well-formed XML. Check them with no rows, one row and two rows: the rows need one root element around them in the header and footer.', 'open-export' ) );
			}
		}
		return true;
	}

	/**
	 * Fills a template with one record.
	 *
	 * A known id gets its value. An unknown plain id becomes empty, and so
	 * does an ACF spread column for a row this export has no column for.
	 * Other text with a pipe in braces is not a placeholder and stays as it is.
	 *
	 * @param string $template Template text.
	 * @param array  $row      Values keyed by field id.
	 * @param array  $known    Allowed field ids as keys.
	 * @return string The filled template.
	 */
	private static function render( string $template, array $row, array $known ): string {
		return (string) preg_replace_callback(
			self::PLACEHOLDER,
			static function ( array $m ) use ( $row, $known ): string {
				list( $id, $cdata ) = self::placeholder( $m[1], $known );
				if ( ! array_key_exists( $id, $known ) ) {
					$plain = false === strpos( $id, '|' );
					return ( $plain || self::acf_spread( $id ) ) ? '' : $m[0];
				}
				$value = (string) ( $row[ $id ] ?? '' );
				if ( $cdata ) {
					$value = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value );
					return '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', $value ) . ']]>';
				}
				return self::xml_escape( $value, true );
			},
			$template
		);
	}

	/**
	 * Splits the text between braces into a field id and the CDATA modifier.
	 *
	 * The whole text is tried as a field id first, so a spread ACF column
	 * whose sub field is named "cdata" ("acf:team|1|cdata") stays a column.
	 * Only when the whole text is no field id does a trailing "|cdata" count
	 * as the modifier.
	 *
	 * @param string $inner The text between the braces.
	 * @param array  $known Known field ids as keys.
	 * @return array{0: string, 1: bool} The field id, and true when the value goes in a CDATA section.
	 */
	private static function placeholder( string $inner, array $known ): array {
		$cut = strlen( $inner ) - strlen( self::CDATA );
		if ( array_key_exists( $inner, $known ) || self::acf_spread( $inner ) || $cut < 1 || self::CDATA !== substr( $inner, $cut ) ) {
			return array( $inner, false );
		}
		return array( substr( $inner, 0, $cut ), true );
	}

	/**
	 * Whether a field id has the form of a spread ACF column.
	 *
	 * @param string $id Field id.
	 * @return bool True for "acf:<field>|<row number>|<sub field>".
	 */
	private static function acf_spread( string $id ): bool {
		return 0 === strpos( $id, OPEX_Acf::PREFIX ) && 1 === preg_match( self::SPREAD, $id );
	}

	/**
	 * Trims a leading UTF-8 BOM.
	 *
	 * @param string $text Template part.
	 * @return string The text without the BOM.
	 */
	private static function clean( string $text ): string {
		return 0 === strpos( $text, "\xEF\xBB\xBF" ) ? substr( $text, 3 ) : $text;
	}

	/**
	 * Ends a non-empty piece with a newline so pieces do not run together.
	 *
	 * @param string $text Piece.
	 * @return string The piece, ending with a newline when it has text.
	 */
	private static function terminate( string $text ): string {
		return ( '' === $text || "\n" === substr( $text, -1 ) ) ? $text : $text . "\n";
	}

	/**
	 * Parses XML without loading entities or touching the network.
	 *
	 * @param string $xml Document.
	 * @return bool True when the XML parses.
	 */
	private static function well_formed( string $xml ): bool {
		if ( class_exists( 'DOMDocument' ) ) {
			$previous = libxml_use_internal_errors( true );
			$doc      = new DOMDocument();
			$ok       = $doc->loadXML( $xml, LIBXML_NONET );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			return $ok;
		}
		$parser = xml_parser_create( 'UTF-8' );
		$ok     = 1 === xml_parse( $parser, $xml, true );
		// PHP 8 frees the parser by itself, and PHP 8.5 deprecates the call.
		if ( PHP_VERSION_ID < 80000 ) {
			xml_parser_free( $parser ); // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- runs only before PHP 8.0, where it is still needed.
		}
		return $ok;
	}

	/**
	 * Appends text to the output file, every byte or an error.
	 *
	 * @param string $text Text.
	 * @return void
	 * @throws OPEX_User_Error When the file cannot be written in full.
	 */
	private function write( string $text ): void {
		$this->append_text( $text );
	}
}

// phpcs:enable
