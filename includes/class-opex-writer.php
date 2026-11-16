<?php
/**
 * Streaming file writers. Each is stateless between batches: the exporter
 * calls start() once, append() per batch, finish() once. Files are opened
 * in append mode so a job can resume after any interruption.
 *
 * @package Open_Export
 */

// No strict_types here on purpose: this file holds moved code, and strict types would change how arguments are coerced.

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- streamed export output; WP_Filesystem cannot append to multi-gigabyte files across hundreds of requests.

/**
 * Base of every file writer, with the checkpoint and restore that make a batch safe to replay.
 */
abstract class OPEX_Writer {

	/**
	 * Path of the output file.
	 *
	 * @var string
	 */
	protected string $path;

	/**
	 * Column labels by field id, in output order.
	 *
	 * @var array<string, string>
	 */
	protected array $headers;

	/**
	 * Sets up a writer. Nothing is written until start().
	 *
	 * @param string $path    Output file path.
	 * @param array  $headers Column labels by field id.
	 */
	public function __construct( string $path, array $headers ) {
		$this->path    = $path;
		$this->headers = $headers;
	}

	/**
	 * The writer for a format.
	 *
	 * @param string $format  csv, json, xml, xlsx or html. Anything else gets CSV.
	 * @param string $path    Output file path.
	 * @param array  $headers Column labels by field id.
	 * @param array  $options csv_delimiter, csv_encoding, csv_eol, csv_enclosure,
	 *                        csv_quote_all, xml_template and title.
	 * @return OPEX_Writer The writer.
	 * @throws OPEX_User_Error When Excel is asked for without the zip extension.
	 */
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
				if ( ! OPEX_Compress::available() ) {
					throw new OPEX_User_Error( esc_html__( 'Excel export needs the PHP zip extension, which is not installed on this server.', 'open-export' ) );
				}
				return new OPEX_Writer_XLSX( $path, $headers );
			case 'html':
				return new OPEX_Writer_HTML( $path, $headers, (string) ( $options['title'] ?? '' ) );
			default:
				return new OPEX_Writer_CSV(
					$path,
					$headers,
					(string) ( $options['csv_delimiter'] ?? ',' ),
					(string) ( $options['csv_encoding'] ?? 'utf8_bom' ),
					(string) ( $options['csv_eol'] ?? 'lf' ),
					(string) ( $options['csv_enclosure'] ?? '"' ),
					! empty( $options['csv_quote_all'] )
				);
		}
	}

	/**
	 * Lists the output formats this server can write.
	 *
	 * @return array id => { label, extension, mime, available, reason }. The
	 *               reason says why a format is not available, and is empty
	 *               when it is.
	 */
	public static function formats(): array {
		return array(
			'csv'  => array(
				'label'     => __( 'CSV', 'open-export' ),
				'extension' => 'csv',
				'mime'      => 'text/csv',
				'available' => true,
				'reason'    => '',
			),
			'json' => array(
				'label'     => __( 'JSON', 'open-export' ),
				'extension' => 'json',
				'mime'      => 'application/json',
				'available' => true,
				'reason'    => '',
			),
			'xml'  => array(
				'label'     => __( 'XML', 'open-export' ),
				'extension' => 'xml',
				'mime'      => 'application/xml',
				'available' => true,
				'reason'    => '',
			),
			'xlsx' => array(
				'label'     => __( 'Excel (XLSX)', 'open-export' ),
				'extension' => 'xlsx',
				'mime'      => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'available' => OPEX_Compress::available(),
				'reason'    => OPEX_Compress::available() ? '' : __( 'Excel files need the PHP zip extension, which this server does not have.', 'open-export' ),
			),
			'html' => array(
				'label'     => __( 'HTML table', 'open-export' ),
				'extension' => 'html',
				'mime'      => 'text/html',
				'available' => true,
				'reason'    => '',
			),
		);
	}

	/**
	 * Escapes text for XML 1.0: drops forbidden control characters, then
	 * encodes markup characters. Shared by every XML-based writer.
	 *
	 * @param string $value      Raw text.
	 * @param bool   $substitute True to replace invalid UTF-8 instead of dropping the value.
	 * @return string The escaped text.
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
	 * @return int Size in bytes.
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
	 * @throws OPEX_User_Error When the file is already shorter.
	 * @throws RuntimeException When the file cannot be opened.
	 */
	protected static function truncate_to( string $file, int $size ): void {
		$have = self::file_size( $file );
		if ( $have === $size ) {
			return;
		}
		if ( $have < $size ) {
			throw new OPEX_User_Error( esc_html__( 'The export file is shorter than expected, so the export cannot continue safely. Start a new export.', 'open-export' ) );
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
	 * @return string The cell text, safe from formula use.
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

	/**
	 * Creates the file and writes what comes before the first row.
	 */
	abstract public function start(): void;

	/**
	 * Adds one batch of rows after the ones already written.
	 *
	 * @param array $rows        Rows keyed by field id.
	 * @param bool  $first_batch True for the first batch, for formats that separate rows.
	 */
	abstract public function append( array $rows, bool $first_batch ): void;

	/**
	 * Writes what comes after the last row.
	 */
	abstract public function finish(): void;

	/**
	 * Opens the output file for appending. Each call is a fresh open, because
	 * the next batch may run in another PHP process.
	 *
	 * @return resource The open handle.
	 * @throws RuntimeException When the file cannot be opened.
	 */
	protected function open() {
		$handle = fopen( $this->path, 'ab' );
		if ( false === $handle ) {
			throw new RuntimeException( 'Could not open export file for writing.' );
		}
		return $handle;
	}

	/**
	 * Writes every byte of the text, or throws. A full disk or a quota can
	 * make fwrite() write only part of the text, so the rest is written in
	 * a loop. A failed write, or one that writes nothing, stops the export,
	 * so a cut-off file is never reported as finished.
	 *
	 * @param resource $handle Open file handle.
	 * @param string   $data   Text to write.
	 * @return void
	 * @throws OPEX_User_Error When the text cannot be written in full.
	 */
	protected function write_all( $handle, string $data ): void {
		$total = strlen( $data );
		$done  = 0;
		while ( $done < $total ) {
			$wrote = $this->write_chunk( $handle, 0 === $done ? $data : substr( $data, $done ) );
			if ( false === $wrote || $wrote <= 0 ) {
				throw self::write_failed();
			}
			$done += $wrote;
		}
	}

	/**
	 * The error for output that could not be written or built in full. Every
	 * writer throws this one, so a person sees the same message for each.
	 *
	 * @return OPEX_User_Error The error to throw.
	 */
	protected static function write_failed(): OPEX_User_Error {
		return new OPEX_User_Error( esc_html__( 'The export file could not be written. The disk may be full.', 'open-export' ) );
	}

	/**
	 * Writes the whole text to a fresh handle on the output file and closes it.
	 *
	 * @param string $data Text to append.
	 * @return void
	 * @throws OPEX_User_Error When the text cannot be written in full.
	 */
	protected function append_text( string $data ): void {
		$handle = $this->open();
		try {
			$this->write_all( $handle, $data );
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * One fwrite() call. Kept apart so a test can stand in for a full disk.
	 *
	 * @param resource $handle Open file handle.
	 * @param string   $data   Text to write.
	 * @return int|false Bytes written, or false on failure.
	 */
	protected function write_chunk( $handle, string $data ) {
		return fwrite( $handle, $data );
	}
}

// phpcs:enable
