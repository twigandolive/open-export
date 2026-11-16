<?php
/**
 * Tests for OPEX_Transforms and custom columns.
 *
 * @package OpenExport
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Covers every step type, sanitizing and custom columns.
 */
class TransformsTest extends TestCase {

	/**
	 * Run one step on one value through apply().
	 *
	 * @param array  $step  Step.
	 * @param string $value Value.
	 * @return string
	 */
	private function run_step( array $step, string $value ): string {
		$notes  = array();
		$clean  = OPEX_Transforms::sanitize( array( 'core:x' => array( $step ) ), array( 'core:x' ), $notes );
		$config = array( 'fields' => array( 'core:x' ), 'transforms' => $clean );
		$out    = OPEX_Transforms::apply( array( array( 'core:x' => $value ) ), $config );
		return $out[0]['core:x'];
	}

	public function test_trim(): void {
		$this->assertSame( 'a b', $this->run_step( array( 'type' => 'trim' ), "  a b \n" ) );
		$this->assertSame( '', $this->run_step( array( 'type' => 'trim' ), '   ' ) );
		$this->assertSame( 'à', $this->run_step( array( 'type' => 'trim' ), " \u{00A0}à\u{00A0}" ) );
		$this->assertSame( '0', $this->run_step( array( 'type' => 'trim' ), ' 0 ' ) );
	}

	public function test_case(): void {
		$this->assertSame( 'ÄÖÜ', $this->run_step( array( 'type' => 'case', 'mode' => 'upper' ), 'äöü' ) );
		$this->assertSame( 'äöü', $this->run_step( array( 'type' => 'case', 'mode' => 'lower' ), 'ÄÖÜ' ) );
		$this->assertSame( 'Élan Vital', $this->run_step( array( 'type' => 'case', 'mode' => 'title' ), 'éLAN vITAL' ) );
		$this->assertSame( '', $this->run_step( array( 'type' => 'case', 'mode' => 'upper' ), '' ) );
		$this->assertSame( '0', $this->run_step( array( 'type' => 'case', 'mode' => 'title' ), '0' ) );
	}

	public function test_case_bad_mode_is_dropped(): void {
		$notes = array();
		$out   = OPEX_Transforms::sanitize( array( 'core:x' => array( array( 'type' => 'case', 'mode' => 'weird' ) ) ), array( 'core:x' ), $notes );
		$this->assertSame( array(), $out );
		$this->assertCount( 1, $notes );
	}

	public function test_prefix_and_suffix(): void {
		$this->assertSame( '$ 5', $this->run_step( array( 'type' => 'prefix', 'text' => '$ ' ), '5' ) );
		$this->assertSame( '0 kg', $this->run_step( array( 'type' => 'suffix', 'text' => ' kg' ), '0' ) );
		$this->assertSame( '', $this->run_step( array( 'type' => 'prefix', 'text' => 'x' ), '' ) );
		$this->assertSame( '日本!', $this->run_step( array( 'type' => 'suffix', 'text' => '!' ), '日本' ) );
	}

	public function test_find_replace_is_plain_text(): void {
		$step = array( 'type' => 'find_replace', 'find' => '.*', 'replace' => '-' );
		$this->assertSame( 'a-b.c', $this->run_step( $step, 'a.*b.c' ) );
		$this->assertSame( 'x', $this->run_step( $step, 'x' ) );
		$this->assertSame( '', $this->run_step( $step, '' ) );
		$this->assertSame( 'Zürich', $this->run_step( array( 'type' => 'find_replace', 'find' => 'ue', 'replace' => 'ü' ), 'Zuerich' ) );
		$this->assertSame( 'Zürich', $this->run_step( array( 'type' => 'find_replace', 'find' => 'ü', 'replace' => 'ü' ), 'Zürich' ) );
		$this->assertSame( '1', $this->run_step( array( 'type' => 'find_replace', 'find' => '0', 'replace' => '1' ), '0' ) );
	}

