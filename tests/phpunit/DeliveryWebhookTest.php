<?php
/**
 * Tests for OPEX_Delivery_Webhook against a local `php -S` receiver.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/servers/helpers.php';

/**
 * Webhook channel.
 */
class DeliveryWebhookTest extends TestCase {

	use OpexDeliveryFixtures;

	/** @var OpexTestServer|null */
	private static $server = null;

	public static function setUpBeforeClass(): void {
		$s       = new OpexTestServer();
		$s->dir  = OpexTestServer::temp_dir();
		$s->port = OpexTestServer::free_port();
		register_shutdown_function( array( $s, 'stop' ) );
		$ok = $s->start(
			array( PHP_BINARY, '-S', '127.0.0.1:' . $s->port, dirname( __DIR__ ) . '/servers/webhook_receiver.php' ),
			array( 'OPEX_RECV_DIR' => $s->dir ),
			true
		);
		self::$server = $s;
		if ( ! $ok ) {
			$s->stop();
			self::$server = null;
		}
	}

	public static function tearDownAfterClass(): void {
		if ( self::$server ) {
			self::$server->stop();
			self::$server = null;
		}
	}

	protected function tearDown(): void {
		remove_all_filters( 'http_request_host_is_external' );
		remove_all_filters( 'http_allowed_safe_ports' );
		$this->cleanup_jobs();
		delete_option( 'opex_settings' );
		if ( self::$server && is_file( self::$server->dir . '/request.json' ) ) {
			unlink( self::$server->dir . '/request.json' );
		}
	}

	private function allow_loopback(): void {
		$port = self::$server->port;
		add_filter( 'http_request_host_is_external', '__return_true' );
		add_filter(
			'http_allowed_safe_ports',
			static function ( $ports ) use ( $port ) {
				$ports[] = $port;
				return $ports;
			}
		);
	}

	private function url( string $path = '/hook' ): string {
		return 'http://127.0.0.1:' . self::$server->port . $path;
	}

	private function recorded(): array {
		return json_decode( (string) file_get_contents( self::$server->dir . '/request.json' ), true );
	}

