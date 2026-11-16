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
}
