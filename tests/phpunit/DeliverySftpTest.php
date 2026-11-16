<?php
/**
 * Tests for OPEX_Delivery_Sftp against a throwaway asyncssh server.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/servers/helpers.php';

/**
 * SFTP channel.
 */
class DeliverySftpTest extends TestCase {

	use OpexDeliveryFixtures;

	/** @var OpexTestServer|null */
	private static $server = null;
	/** @var array */
	private static $keys = array();
	/** @var string */
	private static $skip = 'SFTP test tools are not available (set OPEX_TEST_TOOLS to a folder with venv/ containing asyncssh).';

	public static function setUpBeforeClass(): void {
		$py = OpexTestServer::python();
		if ( '' === $py ) {
			return;
		}
		$s       = new OpexTestServer();
		$s->dir  = OpexTestServer::temp_dir();
		mkdir( $s->dir . '/root/in', 0700, true );
		mkdir( $s->dir . '/keys', 0700 );
		$s->port = OpexTestServer::free_port();
		register_shutdown_function( array( $s, 'stop' ) );
		self::$server = $s;

		$auth = '';
		foreach ( array( 'ed25519' => array( 'ed25519', '' ), 'rsa' => array( 'rsa', '-b 2048 -m PEM' ) ) as $name => $spec ) {
			$f = $s->dir . '/keys/' . $name;
			exec( 'ssh-keygen -q -t ' . $spec[0] . ' ' . $spec[1] . ' -N "" -C opex-test -f ' . escapeshellarg( $f ) . ' 2>&1', $out, $rc );
			if ( 0 !== $rc ) {
				self::$skip   = 'ssh-keygen failed, cannot make test keys.';
				self::$server = null;
				$s->stop();
				return;
			}
			self::$keys[ $name ] = (string) file_get_contents( $f );
			$auth               .= file_get_contents( $f . '.pub' );
		}
		file_put_contents( $s->dir . '/authorized_keys', $auth );

		if ( ! $s->start( array( $py, dirname( __DIR__ ) . '/servers/sftp_server.py', $s->dir . '/root', (string) $s->port, 'tester', 'pw-good', $s->dir . '/authorized_keys' ) ) ) {
			self::$skip   = 'The throwaway asyncssh server did not start.';
			self::$server = null;
			$s->stop();
		}
	}

	public static function tearDownAfterClass(): void {
		if ( self::$server ) {
			self::$server->stop();
			self::$server = null;
		}
	}

	protected function setUp(): void {
		delete_option( 'opex_destinations' );
		if ( ! self::$server ) {
			$this->markTestSkipped( self::$skip );
		}
	}

	protected function tearDown(): void {
		if ( self::$server ) {
			foreach ( (array) glob( self::$server->dir . '/root/in/{,.}*', GLOB_BRACE ) as $f ) {
				is_file( $f ) && unlink( $f );
			}
		}
		$this->cleanup_jobs();
		delete_option( 'opex_destinations' );
	}

	private function dest( array $over = array() ): array {
		$rec = OPEX_Destinations::save( array_merge( array( 'name' => 't', 'type' => 'sftp', 'host' => '127.0.0.1', 'port' => self::$server->port, 'username' => 'tester', 'secret' => 'pw-good', 'path' => '/in' ), $over ) );
		$this->assertIsArray( $rec );
		return $rec;
	}

	private function listing(): array {
		return array_values( array_diff( scandir( self::$server->dir . '/root/in' ), array( '.', '..' ) ) );
	}

	public function test_password_upload() {
		$job = $this->make_job();
		$this->assertTrue( OPEX_Delivery_Sftp::send( $job, $this->dest(), OPEX_Jobs::file_path( $job ) ) );
		$this->assertSame( "id,name\n1,Ann\n2,Bob\n", file_get_contents( self::$server->dir . '/root/in/Orders-May.csv' ) );
		$this->assertSame( array( 'Orders-May.csv' ), $this->listing() );
		// Second send replaces it.
		$this->assertTrue( OPEX_Delivery_Sftp::send( $job, $this->dest(), OPEX_Jobs::file_path( $job ) ) );
	}

