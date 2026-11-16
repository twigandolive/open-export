<?php
/**
 * Background runner against a real small export.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class RunnerTest extends TestCase {

	private array $ids = array();

	protected function tearDown(): void {
		foreach ( $this->ids as $id ) {
			OPEX_Jobs::delete( $id );
			wp_clear_scheduled_hook( 'opex_run_job', array( $id ) );
		}
		$this->ids = array();
	}

	private function make( string $mode = 'background' ): array {
		$config = OPEX_Exporter::sanitize_config(
			array( 'source' => 'posts:post', 'fields' => array( 'core:ID', 'core:post_title' ), 'batch_size' => 10, 'format' => 'csv' )
		);
		$this->assertIsArray( $config );
		$job = OPEX_Exporter::create( $config, 'Runner test', array( 'mode' => $mode ) );
		$this->assertIsArray( $job );
		$this->ids[] = $job['id'];
		// Today's create() ignores $extra, so set the mode here.
		$rec         = OPEX_Jobs::get( $job['id'] );
		$rec['mode'] = $mode;
		OPEX_Jobs::save( $rec );
		return $job;
	}

	private function csv_rows( string $id ): int {
		$fh   = fopen( OPEX_Jobs::file_path( OPEX_Jobs::get( $id ) ), 'rb' );
		$rows = -1; // Header.
		while ( false !== fgetcsv( $fh, 0, ',', '"', '\\' ) ) {
			$rows++;
		}
		fclose( $fh );
		return $rows;
	}

	public function test_work_runs_to_completion() {
		$job = $this->make();
		$out = OPEX_Runner::work( $job['id'], 60 );
		$this->assertIsArray( $out );
		$this->assertSame( 'done', $out['status'] );
		$this->assertGreaterThan( 10, $out['processed'] );
		$this->assertSame( $out['processed'], $this->csv_rows( $job['id'] ) );
		$this->assertGreaterThan( 0, (int) OPEX_Jobs::get( $job['id'] )['heartbeat'] );
	}

	public function test_paused_job_stays_paused() {
		$job = $this->make();
		OPEX_Exporter::pause( $job['id'] );
		$out = OPEX_Runner::work( $job['id'], 30 );
		$this->assertSame( 'paused', $out['status'] );
		$this->assertSame( 0, $out['processed'] );
		$this->assertSame( 'paused', OPEX_Jobs::get( $job['id'] )['status'] );
	}

	public function test_zero_budget_stops_early_but_makes_progress() {
		$job = $this->make();
		$out = OPEX_Runner::work( $job['id'], 0 );
		$this->assertSame( 'running', $out['status'] );
		$this->assertSame( 10, $out['processed'] );
	}

	public function test_one_second_budget_returns_without_error() {
		$job = $this->make();
		$out = OPEX_Runner::work( $job['id'], 1 );
		$this->assertIsArray( $out );
		$this->assertGreaterThanOrEqual( 10, $out['processed'] );
	}

	public function test_locked_job_stops_cleanly() {
		$job = $this->make();
		OPEX_Jobs::acquire_lock( $job['id'] );
		$out = OPEX_Runner::work( $job['id'], 5 );
		OPEX_Jobs::release_lock( $job['id'] );
		$this->assertIsArray( $out );
		$this->assertSame( 0, $out['processed'] );
	}

	public function test_missing_job() {
		$this->assertSame( 'opex_missing', OPEX_Runner::work( 'jnope', 1 )->get_error_code() );
	}

	public function test_dispatch_does_not_duplicate() {
		$job = $this->make();
		OPEX_Runner::dispatch( $job['id'] );
		$first = wp_next_scheduled( 'opex_run_job', array( $job['id'] ) );
		$this->assertNotFalse( $first );
		OPEX_Runner::dispatch( $job['id'] );
		$count = 0;
		foreach ( _get_cron_array() as $hooks ) {
			$count += isset( $hooks['opex_run_job'][ md5( serialize( array( $job['id'] ) ) ) ] ) ? 1 : 0;
		}
		$this->assertSame( 1, $count );
	}

	public function test_on_cron_only_works_background_jobs() {
		$browser = $this->make( 'browser' );
		OPEX_Runner::on_cron( $browser['id'] );
		$this->assertSame( 0, OPEX_Jobs::get( $browser['id'] )['processed'] );

		$bg = $this->make( 'background' );
		OPEX_Runner::on_cron( $bg['id'] );
		$this->assertSame( 'done', OPEX_Jobs::get( $bg['id'] )['status'] );
	}

	public function test_maintenance_restarts_stalled_background_job() {
		$job         = $this->make();
		$rec         = OPEX_Jobs::get( $job['id'] );
		$rec['heartbeat'] = time() - 3600;
		OPEX_Jobs::save( $rec );
		OPEX_Runner::maintenance();
		$this->assertNotFalse( wp_next_scheduled( 'opex_run_job', array( $job['id'] ) ) );
	}

	public function test_register_and_unregister() {
		OPEX_Runner::register();
		$this->assertNotFalse( wp_next_scheduled( 'opex_maintenance' ) );
		$this->assertNotFalse( has_action( 'opex_run_job', array( 'OPEX_Runner', 'on_cron' ) ) );
		wp_schedule_single_event( time() + 500, 'opex_deliver', array( 'jz' ) );
		OPEX_Runner::unregister();
		$this->assertFalse( wp_next_scheduled( 'opex_maintenance' ) );
		$this->assertFalse( wp_next_scheduled( 'opex_deliver', array( 'jz' ) ) );
	}
}
