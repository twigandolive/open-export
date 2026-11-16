<?php
/**
 * The job state machine: database errors, replayed batches, deletes during
 * a batch, the lock, pause and resume, old records, PHP errors and retention.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EngineJobsTest extends TestCase {

	/** @var string[] */
	private array $jobs = array();

	/** @var string */
	private string $table = '';

	protected function setUp(): void {
		wp_set_current_user( 1 );
	}

	protected function tearDown(): void {
		global $wpdb;
		foreach ( $this->jobs as $id ) {
			OPEX_Jobs::delete( $id );
		}
		$this->jobs = array();
		if ( '' !== $this->table ) {
			$wpdb->suppress_errors( true );
			$wpdb->query( "DROP TABLE IF EXISTS `{$this->table}`" );
			$wpdb->suppress_errors( false );
			OPEX_Catalog::reset_cache();
			$this->table = '';
		}
		delete_option( 'opex_settings' );
		remove_all_filters( 'post_link' );
	}

	/** Make a job over posts. */
	private function job( array $over = array(), string $label = 'Engine test' ): array {
		$config = OPEX_Exporter::sanitize_config( array_merge(
			array( 'source' => 'posts:post', 'fields' => array( 'core:ID', 'core:post_title' ), 'batch_size' => 20, 'format' => 'csv' ),
			$over
		) );
		$this->assertIsArray( $config, is_wp_error( $config ) ? $config->get_error_message() : '' );
		if ( 'xlsx' === ( $over['format'] ?? '' ) ) {
			$config['format'] = 'xlsx'; // sanitize_config already keeps xlsx. This line makes the format explicit for the test.
		}
		$job = OPEX_Exporter::create( $config, $label );
		$this->assertIsArray( $job, is_wp_error( $job ) ? $job->get_error_message() : '' );
		$this->jobs[] = $job['id'];
		return $job;
	}

	/** Run a job until it stops running. */
	private function finish( string $id ): array {
		for ( $i = 0; $i < 100; $i++ ) {
			$job = OPEX_Exporter::run( $id );
			$this->assertIsArray( $job );
			if ( 'running' !== $job['status'] ) {
				return $job;
			}
		}
		$this->fail( 'The job never finished.' );
	}

	/** Make a small raw table. */
	private function make_table( int $rows ): void {
		global $wpdb;
		$this->table = $wpdb->prefix . 'opex_engine_tmp';
		$wpdb->query( "DROP TABLE IF EXISTS `{$this->table}`" );
		$wpdb->query( "CREATE TABLE `{$this->table}` ( id INT NOT NULL PRIMARY KEY, name VARCHAR(40) )" );
		$this->fill_table( 1, $rows );
		OPEX_Catalog::reset_cache();
	}

	/** Add rows to the raw table. */
	private function fill_table( int $from, int $to ): void {
		global $wpdb;
		for ( $i = $from; $i <= $to; $i++ ) {
			$wpdb->insert( $this->table, array( 'id' => $i, 'name' => "row $i" ) );
		}
	}

	/** Contents of a job's file. */
	private function content( string $id ): string {
		return (string) file_get_contents( OPEX_Jobs::file_path( OPEX_Jobs::get( $id ) ) );
	}

	/** A database error mid-export fails the job and it resumes from its cursor. */
	public function test_database_error_fails_job_and_resumes(): void {
		global $wpdb;
		$this->make_table( 25 );
		$job = $this->job( array( 'source' => 'table:' . $this->table, 'fields' => array( 'col:id', 'col:name' ), 'batch_size' => 10 ) );
		$this->assertSame( 25, $job['total'] );
		$this->assertSame( 10, OPEX_Exporter::run( $job['id'] )['processed'] );

		$wpdb->suppress_errors( true );
		$wpdb->query( "DROP TABLE `{$this->table}`" );
		$failed = OPEX_Exporter::run( $job['id'] );
		$wpdb->suppress_errors( false );
		$this->assertSame( 'error', $failed['status'] );
		$this->assertSame( 10, $failed['processed'], 'the cursor stays where it was' );
		$this->assertStringNotContainsString( 'SELECT', $failed['error'], 'the browser sees no SQL' );
		$this->assertNotSame( '', $failed['error'] );

		$wpdb->query( "CREATE TABLE `{$this->table}` ( id INT NOT NULL PRIMARY KEY, name VARCHAR(40) )" );
		$this->fill_table( 1, 25 );
		$done = $this->finish( $job['id'] );
		$this->assertSame( 'done', $done['status'] );
		$this->assertSame( 25, $done['processed'] );
		$lines = array_filter( explode( "\n", trim( $this->content( $job['id'] ) ) ) );
		$this->assertCount( 26, $lines, 'header plus every row exactly once' );
	}

	/** A failing count stops job creation instead of making an empty export. */
	public function test_database_error_in_count_is_thrown(): void {
		global $wpdb;
		$this->make_table( 3 );
		$config = OPEX_Exporter::sanitize_config( array( 'source' => 'table:' . $this->table, 'fields' => array( 'col:id' ) ) );
		$wpdb->suppress_errors( true );
		$wpdb->query( "DROP TABLE `{$this->table}`" );
		try {
			OPEX_Query::count( $config );
			$this->fail( 'count() should throw' );
		} catch ( RuntimeException $e ) {
			$this->assertStringNotContainsString( 'no such table', strtolower( $e->getMessage() ) );
		}
		$created = OPEX_Exporter::create( $config, 'broken' );
		$wpdb->suppress_errors( false );
		$this->assertWPError( $created );
		$this->assertSame( 'opex_db', $created->get_error_code() );
	}

	/** An error from an unrelated earlier query does not trip the next one. */
	public function test_old_database_error_does_not_leak(): void {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$wpdb->query( 'SELECT * FROM opex_no_such_table_zz' );
		$wpdb->suppress_errors( false );
		$config = OPEX_Exporter::sanitize_config( array( 'source' => 'users', 'fields' => array( 'core:ID' ) ) );
		$this->assertGreaterThan( 0, OPEX_Query::count( $config ) );
		$this->assertNotEmpty( OPEX_Query::batch( $config, 0, 5 )['items'] );
	}

	/** Data providers for the writers that replay. */
	public static function formats(): array {
		return array( 'csv' => array( 'csv' ), 'json' => array( 'json' ), 'xml' => array( 'xml' ), 'xlsx' => array( 'xlsx' ) );
	}

	/** Normalizes a file so two exports of the same rows compare equal. */
	private function normalized( string $id ): string {
		$path = OPEX_Jobs::file_path( OPEX_Jobs::get( $id ) );
		if ( '.xlsx' !== substr( $path, -5 ) ) {
			return (string) file_get_contents( $path );
		}
		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( $path ) );
		$sheet = (string) $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$zip->close();
		return $sheet;
	}

	/**
	 * A batch that was written but never saved is cut off and written again,
	 * so no row is doubled.
	 *
	 * @param string $format Output format.
	 * @dataProvider formats
	 */
	#[DataProvider( 'formats' )]
	public function test_replayed_batch_writes_rows_once( string $format ): void {
		if ( 'xlsx' === $format && ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}
		$clean = $this->job( array( 'format' => $format ), 'clean' );
		$this->assertSame( 'done', $this->finish( $clean['id'] )['status'] );

		$crash = $this->job( array( 'format' => $format ), 'crash' );
		OPEX_Exporter::run( $crash['id'] );
		$rec = OPEX_Jobs::get( $crash['id'] );
		$this->assertNotEmpty( $rec['checkpoint'], 'the checkpoint is saved with the cursor' );

		// The process dies after the file write but before the job save.
		$batch  = OPEX_Query::batch( $rec['config'], (int) $rec['cursor'], (int) $rec['config']['batch_size'] );
		$writer = OPEX_Writer::make( $format, OPEX_Jobs::file_path( $rec ), $rec['headers'] );
		$writer->append( $batch['items'], false );

		$this->assertSame( 'done', $this->finish( $crash['id'] )['status'] );
		$this->assertSame( $this->normalized( $clean['id'] ), $this->normalized( $crash['id'] ) );
	}

	/** A JSON job replayed from its first batch keeps valid JSON. */
	public function test_replayed_first_json_batch_stays_valid(): void {
		$crash = $this->job( array( 'format' => 'json' ), 'json crash' );
		$rec   = OPEX_Jobs::get( $crash['id'] );
		$batch = OPEX_Query::batch( $rec['config'], 0, 20 );
		OPEX_Writer::make( 'json', OPEX_Jobs::file_path( $rec ), $rec['headers'] )->append( $batch['items'], true );
		$this->finish( $crash['id'] );
		$data = json_decode( $this->content( $crash['id'] ), true );
		$this->assertIsArray( $data );
		$this->assertCount( 60, $data );
	}

	/** Finish() runs inside the try block, so a failure ends in the error status and can be retried. */
	public function test_finish_failure_is_an_error_not_a_fatal(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}
		$job  = $this->job( array( 'format' => 'xlsx', 'batch_size' => 50 ), 'xlsx finish' );
		$path = OPEX_Jobs::file_path( OPEX_Jobs::get( $job['id'] ) );
		$this->assertSame( 'running', OPEX_Exporter::run( $job['id'] )['status'] );
		mkdir( $path . '.zip.tmp' ); // The zip step cannot use a folder as its work file.
		$failed = OPEX_Exporter::run( $job['id'] );
		rmdir( $path . '.zip.tmp' );
		$this->assertSame( 'error', $failed['status'] );
		$done = $this->finish( $job['id'] );
		$this->assertSame( 'done', $done['status'] );
		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( $path ) );
		$this->assertSame( 61, substr_count( (string) $zip->getFromName( 'xl/worksheets/sheet1.xml' ), '<row ' ) );
		$zip->close();
	}

	/** Deleting a job while a batch runs does not bring it back. */
	public function test_delete_during_run_stays_deleted(): void {
		$job  = $this->job( array( 'fields' => array( 'core:ID', 'computed:permalink' ), 'batch_size' => 10 ), 'deleted' );
		$path = OPEX_Jobs::file_path( OPEX_Jobs::get( $job['id'] ) );
		$id   = $job['id'];
		add_filter( 'post_link', static function ( $link ) use ( $id ) {
			OPEX_Jobs::delete( $id );
			return $link;
		} );
		$out = OPEX_Exporter::run( $id );
		remove_all_filters( 'post_link' );
		$this->assertWPError( $out );
		$this->assertSame( 'opex_missing', $out->get_error_code() );
		$this->assertNull( OPEX_Jobs::get( $id ) );
		$this->assertFileDoesNotExist( $path );
	}

	/** The lock is one atomic option row. */
	public function test_lock_is_an_atomic_option(): void {
		global $wpdb;
		$name  = 'jtestlock' . wp_rand( 1000, 9999 );
		$token = OPEX_Lock::acquire( $name, 60 );
		$this->assertIsString( $token );
		$this->assertFalse( OPEX_Lock::acquire( $name, 60 ), 'a second caller is refused' );
		$this->assertTrue( OPEX_Lock::is_held( $name ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", 'opex_lock_' . $name ) );
		$this->assertNotNull( $row );
		$this->assertContains( $row->autoload, array( 'no', 'off' ), 'the lock never autoloads' );
		$this->assertEqualsWithDelta( time() + 60, (int) $row->option_value, 3 );
		$this->assertStringEndsWith( ':' . $token, $row->option_value, 'the value carries the owner token' );
		OPEX_Lock::release( $name, $token );
		$this->assertFalse( OPEX_Lock::is_held( $name ) );
		$again = OPEX_Lock::acquire( $name, 60 );
		$this->assertIsString( $again );
		OPEX_Lock::release( $name, $again );
	}

	/** An expired lock is taken over, and refresh pushes the expiry out. */
	public function test_stale_lock_is_taken_over_and_refresh_extends(): void {
		global $wpdb;
		$name  = 'jtestlock' . wp_rand( 1000, 9999 );
		$first = OPEX_Lock::acquire( $name, 60 );
		$this->assertIsString( $first );
		$wpdb->update( $wpdb->options, array( 'option_value' => ( time() - 5 ) . ':' . $first ), array( 'option_name' => 'opex_lock_' . $name ) );
		$this->assertFalse( OPEX_Lock::is_held( $name ) );
		$second = OPEX_Lock::acquire( $name, 60 );
		$this->assertIsString( $second, 'a stale lock is taken over' );
		$this->assertFalse( OPEX_Lock::acquire( $name, 60 ) );
		$wpdb->update( $wpdb->options, array( 'option_value' => ( time() + 1 ) . ':' . $second ), array( 'option_name' => 'opex_lock_' . $name ) );
		$this->assertTrue( OPEX_Lock::refresh( $name, $second, 300 ) );
		$value = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'opex_lock_' . $name ) );
		$this->assertEqualsWithDelta( time() + 300, $value, 3 );
		OPEX_Lock::release( $name, $second );
	}

	/** Lock ownership: a holder that went stale cannot refresh or release the new holder's lock. */
	public function test_stale_holder_cannot_touch_the_new_holders_lock(): void {
		global $wpdb;
		$name  = 'jtestlock' . wp_rand( 1000, 9999 );
		$old   = OPEX_Lock::acquire( $name, 60 );
		$wpdb->update( $wpdb->options, array( 'option_value' => ( time() - 5 ) . ':' . $old ), array( 'option_name' => 'opex_lock_' . $name ) );
		$new = OPEX_Lock::acquire( $name, 60 );
		$this->assertIsString( $new );
		$this->assertNotSame( $old, $new );

		$this->assertFalse( OPEX_Lock::refresh( $name, $old, 600 ), 'the old holder cannot refresh' );
		OPEX_Lock::release( $name, $old );
		$this->assertTrue( OPEX_Lock::is_held( $name ), 'the old holder cannot release' );
		$this->assertTrue( OPEX_Lock::owns( $name, $new ) );
		$value = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'opex_lock_' . $name ) );
		$this->assertEqualsWithDelta( time() + 60, $value, 3, 'the expiry did not move' );
		$this->assertFalse( OPEX_Lock::refresh( $name, '', 60 ) );

		$this->assertTrue( OPEX_Lock::refresh( $name, $new, 120 ) );
		OPEX_Lock::release( $name, $new );
		$this->assertFalse( OPEX_Lock::is_held( $name ) );
	}

	/** Lock ownership: a run that lost its lock writes nothing and saves nothing. */
	public function test_run_that_lost_its_lock_writes_nothing(): void {
		global $wpdb;
		$job  = $this->job( array( 'batch_size' => 10 ), 'lost lock' );
		$id   = $job['id'];
		$path = OPEX_Jobs::file_path( OPEX_Jobs::get( $id ) );
		$size = filesize( $path );
		$before = OPEX_Jobs::get( $id );

		// While the batch query runs, another worker takes over the lock.
		$swap = static function ( $sql ) use ( $wpdb, $id ) {
			if ( false !== strpos( $sql, 'ORDER BY p.ID' ) ) {
				$wpdb->update( $wpdb->options, array( 'option_value' => ( time() + 90 ) . ':otherholder1234' ), array( 'option_name' => 'opex_lock_' . $id ) );
			}
			return $sql;
		};
		add_filter( 'query', $swap );
		$out = OPEX_Exporter::run( $id );
		remove_filter( 'query', $swap );

		$this->assertWPError( $out );
		$this->assertSame( 'opex_locked', $out->get_error_code() );
		clearstatcache( true, $path );
		$this->assertSame( $size, filesize( $path ), 'nothing was appended' );
		$after = OPEX_Jobs::get( $id );
		$this->assertSame( $before['cursor'], $after['cursor'] );
		$this->assertSame( $before['processed'], $after['processed'] );
		$this->assertSame( 'running', $after['status'] );
		$this->assertTrue( OPEX_Lock::owns( $id, 'otherholder1234' ), 'the new holder keeps its lock' );
		OPEX_Lock::force_release( $id );
	}

	/** A caller locked out does not change the job's status. */
	public function test_locked_out_caller_leaves_status_alone(): void {
		$job = $this->job();
		OPEX_Exporter::pause( $job['id'] );
		$this->assertTrue( OPEX_Jobs::acquire_lock( $job['id'] ) );
		$out = OPEX_Exporter::run( $job['id'], true );
		$this->assertWPError( $out );
		$this->assertSame( 'opex_locked', $out->get_error_code() );
		$this->assertSame( 'paused', OPEX_Jobs::get( $job['id'] )['status'] );

		$rec           = OPEX_Jobs::get( $job['id'] );
		$rec['status'] = 'error';
		$rec['error']  = 'boom';
		OPEX_Jobs::save( $rec );
		$this->assertWPError( OPEX_Exporter::run( $job['id'] ) );
		$stored = OPEX_Jobs::get( $job['id'] );
		$this->assertSame( 'error', $stored['status'] );
		$this->assertSame( 'boom', $stored['error'] );
		OPEX_Jobs::release_lock( $job['id'] );
	}

	/** The lock is refreshed while a batch assembles its rows. */
	public function test_lock_is_refreshed_during_a_batch(): void {
		$ticks  = 0;
		$config = OPEX_Exporter::sanitize_config( array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'batch_size' => 250 ) );
		OPEX_Query::batch( $config, 0, 250, static function () use ( &$ticks ) {
			++$ticks;
		} );
		$this->assertGreaterThanOrEqual( 1, $ticks );
	}

	/** Only an explicit resume continues a paused job, over REST too. */
	public function test_rest_run_resumes_only_with_the_flag(): void {
		$job = $this->job();
		OPEX_Exporter::pause( $job['id'] );
		$run = static function ( string $id, ?array $body ): WP_REST_Response {
			$req = new WP_REST_Request( 'POST', '/open-export/v1/jobs/' . $id . '/run' );
			if ( null !== $body ) {
				$req->set_header( 'content-type', 'application/json' );
				$req->set_body( wp_json_encode( $body ) );
			}
			return rest_do_request( $req );
		};
		$this->assertSame( 'paused', $run( $job['id'], null )->get_data()['status'] );
		$this->assertSame( 'paused', $run( $job['id'], array( 'resume' => false ) )->get_data()['status'] );
		$this->assertSame( 'paused', OPEX_Jobs::get( $job['id'] )['status'] );
		$out = $run( $job['id'], array( 'resume' => true ) )->get_data();
		$this->assertSame( 'running', $out['status'] );
		$this->assertGreaterThan( 0, $out['processed'] );
	}

	/** The background runner leaves a paused job alone. */
	public function test_runner_does_not_unpause(): void {
		$job = $this->job();
		OPEX_Exporter::pause( $job['id'] );
		$out = OPEX_Runner::work( $job['id'], 5 );
		$this->assertSame( 'paused', $out['status'] );
		$this->assertSame( 0, $out['processed'] );
	}

	/** An old record with only the shipped keys loads with every key and still runs. */
	public function test_old_job_record_gets_defaults_and_runs(): void {
		$job  = $this->job();
		$jobs = (array) get_option( 'opex_jobs' );
		$rec  = $jobs[ $job['id'] ];
		foreach ( array( 'checkpoint', 'notes' ) as $key ) {
			unset( $rec[ $key ] );
		}
		unset( $rec['config']['batch_size'], $rec['config']['csv_delimiter'], $rec['config']['column_labels'] );
		$jobs[ $job['id'] ] = $rec;
		update_option( 'opex_jobs', $jobs, false );

		$got = OPEX_Jobs::get( $job['id'] );
		foreach ( array( 'mode' => 'browser', 'schedule_id' => '', 'origin' => 'manual', 'heartbeat' => 0, 'finished' => 0, 'delivery_name' => '', 'deliveries' => array(), 'tokens' => array(), 'since' => array(), 'sites' => array(), 'site_index' => 0, 'notes' => array(), 'checkpoint' => array() ) as $key => $default ) {
			$this->assertArrayHasKey( $key, $got );
			$this->assertSame( $default, $got[ $key ], $key );
		}
		$this->assertSame( 500, $got['config']['batch_size'] );
		$this->assertSame( 'comma', $got['config']['csv_delimiter'] );
		$this->assertArrayHasKey( 'mode', OPEX_Jobs::all()[ $job['id'] ] );
		$this->assertSame( 'done', $this->finish( $job['id'] )['status'] );
		$rerun = OPEX_Exporter::rerun( $job['id'] );
		$this->assertIsArray( $rerun );
		$this->jobs[] = $rerun['id'];
	}

	/** An Error (not an Exception) in the query layer ends in the error status. */
	public function test_throwable_is_caught(): void {
		$job  = $this->job();
		$jobs = (array) get_option( 'opex_jobs' );
		$jobs[ $job['id'] ]['config']['fields'] = 'not-a-list'; // A TypeError in the query layer.
		update_option( 'opex_jobs', $jobs, false );
		$out = OPEX_Exporter::run( $job['id'] );
		$this->assertIsArray( $out );
		$this->assertSame( 'error', $out['status'] );
		$this->assertNotSame( '', $out['error'] );
		$this->assertFalse( OPEX_Lock::is_held( $job['id'] ), 'the lock is released' );
	}

	/** The sweep uses the retention setting, and the hourly maintenance run calls it. */
	public function test_maintenance_sweeps_by_retention_setting(): void {
		OPEX_Settings::save( array( 'retention_days' => 2 ) );
		$old = $this->job( array(), 'old' );
		$new = $this->job( array(), 'new' );
		$rec = OPEX_Jobs::get( $old['id'] );
		$rec['created'] = time() - 3 * DAY_IN_SECONDS;
		OPEX_Jobs::save( $rec );
		$rec = OPEX_Jobs::get( $new['id'] );
		$rec['created'] = time() - DAY_IN_SECONDS;
		OPEX_Jobs::save( $rec );
		$path = OPEX_Jobs::file_path( OPEX_Jobs::get( $old['id'] ) );

		OPEX_Runner::maintenance();
		$this->assertNull( OPEX_Jobs::get( $old['id'] ) );
		$this->assertFileDoesNotExist( $path );
		$this->assertNotNull( OPEX_Jobs::get( $new['id'] ) );
	}

	/**
	 * Asserts a value is a WP_Error.
	 *
	 * @param mixed $value Value.
	 */
	public static function assertWPError( $value ): void {
		self::assertInstanceOf( WP_Error::class, $value );
	}
}
