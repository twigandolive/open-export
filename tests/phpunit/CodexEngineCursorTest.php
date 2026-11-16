<?php
/**
 * R1-2.3b: the batch run passes a raw table's cursor through as stored. A
 * cursor may be digit text (a key that is zero, negative or above
 * PHP_INT_MAX), and it must survive a save and a reload unchanged. A job
 * saved by the older code with an integer cursor resumes as before.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class CodexEngineCursorTest extends TestCase {

	/** Tables to drop. */
	private array $tables = array();

	/** Job ids to remove. */
	private array $jobs = array();

	protected function tearDown(): void {
		global $wpdb;
		foreach ( $this->jobs as $id ) {
			OPEX_Jobs::delete( $id );
		}
		foreach ( $this->tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB
		}
		$this->jobs   = array();
		$this->tables = array();
		OPEX_Catalog::reset_cache();
	}

	/**
	 * Creates a raw table with these keys.
	 *
	 * @param string   $name Table name without the prefix.
	 * @param string   $type Column type of the key.
	 * @param string[] $keys Keys as digit text.
	 * @return string The full table name.
	 */
	private function table( string $name, string $type, array $keys ): string {
		global $wpdb;
		$full = $wpdb->prefix . $name;
		$wpdb->query( "DROP TABLE IF EXISTS `{$full}`" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "CREATE TABLE `{$full}` ( id {$type} NOT NULL PRIMARY KEY, name VARCHAR(20) )" ); // phpcs:ignore WordPress.DB
		foreach ( $keys as $key ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO `{$full}` (id, name) VALUES (%s, %s)", $key, 'r' . $key ) ); // phpcs:ignore WordPress.DB
		}
		$this->tables[] = $full;
		OPEX_Catalog::reset_cache();
		return $full;
	}

	/**
	 * Starts a JSON job on a raw table.
	 *
	 * @param string $table Full table name.
	 * @param int    $size  Batch size.
	 * @return array The stored job.
	 */
	private function start( string $table, int $size ): array {
		$config = OPEX_Exporter::sanitize_config(
			array(
				'source'     => 'table:' . $table,
				'fields'     => array( 'col:id' ),
				'batch_size' => $size,
				'format'     => 'json',
			)
		);
		$this->assertIsArray( $config, is_wp_error( $config ) ? $config->get_error_message() : '' );
		$job = OPEX_Exporter::create( $config, 'Codex cursor' );
		$this->assertIsArray( $job );
		$this->jobs[] = $job['id'];
		return OPEX_Jobs::get( $job['id'] );
	}

	/**
	 * Runs a job batch by batch to the end and returns the exported ids.
	 *
	 * @param string $id Job id.
	 * @return string[] The ids in the file.
	 */
	private function finish( string $id ): array {
		for ( $i = 0; $i < 30; $i++ ) {
			$out = OPEX_Exporter::run( $id );
			$this->assertIsArray( $out );
			if ( 'running' !== $out['status'] ) {
				break;
			}
		}
		$job = OPEX_Jobs::get( $id );
		$this->assertSame( 'done', $job['status'], (string) $job['error'] );
		$rows = json_decode( (string) file_get_contents( OPEX_Jobs::file_path( $job ) ), true );
		return array_map( 'strval', array_column( (array) $rows, 'id' ) );
	}

	public function test_signed_keys_export_every_row_once_across_batches() {
		$keys  = array_map( 'strval', range( -9, 10 ) );
		$table = $this->table( 'opex_codex_cur_signed', 'INT', array_reverse( $keys ) );
		$job   = $this->start( $table, 10 );

		// The first batch of ten ends on key 0, which must not read as a fresh start.
		OPEX_Exporter::run( $job['id'] );
		$stored = OPEX_Jobs::get( $job['id'] );
		$this->assertSame( '0', $stored['cursor'], 'A text cursor survives the save and the reload unchanged.' );

		$this->assertSame( $keys, $this->finish( $job['id'] ) );
	}

	public function test_an_old_integer_cursor_resumes_after_that_key() {
		$table = $this->table( 'opex_codex_cur_old', 'INT', array_map( 'strval', range( 1, 25 ) ) );
		$job   = $this->start( $table, 10 );
		OPEX_Jobs::update(
			$job['id'],
			static function ( array $stored ): array {
				$stored['cursor'] = 4;
				return $stored;
			}
		);
		$this->assertSame( 4, OPEX_Jobs::get( $job['id'] )['cursor'] );
		$this->assertSame( array_map( 'strval', range( 5, 25 ) ), $this->finish( $job['id'] ) );
	}

	public function test_unsigned_keys_above_php_int_max_export_every_row_once() {
		global $wpdb;
		$big   = '9223372036854775808';
		$top   = '18446744073709551615';
		$keys  = array_merge( array_map( 'strval', range( 0, 7 ) ), array( (string) PHP_INT_MAX, $big, $top ) );
		$table = $this->table( 'opex_codex_cur_big', 'BIGINT UNSIGNED', $keys );
		$back  = $wpdb->get_col( "SELECT CAST(id AS CHAR) FROM `{$table}` ORDER BY id" ); // phpcs:ignore WordPress.DB
		if ( $keys !== array_map( 'strval', (array) $back ) ) {
			$this->markTestSkipped( 'This database cannot hold an unsigned key above PHP_INT_MAX exactly (SQLite keeps 64-bit signed integers).' );
		}
		$job = $this->start( $table, 10 );
		OPEX_Exporter::run( $job['id'] );
		$stored = OPEX_Jobs::get( $job['id'] );
		$this->assertSame( $big, $stored['cursor'], 'A key above PHP_INT_MAX is kept as text.' );
		$this->assertSame( $keys, $this->finish( $job['id'] ) );
	}
}
