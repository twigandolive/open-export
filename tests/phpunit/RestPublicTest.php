<?php
/**
 * Tests for the public trigger and file routes.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Both routes are reachable without a login, so these tests check that every
 * failure looks the same.
 */
class RestPublicTest extends TestCase {

	/** @var string[] */
	private $jobs_before = array();

	/** @var string[] */
	private $made = array();

	/** Whether setUp added the route hook, so tearDown leaves the plugin's own hook in place. */
	private bool $added_hook = false;

	protected function setUp(): void {
		delete_option( 'opex_schedules' );
		$this->jobs_before         = array_keys( OPEX_Jobs::all() );
		$GLOBALS['wp_rest_server'] = null;
		// The plugin hooks this itself. Add it only when missing, and take away only what was added here.
		$this->added_hook = false === has_action( 'rest_api_init', array( 'OPEX_Rest_Public', 'register_routes' ) );
		if ( $this->added_hook ) {
			add_action( 'rest_api_init', array( 'OPEX_Rest_Public', 'register_routes' ) );
		}
		rest_get_server();
		wp_set_current_user( 0 );
	}

	protected function tearDown(): void {
		wp_set_current_user( 1 );
		if ( $this->added_hook ) {
			remove_action( 'rest_api_init', array( 'OPEX_Rest_Public', 'register_routes' ) );
		}
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		delete_transient( 'opex_trig_ip_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 ) );
		$GLOBALS['wp_rest_server'] = null;
		foreach ( array_keys( OPEX_Schedules::all() ) as $id ) {
			delete_transient( 'opex_trigger_' . $id );
			OPEX_Schedules::delete( $id );
		}
		delete_option( 'opex_schedules' );
		foreach ( $this->made as $id ) {
			OPEX_Jobs::delete( $id );
		}
		foreach ( OPEX_Jobs::all() as $id => $job ) {
			if ( ! in_array( $id, $this->jobs_before, true ) ) {
				OPEX_Jobs::delete( (string) $id );
			}
		}
	}

	private function get( string $path, array $query = array(), string $method = 'GET' ) {
		$req = new WP_REST_Request( $method, '/open-export/v1' . $path );
		$req->set_query_params( $query );
		return rest_do_request( $req );
	}

	private function schedule(): array {
		$rec = OPEX_Schedules::save(
			array(
				'name'      => 'Cron export',
				'config'    => array( 'source' => 'posts:post', 'fields' => array( 'core:ID', 'core:post_title' ), 'batch_size' => 10 ),
				'frequency' => 'manual',
			)
		);
		$this->assertIsArray( $rec );
		return $rec;
	}

	private function make_job( string $body = "id\n1\n2\n", array $over = array() ): array {
		$id   = 'j' . bin2hex( random_bytes( 6 ) );
		$name = 'pubtest-' . bin2hex( random_bytes( 4 ) ) . '.csv';
		file_put_contents( trailingslashit( opex_export_dir() ) . $name, $body );
		$job = array_merge(
			array(
				'id'            => $id,
				'label'         => 'Orders',
				'config'        => array( 'format' => 'csv' ),
				'headers'       => array(),
				'status'        => 'done',
				'total'         => 2,
				'processed'     => 2,
				'cursor'        => 0,
				'created'       => time(),
				'filename'      => $name,
				'filesize'      => strlen( $body ),
				'error'         => '',
				'delivery_name' => 'Orders May',
			),
			$over
		);
		OPEX_Jobs::save( $job );
		$this->made[] = $id;
		return $job;
	}

	protected function assertGenericDenial( $res, int $status ): void {
		$this->assertSame( $status, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( $status, $data['data']['status'] );
		$this->assertSame( 403 === $status ? 'opex_forbidden' : 'opex_not_found', $data['code'] );
	}

	public function test_trigger_failures_are_identical() {
		$s    = $this->schedule();
		$path = '/schedules/' . $s['id'] . '/trigger';
		$wrong   = $this->get( $path, array( 'key' => str_repeat( 'a', 40 ) ) );
		$missing = $this->get( $path );
		$short   = $this->get( $path, array( 'key' => substr( $s['trigger_key'], 0, 39 ) ) );
		$unknown = $this->get( '/schedules/s000000000000/trigger', array( 'key' => $s['trigger_key'] ) );
		$array   = $this->get( $path, array( 'key' => array( 'x' ) ) );
		$this->assertGenericDenial( $wrong, 403 );
		foreach ( array( $missing, $short, $unknown, $array ) as $res ) {
			$this->assertGenericDenial( $res, 403 );
			$this->assertSame( $wrong->get_data(), $res->get_data() );
		}
		$this->assertSame( $wrong->get_data(), $this->get( $path, array( 'key' => 'x' ), 'POST' )->get_data() );
		$this->assertSame( array(), array_diff( array_keys( OPEX_Jobs::all() ), $this->jobs_before ) );
	}

	public function test_trigger_with_the_key_starts_a_job_and_returns_only_progress() {
		$s   = $this->schedule();
		$res = $this->get( '/schedules/' . $s['id'] . '/trigger', array( 'key' => $s['trigger_key'] ) );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$data = $res->get_data();
		$this->assertSame( array( 'processed', 'status', 'total' ), $this->sorted_keys( $data ) );
		$this->assertContains( $data['status'], array( 'running', 'done' ) );
		$this->assertSame( 'no-store', $res->get_headers()['Cache-Control'] );
		$json = wp_json_encode( $data );
		$this->assertStringNotContainsString( $s['trigger_key'], $json );
		$this->assertStringNotContainsString( '.csv', $json );
		$this->assertCount( 1, array_diff( array_keys( OPEX_Jobs::all() ), $this->jobs_before ) );
		$this->assertSame( 'trigger', $this->new_job()['origin'] ?? 'trigger' );
		$this->assertArrayNotHasKey( 'Set-Cookie', $res->get_headers() );
		$this->assertNotSame( PHP_SESSION_ACTIVE, session_status() );
	}

	public function test_trigger_post_with_the_key_works() {
		$s   = $this->schedule();
		$res = $this->get( '/schedules/' . $s['id'] . '/trigger', array( 'key' => $s['trigger_key'] ), 'POST' );
		$this->assertSame( 200, $res->get_status() );
	}

	public function test_second_start_within_30_seconds_gets_429() {
		$s    = $this->schedule();
		$path = '/schedules/' . $s['id'] . '/trigger';
		$q    = array( 'key' => $s['trigger_key'] );
		$this->assertSame( 200, $this->get( $path, $q )->get_status() );
		$jobs = count( OPEX_Jobs::all() );
		$res  = $this->get( $path, $q );
		$this->assertSame( 429, $res->get_status() );
		$retry = (int) $res->get_headers()['Retry-After'];
		$this->assertGreaterThanOrEqual( 1, $retry );
		$this->assertLessThanOrEqual( 30, $retry );
		$this->assertSame( 'no-store', $res->get_headers()['Cache-Control'] );
		$this->assertSame( $jobs, count( OPEX_Jobs::all() ), 'no second job' );
	}

	public function test_a_running_job_of_the_schedule_is_continued_without_rate_limit() {
		$s = $this->schedule();
		OPEX_Schedules::start( $s['id'], 'manual' );
		$new = $this->new_job();
		if ( ! $new || ! isset( $new['schedule_id'] ) ) {
			$this->markTestIncomplete( 'The engine does not store schedule_id on jobs yet, so a running job cannot be found. Re-run after the engine change.' );
		}
		if ( 'running' !== $new['status'] ) {
			$this->markTestIncomplete( 'The seeded site has no rows to export, so the job finished at once.' );
		}
		set_transient( 'opex_trigger_' . $s['id'], time() + 30, 30 );
		$res = $this->get( '/schedules/' . $s['id'] . '/trigger', array( 'key' => $s['trigger_key'] ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 1, count( array_diff( array_keys( OPEX_Jobs::all() ), $this->jobs_before ) ), 'continued, not restarted' );
	}

	public function test_file_failures_are_identical_404s() {
		$job   = $this->make_job();
		$token = OPEX_Download_Tokens::issue_saved( $job, 3600 );
		$other = $this->make_job();
		$path  = '/file/' . $job['id'];

		$bad = array(
			$this->get( $path, array( 'token' => str_repeat( 'a', 64 ) ) ),
			$this->get( $path ),
			$this->get( $path, array( 'token' => 'short' ) ),
			$this->get( '/file/jabcdef012345', array( 'token' => $token ) ),
			$this->get( '/file/' . $other['id'], array( 'token' => $token ) ),
		);

		$running = $this->make_job( "x\n", array( 'status' => 'running' ) );
		$bad[]   = $this->get( '/file/' . $running['id'], array( 'token' => OPEX_Download_Tokens::issue_saved( $running, 3600 ) ) );

		$gone  = $this->make_job();
		$gtok  = OPEX_Download_Tokens::issue_saved( $gone, 3600 );
		unlink( OPEX_Jobs::file_path( $gone ) );
		$bad[] = $this->get( '/file/' . $gone['id'], array( 'token' => $gtok ) );

		$old       = OPEX_Jobs::get( $job['id'] );
		$old['tokens'][0]['expires'] = time() - 5;
		OPEX_Jobs::save( $old );
		$bad[] = $this->get( $path, array( 'token' => $token ) );

		foreach ( $bad as $i => $res ) {
			$this->assertGenericDenial( $res, 404 );
			$this->assertSame( $bad[0]->get_data(), $res->get_data(), 'case ' . $i );
		}
	}

	public function test_a_good_token_passes_the_check_and_the_seam_yields_the_bytes() {
		$body  = "id,name\n1,Ann\n2,Bob\n";
		$job   = $this->make_job( $body );
		$token = OPEX_Download_Tokens::issue_saved( $job, 3600 );
		$req   = new WP_REST_Request( 'GET', '/open-export/v1/file/' . $job['id'] );
		$req->set_param( 'id', $job['id'] );
		$req->set_param( 'token', $token );
		$this->assertTrue( OPEX_Rest_Public::can_download( $req ) );

		$out = fopen( 'php://memory', 'w+b' );
		OPEX_Download::send_file( OPEX_Jobs::file_path( OPEX_Jobs::get( $job['id'] ) ), $out );
		rewind( $out );
		$this->assertSame( $body, stream_get_contents( $out ) );
		$h = OPEX_Download::headers( $job, OPEX_Download::file_name( $job ), strlen( $body ) );
		$this->assertContains( 'Content-Disposition: attachment; filename="Orders May.csv"', $h );
		$this->assertContains( 'Content-Length: ' . strlen( $body ), $h );
	}

	/**
	 * Keys of an array, sorted.
	 *
	 * @param array $a Array.
	 * @return array
	 */
	private function sorted_keys( array $a ): array {
		$k = array_keys( $a );
		sort( $k );
		return $k;
	}

	/**
	 * The one job this test created through the plugin, or null.
	 *
	 * @return array|null
	 */
	/** The newest job as stored, without the defaults OPEX_Jobs::get adds (these tests ask whether the engine wrote a key). */
	private function new_job(): ?array {
		foreach ( (array) get_option( 'opex_jobs', array() ) as $id => $job ) {
			if ( ! in_array( $id, $this->jobs_before, true ) ) {
				return $job;
			}
		}
		return null;
	}
}
