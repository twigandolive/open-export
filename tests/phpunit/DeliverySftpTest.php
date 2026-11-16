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
}
