<?php
/**
 * Tests for the streaming XLSX writer.
 *
 * @package OpenExport
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Writes across separate writer instances, as the engine does.
 */
class WriterXlsxTest extends TestCase {

	private string $path = '';

	/**
	 * Skips when ZipArchive is missing.
	 */
	protected function setUp(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}
		$this->path = sys_get_temp_dir() . '/opex-xlsx-' . uniqid( '', true ) . '.xlsx';
	}

	/**
	 * Removes the file and sidecars.
	 */
	protected function tearDown(): void {
		foreach ( array( '', '.rows', '.rows.n', '.sheet', '.zip.tmp' ) as $suffix ) {
			if ( '' !== $this->path && file_exists( $this->path . $suffix ) ) {
				unlink( $this->path . $suffix );
			}
		}
	}

	/**
	 * Builds a writer instance.
	 */
	private function writer(): OPEX_Writer {
		return OPEX_Writer::make( 'xlsx', $this->path, array( 'core:id' => 'ID', 'core:title' => 'Title' ) );
	}

	/**
	 * Writes a full file in separate instances.
	 */
	private function write_three_batches(): void {
		$this->writer()->start();
		$this->writer()->append( array( array( 'core:id' => '007', 'core:title' => 'a<&>"' . "\x01" . 'b' ) ), true );
		$this->writer()->append( array( array( 'core:id' => '42', 'core:title' => 'two' ) ), false );
		$this->writer()->append( array( array( 'core:id' => '-1.5', 'core:title' => '' ) ), false );
		$this->writer()->finish();
	}

	/**
	 * Reads sheet1 into a DOMXPath.
	 */
	private function sheet(): DOMXPath {
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $this->path ) );
		$xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$zip->close();
		$this->assertIsString( $xml );
		$doc = new DOMDocument();
		$this->assertTrue( $doc->loadXML( $xml ) );
		$xp = new DOMXPath( $doc );
		$xp->registerNamespace( 'm', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
		return $xp;
	}

	/**
	 * Rows, header, text, numbers and escaping survive three requests.
	 */
	public function test_rows_across_instances(): void {
		$this->write_three_batches();
		$xp = $this->sheet();
		$this->assertSame( 4, $xp->query( '//m:sheetData/m:row' )->length );
		$this->assertSame( array( '1', '2', '3', '4' ), array_map( static fn( $n ) => $n->getAttribute( 'r' ), iterator_to_array( $xp->query( '//m:row' ) ) ) );
		$this->assertSame( 'ID', $xp->query( '//m:c[@r="A1"]//m:t' )->item( 0 )->textContent );
		$this->assertSame( 'Title', $xp->query( '//m:c[@r="B1"]//m:t' )->item( 0 )->textContent );
		$this->assertSame( '1', $xp->query( '//m:c[@r="A1"]' )->item( 0 )->getAttribute( 's' ) );
		$this->assertSame( 'inlineStr', $xp->query( '//m:c[@r="A2"]' )->item( 0 )->getAttribute( 't' ) );
		$this->assertSame( '007', $xp->query( '//m:c[@r="A2"]//m:t' )->item( 0 )->textContent );
		$this->assertSame( 'a<&>"b', $xp->query( '//m:c[@r="B2"]//m:t' )->item( 0 )->textContent );
		$this->assertSame( '', $xp->query( '//m:c[@r="A3"]' )->item( 0 )->getAttribute( 't' ) );
		$this->assertSame( '42', $xp->query( '//m:c[@r="A3"]/m:v' )->item( 0 )->textContent );
		$this->assertSame( '-1.5', $xp->query( '//m:c[@r="A4"]/m:v' )->item( 0 )->textContent );
		$this->assertSame( 0, $xp->query( '//m:c[@r="B4"]' )->length );
		$this->assertFileDoesNotExist( $this->path . '.rows' );
		$this->assertFileDoesNotExist( $this->path . '.rows.n' );
		$this->assertFileDoesNotExist( $this->path . '.sheet' );
	}

	/**
	 * The package has the required parts and a bold style.
	 */
	public function test_package_parts(): void {
		$this->write_three_batches();
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $this->path ) );
		foreach ( array( '[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml' ) as $part ) {
			$xml = $zip->getFromName( $part );
			$this->assertIsString( $xml, $part );
			$doc = new DOMDocument();
			$this->assertTrue( $doc->loadXML( $xml ), $part );
		}
		$this->assertStringContainsString( '<b/>', (string) $zip->getFromName( 'xl/styles.xml' ) );
		$zip->close();
	}

	/**
	 * Calling finish twice keeps the file valid.
	 */
	public function test_finish_twice(): void {
		$this->write_three_batches();
		$before = md5_file( $this->path );
		$this->writer()->finish();
		$this->assertSame( $before, md5_file( $this->path ) );
		$this->assertSame( 4, $this->sheet()->query( '//m:row' )->length );
	}

	/**
	 * At the Excel row limit the writer stops with a plain message.
	 */
	public function test_row_limit(): void {
		$this->writer()->start();
		file_put_contents( $this->path . '.rows.n', (string) OPEX_Writer_XLSX::MAX_ROWS );
		$this->writer()->append( array( array( 'core:id' => '1', 'core:title' => 'last row' ) ), false );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'more rows than one Excel sheet' );
		$this->writer()->append( array( array( 'core:id' => '2', 'core:title' => 'too many' ) ), false );
	}
}