	public function test_find_replace_empty_find_is_dropped(): void {
		$notes = array();
		$out   = OPEX_Transforms::sanitize( array( 'core:x' => array( array( 'type' => 'find_replace', 'find' => '', 'replace' => 'a' ) ) ), array( 'core:x' ), $notes );
		$this->assertSame( array(), $out );
	}

	public function test_date_format(): void {
		$step = array( 'type' => 'date_format', 'format' => 'd/m/Y H:i' );
		$this->assertSame( '05/01/2026 00:00', $this->run_step( $step, '2026-01-05' ) );
		$this->assertSame( '05/01/2026 13:45', $this->run_step( $step, '2026-01-05 13:45:10' ) );
		$this->assertSame( '05/01/2026 13:45', $this->run_step( $step, '2026-01-05T13:45:10' ) );
		$this->assertSame( '', $this->run_step( $step, '' ) );
		$this->assertSame( 'not a date', $this->run_step( $step, 'not a date' ) );
		$this->assertSame( '2026-02-31', $this->run_step( $step, '2026-02-31' ) );
		$this->assertSame( '0', $this->run_step( $step, '0' ) );
	}

	public function test_date_format_zones(): void {
		$step = array( 'type' => 'date_format', 'format' => 'Y-m-d H:i:s' );
		$tz   = wp_timezone();
		$ts   = 1767617110;
		$this->assertSame( wp_date( 'Y-m-d H:i:s', $ts, $tz ), $this->run_step( $step, (string) $ts ) );
		$expect = ( new DateTimeImmutable( '2026-01-05T13:45:10Z' ) )->setTimezone( $tz )->format( 'Y-m-d H:i:s' );
		$this->assertSame( $expect, $this->run_step( $step, '2026-01-05T13:45:10Z' ) );
		$this->assertSame( '1234567', $this->run_step( $step, '1234567' ) );
	}

	public function test_date_format_rejects_bad_format(): void {
		$notes = array();
		$bad   = array( 'type' => 'date_format', 'format' => '<b>Y</b>' );
		$this->assertSame( array(), OPEX_Transforms::sanitize( array( 'core:x' => array( $bad ) ), array( 'core:x' ), $notes ) );
		$long = array( 'type' => 'date_format', 'format' => str_repeat( 'Y', 41 ) );
		$out  = OPEX_Transforms::sanitize( array( 'core:x' => array( $long ) ), array( 'core:x' ), $notes );
		$this->assertSame( str_repeat( 'Y', 40 ), $out['core:x'][0]['format'] );
	}

	public function test_number(): void {
		$step = array( 'type' => 'number', 'decimals' => 2, 'dec_point' => ',', 'thousands_sep' => '.' );
		$this->assertSame( '1.234,50', $this->run_step( $step, '1234.5' ) );
		$this->assertSame( '0,00', $this->run_step( $step, '0' ) );
		$this->assertSame( '-1.000,00', $this->run_step( $step, '-1000' ) );
		$this->assertSame( '', $this->run_step( $step, '' ) );
		$this->assertSame( 'abc', $this->run_step( $step, 'abc' ) );
		$this->assertSame( '12 apples', $this->run_step( $step, '12 apples' ) );
		$this->assertSame( '1 000', $this->run_step( array( 'type' => 'number', 'decimals' => 0, 'dec_point' => '.', 'thousands_sep' => ' ' ), '1000' ) );
		$this->assertSame( '1000', $this->run_step( array( 'type' => 'number', 'decimals' => 0, 'thousands_sep' => '' ), '1000' ) );
	}

