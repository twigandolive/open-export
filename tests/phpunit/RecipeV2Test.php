<?php
/**
 * Recipe import and the capability document for the 2.0 keys.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * A 1.3.1 recipe gives exactly what it gave before. A recipe with the new
 * keys reaches sanitize_config, and anything invalid is reported and dropped.
 */
class RecipeV2Test extends TestCase {

	protected function setUp(): void {
		wp_set_current_user( 1 );
	}

	/**
	 * The recipe cases SchemaTest pins, unchanged.
	 *
	 * @return array
	 */
	private function legacy_cases(): array {
		return array(
			'good'                => array(
				'opex_recipe' => 1, 'name' => 'Pro users', 'source' => 'users',
				'fields'      => array( 'core:user_login', 'core:user_email', 'meta:opex_plan', 'computed:roles' ),
				'filters'     => array( 'relation' => 'or', 'rules' => array( array( 'field' => 'meta:opex_plan', 'op' => 'eq', 'value' => 'pro' ), array( 'field' => 'computed:roles', 'op' => 'eq', 'value' => 'editor' ) ) ),
				'date_range'  => array( 'field' => 'core:user_registered', 'preset' => 'custom', 'from' => '2026-01-01', 'to' => '2026-12-31' ),
				'format'      => 'json', 'csv_delimiter' => 'tab', 'column_labels' => array( 'core:user_login' => 'Login' ), 'batch_size' => 50,
			),
			'unknown field'       => array(
				'source' => 'users', 'fields' => array( 'core:user_login', 'core:user_logn', 'core:user_login', 'plain', 'woo:total' ),
				'filters' => array( 'rules' => array( array( 'field' => 'core:nope', 'op' => 'eq', 'value' => 'x' ), array( 'field' => 'core:user_login', 'op' => 'bogus', 'value' => 'x' ), array( 'field' => 'core:user_login', 'op' => 'contains', 'value' => 'user' ) ) ),
				'date_range' => array( 'field' => 'core:nope', 'preset' => 'last_30_days' ),
			),
			'resolves to nothing' => array( 'source' => 'users', 'fields' => array( 'core:nope', 'meta' ) ),
			'no fields'           => array( 'source' => 'users', 'fields' => array() ),
			'newer version'       => array( 'opex_recipe' => 99, 'source' => 'users', 'fields' => array( 'core:ID' ) ),
			'posts with tax'      => array( 'source' => 'posts:post', 'fields' => array( 'core:post_title', 'tax:category', 'meta:opex_note' ), 'filters' => array( 'rules' => array( array( 'field' => 'tax:category', 'op' => 'eq', 'value' => 'News' ) ) ) ),
		);
	}

	/** The pin: 1.3.1 recipes through the new entry point match the 1.3.1 golden byte for byte. */
	public function test_131_recipes_give_the_same_result(): void {
		$out = array();
		foreach ( $this->legacy_cases() as $name => $recipe ) {
			$notes        = array();
			$out[ $name ] = OPEX_Recipe::to_config( $recipe, $notes );
			$legacy_notes = array();
			$this->assertSame( OPEX_Schema::recipe_to_config( $recipe, $legacy_notes ), $out[ $name ], $name );
			$this->assertSame( $legacy_notes, $notes, $name );
		}
		$golden = file_get_contents( __DIR__ . '/Characterization/golden/schema-recipes.json' );
		$json   = wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		$this->assertSame( $golden, $json );
	}

