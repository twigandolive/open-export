<?php
/**
 * Characterization: every source type through the real engine.
 *
 * @package Open_Export
 */

require_once __DIR__ . '/CharacterizationCase.php';

/**
 * Create, run in a loop and compare with golden output.
 */
class SourcesTest extends CharacterizationCase {

	/**
	 * Sources with a field list and the expected row count.
	 *
	 * @return array
	 */
	public static function sources(): array {
		$post_fields = array( 'core:ID', 'core:post_title', 'core:post_status', 'core:post_date', 'core:post_content', 'meta:opex_rating', 'meta:opex_note', 'meta:opex_settings', 'tax:category', 'tax:post_tag', 'computed:permalink', 'computed:author_display_name' );
		return array(
			'posts_post'        => array( 'posts:post', $post_fields, 60 ),
			'posts_page'        => array( 'posts:page', array( 'core:ID', 'core:post_title', 'core:post_name', 'core:post_date', 'computed:permalink' ), 15 ),
			'users'             => array( 'users', array( 'core:ID', 'core:user_login', 'core:user_email', 'core:display_name', 'core:user_registered', 'meta:first_name', 'meta:opex_plan', 'meta:opex_prefs', 'computed:roles' ), 26 ),
			'comments'          => array( 'comments', array( 'core:comment_ID', 'core:comment_post_ID', 'core:comment_author', 'core:comment_author_email', 'core:comment_date', 'core:comment_content', 'core:comment_approved' ), -1 ),
			'terms_category'    => array( 'terms:category', array( 'core:term_id', 'core:name', 'core:slug', 'core:parent', 'core:count' ), 3 ),
			'table_posts'       => array( 'table:{prefix}posts', array( 'col:ID', 'col:post_title', 'col:post_type', 'col:post_status', 'col:post_date' ), 75 ),
			'table_terms'       => array( 'table:{prefix}terms', array( 'col:term_id', 'col:name', 'col:slug' ), -1 ),
			'table_no_int_pk'   => array( 'table:{prefix}term_relationships', array( 'col:object_id', 'col:term_taxonomy_id', 'col:term_order' ), -1 ),
		);
	}

	/**
	 * Export one source and compare with its golden files.
	 *
	 * @param string $source   Source id.
	 * @param array  $fields   Fields.
	 * @param int    $expected Expected rows or -1 to only compare with count().
	 * @dataProvider sources
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'sources' )]
	public function test_source_through_engine( string $source, array $fields, int $expected ): void {
		global $wpdb;
		$network = array(
			'posts_post' => 'the multisite main site uses pretty permalinks, the golden uses ?p= links.',
			'posts_page' => 'the multisite main site uses pretty permalinks, the golden uses ?p= links.',
			'users'      => 'the users table is shared by every site of the network.',
			'comments'   => 'the network seed writes WooCommerce order notes with another address.',
		);
		if ( isset( $network[ $this->dataName() ] ) ) {
			$this->single_site_only( $network[ $this->dataName() ] );
		}
		$source = str_replace( '{prefix}', $wpdb->prefix, $source );
		$name   = 'source-' . preg_replace( '/[^a-z0-9]+/i', '-', $this->dataName() );
		$raw    = array( 'source' => $source, 'fields' => $fields, 'batch_size' => 10 );
		if ( 'table_posts' === $this->dataName() ) {
			// Order rows in the posts table change when the legacy-orders lane runs, so look at content only.
			$raw['filters'] = array( 'relation' => 'OR', 'rules' => array( array( 'field' => 'col:post_type', 'op' => 'eq', 'value' => 'post' ), array( 'field' => 'col:post_type', 'op' => 'eq', 'value' => 'page' ) ) );
		}
		$res    = $this->export( $raw, $name );
		if ( $expected >= 0 ) {
			$this->assertSame( $expected, $res['total'] );
		}
		$this->assertSame( $res['total'], (int) $res['job']['processed'] );
		$this->assertGreaterThan( 0, $res['total'] );
		$this->assert_golden( $name, $res );
	}

	/**
	 * The terms and pk-less table sources are paged by offset, others by key.
	 */
	public function test_cursor_kinds(): void {
		global $wpdb;
		$keyset = $this->config( array( 'source' => 'users', 'fields' => array( 'core:ID' ), 'batch_size' => 10 ) );
		$batch  = OPEX_Query::batch( $keyset, 0, 10 );
		$this->assertCount( 10, $batch['items'] );
		$this->assertFalse( $batch['done'] );
		$this->assertSame( (int) $batch['items'][9]['core:ID'], $batch['cursor'], 'keyset cursor is the last primary key' );

		$offset = $this->config( array( 'source' => 'table:' . $wpdb->prefix . 'term_relationships', 'fields' => array( 'col:object_id' ) ) );
		$batch  = OPEX_Query::batch( $offset, 0, 10 );
		$this->assertSame( 10, $batch['cursor'], 'offset cursor is the row count' );
	}

	/**
	 * A source whose row count is a multiple of the batch size costs an extra empty batch.
	 */
	public function test_exact_multiple_costs_one_extra_batch(): void {
		$res = $this->export( array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'batch_size' => 20 ), 'exact' );
		$this->assertSame( 60, $res['total'] );
		$this->assertSame( 4, $res['batches'] );
	}

	/**
	 * Unknown sources and empty field lists are rejected.
	 */
	public function test_rejections(): void {
		$bad = OPEX_Exporter::sanitize_config( array( 'source' => 'nope', 'fields' => array( 'core:ID' ) ) );
		$this->assertWPError( $bad );
		$this->assertSame( 'opex_bad_source', $bad->get_error_code() );
		$none = OPEX_Exporter::sanitize_config( array( 'source' => 'users', 'fields' => array( 'bogus' ) ) );
		$this->assertWPError( $none );
		$this->assertSame( 'opex_no_fields', $none->get_error_code() );
	}

	/**
	 * Sanitize adds exactly the eight 1.3.1 keys.
	 */
	public function test_config_keys(): void {
		$config = $this->config( array( 'source' => 'users', 'fields' => array( 'core:ID', 'core:ID' ) ) );
		$keys   = array_keys( $config );
		sort( $keys );
		$this->assertSame( array( 'batch_size', 'column_labels', 'csv_delimiter', 'date_range', 'fields', 'filters', 'format', 'source' ), $keys );
		$this->assertSame( array( 'core:ID' ), $config['fields'] );
		$this->assertSame( 500, $config['batch_size'] );
		$this->assertSame( 'csv', $config['format'] );
		$this->assertSame( 'comma', $config['csv_delimiter'] );
	}
}