	public function test_number_uses_exact_string_math(): void {
		$step = static function ( int $decimals, string $sep = ',' ): array {
			return array( 'type' => 'number', 'decimals' => $decimals, 'dec_point' => '.', 'thousands_sep' => $sep );
		};
		// Half rounds away from zero at the chosen place, where a float would miss.
		$this->assertSame( '1.01', $this->run_step( $step( 2 ), '1.005' ) );
		$this->assertSame( '2.68', $this->run_step( $step( 2 ), '2.675' ) );
		$this->assertSame( '0.5', $this->run_step( $step( 1 ), '0.45' ) );
		$this->assertSame( '-1.01', $this->run_step( $step( 2 ), '-1.005' ) );
		$this->assertSame( '-1,234.57', $this->run_step( $step( 2 ), '-1234.567' ) );
		$this->assertSame( '0.00', $this->run_step( $step( 2 ), '-0.001' ), 'no negative zero' );
		$this->assertSame( '3', $this->run_step( $step( 0 ), '2.5' ) );
		$this->assertSame( '1,000,000', $this->run_step( $step( 0 ), '999999.5' ) );
		$this->assertSame( '12,345,678,901,234,567,890.00', $this->run_step( $step( 2 ), '12345678901234567890' ), 'a 20-digit number keeps every digit' );
		$this->assertSame( "1\u{202F}234.50", $this->run_step( $step( 2, "\u{202F}" ), '1234.5' ), 'a multibyte separator stays whole' );
		$this->assertSame( '0.50', $this->run_step( $step( 2, '' ), '.5' ) );
		$this->assertSame( '7', $this->run_step( $step( 0 ), ' +7 ' ), 'spaces and a plus sign around a number are fine' );
		foreach ( array( 'abc', '1e3', '12 apples', '0x1A', '1,5', '', '-', '1.2.3' ) as $text ) {
			$this->assertSame( $text, $this->run_step( $step( 2 ), $text ), "\"$text\" passes through" );
		}
	}

	public function test_number_clamps_decimals(): void {
		$notes = array();
		$out   = OPEX_Transforms::sanitize( array( 'core:x' => array( array( 'type' => 'number', 'decimals' => 99 ), array( 'type' => 'number', 'decimals' => -4 ) ) ), array( 'core:x' ), $notes );
		$this->assertSame( 6, $out['core:x'][0]['decimals'] );
		$this->assertSame( 0, $out['core:x'][1]['decimals'] );
	}

	public function test_map(): void {
		$step = array( 'type' => 'map', 'pairs' => array( 'publish' => 'Live', '0' => 'No', '1' => 'Yes' ), 'fallback' => 'Other' );
		$this->assertSame( 'Live', $this->run_step( $step, 'publish' ) );
		$this->assertSame( 'No', $this->run_step( $step, '0' ) );
		$this->assertSame( 'Yes', $this->run_step( $step, '1' ) );
		$this->assertSame( 'Other', $this->run_step( $step, 'draft' ) );
		$this->assertSame( 'Other', $this->run_step( $step, '' ) );
		$this->assertSame( 'Other', $this->run_step( $step, '01' ) );
		unset( $step['fallback'] );
		$this->assertSame( 'draft', $this->run_step( $step, 'draft' ) );
		$step['pairs'] = array( 'é' => 'e' );
		$this->assertSame( 'e', $this->run_step( $step, 'é' ) );
	}

	public function test_map_caps(): void {
		$pairs = array();
		for ( $i = 0; $i < 250; $i++ ) {
			$pairs[ 'k' . $i ] = 'v';
		}
		$pairs['long'] = str_repeat( 'é', 300 );
		$notes         = array();
		$out           = OPEX_Transforms::sanitize( array( 'core:x' => array( array( 'type' => 'map', 'pairs' => $pairs ) ) ), array( 'core:x' ), $notes );
		$this->assertCount( 200, $out['core:x'][0]['pairs'] );
		$out = OPEX_Transforms::sanitize(
			array( 'core:x' => array( array( 'type' => 'map', 'pairs' => array( str_repeat( 'a', 300 ) => str_repeat( 'é', 300 ) ) ) ) ),
			array( 'core:x' ),
			$notes
		);
		foreach ( $out['core:x'][0]['pairs'] as $from => $to ) {
			$this->assertSame( 200, mb_strlen( (string) $from ) );
			$this->assertSame( 200, mb_strlen( $to ) );
		}
	}

