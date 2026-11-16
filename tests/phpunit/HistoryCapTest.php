<?php
/**
 * The job history cap: 200 jobs, oldest finished or failed first, and a
 * running or paused job is never removed to make room.
 *
 * The stored job list is set aside in setUp and put back in tearDown, so the
 * test works on its own list. The jobs are plain records with no rows to read.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * OPEX_Jobs::MAX_HISTORY and what it evicts.
 */
class HistoryCapTest extends TestCase {

	/** @var mixed The stored list before the test. */
	private $saved = null;

	/** @var string[] Files the test made. */
	private array $files = array();

	protected function setUp(): void {
		$this->saved = get_option( 'opex_jobs', null );
		update_option( 'opex_jobs', array(), false );
	}

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		if ( null === $this->saved ) {
			delete_option( 'opex_jobs' );
		} else {
			update_option( 'opex_jobs', $this->saved, false );
		}
	}

	/**
	 * Saves a minimal job record.
	 *
	 * @param int    $n       Job number, used for the id and the age.
	 * @param string $status  Status.
	 * @param string $file    File name, or '' for none.
	 */
	private function add( int $n, string $status, string $file = '' ): string {
		$id = 'j' . str_pad( dechex( $n ), 12, '0', STR_PAD_LEFT );
		OPEX_Jobs::save(
			array(
				'id'       => $id,
				'label'    => "cap $n",
				'config'   => array( 'source' => 'posts:post' ),
				'status'   => $status,
				'created'  => 1000000 + $n,
				'filename' => $file,
			)
		);
		return $id;
	}

	/** The cap is 200 and /options reports it. */
	public function test_cap_is_200(): void {
		$this->assertSame( 200, OPEX_Jobs::MAX_HISTORY );
		$this->assertSame( 200, OPEX_Rest_Delivery::options()->get_data()['limits']['history'] );
	}

	/** Over the cap, the oldest finished or failed job goes, with its file. Running and paused jobs stay. */
	public function test_oldest_ended_job_is_evicted_and_in_progress_jobs_stay(): void {
		// The two oldest jobs are still in progress and must survive.
		$running = $this->add( 1, 'running' );
		$paused  = $this->add( 2, 'paused' );
		$file    = 'opex-cap-' . wp_generate_password( 8, false ) . '.csv';
		$path    = trailingslashit( opex_export_dir() ) . $file;
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, 'old' );
		$this->files[] = $path;
		$oldest_done   = $this->add( 3, 'done', $file );
		$oldest_error  = $this->add( 4, 'error' );
		for ( $n = 5; $n <= 200; $n++ ) {
			$this->add( $n, 0 === $n % 2 ? 'done' : 'error' );
		}
		$this->assertCount( 200, OPEX_Jobs::all(), 'at the cap nothing is removed' );
		$this->assertNotNull( OPEX_Jobs::get( $oldest_done ) );

		$this->add( 201, 'running' );
		$this->assertCount( 200, OPEX_Jobs::all() );
		$this->assertNull( OPEX_Jobs::get( $oldest_done ), 'the oldest finished job made room' );
		$this->assertFileDoesNotExist( $path, 'its file went with it' );
		$this->assertNotNull( OPEX_Jobs::get( $running ) );
		$this->assertNotNull( OPEX_Jobs::get( $paused ) );

		$this->add( 202, 'done' );
		$this->assertNull( OPEX_Jobs::get( $oldest_error ), 'a failed job counts as ended' );
		$this->assertNotNull( OPEX_Jobs::get( $running ) );
		$this->assertNotNull( OPEX_Jobs::get( $paused ) );
		$this->assertCount( 200, OPEX_Jobs::all() );
	}

	/** When every job is still in progress, none is removed, even over the cap. */
	public function test_never_evicts_in_progress_jobs(): void {
		for ( $n = 1; $n <= 202; $n++ ) {
			$this->add( $n, 0 === $n % 2 ? 'running' : 'paused' );
		}
		$this->assertCount( 202, OPEX_Jobs::all() );
		// The job being saved is never removed by its own save, so it stays too.
		$done = $this->add( 203, 'done' );
		$this->assertCount( 203, OPEX_Jobs::all() );
		$this->assertNotNull( OPEX_Jobs::get( $done ) );
		// The next save may remove it: it is now the only finished job.
		$this->add( 204, 'running' );
		$this->assertNull( OPEX_Jobs::get( $done ) );
		$this->assertCount( 203, OPEX_Jobs::all() );
	}
}
