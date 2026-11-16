<?php
/**
 * I-1: the busy error of OPEX_Store never reaches a person as a fatal error.
 * A REST route answers 503, a cron callback logs it and leaves the work for
 * the next run, and WP-CLI prints the message and exits with code 1.
 *
 * The store is made busy through its own seam: another holder keeps the
 * option's store lock, and the wait of OPEX_Store::take() is shortened.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/servers/helpers.php';

/**
 * Busy store through every entry point.
 */
class CodexIntegrationBusyTest extends TestCase {

	use OpexDeliveryFixtures;

	/** @var array<string, string> Store locks held by "another process", by option. */
	private $held = array();

	/** @var string[] Schedule ids to remove. */
	private $schedules = array();

	/** @var string[] Job ids that existed before the test. */
	private $jobs_before = array();

	protected function setUp(): void {
		foreach ( array( 'OPEX_Rest', 'OPEX_Rest_Schedules', 'OPEX_Rest_Delivery', 'OPEX_Rest_Public' ) as $class ) {
			if ( false === has_action( 'rest_api_init', array( $class, 'register_routes' ) ) ) {
				add_action( 'rest_api_init', array( $class, 'register_routes' ) );
			}
		}
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
		wp_set_current_user( 1 );
		$this->jobs_before = array_keys( OPEX_Jobs::all() );
	}

