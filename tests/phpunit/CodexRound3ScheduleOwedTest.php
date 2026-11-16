<?php
/**
 * Codex R3-1.2 (and SO-1): a scheduled job is saved as done with the mark
 * schedule_owed in the same write, on every completion path, and the mark
 * is cleared only after the schedule's bookkeeping was saved. So even when
 * the schedule save fails and every later job save fails too (a write
 * outage), the bookkeeping is done later by maintenance, exactly once.
 *
 * The outage is made with the store's own seam, as CodexRound2EngineTest
 * does: another holder keeps an option's store lock and the wait of
 * OPEX_Store::take() is shortened. A listener on opex_job_done that runs
 * before the schedules listener starts the outage, after the done status
 * was saved.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class CodexRound3ScheduleOwedTest extends TestCase {

	/** @var array<string, string> Store locks held by "another process", by option. */
	private $held = array();

	/** @var array<int, array{0: string, 1: callable}> Listeners to remove, by hook. */
	private $listeners = array();

	/** @var int opex_job_done calls seen. */
	private $done = 0;

	protected function tearDown(): void {
		$this->free();
		foreach ( $this->listeners as $pair ) {
			remove_action( $pair[0], $pair[1], 5 );
		}
		$this->listeners = array();
		foreach ( OPEX_Jobs::all() as $job ) {
			if ( 0 === strpos( (string) $job['label'], 'Codex r3' ) ) {
				wp_clear_scheduled_hook( 'opex_run_job', array( (string) $job['id'] ) );
				wp_clear_scheduled_hook( 'opex_deliver', array( (string) $job['id'] ) );
				OPEX_Jobs::delete( (string) $job['id'] );
			}
		}
		foreach ( OPEX_Schedules::all() as $id => $s ) {
			if ( 0 === strpos( (string) $s['name'], 'Codex r3' ) ) {
				wp_clear_scheduled_hook( 'opex_run_schedule', array( (string) $id ) );
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
	 * Adds an opex_job_done listener that runs before the schedules listener.
	 *
	 * @param callable $fn Listener.
	 */
	private function before_schedules( callable $fn ): void {
		add_action( 'opex_job_done', $fn, 5 );
		$this->listeners[] = array( 'opex_job_done', $fn );
	}

	/**
	 * Starts a write outage the moment a job is announced as done: the
	 * schedule save and every later job save give up. Counts the calls.
	 *
	 * @return void
	 */
	private function outage_on_done(): void {
		$this->done = 0;
		$this->before_schedules(
			function (): void {
				++$this->done;
				if ( ! $this->held ) {
					$this->busy( 'opex_schedules' );
					$this->busy( 'opex_jobs' );
				}
			}
		);
	}

	/**
	 * A saved incremental schedule.
	 *
	 * @param bool $network True for a network schedule.
	 * @return array The record.
	 */
	private function schedule( bool $network = false ): array {
		$rec = OPEX_Schedules::save(
			array(
				'name'        => 'Codex r3 schedule',
				'config'      => array(
					'source'     => 'posts:post',
					'fields'     => array( 'core:ID' ),
					'batch_size' => 50,
					'network'    => $network,
				),
				'frequency'   => 'manual',
				'incremental' => array(
					'enabled' => true,
					'field'   => 'core:post_date_gmt',
				),
			)
		);
		$this->assertIsArray( $rec );
		return $rec;
	}

	/**
	 * Creates a scheduled job.
	 *
	 * @param array $rec     Schedule record.
	 * @param bool  $empty   True for a job with no rows, which finishes inside create().
	 * @param bool  $network True for a network job.
	 * @return array The exposed job.
	 */
	private function scheduled_job( array $rec, bool $empty, bool $network = false ): array {
		$raw = array(
			'source'     => 'posts:post',
			'fields'     => array( 'core:ID', 'core:post_title' ),
			'batch_size' => 5,
			'format'     => 'csv',
			'network'    => $network,
		);
		if ( $empty ) {
			$raw['filters'] = array( 'rules' => array( array( 'field' => 'core:ID', 'op' => 'eq', 'value' => '0' ) ) );
		}
		$config = OPEX_Exporter::sanitize_config( $raw );
		$this->assertIsArray( $config );
		$job = OPEX_Exporter::create(
			$config,
			'Codex r3 run',
			array(
				'mode'        => 'background',
				'schedule_id' => $rec['id'],
				'origin'      => 'cron',
				'generation'  => (string) OPEX_Schedules::get( $rec['id'] )['generation'],
			)
		);
		$this->assertIsArray( $job );
		wp_clear_scheduled_hook( 'opex_run_job', array( (string) $job['id'] ) );
		return $job;
	}

	/**
	 * Runs a job to the end, the way `wp opex run` does (OPEX_Runner::work)
	 * or one batch per call the way the screen and cron do (OPEX_Exporter::run).
	 * In an outage only the second works: work() saves after the batch too.
	 *
	 * @param string $id  Job id.
	 * @param bool   $cli True for the WP-CLI path.
	 * @return array The exposed job.
	 */
	private function work_to_end( string $id, bool $cli ): array {
		for ( $i = 0; $i < 200; $i++ ) {
			$job = $cli ? OPEX_Runner::work( $id, 5 ) : OPEX_Exporter::run( $id );
			$this->assertIsArray( $job );
			if ( 'running' !== $job['status'] ) {
				return $job;
			}
		}
		$this->fail( 'The job did not finish.' );
	}

	/**
	 * Checks that maintenance does the owed bookkeeping once, then nothing more.
	 *
	 * @param array $rec Schedule record.
	 * @param array $job Job as stored.
	 * @return void
	 */
	private function assert_settled_by_maintenance( array $rec, array $job ): void {
		$this->assertSame( 'done', $job['status'], 'The job stays done.' );
		$this->assertTrue( (bool) $job['schedule_owed'], 'The debt was saved with the done status.' );
		$this->assertSame( '', (string) OPEX_Schedules::get( $rec['id'] )['watermark'], 'The bookkeeping really failed.' );

		OPEX_Runner::maintenance();

		$s = OPEX_Schedules::get( $rec['id'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', (int) $job['created'] ), (string) $s['watermark'] );
		$this->assertSame( $job['id'], $s['last_run']['job_id'] );
		$this->assertSame( 'done', $s['last_run']['status'] );
		$this->assertFalse( (bool) OPEX_Jobs::get( $job['id'] )['schedule_owed'] );
		$this->assertSame( 1, $this->done, 'opex_job_done fired once.' );
	}

	public function test_an_outage_after_a_zero_row_finish_still_settles_later(): void {
		$rec = $this->schedule();
		$this->outage_on_done();
		$job = $this->scheduled_job( $rec, true );
		$this->free();
		$this->assert_settled_by_maintenance( $rec, OPEX_Jobs::get( $job['id'] ) );
	}

	public function test_an_outage_after_a_normal_finish_still_settles_later(): void {
		$rec = $this->schedule();
		$job = $this->scheduled_job( $rec, false );
		$this->assertSame( 'running', $job['status'] );
		$this->outage_on_done();
		$this->work_to_end( $job['id'], false );
		$this->free();
		$this->assert_settled_by_maintenance( $rec, OPEX_Jobs::get( $job['id'] ) );
	}

	public function test_an_outage_after_a_network_finish_still_settles_later(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network exports need the multisite test site.' );
		}
		$rec = $this->schedule( true );
		$job = $this->scheduled_job( $rec, false, true );
		$this->assertSame( 'running', $job['status'] );
		$this->assertGreaterThan( 1, (int) $job['sites'], 'A network job.' );
		$this->outage_on_done();
		$this->work_to_end( $job['id'], false );
		$this->free();
		$this->assert_settled_by_maintenance( $rec, OPEX_Jobs::get( $job['id'] ) );
	}

	public function test_the_mark_is_saved_with_done_and_cleared_after_the_bookkeeping(): void {
		$rec  = $this->schedule();
		$job  = $this->scheduled_job( $rec, false );
		$seen = array();
		$this->before_schedules(
			static function ( $done ) use ( &$seen ): void {
				$seen[] = array(
					'passed' => (bool) $done['schedule_owed'],
					'stored' => (bool) OPEX_Jobs::get( (string) $done['id'] )['schedule_owed'],
				);
			}
		);

		$end = $this->work_to_end( $job['id'], true );

		$this->assertSame( 'done', $end['status'] );
		$this->assertSame(
			array(
				array(
					'passed' => true,
					'stored' => true,
				),
			),
			$seen,
			'The job is announced once, already saved as owing.'
		);
		$this->assertFalse( (bool) OPEX_Jobs::get( $job['id'] )['schedule_owed'], 'Cleared after the bookkeeping.' );
		$stored = OPEX_Jobs::get( $job['id'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', (int) $stored['created'] ), (string) OPEX_Schedules::get( $rec['id'] )['watermark'] );
	}

	public function test_a_job_without_a_schedule_never_owes(): void {
		$config = OPEX_Exporter::sanitize_config(
			array(
				'source'  => 'posts:post',
				'fields'  => array( 'core:ID' ),
				'format'  => 'csv',
				'filters' => array( 'rules' => array( array( 'field' => 'core:ID', 'op' => 'eq', 'value' => '0' ) ) ),
			)
		);
		$job = OPEX_Exporter::create( $config, 'Codex r3 plain' );
		$this->assertIsArray( $job );
		$this->assertSame( 'done', $job['status'] );
		$this->assertFalse( (bool) OPEX_Jobs::get( $job['id'] )['schedule_owed'] );
	}

	public function test_an_old_record_without_the_key_is_not_owed(): void {
		$rec = $this->schedule();
		$job = $this->scheduled_job( $rec, true );
		// Remove the key, as a record saved before it existed lacks it, and reset the schedule.
		OPEX_Store::change(
			'opex_jobs',
			static function ( array &$jobs ) use ( $job ): void {
				unset( $jobs[ $job['id'] ]['schedule_owed'] );
			}
		);
		$this->assertArrayNotHasKey( 'schedule_owed', OPEX_Store::read( 'opex_jobs' )[ $job['id'] ] );
		$this->assertFalse( (bool) OPEX_Jobs::get( $job['id'] )['schedule_owed'] );
		$before = OPEX_Schedules::get( $rec['id'] );

		OPEX_Schedule_Owed::settle( $rec['id'] );

		$this->assertSame( $before, OPEX_Schedules::get( $rec['id'] ), 'Nothing is redone for it.' );
		$this->assertArrayNotHasKey( 'schedule_owed', OPEX_Store::read( 'opex_jobs' )[ $job['id'] ], 'The record is not rewritten.' );
	}
}
