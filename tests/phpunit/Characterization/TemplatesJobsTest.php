<?php
/**
 * Characterization: templates and job lifecycle.
 *
 * @package Open_Export
 */

require_once __DIR__ . '/CharacterizationCase.php';

/**
 * Saved options opex_templates and opex_jobs, and the job state machine.
 */
class TemplatesJobsTest extends CharacterizationCase {

	/**
	 * Original option values, restored after each test.
	 *
	 * @var array
	 */
	private $saved = array();

	/**
	 * Save the options.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saved = array( get_option( 'opex_templates', false ), get_option( 'opex_jobs', false ) );
	}

	/**
	 * Restore the options.
	 */
	protected function tearDown(): void {
		foreach ( OPEX_Jobs::all() as $job ) {
			if ( ! isset( $this->saved[1][ $job['id'] ] ) ) {
				OPEX_Jobs::delete( $job['id'] );
			}
		}
		foreach ( array( 'opex_templates' => 0, 'opex_jobs' => 1 ) as $name => $i ) {
			if ( false === $this->saved[ $i ] ) {
				delete_option( $name );
			} else {
				update_option( $name, $this->saved[ $i ], false );
			}
		}
		parent::tearDown();
	}

	/**
	 * Template save, list, get and delete.
	 */
	public function test_template_crud(): void {
		$raw = array( 'source' => 'users', 'fields' => array( 'core:ID', 'core:user_login' ), 'date_range' => array( 'field' => 'core:user_registered', 'preset' => 'last_7_days' ), 'format' => 'json' );
		$tpl = OPEX_Templates::save( '  My <b>template</b> ', $raw );
		$this->assertIsArray( $tpl );
		$this->assertMatchesRegularExpression( '/^t[a-f0-9]{12}$/', $tpl['id'] );
		$this->assertSame( 'My template', $tpl['name'] );
		$stored = OPEX_Templates::get( $tpl['id'] );
		$this->assertSame( array( 'id', 'name', 'config', 'created', 'updated' ), array_keys( $stored ) );
		$this->assertSame( 'last_7_days', $stored['config']['date_range']['preset'] );
		$this->assertSame( '', $stored['config']['date_range']['from'], 'relative presets are not frozen in templates' );
		$this->assertSame( array_keys( $tpl ), array_keys( OPEX_Templates::expose( $stored ) ) );
		$this->assertArrayHasKey( $tpl['id'], OPEX_Templates::all() );

		// S-24: saving the same name again updates the template instead of adding one.
		$again = OPEX_Templates::save( 'My template', $raw );
		$this->assertSame( $tpl['id'], $again['id'] );
		$this->assertSame( $tpl['created'], $again['created'] );
		$this->assertCount( 1, OPEX_Templates::all() );

		OPEX_Templates::delete( $tpl['id'] );
		$this->assertNull( OPEX_Templates::get( $tpl['id'] ) );
		OPEX_Templates::delete( 'tnotthere00000' );
		$this->assertFalse( autoload_of( 'opex_templates' ) );
	}

	/**
	 * Template errors.
	 */
	public function test_template_errors(): void {
		$e = OPEX_Templates::save( '   ', array( 'source' => 'users', 'fields' => array( 'core:ID' ) ) );
		$this->assertWPError( $e );
		$this->assertSame( 'opex_tpl_name', $e->get_error_code() );
		$e = OPEX_Templates::save( 'x', array( 'source' => 'nope', 'fields' => array( 'core:ID' ) ) );
		$this->assertWPError( $e );
		$this->assertSame( 'opex_bad_source', $e->get_error_code() );
	}

	/**
	 * Templates keep at most 100 records, dropping the oldest updated.
	 */
	public function test_template_cap(): void {
		$raw = array( 'source' => 'users', 'fields' => array( 'core:ID' ) );
		for ( $i = 0; $i < 102; $i++ ) {
			OPEX_Templates::save( "t$i", $raw );
		}
		$this->assertCount( 100, OPEX_Templates::all() );
	}