	public function test_key_login_for_openssh_and_pem_keys() {
		foreach ( array( 'ed25519', 'rsa' ) as $kind ) {
			$rec = $this->dest( array( 'auth' => 'key', 'secret' => self::$keys[ $kind ] ) );
			$res = OPEX_Destinations::test( $rec['id'] );
			$this->assertTrue( $res, $kind . ( is_wp_error( $res ) ? ' ' . $res->get_error_code() : '' ) );
		}
		$this->assertSame( array(), $this->listing() );
	}

	public function test_process_through_delivery() {
		$job      = $this->make_job();
		$rec      = $this->dest();
		$delivery = array( 'destination' => array( 'enabled' => true, 'id' => $rec['id'] ) );
		$job['deliveries'] = OPEX_Delivery::plan( $delivery );
		$list = OPEX_Delivery::process( $job, $delivery );
		$this->assertSame( 'sent', $list[0]['status'], $list[0]['message'] );
	}

	public function test_wrong_password_bad_key_missing_folder_and_dead_port() {
		$bad = $this->dest( array( 'secret' => 'wrong-pass' ) );
		$res = OPEX_Destinations::test( $bad['id'] );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertStringNotContainsString( 'wrong-pass', $res->get_error_message() );

		$garbage = $this->dest( array( 'auth' => 'key', 'secret' => "-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n-----END OPENSSH PRIVATE KEY-----" ) );
		$this->assertInstanceOf( WP_Error::class, OPEX_Destinations::test( $garbage['id'] ) );

		$nofolder = $this->dest( array( 'path' => '/nope' ) );
		$this->assertInstanceOf( WP_Error::class, OPEX_Destinations::test( $nofolder['id'] ) );

		$dead = $this->dest( array( 'port' => OpexTestServer::free_port() ) );
		$this->assertInstanceOf( WP_Error::class, OPEX_Destinations::test( $dead['id'] ) );
	}

	/**
	 * Starts a second throwaway server (new random host key) that logs auth steps.
	 *
	 * @param int $port Port to listen on.
	 * @return OpexTestServer
	 */
	private function second_server( int $port ): OpexTestServer {
		$s       = new OpexTestServer();
		$s->dir  = OpexTestServer::temp_dir();
		$s->port = $port;
		mkdir( $s->dir . '/root/in', 0700, true );
		copy( self::$server->dir . '/authorized_keys', $s->dir . '/authorized_keys' ); // The server refuses an empty file.
		$ok = $s->start( array( OpexTestServer::python(), dirname( __DIR__ ) . '/servers/sftp_server.py', $s->dir . '/root', (string) $port, 'tester', 'pw-good', $s->dir . '/authorized_keys', $s->dir . '/auth.log' ) );
		if ( ! $ok ) {
			$s->stop();
			$this->fail( 'The second SFTP server did not start.' );
		}
		return $s;
	}

	public function test_first_test_pins_and_later_connections_pass() {
		$rec = $this->dest();
		$this->assertSame( '', $rec['host_key'] );
		$this->assertTrue( OPEX_Destinations::test( $rec['id'] ) );
		$pinned = OPEX_Destinations::get( $rec['id'] )['host_key'];
		$this->assertMatchesRegularExpression( '/^SHA256:[A-Za-z0-9+\/]{43}$/', $pinned );
		$this->assertSame( $pinned, OPEX_Destinations::expose( OPEX_Destinations::get( $rec['id'] ) )['host_key'] );

		$this->assertTrue( OPEX_Destinations::test( $rec['id'] ) );
		$job = $this->make_job();
		$this->assertTrue( OPEX_Delivery_Sftp::send( $job, OPEX_Destinations::get( $rec['id'] ), OPEX_Jobs::file_path( $job ) ) );
		$this->assertSame( $pinned, OPEX_Destinations::get( $rec['id'] )['host_key'] );
	}

	public function test_fingerprint_matches_the_ssh_keyscan_value() {
		$rec = $this->dest();
		$this->assertTrue( OPEX_Destinations::test( $rec['id'] ) );
		$scan = (string) shell_exec( 'ssh-keyscan -t ed25519 -p ' . (int) self::$server->port . ' 127.0.0.1 2>/dev/null | ssh-keygen -lf - 2>/dev/null' );
		if ( 1 !== preg_match( '/(SHA256:\S+)/', $scan, $m ) ) {
			$this->markTestSkipped( 'ssh-keyscan did not answer.' );
		}
		$this->assertSame( $m[1], OPEX_Destinations::get( $rec['id'] )['host_key'] );
	}