	protected function tearDown(): void {
		foreach ( $this->held as $option => $token ) {
			OPEX_Lock::release( 'store_' . $option, $token );
		}
		$this->held = array();
		$this->set_wait( 20 );
		remove_all_filters( 'pre_wp_mail' );
		foreach ( array_keys( OPEX_Jobs::all() ) as $id ) {
			if ( ! in_array( $id, $this->jobs_before, true ) ) {
				wp_clear_scheduled_hook( 'opex_run_job', array( (string) $id ) );
				wp_clear_scheduled_hook( 'opex_deliver', array( (string) $id ) );
				OPEX_Jobs::delete( (string) $id );
			}
		}
		$this->cleanup_jobs();
		foreach ( $this->schedules as $id ) {
			wp_clear_scheduled_hook( 'opex_run_schedule', array( $id ) );
			OPEX_Schedules::delete( $id );
		}
		$GLOBALS['wp_rest_server'] = null;
		wp_set_current_user( 1 );
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
					'name'      => 'Busy schedule',
					'config'    => array(
						'source'     => 'posts:post',
						'fields'     => array( 'core:ID', 'core:post_title' ),
						'batch_size' => 10,
					),
					'frequency' => 'daily',
					'time'      => '03:30',
					'enabled'   => true,
				),
				$over
			)
		);
		$this->assertIsArray( $rec );
		$this->schedules[] = (string) $rec['id'];
		return $rec;
	}

	/**
	 * Sends a REST request and fails the test on a throw, which is what WordPress would turn into a fatal error.
	 *
	 * @param string $method Method.
	 * @param string $path   Path below the namespace.
	 * @param array  $body   JSON body.
	 * @param array  $query  Query parameters.
	 * @return WP_REST_Response
	 */
	private function call( string $method, string $path, array $body = array(), array $query = array() ): WP_REST_Response {
		$req = new WP_REST_Request( $method, '/open-export/v1' . $path );
		if ( $query ) {
			$req->set_query_params( $query );
		}
		if ( $body ) {
			$req->set_header( 'content-type', 'application/json' );
			$req->set_body( wp_json_encode( $body ) );
		}
		try {
			return rest_do_request( $req );
		} catch ( Throwable $e ) {
			$this->fail( "$method $path threw " . get_class( $e ) . ': ' . $e->getMessage() );
		}
	}

	/**
	 * Checks a busy answer.
	 *
	 * @param WP_REST_Response $out Response.
	 * @param string           $what Which route.
	 */
	private function assert_busy( WP_REST_Response $out, string $what ): void {
		$this->assertSame( 503, $out->get_status(), $what );
		$data = $out->get_data();
		$this->assertSame( 'opex_busy', $data['code'] ?? '', $what );
		$this->assertSame( 'The site is busy. Try again in a moment.', $data['message'] ?? '', $what );
	}

	public function test_template_delete_answers_busy(): void {
		$this->busy( 'opex_templates' );
		$this->assert_busy( $this->call( 'DELETE', '/templates/t000000000000' ), 'OPEX_Rest' );
	}

	public function test_schedule_save_answers_busy(): void {
		$this->busy( 'opex_schedules' );
		$out = $this->call(
			'POST',
			'/schedules',
			array(
				'name'      => 'Busy',
				'config'    => array(
					'source' => 'posts:post',
					'fields' => array( 'core:ID' ),
				),
				'frequency' => 'daily',
				'time'      => '03:30',
			)
		);
		$this->assert_busy( $out, 'OPEX_Rest_Schedules' );
	}

	public function test_settings_save_answers_busy(): void {
		$this->busy( 'opex_settings' );
		$this->assert_busy( $this->call( 'POST', '/settings', array( 'retention_days' => 9 ) ), 'OPEX_Rest_Delivery' );
	}

	public function test_destination_save_answers_busy(): void {
		$this->busy( 'opex_destinations' );
		$out = $this->call(
			'POST',
			'/destinations',
			array(
				'name'     => 'Busy',
				'type'     => 'ftp',
				'host'     => 'ftp.example.com',
				'username' => 'u',
				'secret'   => 'p',
				'path'     => '/in',
			)
		);
		$this->assert_busy( $out, 'OPEX_Rest_Delivery destinations' );
	}

	public function test_watermark_reset_answers_busy(): void {
		$rec = $this->schedule();
		$this->busy( 'opex_schedules' );
		$this->assert_busy( $this->call( 'POST', '/schedules/' . $rec['id'] . '/reset-watermark' ), 'OPEX_Rest_Screen' );
	}

	public function test_public_trigger_answers_busy(): void {
		$rec = $this->schedule( array( 'frequency' => 'manual' ) );
		wp_set_current_user( 0 );
		// The job is created, then saving the schedule's next run gives up.
		$this->busy( 'opex_schedules' );
		$this->assert_busy( $this->call( 'GET', '/schedules/' . $rec['id'] . '/trigger', array(), array( 'key' => $rec['trigger_key'] ) ), 'OPEX_Rest_Public' );
	}

	public function test_other_errors_never_show_server_detail(): void {
		$plain = OPEX_Rest_Guard::error_for( new RuntimeException( 'fopen(/var/www/secret/path): failed' ) );
		$this->assertSame( 'opex_failed', $plain->get_error_code() );
		$this->assertSame( 500, $plain->get_error_data()['status'] );
		$this->assertStringNotContainsString( '/var/www', $plain->get_error_message() );

		$user = OPEX_Rest_Guard::error_for( new OPEX_User_Error( 'Pick at least one field.' ) );
		$this->assertSame( 400, $user->get_error_data()['status'] );
		$this->assertSame( 'Pick at least one field.', $user->get_error_message() );
	}

	public function test_every_route_of_the_plugin_is_guarded(): void {
		$routes  = rest_get_server()->get_routes( 'open-export/v1' );
		$guarded = 0;
		foreach ( $routes as $pattern => $handlers ) {
			if ( '/open-export/v1' === $pattern ) {
				continue; // The namespace index is WordPress's own.
			}
			foreach ( $handlers as $handler ) {
				$this->assertInstanceOf( Closure::class, $handler['callback'], $pattern );
				++$guarded;
			}
		}
		$this->assertGreaterThanOrEqual( 25, $guarded );
	}

	public function test_a_cron_schedule_start_that_hits_a_busy_store_runs_again_soon(): void {
		$rec = $this->schedule();
		wp_clear_scheduled_hook( 'opex_run_schedule', array( $rec['id'] ) );
		$this->busy( 'opex_schedules' );
		try {
			OPEX_Runner::on_schedule( $rec['id'] );
		} catch ( Throwable $e ) {
			$this->fail( 'The cron callback threw ' . get_class( $e ) );
		}
		$next = wp_next_scheduled( 'opex_run_schedule', array( $rec['id'] ) );
		$this->assertIsInt( $next, 'The schedule is tried again.' );
		$this->assertLessThanOrEqual( time() + 61, $next );
	}

	public function test_a_cron_delivery_that_hits_a_busy_store_is_tried_again(): void {
		add_filter( 'pre_wp_mail', '__return_true' );
		$job = $this->make_job(
			array(
				'delivery'   => array(
					'email' => array(
						'enabled' => true,
						'to'      => array( 'ann@example.com' ),
						'mode'    => 'link',
					),
				),
				'deliveries' => array(
					array(
						'channel'  => 'email',
						'status'   => 'pending',
						'attempts' => 0,
						'message'  => '',
						'time'     => 0,
					),
				),
			)
		);
		$this->busy( 'opex_jobs' );
		try {
			OPEX_Runner::deliver( $job['id'] );
		} catch ( Throwable $e ) {
			$this->fail( 'The cron callback threw ' . get_class( $e ) );
		}
		$this->assertIsInt( wp_next_scheduled( 'opex_deliver', array( $job['id'] ) ), 'The delivery is tried again.' );
		$this->assertFalse( OPEX_Lock::is_held( 'deliver_' . $job['id'] ), 'The delivery lock is given back.' );
	}

	public function test_maintenance_carries_on_after_a_busy_step(): void {
		$rec = $this->schedule();
		wp_clear_scheduled_hook( 'opex_run_schedule', array( $rec['id'] ) );
		// The first step, the sweep, gives up. The re-arm step must still run.
		$this->busy( 'opex_jobs' );
		try {
			OPEX_Runner::maintenance();
		} catch ( Throwable $e ) {
			$this->fail( 'The cron callback threw ' . get_class( $e ) );
		}
		$this->assertIsInt( wp_next_scheduled( 'opex_run_schedule', array( $rec['id'] ) ) );
	}

	public function test_cli_prints_the_busy_message_and_exits_1(): void {
		if ( '' === trim( (string) shell_exec( 'command -v wp 2>/dev/null' ) ) ) {
			$this->markTestSkipped( 'The wp command is not installed.' );
		}
		$rec = $this->schedule( array( 'frequency' => 'manual' ) );
		// The child process waits the store's full 20 seconds, then gives up.
		$this->busy( 'opex_schedules' );
		$cmd  = array( PHP_BINARY, '-d', 'memory_limit=1G', '-d', 'error_reporting=22527', trim( (string) shell_exec( 'command -v wp' ) ), '--path=' . rtrim( (string) getenv( 'OPEX_TEST_SITE' ), '/' ) . '/wordpress', 'opex', 'run', (string) $rec['id'], '--porcelain' );
		$proc = proc_open( $cmd, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $proc );
		fclose( $pipes[0] );
		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $proc );
		foreach ( array( 'opex_jobs', 'opex_schedules', 'notoptions', 'alloptions' ) as $key ) {
			wp_cache_delete( $key, 'options' );
		}

		$this->assertSame( 1, $code, $out . $err );
		$this->assertStringContainsString( 'Error: The site is busy. Try again in a moment.', $err );
		$this->assertStringNotContainsString( 'Uncaught', $out . $err );
	}
}
