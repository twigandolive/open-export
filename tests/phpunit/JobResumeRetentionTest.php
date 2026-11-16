<?php
/**
 * Two job lifecycle fixes: resuming a background job hands it back to the
 * runner, and the retention sweep counts days from when a job finished.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Resume dispatch and retention age.
 */
class JobResumeRetentionTest extends TestCase {

	/** @var string[] */
	private $jobs = array();

	/** @var mixed */
	private $settings_before;

	protected function setUp(): void {
		$this->settings_before = get_option( 'opex_settings', null );
		if ( false === has_action( 'rest_api_init', array( 'OPEX_Rest', 'register_routes' ) ) ) {
			add_action( 'rest_api_init', array( 'OPEX_Rest', 'register_routes' ) );
		}
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
		wp_set_current_user( 1 );
	}

	protected function tearDown(): void {
		foreach ( $this->jobs as $id ) {
			wp_clear_scheduled_hook( 'opex_run_job', array( $id ) );
			OPEX_Jobs::delete( $id );
		}
		if ( null === $this->settings_before ) {
			delete_option( 'opex_settings' );
		} else {
			update_option( 'opex_settings', $this->settings_before, false );
		}
		$GLOBALS['wp_rest_server'] = null;
	}

	/**
	 * A job over posts with small batches, so it stays running after one batch.
	 *
	 * @param string $mode browser or background.
	 * @return array Exposed job.
	 */
	private function job( string $mode ): array {
		$config = OPEX_Exporter::sanitize_config( array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'batch_size' => 10 ) );
		$job    = OPEX_Exporter::create( $config, 'Resume test', array( 'mode' => $mode ) );
		$this->assertIsArray( $job );
		$this->assertSame( 'running', $job['status'], 'the site has more than one batch of posts' );
		$this->jobs[] = $job['id'];
		wp_clear_scheduled_hook( 'opex_run_job', array( $job['id'] ) );
		return $job;
	}

	/**
	 * Calls the run route as the Resume button does.
	 *
	 * @param string $id Job id.
	 * @return WP_REST_Response
	 */
	private function resume( string $id ) {
		$req = new WP_REST_Request( 'POST', '/open-export/v1/jobs/' . $id . '/run' );
		$req->set_param( 'resume', true );
		return rest_do_request( $req );
	}

	public function test_resuming_a_paused_background_job_dispatches_the_runner(): void {
		$job = $this->job( 'background' );
		OPEX_Exporter::pause( $job['id'] );
		$res = $this->resume( $job['id'] );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'running', $res->get_data()['status'] );
		$this->assertNotFalse( wp_next_scheduled( 'opex_run_job', array( $job['id'] ) ), 'the runner picks it up now, not at the next stall check' );
	}

	public function test_resuming_a_failed_background_job_dispatches_the_runner(): void {
		$job              = $this->job( 'background' );
		$rec              = OPEX_Jobs::get( $job['id'] );
		$rec['status']    = 'error';
		$rec['error']     = 'Earlier failure.';
		OPEX_Jobs::save( $rec );
		$res = $this->resume( $job['id'] );
		$this->assertSame( 'running', $res->get_data()['status'] );
		$this->assertNotFalse( wp_next_scheduled( 'opex_run_job', array( $job['id'] ) ) );
	}

	public function test_a_browser_job_is_not_dispatched(): void {
		$job = $this->job( 'browser' );
		OPEX_Exporter::pause( $job['id'] );
		$this->assertSame( 'running', $this->resume( $job['id'] )->get_data()['status'] );
		$this->assertFalse( wp_next_scheduled( 'opex_run_job', array( $job['id'] ) ), 'the browser drives its own job' );
	}

	public function test_retention_counts_from_finished(): void {
		OPEX_Settings::save( array( 'retention_days' => 2 ) );
		$make = function ( int $created_days, int $finished_days ): string {
			$job                = $this->job( 'browser' );
			$rec                = OPEX_Jobs::get( $job['id'] );
			$rec['created']     = time() - $created_days * DAY_IN_SECONDS;
			$rec['finished']    = $finished_days >= 0 ? time() - $finished_days * DAY_IN_SECONDS : 0;
			$rec['status']      = $finished_days >= 0 ? 'done' : 'paused';
			OPEX_Jobs::save( $rec );
			return $job['id'];
		};
		$long_run   = $make( 10, 1 );  // Started long ago, finished yesterday: kept.
		$old_done   = $make( 10, 3 );  // Finished three days ago: swept.
		$never_done = $make( 3, -1 );  // Never finished, created three days ago: swept by created.
		$fresh      = $make( 1, -1 );  // Never finished, created yesterday: kept.
		$path       = OPEX_Jobs::file_path( OPEX_Jobs::get( $old_done ) );

		OPEX_Jobs::sweep();
		$this->assertNotNull( OPEX_Jobs::get( $long_run ), 'a job that finished within the window is kept, whenever it started' );
		$this->assertNull( OPEX_Jobs::get( $old_done ) );
		$this->assertFileDoesNotExist( $path );
		$this->assertNull( OPEX_Jobs::get( $never_done ) );
		$this->assertNotNull( OPEX_Jobs::get( $fresh ) );
	}
}
