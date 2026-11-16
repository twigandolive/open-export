<?php
/**
 * Every writer path checks that every byte was written. A
 * short write that stops making progress (a full disk) throws, and a short
 * write that keeps making progress is finished in a loop.
 *
 * The seam is OPEX_Writer::write_chunk(): each test writer below replaces
 * it with one that writes less than asked, the way fwrite() does on a full disk.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Shared state for the short-write seam (a trait's statics are per class).
 */
class CodexShortWriteMode {

	/**
	 * Mode: 'ok' writes all, 'halves' writes half of each call, 'stall'
	 * writes 1 byte once and then nothing, 'fail' returns false.
	 *
	 * @var string
	 */
	public static $mode = 'ok';

	/**
	 * Calls made while the mode is not ok.
	 *
	 * @var int
	 */
	public static $calls = 0;
}

/**
 * Stand-in for fwrite() on a disk that fills up.
 */
trait CodexShortWrites {

	/**
	 * Writes less than asked, by mode.
	 *
	 * @param resource $handle Handle.
	 * @param string   $data   Text.
	 * @return int|false Bytes written.
	 */
	protected function write_chunk( $handle, string $data ) {
		$mode = CodexShortWriteMode::$mode;
		if ( 'ok' === $mode ) {
			return fwrite( $handle, $data );
		}
		++CodexShortWriteMode::$calls;
		if ( 'fail' === $mode ) {
			return false;
		}
		if ( 'stall' === $mode ) {
			return 1 === CodexShortWriteMode::$calls ? fwrite( $handle, substr( $data, 0, 1 ) ) : 0;
		}
		return fwrite( $handle, substr( $data, 0, max( 1, intdiv( strlen( $data ), 2 ) ) ) );
	}
}

/**
 * Short writes in every writer.
 */
class CodexWriterShortWriteTest extends TestCase {

	private string $path = '';

	private array $headers = array(
		'a' => 'Alpha',
		'b' => 'Beta',
	);

	protected function setUp(): void {
		$this->path                = sys_get_temp_dir() . '/opex-short-' . bin2hex( random_bytes( 6 ) );
		CodexShortWriteMode::$mode = 'ok';
	}

	protected function tearDown(): void {
		CodexShortWriteMode::$mode = 'ok';
		foreach ( (array) glob( $this->path . '*' ) as $file ) {
			unlink( $file );
		}
	}

	/**
	 * A writer of a format with the short-write seam.
	 *
	 * @param string $format Format.
	 * @return OPEX_Writer
	 */
	private function writer( string $format ): OPEX_Writer {
		$p = $this->path . '.' . $format;
		$h = $this->headers;
		switch ( $format ) {
			case 'csv':
				return new class( $p, $h ) extends OPEX_Writer_CSV {
					use CodexShortWrites;
				};
			case 'csv16':
				return new class( $p, $h, ';', 'utf16le', 'crlf', '"', true ) extends OPEX_Writer_CSV {
					use CodexShortWrites;
				};
			case 'json':
				return new class( $p, $h ) extends OPEX_Writer_JSON {
					use CodexShortWrites;
				};
			case 'xml':
				return new class( $p, $h ) extends OPEX_Writer_XML {
					use CodexShortWrites;
				};
			case 'xmlt':
				return new class( $p, $h, array( 'header' => '<list>', 'row' => '<i a="{a}">{b|cdata}</i>', 'footer' => '</list>' ) ) extends OPEX_Writer_XML_Template {
					use CodexShortWrites;
				};
			case 'html':
				return new class( $p, $h, 'T' ) extends OPEX_Writer_HTML {
					use CodexShortWrites;
				};
			default:
				return new class( $p, $h ) extends OPEX_Writer_XLSX {
					use CodexShortWrites;
				};
		}
	}

	/**
	 * The formats under test.
	 *
	 * @return array
	 */
	public static function formats(): array {
		$out = array();
		foreach ( array( 'csv', 'csv16', 'json', 'xml', 'xmlt', 'html', 'xlsx' ) as $f ) {
			$out[ $f ] = array( $f );
		}
		return $out;
	}

	/**
	 * Rows for one batch.
	 *
	 * @return array
	 */
	private function rows(): array {
		return array(
			array(
				'a' => '1',
				'b' => str_repeat( 'long text ', 40 ),
			),
			array(
				'a' => '2',
				'b' => 'two',
			),
		);
	}

