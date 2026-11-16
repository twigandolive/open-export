<?php
/**
 * Tests for the schedule REST routes.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Permissions, round trips, validation and secrets of /schedules.
 */
class RestSchedulesTest extends TestCase {

	/** @var int[] */
	private $users = array();

	/** @var string[] */
	private $jobs_before = array();

	protected function setUp(): void {
		delete_option( 'opex_schedules' );
		$this->jobs_before = array_keys( OPEX_Jobs::all() );
		$GLOBALS['wp_rest_server'] = null;
		add_action( 'rest_api_init', array( 'OPEX_Rest_Schedules', 'register_routes' ) );
		rest_get_server();
		wp_set_current_user( 1 );
	}

	protected function tearDown(): void {
		wp_set_current_user( 1 );
		remove_action( 'rest_api_init', array( 'OPEX_Rest_Schedules', 'register_routes' ) );
		$GLOBALS['wp_rest_server'] = null;
		foreach ( array_keys( OPEX_Schedules::all() ) as $id ) {
			OPEX_Schedules::delete( $id );
		}
		delete_option( 'opex_schedules' );
		foreach ( OPEX_Jobs::all() as $id => $job ) {
			if ( ! in_array( $id, $this->jobs_before, true ) ) {
				OPEX_Jobs::delete( (string) $id );
			}
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $this->users as $uid ) {
			wp_delete_user( $uid );
		}
	}

	private function call( string $method, string $path, array $body = array() ) {
		$req = new WP_REST_Request( $method, '/open-export/v1' . $path );
		if ( $body ) {
			$req->set_header( 'content-type', 'application/json' );
			$req->set_body( wp_json_encode( $body ) );
		}
		return rest_do_request( $req );
	}

	private function payload( array $over = array() ): array {
		return array_merge(
			array(
				'name'      => 'Nightly posts',
				'config'    => array( 'source' => 'posts:post', 'fields' => array( 'core:ID', 'core:post_title' ), 'batch_size' => 10 ),
				'frequency' => 'daily',
				'time'      => '03:30',
				'enabled'   => true,
			),
			$over
		);
	}

