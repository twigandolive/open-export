<?php
/**
 * Tests for the XML template writer.
 *
 * @package OpenExport
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Escaping, CDATA, unknown placeholders and validation.
 */
class WriterXmlTemplateTest extends TestCase {

	/**
	 * Escapes values, splits CDATA terminators, blanks unknown fields, trims a BOM.
	 */
	public function test_output(): void {
		$path     = sys_get_temp_dir() . '/opex-tpl-' . uniqid( '', true ) . '.xml';
		$template = array(
			'header' => "\xEF\xBB\xBF<feed>",
			'row'    => '<item id="{core:id}"><t>{core:title}</t><c>{core:title|cdata}</c><u>{nope}</u></item>',
			'footer' => '</feed>',
		);
		$writer   = OPEX_Writer::make( 'xml', $path, array( 'core:id' => 'ID', 'core:title' => 'Title' ), array( 'xml_template' => $template ) );
		$this->assertInstanceOf( OPEX_Writer_XML_Template::class, $writer );
		$writer->start();
		$writer->append( array( array( 'core:id' => '7', 'core:title' => "A & \"B\" <x> ]]> \x01" ) ), true );
		$writer->append( array( array( 'core:id' => '8', 'core:title' => 'plain' ) ), false );
		$writer->finish();
		$out = (string) file_get_contents( $path );
		unlink( $path );
		$expected = "<feed>\n"
			. "<item id=\"7\"><t>A &amp; &quot;B&quot; &lt;x&gt; ]]&gt; </t><c><![CDATA[A & \"B\" <x> ]]]]><![CDATA[> ]]></c><u></u></item>\n"
			. "<item id=\"8\"><t>plain</t><c><![CDATA[plain]]></c><u></u></item>\n"
			. "</feed>\n";
		$this->assertSame( $expected, $out );
		$doc = new DOMDocument();
		$this->assertTrue( $doc->loadXML( $out ) );
	}

	/**
	 * A good template validates.
	 */
	public function test_validate_ok(): void {
		$t = array( 'header' => '<feed>', 'row' => '<i>{a}</i><j>{b|cdata}</j>', 'footer' => '</feed>' );
		$this->assertTrue( OPEX_Writer_XML_Template::validate( $t, array( 'a', 'b' ) ) );
	}

	/**
	 * An unknown placeholder is reported.
	 */
	public function test_validate_unknown_field(): void {
		$t   = array( 'header' => '<feed>', 'row' => '<i>{a}{ghost}</i>', 'footer' => '</feed>' );
		$err = OPEX_Writer_XML_Template::validate( $t, array( 'a' ) );
		$this->assertWPError( $err );
		$this->assertSame( 'opex_template_unknown_field', $err->get_error_code() );
		$this->assertStringContainsString( 'ghost', $err->get_error_message() );
	}

	/**
	 * A malformed template is rejected.
	 */
	public function test_validate_malformed(): void {
		$t   = array( 'header' => '<feed>', 'row' => '<i>{a}</j>', 'footer' => '</feed>' );
		$err = OPEX_Writer_XML_Template::validate( $t, array( 'a' ) );
		$this->assertWPError( $err );
		$this->assertSame( 'opex_template_malformed', $err->get_error_code() );
		$t['row'] = '<i>{a}</i>';
		$t['footer'] = '';
		$this->assertWPError( OPEX_Writer_XML_Template::validate( $t, array( 'a' ) ) );
	}

	/**
	 * A DOCTYPE or entity declaration is rejected.
	 */
	public function test_validate_doctype(): void {
		$t   = array(
			'header' => '<!DOCTYPE feed [<!ENTITY x SYSTEM "file:///etc/passwd">]><feed>',
			'row'    => '<i>{a}</i>',
			'footer' => '</feed>',
		);
		$err = OPEX_Writer_XML_Template::validate( $t, array( 'a' ) );
		$this->assertWPError( $err );
		$this->assertSame( 'opex_template_doctype', $err->get_error_code() );
	}

	/**
	 * Asserts a WP_Error.
	 *
	 * @param mixed $value Value.
	 */
	private function assertWPError( $value ): void {
		$this->assertInstanceOf( WP_Error::class, $value );
	}
}
