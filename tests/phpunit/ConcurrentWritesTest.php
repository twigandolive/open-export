<?php
/**
 * Another process writes the jobs, schedules or destinations option while
 * this one is busy. The tests write the option row straight into the
 * database with $wpdb, the way a second PHP process would, so this
 * process's cached copy is out of date. Nothing another process saved may
 * be undone by this process's next save.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class ConcurrentWritesTest extends TestCase {

	/** Job ids to remove. */
	private array $jobs = array();

	/** Schedule ids to remove. */
	private array $schedules = array();

	/** Destination ids to remove. */
	private array $destinations = array();

	/** The query filter in use, if any. */
	private $filter = null;

	protected function tearDown(): void {
		if ( null !== $this->filter ) {
			remove_filter( 'query', $this->filter );
			$this->filter = null;
		}
		foreach ( $this->jobs as $id ) {
			OPEX_Jobs::delete( $id );
		}
		foreach ( $this->schedules as $id ) {
			OPEX_Schedules::delete( $id );
		}
		foreach ( $this->destinations as $id ) {
			OPEX_Destinations::delete( $id );
		}
		$this->jobs         = array();
		$this->schedules    = array();
		$this->destinations = array();
	}

	/** A small post export that needs several batches. */
	private function make_job(): array {
		$config = OPEX_Exporter::sanitize_config(
			array(
				'source'     => 'posts:post',
				'fields'     => array( 'core:ID', 'core:post_title' ),
				'batch_size' => 5,
				'format'     => 'csv',
			)
		);
		$this->assertIsArray( $config );
		$job = OPEX_Exporter::create( $config, 'Concurrent test' );
		$this->assertIsArray( $job );
		$this->assertSame( 'running', $job['status'] );
		$this->jobs[] = $job['id'];
		return $job;
	}

	/**
	 * Changes an option row the way another process would: straight in the
	 * database, leaving this process's cache alone.
	 *
	 * @param string   $option Option name.
	 * @param callable $edit   Receives the stored value and returns the new one.
	 */
	private function write_elsewhere( string $option, callable $edit ): void {
		global $wpdb;
		$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		$value = $edit( maybe_unserialize( (string) $raw ) );
		$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $value ) ), array( 'option_name' => $option ) );
	}

	/**
	 * Runs $fn once, in the middle of the next batch: when the batch reads its rows.
	 *
	 * @param callable $fn What the other process does.
	 */
	private function during_next_batch( callable $fn ): void {
		global $wpdb;
		$fired        = false;
		$posts        = $wpdb->posts;
		$this->filter = static function ( $sql ) use ( &$fired, $fn, $posts ) {
			if ( ! $fired && 0 === stripos( ltrim( (string) $sql ), 'SELECT' ) && false !== strpos( (string) $sql, $posts ) && false !== stripos( (string) $sql, 'LIMIT' ) ) {
				$fired = true;
				$fn();
			}
			return $sql;
		};
		add_filter( 'query', $this->filter );
	}

	/** Prime this process's cache, as a long request has by the time it saves. */
	private function prime(): void {
		OPEX_Jobs::all();
		get_option( 'opex_jobs' );
	}

	public function test_a_pause_from_another_process_survives_the_batch() {
		$job = $this->make_job();
		$this->prime();
		$this->during_next_batch(
			function () use ( $job ) {
				$this->write_elsewhere(
					'opex_jobs',
					static function ( $jobs ) use ( $job ) {
						$jobs[ $job['id'] ]['status'] = 'paused';
						return $jobs;
					}
				);
			}
		);

		$out = OPEX_Exporter::run( $job['id'] );

		$this->assertIsArray( $out );
		$this->assertSame( 'paused', OPEX_Jobs::get( $job['id'] )['status'], 'The pause must survive the batch save.' );
		$this->assertSame( 'paused', OPEX_Runner::work( $job['id'], 5 )['status'], 'The runner must not run a paused job.' );
	}

	public function test_a_delete_from_another_process_stays_deleted() {
		$job  = $this->make_job();
		$path = OPEX_Jobs::file_path( OPEX_Jobs::get( $job['id'] ) );
		$this->assertFileExists( $path );
		$this->prime();
		$this->during_next_batch(
			function () use ( $job ) {
				$this->write_elsewhere(
					'opex_jobs',
					static function ( $jobs ) use ( $job ) {
						unset( $jobs[ $job['id'] ] );
						return $jobs;
					}
				);
			}
		);

		$out = OPEX_Exporter::run( $job['id'] );

		$this->assertWPErrorCode( 'opex_missing', $out );
		$this->assertNull( OPEX_Jobs::get( $job['id'] ), 'A deleted job must not come back.' );
		$this->assertFileDoesNotExist( $path );
	}

	public function test_another_jobs_done_status_survives() {
		$mine  = $this->make_job();
		$other = $this->make_job();
		$this->prime();
		$this->during_next_batch(
			function () use ( $other ) {
				$this->write_elsewhere(
					'opex_jobs',
					static function ( $jobs ) use ( $other ) {
						$jobs[ $other['id'] ]['status']    = 'done';
						$jobs[ $other['id'] ]['processed'] = 99;
						return $jobs;
					}
				);
			}
		);

		OPEX_Exporter::run( $mine['id'] );

		$stored = OPEX_Jobs::get( $other['id'] );
		$this->assertSame( 'done', $stored['status'], 'Another job finished meanwhile: its done status must stay.' );
		$this->assertSame( 99, (int) $stored['processed'] );
		$this->assertGreaterThan( 0, (int) OPEX_Jobs::get( $mine['id'] )['processed'], 'This job still saved its own progress.' );
	}

	public function test_a_schedule_edit_survives_a_run_finishing() {
		$saved = OPEX_Schedules::save(
			array(
				'name'   => 'Concurrent schedule',
				'config' => array(
					'source' => 'posts:post',
					'fields' => array( 'core:ID' ),
				),
			)
		);
		$this->assertIsArray( $saved );
		$this->schedules[] = $saved['id'];
		OPEX_Schedules::all();

		$this->write_elsewhere(
			'opex_schedules',
			static function ( $all ) use ( $saved ) {
				$all[ $saved['id'] ]['name'] = 'Renamed elsewhere';
				return $all;
			}
		);
		OPEX_Schedules::on_job_done(
			array(
				'id'          => 'jconcurrent01',
				'schedule_id' => $saved['id'],
				'status'      => 'done',
				'created'     => time(),
				'processed'   => 3,
			)
		);

		$stored = OPEX_Schedules::get( $saved['id'] );
		$this->assertSame( 'Renamed elsewhere', $stored['name'] );
		$this->assertSame( 'jconcurrent01', $stored['last_run']['job_id'] );
	}

	public function test_a_destination_edit_survives_a_test_result_and_a_pinned_key() {
		$saved = OPEX_Destinations::save(
			array(
				'type'     => 'sftp',
				'host'     => 'sftp.example.test',
				'username' => 'someone',
				'secret'   => 'a password',
			)
		);
		$this->assertIsArray( $saved );
		$this->destinations[] = $saved['id'];
		OPEX_Destinations::all();

		$this->write_elsewhere(
			'opex_destinations',
			static function ( $list ) use ( $saved ) {
				$list[ $saved['id'] ]['name'] = 'Renamed elsewhere';
				return $list;
			}
		);
		OPEX_Destinations::record_test( $saved['id'], true, 'Connected.' );
		OPEX_Destinations::pin_host_key( $saved['id'], 'SHA256:' . str_repeat( 'A', 43 ) );

		$stored = OPEX_Destinations::get( $saved['id'] );
		$this->assertSame( 'Renamed elsewhere', $stored['name'] );
		$this->assertTrue( $stored['last_test']['ok'] );
		$this->assertSame( 'SHA256:' . str_repeat( 'A', 43 ), $stored['host_key'] );
	}

	public function test_a_nested_change_does_not_wait_on_its_own_lock() {
		$started = microtime( true );
		$result  = OPEX_Store::change(
			'opex_concurrent_test',
			static function ( array &$outer ) {
				return OPEX_Store::change(
					'opex_concurrent_test',
					static function ( array &$inner ) {
						$inner['b'] = 2;
						return 'inner';
					}
				);
			}
		);
		$elapsed = microtime( true ) - $started;
		$stored  = get_option( 'opex_concurrent_test' );
		delete_option( 'opex_concurrent_test' );

		$this->assertSame( 'inner', $result );
		$this->assertLessThan( 5, $elapsed, 'A nested change must reuse the lock it already holds.' );
		$this->assertSame( 2, $stored['b'] ?? null );
		$this->assertFalse( OPEX_Lock::is_held( 'store_opex_concurrent_test' ) );
	}

	/**
	 * Asserts a WP_Error with a code.
	 *
	 * @param string $code  Expected code.
	 * @param mixed  $value Value to check.
	 */
	private function assertWPErrorCode( string $code, $value ): void {
		$this->assertInstanceOf( WP_Error::class, $value );
		$this->assertSame( $code, $value->get_error_code() );
	}
}
