<?php
/**
 * Streaming XLSX writer. No library: rows are appended as ready-made XML to
 * a sidecar file during the job, and finish() zips the workbook.
 *
 * @package OpenExport
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- streamed export output across many requests.

/**
 * Writes one sheet with a bold header row and inline strings.
 */
class OPEX_Writer_XLSX extends OPEX_Writer {

	/** Most rows one Excel sheet can hold, header included. */
	const MAX_ROWS = 1048576;

	/** Most columns one Excel sheet can hold. */
	const MAX_COLS = 16384;

	/**
	 * Every work file the writer keeps next to an Excel file: the row XML,
	 * the next row number, the sheet and the zip being built. Each ends in
	 * the on-disk ending when the Excel file does (see OPEX_Jobs::beside), so
	 * no web server serves one as a static file.
	 *
	 * @param string $path Excel file path.
	 * @return string[] Rows, row number, sheet and zip paths, in that order.
	 */
	public static function work_files( string $path ): array {
		return array(
			OPEX_Jobs::beside( $path, '.rows' ),
			OPEX_Jobs::beside( $path, '.rows.n' ),
			OPEX_Jobs::beside( $path, '.sheet' ),
			OPEX_Jobs::beside( $path, '.zip.tmp' ),
		);
	}

	/**
	 * Path of the sidecar that holds the row XML.
	 *
	 * @return string The sidecar path.
	 */
	private function rows_path(): string {
		return self::work_files( $this->path )[0];
	}

	/**
	 * Path of the small file that holds the next row number.
	 *
	 * @return string The counter file path.
	 */
	private function counter_path(): string {
		return self::work_files( $this->path )[1];
	}

	/**
	 * Starts a new workbook: resets the sidecars and writes the header row.
	 *
	 * @throws OPEX_User_Error When there are more columns than a sheet holds.
	 */
	public function start(): void {
		if ( count( $this->headers ) > self::MAX_COLS ) {
			throw new OPEX_User_Error( esc_html__( 'Excel sheets hold at most 16,384 columns. Choose fewer fields or use CSV.', 'open-export' ) );
		}
		$handle = $this->open();
		fclose( $handle );
		$this->write_file( $this->rows_path(), '' );
		$this->write_file( $this->counter_path(), '1' );
		$this->write_rows( array( array_values( $this->headers ) ), true );
	}

	/**
	 * Sizes of the output file and the sidecar, plus the next row number.
	 * The output file stays empty until finish() builds the zip.
	 *
	 * @return array The file sizes and the next row number.
	 */
	public function checkpoint(): array {
		$counter = $this->counter_path();
		return array(
			'size'      => self::file_size( $this->path ),
			'rows_size' => self::file_size( $this->rows_path() ),
			'next'      => is_file( $counter ) ? (int) file_get_contents( $counter ) : 0,
		);
	}

	/**
	 * Cuts the sidecar back to the checkpoint and resets the row counter.
	 * When the sidecar is gone the workbook was already built, so there is
	 * nothing left to undo.
	 *
	 * @param array $checkpoint A value returned by checkpoint().
	 */
	public function restore( array $checkpoint ): void {
		if ( ! isset( $checkpoint['rows_size'], $checkpoint['next'] ) || ! is_file( $this->rows_path() ) ) {
			return;
		}
		self::truncate_to( $this->rows_path(), (int) $checkpoint['rows_size'] );
		$this->write_file( $this->counter_path(), (string) max( 1, (int) $checkpoint['next'] ) );
	}

	/**
	 * Adds one batch of rows after the ones already stored.
	 *
	 * @param array $rows        Rows keyed by field id.
	 * @param bool  $first_batch Unused.
	 */
	public function append( array $rows, bool $first_batch ): void {
		if ( ! $rows ) {
			return;
		}
		$lines = array();
		foreach ( $rows as $row ) {
			$line = array();
			foreach ( array_keys( $this->headers ) as $field ) {
				$line[] = (string) ( $row[ $field ] ?? '' );
			}
			$lines[] = $line;
		}
		$this->write_rows( $lines, false );
	}

