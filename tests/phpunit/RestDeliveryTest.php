<?php
/**
 * Tests for the destination, settings and options REST routes.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Permissions, round trips, validation and secrets.
 */
class RestDeliveryTest extends TestCase {

	/** @var int[] */
	private $users = array();

	/** @var mixed */
	private $settings_before;

	protected function setUp(): void {
		delete_option( 'opex_destinations' );
		$this->settings_before = get_option( 'opex_settings', null );
		$GLOBALS['wp_rest_server'] = null;
		add_action( 'rest_api_init', array( 'OPEX_Rest_Delivery', 'register_routes' ) );
		rest_get_server();
		wp_set_current_user( 1 );
	}

	protected function tearDown(): void {
		wp_set_current_user( 1 );
		remove_action( 'rest_api_init', array( 'OPEX_Rest_Delivery', 'register_routes' ) );
		$GLOBALS['wp_rest_server'] = null;
		delete_option( 'opex_destinations' );
		if ( null === $this->settings_before ) {
			delete_option( 'opex_settings' );
		} else {
			update_option( 'opex_settings', $this->settings_before, false );
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

	private function dest( array $over = array() ): array {
		return array_merge(
			array(
				'name'     => 'Backup box',
				'type'     => 'ftp',
				'host'     => '127.0.0.1',
				'port'     => 1,
				'username' => 'uploader',
				'auth'     => 'password',
				'secret'   => 'PW-SECRET-12345',
				'path'     => '/exports',
			),
			$over
		);
	}

	public function test_every_route_denies_logged_out_and_subscriber() {
		$id     = 'd0123456789ab';
		$routes = array(
			array( 'GET', '/destinations', array() ),
			array( 'POST', '/destinations', $this->dest() ),
			array( 'POST', '/destinations/' . $id, array( 'name' => 'x' ) ),
			array( 'DELETE', '/destinations/' . $id, array() ),
			array( 'POST', '/destinations/' . $id . '/test', array() ),
			array( 'GET', '/settings', array() ),
			array( 'POST', '/settings', array( 'link_days' => 3 ) ),
			array( 'GET', '/options', array() ),
		);
		wp_set_current_user( 0 );
		foreach ( $routes as $r ) {
			$this->assertSame( 401, $this->call( $r[0], $r[1], $r[2] )->get_status(), 'logged out ' . $r[0] . ' ' . $r[1] );
		}
		$uid           = wp_insert_user( array( 'user_login' => 'opexsub' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
		$this->users[] = (int) $uid;
		wp_set_current_user( (int) $uid );
		foreach ( $routes as $r ) {
			$this->assertSame( 403, $this->call( $r[0], $r[1], $r[2] )->get_status(), 'subscriber ' . $r[0] . ' ' . $r[1] );
		}
		$this->assertSame( array(), OPEX_Destinations::all() );
	}

	public function test_destination_round_trip() {
		$res = $this->call( 'POST', '/destinations', $this->dest() );
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$d = $res->get_data();
		$this->assertMatchesRegularExpression( '/^d[0-9a-f]{12}$/', $d['id'] );
		$this->assertTrue( $d['has_secret'] );
		$this->assertArrayNotHasKey( 'secret', $d );

		$list = $this->call( 'GET', '/destinations' )->get_data();
		$this->assertCount( 1, $list );

		$stored = OPEX_Destinations::get( $d['id'] )['secret'];
		$upd    = $this->call( 'POST', '/destinations/' . $d['id'], array( 'name' => 'Renamed', 'host' => 'example.org', 'secret' => '' ) );
		$this->assertSame( 200, $upd->get_status(), wp_json_encode( $upd->get_data() ) );
		$this->assertSame( 'Renamed', $upd->get_data()['name'] );
		$this->assertSame( 'example.org', $upd->get_data()['host'] );
		$this->assertSame( 'uploader', $upd->get_data()['username'] );
		$this->assertSame( $stored, OPEX_Destinations::get( $d['id'] )['secret'], 'blank secret keeps the stored one' );

		$this->assertSame( 200, $this->call( 'DELETE', '/destinations/' . $d['id'] )->get_status() );
		$this->assertSame( array(), OPEX_Destinations::all() );
		$this->assertSame( 404, $this->call( 'DELETE', '/destinations/' . $d['id'] )->get_status() );
		$this->assertSame( 404, $this->call( 'POST', '/destinations/' . $d['id'], array( 'name' => 'x' ) )->get_status() );
		$this->assertSame( 404, $this->call( 'POST', '/destinations/' . $d['id'] . '/test' )->get_status() );
	}

	public function test_destination_validation_failures_return_400() {
		$bad = array(
			array( 'type' => 'gopher' ),
			array( 'host' => 'ftp://evil.example/path' ),
			array( 'host' => '' ),
			array( 'port' => 70000 ),
			array( 'username' => '' ),
			array( 'path' => '/a/../b' ),
			array( 'secret' => '' ),
			array( 'auth' => 'key' ),
			array( 'passive' => 'perhaps' ),
		);
		foreach ( $bad as $over ) {
			$res = $this->call( 'POST', '/destinations', $this->dest( $over ) );
			$this->assertSame( 400, $res->get_status(), wp_json_encode( $over ) . ' ' . wp_json_encode( $res->get_data() ) );
		}
		$this->assertSame( array(), OPEX_Destinations::all() );
	}

	public function test_settings_round_trip_and_validation() {
		$def = $this->call( 'GET', '/settings' )->get_data();
		$this->assertArrayHasKey( 'retention_days', $def );
		$res = $this->call( 'POST', '/settings', array( 'retention_days' => 60, 'link_days' => 3, 'background' => true ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( 'retention_days' => 60, 'link_days' => 3, 'background' => true ), $res->get_data() );
		$this->assertSame( 60, OPEX_Settings::get( 'retention_days' ) );
		$part = $this->call( 'POST', '/settings', array( 'link_days' => 5 ) )->get_data();
		$this->assertSame( 60, $part['retention_days'] );
		foreach ( array( array( 'retention_days' => 0 ), array( 'retention_days' => 366 ), array( 'link_days' => 31 ), array( 'link_days' => 'abc' ), array( 'background' => 'maybe' ) ) as $over ) {
			$this->assertSame( 400, $this->call( 'POST', '/settings', $over )->get_status(), wp_json_encode( $over ) );
		}
		$this->assertSame( 60, OPEX_Settings::get( 'retention_days' ) );
	}

	public function test_options_shape() {
		$res = $this->call( 'GET', '/options' );
		$this->assertSame( 200, $res->get_status() );
		$o = $res->get_data();
		foreach ( array( 'formats', 'transforms', 'csv_encodings', 'csv_eols', 'frequencies', 'weekdays', 'timezone', 'features' ) as $key ) {
			$this->assertArrayHasKey( $key, $o );
		}
		$this->assertArrayHasKey( 'xlsx', $o['formats'] );
		$this->assertCount( 7, $o['weekdays'] );
		$this->assertSame( wp_timezone_string(), $o['timezone'] );
		$this->assertSame( array( 'multisite', 'woocommerce', 'ftp', 'sodium', 'zip', 'cron_disabled' ), array_keys( $o['features'] ) );
		foreach ( $o['features'] as $flag ) {
			$this->assertIsBool( $flag );
		}
		$ids = wp_list_pluck( $o['csv_encodings'], 'id' );
		$this->assertSame( array( 'utf8_bom', 'utf8', 'win1252', 'utf16le' ), $ids );
		$this->assertSame( array( 'manual', 'hourly', 'daily', 'weekly', 'monthly' ), wp_list_pluck( $o['frequencies'], 'id' ) );
	}

	public function test_secrets_never_appear_in_responses() {
		$key  = "-----BEGIN OPENSSH PRIVATE KEY-----\nPRIVKEYBODY-" . wp_generate_password( 20, false ) . "\n-----END OPENSSH PRIVATE KEY-----";
		$pw   = 'PW-SECRET-12345';
		$a    = $this->call( 'POST', '/destinations', $this->dest( array( 'secret' => $pw ) ) );
		$b    = $this->call( 'POST', '/destinations', $this->dest( array( 'type' => 'sftp', 'auth' => 'key', 'secret' => $key, 'name' => 'Key box' ) ) );
		$this->assertSame( 201, $a->get_status() );
		$this->assertSame( 201, $b->get_status(), wp_json_encode( $b->get_data() ) );
		$id   = $a->get_data()['id'];
		$all  = array(
			$a,
			$b,
			$this->call( 'GET', '/destinations' ),
			$this->call( 'POST', '/destinations/' . $id, array( 'name' => 'n', 'secret' => $pw . 'x' ) ),
			$this->call( 'POST', '/destinations/' . $id . '/test' ),
			$this->call( 'POST', '/destinations', $this->dest( array( 'type' => 'bad' ) ) ),
			$this->call( 'GET', '/settings' ),
			$this->call( 'GET', '/options' ),
		);
		$secrets = array( $pw, $pw . 'x', 'PRIVKEYBODY', 'opex1:' );
		foreach ( OPEX_Destinations::all() as $rec ) {
			$secrets[] = $rec['secret'];
		}
		foreach ( $all as $res ) {
			$json = wp_json_encode( $res->get_data() );
			foreach ( $secrets as $s ) {
				$this->assertStringNotContainsString( $s, $json );
			}
		}
	}
}
