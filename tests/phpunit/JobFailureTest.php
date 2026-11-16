<?php
/**
 * What happens when a run fails: the message a person sees, the failure
 * hook, and the schedule's last run.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class JobFailureTest extends TestCase {

	/** Job ids to remove. */
	private array $jobs = array();

	/** Schedule ids to remove. */
	private array $schedules = array();

	/** Filters and actions to remove, as hook name and callback pairs. */
	private array $hooks = array();

	protected function tearDown(): void {
		foreach ( $this->hooks as $hook ) {
			remove_filter( $hook[0], $hook[1] );
		}
		foreach ( $this->jobs as $id ) {
			OPEX_Jobs::delete( $id );
			wp_clear_scheduled_hook( 'opex_run_job', array( $id ) );
		}
		foreach ( $this->schedules as $id ) {
			OPEX_Schedules::delete( $id );
		}
		$this->hooks     = array();
		$this->jobs      = array();
		$this->schedules = array();
	}

	/** A post export config. */
	private function config(): array {
		$config = OPEX_Exporter::sanitize_config(
			array(
				'source'     => 'posts:post',
				'fields'     => array( 'core:ID', 'core:post_title' ),
				'batch_size' => 10,
				'format'     => 'csv',
			)
		);
		$this->assertIsArray( $config );
		return $config;
	}

	/**
	 * Throws $error from the next batch's row query.
	 *
	 * @param Throwable $error What to throw.
	 */
	private function fail_next_batch( Throwable $error ): void {
		global $wpdb;
		$posts  = $wpdb->posts;
		$thrown = false;
		$filter = static function ( $sql ) use ( &$thrown, $error, $posts ) {
			if ( ! $thrown && 0 === stripos( ltrim( (string) $sql ), 'SELECT' ) && false !== strpos( (string) $sql, $posts ) && false !== stripos( (string) $sql, 'LIMIT' ) ) {
				$thrown = true;
				throw $error;
			}
			return $sql;
		};
		add_filter( 'query', $filter );
		$this->hooks[] = array( 'query', $filter );
	}

	public function test_raw_exception_text_never_reaches_the_job() {
		$job          = OPEX_Exporter::create( $this->config(), 'Failure test' );
		$this->jobs[] = $job['id'];
		$this->fail_next_batch( new RuntimeException( 'Fatal in /srv/secret/wp-content/plugins/x.php on line 12' ) );

		$out = OPEX_Exporter::run( $job['id'] );

		$this->assertSame( 'error', $out['status'] );
		$this->assertStringNotContainsString( '/srv/secret', $out['error'] );
		$this->assertStringNotContainsString( '/srv/secret', OPEX_Jobs::get( $job['id'] )['error'] );
		$this->assertSame( __( 'Export failed. Try resuming or starting a new export.', 'open-export' ), $out['error'] );
	}

	public function test_a_message_written_for_people_reaches_them() {
		$job          = OPEX_Exporter::create( $this->config(), 'Failure test' );
		$this->jobs[] = $job['id'];
		$this->fail_next_batch( new OPEX_User_Error( 'Choose fewer rows.' ) );

		$out = OPEX_Exporter::run( $job['id'] );

		$this->assertSame( 'error', $out['status'] );
		$this->assertSame( 'Choose fewer rows.', $out['error'] );
	}

	public function test_the_excel_row_limit_is_a_message_for_people() {
		$path   = trailingslashit( get_temp_dir() ) . 'opex-limit-' . wp_generate_password( 8, false ) . '.xlsx';
		$writer = OPEX_Writer::make( 'xlsx', $path, array( 'core:ID' => 'ID' ) );
		$writer->start();
		// Pretend the sheet is already full.
		file_put_contents( $path . '.rows.n', (string) ( OPEX_Writer_XLSX::MAX_ROWS + 1 ) );
		$caught = null;
		try {
			$writer->append( array( array( 'core:ID' => '1' ) ), false );
		} catch ( Throwable $e ) {
			$caught = $e;
		}
		foreach ( array( $path, $path . '.rows', $path . '.rows.n' ) as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}

		$this->assertInstanceOf( OPEX_User_Error::class, $caught );
		$this->assertStringContainsString( '1,048,576', OPEX_Exporter::public_error_message( $caught ) );
	}

	public function test_a_failed_scheduled_run_fires_the_hook_and_records_the_error() {
		$sched = OPEX_Schedules::save(
			array(
				'name'   => 'Failing schedule',
				'config' => $this->config(),
			)
		);
		$this->assertIsArray( $sched );
		$this->schedules[] = $sched['id'];

		$job = OPEX_Schedules::start( $sched['id'], 'manual' );
		$this->assertIsArray( $job );
		$this->jobs[] = $job['id'];
		$this->assertSame( 'running', OPEX_Schedules::get( $sched['id'] )['last_run']['status'] );

		$seen   = array();
		$listen = static function ( $failed ) use ( &$seen ) {
			$seen[] = $failed['id'];
		};
		add_action( 'opex_job_failed', $listen );
		$this->hooks[] = array( 'opex_job_failed', $listen );
		$this->fail_next_batch( new RuntimeException( 'Broken.' ) );

		$out = OPEX_Exporter::run( $job['id'] );

		$this->assertSame( 'error', $out['status'] );
		$this->assertSame( array( $job['id'] ), $seen );
		$last = OPEX_Schedules::get( $sched['id'] )['last_run'];
		$this->assertSame( 'error', $last['status'] );
		$this->assertSame( $job['id'], $last['job_id'] );
		$this->assertSame( '', OPEX_Schedules::get( $sched['id'] )['watermark'], 'A failed run leaves the watermark alone.' );
	}

	public function test_a_late_failure_of_an_older_run_does_not_hide_the_newer_one() {
		$sched = OPEX_Schedules::save(
			array(
				'name'   => 'Two runs',
				'config' => $this->config(),
			)
		);
		$this->assertIsArray( $sched );
		$this->schedules[] = $sched['id'];
		OPEX_Schedules::on_job_done(
			array(
				'id'          => 'jnewer0000001',
				'schedule_id' => $sched['id'],
				'status'      => 'done',
				'created'     => time(),
				'processed'   => 4,
			)
		);
		OPEX_Schedules::on_job_failed(
			array(
				'id'          => 'jolder0000001',
				'schedule_id' => $sched['id'],
				'status'      => 'error',
			)
		);

		$last = OPEX_Schedules::get( $sched['id'] )['last_run'];
		$this->assertSame( 'jnewer0000001', $last['job_id'] );
		$this->assertSame( 'done', $last['status'] );
	}
}
