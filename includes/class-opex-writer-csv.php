<?php
/**
 * CSV writer, with a choice of delimiter, quote character, line ending and
 * encoding. It holds no state between batches, like every writer.
 *
 * @package Open_Export
 */

// No strict_types here on purpose: this file holds moved code, and strict types would change how arguments are coerced.

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- streamed export output; WP_Filesystem cannot append to multi-gigabyte files across hundreds of requests.

/**
 * CSV with a choice of delimiter, quote character, line ending and encoding.
 */
class OPEX_Writer_CSV extends OPEX_Writer {

	/**
	 * Cell separator: comma, semicolon or tab.
	 *
	 * @var string
	 */
	private string $delimiter;

	/**
	 * The encoding: utf8_bom, utf8, win1252 or utf16le.
	 *
	 * @var string
	 */
	private string $encoding;

	/**
	 * Record ending: lf or crlf.
	 *
	 * @var string
	 */
	private string $eol;

	/**
	 * The quote character: a double or a single quote.
	 *
	 * @var string
	 */
	private string $enclosure;

	/**
	 * True to quote every cell, not only the cells that need it.
	 *
	 * @var bool
	 */
	private bool $quote_all;

	/**
	 * Sets up the CSV options. An unknown value falls back to the default.
	 *
	 * @param string $path      Output file path.
	 * @param array  $headers   Column labels by field id.
	 * @param string $delimiter Cell separator.
	 * @param string $encoding  utf8_bom, utf8, win1252 or utf16le.
	 * @param string $eol       lf or crlf.
	 * @param string $enclosure Quote character: " or '.
	 * @param bool   $quote_all True to quote every cell.
	 */
	public function __construct( string $path, array $headers, string $delimiter = ',', string $encoding = 'utf8_bom', string $eol = 'lf', string $enclosure = '"', bool $quote_all = false ) {
		parent::__construct( $path, $headers );
		$this->delimiter = in_array( $delimiter, array( ',', ';', "\t" ), true ) ? $delimiter : ',';
		$this->encoding  = in_array( $encoding, array( 'utf8_bom', 'utf8', 'win1252', 'utf16le' ), true ) ? $encoding : 'utf8_bom';
		$this->eol       = 'crlf' === $eol ? 'crlf' : 'lf';
		$this->enclosure = "'" === $enclosure ? "'" : '"';
		$this->quote_all = $quote_all;
	}

	/**
	 * Writes the byte order mark the encoding needs, then the header row.
	 */
	public function start(): void {
		$handle = $this->open();
		if ( 'utf16le' === $this->encoding ) {
			fwrite( $handle, "\xFF\xFE" );
		} elseif ( 'utf8_bom' === $this->encoding ) {
			// UTF-8 BOM so Excel opens accented characters correctly.
			fwrite( $handle, "\xEF\xBB\xBF" );
		}
		$this->put( $handle, array_map( array( __CLASS__, 'guard_cell' ), array_map( 'strval', array_values( $this->headers ) ) ) );
		fclose( $handle );
	}

	/**
	 * Adds one batch of rows, each cell guarded against formula injection.
	 *
	 * @param array $rows        Rows keyed by field id.
	 * @param bool  $first_batch Unused: CSV rows need no separator.
	 */
	public function append( array $rows, bool $first_batch ): void {
		$handle = $this->open();
		foreach ( $rows as $row ) {
			$line = array();
			foreach ( array_keys( $this->headers ) as $field ) {
				$line[] = self::guard_cell( (string) ( $row[ $field ] ?? '' ) );
			}
			$this->put( $handle, $line );
		}
		fclose( $handle );
	}

	/**
	 * CSV has nothing after the last row.
	 */
	public function finish(): void {}