	public function test_loopback_is_refused_without_the_filter() {
		$job = $this->make_job();
		$res = OPEX_Delivery_Webhook::send( $job, array( 'url' => 'http://127.0.0.1:' . ( self::$server ? self::$server->port : 8123 ) . '/hook' ), OPEX_Jobs::file_path( $job ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_rejects_bad_schemes_and_urls() {
		$job = $this->make_job();
		foreach ( array( 'ftp://example.com/x', 'file:///etc/passwd', 'javascript:alert(1)', 'http://user:pw@example.com/x', '', 'not a url' ) as $url ) {
			$this->assertInstanceOf( WP_Error::class, OPEX_Delivery_Webhook::send( $job, array( 'url' => $url ), OPEX_Jobs::file_path( $job ) ), $url );
		}
	}

	public function test_stored_plain_http_to_another_host_is_refused_at_send_time() {
		$calls = 0;
		$block = static function ( $pre ) use ( &$calls ) {
			++$calls;
			return new WP_Error( 'opex_test_blocked', 'No real request in tests.' );
		};
		add_filter( 'pre_http_request', $block );
		try {
			$job = $this->make_job();
			// Stored before the rule existed, or edited in the database: the save check never saw it.
			// A documentation address, let through wp_http_validate_url without DNS, so only the https rule refuses it.
			add_filter( 'http_request_host_is_external', '__return_true' );
			$this->assertNotFalse( wp_http_validate_url( 'http://203.0.113.10/hook' ) );
			$res = OPEX_Delivery_Webhook::send( $job, array( 'url' => 'http://203.0.113.10/hook' ), OPEX_Jobs::file_path( $job ) );
			$this->assertInstanceOf( WP_Error::class, $res );
			$this->assertSame( 'opex_webhook_url', $res->get_error_code() );
			$this->assertStringContainsString( 'https', $res->get_error_message() );
			$this->assertSame( 0, $calls, 'nothing was sent' );
			$this->assertSame( array(), OPEX_Jobs::get( $job['id'] )['tokens'], 'no download link was issued' );

			// Through the delivery runner too: the item fails and nothing goes out.
			$delivery          = array( 'webhook' => array( 'enabled' => true, 'url' => 'http://203.0.113.10/hook', 'secret' => '' ) );
			$job['deliveries'] = OPEX_Delivery::plan( $delivery );
			$list              = OPEX_Delivery::process( $job, $delivery );
			$this->assertSame( 'failed', $list[0]['status'] );
			$this->assertStringContainsString( 'https', $list[0]['message'] );
			$this->assertSame( 0, $calls );

			// The same rule allows https and this site's own host.
			$this->assertTrue( OPEX_Delivery_Webhook::transport_allowed( 'https://example.com/hook' ) );
			$this->assertTrue( OPEX_Delivery_Webhook::transport_allowed( home_url( '/hook' ) ) );
			$this->assertFalse( OPEX_Delivery_Webhook::transport_allowed( 'http://example.com/hook' ) );
		} finally {
			remove_filter( 'pre_http_request', $block );
		}
	}

	public function test_signed_post_reaches_receiver() {
		if ( ! self::$server ) {
			$this->markTestSkipped( 'Could not start the local php -S receiver.' );
		}
		$this->allow_loopback();
		$job = $this->make_job( array( 'finished' => 1700000000 ) );
		$res = OPEX_Delivery_Webhook::send( $job, array( 'url' => $this->url(), 'secret' => OPEX_Secrets::encrypt( 's3cret-key' ) ), OPEX_Jobs::file_path( $job ) );
		$this->assertTrue( $res );

		$rec  = $this->recorded();
		$body = base64_decode( $rec['body_b64'] );
		$this->assertSame( 'POST', $rec['method'] );
		$this->assertStringContainsString( 'application/json', $rec['type'] );
		$this->assertSame( 'sha256=' . hash_hmac( 'sha256', $body, 's3cret-key' ), $rec['signature'] );
		$data = json_decode( $body, true );
		$this->assertSame( 'export.completed', $data['event'] );
		$this->assertSame( $job['id'], $data['job']['id'] );
		$this->assertSame( 2, $data['job']['rows'] );
		$this->assertSame( 1700000000, $data['job']['finished'] );
		$this->assertSame( home_url(), $data['site'] );
		$this->assertSame( 1, preg_match( '/token=([0-9a-f]{64})/', urldecode( $data['download_url'] ), $m ) );
		$this->assertTrue( OPEX_Download_Tokens::verify( OPEX_Jobs::get( $job['id'] ), $m[1] ) );
		$this->assertStringNotContainsString( 's3cret-key', $body );
	}

	public function test_500_is_a_failure_with_status_but_no_body() {
		if ( ! self::$server ) {
			$this->markTestSkipped( 'Could not start the local php -S receiver.' );
		}
		$this->allow_loopback();
		$job = $this->make_job();
		$res = OPEX_Delivery_Webhook::send( $job, array( 'url' => $this->url( '/fail' ) ), OPEX_Jobs::file_path( $job ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertStringContainsString( '500', $res->get_error_message() );
		$this->assertStringNotContainsString( 'secret-looking', $res->get_error_message() );
	}

	public function test_unreadable_secret_fails_instead_of_sending_unsigned() {
		if ( ! self::$server ) {
			$this->markTestSkipped( 'Could not start the local php -S receiver.' );
		}
		$this->allow_loopback();
		$job = $this->make_job();
		$res = OPEX_Delivery_Webhook::send( $job, array( 'url' => $this->url(), 'secret' => 'opex1:garbage' ), OPEX_Jobs::file_path( $job ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertFileDoesNotExist( self::$server->dir . '/request.json' );
	}

	public function test_redirect_is_a_failure_and_is_not_followed() {
		if ( ! self::$server ) {
			$this->markTestSkipped( 'Could not start the local php -S receiver.' );
		}
		$this->allow_loopback();
		$job = $this->make_job();
		$res = OPEX_Delivery_Webhook::send( $job, array( 'url' => $this->url( '/redirect' ) ), OPEX_Jobs::file_path( $job ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertStringContainsString( '302', $res->get_error_message() );
		// The receiver overwrites its record on every request, so a followed redirect would show /hook.
		$this->assertSame( '/redirect', $this->recorded()['path'] );
	}

	public function test_pin_is_the_address_the_check_saw() {
		$this->assertNull( OPEX_Delivery_Webhook::pin_for( 'http://127.0.0.1:8123/hook' ), 'An IP address needs no lookup, so nothing is pinned.' );
		$this->assertInstanceOf( WP_Error::class, OPEX_Delivery_Webhook::pin_for( 'https://localhost/hook' ), 'A private address needs the opt-in.' );
		$this->assertInstanceOf( WP_Error::class, OPEX_Delivery_Webhook::pin_for( 'https://opex-no-such-host.invalid/hook' ) );

		add_filter( 'http_request_host_is_external', '__return_true' );
		$this->assertSame(
			array(
				'host' => 'localhost',
				'port' => 443,
				'ip'   => '127.0.0.1',
			),
			OPEX_Delivery_Webhook::pin_for( 'https://localhost/hook' )
		);
		$this->assertSame( 8123, OPEX_Delivery_Webhook::pin_for( 'http://localhost:8123/hook' )['port'] );
	}

	public function test_a_host_name_send_is_pinned_for_that_request_only() {
		if ( ! self::$server ) {
			$this->markTestSkipped( 'Could not start the local php -S receiver.' );
		}
		if ( ! function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'The pin works through cURL, which is not installed here.' );
		}
		$this->allow_loopback();
		$url  = 'http://localhost:' . self::$server->port . '/hook';
		$seen = array();
		$spy  = static function ( $handle, $request, $request_url ) use ( &$seen ): void {
			$seen[] = $request_url;
		};
		$hooked = static function (): int {
			global $wp_filter;
			return isset( $wp_filter['http_api_curl'] ) ? count( $wp_filter['http_api_curl']->callbacks, COUNT_RECURSIVE ) : 0;
		};
		$before = $hooked();
		add_action( 'http_api_curl', $spy, 20, 3 );
		try {
			$job = $this->make_job();
			$res = OPEX_Delivery_Webhook::send( $job, array( 'url' => $url ), OPEX_Jobs::file_path( $job ) );
		} finally {
			remove_action( 'http_api_curl', $spy, 20 );
		}
		$after = $hooked();

		$this->assertTrue( $res );
		$this->assertSame( array( $url ), $seen, 'The request went through cURL, where the pin applies.' );
		$this->assertSame( '/hook', $this->recorded()['path'] );
		$this->assertSame( $before, $after, 'The pin hook is gone after the send.' );
	}
}