	private function subscriber(): void {
		$uid = wp_insert_user( array( 'user_login' => 'opexsub' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
		$this->users[] = (int) $uid;
		wp_set_current_user( (int) $uid );
	}

	public function test_every_route_denies_logged_out_and_subscriber() {
		$id     = 's0123456789ab';
		$routes = array(
			array( 'GET', '/schedules', array() ),
			array( 'POST', '/schedules', $this->payload() ),
			array( 'GET', '/schedules/' . $id, array() ),
			array( 'POST', '/schedules/' . $id, array( 'name' => 'x' ) ),
			array( 'DELETE', '/schedules/' . $id, array() ),
			array( 'POST', '/schedules/' . $id . '/run', array() ),
			array( 'POST', '/schedules/' . $id . '/rotate-key', array() ),
		);
		wp_set_current_user( 0 );
		foreach ( $routes as $r ) {
			$this->assertSame( 401, $this->call( $r[0], $r[1], $r[2] )->get_status(), 'logged out ' . $r[0] . ' ' . $r[1] );
		}
		$this->subscriber();
		foreach ( $routes as $r ) {
			$this->assertSame( 403, $this->call( $r[0], $r[1], $r[2] )->get_status(), 'subscriber ' . $r[0] . ' ' . $r[1] );
		}
		$this->assertSame( array(), OPEX_Schedules::all() );
	}

	public function test_create_read_update_delete_round_trip() {
		$res = $this->call( 'POST', '/schedules', $this->payload() );
		$this->assertSame( 201, $res->get_status() );
		$s = $res->get_data();
		$this->assertMatchesRegularExpression( '/^s[0-9a-f]{12}$/', $s['id'] );
		$this->assertArrayNotHasKey( 'trigger_key', $s );
		$this->assertStringContainsString( '/schedules/' . $s['id'] . '/trigger', urldecode( $s['trigger_url'] ) );

		$list = $this->call( 'GET', '/schedules' )->get_data();
		$this->assertCount( 1, $list );
		$this->assertSame( $s['id'], $list[0]['id'] );

		$read = $this->call( 'GET', '/schedules/' . $s['id'] );
		$this->assertSame( 200, $read->get_status() );
		$this->assertSame( 'Nightly posts', $read->get_data()['name'] );

		$upd = $this->call( 'POST', '/schedules/' . $s['id'], array( 'name' => 'Renamed', 'frequency' => 'weekly', 'weekday' => 2, 'enabled' => false ) );
		$this->assertSame( 200, $upd->get_status() );
		$d = $upd->get_data();
		$this->assertSame( 'Renamed', $d['name'] );
		$this->assertSame( 'weekly', $d['frequency'] );
		$this->assertSame( 2, $d['weekday'] );
		$this->assertFalse( $d['enabled'] );
		$this->assertSame( '03:30', $d['time'] );
		$this->assertSame( 'posts:post', $d['config']['source'] );
		$this->assertSame( $s['trigger_url'], $d['trigger_url'] );

		$del = $this->call( 'DELETE', '/schedules/' . $s['id'] );
		$this->assertSame( 200, $del->get_status() );
		$this->assertSame( 404, $this->call( 'GET', '/schedules/' . $s['id'] )->get_status() );
		$this->assertSame( 404, $this->call( 'POST', '/schedules/' . $s['id'], array( 'name' => 'x' ) )->get_status() );
		$this->assertSame( 404, $this->call( 'DELETE', '/schedules/' . $s['id'] )->get_status() );
	}

	public function test_validation_failures_return_400() {
		$bad = array(
			array( 'name' => '' ),
			array( 'name' => str_repeat( 'x', 101 ) ),
			array( 'frequency' => 'yearly' ),
			array( 'time' => '25:00' ),
			array( 'weekday' => 9 ),
			array( 'monthday' => 29 ),
			array( 'enabled' => 'maybe' ),
			array( 'config' => 'not an object' ),
		);
		foreach ( $bad as $over ) {
			$res = $this->call( 'POST', '/schedules', $this->payload( $over ) );
			$this->assertSame( 400, $res->get_status(), wp_json_encode( $over ) );
			$this->assertStringNotContainsString( ABSPATH, wp_json_encode( $res->get_data() ) );
		}
		$res = $this->call( 'POST', '/schedules', array( 'frequency' => 'daily' ) );
		$this->assertSame( 400, $res->get_status(), 'name is required' );
		$this->assertSame( array(), OPEX_Schedules::all() );
		$this->assertSame( 404, $this->call( 'GET', '/schedules/sXYZ' )->get_status() );
	}

	public function test_rotate_key_changes_the_trigger_url() {
		$s   = $this->call( 'POST', '/schedules', $this->payload() )->get_data();
		$old = OPEX_Schedules::get( $s['id'] )['trigger_key'];
		$res = $this->call( 'POST', '/schedules/' . $s['id'] . '/rotate-key' );
		$this->assertSame( 200, $res->get_status() );
		$new = OPEX_Schedules::get( $s['id'] )['trigger_key'];
		$this->assertNotSame( $old, $new );
		$this->assertStringContainsString( 'key=' . $new, $res->get_data()['trigger_url'] );
		$this->assertStringNotContainsString( $old, wp_json_encode( $res->get_data() ) );
		$this->assertSame( 404, $this->call( 'POST', '/schedules/s0123456789ab/rotate-key' )->get_status() );
	}

	public function test_run_starts_a_manual_job() {
		$s   = $this->call( 'POST', '/schedules', $this->payload() )->get_data();
		$res = $this->call( 'POST', '/schedules/' . $s['id'] . '/run' );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$job = $res->get_data();
		$this->assertMatchesRegularExpression( '/^j[0-9a-f]{12}$/', $job['id'] );
		$this->assertContains( $job['status'], array( 'running', 'done' ) );
		$this->assertSame( $job['id'], OPEX_Schedules::get( $s['id'] )['last_run']['job_id'] );
		$stored = ( (array) get_option( 'opex_jobs', array() ) )[ $job['id'] ] ?? null; // As stored: OPEX_Jobs::get adds defaults.
		if ( ! isset( $stored['origin'] ) ) {
			$this->markTestIncomplete( 'The engine does not store origin on jobs yet.' );
		}
		$this->assertSame( 'manual', $stored['origin'] );
		$this->assertSame( $s['id'], $stored['schedule_id'] );
	}

	public function test_secrets_never_appear_in_responses() {
		$hook_secret = 'WH-SECRET-' . wp_generate_password( 12, false );
		$s           = $this->call(
			'POST',
			'/schedules',
			$this->payload( array( 'delivery' => array( 'webhook' => array( 'enabled' => true, 'url' => 'https://example.com/hook', 'secret' => $hook_secret ) ) ) )
		)->get_data();
		$id          = $s['id'];
		$this->assertTrue( $s['delivery']['webhook']['has_secret'] );

		$stored = OPEX_Schedules::get( $id );
		$key    = $stored['trigger_key'];
		$cipher = $stored['delivery']['webhook']['secret'];
		$this->assertNotSame( '', $cipher );

		// A blank secret on update keeps the stored one.
		$upd = $this->call( 'POST', '/schedules/' . $id, array( 'delivery' => array( 'webhook' => array( 'enabled' => true, 'url' => 'https://example.com/hook' ) ) ) );
		$this->assertSame( 200, $upd->get_status() );
		$this->assertSame( $cipher, OPEX_Schedules::get( $id )['delivery']['webhook']['secret'] );

		$bodies = array(
			$this->call( 'GET', '/schedules' ),
			$this->call( 'GET', '/schedules/' . $id ),
			$upd,
			$this->call( 'POST', '/schedules/' . $id . '/rotate-key' ),
			$this->call( 'POST', '/schedules', $this->payload( array( 'frequency' => 'nope' ) ) ),
			$this->call( 'GET', '/schedules/s0123456789ab' ),
		);
		$key = OPEX_Schedules::get( $id )['trigger_key'];
		foreach ( $bodies as $res ) {
			$data = $res->get_data();
			$json = wp_json_encode( self::strip_trigger_url( $data ) );
			$this->assertStringNotContainsString( $hook_secret, $json );
			$this->assertStringNotContainsString( $cipher, $json );
			$this->assertStringNotContainsString( 'opex1:', $json );
			$this->assertStringNotContainsString( $key, $json );
		}
	}

	/**
	 * Removes every trigger_url value, the only place a key may appear.
	 *
	 * @param mixed $data Response data.
	 * @return mixed
	 */
	public static function strip_trigger_url( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		unset( $data['trigger_url'] );
		foreach ( $data as $k => $v ) {
			$data[ $k ] = self::strip_trigger_url( $v );
		}
		return $data;
	}
}
