<?php
/**
 * Characterization: output formats, delimiters, formula guard, labels.
 *
 * @package Open_Export
 */

require_once __DIR__ . '/CharacterizationCase.php';

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CSV, JSON and XML writers through the real engine.
 */
class FormatsTest extends CharacterizationCase {

	/**
	 * Fields used by the posts format tests.
	 *
	 * @return string[]
	 */
	private function fields(): array {
		return array( 'core:ID', 'core:post_title', 'core:post_status', 'meta:opex_rating', 'tax:category' );
	}

	/**
	 * Format and delimiter combinations.
	 *
	 * @return array
	 */
	public static function combos(): array {
		return array(
			'csv_comma'     => array( 'csv', 'comma' ),
			'csv_semicolon' => array( 'csv', 'semicolon' ),
			'csv_tab'       => array( 'csv', 'tab' ),
			'json'          => array( 'json', 'comma' ),
			'xml'           => array( 'xml', 'comma' ),
		);
	}

	/**
	 * Posts in every format and delimiter.
	 *
	 * @param string $format    Format.
	 * @param string $delimiter Delimiter key.
	 */
	#[DataProvider( 'combos' )]
	public function test_posts_formats( string $format, string $delimiter ): void {
		$res = $this->export( array(
			'source'        => 'posts:post',
			'fields'        => $this->fields(),
			'format'        => $format,
			'csv_delimiter' => $delimiter,
			'batch_size'    => 25,
		), 'formats' );
		$this->assert_golden( 'formats-posts-' . $this->dataName(), $res );

		$content = $res['content'];
		if ( 'csv' === $format ) {
			$this->assertSame( "\xEF\xBB\xBF", substr( $content, 0, 3 ), 'CSV starts with a UTF-8 BOM' );
			$delim = array( 'comma' => ',', 'semicolon' => ';', 'tab' => "\t" )[ $delimiter ];
			$this->assertStringStartsWith( 'ID' . $delim . 'post_title' . $delim, substr( $content, 3 ) );
		} elseif ( 'json' === $format ) {
			$this->assertStringStartsWith( "[\n", $content );
			$this->assertStringEndsWith( "\n]\n", $content );
			$this->assertNotSame( "\xEF\xBB\xBF", substr( $content, 0, 3 ) );
		} else {
			$this->assertStringStartsWith( "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<records>\n", $content );
			$this->assertStringEndsWith( "</records>\n", $content );
		}
	}

	/**
	 * Unknown formats and delimiters fall back to csv and comma.
	 */
	public function test_unknown_format_and_delimiter_fall_back(): void {
		$config = $this->config( array( 'source' => 'users', 'fields' => array( 'core:ID' ), 'format' => 'pdf', 'csv_delimiter' => 'pipe' ) );
		$this->assertSame( 'csv', $config['format'] );
		$this->assertSame( 'comma', $config['csv_delimiter'] );
		$this->assertSame( ',', OPEX_Exporter::csv_delimiter_char( 'comma' ) );
		$this->assertSame( ';', OPEX_Exporter::csv_delimiter_char( 'semicolon' ) );
		$this->assertSame( "\t", OPEX_Exporter::csv_delimiter_char( 'tab' ) );
	}

	/**
	 * Batch size is clamped to 10..5000 (a non-numeric value becomes 10, the default 500 applies only when absent).
	 */
	public function test_batch_size_clamp(): void {
		$sizes = array();
		foreach ( array( 1, 10, 499, 5000, 99999, 'abc' ) as $raw ) {
			$sizes[] = $this->config( array( 'source' => 'users', 'fields' => array( 'core:ID' ), 'batch_size' => $raw ) )['batch_size'];
		}
		$this->assertSame( array( 10, 10, 499, 5000, 5000, 10 ), $sizes );
	}

	/**
	 * Values that start with a formula character are quoted in CSV only.
	 */
	public function test_formula_guard_csv(): void {
		$titles = array( '=SUM(1+1)', '+49 170 1234', '-5', '@mention', 'plain', 'a, "quoted" value', "line1\nline2", 'Ünïcödé' );
		foreach ( $titles as $i => $title ) {
			$this->temp_post( $title, 400 + $i, array( 'post_excerpt' => 'opexguard' ) );
		}
		$this->temp_post( 'ctl', 399, array( 'post_excerpt' => 'opexguard', 'post_content' => "\tTabbed" ) );
		$this->temp_post( 'ctr', 398, array( 'post_excerpt' => 'opexguard', 'post_content' => "\rReturn" ) );
		$raw = array(
			'source'  => 'posts:post',
			'fields'  => array( 'core:post_title', 'core:post_excerpt', 'core:post_content' ),
			'filters' => array( 'relation' => 'AND', 'rules' => array( array( 'field' => 'core:post_excerpt', 'op' => 'eq', 'value' => 'opexguard' ) ) ),
		);
		$res  = $this->export( $raw, 'guard' );
		$rows = $this->parse_csv( $res['content'] );
		$by   = array_column( array_slice( $rows, 1 ), 2, 0 );
		$this->assertSame( "'\tTabbed", $by['ctl'] );
		$this->assertSame( "'\rReturn", $by['ctr'] );
		$got  = array_slice( array_column( array_slice( $rows, 1 ), 0 ), 0, 10 );
		$got  = array_values( array_diff( $got, array( 'ctl', 'ctr' ) ) );
		sort( $got );
		$want = array( "'=SUM(1+1)", "'+49 170 1234", "'-5", "'@mention", 'plain', 'a, "quoted" value', "line1\nline2", 'Ünïcödé' );
		sort( $want );
		$this->assertSame( $want, $got );

		$json = $this->export( $raw + array( 'format' => 'json' ), 'guard-json' );
		$this->assertSame( 10, $json['total'] );
		$titles_json = array_column( json_decode( $json['content'], true ), 'post_title' );
		$this->assertContains( '=SUM(1+1)', $titles_json, 'JSON has no formula guard' );
		$this->assertContains( '-5', $titles_json );

		$xml = $this->export( $raw + array( 'format' => 'xml' ), 'guard-xml' );
		$this->assertStringContainsString( '<post_title>=SUM(1+1)</post_title>', $xml['content'] );
		$this->assertStringContainsString( '<post_title>a, &quot;quoted&quot; value</post_title>', $xml['content'] );
	}

