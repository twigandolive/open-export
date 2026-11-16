<?php
/**
 * Codex R2-3.1: a CSV record that cannot be built throws, instead of adding
 * an empty record to the file. Codex R2-3.3: a spread ACF column whose sub
 * field is named "cdata" is a column, not the CDATA modifier.
 *
 * The seam for R2-3.1 is OPEX_Writer_CSV::put_record(): the test writer
 * below replaces it with one that fails the way fputcsv() does when its
 * stream cannot take the record.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Shared state for the record seam.
 */
class CodexRound2RecordMode {

	/**
	 * Mode: 'ok' builds the record, 'zero' writes nothing and returns 0,
	 * 'false' returns false, 'short' writes part of it but reports it all.
	 *
	 * @var string
	 */
	public static $mode = 'ok';
}

/**
 * Template, CSV record and spread column cases.
 */
class CodexRound2WriterTest extends TestCase {

	private string $path = '';

	protected function setUp(): void {
		$this->path                  = sys_get_temp_dir() . '/opex-r2w-' . bin2hex( random_bytes( 6 ) );
		CodexRound2RecordMode::$mode = 'ok';
	}

	protected function tearDown(): void {
		CodexRound2RecordMode::$mode = 'ok';
		foreach ( (array) glob( $this->path . '*' ) as $file ) {
			unlink( $file );
		}
	}

	/**
	 * A CSV writer whose record step can fail by mode.
	 *
	 * @return OPEX_Writer_CSV
	 */
	private function csv(): OPEX_Writer_CSV {
		return new class( $this->path . '.csv', array( 'a' => 'A', 'b' => 'B' ), ',', 'utf8' ) extends OPEX_Writer_CSV {
			/**
			 * Builds the record, or fails by mode.
			 *
			 * @param resource $stream Stream.
			 * @param array    $fields Cells.
			 * @return int|false Bytes written.
			 */
			protected function put_record( $stream, array $fields ) {
				$mode = CodexRound2RecordMode::$mode;
				if ( 'zero' === $mode ) {
					return 0;
				}
				if ( 'false' === $mode ) {
					return false;
				}
				$wrote = parent::put_record( $stream, $fields );
				if ( 'short' === $mode ) {
					ftruncate( $stream, 2 );
				}
				return $wrote;
			}
		};
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function failures(): array {
		return array(
			'nothing written' => array( 'zero' ),
			'write failed'    => array( 'false' ),
			'read back short' => array( 'short' ),
		);
	}

	/**
	 * @dataProvider failures
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'failures' )]
	public function test_a_record_that_cannot_be_built_throws( string $mode ) {
		$writer = $this->csv();
		$writer->start();
		$before                      = (string) file_get_contents( $this->path . '.csv' );
		CodexRound2RecordMode::$mode = $mode;
		try {
			$writer->append( array( array( 'a' => '1', 'b' => str_repeat( 'x', 64 ) ) ), true );
			$this->fail( 'A record that could not be built must throw.' );
		} catch ( OPEX_User_Error $e ) {
			$this->assertStringContainsString( 'could not be written', $e->getMessage() );
		}
		$this->assertSame( $before, (string) file_get_contents( $this->path . '.csv' ), 'Nothing was added to the file.' );
	}

	public function test_a_large_record_is_written_whole() {
		$big    = str_repeat( 'y', 3 * 1024 * 1024 );
		$writer = $this->csv();
		$writer->start();
		$writer->append( array( array( 'a' => '1', 'b' => $big ) ), true );
		$this->assertSame( "A,B\n1," . $big . "\n", (string) file_get_contents( $this->path . '.csv' ) );
	}

	/**
	 * Writes rows through a template and returns the file.
	 *
	 * @param array $template Template parts.
	 * @param array $headers  Column key => label.
	 * @param array $rows     Rows.
	 * @return string The output.
	 */
	private function render( array $template, array $headers, array $rows ): string {
		$writer = new OPEX_Writer_XML_Template( $this->path . '.xml', $headers, $template );
		$writer->start();
		$writer->append( $rows, true );
		$writer->finish();
		return (string) file_get_contents( $this->path . '.xml' );
	}

	public function test_a_sub_field_named_cdata_is_a_column() {
		$t       = array(
			'header' => '<team>',
			'row'    => '<p a="{acf:team|1|cdata}">{acf:team|1|cdata|cdata}{acf:team|1|name|cdata}<z>{acf:team|2|cdata}</z></p>',
			'footer' => '</team>',
		);
		$headers = array(
			'acf:team|1|name'  => 'team 1 name',
			'acf:team|1|cdata' => 'team 1 cdata',
		);
		$this->assertTrue( OPEX_Writer_XML_Template::validate( $t, array( 'acf:team' ) ) );
		$out = $this->render( $t, $headers, array( array( 'acf:team|1|name' => 'Ann', 'acf:team|1|cdata' => 'B & <c>' ) ) );
		$this->assertSame( "<team>\n<p a=\"B &amp; &lt;c&gt;\"><![CDATA[B & <c>]]><![CDATA[Ann]]><z></z></p>\n</team>\n", $out );
	}

	public function test_the_cdata_modifier_still_works_on_other_ids() {
		$t = array( 'header' => '<r>', 'row' => '<a>{core:ID|cdata}{x}{a|b|cdata}</a>', 'footer' => '</r>' );
		$this->assertTrue( OPEX_Writer_XML_Template::validate( $t, array( 'core:ID', 'x' ) ) );
		$out = $this->render( $t, array( 'core:ID' => 'ID', 'x' => 'X' ), array( array( 'core:ID' => '<7>', 'x' => 'y' ) ) );
		$this->assertSame( "<r>\n<a><![CDATA[<7>]]>y{a|b|cdata}</a>\n</r>\n", $out );
		$err = OPEX_Writer_XML_Template::validate( array( 'header' => '<r>', 'row' => '<a>{acf:gone|1|cdata}</a>', 'footer' => '</r>' ), array( 'acf:team' ) );
		$this->assertInstanceOf( WP_Error::class, $err, 'a spread column of a field that is not chosen is unknown, as any other one is' );
	}
}
