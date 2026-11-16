<?php
/**
 * The ACF sources: rules inside one location group all apply, a row limit
 * cut gets a job note, and the users scan keeps to the site's members.
 *
 * The location checks need nothing. The others need ACF or Secure Custom
 * Fields and skip without it. The members check needs a multisite network
 * and skips on a single site: run it on a multisite test site with
 * OPEX_TEST_SITE=<multisite site> tests/bin/test.sh --filter CodexSource
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class CodexSourceAcfTest extends TestCase {

	/** @var int[] */
	private $posts = array();

	/** @var int[] */
	private $users = array();

	/** @var string[] */
	private $jobs = array();

	/** @var string[] Local field group keys made by the test. */
	private $groups = array();

	protected function setUp(): void {
		wp_set_current_user( 1 );
		OPEX_Acf::reset();
	}

	protected function tearDown(): void {
		while ( is_multisite() && ms_is_switched() ) {
			restore_current_blog();
		}
		wp_set_current_user( 1 );
		foreach ( $this->jobs as $id ) {
			OPEX_Jobs::delete( $id );
		}
		foreach ( $this->posts as $id ) {
			wp_delete_post( $id, true );
		}
		if ( $this->users ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			if ( is_multisite() ) {
				require_once ABSPATH . 'wp-admin/includes/ms.php';
				$GLOBALS['wpdb']->suppress_errors( true );
			}
			foreach ( $this->users as $id ) {
				is_multisite() ? wpmu_delete_user( $id ) : wp_delete_user( $id );
			}
			if ( is_multisite() ) {
				$GLOBALS['wpdb']->suppress_errors( false );
				$GLOBALS['wpdb']->last_error = '';
			}
		}
		if ( OPEX_Acf::available() ) {
			foreach ( $this->groups as $key ) {
				acf_remove_local_field_group( $key );
			}
		}
		$this->jobs   = array();
		$this->posts  = array();
		$this->users  = array();
		$this->groups = array();
		OPEX_Acf::reset();
	}

	/** Skip unless ACF or Secure Custom Fields is active. */
	private function needs_acf(): void {
		if ( ! OPEX_Acf::available() ) {
			$this->markTestSkipped( 'ACF or Secure Custom Fields is not active.' );
		}
	}

	/** One rule. */
	private function rule( string $param, string $value, string $operator = '==' ): array {
		return array(
			'param'    => $param,
			'operator' => $operator,
			'value'    => $value,
		);
	}

	/** A local field group with one repeater, keyed by a test prefix. */
	private function group( string $key, array $location, string $name, array $subs, string $type = 'repeater' ): void {
		$fields = array();
		foreach ( $subs as $sub ) {
			$fields[] = array( 'key' => "field_{$key}_{$sub}", 'name' => $sub, 'label' => $sub, 'type' => 'text' );
		}
		$field = array( 'key' => "field_{$key}", 'name' => $name, 'label' => $name, 'type' => $type );
		if ( 'flexible_content' === $type ) {
			$field['layouts'] = array( array( 'key' => "layout_{$key}", 'name' => 'row', 'label' => 'Row', 'sub_fields' => $fields ) );
		} else {
			$field['sub_fields'] = $fields;
		}
		acf_add_local_field_group( array( 'key' => $key, 'title' => $key, 'location' => $location, 'fields' => array( $field ) ) );
		$this->groups[] = $key;
		OPEX_Acf::reset();
	}

	/** Every rule in one group must fit the source. Groups are OR. */
	public function test_rules_in_one_group_all_apply(): void {
		$posts = array( 'type' => 'posts', 'post_type' => 'post' );
		$pages = array( 'type' => 'posts', 'post_type' => 'page' );
		$terms = array( 'type' => 'terms', 'taxonomy' => 'category' );
		$users = array( 'type' => 'users' );

		$post_and_status = array( array( $this->rule( 'post_type', 'post' ), $this->rule( 'post_status', 'publish' ) ) );
		$this->assertTrue( OPEX_Acf_Location::applies( $post_and_status, $posts ) );
		$this->assertFalse( OPEX_Acf_Location::applies( $post_and_status, $pages ), 'a post-only group does not apply to pages through its status rule' );

		$either = array( array( $this->rule( 'post_type', 'post' ) ), array( $this->rule( 'post_type', 'page' ) ) );
		$this->assertTrue( OPEX_Acf_Location::applies( $either, $posts ) );
		$this->assertTrue( OPEX_Acf_Location::applies( $either, $pages ), 'groups are still OR' );

		$with_viewer = array( array( $this->rule( 'post_type', 'post' ), $this->rule( 'current_user_role', 'administrator' ) ) );
		$this->assertTrue( OPEX_Acf_Location::applies( $with_viewer, $posts ), 'a rule about the viewer does not exclude the group' );
		$this->assertTrue( OPEX_Acf_Location::applies( array( array( $this->rule( 'post_status', 'publish' ) ) ), $pages ), 'a status rule alone fits any post type' );
		$this->assertTrue( OPEX_Acf_Location::applies( array( array( $this->rule( 'post_type', 'page' ), $this->rule( 'page_template', 'default' ) ) ), $pages ) );
		$this->assertFalse( OPEX_Acf_Location::applies( array( array( $this->rule( 'post_type', 'post', '!=' ), $this->rule( 'post_status', 'publish' ) ) ), $posts ) );
		$this->assertFalse( OPEX_Acf_Location::applies( array( array( $this->rule( 'current_user_role', 'administrator' ) ) ), $posts ), 'a viewer rule alone names no records' );

		$this->assertTrue( OPEX_Acf_Location::applies( array( array( $this->rule( 'taxonomy', 'category' ) ) ), $terms ) );
		$this->assertFalse( OPEX_Acf_Location::applies( array( array( $this->rule( 'taxonomy', 'category' ), $this->rule( 'post_type', 'post' ) ) ), $terms ) );
		$this->assertTrue( OPEX_Acf_Location::applies( array( array( $this->rule( 'user_role', 'editor' ) ) ), $users ) );
		$this->assertFalse( OPEX_Acf_Location::applies( array( array( $this->rule( 'user_role', 'editor' ), $this->rule( 'taxonomy', 'category' ) ) ), $users ) );
		$this->assertFalse( OPEX_Acf_Location::applies( array( array( $this->rule( 'user_form', 'all' ) ) ), $posts ) );
	}

	/** With ACF, pages get the page definition of a shared field name. */
	public function test_pages_get_their_own_definition(): void {
		$this->needs_acf();
		$this->group( 'group_opexb1_a_post', array( array( $this->rule( 'post_type', 'post' ), $this->rule( 'post_status', 'publish' ) ) ), 'opexb1_sections', array( 'post_sub' ) );
		$this->group( 'group_opexb1_b_page', array( array( $this->rule( 'post_type', 'page' ) ) ), 'opexb1_sections', array( 'page_sub' ) );
		$page = OPEX_Acf::definition( array( 'type' => 'posts', 'post_type' => 'page' ), 'acf:opexb1_sections' );
		$post = OPEX_Acf::definition( array( 'type' => 'posts', 'post_type' => 'post' ), 'acf:opexb1_sections' );
		$this->assertIsArray( $page );
		$this->assertIsArray( $post );
		$this->assertSame( array( 'page_sub' ), OPEX_Acf::sub_names( $page ) );
		$this->assertSame( array( 'post_sub' ), OPEX_Acf::sub_names( $post ) );
	}

	/** A field cut at the row limit keeps 1000 rows and names itself in a job note. */
	public function test_row_limit_cut_gets_a_note(): void {
		$this->needs_acf();
		$location = array( array( $this->rule( 'post_type', 'post' ) ) );
		$this->group( 'group_opexb1_many', $location, 'opexb1_many', array( 'v' ) );
		$this->group( 'group_opexb1_flex', $location, 'opexb1_flex', array( 'w' ), 'flexible_content' );
		$this->group( 'group_opexb1_few', $location, 'opexb1_few', array( 'u' ) );

		$id = wp_insert_post( array( 'post_title' => 'opexb1 many', 'post_status' => 'publish', 'post_type' => 'post' ) );
		$this->assertIsInt( $id );
		$this->posts[] = $id;
		update_post_meta( $id, 'opexb1_many', 1001 );
		update_post_meta( $id, 'opexb1_many_0_v', 'first' );
		update_post_meta( $id, 'opexb1_flex', array_fill( 0, 1002, 'row' ) );
		update_post_meta( $id, 'opexb1_few', 2 );

		$notes  = array();
		$config = OPEX_Exporter::sanitize_config(
			array(
				'source'     => 'posts:post',
				'format'     => 'json',
				'batch_size' => 10,
				'fields'     => array( 'core:ID', 'acf:opexb1_many', 'acf:opexb1_flex', 'acf:opexb1_few' ),
				'filters'    => array( 'rules' => array( array( 'field' => 'core:ID', 'op' => 'eq', 'value' => (string) $id ) ) ),
			),
			$notes
		);
		$this->assertIsArray( $config );
		$job = OPEX_Exporter::create( $config, 'ACF limit' );
		$this->assertIsArray( $job );
		$this->jobs[] = $job['id'];
		for ( $i = 0; $i < 20 && 'running' === $job['status']; $i++ ) {
			$job = OPEX_Exporter::run( $job['id'] );
		}
		$this->assertSame( 'done', $job['status'], (string) ( $job['error'] ?? '' ) );
		$rec  = OPEX_Jobs::get( $job['id'] );
		$data = json_decode( (string) file_get_contents( OPEX_Jobs::file_path( $rec ) ), true );
		$this->assertCount( 1, $data );
		$many = json_decode( (string) $data[0]['opexb1_many'], true );
		$flex = json_decode( (string) $data[0]['opexb1_flex'], true );
		$this->assertCount( 1000, $many, 'the first 1000 rows are kept' );
		$this->assertSame( 'first', $many[0]['v'] );
		$this->assertCount( 1000, $flex );

		$text = implode( "\n", (array) $rec['notes'] );
		$this->assertStringContainsString( 'opexb1_many', $text, 'the note names the cut repeater' );
		$this->assertStringContainsString( 'opexb1_flex', $text, 'the note names the cut flexible content' );
		$this->assertStringContainsString( '1000', $text, 'the note gives the limit' );
		$this->assertStringNotContainsString( 'opexb1_few', $text, 'a field under the limit is not named' );
	}

	/** On a network, a site admin's spread plan counts only the site's members. */
	public function test_spread_plan_counts_only_members(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Site membership exists only on a multisite network. Run this on the multisite lane.' );
		}
		$this->needs_acf();
		$this->group( 'group_opexb1_links', array( array( $this->rule( 'user_form', 'all' ) ) ), 'opexb1_links', array( 'url' ) );

		$member = wp_insert_user( array( 'user_login' => 'opexb1member' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'opexb1member' . wp_rand() . '@example.test', 'role' => 'subscriber' ) );
		$other  = wp_insert_user( array( 'user_login' => 'opexb1other' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'opexb1other' . wp_rand() . '@example.test', 'role' => 'subscriber' ) );
		$admin  = wp_insert_user( array( 'user_login' => 'opexb1admin' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'opexb1admin' . wp_rand() . '@example.test', 'role' => 'administrator' ) );
		foreach ( array( $member, $other, $admin ) as $uid ) {
			$this->assertIsInt( $uid );
			$this->users[] = $uid;
		}
		remove_user_from_blog( $other, 1 );
		add_user_to_blog( 2, $other, 'subscriber' );
		update_user_meta( $member, 'opexb1_links', 2 );
		update_user_meta( $other, 'opexb1_links', 37 );
		$this->assertFalse( is_super_admin( $admin ) );

		wp_set_current_user( $admin );
		$this->assertTrue( OPEX_Access::restricted() );
		$notes   = array();
		$headers = OPEX_Acf_Spread::headers(
			array(
				'source'      => 'users',
				'acf_flatten' => array( 'acf:opexb1_links' ),
			),
			array( 'acf:opexb1_links' => 'Links' ),
			$notes
		);
		$this->assertSame( array( 'Links 1 url', 'Links 2 url' ), array_values( $headers ), 'two rows, from the member only' );
		$this->assertStringNotContainsString( '37', implode( ' ', $notes ), 'nothing about the other site leaks' );

		// A super admin still sees the whole network.
		wp_set_current_user( 1 );
		$notes = array();
		$wide  = OPEX_Acf_Spread::headers( array( 'source' => 'users', 'acf_flatten' => array( 'acf:opexb1_links' ) ), array( 'acf:opexb1_links' => 'Links' ), $notes );
		$this->assertCount( 20, $wide );
	}
}