	/**
	 * Pause, resume, finish, rerun and delete.
	 */
	public function test_job_lifecycle(): void {
		$config = $this->config( array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'batch_size' => 20 ) );
		$job    = OPEX_Exporter::create( $config, 'Lifecycle' );
		$this->job_ids[] = $job['id'];
		$this->assertSame( array( 'id', 'label', 'format', 'status', 'total', 'processed', 'created', 'filename', 'filesize', 'error', 'mode', 'origin', 'schedule_id', 'deliveries', 'notes', 'delivery_name', 'finished', 'heartbeat', 'schedule_name', 'source', 'source_label', 'sites', 'site_index' ), array_keys( $job ) );
		$this->assertSame( 'running', $job['status'] );
		$this->assertSame( 60, $job['total'] );
		$this->assertSame( 0, $job['processed'] );
		$this->assertSame( 0, $job['filesize'] );
		$this->assertMatchesRegularExpression( '/^lifecycle-\d{8}-\d{6}-[A-Za-z0-9]{12}\.csv$/', $job['filename'] );

		$record = OPEX_Jobs::get( $job['id'] );
		$shipped = array( 'config', 'created', 'cursor', 'error', 'filename', 'filesize', 'headers', 'id', 'label', 'processed', 'status', 'total' );
		$this->assertSame( array(), array_diff( $shipped, array_keys( $record ) ), 'the 12 shipped job keys are still there' );
		$stored = ( (array) get_option( 'opex_jobs' ) )[ $job['id'] ];
		$this->assertSame( array(), array_diff( $shipped, array_keys( $stored ) ), 'and are still saved' );
		$path = OPEX_Jobs::file_path( $record );
		$this->assertFileExists( $path );
		$this->assertSame( "\xEF\xBB\xBFID\n", file_get_contents( $path ), 'start() writes the BOM and header' );

		$r1 = OPEX_Exporter::run( $job['id'] );
		$this->assertSame( 'running', $r1['status'] );
		$this->assertSame( 20, $r1['processed'] );

		$paused = OPEX_Exporter::pause( $job['id'] );
		$this->assertSame( 'paused', $paused['status'] );
		$this->assertSame( 20, $paused['processed'] );
		$this->assertSame( 'paused', OPEX_Exporter::pause( $job['id'] )['status'] );

		// S-7: a plain run call leaves a paused job paused. Only an explicit resume continues it.
		$late = OPEX_Exporter::run( $job['id'] );
		$this->assertSame( 'paused', $late['status'] );
		$this->assertSame( 20, $late['processed'] );
		$r2 = OPEX_Exporter::run( $job['id'], true );
		$this->assertSame( 'running', $r2['status'], 'run with resume continues a paused job' );
		$this->assertSame( 40, $r2['processed'] );
		$this->assertGreaterThan( 0, (int) OPEX_Jobs::get( $job['id'] )['cursor'] );

		$r3 = OPEX_Exporter::run( $job['id'] );
		$this->assertSame( 'running', $r3['status'] );
		$this->assertSame( 60, $r3['processed'] );
		$r4 = OPEX_Exporter::run( $job['id'] );
		$this->assertSame( 'done', $r4['status'], 'the exact multiple needs one more empty batch' );
		$this->assertGreaterThan( 0, $r4['filesize'] );
		$this->assertSame( 'done', OPEX_Exporter::pause( $job['id'] )['status'], 'pause only affects running jobs' );
		$this->assertSame( 'done', OPEX_Exporter::run( $job['id'] )['status'], 'running a done job is a no-op' );

		$again = OPEX_Exporter::rerun( $job['id'] );
		$this->assertIsArray( $again );
		$this->job_ids[] = $again['id'];
		$this->assertNotSame( $job['id'], $again['id'] );
		$this->assertSame( 'Lifecycle (rerun)', $again['label'] );
		$this->assertSame( 'running', $again['status'] );
		$this->assertSame( 60, $again['total'] );