	/**
	 * Builds the zip at the export path and removes the sidecars. Safe to
	 * call twice: the second call finds no sidecar and does nothing.
	 *
	 * @throws RuntimeException When the zip cannot be written.
	 */
	public function finish(): void {
		$rows = $this->rows_path();
		if ( ! is_file( $rows ) ) {
			return;
		}
		$sheet = self::work_files( $this->path )[2];
		$tmp   = self::work_files( $this->path )[3];
		$this->build_sheet( $rows, $sheet );

		$zip = new ZipArchive();
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			@unlink( $sheet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			throw new RuntimeException( 'Could not create the Excel file.' );
		}
		$ok = $zip->addFromString( '[Content_Types].xml', self::content_types() )
			&& $zip->addFromString( '_rels/.rels', self::root_rels() )
			&& $zip->addFromString( 'xl/workbook.xml', self::workbook() )
			&& $zip->addFromString( 'xl/_rels/workbook.xml.rels', self::workbook_rels() )
			&& $zip->addFromString( 'xl/styles.xml', self::styles() )
			&& $zip->addFile( $sheet, 'xl/worksheets/sheet1.xml' );
		// ZipArchive reads the sheet file at close(), so delete it only afterwards.
		$closed = $ok && $zip->close();
		@unlink( $sheet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $closed || ! rename( $tmp, $this->path ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			throw new RuntimeException( 'Could not write the Excel file.' );
		}
		@unlink( $rows ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		@unlink( $this->counter_path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * Writes the full sheet XML to a file, streaming the sidecar into it.
	 *
	 * @param string $rows  Sidecar path.
	 * @param string $sheet Output path.
	 * @return void
	 * @throws RuntimeException When a file cannot be opened or read, or (as
	 *                          OPEX_User_Error) when the sheet cannot be written in full.
	 */
	private function build_sheet( string $rows, string $sheet ): void {
		$out = fopen( $sheet, 'wb' );
		$in  = fopen( $rows, 'rb' );
		if ( false === $out || false === $in ) {
			throw new RuntimeException( 'Could not write the Excel file.' );
		}
		$done = false;
		try {
			$this->write_all(
				$out,
				'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
				. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
			);
			// Copied in chunks through write_all, so a short write on a full disk stops the build.
			while ( ! feof( $in ) ) {
				$chunk = fread( $in, 1048576 );
				if ( false === $chunk ) {
					throw new RuntimeException( 'Could not read the Excel export data.' );
				}
				$this->write_all( $out, $chunk );
			}
			$this->write_all( $out, '</sheetData></worksheet>' );
			$done = true;
		} finally {
			fclose( $in );
			fclose( $out );
			if ( ! $done ) {
				@unlink( $sheet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
	}

	/**
	 * Converts lines to row XML and appends them to the sidecar.
	 *
	 * @param array $lines  List of lists of string cell values.
	 * @param bool  $header True for the bold header row.
	 * @return void
	 * @throws OPEX_User_Error When the sheet is full, was never started, or the
	 *                         sidecar cannot be written in full.
	 * @throws RuntimeException When the sidecar cannot be opened.
	 */
	private function write_rows( array $lines, bool $header ): void {
		$counter = $this->counter_path();
		$next    = is_file( $counter ) ? (int) file_get_contents( $counter ) : 0;
		if ( $next < 1 ) {
			throw new OPEX_User_Error( esc_html__( 'The Excel export was not started. Start the export again.', 'open-export' ) );
		}
		$xml = '';
		foreach ( $lines as $line ) {
			if ( $next > self::MAX_ROWS ) {
				throw new OPEX_User_Error( esc_html__( 'This export has more rows than one Excel sheet can hold (1,048,576). Use CSV, or narrow the filters.', 'open-export' ) );
			}
			$xml .= $this->row_xml( $next, $line, $header );
			++$next;
		}
		$handle = fopen( $this->rows_path(), 'ab' );
		if ( false === $handle ) {
			throw new RuntimeException( 'Could not write the Excel export data.' );
		}
		try {
			$this->write_all( $handle, $xml );
		} finally {
			fclose( $handle );
		}
		// The row counter moves on only after every row reached the sidecar.
		$this->write_file( $counter, (string) $next );
	}

	/**
	 * Builds one row element.
	 *
	 * @param int   $n      Row number, starting at 1.
	 * @param array $values Cell values.
	 * @param bool  $header True to apply the bold style.
	 * @return string The row element.
	 */
	private function row_xml( int $n, array $values, bool $header ): string {
		$xml   = '<row r="' . $n . '">';
		$col   = 0;
		$style = $header ? ' s="1"' : '';
		foreach ( $values as $value ) {
			$ref = self::column_name( $col ) . $n;
			++$col;
			if ( '' === $value ) {
				continue;
			}
			if ( ! $header && self::is_exact_number( $value ) ) {
				$xml .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
				continue;
			}
			$xml .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">'
				. self::xml_escape( $value, true ) . '</t></is></c>';
		}
		return $xml . "</row>\n";
	}

	/**
	 * Whether a value can be a number cell without changing. Excel keeps 15
	 * significant digits, so a value with more, counted over the whole number
	 * without its leading zeros, stays text. Leading-zero ids such as 007 are
	 * text too, because Excel would drop the zeros.
	 *
	 * @param string $value Cell text.
	 * @return bool True when Excel shows exactly this number.
	 */
	private static function is_exact_number( string $value ): bool {
		if ( 1 !== preg_match( '/^-?(0|[1-9][0-9]*)(?:\.([0-9]+))?$/', $value, $m ) ) {
			return false;
		}
		$digits = ltrim( $m[1] . ( $m[2] ?? '' ), '0' );
		return strlen( $digits ) <= 15;
	}

	/**
	 * Converts a zero-based column index to letters (0 = A, 26 = AA).
	 *
	 * @param int $index Column index.
	 * @return string The column letters.
	 */
	private static function column_name( int $index ): string {
		$name = '';
		++$index;
		while ( $index > 0 ) {
			$rem   = ( $index - 1 ) % 26;
			$name  = chr( 65 + $rem ) . $name;
			$index = intdiv( $index - 1, 26 );
		}
		return $name;
	}

	/**
	 * Replaces a small file in one locked write.
	 *
	 * @param string $file    Path.
	 * @param string $content Content.
	 * @return void
	 * @throws RuntimeException When the file cannot be written.
	 * @throws OPEX_User_Error When only part of the content was written.
	 */
	private function write_file( string $file, string $content ): void {
		$wrote = file_put_contents( $file, $content, LOCK_EX );
		if ( false === $wrote ) {
			throw new RuntimeException( 'Could not write the Excel export data.' );
		}
		if ( strlen( $content ) !== $wrote ) {
			throw new OPEX_User_Error( esc_html__( 'Open Export could not write the export file. The disk may be full.', 'open-export' ) );
		}
	}

	/**
	 * Package content types part.
	 *
	 * @return string The XML of the part.
	 */
	private static function content_types(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. '</Types>';
	}

	/**
	 * Root relationships part.
	 *
	 * @return string The XML of the part.
	 */
	private static function root_rels(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';
	}

	/**
	 * Workbook part.
	 *
	 * @return string The XML of the part.
	 */
	private static function workbook(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets><sheet name="Export" sheetId="1" r:id="rId1"/></sheets></workbook>';
	}

	/**
	 * Workbook relationships part.
	 *
	 * @return string The XML of the part.
	 */
	private static function workbook_rels(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '</Relationships>';
	}

	/**
	 * Styles part: style 0 is plain, style 1 is bold.
	 *
	 * @return string The XML of the part.
	 */
	private static function styles(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
			. '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
			. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
			. '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			. '</styleSheet>';
	}
}

// phpcs:enable
