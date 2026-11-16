<?php
/**
 * Codex round 2 engine fixes: R2-1.2 (a job whose lock file cannot be opened
 * says so once), R2-1.3 (a cron callback whose work comes back busy is tried
 * again, a bounded number of times) and R2-1.5 (a schedule's completion
 * bookkeeping that could not be saved is done later, exactly once).
 *
 * The store is made busy through its own seam, as CodexIntegrationBusyTest
 * does: another holder keeps the option's store lock, and the wait of
 * OPEX_Store::take() is shortened.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class CodexRound2EngineTest extends TestCase {

	/** @var array<string, string> Store locks held by "another process", by option. */
	private $held = array();

	/** @var string[] Folders made in place of lock files. */
	private $dirs = array();

	/** @var int opex_job_done calls seen. */
	private $done = 0;

	/** @var callable|null Listener to remove. */
	private $listener = null;

	protected function tearDown(): void {
		$this->free();
		if ( null !== $this->listener ) {
			remove_action( 'opex_job_done', $this->listener, 5 );
			$this->listener = null;
		}
		foreach ( OPEX_Jobs::all() as $job ) {
			if ( 0 === strpos( (string) $job['label'], 'Codex r2' ) ) {
				wp_clear_scheduled_hook( 'opex_run_job', array( (string) $job['id'] ) );
				delete_transient( 'opex_retry_' . md5( 'opex_run_job|' . $job['id'] ) );
				OPEX_Jobs::delete( (string) $job['id'] );
			}
		}
		foreach ( $this->dirs as $dir ) {
			if ( is_dir( $dir ) ) {
				rmdir( $dir );
			}
		}
		$this->dirs = array();
		foreach ( OPEX_Schedules::all() as $id => $s ) {
			if ( 0 === strpos( (string) $s['name'], 'Codex r2' ) ) {
				wp_clear_scheduled_hook( 'opex_run_schedule', array( (string) $id ) );
				delete_transient( 'opex_retry_' . md5( 'opex_run_schedule|' . $id ) );
				OPEX_Lock::force_release( 'sched_' . $id );
				OPEX_Schedules::delete( (string) $id );
			}
		}
	}

	/** Gives back every store lock the test holds and restores the wait. */
	private function free(): void {
		foreach ( $this->held as $option => $token ) {
			OPEX_Lock::release( 'store_' . $option, $token );
		}
		$this->held = array();
		$this->set_wait( 20 );
	}

	/**
	 * Sets the wait of OPEX_Store::take().
	 *
	 * @param float $seconds Seconds.
	 */
	private function set_wait( float $seconds ): void {
		$prop = new ReflectionProperty( OPEX_Store::class, 'wait' );
		if ( PHP_VERSION_ID < 80100 ) {
			$prop->setAccessible( true );
		}
		$prop->setValue( null, $seconds );
	}

	/**
	 * Makes every change of an option give up: another holder keeps its lock.
	 *
	 * @param string $option Option name.
	 */
	private function busy( string $option ): void {
		$token = OPEX_Lock::acquire( 'store_' . $option, 300 );
		$this->assertIsString( $token, 'The test could take the store lock.' );
		$this->held[ $option ] = $token;
		$this->set_wait( 0.1 );
	}

	/**
	 * A saved schedule.
	 *
	 * @param array $over Field overrides.
	 * @return array The record.
	 */
	private function schedule( array $over = array() ): array {
		$rec = OPEX_Schedules::save(
			array_merge(
				array(
					'name'        => 'Codex r2 schedule',
					'config'      => array(
						'source'     => 'posts:post',
						'fields'     => array( 'core:ID' ),
						'batch_size' => 50,
					),
					'frequency'   => 'manual',
					'incremental' => array(
						'enabled' => true,
						'field'   => 'core:post_date_gmt',
					),
				),
				$over
			)
		);
		$this->assertIsArray( $rec );
		return $rec;
	}

	/**
	 * A config.
	 *
	 * @param array $over Keys to change.
	 * @return array The sanitized config.
	 */
	private function config( array $over = array() ): array {
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
		return $config;
	}

	/**
	 * A scheduled job that has no rows, so it finishes inside create().
	 *
	 * @param array $rec Schedule record.
	 * @return array The job as stored.
	 */
	private function finished_run( array $rec ): array {
		$config = $this->config( array( 'filters' => array( 'rules' => array( array( 'field' => 'core:ID', 'op' => 'eq', 'value' => '0' ) ) ) ) );
		$job    = OPEX_Exporter::create(
			$config,
			'Codex r2 run',
			array(
				'mode'        => 'background',
				'schedule_id' => $rec['id'],
				'origin'      => 'cron',
				'generation'  => (string) OPEX_Schedules::get( $rec['id'] )['generation'],
			)
		);
		$this->assertIsArray( $job );
		$this->assertSame( 'done', $job['status'] );
		return OPEX_Jobs::get( $job['id'] );
	}

	/** Counts opex_job_done calls. */
	private function count_done(): void {
		$this->done     = 0;
		$this->listener = function (): void {
			++$this->done;
		};
		add_action( 'opex_job_done', $this->listener, 5 );
	}

	public function test_an_unavailable_file_lock_notes_the_job_once(): void {
		$job = OPEX_Exporter::create( $this->config(), 'Codex r2 no lock file' );
		$this->assertIsArray( $job );
		$job  = OPEX_Jobs::get( $job['id'] );
		$lock = OPEX_File_Lock::path( OPEX_Jobs::file_path( $job ) );
		// A folder where the lock file belongs cannot be opened as a file.
		$this->assertTrue( mkdir( $lock ) );
		$this->dirs[] = $lock;
		$this->assertSame( OPEX_File_Lock::UNAVAILABLE, OPEX_File_Lock::take( OPEX_Jobs::file_path( $job ) ) );

		OPEX_Exporter::run( $job['id'] );
		OPEX_Exporter::run( $job['id'] );

		$stored = OPEX_Jobs::get( $job['id'] );
		$this->assertGreaterThan( 5, (int) $stored['processed'], 'The export still runs.' );
		$notes = array_filter(
			(array) $stored['notes'],
			static function ( $note ): bool {
				return false !== strpos( (string) $note, 'cannot lock the export file' );
			}
		);
		$this->assertCount( 1, $notes );
	}

	public function test_a_busy_start_error_is_tried_again_then_shown(): void {
		$rec = $this->schedule( array( 'frequency' => 'daily', 'time' => '03:30', 'enabled' => true ) );
		wp_clear_scheduled_hook( 'opex_run_schedule', array( $rec['id'] ) );
		// Saving the new job gives up, so start() returns the busy WP_Error.
		$this->busy( 'opex_jobs' );
		$start = OPEX_Schedules::start( $rec['id'], 'cron' );
		$this->assertInstanceOf( WP_Error::class, $start );
		$this->assertSame( 'opex_busy', $start->get_error_code() );

		OPEX_Runner::on_schedule( $rec['id'] );
		$next = wp_next_scheduled( 'opex_run_schedule', array( $rec['id'] ) );
		$this->assertIsInt( $next, 'The schedule is tried again.' );
		$this->assertLessThanOrEqual( time() + 61, $next );

		// The store stays busy. After the last try the run is given up.
		for ( $i = 0; $i < 10; $i++ ) {
			OPEX_Runner::on_schedule( $rec['id'] );
		}
		$this->free();
		$s = OPEX_Schedules::get( $rec['id'] );
		$this->assertSame( 'error', $s['last_run']['status'], 'The schedule shows that its run failed.' );
		$this->assertSame( (int) $s['next_run'], wp_next_scheduled( 'opex_run_schedule', array( $rec['id'] ) ), 'Only the next regular run is queued.' );
		$this->assertGreaterThan( time() + 61, (int) $s['next_run'] );
		$this->assertSame( array(), array_filter( OPEX_Jobs::all(), static function ( $job ) use ( $rec ): bool {
			return $rec['id'] === (string) $job['schedule_id'];
		} ), 'No job was saved.' );
	}

	public function test_a_schedule_that_is_still_running_is_not_retried(): void {
		$rec   = $this->schedule();
		$token = OPEX_Lock::acquire( 'sched_' . $rec['id'], 60 );
		$this->assertIsString( $token );
		OPEX_Runner::on_schedule( $rec['id'] );
		OPEX_Lock::release( 'sched_' . $rec['id'], $token );
		$this->assertFalse( wp_next_scheduled( 'opex_run_schedule', array( $rec['id'] ) ), 'Another start owns this run.' );
	}

	public function test_a_background_batch_that_comes_back_busy_is_tried_again(): void {
		$job = OPEX_Exporter::create( $this->config(), 'Codex r2 busy batch', array( 'mode' => 'background' ) );
		$this->assertIsArray( $job );
		wp_clear_scheduled_hook( 'opex_run_job', array( $job['id'] ) );
		$this->busy( 'opex_jobs' );

		OPEX_Runner::on_cron( $job['id'] );

		$next = wp_next_scheduled( 'opex_run_job', array( $job['id'] ) );
		$this->assertIsInt( $next, 'The job runs again soon.' );
		$this->assertLessThanOrEqual( time() + 61, $next );
	}

	public function test_bookkeeping_that_could_not_be_saved_is_done_by_maintenance(): void {
		$rec = $this->schedule();
		$this->count_done();
		$this->busy( 'opex_schedules' );
		$job = $this->finished_run( $rec );
		$this->free();

		$this->assertSame( 'done', $job['status'], 'The job stays done.' );
		$this->assertTrue( (bool) $job['schedule_owed'], 'The job records that the bookkeeping is owed.' );
		$this->assertSame( '', (string) OPEX_Schedules::get( $rec['id'] )['watermark'] );

		OPEX_Runner::maintenance();

		$s = OPEX_Schedules::get( $rec['id'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', (int) $job['created'] ), (string) $s['watermark'] );
		$this->assertSame( $job['id'], $s['last_run']['job_id'] );
		$this->assertSame( 'done', $s['last_run']['status'] );
		$this->assertFalse( (bool) OPEX_Jobs::get( $job['id'] )['schedule_owed'] );
		$this->assertSame( 1, $this->done, 'opex_job_done fired once.' );
	}

	public function test_late_bookkeeping_never_moves_the_watermark_back(): void {
		$rec = $this->schedule();
		$this->busy( 'opex_schedules' );
		$job = $this->finished_run( $rec );
		$this->free();
		$this->assertTrue( (bool) $job['schedule_owed'] );

		// A later run finished meanwhile and moved the watermark on.
		$later = array(
			'id'          => 'jcodexr2later',
			'schedule_id' => $rec['id'],
			'status'      => 'done',
			'created'     => time() + 120,
			'processed'   => 3,
			'generation'  => (string) OPEX_Schedules::get( $rec['id'] )['generation'],
		);
		OPEX_Schedules::on_job_done( $later );

		OPEX_Runner::maintenance();

		$s = OPEX_Schedules::get( $rec['id'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $later['created'] ), (string) $s['watermark'] );
		$this->assertSame( 'jcodexr2later', $s['last_run']['job_id'], 'The newer run stays the last run.' );
		$this->assertFalse( (bool) OPEX_Jobs::get( $job['id'] )['schedule_owed'] );
	}

	public function test_the_next_start_does_the_owed_bookkeeping_first(): void {
		$rec = $this->schedule();
		$this->busy( 'opex_schedules' );
		$job = $this->finished_run( $rec );
		$this->free();
		$this->assertTrue( (bool) $job['schedule_owed'] );

		$next = OPEX_Schedules::start( $rec['id'], 'manual' );

		$this->assertIsArray( $next );
		$mark = gmdate( 'Y-m-d H:i:s', (int) $job['created'] );
		$this->assertSame( $mark, (string) ( OPEX_Jobs::get( $next['id'] )['since']['after'] ?? '' ), 'The new run starts after the owed watermark.' );
		// A new run with no rows finishes at once and may move the watermark on to its own start.
		$this->assertGreaterThanOrEqual( $mark, (string) OPEX_Schedules::get( $rec['id'] )['watermark'] );
		$this->assertFalse( (bool) OPEX_Jobs::get( $job['id'] )['schedule_owed'] );
	}
}
