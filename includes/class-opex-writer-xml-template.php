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

	/** Matches {id} and {id|cdata}. */
	const PLACEHOLDER = '/\{([^{}|\r\n]+)(\|cdata)?\}/';

	private string $header;
	private string $row;
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
	 * Checks a template: known fields, no DOCTYPE or entities, well-formed XML.
	 *
	 * @param array $template  Keys header, row, footer.
	 * @param array $field_ids Allowed field ids.
	 * @return true|WP_Error
	 */
	public static function validate( array $template, array $field_ids ) {
		$parts = array();
		foreach ( array( 'header', 'row', 'footer' ) as $key ) {
			$parts[ $key ] = self::clean( (string) ( $template[ $key ] ?? '' ) );
		}
		$all     = implode( "\n", $parts );
		$unknown = array();
		if ( preg_match_all( self::PLACEHOLDER, $all, $found ) ) {
			foreach ( $found[1] as $id ) {
				if ( ! in_array( $id, $field_ids, true ) ) {
					$unknown[ $id ] = true;
				}
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
		$samples = array();
		foreach ( $field_ids as $id ) {
			$samples[ (string) $id ] = 'sample';
		}
		$doc = $parts['header'] . "\n" . self::render( $parts['row'], $samples, $samples ) . "\n" . $parts['footer'];
		if ( ! self::well_formed( $doc ) ) {
			return new WP_Error( 'opex_template_malformed', __( 'The header, row and footer together are not well-formed XML.', 'open-export' ) );
		}
		return true;
	}

	/**
	 * Fills a template with one record.
	 *
	 * @param string $template Template text.
	 * @param array  $row      Values keyed by field id.
	 * @param array  $known    Allowed field ids as keys.
	 */
	private static function render( string $template, array $row, array $known ): string {
		return (string) preg_replace_callback(
			self::PLACEHOLDER,
			static function ( array $m ) use ( $row, $known ): string {
				if ( ! array_key_exists( $m[1], $known ) ) {
					return '';
				}
				$value = (string) ( $row[ $m[1] ] ?? '' );
				if ( isset( $m[2] ) && '' !== $m[2] ) {
					$value = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value );
					return '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', $value ) . ']]>';
				}
				return self::xml_escape( $value, true );
			},
			$template
		);
	}

	/**
	 * Trims a leading UTF-8 BOM.
	 *
	 * @param string $text Template part.
	 */
	private static function clean( string $text ): string {
		return 0 === strpos( $text, "\xEF\xBB\xBF" ) ? substr( $text, 3 ) : $text;
	}

	/**
	 * Ends a non-empty piece with a newline so pieces do not run together.
	 *
	 * @param string $text Piece.
	 */
	private static function terminate( string $text ): string {
		return ( '' === $text || "\n" === substr( $text, -1 ) ) ? $text : $text . "\n";
	}

	/**
	 * Parses XML without loading entities or touching the network.
	 *
	 * @param string $xml Document.
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
		xml_parser_free( $parser );
		return $ok;
	}

	/**
	 * Appends text to the output file.
	 *
	 * @param string $text Text.
	 */
	private function write( string $text ): void {
		$handle = $this->open();
		if ( false === fwrite( $handle, $text ) ) {
			fclose( $handle );
			throw new RuntimeException( 'Could not write the export file.' );
		}
		fclose( $handle );
	}
}

// phpcs:enable
