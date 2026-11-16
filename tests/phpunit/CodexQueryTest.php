<?php
/**
 * Codex round 1 fixes in the query layer: R1-2.1, R1-2.2, R1-2.3, R1-2.4 and R1-2.7.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Records every call a serialized object would make if PHP built it.
 */
class Opex_B1_Wakeup_Probe {

	/** @var string[] Calls seen. */
	public static $calls = array();

	/** @var int A property, so the payload has a body. */
	public $x = 1;

	/** Runs when unserialize() builds the object. */
	public function __wakeup() {
		self::$calls[] = 'wakeup';
	}

	/** Runs when the built object is freed. */
	public function __destruct() {
		self::$calls[] = 'destruct';
	}
}

class CodexQueryTest extends TestCase {

	/** @var string[] */
	private $jobs = array();

	/** @var string[] */
	private $tables = array();

	/** @var int[] */
	private $posts = array();

	/** @var int[] */
	private $terms = array();

	/** @var int[] */
	private $users = array();

	protected function setUp(): void {
		wp_set_current_user( 1 );
		Opex_B1_Wakeup_Probe::$calls = array();
	}

	protected function tearDown(): void {
		global $wpdb;
		foreach ( $this->jobs as $id ) {
			OPEX_Jobs::delete( $id );
		}
		foreach ( $this->tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		foreach ( $this->posts as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( $this->terms as $id ) {
			wp_delete_term( $id, 'category' );
		}
		if ( $this->users ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			if ( is_multisite() ) {
				require_once ABSPATH . 'wp-admin/includes/ms.php';
			}
			foreach ( $this->users as $id ) {
				is_multisite() ? wpmu_delete_user( $id ) : wp_delete_user( $id );
			}
		}
		$this->jobs   = array();
		$this->tables = array();
		$this->posts  = array();
		$this->terms  = array();
		$this->users  = array();
		OPEX_Catalog::reset_cache();
	}

	/** A sanitized config. Fails the test when it is refused. */
	private function config( array $raw ): array {
		$config = OPEX_Exporter::sanitize_config( $raw );
		$this->assertIsArray( $config, is_wp_error( $config ) ? $config->get_error_message() : '' );
		return $config;
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

	/** Every row a config returns, passing each cursor back as it came. */
	private function all_rows( array $config, int $limit ): array {
		$out    = array();
		$cursor = 0;
		for ( $i = 0; $i < 50; $i++ ) {
			$batch  = OPEX_Query::batch( $config, $cursor, $limit );
			$out    = array_merge( $out, $batch['items'] );
			$cursor = $batch['cursor'];
			if ( $batch['done'] ) {
				return $out;
			}
		}
		$this->fail( 'The export never finished: the cursor did not move on.' );
	}

	/** Run a job to the end and return its record and file text. */
	private function export( array $config, ?callable $before = null ): array {
		$job = OPEX_Exporter::create( $config, 'codex query' );
		$this->assertIsArray( $job );
		$this->jobs[] = $job['id'];
		if ( $before ) {
			$before( $job['id'] );
		}
		for ( $i = 0; $i < 100 && 'running' === $job['status']; $i++ ) {
			$job = OPEX_Exporter::run( $job['id'] );
			$this->assertIsArray( $job );
		}
		$this->assertSame( 'done', $job['status'], (string) $job['error'] );
		$rec = OPEX_Jobs::get( $job['id'] );
		return array( $rec, (string) file_get_contents( OPEX_Jobs::file_path( $rec ) ) );
	}

	/** R1-2.1: serialized objects are never built, and arrays still read as JSON. */
	public function test_serialized_objects_are_never_built(): void {
		global $wpdb;
		$class  = 'Opex_B1_Wakeup_Probe';
		$object = 'O:' . strlen( $class ) . ':"' . $class . '":1:{s:1:"x";i:1;}';
		$nested = 'a:2:{i:0;s:1:"a";i:1;' . $object . '}';
		$id     = wp_insert_post( array( 'post_title' => 'opex b1 serialized', 'post_status' => 'publish', 'post_type' => 'post' ) );
		$this->assertIsInt( $id );
		$this->posts[] = $id;
		$wpdb->update( $wpdb->posts, array( 'post_content' => $object, 'post_excerpt' => 'a:2:{i:0;s:1:"a";s:1:"k";i:2;}' ), array( 'ID' => $id ) );
		$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $id, 'meta_key' => 'opex_b1_nested', 'meta_value' => $nested ) );
		$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $id, 'meta_key' => 'opex_b1_std', 'meta_value' => 'O:8:"stdClass":1:{s:1:"a";i:1;}' ) );
		clean_post_cache( $id );

		$config = $this->config( array(
			'source'  => 'posts:post',
			'fields'  => array( 'core:post_content', 'core:post_excerpt', 'meta:opex_b1_nested', 'meta:opex_b1_std' ),
			'filters' => array( 'rules' => array( array( 'field' => 'core:ID', 'op' => 'eq', 'value' => (string) $id ) ) ),
		) );
		$rows = OPEX_Query::batch( $config, 0, 10 )['items'];
		gc_collect_cycles();

