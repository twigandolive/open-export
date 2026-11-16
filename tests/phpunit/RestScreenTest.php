<?php
/**
 * Tests for the routes added for the 2.0 screen: retry deliveries now,
 * reset a schedule's watermark, and the feed preset list.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/servers/helpers.php';

/**
 * Permissions, behavior and secrets of the new routes.
 */
class RestScreenTest extends TestCase {

	use OpexDeliveryFixtures;

	/** @var int[] */
	private $users = array();

	/** @var string[] */
	private $schedules = array();

	protected function setUp(): void {
		// Other tests remove the plugin's own route hooks in tearDown, so put back any that are missing.
		foreach ( array( 'OPEX_Rest', 'OPEX_Rest_Schedules', 'OPEX_Rest_Delivery' ) as $class ) {
			if ( false === has_action( 'rest_api_init', array( $class, 'register_routes' ) ) ) {
				add_action( 'rest_api_init', array( $class, 'register_routes' ) );
			}
		}
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
		wp_set_current_user( 1 );
	}

	protected function tearDown(): void {
		wp_set_current_user( 1 );
		remove_all_filters( 'pre_wp_mail' );
		foreach ( $this->made_jobs as $id ) {
			wp_clear_scheduled_hook( 'opex_deliver', array( $id ) );
		}
		$this->cleanup_jobs();
		foreach ( $this->schedules as $id ) {
			OPEX_Schedules::delete( $id );
		}
		$GLOBALS['wp_rest_server'] = null;
		require_once ABSPATH . 'wp-admin/includes/user.php';
		// On a network wp_delete_user only removes the user from this site, so the user would stay.
		if ( is_multisite() ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
		}
		foreach ( $this->users as $uid ) {
			is_multisite() ? wpmu_delete_user( $uid ) : wp_delete_user( $uid );
		}
	}

	/**
	 * Runs a request.
	 *
	 * @param string $method Method.
	 * @param string $path   Path below the namespace.
	 * @param array  $body   JSON body.
	 * @return WP_REST_Response
	 */
	private function call( string $method, string $path, array $body = array() ) {
		$query = '';
		if ( false !== strpos( $path, '?' ) ) {
			list( $path, $query ) = explode( '?', $path, 2 );
		}
		$req = new WP_REST_Request( $method, '/open-export/v1' . $path );
		if ( '' !== $query ) {
			parse_str( $query, $params );
			$req->set_query_params( $params );
		}
		if ( $body ) {
			$req->set_header( 'content-type', 'application/json' );
			$req->set_body( wp_json_encode( $body ) );
		}
		return rest_do_request( $req );
	}

	/**
	 * A done job with a REST-shaped id and the given deliveries.
	 *
	 * @param array $deliveries Delivery items.
	 * @param array $over       Other keys.
	 * @return array
	 */
	private function job( array $deliveries, array $over = array() ): array {
		$delivery = array(
			'email'   => array( 'enabled' => true, 'to' => array( 'a@example.com' ), 'mode' => 'link', 'subject' => '' ),
			'webhook' => array( 'enabled' => false, 'url' => 'https://example.com/hook', 'secret' => OPEX_Secrets::encrypt( 'HOOK-SECRET-998877' ) ),
		);
		return $this->make_job( array_merge( array( 'id' => 'j' . bin2hex( random_bytes( 6 ) ), 'delivery' => $delivery, 'deliveries' => $deliveries ), $over ) );
	}

	/**
	 * A saved schedule.
	 *
	 * @return array
	 */
	private function schedule(): array {
		$saved = OPEX_Schedules::save(
			array(
				'name'        => 'Nightly users',
				'config'      => array( 'source' => 'users', 'fields' => array( 'core:ID' ) ),
				'incremental' => array( 'enabled' => true, 'field' => 'core:user_registered' ),
				'delivery'    => array( 'webhook' => array( 'enabled' => true, 'url' => 'https://example.com/hook', 'secret' => 'HOOK-SECRET-998877' ) ),
			)
		);
		$this->assertIsArray( $saved );
		$this->schedules[] = $saved['id'];
		return $saved;
	}

