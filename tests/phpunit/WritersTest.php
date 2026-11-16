<?php
/**
 * Tests for the CSV, JSON and XML writers and OPEX_Writer::make and formats.
 *
 * @package OpenExport
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Default output stays byte-identical, and the new CSV options work.
 */
class WritersTest extends TestCase {

	/** @var string[] Files to remove after each test. */
	private array $files = array();

	/**
	 * Removes temp files.
	 */
	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			foreach ( array( '', '.rows', '.rows.n', '.sheet', '.zip.tmp' ) as $suffix ) {
				if ( file_exists( $file . $suffix ) ) {
					unlink( $file . $suffix );
				}
			}
		}
		$this->files = array();
	}

	/**
	 * Returns a fresh temp path that does not exist yet.
	 */
	private function path(): string {
		$path = sys_get_temp_dir() . '/opex-writers-' . uniqid( '', true ) . '.out';
		$this->files[] = $path;
		return $path;
	}

	/**
	 * Runs a full write and returns the bytes.
	 *
	 * @param string $format  Format id.
	 * @param array  $options Writer options.
	 * @param array  $rows    Rows.
	 */
	private function run_writer( string $format, array $options, array $rows ): string {
		$headers = array(
			'core:id'    => 'ID',
			'core:title' => 'Title',
		);
		$path    = $this->path();
		$writer  = OPEX_Writer::make( $format, $path, $headers, $options );
		$writer->start();
		$writer->append( $rows, true );
		$writer->finish();
		return (string) file_get_contents( $path );
	}

	/**
	 * Sample rows.
	 */
	private function rows(): array {
		return array(
			array( 'core:id' => '1', 'core:title' => 'Hello' ),
			array( 'core:id' => '2', 'core:title' => '=1+1' ),
			array( 'core:id' => '3', 'core:title' => "caf\u{E9} \u{1F600}\nline2" ),
		);
	}

	/**
	 * Default CSV bytes.
	 */
	public function test_csv_default_bytes(): void {
		$expected = "\xEF\xBB\xBF" . "ID,Title\n1,Hello\n2,'=1+1\n3,\"caf\xC3\xA9 \xF0\x9F\x98\x80\nline2\"\n";
		$this->assertSame( $expected, $this->run_writer( 'csv', array(), $this->rows() ) );
		$this->assertSame( $expected, $this->run_writer( 'csv', array( 'csv_encoding' => 'utf8_bom', 'csv_eol' => 'lf' ), $this->rows() ) );
		$this->assertSame( $expected, $this->run_writer( 'bogus', array(), $this->rows() ) );
	}

	/**
	 * Semicolon delimiter still works.
	 */
	public function test_csv_delimiter(): void {
		$out = $this->run_writer( 'csv', array( 'csv_delimiter' => ';' ), array( array( 'core:id' => '1', 'core:title' => 'a,b' ) ) );
		$this->assertSame( "\xEF\xBB\xBFID;Title\n1;a,b\n", $out );
	}

	/**
	 * Default JSON bytes.
	 */
	public function test_json_default_bytes(): void {
		$expected = "[\n  {\"ID\":\"1\",\"Title\":\"Hello\"},\n  {\"ID\":\"2\",\"Title\":\"x\"}\n]\n";
		$rows     = array(
			array( 'core:id' => '1', 'core:title' => 'Hello' ),
			array( 'core:id' => '2', 'core:title' => 'x' ),
		);
		$this->assertSame( $expected, $this->run_writer( 'json', array(), $rows ) );
	}

	/**
	 * Default XML bytes.
	 */
	public function test_xml_default_bytes(): void {
		$rows     = array( array( 'core:id' => '1', 'core:title' => "A & \"B\" <c>\x01" ) );
		$expected = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<records>\n  <record>\n    <ID>1</ID>\n    <Title>A &amp; &quot;B&quot; &lt;c&gt;</Title>\n  </record>\n</records>\n";
		$this->assertSame( $expected, $this->run_writer( 'xml', array(), $rows ) );
		$this->assertSame( $expected, $this->run_writer( 'xml', array( 'xml_template' => array( 'header' => '', 'row' => '', 'footer' => '' ) ), $rows ) );
	}

	/**
	 * UTF-8 without BOM.
	 */
	public function test_csv_utf8_no_bom(): void {
		$out = $this->run_writer( 'csv', array( 'csv_encoding' => 'utf8' ), array( array( 'core:id' => '1', 'core:title' => "caf\u{E9}" ) ) );
		$this->assertSame( "ID,Title\n1,caf\xC3\xA9\n", $out );
	}

	/**
	 * CRLF ends records, but a break inside a quoted cell stays LF.
	 */
	public function test_csv_crlf(): void {
		$out = $this->run_writer( 'csv', array( 'csv_encoding' => 'utf8', 'csv_eol' => 'crlf' ), $this->rows() );
		$expected = "ID,Title\r\n1,Hello\r\n2,'=1+1\r\n3,\"caf\xC3\xA9 \xF0\x9F\x98\x80\nline2\"\r\n";
		$this->assertSame( $expected, $out );
	}

	/**
	 * Windows-1252: accent maps, emoji becomes "?", the rest of the line survives.
	 */
	public function test_csv_win1252(): void {
		$out      = $this->run_writer( 'csv', array( 'csv_encoding' => 'win1252', 'csv_eol' => 'crlf' ), $this->rows() );
		$expected = "ID,Title\r\n1,Hello\r\n2,'=1+1\r\n3,\"caf\xE9 ?\nline2\"\r\n";
		$this->assertSame( $expected, $out );
	}

	/**
	 * UTF-16LE with BOM.
	 */
	public function test_csv_utf16le(): void {
		$out  = $this->run_writer( 'csv', array( 'csv_encoding' => 'utf16le', 'csv_eol' => 'crlf' ), $this->rows() );
		$utf8 = "ID,Title\r\n1,Hello\r\n2,'=1+1\r\n3,\"caf\xC3\xA9 \xF0\x9F\x98\x80\nline2\"\r\n";
		$this->assertSame( "\xFF\xFE", substr( $out, 0, 2 ) );
		$this->assertSame( mb_convert_encoding( $utf8, 'UTF-16LE', 'UTF-8' ), substr( $out, 2 ) );
		$this->assertStringContainsString( "\x3D\xD8\x00\xDE", $out, 'emoji as a surrogate pair' );
	}

	/**
	 * Unknown encoding and eol values fall back to the defaults.
	 */
	public function test_csv_bad_options_fall_back(): void {
		$out = $this->run_writer( 'csv', array( 'csv_encoding' => 'nope', 'csv_eol' => 'cr' ), array( array( 'core:id' => '1', 'core:title' => 'a' ) ) );
		$this->assertSame( "\xEF\xBB\xBFID,Title\n1,a\n", $out );
	}

	/**
	 * make picks the template writer only for xml with a row template.
	 */
	public function test_make_picks_writers(): void {
		$h = array( 'a' => 'A' );
		$this->assertInstanceOf( OPEX_Writer_XML_Template::class, OPEX_Writer::make( 'xml', $this->path(), $h, array( 'xml_template' => array( 'row' => '<r/>' ) ) ) );
		$this->assertInstanceOf( OPEX_Writer_XML::class, OPEX_Writer::make( 'xml', $this->path(), $h, array( 'xml_template' => array( 'row' => '  ' ) ) ) );
		$this->assertInstanceOf( OPEX_Writer_CSV::class, OPEX_Writer::make( 'nope', $this->path(), $h ) );
		if ( class_exists( 'ZipArchive' ) ) {
			$this->assertInstanceOf( OPEX_Writer_XLSX::class, OPEX_Writer::make( 'xlsx', $this->path(), $h ) );
		}
	}

	/**
	 * formats() lists the five formats.
	 */
	public function test_formats(): void {
		$formats = OPEX_Writer::formats();
		$this->assertSame( array( 'csv', 'json', 'xml', 'xlsx', 'html' ), array_keys( $formats ) );
		foreach ( $formats as $format ) {
			$this->assertSame( array( 'label', 'extension', 'mime', 'available', 'reason' ), array_keys( $format ) );
		}
		$this->assertTrue( $formats['csv']['available'] );
		$this->assertSame( class_exists( 'ZipArchive' ), $formats['xlsx']['available'] );
		$this->assertSame( 'xlsx', $formats['xlsx']['extension'] );
	}
}