		$this->assertSame( array(), Opex_B1_Wakeup_Probe::$calls, 'no object was built, so no magic method ran' );
		$this->assertCount( 1, $rows );
		$this->assertSame( $object, $rows[0]['core:post_content'], 'an object payload is exported as its stored text' );
		$this->assertSame( $nested, $rows[0]['meta:opex_b1_nested'], 'an array holding an object is exported as its stored text' );
		$this->assertSame( '{"0":"a","k":2}', $rows[0]['core:post_excerpt'], 'a serialized array still reads as JSON' );
		$this->assertSame( '{"a":1}', $rows[0]['meta:opex_b1_std'], 'a plain stdClass still reads as JSON' );
	}

	/** R1-2.2: term rules on description, parent and count read the term_taxonomy table. */
	public function test_term_rules_on_term_taxonomy_columns(): void {
		$parent = wp_insert_term( 'Opex B1 Parent', 'category', array( 'description' => 'opexb1 top' ) );
		$this->assertIsArray( $parent );
		$this->terms[] = (int) $parent['term_id'];
		$child = wp_insert_term( 'Opex B1 Child', 'category', array( 'parent' => (int) $parent['term_id'], 'description' => 'opexb1 inner' ) );
		$this->assertIsArray( $child );
		$this->terms[] = (int) $child['term_id'];

		$names = function ( array $rule ): array {
			$only   = array( 'field' => 'core:name', 'op' => 'starts_with', 'value' => 'Opex B1' );
			$config = $this->config( array( 'source' => 'terms:category', 'fields' => array( 'core:name' ), 'filters' => array( 'rules' => array( $only, $rule ) ) ) );
			$this->assertSame( count( $this->all_rows( $config, 10 ) ), OPEX_Query::count( $config ) );
			return array_column( $this->all_rows( $config, 10 ), 'core:name' );
		};
		$this->assertSame( array( 'Opex B1 Parent' ), $names( array( 'field' => 'core:parent', 'op' => 'eq', 'value' => '0' ) ) );
		$this->assertSame( array( 'Opex B1 Child' ), $names( array( 'field' => 'core:parent', 'op' => 'eq', 'value' => (string) $parent['term_id'] ) ) );
		$this->assertSame( array( 'Opex B1 Child' ), $names( array( 'field' => 'core:description', 'op' => 'contains', 'value' => 'inner' ) ) );
		$this->assertSame( array( 'Opex B1 Parent', 'Opex B1 Child' ), $names( array( 'field' => 'core:count', 'op' => 'lte', 'value' => '0' ) ) );
		$this->assertSame( array( 'Opex B1 Child' ), $names( array( 'field' => 'core:term_id', 'op' => 'eq', 'value' => (string) $child['term_id'] ) ), 'term_id still reads the terms table' );
	}

	/** R1-2.3: keys of zero and below export, a key of zero can end a batch, and string cursors work. */
	public function test_signed_integer_keys_export_every_row(): void {
		global $wpdb;
		$table = $this->table( 'opex_b1_signed', 'id INT NOT NULL PRIMARY KEY, name VARCHAR(20)' );
		foreach ( array( 2, -1, 0, 1, -2 ) as $id ) {
			$wpdb->insert( $table, array( 'id' => $id, 'name' => 'r' . $id ) );
		}
		$config = $this->config( array( 'source' => 'table:' . $table, 'fields' => array( 'col:id' ), 'batch_size' => 2 ) );

		// Batches of three end on key 0, which must not look like a fresh start.
		foreach ( array( 1, 2, 3, 5, 10 ) as $limit ) {
			$this->assertSame( array( '-2', '-1', '0', '1', '2' ), array_column( $this->all_rows( $config, $limit ), 'col:id' ), "batches of $limit" );
		}
		$this->assertSame( 5, OPEX_Query::count( $config ) );

		// A cursor saved as text, or as a plain integer by the older code, resumes after that key.
		$this->assertSame( array( '1', '2' ), array_column( OPEX_Query::batch( $config, '0', 5 )['items'], 'col:id' ) );
		$this->assertSame( array( '2' ), array_column( OPEX_Query::batch( $config, 1, 5 )['items'], 'col:id' ) );
		$this->assertSame( array( '0', '1', '2' ), array_column( OPEX_Query::batch( $config, '-1', 5 )['items'], 'col:id' ) );

		// A whole job through the runner, batches of two.
		list( $rec, $text ) = $this->export( array_merge( $config, array( 'format' => 'json' ) ) );
		$this->assertSame( 5, (int) $rec['processed'] );
		$this->assertSame( array( '-2', '-1', '0', '1', '2' ), array_map( 'strval', array_column( (array) json_decode( $text, true ), 'id' ) ) );
	}

	/** R1-2.3: a job saved by the older code with an integer cursor resumes after that key. */
	public function test_old_integer_cursor_resumes(): void {
		global $wpdb;
		$table = $this->table( 'opex_b1_resume', 'id INT NOT NULL PRIMARY KEY, name VARCHAR(20)' );
		for ( $id = 1; $id <= 6; $id++ ) {
			$wpdb->insert( $table, array( 'id' => $id, 'name' => 'r' . $id ) );
		}
		$config = $this->config( array( 'source' => 'table:' . $table, 'fields' => array( 'col:id' ), 'batch_size' => 2, 'format' => 'json' ) );
		list( $rec ) = $this->export(
			$config,
			static function ( string $id ): void {
				OPEX_Jobs::update(
					$id,
					static function ( array $job ): array {
						$job['cursor'] = 4;
						return $job;
					}
				);
			}
		);
		$this->assertSame( 2, (int) $rec['processed'], 'only keys 5 and 6 are left after an old cursor of 4' );
	}

	/** R1-2.3: an unsigned key carries a cursor above PHP_INT_MAX as text, never as a cut-down integer. */
	public function test_unsigned_big_keys_carry_a_text_cursor(): void {
		global $wpdb;
		$table = $this->table( 'opex_b1_unsigned', 'id BIGINT UNSIGNED NOT NULL PRIMARY KEY, name VARCHAR(20)' );
		foreach ( array( 0, 5, PHP_INT_MAX ) as $id ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO `{$table}` (id, name) VALUES (%s, %s)", (string) $id, 'r' ) );
		}
		$config = $this->config( array( 'source' => 'table:' . $table, 'fields' => array( 'col:id' ), 'batch_size' => 1 ) );
		$this->assertSame( array( '0', '5', (string) PHP_INT_MAX ), array_column( $this->all_rows( $config, 1 ), 'col:id' ) );

		$last = OPEX_Query::batch( $config, '5', 1 );
		$this->assertSame( PHP_INT_MAX, $last['cursor'], 'a key that fits keeps an integer cursor' );

		// A cursor past PHP_INT_MAX is bound as text and compared as a number.
		$past = OPEX_Query::batch( $config, '9223372036854775808', 5 );
		$this->assertSame( array(), $past['items'] );
		$this->assertTrue( $past['done'] );
		$this->assertSame( '9223372036854775808', $past['cursor'], 'the text cursor is kept as it was, not cut to PHP_INT_MAX' );

		$this->expectException( RuntimeException::class );
		OPEX_Query::batch( $config, '5 OR 1=1', 5 );
	}

	/** R1-2.4: empty and not_empty on roles test whether a user has any role. */
	public function test_role_emptiness_rules(): void {
		$with = wp_insert_user( array( 'user_login' => 'opexb1with', 'user_pass' => wp_generate_password(), 'user_email' => 'opexb1with@example.test', 'role' => 'subscriber' ) );
		$none = wp_insert_user( array( 'user_login' => 'opexb1none', 'user_pass' => wp_generate_password(), 'user_email' => 'opexb1none@example.test', 'role' => '' ) );
		$this->assertIsInt( $with );
		$this->assertIsInt( $none );
		$this->users[] = $with;
		$this->users[] = $none;
		( new WP_User( $none ) )->set_role( '' );

		$logins = function ( string $op, string $value = '' ): array {
			$only   = array( 'field' => 'core:user_login', 'op' => 'starts_with', 'value' => 'opexb1' );
			$config = $this->config( array( 'source' => 'users', 'fields' => array( 'core:user_login' ), 'filters' => array( 'rules' => array( $only, array( 'field' => 'computed:roles', 'op' => $op, 'value' => $value ) ) ) ) );
			$rows   = array_column( $this->all_rows( $config, 10 ), 'core:user_login' );
			sort( $rows );
			return $rows;
		};
		$this->assertSame( array( 'opexb1with' ), $logins( 'not_empty' ) );
		$this->assertSame( array( 'opexb1none' ), $logins( 'empty' ) );
		$this->assertSame( array( 'opexb1none' ), $logins( 'eq' ), 'eq with no value means no role' );
		$this->assertSame( array( 'opexb1with' ), $logins( 'neq' ), 'neq with no value means some role' );
		$this->assertSame( array( 'opexb1with' ), $logins( 'eq', 'subscriber' ), 'a named role still works' );
	}

	/** R1-2.7: a new custom column never takes the id of a later existing column. */
	public function test_new_custom_column_does_not_take_an_existing_id(): void {
		$notes = array();
		$clean = OPEX_Custom_Columns::sanitize(
			array(
				array( 'label' => 'Total', 'template' => '{core:ID}' ),
				array( 'id' => 'custom:total', 'label' => 'Sum', 'template' => '{core:post_title}' ),
				array( 'label' => 'Total', 'template' => '{core:ID}' ),
				array( 'id' => 'custom:total', 'label' => 'Again', 'template' => '{core:ID}' ),
			),
			'__return_true',
			$notes
		);
		$this->assertSame( array( 'custom:total-2', 'custom:total', 'custom:total-3', 'custom:again' ), array_column( $clean, 'id' ) );
		$this->assertSame( 'Sum', $clean[1]['label'], 'the existing column keeps its id' );
	}
}
