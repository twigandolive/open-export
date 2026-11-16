<?php
/**
 * A partial destination update sends only the submitted
 * fields to the store, which merges them with the record as stored at that
 * moment, under its lock. A change another request saved in between is kept.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * POST /destinations/<id> with some fields.
 */
class CodexDeliveryRestUpdateTest extends TestCase {

	/** Whether setUp added the route hook. */
	private bool $added_hook = false;

	/** @var callable|null */
	private $filter = null;

	protected function setUp(): void {
		delete_option( 'opex_destinations' );
		$GLOBALS['wp_rest_server'] = null;
		$this->added_hook          = false === has_action( 'rest_api_init', array( 'OPEX_Rest_Delivery', 'register_routes' ) );
		if ( $this->added_hook ) {
			add_action( 'rest_api_init', array( 'OPEX_Rest_Delivery', 'register_routes' ) );
		}
		rest_get_server();
		wp_set_current_user( 1 );
	}

	protected function tearDown(): void {
		if ( $this->filter ) {
			remove_filter( 'option_opex_destinations', $this->filter );
			$this->filter = null;
		}
		if ( $this->added_hook ) {
			remove_action( 'rest_api_init', array( 'OPEX_Rest_Delivery', 'register_routes' ) );
		}
		$GLOBALS['wp_rest_server'] = null;
		delete_option( 'opex_destinations' );
	}

	/**
	 * Sends a request.
	 *
	 * @param string $path Route under the namespace.
	 * @param array  $body JSON body.
	 * @return WP_REST_Response
	 */
	private function post( string $path, array $body ): WP_REST_Response {
		$req = new WP_REST_Request( 'POST', '/open-export/v1' . $path );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
		return rest_do_request( $req );
	}

	/**
	 * Creates a destination through the store.
	 *
	 * @return array The record.
	 */
	private function make(): array {
		$rec = OPEX_Destinations::save(
			array(
				'name'     => 'Box',
				'type'     => 'sftp',
				'host'     => 'one.example.com',
				'port'     => 22,
				'username' => 'u',
				'secret'   => 'pw-1',
				'path'     => '/in',
			)
		);
		$this->assertIsArray( $rec );
		return $rec;
	}

	public function test_a_path_only_update_keeps_a_host_saved_in_between() {
		$rec = $this->make();
		// Another request saves a new host right after this request first reads the record.
		$this->filter = function ( $value ) use ( $rec ) {
			remove_filter( 'option_opex_destinations', $this->filter );
			$this->filter = null;
			$this->assertIsArray( OPEX_Destinations::save( array( 'type' => 'sftp', 'host' => 'two.example.com', 'port' => 22, 'username' => 'u', 'secret' => '', 'path' => '/in', 'name' => 'Box' ), $rec['id'] ) );
			return $value; // This read still sees the record as it was before that save.
		};
		add_filter( 'option_opex_destinations', $this->filter );

		$res = $this->post( '/destinations/' . $rec['id'], array( 'path' => '/out' ) );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertNull( $this->filter, 'the concurrent save ran' );
		$now = OPEX_Destinations::get( $rec['id'] );
		$this->assertSame( 'two.example.com', $now['host'], 'the host saved in between is kept' );
		$this->assertSame( '/out', $now['path'] );
		$this->assertSame( 'two.example.com', $res->get_data()['host'] );
	}

	public function test_blank_secret_reset_host_key_and_last_test_still_work() {
		$rec = $this->make();
		OPEX_Destinations::pin_host_key( $rec['id'], 'SHA256:' . str_repeat( 'A', 43 ) );
		OPEX_Destinations::record_test( $rec['id'], true, 'The connection works.' );
		$secret = OPEX_Destinations::get( $rec['id'] )['secret'];

		$res = $this->post( '/destinations/' . $rec['id'], array( 'name' => 'Renamed', 'secret' => '' ) );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$now = OPEX_Destinations::get( $rec['id'] );
		$this->assertSame( 'Renamed', $now['name'] );
		$this->assertSame( $secret, $now['secret'], 'a blank secret keeps the stored one' );
		$this->assertSame( 'SHA256:' . str_repeat( 'A', 43 ), $now['host_key'] );
		$this->assertTrue( $now['last_test']['ok'], 'a name change keeps the last test' );
		$this->assertArrayNotHasKey( 'secret', $res->get_data() );

		$res = $this->post( '/destinations/' . $rec['id'], array( 'reset_host_key' => true ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( '', OPEX_Destinations::get( $rec['id'] )['host_key'] );
	}

	public function test_unknown_and_deleted_ids_are_404() {
		$res = $this->post( '/destinations/d000000000000', array( 'path' => '/x' ) );
		$this->assertSame( 404, $res->get_status() );
		$this->assertWPErrorCode( 'opex_destination_missing', OPEX_Destinations::update( 'd000000000000', array( 'path' => '/x' ) ) );
	}

	/**
	 * Asserts a WP_Error with a code.
	 *
	 * @param string $code  Code.
	 * @param mixed  $value Value.
	 */
	private function assertWPErrorCode( string $code, $value ): void {
		$this->assertInstanceOf( WP_Error::class, $value );
		$this->assertSame( $code, $value->get_error_code() );
	}
}
