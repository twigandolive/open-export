<?php
/**
 * Engine features: new config keys, transforms and custom columns
 * through real exports, nested filters, since, create() extras and job done.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class EngineFeaturesTest extends TestCase {

	/** @var string[] */
	private array $jobs = array();

	protected function setUp(): void {
		wp_set_current_user( 1 );
	}

	protected function tearDown(): void {
		foreach ( $this->jobs as $id ) {
			OPEX_Jobs::delete( $id );
		}
		$this->jobs = array();
		remove_all_actions( 'opex_job_done' );
		add_action( 'opex_job_done', array( 'OPEX_Schedules', 'on_job_done' ) );
		add_action( 'opex_job_done', array( 'OPEX_Runner', 'on_job_done' ), 20 );
	}

	/** Assert that a value is a WP_Error. */
	private function assertWPError( $value ): void {
		$this->assertInstanceOf( WP_Error::class, $value );
	}

	/** Sanitize a raw config and expect success. */
	private function config( array $raw, array &$notes = array() ): array {
		$config = OPEX_Exporter::sanitize_config( array_merge( array( 'source' => 'posts:post', 'batch_size' => 25 ), $raw ), $notes );
		$this->assertIsArray( $config, is_wp_error( $config ) ? $config->get_error_message() : '' );
		return $config;
	}

	/** Create a job and run it to the end. */
	private function export( array $config, array $extra = array() ): array {
		$job = OPEX_Exporter::create( $config, 'Features test', $extra );
		$this->assertIsArray( $job, is_wp_error( $job ) ? $job->get_error_message() : '' );
		$this->jobs[] = $job['id'];
		for ( $i = 0; $i < 100 && 'running' === $job['status']; $i++ ) {
			$job = OPEX_Exporter::run( $job['id'] );
			$this->assertIsArray( $job, is_wp_error( $job ) ? $job->get_error_message() : '' );
		}
		$this->assertSame( 'done', $job['status'] );
		return OPEX_Jobs::get( $job['id'] );
	}

	/** The finished file as text. */
	private function body( array $job ): string {
		return (string) file_get_contents( OPEX_Jobs::file_path( $job ) );
	}

	/** Titles of the posts the export covers, in ID order. */
	private function posts(): array {
		global $wpdb;
		return $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status <> 'auto-draft' ORDER BY ID ASC", ARRAY_A );
	}

	public function test_old_config_gets_the_same_saved_shape(): void {
		$config = $this->config( array( 'fields' => array( 'core:ID', 'core:post_title' ) ) );
		$keys   = array_keys( $config );
		sort( $keys );
		$this->assertSame( array( 'batch_size', 'column_labels', 'csv_delimiter', 'date_range', 'fields', 'filters', 'format', 'source' ), $keys, 'defaults are not saved' );

		$job = $this->export( $config );
		$this->assertSame( 'utf8_bom', $job['config']['csv_encoding'], 'a job read fills the defaults' );
		$this->assertSame( 'lf', $job['config']['csv_eol'] );
		$this->assertSame( array(), $job['config']['transforms'] );
		$this->assertSame( array(), $job['config']['custom_columns'] );
		$this->assertFalse( $job['config']['network'] );
		$body = $this->body( $job );
		$this->assertStringStartsWith( "\xEF\xBB\xBF" . 'ID,post_title' . "\n", $body );
		$this->assertSame( count( $this->posts() ) + 2, substr_count( $body, "\n" ) + 1, 'header, rows and the trailing line' );
	}

	/** A config saved by 1.3.1 (only the eight keys) still creates and runs a job with the same output. */
	public function test_a_saved_1_3_1_config_exports_as_before(): void {
		$legacy = array(
			'source'        => 'posts:post',
			'fields'        => array( 'core:ID', 'core:post_title' ),
			'filters'       => array( 'relation' => 'AND', 'rules' => array( array( 'field' => 'core:ID', 'op' => 'gt', 'value' => '0' ) ) ),
			'date_range'    => array( 'field' => '', 'preset' => 'all_time', 'from' => '', 'to' => '' ),
			'format'        => 'json',
			'batch_size'    => 25,
			'csv_delimiter' => 'comma',
			'column_labels' => array( 'core:post_title' => 'Title' ),
		);
		$job  = $this->export( $legacy );
		$data = json_decode( $this->body( $job ), true );
		$this->assertCount( count( $this->posts() ), $data );
		$this->assertSame( array( 'ID', 'Title' ), array_keys( $data[0] ) );
		$this->assertSame( array(), $job['since'] );
		$this->assertSame( 'browser', $job['mode'] );
	}

	public function test_new_keys_sanitize(): void {
		$config = $this->config( array(
			'fields'       => array( 'core:ID' ),
			'csv_encoding' => 'win1252',
			'csv_eol'      => 'crlf',
		) );
		$this->assertSame( 'win1252', $config['csv_encoding'] );
		$this->assertSame( 'crlf', $config['csv_eol'] );

		// Ruling 3.5: network is kept only for a super admin on a multisite network.
		$network = OPEX_Exporter::sanitize_config( array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'network' => true ) );
		if ( is_multisite() && is_super_admin() ) {
			$this->assertTrue( $network['network'] );
		} else {
			$this->assertWPError( $network );
			$this->assertSame( 'opex_bad_network', $network->get_error_code() );
		}

		$config = $this->config( array( 'fields' => array( 'core:ID' ), 'csv_encoding' => 'ebcdic', 'csv_eol' => 'cr' ) );
		$this->assertArrayNotHasKey( 'csv_encoding', $config );
		$this->assertArrayNotHasKey( 'csv_eol', $config );
		$this->assertArrayNotHasKey( 'network', $config );
	}

	public function test_xlsx_is_accepted_when_zip_exists(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}
		$config = $this->config( array( 'fields' => array( 'core:ID', 'core:post_title' ), 'format' => 'xlsx' ) );
		$this->assertSame( 'xlsx', $config['format'] );
		$job = $this->export( $config );
		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( OPEX_Jobs::file_path( $job ) ) );
		$this->assertSame( count( $this->posts() ) + 1, substr_count( (string) $zip->getFromName( 'xl/worksheets/sheet1.xml' ), '<row ' ) );
		$zip->close();
		$this->assertTrue( OPEX_Writer::formats()['xlsx']['available'] );
	}

	public function test_formats_report_availability(): void {
		$formats = OPEX_Writer::formats();
		$this->assertSame( class_exists( 'ZipArchive' ), $formats['xlsx']['available'] );
		$this->assertTrue( $formats['csv']['available'] );
	}

	/** Transforms and a custom column, through every format. */
	public function test_transforms_and_custom_columns_in_every_format(): void {
		$raw = array(
			'fields'         => array( 'core:ID', 'core:post_title', 'custom:key' ),
			'transforms'     => array( 'core:post_title' => array( array( 'type' => 'prefix', 'text' => 'T-' ), array( 'type' => 'case', 'mode' => 'upper' ) ) ),
			'custom_columns' => array( array( 'label' => 'Key', 'template' => '{core:ID}:{core:post_title}' ) ),
		);
		$posts = $this->posts();
		$first = $posts[0];
		$title = 'T-' . strtoupper( $first['post_title'] );
		$key   = $first['ID'] . ':' . $first['post_title']; // The custom column reads the source value, not the transformed one.

		$csv_config = $this->config( $raw + array( 'format' => 'csv' ) );
		$this->assertSame( 'custom:key', $csv_config['custom_columns'][0]['id'] );
		$this->assertSame( array( 'core:ID', 'core:post_title', 'custom:key' ), $csv_config['fields'] );
		$csv = $this->export( $csv_config );
		$row = str_getcsv( explode( "\n", substr( $this->body( $csv ), 3 ) )[1], ',', '"', '\\' );
		$this->assertSame( array( (string) $first['ID'], $title, $key ), $row );
		$this->assertSame( array( 'ID', 'post_title', 'Key' ), array_values( $csv['headers'] ) );

		$json = json_decode( $this->body( $this->export( $this->config( $raw + array( 'format' => 'json' ) ) ) ), true );
		$this->assertSame( array( 'ID' => (string) $first['ID'], 'post_title' => $title, 'Key' => $key ), $json[0] );
		$this->assertCount( count( $posts ), $json );

		$xml = simplexml_load_string( $this->body( $this->export( $this->config( $raw + array( 'format' => 'xml' ) ) ) ) );
		$this->assertSame( $title, (string) $xml->record[0]->post_title );
		$this->assertSame( $key, (string) $xml->record[0]->Key );

		if ( class_exists( 'ZipArchive' ) ) {
			$job = $this->export( $this->config( $raw + array( 'format' => 'xlsx' ) ) );
			$zip = new ZipArchive();
			$zip->open( OPEX_Jobs::file_path( $job ) );
			$sheet = (string) $zip->getFromName( 'xl/worksheets/sheet1.xml' );
			$zip->close();
			$this->assertStringContainsString( htmlspecialchars( $title, ENT_XML1 ), $sheet );
			$this->assertStringContainsString( '>Key<', $sheet );
		}
	}

	public function test_custom_only_fetch_fields_and_stable_ids(): void {
		$config = $this->config( array(
			'fields'         => array( 'custom:label' ),
			'custom_columns' => array(
				array( 'id' => 'custom:label', 'label' => 'Renamed', 'template' => 'Post {core:ID} {meta:_not_selected}' ),
				array( 'id' => 'custom:label', 'label' => 'Second', 'template' => 'x' ),
				array( 'id' => 'BAD ID', 'label' => 'Third', 'template' => 'y' ),
			),
		) );
		$this->assertSame( array( 'custom:label', 'custom:second', 'custom:third' ), wp_list_pluck( $config['custom_columns'], 'id' ), 'an incoming id is kept, a duplicate or bad id is derived' );
		$this->assertSame( array( 'custom:label', 'custom:second', 'custom:third' ), $config['fields'], 'columns not listed go last' );
		$this->assertSame( array( 'core:ID', 'meta:_not_selected' ), OPEX_Config::fetch_fields( $config ), 'only real fields are fetched' );

		$none = OPEX_Exporter::sanitize_config( array( 'source' => 'posts:post', 'fields' => array( 'custom:a' ), 'custom_columns' => array( array( 'label' => 'A', 'template' => 'literal only' ) ) ) );
		$this->assertWPError( $none );
		$this->assertSame( 'opex_no_fields', $none->get_error_code() );

		$job = $this->export( $config );
		$this->assertStringContainsString( 'Post ' . $this->posts()[0]['ID'] . ' ', $this->body( $job ) );
	}

	public function test_custom_column_never_reads_a_blocked_field(): void {
		$notes  = array();
		$config = $this->config( array(
			'fields'         => array( 'core:ID' ),
			'custom_columns' => array( array( 'label' => 'Secret', 'template' => '{meta:session_tokens}{core:ID}' ) ),
		), $notes );
		$this->assertSame( array( 'core:ID' ), OPEX_Config::fetch_fields( $config ) );
		$this->assertNotEmpty( $notes );
	}

	public function test_preview_shows_transformed_values(): void {
		$config  = $this->config( array(
			'fields'         => array( 'core:post_title', 'custom:up' ),
			'transforms'     => array( 'custom:up' => array( array( 'type' => 'case', 'mode' => 'upper' ) ) ),
			'custom_columns' => array( array( 'label' => 'Up', 'template' => '{core:post_title}!' ) ),
		) );
		$preview = OPEX_Exporter::preview( $config );
		$this->assertSame( array( 'post_title', 'Up' ), $preview['headers'] );
		$this->assertSame( strtoupper( $preview['rows'][0][0] . '!' ), $preview['rows'][0][1] );
	}

	public function test_csv_encoding_and_line_endings(): void {
		$config = $this->config( array( 'fields' => array( 'core:ID' ), 'csv_eol' => 'crlf', 'csv_encoding' => 'utf8' ) );
		$body   = $this->body( $this->export( $config ) );
		$this->assertStringStartsWith( "ID\r\n", $body, 'no BOM, CRLF' );
		$this->assertSame( substr_count( $body, "\r\n" ), substr_count( $body, "\n" ) );

		$body = $this->body( $this->export( $this->config( array( 'fields' => array( 'core:ID' ), 'csv_encoding' => 'utf16le' ) ) ) );
		$this->assertStringStartsWith( "\xFF\xFEI\x00D\x00", $body );
	}

	public function test_xml_template_format(): void {
		$template = array(
			'header' => '<?xml version="1.0"?><feed>',
			'row'    => '<item><id>{core:ID}</id><title>{core:post_title}</title></item>',
			'footer' => '</feed>',
		);
		$config = $this->config( array( 'fields' => array( 'core:ID', 'core:post_title' ), 'format' => 'xml', 'xml_template' => $template ) );
		$this->assertSame( $template, $config['xml_template'] );
		$xml   = simplexml_load_string( $this->body( $this->export( $config ) ) );
		$posts = $this->posts();
		$this->assertCount( count( $posts ), $xml->item );
		$this->assertSame( (string) $posts[0]['ID'], (string) $xml->item[0]->id );
		$this->assertSame( $posts[0]['post_title'], (string) $xml->item[0]->title );

		// An empty row template keeps the plain XML writer.
		$plain = $this->config( array( 'fields' => array( 'core:ID' ), 'format' => 'xml' ) );
		$this->assertStringContainsString( '<records>', $this->body( $this->export( $plain ) ) );

		$bad = OPEX_Exporter::sanitize_config( array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'format' => 'xml', 'xml_template' => array( 'row' => '<a>{core:nope}</a>' ) ) );
		$this->assertWPError( $bad );
		$this->assertSame( 400, $bad->get_error_data()['status'] );
		$broken = OPEX_Exporter::sanitize_config( array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'format' => 'xml', 'xml_template' => array( 'header' => '<feed>', 'row' => '<a>{core:ID}</a>', 'footer' => '' ) ) );
		$this->assertWPError( $broken );
	}

	public function test_nested_filter_groups(): void {
		$rule = static function ( $value ) {
			return array( 'field' => 'core:ID', 'op' => 'eq', 'value' => (string) $value );
		};
		$posts = $this->posts();
		$a     = $posts[0]['ID'];
		$b     = $posts[1]['ID'];
		$c     = $posts[2]['ID'];
		$tree  = array(
			'relation' => 'AND',
			'rules'    => array(
				array( 'relation' => 'OR', 'rules' => array( $rule( $a ), $rule( $b ), $rule( $c ) ) ),
				array( 'field' => 'core:ID', 'op' => 'neq', 'value' => (string) $b ),
			),
		);
		$config = $this->config( array( 'fields' => array( 'core:ID' ), 'filters' => $tree ) );
		$this->assertSame( 'OR', $config['filters']['rules'][0]['relation'] );
		$this->assertSame( 2, OPEX_Query::count( $config ) );
		$job = $this->export( $config );
		$this->assertSame( 2, $job['processed'] );
	}

	public function test_filter_group_depth_and_count_limits(): void {
		$leaf  = array( 'field' => 'core:ID', 'op' => 'gt', 'value' => '0' );
		$deep  = array( 'relation' => 'AND', 'rules' => array( $leaf ) );
		for ( $i = 0; $i < 4; $i++ ) {
			$deep = array( 'relation' => 'OR', 'rules' => array( $deep, $leaf ) );
		}
		$notes  = array();
		$config = $this->config( array( 'fields' => array( 'core:ID' ), 'filters' => $deep ), $notes );
		$depth  = static function ( array $group ) use ( &$depth ): int {
			$max = 1;
			foreach ( $group['rules'] as $rule ) {
				if ( isset( $rule['rules'] ) ) {
					$max = max( $max, 1 + $depth( $rule ) );
				}
			}
			return $max;
		};
		$this->assertSame( 3, $depth( $config['filters'] ) );
		$this->assertNotEmpty( $notes );

		$groups = array();
		for ( $g = 0; $g < 4; $g++ ) {
			$groups[] = array( 'relation' => 'OR', 'rules' => array_fill( 0, 20, $leaf ) );
		}
		$config = $this->config( array( 'fields' => array( 'core:ID' ), 'filters' => array( 'relation' => 'AND', 'rules' => $groups ) ) );
		$total  = 0;
		foreach ( $config['filters']['rules'] as $group ) {
			$total += count( $group['rules'] );
		}
		$this->assertSame( 50, $total, 'at most 50 rules in all' );

		$bad = OPEX_Exporter::sanitize_config( array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'filters' => array( 'rules' => array( array( 'relation' => 'OR', 'rules' => array( array( 'field' => 'core:nope', 'op' => 'eq', 'value' => '1' ) ) ) ) ) ) );
		$this->assertWPError( $bad );
		$this->assertSame( 'opex_bad_filter', $bad->get_error_code() );
	}

	public function test_flat_filter_shape_gives_the_same_sql_as_before(): void {
		global $wpdb;
		$posts = $this->posts();
		$flat  = array(
			'relation' => 'OR',
			'rules'    => array(
				array( 'field' => 'core:ID', 'op' => 'eq', 'value' => (string) $posts[0]['ID'] ),
				array( 'field' => 'core:ID', 'op' => 'eq', 'value' => (string) $posts[3]['ID'] ),
			),
		);
		$args = array();
		$src  = OPEX_Query::parse_source( 'posts:post' );
		$sql  = OPEX_Filter_Tree::compile( $src, $flat, $args );
		$old  = array();
		$one  = OPEX_Query_Filters::rule_clause( $src, $flat['rules'][0], $old );
		$two  = OPEX_Query_Filters::rule_clause( $src, $flat['rules'][1], $old );
		$this->assertSame( "({$one} OR {$two})", $sql );
		$this->assertSame( $old, $args );
		$this->assertSame( 2, OPEX_Query::count( $this->config( array( 'fields' => array( 'core:ID' ), 'filters' => $flat ) ) ) );
		unset( $wpdb );
	}

	/** Since is its own AND term, even when the filters are joined with OR. */
	public function test_since_is_an_independent_and_term(): void {
		global $wpdb;
		$cut = (string) $wpdb->get_var( "SELECT post_date_gmt FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status <> 'auto-draft' ORDER BY post_date_gmt ASC LIMIT 1 OFFSET 20" );
		$this->assertNotSame( '', $cut );
		$expect = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status <> 'auto-draft' AND post_date_gmt > %s", $cut ) );
		$this->assertGreaterThan( 0, $expect );

		$config = $this->config( array( 'fields' => array( 'core:ID' ) ) );
		$job    = $this->export( $config, array( 'since' => array( 'field' => 'core:post_date_gmt', 'after' => $cut ) ) );
		$this->assertSame( $expect, $job['processed'] );
		$this->assertSame( $expect, $job['total'] );
		$this->assertSame( array( 'field' => 'core:post_date_gmt', 'after' => $cut ), $job['since'] );

		// An OR relation between filters does not widen it.
		$or = $this->config( array( 'fields' => array( 'core:ID' ), 'filters' => array( 'relation' => 'OR', 'rules' => array(
			array( 'field' => 'core:ID', 'op' => 'gt', 'value' => '0' ),
			array( 'field' => 'core:ID', 'op' => 'lt', 'value' => '1' ),
		) ) ) );
		$this->assertSame( $expect, $this->export( $or, array( 'since' => array( 'field' => 'core:post_date_gmt', 'after' => $cut ) ) )['processed'] );

		// The site-time column converts the UTC moment.
		$local  = $this->export( $config, array( 'since' => array( 'field' => 'core:post_date', 'after' => $cut ) ) );
		$offset = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status <> 'auto-draft' AND post_date > %s", get_date_from_gmt( $cut ) ) );
		$this->assertSame( $offset, $local['processed'] );
	}

	public function test_bad_since_is_refused(): void {
		$config = $this->config( array( 'fields' => array( 'core:ID' ) ) );
		foreach ( array(
			array( 'field' => 'core:ID', 'after' => '2026-01-01 00:00:00' ),
			array( 'field' => 'core:post_date_gmt', 'after' => 'yesterday' ),
		) as $since ) {
			$out = OPEX_Exporter::create( $config, 'x', array( 'since' => $since ) );
			$this->assertWPError( $out );
			$this->assertSame( 'opex_bad_since', $out->get_error_code() );
		}
	}

	public function test_create_stores_the_extras_explicitly(): void {
		$config = $this->config( array( 'fields' => array( 'core:ID' ) ) );
		$job    = $this->export( $config, array(
			'mode'          => 'background',
			'origin'        => 'cli',
			'schedule_id'   => 's0123456789ab',
			'delivery_name' => 'Nightly export',
		) );
		$stored = ( (array) get_option( 'opex_jobs', array() ) )[ $job['id'] ];
		$this->assertSame( 'background', $stored['mode'] );
		$this->assertSame( 'cli', $stored['origin'] );
		$this->assertSame( 's0123456789ab', $stored['schedule_id'] );
		$this->assertSame( 'Nightly export', $stored['delivery_name'] );

		$plain  = $this->export( $config );
		$stored = ( (array) get_option( 'opex_jobs', array() ) )[ $plain['id'] ];
		$this->assertSame( 'browser', $stored['mode'] );
		$this->assertSame( 'manual', $stored['origin'] );
		$this->assertSame( '', $stored['schedule_id'] );

		$odd = OPEX_Jobs::get( $this->export( $config, array( 'mode' => 'x', 'origin' => 'y', 'schedule_id' => '../x' ) )['id'] );
		$this->assertSame( array( 'browser', 'manual', '' ), array( $odd['mode'], $odd['origin'], $odd['schedule_id'] ) );

		$exposed = OPEX_Jobs::expose( $job );
		$this->assertSame( 'background', $exposed['mode'] );
		$this->assertSame( 'Nightly export', $exposed['delivery_name'] );
	}

	/** Deliveries are planned in the same save as done, and opex_job_done fires once. */
	public function test_deliveries_are_planned_with_done_and_the_hook_fires_once(): void {
		$seen = array();
		add_action( 'opex_job_done', static function ( $job ) use ( &$seen ) {
			$seen[] = array( $job['id'], $job['status'], $job['deliveries'] );
		} );
		$config   = $this->config( array( 'fields' => array( 'core:ID' ), 'batch_size' => 20 ) );
		$delivery = array( 'email' => array( 'enabled' => true, 'to' => 'ann@example.com', 'mode' => 'link' ) );
		$job      = $this->export( $config, array( 'delivery' => $delivery ) );

		$this->assertCount( 1, $seen, 'once' );
		$this->assertSame( $job['id'], $seen[0][0] );
		$this->assertSame( 'done', $seen[0][1] );
		$this->assertSame( 'email', $seen[0][2][0]['channel'], 'the plan was already saved when the hook ran' );
		$this->assertSame( 'pending', $job['deliveries'][0]['status'] );
		$this->assertGreaterThan( 0, $job['finished'] );

		OPEX_Exporter::run( $job['id'] );
		$this->assertCount( 1, $seen, 'running a done job fires nothing' );

		// A job with no rows finishes inside create(), and also fires once.
		$empty = $this->config( array( 'fields' => array( 'core:ID' ), 'filters' => array( 'rules' => array( array( 'field' => 'core:ID', 'op' => 'lt', 'value' => '0' ) ) ) ) );
		$job   = $this->export( $empty, array( 'delivery' => $delivery ) );
		$this->assertCount( 2, $seen );
		$this->assertSame( 'email', $job['deliveries'][0]['channel'] );

		// A job with no delivery stores no plan and no settings.
		$plain = $this->export( $config );
		$this->assertSame( array(), $plain['deliveries'] );
		$this->assertSame( array(), $plain['delivery'] );
	}

	public function test_job_secrets_stay_out_of_the_exposed_job(): void {
		$config   = $this->config( array( 'fields' => array( 'core:ID' ) ) );
		$delivery = array( 'webhook' => array( 'enabled' => true, 'url' => 'https://93.184.216.34/hook', 'secret' => 'sekrit-value' ) );
		$job      = $this->export( $config, array( 'delivery' => $delivery ) );
		$this->assertStringNotContainsString( 'sekrit-value', wp_json_encode( OPEX_Jobs::expose( $job ) ) );
		$this->assertStringNotContainsString( 'sekrit-value', wp_json_encode( OPEX_Jobs::expose_detail( $job ) ) );
		$this->assertStringNotContainsString( 'sekrit-value', (string) wp_json_encode( get_option( 'opex_jobs' ) ), 'stored encrypted' );
		wp_clear_scheduled_hook( 'opex_deliver', array( $job['id'] ) );
	}
}
