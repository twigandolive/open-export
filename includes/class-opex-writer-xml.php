<?php
/**
 * Plain XML writer: one element per row, one child per column.
 *
 * @package Open_Export
 */

// No strict_types here on purpose: strict types would change how this code coerces arguments.

defined( 'ABSPATH' ) || exit;

/**
 * Plain XML with one element per row.
 */
class OPEX_Writer_XML extends OPEX_Writer {

	/**
	 * Writes the declaration and opens the root element.
	 */
	public function start(): void {
		$this->append_text( "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<records>\n" );
	}

	/**
	 * Adds one record element per row, with one child per column.
	 *
	 * @param array $rows        Rows keyed by field id.
	 * @param bool  $first_batch Unused: XML records need no separator.
	 */
	public function append( array $rows, bool $first_batch ): void {
		$out = '';
		foreach ( $rows as $row ) {
			$out .= "  <record>\n";
			foreach ( $this->headers as $field => $label ) {
				$el   = $this->element_name( $label );
				$out .= "    <{$el}>" . self::xml_escape( (string) ( $row[ $field ] ?? '' ) ) . "</{$el}>\n";
			}
			$out .= "  </record>\n";
		}
		if ( '' !== $out ) {
			$this->append_text( $out );
		}
	}

	/**
	 * Closes the root element.
	 */
	public function finish(): void {
		$this->append_text( "</records>\n" );
	}

	/**
	 * An XML element name made from a column label: anything not allowed in
	 * a name becomes an underscore, and a name may not start with a digit.
	 *
	 * @param string $label Column label.
	 * @return string The element name.
	 */
	private function element_name( string $label ): string {
		$name = preg_replace( '/[^A-Za-z0-9_\-]/', '_', $label );
		if ( '' === $name || ! preg_match( '/^[A-Za-z_]/', $name ) ) {
			$name = '_' . $name;
		}
		return $name;
	}
}