	/** Every new key, nested groups with a bad rule and a too-deep group, custom columns in order. */
	public function test_new_keys_reach_the_config(): void {
		$deep   = array( 'relation' => 'AND', 'rules' => array( array( 'relation' => 'OR', 'rules' => array( array( 'field' => 'core:ID', 'op' => 'gt', 'value' => '1' ) ) ) ) );
		$recipe = array(
			'opex_recipe'    => 1,
			'source'         => 'posts:post',
			'fields'         => array( 'core:post_title', 'custom:title-id', 'core:ID' ),
			'custom_columns' => array( array( 'id' => 'custom:title-id', 'label' => 'Title and id', 'template' => '{core:post_title} #{core:ID}' ) ),
			'transforms'     => array(
				'core:post_title' => array( array( 'type' => 'case', 'mode' => 'upper' ) ),
				'core:ID'         => array( array( 'type' => 'bogus' ) ),
			),
			'csv_encoding'   => 'utf8',
			'csv_eol'        => 'crlf',
			'filters'        => array(
				'relation' => 'AND',
				'rules'    => array(
					array( 'field' => 'core:ID', 'op' => 'gt', 'value' => '0' ),
					array(
						'relation' => 'OR',
						'rules'    => array(
							array( 'field' => 'core:post_status', 'op' => 'eq', 'value' => 'publish' ),
							array( 'field' => 'core:nope', 'op' => 'eq', 'value' => 'x' ),
							$deep,
						),
					),
				),
			),
		);
		$notes  = array();
		$result = OPEX_Recipe::to_config( $recipe, $notes );
		$this->assertTrue( $result['ok'], wp_json_encode( $result ) );
		$config = $result['config'];
		$this->assertSame( array( 'core:post_title', 'custom:title-id', 'core:ID' ), $config['fields'] );
		$this->assertSame( 'Title and id', $config['custom_columns'][0]['label'] );
		$this->assertSame( array( 'core:post_title' ), array_keys( $config['transforms'] ), 'the unknown step type is dropped' );
		$this->assertSame( 'utf8', $config['csv_encoding'] );
		$this->assertSame( 'crlf', $config['csv_eol'] );
		$this->assertSame(
			array(
				'relation' => 'AND',
				'rules'    => array(
					array( 'field' => 'core:ID', 'op' => 'gt', 'value' => '0' ),
					array( 'relation' => 'OR', 'rules' => array( array( 'field' => 'core:post_status', 'op' => 'eq', 'value' => 'publish' ) ) ),
				),
			),
			$config['filters']
		);
		$this->assertNotEmpty( $notes, 'the dropped transform is noted' );
		$this->assertSame( $config, OPEX_Exporter::sanitize_config( $config ), 'the result is a clean config' );

		$report = $result['report'];
		$this->assertSame( array( 'matched', 'matched', 'matched' ), array_column( $report['fields'], 'status' ) );
		$this->assertTrue( $report['fields'][1]['custom'] );
		$group = $report['filters'][1];
		$this->assertSame( array( 1, true, 'OR', 'matched' ), array( $group['index'], $group['group'], $group['relation'], $group['status'] ) );
		$this->assertSame( array( 'matched', 'dropped', 'dropped' ), array_column( $group['rules'], 'status' ) );
		$this->assertSame( 'dropped', $group['rules'][2]['rules'][0]['status'], 'a group four levels deep is dropped' );
		$this->assertSame( 2, $report['summary']['rules_matched'] );
		$this->assertSame( 1, $report['summary']['rules_dropped'] );
		$this->assertSame( 3, $report['summary']['fields_matched'] );
	}

	/** A broken XML template is dropped with a note; a good one is kept. */
	public function test_xml_template_report_and_drop(): void {
		$base  = array( 'source' => 'posts:post', 'fields' => array( 'core:ID', 'core:post_title' ), 'format' => 'xml' );
		$notes = array();
		$bad   = OPEX_Recipe::to_config( $base + array( 'xml_template' => array( 'header' => '<list>', 'row' => '<p id="{core:ID}">{core:nope}</p>', 'footer' => '</list>' ) ), $notes );
		$this->assertTrue( $bad['ok'] );
		$this->assertArrayNotHasKey( 'xml_template', $bad['config'] );
		$this->assertStringContainsString( 'XML template was dropped', implode( ' ', $notes ) );

		$good = OPEX_Recipe::to_config( $base + array( 'xml_template' => array( 'header' => '<list>', 'row' => '<p id="{core:ID}">{core:post_title|cdata}</p>', 'footer' => '</list>' ) ) );
		$this->assertTrue( $good['ok'] );
		$this->assertSame( '<p id="{core:ID}">{core:post_title|cdata}</p>', $good['config']['xml_template']['row'] );
	}

