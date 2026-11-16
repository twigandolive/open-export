<?php
/**
 * Streaming file writers. Each is stateless between batches: the exporter
 * calls start() once, append() per batch, finish() once. Files are opened
 * in append mode so a job can resume after any interruption.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- streamed export output; WP_Filesystem cannot append to multi-gigabyte files across hundreds of requests.

abstract class OPEX_Writer {

	protected string $path;
	protected array $headers;

	public function __construct( string $path, array $headers ) {
		$this->path    = $path;
		$this->headers = $headers;
	}

	public static function make( string $format, string $path, array $headers, array $options = array() ): OPEX_Writer {
		switch ( $format ) {
			case 'json':
				return new OPEX_Writer_JSON( $path, $headers );
			case 'xml':
				$template = $options['xml_template'] ?? array();
				if ( is_array( $template ) && '' !== trim( (string) ( $template['row'] ?? '' ) ) ) {
					return new OPEX_Writer_XML_Template( $path, $headers, $template );
				}
				return new OPEX_Writer_XML( $path, $headers );
			case 'xlsx':
				if ( ! class_exists( 'ZipArchive' ) ) {
					throw new RuntimeException( 'Excel export needs the PHP zip extension, which is not installed on this server.' );
				}
				return new OPEX_Writer_XLSX( $path, $headers );
			default:
				return new OPEX_Writer_CSV(
					$path,
					$headers,
					(string) ( $options['csv_delimiter'] ?? ',' ),
					(string) ( $options['csv_encoding'] ?? 'utf8_bom' ),
					(string) ( $options['csv_eol'] ?? 'lf' )
				);
		}
	}

	/**
	 * Lists the output formats this server can write.
	 *
	 * @return array id => { label, extension, mime, available }.
	 */
	public static function formats(): array {
		return array(
			'csv'  => array(
				'label'     => __( 'CSV', 'open-export' ),
				'extension' => 'csv',
				'mime'      => 'text/csv',
				'available' => true,
			),
			'json' => array(
				'label'     => __( 'JSON', 'open-export' ),
				'extension' => 'json',
				'mime'      => 'application/json',
				'available' => true,
			),
			'xml'  => array(
				'label'     => __( 'XML', 'open-export' ),
				'extension' => 'xml',
				'mime'      => 'application/xml',
				'available' => true,
			),
			'xlsx' => array(
				'label'     => __( 'Excel (XLSX)', 'open-export' ),
				'extension' => 'xlsx',
				'mime'      => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'available' => class_exists( 'ZipArchive' ),
			),
		);
	}

	/**
	 * Escapes text for XML 1.0: drops forbidden control characters, then
	 * encodes markup characters. Shared by every XML-based writer.
	 *
	 * @param string $value      Raw text.
	 * @param bool   $substitute True to replace invalid UTF-8 instead of dropping the value.
	 */
	protected static function xml_escape( string $value, bool $substitute = false ): string {
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value );
		$flags = ENT_XML1 | ENT_QUOTES;
		if ( $substitute ) {
			$flags |= ENT_SUBSTITUTE;
		}
		return htmlspecialchars( (string) $value, $flags, 'UTF-8' );
	}

	/**
	 * Sizes of the files this writer owns, so a batch can be replayed. The
	 * exporter saves this with the cursor and passes it back to restore().
	 *
	 * @return array Keys depend on the writer; `size` is the output file's byte size.
	 */
	public function checkpoint(): array {
		return array( 'size' => self::file_size( $this->path ) );
	}

	/**
	 * Cuts the files back to a checkpoint, dropping anything a failed batch wrote.
	 *
	 * @param array $checkpoint A value returned by checkpoint().
	 * @throws RuntimeException When a file is shorter than the checkpoint says.
	 */
	public function restore( array $checkpoint ): void {
		if ( isset( $checkpoint['size'] ) ) {
			self::truncate_to( $this->path, (int) $checkpoint['size'] );
		}
	}

	/**
	 * Byte size of a file, 0 when it is missing.
	 *
	 * @param string $file Path.
	 */
	protected static function file_size( string $file ): int {
		clearstatcache( true, $file );
		return is_file( $file ) ? (int) filesize( $file ) : 0;
	}

	/**
	 * Truncates a file to an exact size.
	 *
	 * @param string $file Path.
	 * @param int    $size Bytes to keep.
	 * @throws RuntimeException When the file is already shorter or cannot be opened.
	 */
	protected static function truncate_to( string $file, int $size ): void {
		$have = self::file_size( $file );
		if ( $have === $size ) {
			return;
		}
		if ( $have < $size ) {
			throw new RuntimeException( 'The export file is shorter than expected, so the export cannot continue safely. Start a new export.' );
		}
		$handle = fopen( $file, 'r+b' );
		if ( false === $handle || ! ftruncate( $handle, $size ) ) {
			if ( $handle ) {
				fclose( $handle );
			}
			throw new RuntimeException( 'Could not reset the export file.' );
		}
		fclose( $handle );
	}

	/**
	 * Spreadsheet programs run a cell that starts with = + - @ or a tab as a
	 * formula. A leading apostrophe makes it plain text. Plain numbers, such as
	 * a negative refund total, are left alone so they stay numbers.
	 *
	 * @param string $value Cell text.
	 */
	protected static function guard_cell( string $value ): string {
		if ( '' === $value || false === strpos( "=+-@\t\r", $value[0] ) ) {
			return $value;
		}
		if ( 1 === preg_match( '/^-?[0-9]+([.,][0-9]+)?$/', $value ) ) {
			return $value;
		}
		return "'" . $value;
	}

	abstract public function start(): void;

	/** @param array $rows list of assoc arrays keyed by field id. */
	abstract public function append( array $rows, bool $first_batch ): void;

	abstract public function finish(): void;

	protected function open() {
		$handle = fopen( $this->path, 'ab' );
		if ( false === $handle ) {
			throw new RuntimeException( 'Could not open export file for writing.' );
		}
		return $handle;
	}
}