	public function test_new_routes_deny_logged_out_and_subscriber(): void {
		$job    = $this->job( array() );
		$sched  = $this->schedule();
		$routes = array(
			array( 'POST', '/jobs/' . $job['id'] . '/deliver' ),
			array( 'POST', '/schedules/' . $sched['id'] . '/reset-watermark' ),
			array( 'GET', '/presets' ),
		);
		wp_set_current_user( 0 );
		foreach ( $routes as $r ) {
			$this->assertSame( 401, $this->call( $r[0], $r[1] )->get_status(), 'logged out ' . $r[1] );
		}
		$uid           = wp_insert_user( array( 'user_login' => 'opexsub' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
		$this->users[] = (int) $uid;
		wp_set_current_user( (int) $uid );
		foreach ( $routes as $r ) {
			$this->assertSame( 403, $this->call( $r[0], $r[1] )->get_status(), 'subscriber ' . $r[1] );
		}
	}

	public function test_deliver_retries_now_and_never_resends_sent(): void {
		$calls = 0;
		add_filter(
			'pre_wp_mail',
			static function () use ( &$calls ) {
				++$calls;
				return true;
			}
		);
		$now = time();
		$job = $this->job(
			array(
				array( 'channel' => 'email', 'status' => 'failed', 'attempts' => 1, 'message' => 'x', 'time' => $now ),
				array( 'channel' => 'email', 'status' => 'sent', 'attempts' => 1, 'message' => '', 'time' => $now - 50 ),
				array( 'channel' => 'email', 'status' => 'failed', 'attempts' => OPEX_Delivery::MAX_ATTEMPTS, 'message' => 'gave up', 'time' => $now ),
			)
		);
		$res = $this->call( 'POST', '/jobs/' . $job['id'] . '/deliver' );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$items = $res->get_data()['deliveries'];
		$this->assertSame( 1, $calls, 'only the failed item with attempts left was sent, without waiting for its retry time' );
		$this->assertSame( array( 'sent', 'sent', 'failed' ), array_column( $items, 'status' ) );
		$this->assertSame( array( 2, 1, OPEX_Delivery::MAX_ATTEMPTS ), array_column( $items, 'attempts' ) );
		$this->assertSame( $now - 50, $items[1]['time'], 'the sent item is untouched' );
		$this->assertSame( $items, OPEX_Jobs::expose( OPEX_Jobs::get( $job['id'] ) )['deliveries'], 'the result is saved' );

		// Nothing left to try: the route answers with the job and sends nothing.
		$again = $this->call( 'POST', '/jobs/' . $job['id'] . '/deliver' );
		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( 1, $calls );
		$this->assertStringNotContainsString( 'HOOK-SECRET', wp_json_encode( $again->get_data() ) );
		$this->assertStringNotContainsString( 'opex1:', wp_json_encode( $again->get_data() ) );
	}

	public function test_deliver_refuses_running_missing_and_locked_jobs(): void {
		$running = $this->job( array( array( 'channel' => 'email', 'status' => 'pending', 'attempts' => 0, 'message' => '', 'time' => 0 ) ), array( 'status' => 'running' ) );
		$res     = $this->call( 'POST', '/jobs/' . $running['id'] . '/deliver' );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'opex_not_done', $res->get_data()['code'] );

		$this->assertSame( 404, $this->call( 'POST', '/jobs/j000000000000/deliver' )->get_status() );

		$calls = 0;
		add_filter(
			'pre_wp_mail',
			static function () use ( &$calls ) {
				++$calls;
				return true;
			}
		);
		$done  = $this->job( array( array( 'channel' => 'email', 'status' => 'pending', 'attempts' => 0, 'message' => '', 'time' => 0 ) ) );
		$token = OPEX_Lock::acquire( 'deliver_' . $done['id'], 300 );
		$this->assertNotFalse( $token );
		try {
			$busy = $this->call( 'POST', '/jobs/' . $done['id'] . '/deliver' );
			$this->assertSame( 409, $busy->get_status(), 'the runner lock is respected' );
			$this->assertSame( 'opex_busy', $busy->get_data()['code'] );
			$this->assertSame( 0, $calls );
		} finally {
			OPEX_Lock::release( 'deliver_' . $done['id'], $token );
		}
		$this->assertSame( 200, $this->call( 'POST', '/jobs/' . $done['id'] . '/deliver' )->get_status() );
		$this->assertSame( 1, $calls );
	}

	public function test_reset_watermark(): void {
		$sched = $this->schedule();
		OPEX_Schedules::on_job_done( array( 'id' => 'jabc', 'schedule_id' => $sched['id'], 'status' => 'done', 'created' => time(), 'processed' => 3 ) );
		$this->assertNotSame( '', OPEX_Schedules::get( $sched['id'] )['watermark'] );

		$res = $this->call( 'POST', '/schedules/' . $sched['id'] . '/reset-watermark' );
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( '', $data['watermark'] );
		$this->assertSame( '', OPEX_Schedules::get( $sched['id'] )['watermark'] );
		$this->assertSame( $sched['trigger_key'], OPEX_Schedules::get( $sched['id'] )['trigger_key'], 'nothing else changes' );
		$this->assertArrayNotHasKey( 'trigger_key', $data );
		$this->assertArrayNotHasKey( 'secret', $data['delivery']['webhook'] );
		$json = wp_json_encode( $data );
		$this->assertStringNotContainsString( 'HOOK-SECRET', $json );
		$this->assertStringNotContainsString( 'opex1:', $json );
		$this->assertSame( 1, substr_count( $json, $sched['trigger_key'] ), 'the trigger key appears only inside trigger_url' );

		$this->assertSame( 404, $this->call( 'POST', '/schedules/s000000000000/reset-watermark' )->get_status() );
	}

	public function test_presets_route_shape(): void {
		$res = $this->call( 'GET', '/presets' );
		$this->assertSame( 200, $res->get_status() );
		$list = $res->get_data();
		if ( ! OPEX_Woo::is_active() ) {
			$this->assertSame( array(), $list );
			return;
		}
		$this->assertSame( array( 'google-merchant', 'meta-catalog', 'pinterest-catalog', 'woocommerce-product-csv' ), array_column( $list, 'id' ) );
		foreach ( $list as $preset ) {
			$this->assertSame( array( 'id', 'label', 'description', 'config' ), array_keys( $preset ) );
			$this->assertSame( 'woo:products', $preset['config']['source'] );
		}
	}

	public function test_new_routes_are_registered_with_the_shipped_ones(): void {
		$routes = rest_get_server()->get_routes( 'open-export/v1' );
		$this->assertArrayHasKey( '/open-export/v1/jobs/(?P<id>j[a-f0-9]{12})/deliver', $routes );
		$this->assertArrayHasKey( '/open-export/v1/schedules/(?P<id>s[a-f0-9]{12})/reset-watermark', $routes );
		$this->assertArrayHasKey( '/open-export/v1/presets', $routes );
	}
}
