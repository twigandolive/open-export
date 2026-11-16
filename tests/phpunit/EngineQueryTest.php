<?php
/**
 * Engine fixes for the query layer: S-3, S-8, S-14, S-15, S-16, S-19, S-24, S-28, S-30 and NULL text columns.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class EngineQueryTest extends TestCase {

	/** @var string[] */
	private array $jobs = array();

	/** @var string[] */
	private array $tables = array();

	protected function setUp(): void {
		wp_set_current_user( 1 );
	}

	protected function tearDown(): void {
		global $wpdb;
		foreach ( $this->jobs as $id ) {
			OPEX_Jobs::delete( $id );
		}
		$this->jobs = array();
		foreach ( $this->tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		$this->tables = array();
		OPEX_Catalog::reset_cache();
		delete_user_meta( 1, 'session_tokens_probe' );
		delete_option( 'opex_templates' );
		delete_option( 'opex_schedules' );
	}

	/** Create a raw table and make the catalog see it. */
	private function table( string $name, string $columns ): string {
		global $wpdb;
		$full = $wpdb->prefix . $name;
		$wpdb->query( "DROP TABLE IF EXISTS `{$full}`" );
		$wpdb->query( "CREATE TABLE `{$full}` ( {$columns} )" );
		$this->tables[] = $full;
		OPEX_Catalog::reset_cache();
		return $full;
	}

	/** Sanitize a config and fail if it is refused. */
	private function config( array $raw ): array {
		$config = OPEX_Exporter::sanitize_config( $raw );
		$this->assertIsArray( $config, is_wp_error( $config ) ? $config->get_error_message() : '' );
		return $config;
	}

	/** First column of every row a config returns. */
	private function ids( array $config ): array {
		$out = array();
		$cursor = 0;
		for ( $i = 0; $i < 50; $i++ ) {
			$batch = OPEX_Query::batch( $config, $cursor, (int) $config['batch_size'] );
			foreach ( $batch['items'] as $item ) {
				$out[] = (string) reset( $item );
			}
			$cursor = $batch['cursor'];
			if ( $batch['done'] ) {
				break;
			}
		}
		return $out;
	}

	/** Run a job to the end and return the file text. */
	private function export( array $config ): array {
		$job = OPEX_Exporter::create( $config, 'engine query' );
		$this->assertIsArray( $job );
		$this->jobs[] = $job['id'];
		for ( $i = 0; $i < 100 && 'running' === $job['status']; $i++ ) {
			$job = OPEX_Exporter::run( $job['id'] );
			$this->assertIsArray( $job );
		}
		$this->assertSame( 'done', $job['status'], (string) $job['error'] );
		return array( OPEX_Jobs::get( $job['id'] ), (string) file_get_contents( OPEX_Jobs::file_path( OPEX_Jobs::get( $job['id'] ) ) ) );
	}

	/** S-3: a rule on a field the source lacks is refused with the field named, everywhere a config is saved. */
	public function test_bad_filter_field_is_refused_everywhere(): void {
		$raw = array(
			'source'  => 'posts:post',
			'fields'  => array( 'core:ID' ),
			'filters' => array( 'rules' => array( array( 'field' => 'meta:ok', 'op' => 'eq', 'value' => '1' ), array( 'field' => 'core:typo_field', 'op' => 'eq', 'value' => 'x' ) ) ),
		);

		$req = new WP_REST_Request( 'POST', '/open-export/v1/preview' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $raw ) );
		$res = rest_do_request( $req );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'opex_bad_filter', $res->get_data()['code'] );
		$this->assertStringContainsString( 'core:typo_field', $res->get_data()['message'] );

		$req = new WP_REST_Request( 'POST', '/open-export/v1/jobs' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $raw ) );
		$res = rest_do_request( $req );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'opex_bad_filter', $res->get_data()['code'] );

		$tpl = OPEX_Templates::save( 'Bad', $raw );
		$this->assertWPError( $tpl );
		$this->assertSame( 'opex_bad_filter', $tpl->get_error_code() );

		$sched = OPEX_Schedules::save( array( 'name' => 'Bad', 'config' => $raw, 'frequency' => 'daily', 'time' => '03:00' ) );
		$this->assertWPError( $sched );
		$this->assertSame( 'opex_bad_filter', $sched->get_error_code() );
		$this->assertSame( 400, $sched->get_error_data()['status'] );
	}

	/** S-3: computed values and credential keys cannot be filtered on. */
	public function test_unfilterable_and_credential_fields_are_refused(): void {
		foreach ( array( 'computed:permalink', 'meta:session_tokens', 'meta:_application_passwords' ) as $field ) {
			$out = OPEX_Exporter::sanitize_config( array( 'source' => 'users' === substr( $field, 0, 4 ) ? 'users' : 'posts:post', 'fields' => array( 'core:ID' ), 'filters' => array( 'rules' => array( array( 'field' => $field, 'op' => 'eq', 'value' => 'x' ) ) ) ) );
			$this->assertWPError( $out, $field );
			$this->assertSame( 'opex_bad_filter', $out->get_error_code(), $field );
		}
		$users = OPEX_Exporter::sanitize_config( array( 'source' => 'users', 'fields' => array( 'core:ID' ), 'filters' => array( 'rules' => array( array( 'field' => 'meta:session_tokens', 'op' => 'contains', 'value' => 'a' ) ) ) ) );
		$this->assertWPError( $users );
	}

	/** S-3: the recipe path still reports the dropped rule and keeps going. */
	public function test_recipe_keeps_report_and_drop(): void {
		$res = OPEX_Schema::recipe_to_config( array(
			'source'  => 'posts:post',
			'fields'  => array( 'core:ID' ),
			'filters' => array( 'rules' => array( array( 'field' => 'core:typo_field', 'op' => 'eq', 'value' => 'x' ), array( 'field' => 'core:ID', 'op' => 'gt', 'value' => '1' ) ) ),
		) );
		$this->assertTrue( $res['ok'] );
		$this->assertSame( 'dropped', $res['report']['filters'][0]['status'] );
		$this->assertCount( 1, $res['config']['filters']['rules'] );
	}

	/** S-3: unknown fields at query time match nothing instead of vanishing. */
	public function test_unknown_field_at_query_time_matches_nothing(): void {
		$config = $this->config( array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ) ) );
		$config['filters'] = array( 'relation' => 'AND', 'rules' => array( array( 'field' => 'core:nope', 'op' => 'eq', 'value' => 'x' ) ) );
		$this->assertSame( 0, OPEX_Query::count( $config ) );
	}

	/** S-30: a recipe with the wrong shapes at every level does not warn or fail. */
	public function test_recipe_with_wrong_shapes(): void {
		$warnings = array();
		set_error_handler( static function ( $no, $msg ) use ( &$warnings ) {
			$warnings[] = $msg;
			return true;
		} );
		$shapes = array(
			array( 'source' => 'posts:post', 'fields' => array( 'core:ID', array( 'x' ) ), 'filters' => 'nope' ),
			array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'filters' => array( 'rules' => 'nope' ) ),
			array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'filters' => array( 'relation' => array( 'x' ), 'rules' => array( 'text', array( 'field' => array( 'a' ), 'op' => array( 'b' ), 'value' => array( 'c' ) ) ) ) ),
			array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'name' => array( 'x' ), 'format' => array( 'x' ), 'csv_delimiter' => array( 'x' ), 'date_range' => array( 'preset' => array( 'x' ), 'field' => array( 'y' ) ) ),
		);
		foreach ( $shapes as $i => $recipe ) {
			$res = OPEX_Schema::recipe_to_config( $recipe );
			$this->assertIsArray( $res, "shape $i" );
			$this->assertTrue( $res['ok'], "shape $i" );
		}
		restore_error_handler();
		$this->assertSame( array(), $warnings );
	}

	/** S-8: a table with a composite key pages in key order with no row twice or missed. */
	public function test_table_without_integer_key_is_ordered(): void {
		global $wpdb;
		$table = $this->table( 'opex_eng_pair', 'a INT NOT NULL, b INT NOT NULL, PRIMARY KEY (a, b)' );
		$pairs = array();
		for ( $a = 5; $a >= 1; $a-- ) {
			for ( $b = 4; $b >= 1; $b-- ) {
				$wpdb->insert( $table, array( 'a' => $a, 'b' => $b ) );
				$pairs[] = $a . '-' . $b;
			}
		}
		$config = $this->config( array( 'source' => 'table:' . $table, 'fields' => array( 'col:a', 'col:b' ), 'batch_size' => 10 ) );
		$got = array();
		$cursor = 0;
		do {
			$batch = OPEX_Query::batch( $config, $cursor, 7 );
			foreach ( $batch['items'] as $item ) {
				$got[] = $item['col:a'] . '-' . $item['col:b'];
			}
			$cursor = $batch['cursor'];
		} while ( ! $batch['done'] );
		$this->assertCount( 20, $got );
		$this->assertSame( 20, count( array_unique( $got ) ) );
		$want = $pairs;
		usort( $want, static function ( $x, $y ) {
			return array_map( 'intval', explode( '-', $x ) ) <=> array_map( 'intval', explode( '-', $y ) );
		} );
		$this->assertSame( $want, $got, 'rows come back in primary key order' );
		$this->assertSame( array(), OPEX_Query::export_notes( $config ), 'a keyed table needs no warning' );
	}

	/** S-8: a table with no key at all gets a job note. */
	public function test_table_without_any_key_gets_a_note(): void {
		global $wpdb;
		$table = $this->table( 'opex_eng_nokey', 'a INT, b VARCHAR(10)' );
		$wpdb->insert( $table, array( 'a' => 1, 'b' => 'x' ) );
		$config = $this->config( array( 'source' => 'table:' . $table, 'fields' => array( 'col:a' ) ) );
		$job    = OPEX_Exporter::create( $config, 'nokey' );
		$this->jobs[] = $job['id'];
		$notes = OPEX_Jobs::get( $job['id'] )['notes'];
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'not guaranteed', $notes[0] );
	}

	/** S-16: column lists are read once per request. */
	public function test_describe_runs_once_per_table(): void {
		global $wpdb;
		$table = $this->table( 'opex_eng_cols', 'id INT NOT NULL PRIMARY KEY, c1 INT, c2 INT, c3 INT, c4 INT, created DATETIME' );
		$wpdb->insert( $table, array( 'id' => 1, 'c1' => 1, 'c2' => 2, 'c3' => 3, 'c4' => 4, 'created' => '2026-01-01 00:00:00' ) );
		$count = 0;
		$spy   = static function ( $query ) use ( &$count ) {
			if ( 0 === stripos( ltrim( (string) $query ), 'DESCRIBE' ) ) {
				++$count;
			}
			return $query;
		};
		add_filter( 'query', $spy );
		$config = $this->config( array( 'source' => 'table:' . $table, 'fields' => array( 'col:id', 'col:c1', 'col:c2', 'col:c3', 'col:c4', 'col:created' ), 'date_range' => array( 'preset' => 'last_year' ) ) );
		OPEX_Query::count( $config );
		OPEX_Query::batch( $config, 0, 10 );
		OPEX_Query::batch( $config, 0, 10 );
		remove_filter( 'query', $spy );
		$this->assertSame( 1, $count );
	}

	/** NULL text columns count as empty for eq, neq and empty. */
	public function test_null_text_counts_as_empty(): void {
		global $wpdb;
		$table = $this->table( 'opex_eng_null', 'id INT NOT NULL PRIMARY KEY, name VARCHAR(20) NULL' );
		$wpdb->query( "INSERT INTO `{$table}` (id, name) VALUES (1, NULL), (2, ''), (3, 'x')" );
		$ids = function ( string $op, string $value ) use ( $table ): array {
			$config = $this->config( array( 'source' => 'table:' . $table, 'fields' => array( 'col:id' ), 'filters' => array( 'rules' => array( array( 'field' => 'col:name', 'op' => $op, 'value' => $value ) ) ) ) );
			$this->assertSame( OPEX_Query::count( $config ), count( $this->ids( $config ) ) );
			return $this->ids( $config );
		};
		$this->assertSame( array( '1', '2' ), $ids( 'eq', '' ) );
		$this->assertSame( array( '3' ), $ids( 'neq', '' ) );
		$this->assertSame( array( '1', '2' ), $ids( 'empty', '' ) );
		$this->assertSame( array( '3' ), $ids( 'not_empty', '' ) );
		$this->assertSame( array( '1', '2' ), $ids( 'neq', 'x' ), 'empty is not equal to x' );
		$this->assertSame( array( '3' ), $ids( 'eq', 'x' ) );
	}

	/** S-19: credential values never reach the file, through the raw usermeta table. */
	public function test_usermeta_table_blanks_credentials(): void {
		global $wpdb;
		update_user_meta( 1, 'session_tokens', array( 'TOKENSECRET' => array( 'expiration' => time() + 100 ) ) );
		update_user_meta( 1, '_application_passwords', array( array( 'uuid' => 'u', 'password' => 'HASHSECRET' ) ) );
		update_user_meta( 1, 'session_tokens_probe', 'visible value' );
		try {
			$config = $this->config( array( 'source' => 'table:' . $wpdb->usermeta, 'fields' => array( 'col:umeta_id', 'col:meta_key', 'col:meta_value' ), 'batch_size' => 100 ) );
			list( $job, $text ) = $this->export( $config );
			$this->assertStringNotContainsString( 'TOKENSECRET', $text );
			$this->assertStringNotContainsString( 'HASHSECRET', $text );
			$this->assertStringContainsString( 'visible value', $text, 'other meta still exports' );
			$this->assertMatchesRegularExpression( '/,session_tokens,""?\n|,session_tokens,\n/', $text . "\n", 'the row stays with an empty value' );
			$this->assertNotEmpty( $job['notes'] );

			// A filter cannot probe the credential value either.
			$probe = $config;
			$probe['filters'] = array( 'relation' => 'AND', 'rules' => array( array( 'field' => 'col:meta_value', 'op' => 'contains', 'value' => 'TOKENSECRET' ) ) );
			$this->assertSame( 0, OPEX_Query::count( $probe ) );
			$probe['filters']['rules'][0]['value'] = 'HASHSECRET';
			$this->assertSame( 0, OPEX_Query::count( $probe ) );
			$probe['filters']['rules'][0]['value'] = 'visible value';
			$this->assertSame( 1, OPEX_Query::count( $probe ) );
		} finally {
			delete_user_meta( 1, 'session_tokens' );
			delete_user_meta( 1, '_application_passwords' );
		}
	}

	/** S-19: the user source never reads the credential keys, even from a hand-made config. */
	public function test_users_source_never_reads_credential_meta(): void {
		update_user_meta( 1, '_application_passwords', array( array( 'uuid' => 'u', 'password' => 'HASHSECRET' ) ) );
		update_user_meta( 1, 'session_tokens', array( 'TOKENSECRET' => array() ) );
		try {
			$notes  = array();
			$config = OPEX_Exporter::sanitize_config( array( 'source' => 'users', 'fields' => array( 'core:ID', 'meta:session_tokens', 'meta:_application_passwords' ) ), $notes );
			$this->assertSame( array( 'core:ID' ), $config['fields'] );
			$this->assertCount( 2, $notes );

			$config['fields'] = array( 'core:ID', 'meta:session_tokens', 'meta:_application_passwords' );
			$items = OPEX_Query::batch( $config, 0, 50 )['items'];
			$this->assertNotEmpty( $items );
			foreach ( $items as $item ) {
				$this->assertSame( '', $item['meta:session_tokens'] );
				$this->assertSame( '', $item['meta:_application_passwords'] );
			}
			$config['filters'] = array( 'rules' => array( array( 'field' => 'meta:session_tokens', 'op' => 'not_empty', 'value' => '' ) ) );
			$this->assertSame( 0, OPEX_Query::count( $config ), 'a hand-made filter matches nothing' );

			delete_transient( 'opex_keys_' . md5( 'users|' ) );
			$keys = OPEX_Catalog::sample_meta_keys( 'users' );
			$this->assertNotContains( 'session_tokens', $keys );
			$this->assertNotContains( '_application_passwords', $keys );
			$this->assertSame( array( 'session_tokens', '_application_passwords' ), OPEX_Query::CREDENTIAL_META_KEYS );
		} finally {
			delete_user_meta( 1, 'session_tokens' );
			delete_user_meta( 1, '_application_passwords' );
			delete_transient( 'opex_keys_' . md5( 'users|' ) );
		}
	}

	/** S-15: the shared helpers behave the same way for every caller. */
	public function test_shared_helpers(): void {
		$this->assertTrue( OPEX_Query::is_field_id( 'woo:total' ) );
		$this->assertTrue( OPEX_Query::is_field_id( 'meta:_sku' ) );
		$this->assertFalse( OPEX_Query::is_field_id( 'total' ) );
		$this->assertFalse( OPEX_Query::is_field_id( 'meta:' ) );
		$this->assertSame( '_sku', OPEX_Query::field_name( 'meta:_sku' ) );
		$this->assertSame( 'plain', OPEX_Query::field_name( 'plain' ) );
		$this->assertSame( 'OR', OPEX_Query::normalize_relation( 'or' ) );
		$this->assertSame( 'AND', OPEX_Query::normalize_relation( 'XOR' ) );
		$this->assertSame( 'AND', OPEX_Query::normalize_relation( null ) );
		$src = OPEX_Query::parse_source( 'posts:post' );
		$this->assertSame( 'core:post_modified', OPEX_Query::date_field_or_default( $src, 'core:post_modified' ) );
		$this->assertSame( 'core:post_date', OPEX_Query::date_field_or_default( $src, 'nope' ) );
		$this->assertSame( '', OPEX_Query::date_field_or_default( array( 'type' => 'terms', 'taxonomy' => 'category' ), 'nope' ) );
	}

	/** S-14: the time zone comes straight from WordPress. */
	public function test_site_timezone_follows_wordpress(): void {
		$this->assertSame( wp_timezone()->getName(), OPEX_Dates::site_timezone()->getName() );
		$this->assertSame( 'This month so far', OPEX_Dates::preset_label( 'this_month' ) );
	}

	/** S-24: saving a template under an existing name updates it. */
	public function test_template_with_same_name_updates(): void {
		$raw   = array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ) );
		$first = OPEX_Templates::save( 'Weekly', $raw );
		$next  = OPEX_Templates::save( 'Weekly', array( 'source' => 'posts:post', 'fields' => array( 'core:ID', 'core:post_title' ) ) );
		$this->assertSame( $first['id'], $next['id'] );
		$this->assertCount( 1, OPEX_Templates::all() );
		$this->assertSame( array( 'core:ID', 'core:post_title' ), OPEX_Templates::get( $first['id'] )['config']['fields'] );
		OPEX_Templates::save( 'Other', $raw );
		$this->assertCount( 2, OPEX_Templates::all() );
	}

	/** S-28: the "see all fields" link is built from rest_url. */
	public function test_schema_link_uses_rest_url(): void {
		$post = wp_insert_post( array( 'post_title' => 'meta bag', 'post_status' => 'publish' ) );
		for ( $i = 0; $i < 130; $i++ ) {
			add_post_meta( $post, 'opex_bulk_key_' . $i, 'v' );
		}
		delete_transient( 'opex_keys_' . md5( 'posts|post' ) );
		try {
			$doc  = OPEX_Schema::build( '', true );
			$link = '';
			foreach ( $doc['sources'] as $source ) {
				if ( 'posts:post' === $source['id'] ) {
					$link = (string) ( $source['to_see_all_fields'] ?? '' );
				}
			}
			$this->assertNotSame( '', $link );
			$this->assertSame( 0, strpos( $link, rest_url( 'open-export/v1/schema' ) ) );
			$this->assertStringContainsString( 'source=posts%3Apost', $link );
		} finally {
			wp_delete_post( $post, true );
			delete_transient( 'opex_keys_' . md5( 'posts|post' ) );
		}
	}

	/**
	 * Asserts a value is a WP_Error.
	 *
	 * @param mixed  $value Value.
	 * @param string $msg   Message.
	 */
	public static function assertWPError( $value, string $msg = '' ): void {
		self::assertInstanceOf( WP_Error::class, $value, $msg );
	}
}