	/**
	 * Custom labels and duplicate header handling.
	 */
	public function test_labels_and_duplicates(): void {
		$map = OPEX_Exporter::header_map(
			array( 'core:ID', 'meta:ID', 'tax:category', 'meta:category', 'core:post_title' ),
			array( 'core:post_title' => 'Title', 'tax:category' => 'Title' )
		);
		$this->assertSame( array(
			'core:ID'         => 'ID',
			'meta:ID'         => 'ID_2',
			'tax:category'    => 'Title',
			'meta:category'   => 'category',
			'core:post_title' => 'Title_2',
		), $map );
		$three = OPEX_Exporter::header_map( array( 'core:a', 'meta:a', 'col:a' ) );
		$this->assertSame( array( 'a', 'a_2', 'a_3' ), array_values( $three ) );

		$raw = array(
			'source'        => 'posts:page',
			'fields'        => array( 'core:ID', 'meta:ID', 'core:post_title', 'core:post_name' ),
			'column_labels' => array( 'core:post_title' => 'My Col!', 'core:post_name' => '9 lives', 'core:nope' => 'ignored' ),
		);
		$config = $this->config( $raw );
		$this->assertSame( array( 'core:post_title' => 'My Col!', 'core:post_name' => '9 lives' ), $config['column_labels'] );

		$csv = $this->export( $raw, 'labels' );
		$this->assertSame( array( 'ID', 'ID_2', 'My Col!', '9 lives' ), $this->parse_csv( $csv['content'] )[0] );
		$this->assert_golden( 'formats-labels-csv', $csv );

		$xml = $this->export( $raw + array( 'format' => 'xml' ), 'labels-xml' );
		$this->assertStringContainsString( '<My_Col_>', $xml['content'] );
		$this->assertStringContainsString( '<_9_lives>', $xml['content'] );
		$this->assertStringContainsString( '<ID_2>', $xml['content'] );
		$this->assert_golden( 'formats-labels-xml', $xml );

		$json = $this->export( $raw + array( 'format' => 'json' ), 'labels-json' );
		$this->assertSame( array( 'ID', 'ID_2', 'My Col!', '9 lives' ), array_keys( json_decode( $json['content'], true )[0] ) );
	}

	/**
	 * XML strips control characters, escapes markup and keeps unicode.
	 */
	public function test_xml_escaping(): void {
		$this->temp_post( "A & B <i>\"x\"</i> 'y' \x01\x0B end é", 500, array( 'post_excerpt' => 'opexxml' ) );
		$raw = array(
			'source'  => 'posts:post',
			'fields'  => array( 'core:post_title' ),
			'format'  => 'xml',
			'filters' => array( 'relation' => 'AND', 'rules' => array( array( 'field' => 'core:post_excerpt', 'op' => 'eq', 'value' => 'opexxml' ) ) ),
		);
		$res = $this->export( $raw, 'xml-esc' );
		$this->assertSame( 1, $res['total'] );
		$this->assert_golden_data( 'formats-xml-escaping.out', preg_replace( '/<post_title>.*<\/post_title>/', '<post_title>X</post_title>', $res['content'] ) . strstr( $res['content'], '<post_title>', false ) );
	}

	/**
	 * A job with zero rows finishes at once and writes a valid empty file.
	 */
	public function test_zero_rows_finish_at_creation(): void {
		$raw = array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'filters' => array( 'relation' => 'AND', 'rules' => array( array( 'field' => 'core:post_title', 'op' => 'eq', 'value' => 'no such title' ) ) ) );
		foreach ( array( 'csv' => "\xEF\xBB\xBFID\n", 'json' => "[\n\n]\n", 'xml' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<records>\n</records>\n" ) as $format => $expected ) {
			$config = $this->config( $raw + array( 'format' => $format ) );
			$job    = OPEX_Exporter::create( $config, 'empty' );
			$this->job_ids[] = $job['id'];
			$this->assertSame( 'done', $job['status'] );
			$this->assertSame( 0, $job['total'] );
			$this->assertSame( $expected, file_get_contents( OPEX_Jobs::file_path( OPEX_Jobs::get( $job['id'] ) ) ), $format );
		}
	}
}
