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
	 * Memory stream that fputcsv writes each record into, opened on first use.
	 *
	 * @var resource|null
	 */
	private $temp = null;

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
	 *
	 * @return void
	 */
	public function start(): void {
		$bom = '';
		if ( 'utf16le' === $this->encoding ) {
			$bom = "\xFF\xFE";
		} elseif ( 'utf8_bom' === $this->encoding ) {
			// UTF-8 BOM so Excel opens accented characters correctly.
			$bom = "\xEF\xBB\xBF";
		}
		$this->append_text( $bom . $this->record( array_map( 'strval', array_values( $this->headers ) ) ) );
	}

	/**
	 * Adds one batch of rows, each cell guarded against formula injection.
	 *
	 * @param array $rows        Rows keyed by field id.
	 * @param bool  $first_batch Unused: CSV rows need no separator.
	 * @return void
	 */
	public function append( array $rows, bool $first_batch ): void {
		$out = '';
		foreach ( $rows as $row ) {
			$line = array();
			foreach ( array_keys( $this->headers ) as $field ) {
				$line[] = (string) ( $row[ $field ] ?? '' );
			}
			$out .= $this->record( $line );
		}
		if ( '' !== $out ) {
			$this->append_text( $out );
		}
	}

	/**
	 * CSV has nothing after the last row.
	 *
	 * @return void
	 */
	public function finish(): void {}

	/**
	 * One record as bytes in the chosen encoding, line ending included.
	 *
	 * Each cell is put into the target encoding first. The formula guard and
	 * the quoting then see the characters the file will really hold, so a
	 * character that becomes a comma, a quote or "=" in Windows-1252 is
	 * quoted or guarded like any other. Nothing after the quoting changes
	 * which characters a cell holds.
	 *
	 * The escape argument is empty on purpose. With PHP's default backslash,
	 * a cell holding a backslash before a quote is written in a way RFC 4180
	 * readers (Excel, Google Sheets, Python's csv module) read back wrong.
	 * Empty means a quote inside a cell is always doubled, the RFC rule.
	 *
	 * @param array $fields Raw cell values, not yet guarded.
	 * @return string The record, ready to write.
	 */
	private function record( array $fields ): string {
		$cells = array();
		foreach ( $fields as $field ) {
			$cells[] = self::guard_cell( $this->encode_cell( (string) $field ) );
		}
		$line = $this->quote_all ? $this->quoted_line( $cells ) : $this->csv_line( $cells );
		// Only the record terminator changes: breaks inside a quoted cell stay as they are.
		if ( 'crlf' === $this->eol && "\n" === substr( $line, -1 ) ) {
			$line = substr( $line, 0, -1 ) . "\r\n";
		}
		if ( 'utf16le' === $this->encoding ) {
			// The cells are valid UTF-8 by now, so this maps each character one to one.
			$line = $this->convert( $line, 'UTF-8', 'UTF-16LE' );
		}
		return $line;
	}

	/**
	 * One cell in the target encoding, before any quoting.
	 *
	 * Windows-1252 cells become their final single-byte form here. Quoting
	 * works on those bytes, because every special character is ASCII and
	 * Windows-1252 keeps ASCII as it is. UTF-16 LE cells go through UTF-16
	 * and back, so text that cannot be encoded is replaced or dropped now,
	 * before the guard and the quoting look at it.
	 *
	 * @param string $cell UTF-8 cell text.
	 * @return string The cell, ready for the guard and the quoting.
	 */
	private function encode_cell( string $cell ): string {
		if ( 'win1252' === $this->encoding ) {
			return $this->to_win1252( $cell );
		}
		if ( 'utf16le' === $this->encoding && '' !== $cell ) {
			return $this->convert( $this->convert( $cell, 'UTF-8', 'UTF-16LE' ), 'UTF-16LE', 'UTF-8' );
		}
		return $cell;
	}

	/**
	 * One record as fputcsv writes it, with only the cells that need it quoted.
	 *
	 * @param array $fields Cell values.
	 * @return string The line as fputcsv writes it.
	 * @throws RuntimeException When no temporary stream can be opened.
	 */
	private function csv_line( array $fields ): string {
		if ( null === $this->temp ) {
			$temp = fopen( 'php://temp', 'w+b' );
			if ( false === $temp ) {
				throw new RuntimeException( 'Could not open export file for writing.' );
			}
			$this->temp = $temp;
		}
		// One memory stream serves every record of the batch: it is emptied before each one.
		ftruncate( $this->temp, 0 );
		rewind( $this->temp );
		fputcsv( $this->temp, $fields, $this->delimiter, $this->enclosure, '' );
		rewind( $this->temp );
		return (string) stream_get_contents( $this->temp );
	}

	/**
	 * One record with every cell quoted. fputcsv cannot force quotes, so the
	 * line is built here: a quote character inside a cell is doubled, which
	 * is how every spreadsheet reads it back.
	 *
	 * @param array $fields Cell values.
	 * @return string The line, ending with a line break.
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
	 * Converts text between encodings with mbstring or iconv.
	 *
	 * @param string $text Text to convert.
	 * @param string $from Its encoding.
	 * @param string $to   Target encoding.
	 * @return string The converted text.
	 */
	private function convert( string $text, string $from, string $to ): string {
		if ( function_exists( 'mb_convert_encoding' ) ) {
			return (string) mb_convert_encoding( $text, $to, $from );
		}
		return (string) iconv( $from, $to . '//IGNORE', $text );
	}

	/**
	 * Converts one cell to Windows-1252 one character at a time. A character
	 * with no mapping is transliterated when possible, else becomes "?".
	 * The rest of the cell is never dropped.
	 *
	 * @param string $line UTF-8 cell text.
	 * @return string The cell in Windows-1252.
	 */
	private function to_win1252( string $line ): string {
		if ( ! preg_match( '/[^\x00-\x7F]/', $line ) ) {
			return $line;
		}
		$chars = preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $chars ) {
			// Invalid UTF-8: keep the cell and mark the bad bytes.
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
	 * @return string The character in Windows-1252, or a stand-in.
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