	public function test_default(): void {
		$step = array( 'type' => 'default', 'text' => 'n/a' );
		$this->assertSame( 'n/a', $this->run_step( $step, '' ) );
		$this->assertSame( '0', $this->run_step( $step, '0' ) );
		$this->assertSame( 'x', $this->run_step( $step, 'x' ) );
	}

	public function test_strip_html(): void {
		$step = array( 'type' => 'strip_html' );
		$this->assertSame( 'Tom & Jerry', $this->run_step( $step, '<p>Tom &amp; Jerry</p>' ) );
		$this->assertSame( 'Café', $this->run_step( $step, '<b>Caf&eacute;</b>' ) );
		$this->assertSame( '', $this->run_step( $step, '' ) );
		$this->assertSame( '0', $this->run_step( $step, '0' ) );
		$this->assertSame( 'hi', $this->run_step( $step, 'hi<script>alert(1)</script>' ) );
	}

	public function test_truncate(): void {
		$this->assertSame( '日本語', $this->run_step( array( 'type' => 'truncate', 'length' => 3 ), '日本語テキスト' ) );
		$this->assertSame( 'ab', $this->run_step( array( 'type' => 'truncate', 'length' => 10 ), 'ab' ) );
		$this->assertSame( '', $this->run_step( array( 'type' => 'truncate', 'length' => 3 ), '' ) );
		$this->assertSame( '0', $this->run_step( array( 'type' => 'truncate', 'length' => 1 ), '0' ) );
		$notes = array();
		$out   = OPEX_Transforms::sanitize( array( 'core:x' => array( array( 'type' => 'truncate', 'length' => 99999 ), array( 'type' => 'truncate', 'length' => 0 ) ) ), array( 'core:x' ), $notes );
		$this->assertSame( 10000, $out['core:x'][0]['length'] );
		$this->assertSame( 1, $out['core:x'][1]['length'] );
	}

	public function test_step_order_matters(): void {
		$prefix   = array( 'type' => 'prefix', 'text' => 'ab' );
		$truncate = array( 'type' => 'truncate', 'length' => 3 );
		$rows     = array( array( 'core:x' => 'XYZZY' ) );
		$forward  = array( 'fields' => array( 'core:x' ), 'transforms' => array( 'core:x' => array( $prefix, $truncate ) ) );
		$reverse  = array( 'fields' => array( 'core:x' ), 'transforms' => array( 'core:x' => array( $truncate, $prefix ) ) );
		$this->assertSame( 'abX', OPEX_Transforms::apply( $rows, $forward )[0]['core:x'] );
		$this->assertSame( 'abXYZ', OPEX_Transforms::apply( $rows, $reverse )[0]['core:x'] );
	}

	public function test_sanitize_drops_unknown_and_unselected(): void {
		$notes = array();
		$raw   = array(
			'core:x'   => array( array( 'type' => 'eval', 'code' => 'x' ), 'junk', array( 'type' => 'trim', 'extra' => 'gone' ) ),
			'core:no'  => array( array( 'type' => 'trim' ) ),
			'bad id'   => array( array( 'type' => 'trim' ) ),
			'core:y'   => 'not a list',
		);
		$out = OPEX_Transforms::sanitize( $raw, array( 'core:x', 'core:y' ), $notes );
		$this->assertSame( array( 'core:x' => array( array( 'type' => 'trim' ) ) ), $out );
		$this->assertNotEmpty( $notes );
		$this->assertSame( array(), OPEX_Transforms::sanitize( 'nope', array( 'core:x' ), $notes ) );
	}

	public function test_sanitize_caps(): void {
		$notes = array();
		$steps = array_fill( 0, 12, array( 'type' => 'trim' ) );
		$out   = OPEX_Transforms::sanitize( array( 'core:x' => $steps ), array( 'core:x' ), $notes );
		$this->assertCount( 8, $out['core:x'] );
		$out = OPEX_Transforms::sanitize( array( 'core:x' => array( array( 'type' => 'prefix', 'text' => str_repeat( 'é', 900 ) ) ) ), array( 'core:x' ), $notes );
		$this->assertSame( 500, mb_strlen( $out['core:x'][0]['text'] ) );
	}

