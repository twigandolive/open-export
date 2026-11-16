<?php
/**
 * Shared setup and checks for the WooCommerce source tests.
 *
 * Each source test makes its own fixtures and removes them, then runs the real
 * engine: sanitize, count, batches with cursor paging, jobs in every format,
 * preview, the picker, the capability document and recipe import.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Base class for WooProductsTest, WooCouponsTest, WooOrderItemsTest and WooCustomersTest.
 */
abstract class WooSourceCase extends TestCase {

	/** @var string[] Job ids made by the current test. */
	protected $jobs = array();

	/** Start as the admin, with WooCommerce on. */
	protected function setUp(): void {
		wp_set_current_user( 1 );
		$this->assertTrue( OPEX_Woo::is_active(), 'WooCommerce must be active on the test site' );
	}

	/** Remove the jobs and their files. */
	protected function tearDown(): void {
		foreach ( $this->jobs as $id ) {
			OPEX_Jobs::delete( $id );
		}
		$this->jobs = array();
		wp_set_current_user( 1 );
	}

	/**
	 * A sanitized config for a source. Fails the test when it is refused.
	 *
	 * @param string $source Source id.
	 * @param array  $raw    Raw config keys.
	 * @param array  $notes  Notes out.
	 */
	protected function config( string $source, array $raw, array &$notes = array() ): array {
		$config = OPEX_Exporter::sanitize_config( array_merge( array( 'source' => $source, 'batch_size' => 2 ), $raw ), $notes );
		$this->assertIsArray( $config, is_wp_error( $config ) ? $config->get_error_message() : '' );
		return $config;
	}

	/**
	 * Every row through OPEX_Query in small batches, so cursor paging runs.
	 * Also checks the count agrees with the rows.
	 *
	 * @param array $config Sanitized config.
	 * @return array[]
	 */
	protected function rows( array $config ): array {
		$out    = array();
		$cursor = 0;
		for ( $i = 0; $i < 500; $i++ ) {
			$batch = OPEX_Query::batch( $config, $cursor, 2 );
			$out   = array_merge( $out, $batch['items'] );
			$this->assertGreaterThanOrEqual( $cursor, $batch['cursor'] );
			$cursor = $batch['cursor'];
			if ( $batch['done'] ) {
				break;
			}
		}
		$this->assertSame( count( $out ), OPEX_Query::count( $config ), 'count matches the rows' );
		return $out;
	}

	/**
	 * Rows a filter tree keeps, as values of one field.
	 *
	 * @param string $source  Source id.
	 * @param string $field   Field to read back.
	 * @param array  $filters Filter tree.
	 * @return string[]
	 */
	protected function pluck( string $source, string $field, array $filters ): array {
		$notes = array();
		$rows  = $this->rows( $this->config( $source, array( 'fields' => array( $field ), 'filters' => $filters ), $notes ) );
		$this->assertSame( array(), $notes, 'the filter was accepted' );
		return array_column( $rows, $field );
	}

	/**
	 * Runs a job to the end and returns its record.
	 *
	 * @param array $config Sanitized config.
	 * @param array $extra  Job extras, such as since.
	 */
	protected function export( array $config, array $extra = array() ): array {
		$job = OPEX_Exporter::create( $config, 'Woo source test', $extra );
		$this->assertIsArray( $job, is_wp_error( $job ) ? $job->get_error_message() : '' );
		$this->jobs[] = $job['id'];
		for ( $i = 0; $i < 200 && 'running' === $job['status']; $i++ ) {
			$job = OPEX_Exporter::run( $job['id'] );
			$this->assertIsArray( $job, is_wp_error( $job ) ? $job->get_error_message() : '' );
		}
		$this->assertSame( 'done', $job['status'], (string) ( $job['error'] ?? '' ) );
		return OPEX_Jobs::get( $job['id'] );
	}

