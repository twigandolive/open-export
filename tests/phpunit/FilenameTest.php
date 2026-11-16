<?php
/**
 * File name patterns.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class FilenameTest extends TestCase {

	public function test_tokens_render() {
		$time = 1788000000;
		$ctx  = array( 'label' => 'Posts', 'source' => 'posts:post', 'id' => 's1', 'site' => 'example.test', 'time' => $time );
		$out  = OPEX_Filename::render( '{label}-{date}-{time}-{datetime}-{id}-{site}', $ctx );
		$this->assertSame( 'Posts-' . wp_date( 'Y-m-d', $time ) . '-' . wp_date( 'His', $time ) . '-' . wp_date( 'Y-m-d-His', $time ) . '-s1-example-test', $out );
	}

	/** A source id keeps its two parts apart: posts:post becomes posts-post, not postspost. */
	public function test_source_token_keeps_its_parts() {
		$this->assertSame( 'posts-post', OPEX_Filename::render( '{source}', array( 'source' => 'posts:post' ) ) );
		$this->assertSame( 'Orders-woo-orders', OPEX_Filename::render( '{label}-{source}', array( 'label' => 'Orders', 'source' => 'woo:orders' ) ) );
		$this->assertSame( 'terms-product_cat', OPEX_Filename::render( '{source}', array( 'source' => 'terms:product_cat' ) ) );
	}

	public function test_unknown_tokens_are_removed() {
		$this->assertSame( 'a-b', OPEX_Filename::render( 'a-{nope}-b', array() ) );
		$this->assertSame( 'export', OPEX_Filename::render( '{foo}{bar}', array() ) );
	}

	public function test_hostile_input() {
		foreach ( array( '../../etc/passwd', '..\\..\\win', '/abs/path', '.htaccess', '{label}' ) as $p ) {
			$out = OPEX_Filename::render( $p, array( 'label' => '../../x/y' ) );
			$this->assertDoesNotMatchRegularExpression( '#[/\\\\]#', $out );
			$this->assertStringNotContainsString( '.', $out );
			$this->assertNotSame( '.', substr( $out, 0, 1 ) );
			$this->assertNotSame( '', $out );
		}
		$this->assertSame( 'htaccess', OPEX_Filename::render( '.htaccess', array() ) );
		$this->assertSame( 'etc-passwd', OPEX_Filename::render( '../../etc/passwd', array() ) );
	}

	public function test_long_and_empty() {
		$this->assertLessThanOrEqual( 120, strlen( OPEX_Filename::render( str_repeat( 'a', 500 ), array() ) ) );
		$this->assertLessThanOrEqual( 120, strlen( OPEX_Filename::render( '{label}', array( 'label' => str_repeat( 'b ', 500 ) ) ) ) );
		$this->assertSame( 'export', OPEX_Filename::render( '', array() ) );
		$this->assertSame( 'export', OPEX_Filename::render( '../..//', array() ) );
	}
}