		OPEX_Jobs::delete( $job['id'] );
		$this->assertNull( OPEX_Jobs::get( $job['id'] ) );
		$this->assertFileDoesNotExist( $path );
		$this->assertNotNull( OPEX_Jobs::get( $again['id'] ) );
	}

	/**
	 * Unknown jobs return opex_missing.
	 */
	public function test_missing_job(): void {
		foreach ( array( 'run', 'pause', 'rerun' ) as $fn ) {
			$e = OPEX_Exporter::$fn( 'j000000000000' );
			$this->assertWPError( $e, $fn );
			$this->assertSame( 'opex_missing', $e->get_error_code() );
		}
	}

	/**
	 * An error job restarts on the next run.
	 */
	public function test_error_job_resumes(): void {
		$job = OPEX_Exporter::create( $this->config( array( 'source' => 'terms:category', 'fields' => array( 'core:term_id' ) ) ), 'err' );
		$this->job_ids[] = $job['id'];
		$rec           = OPEX_Jobs::get( $job['id'] );
		$rec['status'] = 'error';
		$rec['error']  = 'boom';
		OPEX_Jobs::save( $rec );
		$out = OPEX_Exporter::run( $job['id'] );
		$this->assertSame( 'done', $out['status'] );
		$this->assertSame( '', $out['error'] );
	}

	/**
	 * A held lock gives a 409.
	 */
	public function test_lock(): void {
		$job = OPEX_Exporter::create( $this->config( array( 'source' => 'terms:category', 'fields' => array( 'core:term_id' ) ) ), 'lock' );
		$this->job_ids[] = $job['id'];
		$this->assertTrue( OPEX_Jobs::acquire_lock( $job['id'] ) );
		$this->assertFalse( OPEX_Jobs::acquire_lock( $job['id'] ) );
		$e = OPEX_Exporter::run( $job['id'] );
		$this->assertWPError( $e );
		$this->assertSame( 'opex_locked', $e->get_error_code() );
		$this->assertSame( 409, $e->get_error_data()['status'] );
		OPEX_Jobs::release_lock( $job['id'] );
		$this->assertSame( 'done', OPEX_Exporter::run( $job['id'] )['status'] );
	}

	/**
	 * History keeps at most OPEX_Jobs::MAX_HISTORY (200) jobs, not counting running and paused ones. Sweep removes jobs older than 30 days.
	 */
	public function test_history_cap_and_sweep(): void {
		$ids = array();
		for ( $i = 0; $i < 52; $i++ ) {
			$job   = OPEX_Exporter::create( $this->config( array( 'source' => 'terms:category', 'fields' => array( 'core:term_id' ) ) ), "h$i" );
			$ids[] = $job['id'];
		}
		// The cap went from 50 to 200 in 2.0 (spec 3.8, D2). HistoryCapTest proves the cap and what it evicts.
		$this->assertLessThanOrEqual( max( OPEX_Jobs::MAX_HISTORY, count( $ids ) ), count( OPEX_Jobs::all() ) );

		$old            = OPEX_Jobs::get( end( $ids ) );
		$old['created'] = time() - 31 * DAY_IN_SECONDS;
		OPEX_Jobs::save( $old );
		$path = OPEX_Jobs::file_path( $old );
		$this->assertFileExists( $path );
		OPEX_Jobs::sweep();
		$this->assertNull( OPEX_Jobs::get( $old['id'] ) );
		$this->assertFileDoesNotExist( $path );
		foreach ( $ids as $id ) {
			OPEX_Jobs::delete( $id );
		}
	}
}

/**
 * Whether an option autoloads.
 *
 * @param string $name Option name.
 * @return bool
 */
function autoload_of( string $name ): bool {
	global $wpdb;
	$v = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	return in_array( $v, array( 'yes', 'on', 'auto', 'auto-on' ), true );
}