class OPEX_Writer_CSV extends OPEX_Writer {

	private string $delimiter;
	private string $encoding;
	private string $eol;

	public function __construct( string $path, array $headers, string $delimiter = ',', string $encoding = 'utf8_bom', string $eol = 'lf' ) {
		parent::__construct( $path, $headers );
		$this->delimiter = in_array( $delimiter, array( ',', ';', "\t" ), true ) ? $delimiter : ',';
		$this->encoding  = in_array( $encoding, array( 'utf8_bom', 'utf8', 'win1252', 'utf16le' ), true ) ? $encoding : 'utf8_bom';
		$this->eol       = 'crlf' === $eol ? 'crlf' : 'lf';
	}

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

	public function finish(): void {}

	/**
	 * Writes one record, applying the line ending and encoding options.
	 * The default options take the plain fputcsv path, so output is unchanged.
	 *
	 * @param resource $handle Open file handle.
	 * @param array    $fields Cell values.
	 */
	private function put( $handle, array $fields ): void {
		if ( in_array( $this->encoding, array( 'utf8_bom', 'utf8' ), true ) && 'lf' === $this->eol ) {
			fputcsv( $handle, $fields, $this->delimiter, '"', '\\' );
			return;
		}
		$temp = fopen( 'php://temp', 'w+b' );
		if ( false === $temp ) {
			throw new RuntimeException( 'Could not open export file for writing.' );
		}
		fputcsv( $temp, $fields, $this->delimiter, '"', '\\' );
		rewind( $temp );
		$line = (string) stream_get_contents( $temp );
		fclose( $temp );
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

class OPEX_Writer_JSON extends OPEX_Writer {

	public function start(): void {
		$handle = $this->open();
		fwrite( $handle, "[\n" );
		fclose( $handle );
	}

	public function append( array $rows, bool $first_batch ): void {
		$handle = $this->open();
		$first  = $first_batch;
		foreach ( $rows as $row ) {
			$item = array();
			foreach ( $this->headers as $field => $label ) {
				$item[ $label ] = (string) ( $row[ $field ] ?? '' );
			}
			fwrite( $handle, ( $first ? '' : ",\n" ) . '  ' . wp_json_encode( $item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			$first = false;
		}
		fclose( $handle );
	}

	public function finish(): void {
		$handle = $this->open();
		fwrite( $handle, "\n]\n" );
		fclose( $handle );
	}
}

class OPEX_Writer_XML extends OPEX_Writer {

	public function start(): void {
		$handle = $this->open();
		fwrite( $handle, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<records>\n" );
		fclose( $handle );
	}

	public function append( array $rows, bool $first_batch ): void {
		$handle = $this->open();
		foreach ( $rows as $row ) {
			fwrite( $handle, "  <record>\n" );
			foreach ( $this->headers as $field => $label ) {
				$el = $this->element_name( $label );
				fwrite( $handle, "    <{$el}>" . self::xml_escape( (string) ( $row[ $field ] ?? '' ) ) . "</{$el}>\n" );
			}
			fwrite( $handle, "  </record>\n" );
		}
		fclose( $handle );
	}

	public function finish(): void {
		$handle = $this->open();
		fwrite( $handle, "</records>\n" );
		fclose( $handle );
	}

	private function element_name( string $label ): string {
		$name = preg_replace( '/[^A-Za-z0-9_\-]/', '_', $label );
		if ( '' === $name || ! preg_match( '/^[A-Za-z_]/', $name ) ) {
			$name = '_' . $name;
		}
		return $name;
	}
}

// phpcs:enable