	/**
	 * Exports one config in csv, json, xml and xlsx with a transform and a
	 * custom column, and checks every format holds the same first row.
	 *
	 * @param string $source  Source id.
	 * @param array  $fields  Two or more field ids. The first gets a prefix transform.
	 * @param array  $filters Filter tree that keeps at least one row.
	 */
	protected function assert_all_formats( string $source, array $fields, array $filters ): void {
		$first = $fields[0];
		$raw   = array(
			'fields'         => array_merge( $fields, array( 'custom:pair' ) ),
			'filters'        => $filters,
			'transforms'     => array( $first => array( array( 'type' => 'prefix', 'text' => 'X-' ) ) ),
			'custom_columns' => array( array( 'label' => 'Pair', 'template' => '{' . $fields[0] . '}|{' . $fields[1] . '}' ) ),
		);
		$plain = $this->rows( $this->config( $source, array( 'fields' => $fields, 'filters' => $filters ) ) );
		$this->assertNotEmpty( $plain );
		$expect = array();
		foreach ( $fields as $field ) {
			$expect[] = $field === $first ? 'X-' . $plain[0][ $field ] : $plain[0][ $field ];
		}
		$expect[] = $plain[0][ $fields[0] ] . '|' . $plain[0][ $fields[1] ];

		$csv  = $this->export( $this->config( $source, $raw + array( 'format' => 'csv' ) ) );
		$text = (string) file_get_contents( OPEX_Jobs::file_path( $csv ) );
		$text = 0 === strpos( $text, "\xEF\xBB\xBF" ) ? substr( $text, 3 ) : $text;
		$h    = fopen( 'php://memory', 'r+' );
		fwrite( $h, $text );
		rewind( $h );
		fgetcsv( $h, 0, ',', '"', '\\' );
		$this->assertSame( $expect, fgetcsv( $h, 0, ',', '"', '\\' ), 'csv' );
		fclose( $h );
		$this->assertSame( count( $plain ), (int) $csv['processed'] );

		$json = json_decode( (string) file_get_contents( OPEX_Jobs::file_path( $this->export( $this->config( $source, $raw + array( 'format' => 'json' ) ) ) ) ), true );
		$this->assertSame( $expect, array_values( $json[0] ), 'json' );
		$this->assertCount( count( $plain ), $json );

		$xml = simplexml_load_string( (string) file_get_contents( OPEX_Jobs::file_path( $this->export( $this->config( $source, $raw + array( 'format' => 'xml' ) ) ) ) ) );
		$got = array();
		foreach ( $xml->record[0]->children() as $cell ) {
			$got[] = (string) $cell;
		}
		$this->assertSame( $expect, $got, 'xml' );

		if ( class_exists( 'ZipArchive' ) ) {
			$job = $this->export( $this->config( $source, $raw + array( 'format' => 'xlsx' ) ) );
			$zip = new ZipArchive();
			$this->assertTrue( true === $zip->open( OPEX_Jobs::file_path( $job ) ) );
			$sheet = (string) $zip->getFromName( 'xl/worksheets/sheet1.xml' );
			$zip->close();
			$this->assertStringContainsString( htmlspecialchars( $expect[0], ENT_XML1 ), $sheet, 'xlsx' );
			$this->assertStringContainsString( htmlspecialchars( end( $expect ), ENT_XML1 ), $sheet, 'xlsx custom column' );
		}

		$preview = OPEX_Exporter::preview( $this->config( $source, $raw ) );
		$this->assertIsArray( $preview );
		$this->assertSame( $expect, array_map( 'strval', $preview['rows'][0] ), 'preview' );
	}

	/**
	 * The source is in the picker, its fields list, the capability document
	 * describes it, and a recipe with its field ids imports cleanly.
	 *
	 * @param string $source Source id.
	 * @param array  $fields Field ids that must be listed, keyed by the type the document gives.
	 */
	protected function assert_surface( string $source, array $fields ): void {
		$ids = array();
		foreach ( OPEX_Catalog::sources() as $group ) {
			foreach ( $group['items'] as $item ) {
				$ids[ $item['id'] ] = $item;
			}
		}
		$this->assertArrayHasKey( $source, $ids, 'picker' );
		$this->assertGreaterThan( 0, $ids[ $source ]['count'] );

		$listed = OPEX_Catalog::field_ids( $source );
		$doc    = OPEX_Schema::build( $source );
		$this->assertSame( 1, $doc['opex_schema'] );
		$described = null;
		foreach ( $doc['sources'] as $entry ) {
			if ( $source === $entry['id'] ) {
				$described = $entry;
			}
		}
		$this->assertNotNull( $described, 'schema' );
		$types = array_column( $described['fields'], 'type', 'id' );
		foreach ( $fields as $field => $type ) {
			$this->assertArrayHasKey( $field, $listed, "picker lists $field" );
			$this->assertSame( $type, $types[ $field ] ?? null, "schema type of $field" );
		}
		$this->assertNotEmpty( $described['date_fields'] );

		$notes  = array();
		$recipe = OPEX_Schema::recipe_to_config(
			array(
				'opex_recipe' => 1,
				'name'        => 'Woo test',
				'source'      => $source,
				'fields'      => array_keys( $fields ),
				'date_range'  => array( 'field' => $described['date_fields'][0]['id'], 'preset' => 'all_time' ),
			),
			$notes
		);
		$this->assertIsArray( $recipe );
		$this->assertTrue( $recipe['ok'] );
		$this->assertSame( array_keys( $fields ), $recipe['config']['fields'] );
	}
}
