<?php
/**
 * ACF repeater and flexible content fields (acf:<name>), as JSON and spread
 * into columns.
 *
 * Needs ACF or Secure Custom Fields on the test site. Every test but the
 * one for a site without ACF skips itself when the ACF functions are missing,
 * so the standard lanes stay green. The field groups are registered in code
 * and every value is removed afterwards.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class AcfFieldsTest extends TestCase {

	/** @var int[] */
	private array $posts = array();

	/** @var int[] */
	private array $terms = array();

	/** @var int[] */
	private array $users = array();

	/** @var string[] */
	private array $jobs = array();

	/** Local field group keys. */
	private const GROUPS = array( 'group_opex_posts', 'group_opex_terms', 'group_opex_users' );

	protected function setUp(): void {
		wp_set_current_user( 1 );
		if ( ! OPEX_Acf::available() ) {
			return;
		}
		acf_add_local_field_group( array(
			'key'      => 'group_opex_posts',
			'title'    => 'Open Export test',
			'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
			'fields'   => array(
				array( 'key' => 'field_opex_team', 'name' => 'opex_team', 'label' => 'Team', 'type' => 'repeater', 'sub_fields' => array(
					array( 'key' => 'field_opex_team_name', 'name' => 'name', 'label' => 'Name', 'type' => 'text' ),
					array( 'key' => 'field_opex_team_skills', 'name' => 'skills', 'label' => 'Skills', 'type' => 'repeater', 'sub_fields' => array(
						array( 'key' => 'field_opex_team_skill', 'name' => 'skill', 'label' => 'Skill', 'type' => 'text' ),
					) ),
				) ),
				array( 'key' => 'field_opex_blocks', 'name' => 'opex_blocks', 'label' => 'Blocks', 'type' => 'flexible_content', 'layouts' => array(
					array( 'key' => 'layout_opex_text', 'name' => 'text_block', 'label' => 'Text', 'sub_fields' => array(
						array( 'key' => 'field_opex_body', 'name' => 'body', 'label' => 'Body', 'type' => 'textarea' ),
					) ),
					array( 'key' => 'layout_opex_quote', 'name' => 'quote', 'label' => 'Quote', 'sub_fields' => array(
						array( 'key' => 'field_opex_quote_text', 'name' => 'quote_text', 'label' => 'Quote', 'type' => 'text' ),
						array( 'key' => 'field_opex_by', 'name' => 'by', 'label' => 'By', 'type' => 'text' ),
					) ),
				) ),
				array( 'key' => 'field_opex_plain', 'name' => 'opex_plain', 'label' => 'Plain', 'type' => 'text' ),
			),
		) );
		acf_add_local_field_group( array(
			'key'      => 'group_opex_terms',
			'title'    => 'Open Export term test',
			'location' => array( array( array( 'param' => 'taxonomy', 'operator' => '==', 'value' => 'category' ) ) ),
			'fields'   => array(
				array( 'key' => 'field_opex_term_rows', 'name' => 'opex_term_rows', 'label' => 'Term rows', 'type' => 'repeater', 'sub_fields' => array(
					array( 'key' => 'field_opex_term_label', 'name' => 'label', 'label' => 'Label', 'type' => 'text' ),
				) ),
			),
		) );
		acf_add_local_field_group( array(
			'key'      => 'group_opex_users',
			'title'    => 'Open Export user test',
			'location' => array( array( array( 'param' => 'user_form', 'operator' => '==', 'value' => 'all' ) ) ),
			'fields'   => array(
				array( 'key' => 'field_opex_user_links', 'name' => 'opex_user_links', 'label' => 'Links', 'type' => 'repeater', 'sub_fields' => array(
					array( 'key' => 'field_opex_user_url', 'name' => 'url', 'label' => 'URL', 'type' => 'url' ),
				) ),
			),
		) );
		OPEX_Acf::reset();
	}

	protected function tearDown(): void {
		foreach ( $this->jobs as $id ) {
			OPEX_Jobs::delete( $id );
		}
		foreach ( $this->posts as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( $this->terms as $id ) {
			wp_delete_term( $id, 'category' );
		}
		if ( $this->users ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			foreach ( $this->users as $id ) {
				wp_delete_user( $id );
			}
		}
		if ( OPEX_Acf::available() ) {
			foreach ( self::GROUPS as $key ) {
				acf_remove_local_field_group( $key );
			}
			foreach ( array( 'field_opex_team', 'field_opex_blocks', 'field_opex_plain', 'field_opex_term_rows', 'field_opex_user_links' ) as $key ) {
				acf_remove_local_field( $key );
			}
		}
		OPEX_Acf::reset();
	}

	/** Skip unless ACF or Secure Custom Fields is active. */
	private function needs_acf(): void {
		if ( ! OPEX_Acf::available() ) {
			$this->markTestSkipped( 'ACF or Secure Custom Fields is not active.' );
		}
	}

	/** A post with ACF values. */
	private function post( string $title, array $values = array() ): int {
		$id = wp_insert_post( array( 'post_title' => $title, 'post_status' => 'publish', 'post_type' => 'post' ) );
		$this->assertIsInt( $id );
		$this->posts[] = $id;
		foreach ( $values as $key => $value ) {
			update_field( $key, $value, $id );
		}
		return $id;
	}

	/** The three test posts: two with data, one without. */
	private function three_posts(): array {
		$a = $this->post( 'ACF A', array(
			'field_opex_team'   => array(
				array( 'field_opex_team_name' => 'Ann', 'field_opex_team_skills' => array( array( 'field_opex_team_skill' => 'a' ), array( 'field_opex_team_skill' => 'b' ) ) ),
				array( 'field_opex_team_name' => 'Bo', 'field_opex_team_skills' => array() ),
			),
			'field_opex_blocks' => array(
				array( 'acf_fc_layout' => 'quote', 'field_opex_quote_text' => 'Hi "there"', 'field_opex_by' => 'X' ),
				array( 'acf_fc_layout' => 'text_block', 'field_opex_body' => "Long\ntext" ),
			),
		) );
		$b = $this->post( 'ACF B', array(
			'field_opex_team' => array(
				array( 'field_opex_team_name' => 'Cy' ),
				array( 'field_opex_team_name' => 'Di' ),
				array( 'field_opex_team_name' => 'Ed' ),
			),
		) );
		$c = $this->post( 'ACF C' );
		return array( $a, $b, $c );
	}

	/** A sanitized config. */
	private function config( array $over, array &$notes = array() ): array {
		$config = OPEX_Exporter::sanitize_config( array_merge( array( 'source' => 'posts:post', 'format' => 'json', 'batch_size' => 10 ), $over ), $notes );
		$this->assertIsArray( $config, is_wp_error( $config ) ? $config->get_error_message() : '' );
		return $config;
	}

	/** Run an export to the end and return its JSON records. */
	private function export( array $config ): array {
		$job = OPEX_Exporter::create( $config, 'ACF test' );
		$this->assertIsArray( $job, is_wp_error( $job ) ? $job->get_error_message() : '' );
		$this->jobs[] = $job['id'];
		for ( $i = 0; $i < 100 && 'running' === $job['status']; $i++ ) {
			$job = OPEX_Exporter::run( $job['id'] );
		}
		$this->assertSame( 'done', $job['status'], (string) $job['error'] );
		$data = json_decode( (string) file_get_contents( OPEX_Jobs::file_path( OPEX_Jobs::get( $job['id'] ) ) ), true );
		$this->assertIsArray( $data );
		return array( 'job' => $job, 'rows' => $data );
	}

	/** Records keyed by their ID column. */
	private function by_id( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['ID'] ] = $row;
		}
		return $out;
	}

	public function test_picker_offers_acf_fields_for_posts_terms_and_users(): void {
		$this->needs_acf();
		$expect = array(
			'posts:post'    => array( 'acf:opex_team' => 'Team', 'acf:opex_blocks' => 'Blocks' ),
			'terms:category' => array( 'acf:opex_term_rows' => 'Term rows' ),
			'users'         => array( 'acf:opex_user_links' => 'Links' ),
		);
		foreach ( $expect as $source => $fields ) {
			$groups = OPEX_Catalog::fields( $source );
			$group  = end( $groups );
			$this->assertSame( 'ACF repeaters and flexible content', $group['label'], $source );
			$this->assertSame( $fields, array_column( $group['fields'], 'label', 'id' ), $source . ': only repeaters and flexible content' );
			$this->assertSame( array( 'json' ), array_values( array_unique( array_column( $group['fields'], 'type' ) ) ) );
			$decorated = OPEX_Field_Types::decorate( $source, $groups );
			$last      = end( $decorated );
			$this->assertSame( 'acf-repeaters', $last['id'] );
			$this->assertSame( 'json', $last['fields'][0]['value_type'] );
		}
		$page = array_keys( OPEX_Catalog::field_ids( 'posts:page' ) );
		$this->assertNotContains( 'acf:opex_team', $page, 'a group for posts is not offered on pages' );
		$this->assertContains( 'acf:opex_team', array_keys( OPEX_Catalog::field_ids( 'posts:post' ) ) );
	}

	public function test_fields_assemble_into_json(): void {
		$this->needs_acf();
		list( $a, $b, $c ) = $this->three_posts();
		$rows = $this->by_id( $this->export( $this->config( array(
			'fields'  => array( 'core:ID', 'acf:opex_team', 'acf:opex_blocks' ),
			'filters' => array( 'relation' => 'AND', 'rules' => array( array( 'field' => 'core:ID', 'op' => 'in', 'value' => "$a,$b,$c" ) ) ),
		) ) )['rows'] );
		$this->assertSame(
			array(
				array( 'name' => 'Ann', 'skills' => array( array( 'skill' => 'a' ), array( 'skill' => 'b' ) ) ),
				array( 'name' => 'Bo', 'skills' => array() ),
			),
			json_decode( $rows[ $a ]['opex_team'], true ),
			'a nested repeater is a list inside the row'
		);
		$this->assertSame(
			array(
				array( 'acf_fc_layout' => 'quote', 'quote_text' => 'Hi "there"', 'by' => 'X' ),
				array( 'acf_fc_layout' => 'text_block', 'body' => "Long\ntext" ),
			),
			json_decode( $rows[ $a ]['opex_blocks'], true ),
			'flexible content keeps acf_fc_layout'
		);
		$this->assertSame( array( 'Cy', 'Di', 'Ed' ), array_column( json_decode( $rows[ $b ]['opex_team'], true ), 'name' ) );
		$this->assertSame( '', $rows[ $b ]['opex_blocks'], 'no data is an empty cell' );
		$this->assertSame( '', $rows[ $c ]['opex_team'] );
	}

	public function test_spread_columns_are_fixed_for_the_whole_job(): void {
		$this->needs_acf();
		list( $a, $b ) = $this->three_posts();
		$notes  = array();
		$config = $this->config( array( 'fields' => array( 'core:ID', 'acf:opex_team', 'acf:opex_blocks' ), 'acf_flatten' => array( 'acf:opex_team', 'acf:opex_blocks', 'acf:opex_nope', 'core:ID' ), 'format' => 'csv' ), $notes );
		$this->assertSame( array( 'acf:opex_team', 'acf:opex_blocks' ), $config['acf_flatten'], 'only selected acf: fields are kept' );

		$job  = OPEX_Exporter::create( $config, 'ACF spread' );
		$this->jobs[] = $job['id'];
		$team = array( 'opex_team 1 name', 'opex_team 1 skills', 'opex_team 2 name', 'opex_team 2 skills', 'opex_team 3 name', 'opex_team 3 skills' );
		$fc   = array();
		foreach ( array( 1, 2 ) as $n ) {
			foreach ( array( 'acf_fc_layout', 'body', 'quote_text', 'by' ) as $sub ) {
				$fc[] = "opex_blocks $n $sub";
			}
		}
		$this->assertSame( array_merge( array( 'ID' ), $team, $fc ), array_values( OPEX_Jobs::get( $job['id'] )['headers'] ) );

		// Every post of the type is exported in batches of 10, and every row has the same columns.
		for ( $i = 0; $i < 100 && 'running' === $job['status']; $i++ ) {
			$job = OPEX_Exporter::run( $job['id'] );
		}
		$this->assertSame( 'done', $job['status'] );
		$this->assertGreaterThan( 10, $job['processed'], 'more than one batch' );
		$handle = fopen( OPEX_Jobs::file_path( OPEX_Jobs::get( $job['id'] ) ), 'r' );
		$header = fgetcsv( $handle, 0, ',', '"', '' );
		$header[0] = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );
		$rows   = array();
		while ( false !== ( $line = fgetcsv( $handle, 0, ',', '"', '' ) ) ) {
			$this->assertCount( count( $header ), $line );
			$rows[ (int) $line[0] ] = array_combine( $header, $line );
		}
		fclose( $handle );
		$this->assertSame( 'Ann', $rows[ $a ]['opex_team 1 name'] );
		$this->assertSame( array( array( 'skill' => 'a' ), array( 'skill' => 'b' ) ), json_decode( $rows[ $a ]['opex_team 1 skills'], true ) );
		$this->assertSame( '', $rows[ $a ]['opex_team 3 name'] );
		$this->assertSame( 'Ed', $rows[ $b ]['opex_team 3 name'] );
		$this->assertSame( 'quote', $rows[ $a ]['opex_blocks 1 acf_fc_layout'] );
		$this->assertSame( 'Hi "there"', $rows[ $a ]['opex_blocks 1 quote_text'] );
		$this->assertSame( '', $rows[ $a ]['opex_blocks 1 body'] );
		$this->assertSame( "Long\ntext", $rows[ $a ]['opex_blocks 2 body'] );

		$preview = OPEX_Exporter::preview( $config );
		$this->assertSame( array_merge( array( 'ID' ), $team, $fc ), $preview['headers'], 'the preview shows the same columns' );
	}

	public function test_spread_is_capped_at_twenty_rows_with_a_note(): void {
		$this->needs_acf();
		$rows = array();
		for ( $i = 1; $i <= 25; $i++ ) {
			$rows[] = array( 'field_opex_team_name' => 'P' . $i );
		}
		$id     = $this->post( 'ACF many', array( 'field_opex_team' => $rows ) );
		$config = $this->config( array(
			'fields'      => array( 'core:ID', 'acf:opex_team' ),
			'acf_flatten' => array( 'acf:opex_team' ),
			'filters'     => array( 'relation' => 'AND', 'rules' => array( array( 'field' => 'core:ID', 'op' => 'eq', 'value' => (string) $id ) ) ),
		) );
		$out = $this->export( $config );
		$this->assertStringContainsString( 'up to 25 rows', implode( ' ', $out['job']['notes'] ) );
		$record = $out['rows'][0];
		$this->assertCount( 1 + 20 * 2, $record );
		$this->assertSame( 'P20', $record['opex_team 20 name'] );
		$this->assertArrayNotHasKey( 'opex_team 21 name', $record );
	}

	public function test_transforms_work_on_the_json_column(): void {
		$this->needs_acf();
		list( $a ) = $this->three_posts();
		$config = $this->config( array(
			'fields'     => array( 'core:ID', 'acf:opex_team' ),
			'filters'    => array( 'relation' => 'AND', 'rules' => array( array( 'field' => 'core:ID', 'op' => 'eq', 'value' => (string) $a ) ) ),
			'transforms' => array( 'acf:opex_team' => array( array( 'type' => 'case', 'mode' => 'upper' ), array( 'type' => 'find_replace', 'find' => 'ANN', 'replace' => 'ANNE' ) ) ),
		) );
		$this->assertArrayHasKey( 'acf:opex_team', $config['transforms'] );
		$team = json_decode( $this->export( $config )['rows'][0]['opex_team'], true );
		$this->assertSame( 'ANNE', $team[0]['NAME'] );

		$custom = $this->config( array(
			'fields'         => array( 'core:ID' ),
			'custom_columns' => array( array( 'label' => 'Team copy', 'template' => 'team={acf:opex_team}' ) ),
			'filters'        => array( 'relation' => 'AND', 'rules' => array( array( 'field' => 'core:ID', 'op' => 'eq', 'value' => (string) $a ) ) ),
		) );
		$this->assertStringStartsWith( 'team=[{"name":"Ann"', $this->export( $custom )['rows'][0]['Team copy'], 'a custom column can read an acf: field' );
	}

	public function test_filters_on_acf_fields_are_refused(): void {
		$this->needs_acf();
		$refused = OPEX_Exporter::sanitize_config( array(
			'source'  => 'posts:post',
			'fields'  => array( 'core:ID', 'acf:opex_team' ),
			'filters' => array( 'relation' => 'AND', 'rules' => array( array( 'field' => 'acf:opex_team', 'op' => 'not_empty' ) ) ),
		) );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'opex_bad_filter', $refused->get_error_code() );
	}

	public function test_terms_and_users_read_their_own_meta(): void {
		$this->needs_acf();
		$term = wp_insert_term( 'ACF term ' . wp_rand(), 'category' );
		$this->assertIsArray( $term );
		$this->terms[] = (int) $term['term_id'];
		update_field( 'field_opex_term_rows', array( array( 'field_opex_term_label' => 'one' ), array( 'field_opex_term_label' => 'two' ) ), 'term_' . $term['term_id'] );
		$terms = $this->by_id( array_map( static function ( $row ) {
			return array_merge( $row, array( 'ID' => $row['term_id'] ) );
		}, $this->export( $this->config( array( 'source' => 'terms:category', 'fields' => array( 'core:term_id', 'acf:opex_term_rows' ) ) ) )['rows'] ) );
		$this->assertSame( array( array( 'label' => 'one' ), array( 'label' => 'two' ) ), json_decode( $terms[ (int) $term['term_id'] ]['opex_term_rows'], true ) );

		$user = wp_insert_user( array( 'user_login' => 'opexacf' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'opexacf' . wp_rand() . '@example.test' ) );
		$this->assertIsInt( $user );
		$this->users[] = $user;
		update_field( 'field_opex_user_links', array( array( 'field_opex_user_url' => 'https://example.test/a' ) ), 'user_' . $user );
		$users = $this->by_id( $this->export( $this->config( array( 'source' => 'users', 'fields' => array( 'core:ID', 'acf:opex_user_links' ) ) ) )['rows'] );
		$this->assertSame( array( array( 'url' => 'https://example.test/a' ) ), json_decode( $users[ $user ]['opex_user_links'], true ) );
	}

	public function test_unknown_acf_fields_get_the_normal_handling(): void {
		$this->needs_acf();
		$notes  = array();
		$config = $this->config( array( 'fields' => array( 'core:ID', 'acf:opex_nope', 'acf:opex_plain' ) ), $notes );
		$this->assertSame( array( 'core:ID' ), $config['fields'], 'a field that is not a repeater or flexible content is not an acf: field' );
		$this->assertStringContainsString( 'acf:opex_nope', implode( ' ', $notes ) );
		$this->assertArrayNotHasKey( 'acf_flatten', $config, 'a config without spread fields saves as before' );
		$this->assertNotContains( 'acf:opex_team', array_keys( OPEX_Catalog::field_ids( 'comments' ) ), 'comments have no acf: fields' );
	}

	public function test_recipe_and_document_know_acf_fields(): void {
		$this->needs_acf();
		$result = OPEX_Recipe::to_config( array( 'opex_recipe' => 1, 'source' => 'posts:post', 'fields' => array( 'core:ID', 'acf:opex_team', 'acf:opex_blocks' ), 'acf_flatten' => array( 'acf:opex_team' ) ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'core:ID', 'acf:opex_team', 'acf:opex_blocks' ), $result['config']['fields'] );
		$this->assertSame( array( 'acf:opex_team' ), $result['config']['acf_flatten'] );

		$doc    = OPEX_Schema::build( 'posts:post' );
		$fields = array();
		foreach ( $doc['sources'] as $source ) {
			foreach ( (array) ( $source['fields'] ?? array() ) as $field ) {
				$fields[ $field['id'] ] = $field;
			}
		}
		$this->assertSame( 'json', $fields['acf:opex_team']['type'] );
		$this->assertArrayHasKey( 'acf_flatten', $doc['recipe_format'] );
	}

	public function test_without_acf_nothing_is_offered(): void {
		if ( OPEX_Acf::available() ) {
			$this->markTestSkipped( 'This check is for a site without ACF.' );
		}
		$this->assertSame( array(), OPEX_Acf::fields_for( array( 'type' => 'posts', 'post_type' => 'post' ) ) );
		foreach ( OPEX_Catalog::fields( 'posts:post' ) as $group ) {
			$this->assertNotSame( 'ACF repeaters and flexible content', $group['label'] );
		}
		$notes  = array();
		$config = OPEX_Exporter::sanitize_config( array( 'source' => 'posts:post', 'fields' => array( 'core:ID', 'acf:anything' ), 'acf_flatten' => array( 'acf:anything' ) ), $notes );
		$this->assertSame( array( 'core:ID' ), $config['fields'] );
		$this->assertArrayNotHasKey( 'acf_flatten', $config );
		$this->assertStringContainsString( 'this source does not have it', implode( ' ', $notes ) );
	}
}
