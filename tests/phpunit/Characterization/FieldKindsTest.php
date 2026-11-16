<?php
/**
 * Characterization: field kinds (core, meta, tax, computed, col).
 *
 * @package Open_Export
 */

require_once __DIR__ . '/CharacterizationCase.php';

/**
 * One export per source with every kind of field.
 */
class FieldKindsTest extends CharacterizationCase {

	/**
	 * Posts with core, meta, tax and computed fields.
	 */
	public function test_posts_all_kinds(): void {
		$fields = array(
			'core:ID', 'core:post_title', 'core:post_content', 'core:post_excerpt', 'core:post_status', 'core:post_name',
			'core:post_date', 'core:post_date_gmt', 'core:post_modified', 'core:post_modified_gmt', 'core:post_author',
			'core:post_parent', 'core:menu_order', 'core:comment_status', 'core:comment_count', 'core:guid',
			'meta:opex_rating', 'meta:opex_note', 'meta:opex_settings', 'meta:missing_key',
			'tax:category', 'tax:post_tag', 'tax:post_format',
			'computed:permalink', 'computed:attachment_url', 'computed:featured_image_url', 'computed:author_display_name', 'computed:author_email',
		);
		$res = $this->export( array( 'source' => 'posts:post', 'fields' => $fields, 'batch_size' => 30 ), 'kinds-posts' );
		$this->assertSame( 60, $res['total'] );
		$this->assert_golden( 'kinds-posts-csv', $res );

		$rows = $this->parse_csv( $res['content'] );
		$row  = array_combine( $rows[0], $rows[1] );
		$this->assertSame( '{"color":"blue","sizes":[1,2,1],"on":true}', $row['opex_settings'], 'serialized meta is unserialized then JSON encoded' );
		$this->assertSame( '', $row['missing_key'] );
		$this->assertMatchesRegularExpression( '/^(News|Guides)$/', $row['category'] );
		$this->assertStringContainsString( ' | ', $row['post_tag'], 'several terms are joined with a pipe' );

		$json = $this->export( array( 'source' => 'posts:post', 'fields' => array( 'core:ID', 'meta:opex_settings' ), 'format' => 'json' ), 'kinds-json' );
		$this->assertSame( '{"color":"blue","sizes":[1,2,1],"on":true}', json_decode( $json['content'], true )[0]['opex_settings'] );
	}

	/**
	 * Several meta rows for one key are joined with a pipe, in id order.
	 */
	public function test_multi_value_meta(): void {
		$id = $this->temp_post( 'multi meta', 410, array( 'post_excerpt' => 'opexmulti' ) );
		add_post_meta( $id, 'opex_multi', 'one' );
		add_post_meta( $id, 'opex_multi', 'two' );
		add_post_meta( $id, 'opex_multi', 'three' );
		$res = $this->export( array(
			'source'  => 'posts:post',
			'fields'  => array( 'core:ID', 'meta:opex_multi' ),
			'filters' => array( 'relation' => 'AND', 'rules' => array( array( 'field' => 'core:post_excerpt', 'op' => 'eq', 'value' => 'opexmulti' ) ) ),
		), 'multi' );
		$this->assertSame( array( array( (string) $id, 'one | two | three' ) ), array_slice( $this->parse_csv( $res['content'] ), 1 ) );
	}

	/**
	 * Users with core, meta and computed fields. Password hashes never export.
	 */
	public function test_users_all_kinds(): void {
		$fields = array( 'core:ID', 'core:user_login', 'core:user_email', 'core:user_nicename', 'core:user_url', 'core:user_registered', 'core:display_name', 'meta:first_name', 'meta:last_name', 'meta:opex_plan', 'meta:opex_prefs', 'meta:wp_capabilities', 'computed:roles' );
		$res    = $this->export( array( 'source' => 'users', 'fields' => $fields, 'batch_size' => 10 ), 'kinds-users' );
		$this->assert_golden( 'kinds-users-csv', $res );

		$notes  = array();
		$config = $this->config( array( 'source' => 'users', 'fields' => array( 'core:ID', 'core:user_pass', 'core:user_activation_key', 'col:ID', 'tax:category', 'woo:total', 'computed:nope', 'nonsense' ) ), $notes );
		$this->assertSame( array( 'core:ID' ), $config['fields'] );
		$this->assertNotEmpty( $notes );
	}

	/**
	 * Raw tables accept only col fields, and reject the secret columns.
	 */
	public function test_table_col_fields(): void {
		global $wpdb;
		$notes  = array();
		$config = $this->config( array( 'source' => 'table:' . $wpdb->users, 'fields' => array( 'col:ID', 'col:user_login', 'col:user_pass', 'col:user_activation_key', 'core:ID', 'meta:x', 'col:missing' ) ), $notes );
		$this->assertSame( array( 'col:ID', 'col:user_login' ), $config['fields'] );
		$this->assertNotEmpty( $notes );
		$res = $this->export( array( 'source' => 'table:' . $wpdb->users, 'fields' => array( 'col:ID', 'col:user_login', 'col:user_registered' ) ), 'kinds-table' );
		$this->assert_golden( 'kinds-table-users', $res );
	}

	/**
	 * Comments and terms export their core fields.
	 */
	public function test_comments_and_terms_core(): void {
		$res = $this->export( array( 'source' => 'comments', 'fields' => array( 'core:comment_ID', 'core:comment_type', 'core:comment_approved', 'core:user_id', 'core:comment_parent' ), 'batch_size' => 50 ), 'kinds-comments' );
		$this->assert_golden( 'kinds-comments-csv', $res );
		$res = $this->export( array( 'source' => 'terms:post_tag', 'fields' => array( 'core:term_id', 'core:name', 'core:slug', 'core:description', 'core:parent', 'core:count' ) ), 'kinds-terms' );
		$this->assert_golden( 'kinds-terms-csv', $res );
	}

	/**
	 * Rows of a forbidden column are removed from the fetched row.
	 */
	public function test_forbidden_columns_are_not_in_rows(): void {
		$config = $this->config( array( 'source' => 'users', 'fields' => array( 'core:ID' ) ) );
		$batch  = OPEX_Query::batch( $config, 0, 5 );
		$this->assertArrayNotHasKey( 'user_pass', $batch['items'][0] );
		$this->assertTrue( OPEX_Query::is_forbidden_column( 'user_pass' ) );
		$this->assertTrue( OPEX_Query::is_forbidden_column( 'user_activation_key' ) );
		$this->assertFalse( OPEX_Query::is_forbidden_column( 'user_email' ) );
	}
}
