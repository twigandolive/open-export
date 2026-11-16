<?php
/**
 * JSON writer: one array with an object per row.
 *
 * @package Open_Export
 */

// No strict_types here on purpose: this file holds moved code, and strict types would change how arguments are coerced.

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- streamed export output; WP_Filesystem cannot append to multi-gigabyte files across hundreds of requests.

/**
 * A JSON array with one object per row, written a batch at a time.
 */
class OPEX_Writer_JSON extends OPEX_Writer {

	/**
	 * Opens the array.
	 */
	public function start(): void {
		$this->append_text( "[\n" );
	}

	/**
	 * Adds one object per row, keyed by column label.
	 *
	 * @param array $rows        Rows keyed by field id.
	 * @param bool  $first_batch True when no row has been written yet, so no comma comes first.
	 */
	public function append( array $rows, bool $first_batch ): void {
		$out   = '';
		$first = $first_batch;
		foreach ( $rows as $row ) {
			$item = array();
			foreach ( $this->headers as $field => $label ) {
				$item[ $label ] = (string) ( $row[ $field ] ?? '' );
			}
			$out  .= ( $first ? '' : ",\n" ) . '  ' . wp_json_encode( $item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$first = false;
		}
		if ( '' !== $out ) {
			$this->append_text( $out );
		}
	}

	/**
	 * Closes the array.
	 */
	public function finish(): void {
		$this->append_text( "\n]\n" );
	}
}

// phpcs:enable
