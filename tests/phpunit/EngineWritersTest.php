<?php
/**
 * Engine fixes for writers: S-2 checkpoint and restore, S-11 formula guard, S-12 fputcsv arguments.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EngineWritersTest extends TestCase {

	/** @var string[] */
	private array $files = array();

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

	/** A fresh temp path. */
	private function path(): string {
		$path          = sys_get_temp_dir() . '/opex-engine-' . uniqid( '', true ) . '.out';
		$this->files[] = $path;
		return $path;
	}

	/** Rows keyed by the two test fields. */
	private static function rows( int $from, int $to ): array {
		$rows = array();
		for ( $i = $from; $i <= $to; $i++ ) {
			$rows[] = array( 'a' => (string) $i, 'b' => "name $i" );
		}
		return $rows;
	}

	/** Writer formats under test. */
	public static function formats(): array {
		return array(
			'csv'          => array( 'csv', array() ),
			'json'         => array( 'json', array() ),
			'xml'          => array( 'xml', array() ),
			'xml template' => array( 'xml', array( 'xml_template' => array( 'header' => '<rows>', 'row' => '<r id="{a}">{b}</r>', 'footer' => '</rows>' ) ) ),
			'xlsx'         => array( 'xlsx', array() ),
		);
	}

	/**
	 * A writer cut back to a checkpoint writes the same bytes as one that never saw the extra batch.
	 *
	 * @param string $format  Format.
	 * @param array  $options Writer options.
	 */
	#[DataProvider( 'formats' )]
	public function test_restore_undoes_a_replayed_batch( string $format, array $options ): void {
		if ( 'xlsx' === $format && ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}
		$headers = array( 'a' => 'A', 'b' => 'B' );

		$clean_path = $this->path();
		$clean      = OPEX_Writer::make( $format, $clean_path, $headers, $options );
		$clean->start();
		$clean->append( self::rows( 1, 3 ), true );
		$clean->append( self::rows( 4, 6 ), false );
		$clean->finish();

		$path   = $this->path();
		$writer = OPEX_Writer::make( $format, $path, $headers, $options );
		$writer->start();
		$writer->append( self::rows( 1, 3 ), true );
		$point = $writer->checkpoint();
		$this->assertIsArray( $point );
		$this->assertArrayHasKey( 'size', $point );

		// A batch is written, then the process dies before it is saved. A new process replays it.
		$writer->append( self::rows( 4, 6 ), false );
		$again = OPEX_Writer::make( $format, $path, $headers, $options );
		$again->restore( $point );
		$this->assertSame( $point, $again->checkpoint(), 'the files are back to the checkpoint' );
		$again->append( self::rows( 4, 6 ), false );
		$again->finish();

		if ( 'xlsx' === $format ) {
			$read = static function ( string $file ): string {
				$zip = new ZipArchive();
				$zip->open( $file );
				$xml = (string) $zip->getFromName( 'xl/worksheets/sheet1.xml' );
				$zip->close();
				return $xml;
			};
			$this->assertSame( $read( $clean_path ), $read( $path ) );
			$this->assertSame( 7, substr_count( $read( $path ), '<row ' ), 'header plus six rows' );
			return;
		}
		$this->assertSame( file_get_contents( $clean_path ), file_get_contents( $path ) );
	}

	/** The XLSX checkpoint carries the sidecar size and the row counter. */
	public function test_xlsx_checkpoint_has_sidecar_size_and_counter(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}
		$path   = $this->path();
		$writer = OPEX_Writer::make( 'xlsx', $path, array( 'a' => 'A', 'b' => 'B' ) );
		$writer->start();
		$start = $writer->checkpoint();
		$this->assertSame( 2, $start['next'], 'the header took row 1' );
		$writer->append( self::rows( 1, 4 ), true );
		$after = $writer->checkpoint();
		$this->assertSame( 6, $after['next'] );
		$this->assertGreaterThan( $start['rows_size'], $after['rows_size'] );
		$writer->restore( $start );
		$this->assertSame( $start, $writer->checkpoint() );
		// Once the workbook is built there is nothing to undo, and a repeat finish does nothing.
		$writer->append( self::rows( 1, 2 ), true );
		$writer->finish();
		$writer->restore( $start );
		$writer->append( array(), false );
		$writer->finish();
		$this->assertFileExists( $path );
	}

	/** A file shorter than its checkpoint cannot be repaired, so restore refuses. */
	public function test_restore_refuses_a_short_file(): void {
		$path   = $this->path();
		$writer = OPEX_Writer::make( 'csv', $path, array( 'a' => 'A' ) );
		$writer->start();
		$this->expectException( RuntimeException::class );
		$writer->restore( array( 'size' => 9999 ) );
	}

	/** A checkpoint with no known keys changes nothing. */
	public function test_restore_ignores_an_empty_checkpoint(): void {
		$path   = $this->path();
		$writer = OPEX_Writer::make( 'json', $path, array( 'a' => 'A' ) );
		$writer->start();
		$before = (string) file_get_contents( $path );
		$writer->restore( array() );
		$this->assertSame( $before, (string) file_get_contents( $path ) );
	}

	/** S-11: plain numbers are not formulas, anything else that starts with a formula character still is. */
	public function test_formula_guard_leaves_plain_numbers_alone(): void {
		$path   = $this->path();
		$writer = OPEX_Writer::make( 'csv', $path, array( 'a' => 'A', 'b' => 'B' ) );
		$writer->start();
		$cells = array( '-5', '-12.50', '-3,5', '-0', '-5 apples', '=1+1', '+7', '@x', "\tx", '-', '-1e5', '- 5' );
		$writer->append( array( array( 'a' => implode( '|', $cells ), 'b' => 'z' ) ), true );
		foreach ( $cells as $cell ) {
			$writer->append( array( array( 'a' => $cell, 'b' => '' ) ), false );
		}
		$handle = fopen( $path, 'rb' );
		$rows   = array();
		while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', '\\' ) ) ) {
			$rows[] = $row[0];
		}
		fclose( $handle );
		array_shift( $rows ); // BOM and header.
		array_shift( $rows ); // The joined row.
		$want = array( '-5', '-12.50', '-3,5', '-0', "'-5 apples", "'=1+1", "'+7", "'@x", "'\tx", "'-", "'-1e5", "'- 5" );
		$this->assertSame( $want, $rows );
	}

	/** S-11: the header row is guarded too, because labels come from users. */
	public function test_header_row_is_guarded(): void {
		$path   = $this->path();
		$writer = OPEX_Writer::make( 'csv', $path, array( 'a' => '=HYPERLINK("x")', 'b' => '-5', 'c' => '@sum', 'd' => 'Plain' ) );
		$writer->start();
		$text = (string) file_get_contents( $path );
		$this->assertStringContainsString( "'=HYPERLINK(", $text );
		$this->assertStringContainsString( "'@sum", $text );
		$this->assertStringContainsString( ',-5,', $text, 'a header that is a plain number is left alone' );
		$this->assertStringContainsString( 'Plain', $text );
	}

	/** S-12: fputcsv gets explicit enclosure and escape arguments, so PHP 8.4 raises no deprecation. */
	public function test_csv_raises_no_deprecation(): void {
		$seen = array();
		$old  = error_reporting( E_ALL );
		set_error_handler( static function ( $no, $msg ) use ( &$seen ) {
			$seen[] = $msg;
			return true;
		}, E_DEPRECATED | E_USER_DEPRECATED );
		try {
			foreach ( array( 'utf8_bom', 'win1252' ) as $encoding ) {
				$path   = $this->path();
				$writer = OPEX_Writer::make( 'csv', $path, array( 'a' => 'A' ), array( 'csv_encoding' => $encoding, 'csv_eol' => 'crlf' ) );
				$writer->start();
				$writer->append( array( array( 'a' => 'x "y"' ) ), true );
			}
		} finally {
			restore_error_handler();
			error_reporting( $old );
		}
		$this->assertSame( array(), $seen );
	}
}
