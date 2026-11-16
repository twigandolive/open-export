<?php
/**
 * CSV cells are put into the target encoding before the
 * formula guard and the quoting, so a transliterated character can never
 * add a delimiter, a quote or a formula.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Encoding runs before the guard and the quoting.
 */
class CodexWriterEncodingTest extends TestCase {

	private string $path = '';

	protected function setUp(): void {
		$this->path = sys_get_temp_dir() . '/opex-enc-' . bin2hex( random_bytes( 6 ) ) . '.csv';
	}

	protected function tearDown(): void {
		if ( is_file( $this->path ) ) {
			unlink( $this->path );
		}
	}

	/**
	 * Writes one row of cells and returns the parsed rows (header first).
	 *
	 * @param array  $cells     Cell values.
	 * @param string $encoding  CSV encoding.
	 * @param bool   $quote_all Quote every cell.
	 * @return array Parsed rows, as UTF-8.
	 */
	private function roundtrip( array $cells, string $encoding, bool $quote_all = false ): array {
		$headers = array();
		$row     = array();
		foreach ( array_values( $cells ) as $i => $cell ) {
			$headers[ 'f' . $i ] = 'H' . $i;
			$row[ 'f' . $i ]     = $cell;
		}
		if ( is_file( $this->path ) ) {
			unlink( $this->path );
		}
		$writer = new OPEX_Writer_CSV( $this->path, $headers, ',', $encoding, 'lf', '"', $quote_all );
		$writer->start();
		$writer->append( array( $row ), true );
		$writer->finish();
		$bytes = (string) file_get_contents( $this->path );
		if ( 'utf16le' === $encoding ) {
			$this->assertSame( "\xFF\xFE", substr( $bytes, 0, 2 ) );
			$bytes = mb_convert_encoding( substr( $bytes, 2 ), 'UTF-8', 'UTF-16LE' );
		} elseif ( 'win1252' === $encoding ) {
			$bytes = mb_convert_encoding( $bytes, 'UTF-8', 'Windows-1252' );
		}
		$fh = fopen( 'php://temp', 'w+b' );
		fwrite( $fh, $bytes );
		rewind( $fh );
		$rows = array();
		while ( false !== ( $line = fgetcsv( $fh, 0, ',', '"', '' ) ) ) {
			$rows[] = $line;
		}
		fclose( $fh );
		return $rows;
	}

	public function test_reviewer_example_stays_two_cells_and_no_formula_in_win1252() {
		foreach ( array( false, true ) as $quote_all ) {
			$rows = $this->roundtrip( array( "left\u{FF0C}right", "\u{FF1D}1+1" ), 'win1252', $quote_all );
			$this->assertCount( 2, $rows );
			$this->assertSame( array( 'left,right', "'=1+1" ), $rows[1], $quote_all ? 'quote all' : 'quote when needed' );
		}
	}

	public function test_transliterated_formula_prefixes_and_quotes_are_handled() {
		// A fullwidth @ has no plain stand-in in this writer, so it becomes "?".
		$cells = array( "\u{FF0B}SUM(A1)", "\u{FF0D}A1", "\u{FF20}cmd", "say \u{FF02}hi\u{FF02}", "a\u{FF1B}b" );
		$rows  = $this->roundtrip( $cells, 'win1252' );
		$this->assertSame( array( "'+SUM(A1)", "'-A1", "?cmd", 'say "hi"', 'a;b' ), $rows[1] );
		$this->assertCount( 5, $rows[1] );
	}

	public function test_plain_win1252_text_is_unchanged() {
		$rows = $this->roundtrip( array( 'Café', '€ 12,50', '-3.5', 'two words' ), 'win1252' );
		$this->assertSame( array( 'Café', '€ 12,50', '-3.5', 'two words' ), $rows[1] );
		$this->assertStringContainsString( "Caf\xE9", (string) file_get_contents( $this->path ) );
	}

	public function test_utf16_cells_cannot_gain_a_formula_or_a_delimiter() {
		$rows = $this->roundtrip( array( "\xFF=1+1", "x\xFE,y", "\u{FF1D}1+1" ), 'utf16le' );
		$this->assertCount( 3, $rows[1] );
		foreach ( $rows[1] as $cell ) {
			$this->assertNotSame( '=', substr( $cell, 0, 1 ), $cell );
		}
		$this->assertSame( "\u{FF1D}1+1", $rows[1][2], 'a character UTF-16 holds stays as it is' );
	}
}