	/**
	 * Writes a whole file in the given mode for every call.
	 *
	 * @param string $format Format.
	 * @param string $mode   Seam mode.
	 * @return string The output bytes.
	 */
	private function whole( string $format, string $mode ): string {
		if ( 'xlsx' === $format && ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}
		CodexShortWriteMode::$mode = $mode;
		$this->writer( $format )->start();
		$this->writer( $format )->append( $this->rows(), true );
		$this->writer( $format )->finish();
		CodexShortWriteMode::$mode = 'ok';
		$file  = $this->path . '.' . $format;
		$bytes = (string) file_get_contents( $file );
		unlink( $file );
		return $bytes;
	}

	/**
	 * @dataProvider formats
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'formats' )]
	public function test_partial_writes_are_finished_in_a_loop( string $format ) {
		$full = $this->whole( $format, 'ok' );
		$loop = $this->whole( $format, 'halves' );
		if ( 'xlsx' === $format ) {
			$this->assertSame( $this->sheet( $full ), $this->sheet( $loop ) );
			return;
		}
		$this->assertSame( $full, $loop );
	}

	/**
	 * @dataProvider formats
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'formats' )]
	public function test_a_stalled_write_in_start_throws( string $format ) {
		CodexShortWriteMode::$mode  = 'stall';
		CodexShortWriteMode::$calls = 0;
		$this->expectException( OPEX_User_Error::class );
		$this->expectExceptionMessage( 'Open Export could not write the export file. The disk may be full.' );
		$this->writer( $format )->start();
	}

	/**
	 * @dataProvider formats
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'formats' )]
	public function test_a_failed_write_in_append_throws( string $format ) {
		$this->writer( $format )->start();
		CodexShortWriteMode::$mode  = 'stall';
		CodexShortWriteMode::$calls = 0;
		try {
			$this->writer( $format )->append( $this->rows(), true );
			$this->fail( 'A stalled write must stop the batch.' );
		} catch ( OPEX_User_Error $e ) {
			$this->assertSame( 'Open Export could not write the export file. The disk may be full.', $e->getMessage() );
		}
		CodexShortWriteMode::$mode  = 'fail';
		CodexShortWriteMode::$calls = 0;
		$this->expectException( OPEX_User_Error::class );
		$this->writer( $format )->append( $this->rows(), false );
	}

	public function test_xlsx_row_counter_moves_only_after_a_full_write() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}
		$this->writer( 'xlsx' )->start();
		$before = $this->writer( 'xlsx' )->checkpoint();
		$this->assertSame( 2, $before['next'] );
		CodexShortWriteMode::$mode  = 'stall';
		CodexShortWriteMode::$calls = 0;
		try {
			$this->writer( 'xlsx' )->append( $this->rows(), true );
			$this->fail( 'A stalled write must stop the batch.' );
		} catch ( OPEX_User_Error $e ) {
			$this->assertStringContainsString( 'disk may be full', $e->getMessage() );
		}
		CodexShortWriteMode::$mode = 'ok';
		$this->assertSame( 2, $this->writer( 'xlsx' )->checkpoint()['next'], 'the counter did not move' );
		// The engine restores the checkpoint and replays the batch: the result is whole.
		$this->writer( 'xlsx' )->restore( $before );
		$this->writer( 'xlsx' )->append( $this->rows(), true );
		$this->writer( 'xlsx' )->finish();
		$sheet = $this->sheet( (string) file_get_contents( $this->path . '.xlsx' ) );
		$this->assertSame( 3, substr_count( $sheet, '<row ' ) );
	}

	public function test_xlsx_sheet_build_stops_on_a_stalled_write() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}
		$this->writer( 'xlsx' )->start();
		$this->writer( 'xlsx' )->append( $this->rows(), true );
		CodexShortWriteMode::$mode  = 'stall';
		CodexShortWriteMode::$calls = 0;
		try {
			$this->writer( 'xlsx' )->finish();
			$this->fail( 'A stalled write must stop the build.' );
		} catch ( OPEX_User_Error $e ) {
			$this->assertStringContainsString( 'disk may be full', $e->getMessage() );
		}
		$this->assertFileDoesNotExist( $this->path . '.xlsx.sheet' );
		$this->assertFileExists( $this->path . '.xlsx.rows', 'the rows are kept for another try' );
		$this->assertSame( 0, filesize( $this->path . '.xlsx' ) );
	}

	/**
	 * The sheet XML from xlsx bytes.
	 *
	 * @param string $bytes Zip bytes.
	 * @return string Sheet XML.
	 */
	private function sheet( string $bytes ): string {
		$tmp = $this->path . '.read.zip';
		file_put_contents( $tmp, $bytes );
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $tmp ) );
		$xml = (string) $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$zip->close();
		unlink( $tmp );
		return $xml;
	}
}