	public function test_first_send_pins_when_nothing_is_stored() {
		$rec = $this->dest();
		$job = $this->make_job();
		$this->assertTrue( OPEX_Delivery_Sftp::send( $job, $rec, OPEX_Jobs::file_path( $job ) ) );
		$this->assertStringStartsWith( 'SHA256:', OPEX_Destinations::get( $rec['id'] )['host_key'] );
	}

	public function test_failed_first_connection_does_not_pin() {
		$rec = $this->dest( array( 'secret' => 'wrong-pass' ) );
		$this->assertWPError( OPEX_Destinations::test( $rec['id'] ) );
		$this->assertSame( '', OPEX_Destinations::get( $rec['id'] )['host_key'] );
	}

	public function test_a_different_server_on_the_same_port_is_refused_before_login() {
		$port  = OpexTestServer::free_port();
		$first = $this->second_server( $port );
		$rec   = $this->dest( array( 'port' => $port ) );
		$this->assertTrue( OPEX_Destinations::test( $rec['id'] ) );
		$pinned = OPEX_Destinations::get( $rec['id'] )['host_key'];
		$this->assertNotSame( '', $pinned );
		$this->assertStringContainsString( 'password tester', (string) file_get_contents( $first->dir . '/auth.log' ), 'The real server saw the login.' );
		$first->stop();

		$impostor = $this->second_server( $port );
		try {
			$res = OPEX_Destinations::test( $rec['id'] );
			$this->assertWPError( $res );
			$this->assertSame( 'opex_sftp_hostkey', $res->get_error_code() );
			$this->assertStringContainsString( 'identity has changed', $res->get_error_message() );
			$job = $this->make_job();
			$sent = OPEX_Delivery_Sftp::send( $job, OPEX_Destinations::get( $rec['id'] ), OPEX_Jobs::file_path( $job ) );
			$this->assertWPError( $sent );
			$this->assertSame( 'opex_sftp_hostkey', $sent->get_error_code() );
			$this->assertFileDoesNotExist( $impostor->dir . '/auth.log', 'The impostor saw an authentication step.' );
			$this->assertSame( array(), array_values( array_diff( scandir( $impostor->dir . '/root/in' ), array( '.', '..' ) ) ) );
			$this->assertSame( $pinned, OPEX_Destinations::get( $rec['id'] )['host_key'], 'The pin is not overwritten.' );
		} finally {
			$impostor->stop();
		}
	}

	public function test_changing_host_port_or_type_clears_the_pin_and_reset_does_too() {
		$rec = $this->dest();
		$this->assertTrue( OPEX_Destinations::test( $rec['id'] ) );
		$raw = array( 'name' => 't', 'type' => 'sftp', 'host' => '127.0.0.1', 'port' => self::$server->port, 'username' => 'tester', 'secret' => '', 'path' => '/in' );

		$same = OPEX_Destinations::save( $raw, $rec['id'] );
		$this->assertStringStartsWith( 'SHA256:', $same['host_key'], 'A rename keeps the pin.' );

		$moved = OPEX_Destinations::save( array_merge( $raw, array( 'host' => 'localhost' ) ), $rec['id'] );
		$this->assertSame( '', $moved['host_key'] );

		OPEX_Destinations::save( $raw, $rec['id'] );
		$this->assertTrue( OPEX_Destinations::test( $rec['id'] ) );
		$this->assertSame( '', OPEX_Destinations::save( array_merge( $raw, array( 'port' => 2222 ) ), $rec['id'] )['host_key'] );

		OPEX_Destinations::save( $raw, $rec['id'] );
		$this->assertTrue( OPEX_Destinations::test( $rec['id'] ) );
		$this->assertSame( '', OPEX_Destinations::save( array_merge( $raw, array( 'reset_host_key' => true ) ), $rec['id'] )['host_key'] );
	}

	private function assertWPError( $value ): void {
		$this->assertInstanceOf( WP_Error::class, $value );
	}
}