	/**
	 * Writes one record, applying the line ending and encoding options.
	 *
	 * The escape argument is empty on purpose. With PHP's default backslash,
	 * a cell holding a backslash before a quote is written in a way RFC 4180
	 * readers (Excel, Google Sheets, Python's csv module) read back wrong.
	 * Empty means a quote inside a cell is always doubled, the RFC rule.
	 *
	 * @param resource $handle Open file handle.
	 * @param array    $fields Cell values.
	 */
	private function put( $handle, array $fields ): void {
		if ( ! $this->quote_all && in_array( $this->encoding, array( 'utf8_bom', 'utf8' ), true ) && 'lf' === $this->eol ) {
			fputcsv( $handle, $fields, $this->delimiter, $this->enclosure, '' );
			return;
		}
		$line = $this->quote_all ? $this->quoted_line( $fields ) : $this->csv_line( $fields );
		// Only the record terminator changes: breaks inside a quoted cell stay as they are.
		if ( 'crlf' === $this->eol && "\n" === substr( $line, -1 ) ) {
			$line = substr( $line, 0, -1 ) . "\r\n";
		}
		if ( 'win1252' === $this->encoding ) {
			$line = $this->to_win1252( $line );
		} elseif ( 'utf16le' === $this->encoding ) {
			$line = $this->convert( $line, 'UTF-16LE' );
		}
		fwrite( $handle, $line );
	}

	/**
	 * One record as fputcsv writes it, with only the cells that need it quoted.
	 *
	 * @param array $fields Cell values.
	 * @throws RuntimeException When no temporary stream can be opened.
	 */
	private function csv_line( array $fields ): string {
		$temp = fopen( 'php://temp', 'w+b' );
		if ( false === $temp ) {
			throw new RuntimeException( 'Could not open export file for writing.' );
		}
		fputcsv( $temp, $fields, $this->delimiter, $this->enclosure, '' );
		rewind( $temp );
		$line = (string) stream_get_contents( $temp );
		fclose( $temp );
		return $line;
	}

	/**
	 * One record with every cell quoted. fputcsv cannot force quotes, so the
	 * line is built here: a quote character inside a cell is doubled, which
	 * is how every spreadsheet reads it back.
	 *
	 * @param array $fields Cell values.
	 */
	private function quoted_line( array $fields ): string {
		$q     = $this->enclosure;
		$cells = array();
		foreach ( $fields as $field ) {
			$cells[] = $q . str_replace( $q, $q . $q, (string) $field ) . $q;
		}
		return implode( $this->delimiter, $cells ) . "\n";
	}

	/**
	 * Converts UTF-8 text to another encoding with mbstring or iconv.
	 *
	 * @param string $text UTF-8 text.
	 * @param string $to   Target encoding.
	 */
	private function convert( string $text, string $to ): string {
		if ( function_exists( 'mb_convert_encoding' ) ) {
			return (string) mb_convert_encoding( $text, $to, 'UTF-8' );
		}
		return (string) iconv( 'UTF-8', $to . '//IGNORE', $text );
	}

	/**
	 * Converts a line to Windows-1252 one character at a time. A character
	 * with no mapping is transliterated when possible, else becomes "?".
	 * The rest of the line is never dropped.
	 *
	 * @param string $line UTF-8 line.
	 */
	private function to_win1252( string $line ): string {
		if ( ! preg_match( '/[^\x00-\x7F]/', $line ) ) {
			return $line;
		}
		$chars = preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $chars ) {
			// Invalid UTF-8: keep the line and mark the bad bytes.
			return preg_replace( '/[^\x00-\x7F]/', '?', $line );
		}
		$out = '';
		foreach ( $chars as $char ) {
			$out .= ( $char < "\x80" ) ? $char : $this->char_to_win1252( $char );
		}
		return $out;
	}

	/**
	 * Maps one non-ASCII character to Windows-1252.
	 *
	 * @param string $char One UTF-8 character.
	 */
	private function char_to_win1252( string $char ): string {
		static $cache = array();
		if ( isset( $cache[ $char ] ) ) {
			return $cache[ $char ];
		}
		$result = '?';
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$try = (string) mb_convert_encoding( $char, 'Windows-1252', 'UTF-8' );
			if ( '' !== $try && mb_convert_encoding( $try, 'UTF-8', 'Windows-1252' ) === $char ) {
				$result = $try;
			}
		} elseif ( function_exists( 'iconv' ) ) {
			$try = @iconv( 'UTF-8', 'Windows-1252', $char ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( is_string( $try ) && '' !== $try ) {
				$result = $try;
			}
		}
		if ( '?' === $result && function_exists( 'iconv' ) ) {
			$try = @iconv( 'UTF-8', 'ASCII//TRANSLIT', $char ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( is_string( $try ) && '' !== $try && 1 === preg_match( '/^[A-Za-z0-9 .,\-\'"<>()\/*+=:;!]+$/', $try ) ) {
				$result = $try;
			}
		}
		$cache[ $char ] = $result;
		return $result;
	}
}

// phpcs:enable
