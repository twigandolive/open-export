<?php
/**
 * Engine wiring (pass b): schedules end to end through the runner, locks,
 * delivery timing, the trigger limit, route permissions, background mode.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class EngineRunTest extends TestCase {

	/** @var string[] */
	private array $schedules = array();

	/** @var string[] */
	private array $jobs_before = array();

	/** @var int[] */
	private array $users = array();

	/** @var string[] */
	private array $locks = array();

	protected function setUp(): void {
		wp_set_current_user( 1 );
		delete_option( 'opex_schedules' );
		delete_option( 'opex_settings' );
		$this->jobs_before         = array_keys( OPEX_Jobs::all() );
		$GLOBALS['wp_rest_server'] = null;
		foreach ( array( 'OPEX_Rest', 'OPEX_Rest_Schedules', 'OPEX_Rest_Delivery', 'OPEX_Rest_Public' ) as $class ) {
			add_action( 'rest_api_init', array( $class, 'register_routes' ) );
		}
		rest_get_server();
		add_filter( 'pre_wp_mail', '__return_true' );
	}

	protected function tearDown(): void {
		wp_set_current_user( 1 );
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'opex_delivery_retry_wait' );
		$GLOBALS['wp_rest_server'] = null;
		foreach ( array_keys( OPEX_Schedules::all() ) as $id ) {
			delete_transient( 'opex_trigger_' . $id );
			delete_transient( 'opex_trig_fail_' . $id );
			OPEX_Schedules::delete( $id );
		}
		delete_option( 'opex_schedules' );
		delete_option( 'opex_settings' );
		foreach ( array_keys( OPEX_Jobs::all() ) as $id ) {
			if ( ! in_array( $id, $this->jobs_before, true ) ) {
				wp_clear_scheduled_hook( 'opex_run_job', array( (string) $id ) );
				wp_clear_scheduled_hook( 'opex_deliver', array( (string) $id ) );
				OPEX_Jobs::delete( (string) $id );
			}
		}
		foreach ( $this->locks as $name ) {
			OPEX_Lock::force_release( $name );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $this->users as $uid ) {
			wp_delete_user( $uid );
		}
		delete_transient( 'opex_trig_fail_s0000000000aa' );
	}

	/** Assert that a value is a WP_Error. */
	private function assertWPError( $value ): void {
		$this->assertInstanceOf( WP_Error::class, $value );
	}

	/** Save a schedule over posts. */
	private function schedule( array $over = array() ): array {
		$rec = OPEX_Schedules::save( array_merge(
			array(
				'name'      => 'Engine schedule',
				'config'    => array( 'source' => 'posts:post', 'fields' => array( 'core:ID', 'core:post_title' ), 'batch_size' => 10 ),
				'frequency' => 'daily',
				'time'      => '03:30',
				'enabled'   => true,
			),
			$over
		) );
		$this->assertIsArray( $rec, is_wp_error( $rec ) ? $rec->get_error_message() : '' );
		return $rec;
	}

	/** Send a REST request. */
	private function call( string $method, string $path, array $body = array(), array $query = array() ) {
		$req = new WP_REST_Request( $method, '/open-export/v1' . $path );
		if ( $query ) {
			$req->set_query_params( $query );
		}
		if ( $body ) {
			$req->set_header( 'content-type', 'application/json' );
			$req->set_body( wp_json_encode( $body ) );
		}
		return rest_do_request( $req );
	}

	/** Log in as a subscriber. */
	private function subscriber(): void {
		$uid           = wp_insert_user( array( 'user_login' => 'opexsub' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
		$this->users[] = (int) $uid;
		wp_set_current_user( (int) $uid );
	}

	public function test_schedule_runs_end_to_end_through_the_runner(): void {
		$mails = array();
		remove_all_filters( 'pre_wp_mail' );
		add_filter( 'pre_wp_mail', static function ( $pre, $atts ) use ( &$mails ) {
			$mails[] = $atts;
			return true;
		}, 10, 2 );
		$rec = $this->schedule( array(
			'incremental' => array( 'enabled' => true, 'field' => 'core:post_date_gmt' ),
			'delivery'    => array( 'email' => array( 'enabled' => true, 'to' => 'ann@example.com', 'mode' => 'link' ) ),
			'filename'    => 'posts-{date}',
		) );
		$id = $rec['id'];
		$this->assertSame( '', $rec['watermark'] );

		$before = time();
		$job    = OPEX_Schedules::start( $id, 'cron' );
		$this->assertIsArray( $job, is_wp_error( $job ) ? $job->get_error_message() : '' );
		$this->assertSame( 'running', $job['status'] );
		$this->assertSame( 'background', $job['mode'] );
		$this->assertSame( 'cron', $job['origin'] );
		$this->assertSame( $id, $job['schedule_id'] );
		$this->assertNotFalse( wp_next_scheduled( 'opex_run_job', array( $job['id'] ) ), 'the work is queued' );
		$this->assertSame( $job['id'], OPEX_Schedules::get( $id )['last_run']['job_id'] );
		$this->assertSame( 'running', OPEX_Schedules::get( $id )['last_run']['status'] );
		$this->assertGreaterThan( $before, OPEX_Schedules::get( $id )['next_run'], 'next run armed' );

		OPEX_Runner::on_cron( $job['id'] );
		for ( $i = 0; $i < 10 && 'running' === OPEX_Jobs::get( $job['id'] )['status']; $i++ ) {
			OPEX_Runner::on_cron( $job['id'] );
		}
		$done = OPEX_Jobs::get( $job['id'] );
		$this->assertSame( 'done', $done['status'] );
		$this->assertGreaterThan( 0, $done['heartbeat'] );
		$this->assertStringStartsWith( 'posts-', $done['delivery_name'] );

		$s = OPEX_Schedules::get( $id );
		$this->assertSame( 'done', $s['last_run']['status'] );
		$this->assertSame( $done['processed'], $s['last_run']['rows'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $done['created'] ), $s['watermark'], 'the watermark is the job start time' );
		$this->assertSame( wp_next_scheduled( 'opex_run_schedule', array( $id ) ), $s['next_run'], 'next run still armed' );
		$this->assertNotFalse( wp_next_scheduled( 'opex_deliver', array( $job['id'] ) ), 'delivery is queued by the done hook' );

		OPEX_Runner::deliver( $job['id'] );
		$sent = OPEX_Jobs::get( $job['id'] );
		$this->assertSame( 'sent', $sent['deliveries'][0]['status'] );
		$this->assertCount( 1, $mails );
		$this->assertFalse( wp_next_scheduled( 'opex_deliver', array( $job['id'] ) ), 'nothing left to retry' );

		// The second run asks only for rows after the watermark.
		$next = OPEX_Schedules::start( $id, 'manual' );
		$this->assertIsArray( $next );
		$this->assertSame( 'done', OPEX_Jobs::get( $next['id'] )['status'], 'nothing is newer, so it ends at once' );
		$this->assertSame( array( 'field' => 'core:post_date_gmt', 'after' => $s['watermark'] ), OPEX_Jobs::get( $next['id'] )['since'] );
		$this->assertSame( 0, $next['processed'] );
		$this->assertSame( $next['id'], OPEX_Schedules::get( $id )['last_run']['job_id'] );
		$this->assertGreaterThanOrEqual( $s['watermark'], OPEX_Schedules::get( $id )['watermark'], 'the watermark never moves back' );
		$this->assertNotFalse( wp_next_scheduled( 'opex_run_schedule', array( $id ) ) );
	}

	public function test_a_second_start_is_refused_while_one_runs(): void {
		$rec   = $this->schedule();
		$first = OPEX_Schedules::start( $rec['id'], 'manual' );
		$this->assertSame( 'running', $first['status'] );
		$busy = OPEX_Schedules::start( $rec['id'], 'trigger' );
		$this->assertWPError( $busy );
		$this->assertSame( 'opex_busy', $busy->get_error_code() );
		$this->assertSame( 409, $busy->get_error_data()['status'] );

		// While another process holds the start lock, a start is refused too and makes no job.
		OPEX_Jobs::delete( $first['id'] );
		$count = count( OPEX_Jobs::all() );
		$token = OPEX_Lock::acquire( 'sched_' . $rec['id'], 60 );
		$this->assertIsString( $token );
		$this->locks[] = 'sched_' . $rec['id'];
		$this->assertSame( 'opex_busy', OPEX_Schedules::start( $rec['id'], 'manual' )->get_error_code() );
		$this->assertSame( $count, count( OPEX_Jobs::all() ) );
		OPEX_Lock::release( 'sched_' . $rec['id'], $token );
		$this->assertIsArray( OPEX_Schedules::start( $rec['id'], 'manual' ), 'the lock is released after a start' );
		$this->assertFalse( OPEX_Lock::is_held( 'sched_' . $rec['id'] ) );
	}

	/** Make a done job with one email delivery, mail sending set by the caller. */
	private function job_with_email(): array {
		$config = OPEX_Exporter::sanitize_config( array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'batch_size' => 100 ) );
		$job    = OPEX_Exporter::create( $config, 'Delivery timing', array( 'delivery' => array( 'email' => array( 'enabled' => true, 'to' => 'ann@example.com', 'mode' => 'link' ) ) ) );
		$this->assertIsArray( $job );
		while ( 'running' === $job['status'] ) {
			$job = OPEX_Exporter::run( $job['id'] );
		}
		$this->assertSame( 'pending', $job['deliveries'][0]['status'] );
		wp_clear_scheduled_hook( 'opex_deliver', array( $job['id'] ) );
		return $job;
	}

	/** Move the last attempt of the first delivery item back in time. */
	private function age_attempt( string $id, int $seconds ): void {
		$rec = OPEX_Jobs::get( $id );
		$rec['deliveries'][0]['time'] -= $seconds;
		OPEX_Jobs::save( $rec );
	}

	public function test_delivery_retries_follow_next_attempt_at(): void {
		remove_all_filters( 'pre_wp_mail' );
		add_filter( 'pre_wp_mail', '__return_false' );
		$job = $this->job_with_email();
		$id  = $job['id'];

		OPEX_Runner::deliver( $id );
		$item = OPEX_Jobs::get( $id )['deliveries'][0];
		$this->assertSame( 'failed', $item['status'] );
		$this->assertSame( 1, $item['attempts'] );
		$this->assertEqualsWithDelta( time() + 300, (int) wp_next_scheduled( 'opex_deliver', array( $id ) ), 3, 'first retry in 5 minutes' );
		$this->assertSame( OPEX_Delivery::next_attempt_at( OPEX_Jobs::get( $id )['deliveries'] ), (int) wp_next_scheduled( 'opex_deliver', array( $id ) ) );

		OPEX_Runner::deliver( $id );
		$this->assertSame( 1, OPEX_Jobs::get( $id )['deliveries'][0]['attempts'], 'not due yet, so no second try' );

		$this->age_attempt( $id, 400 );
		OPEX_Runner::deliver( $id );
		$this->assertSame( 2, OPEX_Jobs::get( $id )['deliveries'][0]['attempts'] );
		$this->assertEqualsWithDelta( time() + 1800, (int) wp_next_scheduled( 'opex_deliver', array( $id ) ), 3, 'second retry in 30 minutes' );

		$this->age_attempt( $id, 2000 );
		OPEX_Runner::deliver( $id );
		$this->assertSame( 3, OPEX_Jobs::get( $id )['deliveries'][0]['attempts'] );
		$this->assertFalse( wp_next_scheduled( 'opex_deliver', array( $id ) ), 'three attempts is the end' );
		OPEX_Runner::deliver( $id );
		$this->assertSame( 3, OPEX_Jobs::get( $id )['deliveries'][0]['attempts'] );
	}

	public function test_a_retry_that_works_marks_sent_and_stops(): void {
		remove_all_filters( 'pre_wp_mail' );
		add_filter( 'pre_wp_mail', '__return_false' );
		$id = $this->job_with_email()['id'];
		OPEX_Runner::deliver( $id );
		$this->assertNotFalse( wp_next_scheduled( 'opex_deliver', array( $id ) ) );

		remove_all_filters( 'pre_wp_mail' );
		add_filter( 'pre_wp_mail', '__return_true' );
		$this->age_attempt( $id, 400 );
		OPEX_Runner::deliver( $id );
		$item = OPEX_Jobs::get( $id )['deliveries'][0];
		$this->assertSame( 'sent', $item['status'] );
		$this->assertSame( 2, $item['attempts'] );
		$this->assertFalse( wp_next_scheduled( 'opex_deliver', array( $id ) ) );
	}

	public function test_the_delivery_lock_keeps_two_senders_apart(): void {
		$mails = 0;
		remove_all_filters( 'pre_wp_mail' );
		add_filter( 'pre_wp_mail', static function () use ( &$mails ) {
			$mails++;
			return true;
		} );
		$id            = $this->job_with_email()['id'];
		$token         = OPEX_Lock::acquire( 'deliver_' . $id, 60 );
		$this->locks[] = 'deliver_' . $id;
		$this->assertIsString( $token );
		OPEX_Runner::deliver( $id );
		$this->assertSame( 0, $mails );
		$this->assertSame( 0, OPEX_Jobs::get( $id )['deliveries'][0]['attempts'] );
		OPEX_Lock::release( 'deliver_' . $id, $token );

		OPEX_Runner::deliver( $id );
		$this->assertSame( 1, $mails );
		$this->assertFalse( OPEX_Lock::is_held( 'deliver_' . $id ), 'released after sending' );
		OPEX_Runner::deliver( $id );
		$this->assertSame( 1, $mails, 'a sent item is never sent again' );
	}

	public function test_trigger_stops_answering_after_ten_wrong_keys(): void {
		wp_set_current_user( 0 );
		$s    = $this->schedule( array( 'frequency' => 'manual' ) );
		$path = '/schedules/' . $s['id'] . '/trigger';
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertSame( 403, $this->call( 'GET', $path, array(), array( 'key' => 'wrong' . $i ) )->get_status() );
		}
		$this->assertSame( 10, get_transient( 'opex_trig_fail_' . $s['id'] )['count'] );
		$locked = $this->call( 'GET', $path, array(), array( 'key' => $s['trigger_key'] ) );
		$this->assertSame( 429, $locked->get_status(), 'the right key is refused inside the window' );
		$this->assertSame( 429, $this->call( 'GET', $path, array(), array( 'key' => 'wrong' ) )->get_status() );
		$this->assertSame( array(), array_diff( array_keys( OPEX_Jobs::all() ), $this->jobs_before ), 'no job started' );

		// The window ends and the right key works again.
		set_transient( 'opex_trig_fail_' . $s['id'], array( 'count' => 10, 'until' => time() - 1 ), 600 );
		$this->assertSame( 200, $this->call( 'GET', $path, array(), array( 'key' => $s['trigger_key'] ) )->get_status() );

		// An unknown id takes the same road and gets the same answers.
		$unknown = '/schedules/s0000000000aa/trigger';
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertSame( 403, $this->call( 'GET', $unknown, array(), array( 'key' => 'wrong' . $i ) )->get_status() );
		}
		$this->assertSame( 429, $this->call( 'GET', $unknown, array(), array( 'key' => 'wrong' ) )->get_status() );
		$this->assertSame( 10, get_transient( 'opex_trig_fail_s0000000000aa' )['count'] );
	}

	public function test_a_good_key_does_not_count_as_a_failure(): void {
		wp_set_current_user( 0 );
		$s = $this->schedule( array( 'frequency' => 'manual' ) );
		$this->call( 'GET', '/schedules/' . $s['id'] . '/trigger', array(), array( 'key' => $s['trigger_key'] ) );
		$this->assertFalse( get_transient( 'opex_trig_fail_' . $s['id'] ) );
	}

	/** Every route except the two public ones refuses a logged-out caller and a subscriber. */
	public function test_every_admin_route_checks_the_capability(): void {
		$routes = rest_get_server()->get_routes( 'open-export/v1' );
		$this->assertGreaterThanOrEqual( 25, count( $routes ) );
		$names = array_keys( $routes );
		foreach ( array( '/schedules', '/destinations', '/settings', '/options', '/jobs' ) as $expected ) {
			$this->assertContains( '/open-export/v1' . $expected, $names );
		}
		$public = 0;
		$this->subscriber();
		$sub = get_current_user_id();
		foreach ( $routes as $pattern => $handlers ) {
			if ( '/open-export/v1' === $pattern ) {
				continue; // The namespace index is WordPress's own.
			}
			$path = (string) preg_replace( '/\(\?P<id>([a-z])\[a-f0-9\]\{12\}\)/', '${1}000000000000', substr( $pattern, strlen( '/open-export/v1' ) ) );
			if ( preg_match( '#^/(file/|schedules/[^/]+/trigger$)#', $path ) ) {
				$public++;
				foreach ( $handlers as $handler ) {
					$this->assertIsCallable( $handler['permission_callback'], $pattern );
				}
				continue;
			}
			foreach ( $handlers as $handler ) {
				foreach ( array_keys( $handler['methods'] ) as $method ) {
					wp_set_current_user( 0 );
					$out = $this->call( $method, $path, array( 'name' => 'Probe' ), array( 'source' => 'users' ) );
					$this->assertContains( $out->get_status(), array( 401, 403 ), "logged out: $method $pattern" );
					wp_set_current_user( $sub );
					$out = $this->call( $method, $path, array( 'name' => 'Probe' ), array( 'source' => 'users' ) );
					$this->assertSame( 403, $out->get_status(), "subscriber: $method $pattern" );
				}
			}
		}
		$this->assertSame( 2, $public, 'only the token download and the trigger URL are public' );
	}

	public function test_public_routes_check_their_secret_inside_the_callback(): void {
		wp_set_current_user( 0 );
		$s = $this->schedule( array( 'frequency' => 'manual' ) );
		$this->assertSame( 403, $this->call( 'GET', '/schedules/' . $s['id'] . '/trigger' )->get_status() );
		$this->assertSame( 404, $this->call( 'GET', '/file/j000000000000' )->get_status() );
	}

	public function test_post_jobs_background_mode(): void {
		$body = array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'batch_size' => 10 );

		// Nothing sent and the setting is off: browser mode, nothing queued.
		$out = $this->call( 'POST', '/jobs', $body );
		$this->assertSame( 200, $out->get_status(), wp_json_encode( $out->get_data() ) );
		$job = $out->get_data();
		$this->assertSame( 'browser', $job['mode'] );
		$this->assertSame( 'manual', $job['origin'] );
		$this->assertFalse( wp_next_scheduled( 'opex_run_job', array( $job['id'] ) ) );

		// Asked for in the request.
		$bg = $this->call( 'POST', '/jobs', $body + array( 'mode' => 'background' ) )->get_data();
		$this->assertSame( 'background', $bg['mode'] );
		$this->assertNotFalse( wp_next_scheduled( 'opex_run_job', array( $bg['id'] ) ) );
		OPEX_Runner::on_cron( $bg['id'] );
		for ( $i = 0; $i < 10 && 'running' === OPEX_Jobs::get( $bg['id'] )['status']; $i++ ) {
			OPEX_Runner::on_cron( $bg['id'] );
		}
		$this->assertSame( 'done', OPEX_Jobs::get( $bg['id'] )['status'] );

		// The setting decides when the request is silent, and an explicit browser wins.
		OPEX_Settings::save( array( 'background' => true ) );
		$set = $this->call( 'POST', '/jobs', $body )->get_data();
		$this->assertSame( 'background', $set['mode'] );
		$this->assertNotFalse( wp_next_scheduled( 'opex_run_job', array( $set['id'] ) ) );
		$browser = $this->call( 'POST', '/jobs', $body + array( 'mode' => 'browser' ) )->get_data();
		$this->assertSame( 'browser', $browser['mode'] );
		$this->assertFalse( wp_next_scheduled( 'opex_run_job', array( $browser['id'] ) ) );

		$bad = $this->call( 'POST', '/jobs', $body + array( 'mode' => 'warp' ) );
		$this->assertSame( 400, $bad->get_status() );
	}

	public function test_post_jobs_accepts_delivery_without_leaking_secrets(): void {
		$body = array(
			'source'   => 'posts:post',
			'fields'   => array( 'core:ID' ),
			'delivery' => array( 'webhook' => array( 'enabled' => true, 'url' => 'https://example.com/hook', 'secret' => 'route-secret-1' ) ),
		);
		$out  = $this->call( 'POST', '/jobs', $body )->get_data();
		$this->assertStringNotContainsString( 'route-secret-1', wp_json_encode( $out ) );
		$job = OPEX_Jobs::get( $out['id'] );
		$this->assertSame( 'route-secret-1', OPEX_Secrets::decrypt( $job['delivery']['webhook']['secret'] ) );
		$list = $this->call( 'GET', '/jobs' )->get_data();
		$this->assertStringNotContainsString( 'route-secret-1', wp_json_encode( $list ) );
		$this->assertStringNotContainsString( 'route-secret-1', wp_json_encode( $this->call( 'GET', '/jobs/' . $out['id'] )->get_data() ) );
	}

	public function test_options_report_the_real_flags(): void {
		$o = $this->call( 'GET', '/options' )->get_data();
		$this->assertSame( class_exists( 'ZipArchive' ), $o['formats']['xlsx']['available'] );
		$this->assertSame( class_exists( 'WooCommerce' ), $o['features']['woocommerce'] );
		$this->assertSame( is_multisite(), $o['features']['multisite'] );
		$this->assertFalse( $o['features']['background'] );
		$this->assertArrayHasKey( 'find_replace', $o['transforms'] );
		$this->assertSame( 3, $o['limits']['filter_depth'] );
		OPEX_Settings::save( array( 'background' => true ) );
		$this->assertTrue( $this->call( 'GET', '/options' )->get_data()['features']['background'] );
	}

	public function test_the_sweep_runs_from_cron_not_from_the_job_list(): void {
		$this->assertNotFalse( has_action( 'opex_maintenance', array( 'OPEX_Runner', 'maintenance' ) ) );
		$this->assertNotFalse( has_action( 'opex_job_done', array( 'OPEX_Schedules', 'on_job_done' ) ) );
		$old            = OPEX_Jobs::all();
		$stale          = array( 'id' => 'jstale0000001', 'label' => 'Stale', 'config' => array( 'format' => 'csv' ), 'status' => 'done', 'created' => time() - 90 * DAY_IN_SECONDS, 'filename' => '' );
		OPEX_Jobs::save( $stale );
		$this->call( 'GET', '/jobs' );
		$this->assertNotNull( OPEX_Jobs::get( 'jstale0000001' ), 'listing jobs no longer sweeps' );
		OPEX_Runner::maintenance();
		$this->assertNull( OPEX_Jobs::get( 'jstale0000001' ) );
		$this->assertSame( count( $old ), count( OPEX_Jobs::all() ) );
	}
}