	public function test_sanitize_is_idempotent(): void {
		$notes = array();
		$raw   = array(
			'core:x' => array(
				array( 'type' => 'trim' ),
				array( 'type' => 'case', 'mode' => 'title' ),
				array( 'type' => 'prefix', 'text' => str_repeat( 'é', 600 ) ),
				array( 'type' => 'find_replace', 'find' => 'a', 'replace' => '' ),
				array( 'type' => 'date_format', 'format' => 'Y-m-d' ),
				array( 'type' => 'number', 'decimals' => 3, 'dec_point' => '', 'thousands_sep' => '' ),
				array( 'type' => 'map', 'pairs' => array( '1' => 'a', 'b' => 'c' ), 'fallback' => '' ),
				array( 'type' => 'truncate', 'length' => 5 ),
			),
			'core:y' => array( array( 'type' => 'default', 'text' => 'z' ), array( 'type' => 'strip_html' ) ),
		);
		$once  = OPEX_Transforms::sanitize( $raw, array( 'core:x', 'core:y' ), $notes );
		$twice = OPEX_Transforms::sanitize( $once, array( 'core:x', 'core:y' ), $notes );
		$this->assertSame( $once, $twice );
		$this->assertSame( '', $once['core:x'][5]['dec_point'] );
		$this->assertSame( '', $once['core:x'][6]['fallback'] );
	}

	public function test_types_have_labels(): void {
		$types = OPEX_Transforms::types();
		foreach ( array( 'trim', 'case', 'prefix', 'suffix', 'find_replace', 'date_format', 'number', 'map', 'default', 'strip_html', 'truncate' ) as $type ) {
			$this->assertArrayHasKey( $type, $types );
			$this->assertNotSame( '', $types[ $type ]['label'] );
			$this->assertIsArray( $types[ $type ]['args'] );
		}
		$this->assertCount( 11, $types );
	}

	/**
	 * Field check used by the custom column tests.
	 *
	 * @return callable
	 */
	private function valid_fields(): callable {
		return static function ( string $id ): bool {
			return in_array( $id, array( 'woo:first', 'woo:last', 'core:post_title', 'meta:_sku' ), true );
		};
	}

	public function test_custom_columns_sanitize(): void {
		$notes = array();
		$raw   = array(
			array( 'label' => 'Full name', 'template' => '{woo:first} {woo:last}' ),
			array( 'label' => 'Full name', 'template' => '{core:post_title}' ),
			array( 'label' => '', 'template' => 'x' ),
			array( 'label' => 'Nothing', 'template' => '' ),
			'junk',
			array( 'label' => 'Mixed', 'template' => '{woo:nope} {custom:full-name} {plain} {woo:first}' ),
		);
		$out = OPEX_Transforms::sanitize_custom_columns( $raw, $this->valid_fields(), $notes );
		$this->assertSame( array( 'custom:full-name', 'custom:full-name-2', 'custom:mixed' ), array_column( $out, 'id' ) );
		$noted = implode( "\n", $notes );
		$this->assertStringContainsString( 'woo:nope', $noted );
		$this->assertStringContainsString( 'custom:full-name', $noted );
		$this->assertStringNotContainsString( '{plain}', $noted );
		$again = OPEX_Transforms::sanitize_custom_columns( $out, $this->valid_fields(), $notes );
		$this->assertSame( $out, $again );
	}

