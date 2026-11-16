<?php
/**
 * Codex R1-4.6: an XLSX number cell holds at most 15 significant digits,
 * counted over the whole number. Longer values stay text, so Excel never
 * rounds them.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Number cells versus text cells.
 */
class CodexWriterXlsxNumberTest extends TestCase {

	private string $path = '';

	protected function setUp(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}
		$this->path = sys_get_temp_dir() . '/opex-xlsxn-' . bin2hex( random_bytes( 6 ) ) . '.xlsx';
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->path . '*' ) as $file ) {
			unlink( $file );
		}
	}

	/**
	 * Writes one column of values and returns each cell's kind and text.
	 *
	 * @param string[] $values Cell values.
	 * @return array value => 'n' or 's'.
	 */
	private function kinds( array $values ): array {
		$writer = OPEX_Writer::make( 'xlsx', $this->path, array( 'v' => 'Value' ) );
		$writer->start();
		$rows = array();
		foreach ( $values as $value ) {
			$rows[] = array( 'v' => $value );
		}
		$writer->append( $rows, true );
		$writer->finish();
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $this->path ) );
		$xml = (string) $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$zip->close();
		$doc = new DOMDocument();
		$doc->loadXML( $xml );
		$xp = new DOMXPath( $doc );
		$xp->registerNamespace( 'x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
		$out = array();
		foreach ( $values as $i => $value ) {
			$ref  = 'A' . ( $i + 2 );
			$cell = $xp->query( '//x:c[@r="' . $ref . '"]' )->item( 0 );
			$this->assertNotNull( $cell, $ref );
			$kind = 'inlineStr' === $cell->getAttribute( 't' ) ? 's' : 'n';
			$text = 's' === $kind ? $xp->query( 'x:is/x:t', $cell )->item( 0 )->textContent : $xp->query( 'x:v', $cell )->item( 0 )->textContent;
			$this->assertSame( $value, $text, 'the cell holds the exact text' );
			$out[ $value ] = $kind;
		}
		return $out;
	}

	public function test_long_fractions_stay_text() {
		$kinds = $this->kinds( array( '999999999999999.99', '0.1234567890123456789', '12345678901234.56', '1.0000000000000001' ) );
		$this->assertSame( 's', $kinds['999999999999999.99'] );
		$this->assertSame( 's', $kinds['0.1234567890123456789'] );
		$this->assertSame( 's', $kinds['12345678901234.56'] );
		$this->assertSame( 's', $kinds['1.0000000000000001'] );
	}

	public function test_ordinary_numbers_stay_numbers_and_ids_stay_text() {
		$kinds = $this->kinds( array( '82.50', '-1.5', '0', '0.000000000000001', '123456789012345', '1234567890123.45', '-0.123456789012345', '1234567890123456', '007', '1e5', '12.' ) );
		$this->assertSame( 'n', $kinds['82.50'] );
		$this->assertSame( 'n', $kinds['-1.5'] );
		$this->assertSame( 'n', $kinds['0'] );
		$this->assertSame( 'n', $kinds['0.000000000000001'], 'leading zeros are not significant' );
		$this->assertSame( 'n', $kinds['123456789012345'], '15 digits fit' );
		$this->assertSame( 'n', $kinds['1234567890123.45'], '15 digits fit' );
		$this->assertSame( 'n', $kinds['-0.123456789012345'], '15 digits fit' );
		$this->assertSame( 's', $kinds['1234567890123456'], 'a 16 digit integer stays text, as before' );
		$this->assertSame( 's', $kinds['007'], 'a leading-zero id stays text, as before' );
		$this->assertSame( 's', $kinds['1e5'] );
		$this->assertSame( 's', $kinds['12.'] );
	}
}
