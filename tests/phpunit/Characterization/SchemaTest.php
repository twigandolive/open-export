<?php
/**
 * Characterization: OPEX_Schema::build and recipe_to_config.
 *
 * @package Open_Export
 */

require_once __DIR__ . '/CharacterizationCase.php';

/**
 * The capability document and recipe validation.
 */
class SchemaTest extends CharacterizationCase {

	/**
	 * Top-level shape of the default document.
	 */
	public function test_build_shape(): void {
		$doc = OPEX_Schema::build();
		$this->assertSame(
			array( 'opex_schema', 'plugin', 'plugin_version', 'generated', 'site', 'how_to_use', 'recipe_format', 'operators', 'date_presets', 'formats', 'csv_delimiters', 'all_sources', 'sources', 'format_options', 'csv_encodings', 'csv_eols', 'transforms', 'custom_columns', 'filter_groups', 'xml_template', 'network' ),
			array_keys( $doc )
		);
		$this->assertSame( 1, $doc['opex_schema'] );
		$this->assertSame( 'Open Export', $doc['plugin'] );
		$this->assertSame( array( 'timezone', 'wordpress', 'locale', 'woocommerce' ), array_keys( $doc['site'] ) );
		$this->assertSame( array( 'opex_recipe', 'name', 'source', 'fields', 'filters', 'date_range', 'format', 'csv_delimiter', 'column_labels', 'csv_encoding', 'csv_eol', 'xml_template', 'transforms', 'custom_columns', 'network', 'acf_flatten', 'csv_enclosure', 'csv_quote_all', 'compress' ), array_keys( $doc['recipe_format'] ) );
		$this->assertCount( 14, $doc['operators'] );
		$this->assertCount( 15, $doc['date_presets'] );
		$this->assertCount( class_exists( 'ZipArchive' ) ? 5 : 4, $doc['formats'], 'xlsx is listed when the server can write it, html always' );
		$this->assertCount( 3, $doc['csv_delimiters'] );
		$this->assertSame( array( 'id', 'label', 'group', 'tier', 'rows' ), array_keys( $doc['all_sources'][0] ) );

		$shape = array(
			'operators'      => $doc['operators'],
			'date_presets'   => $doc['date_presets'],
			'formats'        => $doc['formats'],
			'csv_delimiters' => $doc['csv_delimiters'],
			'site_keys'      => array_keys( $doc['site'] ),
			'all_source_ids' => array_column( $doc['all_sources'], 'id' ),
			'source_ids'     => array_column( $doc['sources'], 'id' ),
		);
		$this->assert_golden_json( 'schema-default-shape', $shape );
	}

	/**
	 * One source in full: keys and field list.
	 */
	public function test_build_one_source(): void {
		$doc = OPEX_Schema::build( 'users' );
		$src = null;
		foreach ( $doc['sources'] as $s ) {
			if ( 'users' === $s['id'] ) {
				$src = $s;
			}
		}
		$this->assertNotNull( $src );
		$this->assertSame( array( 'id', 'label', 'group', 'tier', 'kind', 'rows', 'fields', 'date_fields' ), array_keys( $src ) );
		// Meta keys that WooCommerce adds when a person logs in vary between sites.
		$fields = array_values( array_filter( $src['fields'], static function ( $f ) {
			return 'meta:wc_last_active' !== $f['id'];
		} ) );
		$this->assert_golden_json( 'schema-users-source', array( 'field_keys' => array_keys( $src['fields'][0] ), 'fields' => $fields, 'date_fields' => $src['date_fields'], 'kind' => $src['kind'] ) );
	}

	/**
	 * The all scope describes every source in full.
	 */
	public function test_build_all(): void {
		$doc = OPEX_Schema::build( '', true );
		$this->assertSame( count( $doc['all_sources'] ), count( $doc['sources'] ) );
	}

	/**
	 * Recipe cases.
	 */
	public function test_recipes(): void {
		$cases = array(
			'good' => array(
				'opex_recipe' => 1, 'name' => 'Pro users', 'source' => 'users',
				'fields'      => array( 'core:user_login', 'core:user_email', 'meta:opex_plan', 'computed:roles' ),
				'filters'     => array( 'relation' => 'or', 'rules' => array( array( 'field' => 'meta:opex_plan', 'op' => 'eq', 'value' => 'pro' ), array( 'field' => 'computed:roles', 'op' => 'eq', 'value' => 'editor' ) ) ),
				'date_range'  => array( 'field' => 'core:user_registered', 'preset' => 'custom', 'from' => '2026-01-01', 'to' => '2026-12-31' ),
				'format'      => 'json', 'csv_delimiter' => 'tab', 'column_labels' => array( 'core:user_login' => 'Login' ), 'batch_size' => 50,
			),
			'unknown field' => array(
				'source' => 'users', 'fields' => array( 'core:user_login', 'core:user_logn', 'core:user_login', 'plain', 'woo:total' ),
				'filters' => array( 'rules' => array( array( 'field' => 'core:nope', 'op' => 'eq', 'value' => 'x' ), array( 'field' => 'core:user_login', 'op' => 'bogus', 'value' => 'x' ), array( 'field' => 'core:user_login', 'op' => 'contains', 'value' => 'user' ) ) ),
				'date_range' => array( 'field' => 'core:nope', 'preset' => 'last_30_days' ),
			),
			'resolves to nothing' => array( 'source' => 'users', 'fields' => array( 'core:nope', 'meta' ) ),
			'no fields'           => array( 'source' => 'users', 'fields' => array() ),
			'newer version'       => array( 'opex_recipe' => 99, 'source' => 'users', 'fields' => array( 'core:ID' ) ),
			'posts with tax'      => array( 'source' => 'posts:post', 'fields' => array( 'core:post_title', 'tax:category', 'meta:opex_note' ), 'filters' => array( 'rules' => array( array( 'field' => 'tax:category', 'op' => 'eq', 'value' => 'News' ) ) ) ),
		);
		$out = array();
		foreach ( $cases as $name => $recipe ) {
			$notes  = array();
			$result = OPEX_Schema::recipe_to_config( $recipe, $notes );
			$this->assertIsArray( $result, $name );
			$out[ $name ] = $result;
		}
		$this->assertTrue( $out['good']['ok'] );
		$this->assertSame( 'Pro users', $out['good']['name'] );
		$this->assertSame( 'json', $out['good']['config']['format'] );
		$this->assertTrue( $out['unknown field']['ok'] );
		$this->assertFalse( $out['resolves to nothing']['ok'] );
		$this->assertNull( $out['resolves to nothing']['config'] );
		$this->assertFalse( $out['no fields']['ok'] );
		$this->assert_golden_json( 'schema-recipes', $out );
	}

	/**
	 * Malformed recipes are errors.
	 */
	public function test_recipe_errors(): void {
		$none = OPEX_Schema::recipe_to_config( array( 'fields' => array( 'core:ID' ) ) );
		$this->assertWPError( $none );
		$this->assertSame( 'opex_recipe_source', $none->get_error_code() );
		$bad = OPEX_Schema::recipe_to_config( array( 'source' => 'nonsense', 'fields' => array( 'core:ID' ) ) );
		$this->assertWPError( $bad );
		$this->assertSame( 'opex_recipe_source', $bad->get_error_code() );
		$this->assertSame( 400, $bad->get_error_data()['status'] );
	}
}