	public function test_custom_columns_cap(): void {
		$notes = array();
		$raw   = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$raw[] = array( 'label' => 'C' . $i, 'template' => 'x' );
		}
		$this->assertCount( 50, OPEX_Transforms::sanitize_custom_columns( $raw, $this->valid_fields(), $notes ) );
		$out = OPEX_Transforms::sanitize_custom_columns( array( array( 'label' => str_repeat( 'a', 300 ), 'template' => str_repeat( 'é', 900 ) ) ), $this->valid_fields(), $notes );
		$this->assertSame( 500, mb_strlen( $out[0]['template'] ) );
		$this->assertSame( 100, mb_strlen( $out[0]['label'] ) );
	}

	public function test_referenced_fields(): void {
		$cols = array(
			array( 'id' => 'custom:a', 'label' => 'A', 'template' => '{woo:first}-{woo:last}-{woo:first}' ),
			array( 'id' => 'custom:b', 'label' => 'B', 'template' => '{meta:_sku} {custom:a} {nope}' ),
		);
		$this->assertSame( array( 'woo:first', 'woo:last', 'meta:_sku' ), OPEX_Transforms::referenced_fields( $cols ) );
		$this->assertSame( array(), OPEX_Transforms::referenced_fields( array() ) );
	}

	public function test_apply_order_and_custom_column_in_the_middle(): void {
		$config = array(
			'fields'         => array( 'core:post_title', 'custom:full-name', 'meta:_sku' ),
			'custom_columns' => array( array( 'id' => 'custom:full-name', 'label' => 'Full name', 'template' => '{woo:first} {woo:last} ({core:post_title}) {woo:gone}' ) ),
			'transforms'     => array( 'custom:full-name' => array( array( 'type' => 'case', 'mode' => 'upper' ) ) ),
		);
		$rows = array(
			array( 'meta:_sku' => 'S1', 'core:post_title' => 'Hat', 'woo:first' => 'Ann', 'woo:last' => 'Lee' ),
			array( 'woo:last' => 'Ö', 'woo:first' => '' ),
		);
		$out = OPEX_Transforms::apply( $rows, $config );
		$this->assertSame( array( 'core:post_title', 'custom:full-name', 'meta:_sku' ), array_keys( $out[0] ) );
		$this->assertSame( 'ANN LEE (HAT) {WOO:GONE}', $out[0]['custom:full-name'] );
		$this->assertSame( array( 'core:post_title' => '', 'custom:full-name' => ' Ö ({CORE:POST_TITLE}) {WOO:GONE}', 'meta:_sku' => '' ), $out[1] );
	}

	public function test_custom_column_uses_field_that_is_not_selected(): void {
		$config = array(
			'fields'         => array( 'custom:name' ),
			'custom_columns' => array( array( 'id' => 'custom:name', 'label' => 'Name', 'template' => '{woo:first}/{woo:last}' ) ),
		);
		$out = OPEX_Transforms::apply( array( array( 'woo:first' => 'A', 'woo:last' => '0' ) ), $config );
		$this->assertSame( array( array( 'custom:name' => 'A/0' ) ), $out );
	}

	public function test_apply_without_config_extras_and_non_strings(): void {
		$out = OPEX_Transforms::apply( array( array( 'core:a' => 5, 'core:b' => null, 'core:c' => array( 1 ) ) ), array( 'fields' => array( 'core:a', 'core:b', 'core:c', 'core:d' ) ) );
		$this->assertSame( array( 'core:a' => '5', 'core:b' => '', 'core:c' => '', 'core:d' => '' ), $out[0] );
		$this->assertSame( array(), OPEX_Transforms::apply( array(), array( 'fields' => array( 'core:a' ) ) ) );
	}

	public function test_apply_many_rows(): void {
		$rows = array();
		for ( $i = 0; $i < 5000; $i++ ) {
			$rows[] = array( 'core:x' => ' v' . $i . ' ' );
		}
		$config = array( 'fields' => array( 'core:x' ), 'transforms' => array( 'core:x' => array( array( 'type' => 'trim' ), array( 'type' => 'case', 'mode' => 'upper' ) ) ) );
		$start  = microtime( true );
		$out    = OPEX_Transforms::apply( $rows, $config );
		$this->assertSame( 'V4999', $out[4999]['core:x'] );
		$this->assertLessThan( 2.0, microtime( true ) - $start );
	}
}