	/** A recipe whose only column is a custom one still exports it. */
	public function test_custom_column_alone(): void {
		$result = OPEX_Recipe::to_config(
			array(
				'source'         => 'users',
				'fields'         => array(),
				'custom_columns' => array( array( 'label' => 'Who', 'template' => '{core:user_login} <{core:user_email}>' ) ),
			)
		);
		$this->assertTrue( $result['ok'], wp_json_encode( $result ) );
		$this->assertSame( array( 'custom:who' ), $result['config']['fields'] );
	}

	/** A group in an otherwise 1.3.1-shaped recipe is read, not dropped as a rule with no field. */
	public function test_group_without_other_new_keys(): void {
		$result = OPEX_Recipe::to_config(
			array(
				'source'  => 'users',
				'fields'  => array( 'core:ID' ),
				'filters' => array( 'rules' => array( array( 'relation' => 'OR', 'rules' => array( array( 'field' => 'core:ID', 'op' => 'gt', 'value' => '0' ) ) ) ) ),
			)
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'OR', $result['config']['filters']['rules'][0]['relation'] );
	}

	/** The REST route uses the same entry point. */
	public function test_rest_recipe_accepts_new_keys(): void {
		$req = new WP_REST_Request( 'POST', '/open-export/v1/recipe' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'source' => 'users', 'fields' => array( 'core:ID' ), 'csv_eol' => 'crlf' ) ) );
		$res = rest_do_request( $req );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'crlf', $res->get_data()['config']['csv_eol'] );
	}

	/** The capability document describes the 2.0 options. */
	public function test_document_describes_new_options(): void {
		$doc = OPEX_Schema::build();
		$this->assertSame( 1, $doc['opex_schema'] );
		$this->assertContains( 'csv', $doc['formats'] );
		$zip = class_exists( 'ZipArchive' ) ? array( 'compress' ) : array();
		$this->assertSame( array_merge( array( 'csv_delimiter', 'csv_encoding', 'csv_eol', 'csv_enclosure', 'csv_quote_all' ), $zip ), $doc['format_options']['csv']['options'] );
		$this->assertSame( array_merge( array( 'xml_template' ), $zip ), $doc['format_options']['xml']['options'] );
		$this->assertSame( array_keys( OPEX_Transforms::types() ), array_keys( $doc['transforms']['types'] ) );
		$this->assertSame( array( 'upper', 'lower', 'title' ), $doc['transforms']['types']['case']['args']->mode['values'] );
		$this->assertSame( OPEX_Filter_Tree::MAX_DEPTH, $doc['filter_groups']['max_depth'] );
		$this->assertSame( OPEX_Filter_Tree::MAX_RULES, $doc['filter_groups']['max_rules'] );
		$this->assertSame( 20000, $doc['xml_template']['max_part_length'] );
		$this->assertSame( array( 'utf8_bom', 'utf8', 'win1252', 'utf16le' ), $doc['csv_encodings'] );
		$this->assertSame( array( 'lf', 'crlf' ), $doc['csv_eols'] );
		$this->assertSame( 50, $doc['custom_columns']['max_columns'] );
		$this->assertStringNotContainsString( 'there is no nesting', $doc['how_to_use'] );
		$this->assertArrayHasKey( 'relation', $doc['recipe_format']['filters']['rules'][1], 'the recipe format shows a nested group' );
		$this->assertDoesNotMatchRegularExpression( '/[\x{2013}\x{2014}]/u', wp_json_encode( array( $doc['format_options'], $doc['transforms'], $doc['custom_columns'], $doc['filter_groups'], $doc['xml_template'] ), JSON_UNESCAPED_UNICODE ) );
	}
}
