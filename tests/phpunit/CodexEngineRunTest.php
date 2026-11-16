<?php
/**
 * Batch runs: a file lock keeps a second worker out of a file the first one
 * still writes, a throwing opex_job_done listener cannot turn a finished job
 * into an error, and a failed existence query on a network site is an
 * error, not an empty site.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class CodexEngineRunTest extends TestCase {

	/** Job ids to remove. */
	private array $jobs = array();

	/** Open lock handles to give up. */
	private array $handles = array();

	/** Listener to remove. */
	private $listener = null;

	/** The query filter in use, if any. */
	private $filter = null;

	protected function tearDown(): void {
		foreach ( $this->handles as $handle ) {
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
		$this->handles = array();
		if ( null !== $this->listener ) {
			remove_action( 'opex_job_done', $this->listener );
			$this->listener = null;
		}
		if ( null !== $this->filter ) {
			remove_filter( 'query', $this->filter );
			$this->filter = null;
		}
		$this->set_off( false );
		foreach ( $this->jobs as $id ) {
			OPEX_Jobs::delete( $id );
		}
		$this->jobs = array();
	}

	/**
	 * Makes OPEX_File_Lock act as if the filesystem could not lock.
	 *
	 * @param bool $off True to turn locking off.
	 */
	private function set_off( bool $off ): void {
		$prop = new ReflectionProperty( OPEX_File_Lock::class, 'off' );
		if ( PHP_VERSION_ID < 80100 ) {
			$prop->setAccessible( true );
		}
		$prop->setValue( null, $off );
	}

	/**
	 * A post export that needs several batches.
	 *
	 * @param array $over Config keys to change.
	 * @return array The job as stored.
	 */
	private function make_job( array $over = array() ): array {
		$config = OPEX_Exporter::sanitize_config(
			array_merge(
				array(
					'source'     => 'posts:post',
					'fields'     => array( 'core:ID', 'core:post_title' ),
					'batch_size' => 5,
					'format'     => 'csv',
				),
				$over
			)
		);
		$this->assertIsArray( $config );
		$job = OPEX_Exporter::create( $config, 'Codex run test' );
		$this->assertIsArray( $job );
		$this->jobs[] = $job['id'];
		return OPEX_Jobs::get( $job['id'] );
	}

	/**
	 * Holds the file lock of a job the way another worker would.
	 *
	 * @param array $job Job record.
	 */
	private function hold_file_lock( array $job ): void {
		$handle = fopen( substr( OPEX_Jobs::file_path( $job ), 0, -5 ) . '.lock.opex', 'c' );
		$this->assertTrue( flock( $handle, LOCK_EX | LOCK_NB ) );
		$this->handles[] = $handle;
	}

	public function test_a_worker_never_writes_a_file_another_worker_holds() {
		$job  = $this->make_job();
		$this->assertSame( 'running', $job['status'] );
		$path = OPEX_Jobs::file_path( $job );
		$body = file_get_contents( $path );
		// The first worker's job lock ran out, but it is still writing the file.
		$this->hold_file_lock( $job );

		$out = OPEX_Exporter::run( $job['id'] );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'opex_locked', $out->get_error_code() );
		$stored = OPEX_Jobs::get( $job['id'] );
		$this->assertSame( 0, (int) $stored['processed'], 'The second worker must not run the batch.' );
		$this->assertSame( $body, file_get_contents( $path ), 'The file must be untouched.' );
		$this->assertFalse( OPEX_Lock::is_held( $job['id'] ), 'The job lock is given back.' );
	}

	public function test_the_lock_file_goes_when_the_job_finishes() {
		$job  = $this->make_job();
		$lock = substr( OPEX_Jobs::file_path( $job ), 0, -5 ) . '.lock.opex';
		$out  = OPEX_Exporter::run( $job['id'] );
		$this->assertSame( 'running', $out['status'] );
		$this->assertFileExists( $lock, 'A running job keeps its lock file between batches.' );

		$done = OPEX_Runner::work( $job['id'], 30 );
		$this->assertSame( 'done', $done['status'] );
		$this->assertFileDoesNotExist( $lock );
	}

	public function test_the_lock_file_goes_with_a_deleted_job() {
		$job  = $this->make_job();
		$lock = substr( OPEX_Jobs::file_path( $job ), 0, -5 ) . '.lock.opex';
		OPEX_Exporter::run( $job['id'] );
		$this->assertFileExists( $lock );
		OPEX_Jobs::delete( $job['id'] );
		$this->assertFileDoesNotExist( $lock );
	}

	public function test_a_zipped_job_leaves_no_lock_file() {
		if ( ! OPEX_Compress::available() ) {
			$this->markTestSkipped( 'No zip extension.' );
		}
		$job  = $this->make_job( array( 'compress' => 'zip' ) );
		$lock = substr( OPEX_Jobs::file_path( $job ), 0, -5 ) . '.lock.opex';
		$done = OPEX_Runner::work( $job['id'], 30 );
		$this->assertSame( 'done', $done['status'] );
		$this->assertFileDoesNotExist( $lock );
		$this->assertStringEndsWith( '.zip.opex', OPEX_Jobs::get( $job['id'] )['filename'] );
	}

	public function test_without_file_locks_the_job_runs_and_says_so_once() {
		$this->set_off( true );
		$job = $this->make_job();
		OPEX_Exporter::run( $job['id'] );
		OPEX_Exporter::run( $job['id'] );

		$stored = OPEX_Jobs::get( $job['id'] );
		$this->assertGreaterThan( 5, (int) $stored['processed'], 'The export still runs.' );
		$notes = array_filter(
			(array) $stored['notes'],
			static function ( $note ) {
				return false !== strpos( (string) $note, 'cannot lock the export file' );
			}
		);
		$this->assertCount( 1, $notes );
	}

	public function test_a_throwing_listener_leaves_the_job_done() {
		$job            = $this->make_job();
		$this->listener = static function () {
			throw new RuntimeException( 'listener failed' );
		};
		add_action( 'opex_job_done', $this->listener );

		$out = OPEX_Runner::work( $job['id'], 30 );

		$this->assertIsArray( $out );
		$stored = OPEX_Jobs::get( $job['id'] );
		$this->assertSame( 'done', $stored['status'] );
		$this->assertSame( '', $stored['error'] );
	}

	public function test_a_throwing_listener_leaves_a_job_done_at_create() {
		$this->listener = static function () {
			throw new RuntimeException( 'listener failed' );
		};
		add_action( 'opex_job_done', $this->listener );
		$config = OPEX_Exporter::sanitize_config(
			array(
				'source'  => 'posts:post',
				'fields'  => array( 'core:ID' ),
				'filters' => array(
					'relation' => 'AND',
					'rules'    => array(
						array(
							'field' => 'core:post_title',
							'op'    => 'eq',
							'value' => 'no post has this title ' . wp_rand(),
						),
					),
				),
			)
		);
		$this->assertIsArray( $config );

		$job = OPEX_Exporter::create( $config, 'Codex run empty' );

		$this->assertIsArray( $job );
		$this->jobs[] = $job['id'];
		$this->assertSame( 'done', $job['status'] );
	}

	public function test_a_failed_existence_query_is_an_error_not_an_empty_site() {
		global $wpdb;
		$posts        = $wpdb->posts;
		$this->filter = static function ( $sql ) use ( $posts ) {
			if ( 0 === strpos( ltrim( (string) $sql ), "SELECT 1 FROM {$posts} WHERE post_type" ) ) {
				return str_replace( "FROM {$posts} ", "FROM {$posts}_missing_table ", (string) $sql );
			}
			return $sql;
		};
		add_filter( 'query', $this->filter );
		$quiet = $wpdb->suppress_errors( true );
		$error = null;
		try {
			OPEX_Network::source_state( 'posts:post' );
		} catch ( Throwable $e ) {
			$error = $e;
		}
		$wpdb->suppress_errors( $quiet );

		$this->assertInstanceOf( RuntimeException::class, $error, 'A broken table must not read as an empty site.' );
	}
}
