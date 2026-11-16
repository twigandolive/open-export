<?php
/**
 * Codex R1-4.7 and R1-4.8: XML templates are checked with no rows, one row
 * and two rows, and field ids with pipes (spread ACF columns) are filled.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Template validation and rendering.
 */
class CodexWriterXmlTemplateTest extends TestCase {

	private string $path = '';

	protected function setUp(): void {
		$this->path = sys_get_temp_dir() . '/opex-xmlt-' . bin2hex( random_bytes( 6 ) ) . '.xml';
	}

	protected function tearDown(): void {
		if ( is_file( $this->path ) ) {
			unlink( $this->path );
		}
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
		$writer = new OPEX_Writer_XML_Template( $this->path, $headers, $template );
		$writer->start();
		$writer->append( $rows, true );
		$writer->finish();
		return (string) file_get_contents( $this->path );
	}

	public function test_rows_without_a_root_are_refused() {
		$t   = array( 'header' => '', 'row' => '<item>{core:ID}</item>', 'footer' => '' );
		$err = OPEX_Writer_XML_Template::validate( $t, array( 'core:ID' ) );
		$this->assertWPError( $err, 'two rows would be two root elements' );
		$this->assertSame( 'opex_template_malformed', $err->get_error_code() );
	}

	public function test_a_root_that_only_the_row_opens_is_refused() {
		// One row is well-formed, but no rows leaves an empty document.
		$t = array( 'header' => '<?xml version="1.0"?>', 'row' => '<item>{core:ID}</item>', 'footer' => '' );
		$this->assertWPError( OPEX_Writer_XML_Template::validate( $t, array( 'core:ID' ) ) );
	}

	public function test_a_proper_root_passes_and_writes_any_row_count() {
		$t = array( 'header' => '<list>', 'row' => '<item>{core:ID}</item>', 'footer' => '</list>' );
		$this->assertTrue( OPEX_Writer_XML_Template::validate( $t, array( 'core:ID' ) ) );
		foreach ( array( 0, 1, 2 ) as $n ) {
			$rows = array();
			for ( $i = 1; $i <= $n; $i++ ) {
				$rows[] = array( 'core:ID' => (string) $i );
			}
			$doc = new DOMDocument();
			$this->assertTrue( $doc->loadXML( $this->render( $t, array( 'core:ID' => 'ID' ), $rows ) ), $n . ' rows' );
			unlink( $this->path );
		}
	}

	public function test_spread_acf_columns_are_filled() {
		$t       = array(
			'header' => '<team>',
			'row'    => '<p n="{acf:people|1|name}">{acf:people|2|name|cdata}<x>{acf:people|3|name}</x></p>',
			'footer' => '</team>',
		);
		$headers = array(
			'acf:people|1|name' => 'people 1 name',
			'acf:people|2|name' => 'people 2 name',
		);
		$this->assertTrue( OPEX_Writer_XML_Template::validate( $t, array( 'acf:people' ) ) );
		$out = $this->render( $t, $headers, array( array( 'acf:people|1|name' => 'Ann & Bo', 'acf:people|2|name' => 'Cy <z>' ) ) );
		$this->assertSame( "<team>\n<p n=\"Ann &amp; Bo\"><![CDATA[Cy <z>]]><x></x></p>\n</team>\n", $out, 'a spread row this export has no column for is empty' );
	}

	public function test_unknown_pipe_fields_are_flagged_for_chosen_fields_only() {
		$wrap = static function ( string $row ): array {
			return array( 'header' => '<r>', 'row' => $row, 'footer' => '</r>' );
		};
		$err = OPEX_Writer_XML_Template::validate( $wrap( '<a>{acf:people|x}</a>' ), array( 'acf:people' ) );
		$this->assertWPError( $err );
		$this->assertStringContainsString( 'acf:people|x', $err->get_error_message() );
		$err = OPEX_Writer_XML_Template::validate( $wrap( '<a>{acf:gone|1|name}</a>' ), array( 'acf:people' ) );
		$this->assertWPError( $err );
		$this->assertStringContainsString( 'acf:gone|1|name', $err->get_error_message() );
		$this->assertWPError( OPEX_Writer_XML_Template::validate( $wrap( '<a>{nope}</a>' ), array( 'acf:people' ) ) );
	}

	public function test_text_with_a_pipe_in_braces_renders_as_before() {
		// The old pattern never matched these, so they were written as text. They still are.
		$t = array( 'header' => '<r>', 'row' => '<a>{a|b} {a|b|cdata} {x}{core:ID|cdata}</a>', 'footer' => '</r>' );
		$this->assertTrue( OPEX_Writer_XML_Template::validate( $t, array( 'core:ID', 'x' ) ) );
		$out = $this->render( $t, array( 'core:ID' => 'ID', 'x' => 'X' ), array( array( 'core:ID' => '7', 'x' => 'y' ) ) );
		$this->assertSame( "<r>\n<a>{a|b} {a|b|cdata} y<![CDATA[7]]></a>\n</r>\n", $out );
	}

	/**
	 * Asserts a WP_Error.
	 *
	 * @param mixed  $value Value.
	 * @param string $msg   Message.
	 */
	private function assertWPError( $value, string $msg = '' ): void {
		$this->assertInstanceOf( WP_Error::class, $value, $msg );
	}
}
