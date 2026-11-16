<?php
/**
 * Tests for OPEX_Delivery_Ftp against a throwaway pyftpdlib server.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/servers/helpers.php';

/**
 * FTP channel.
 */
class DeliveryFtpTest extends TestCase {

	use OpexDeliveryFixtures;

	/** @var OpexTestServer|null */
	private static $server = null;

	public static function setUpBeforeClass(): void {
		$py = OpexTestServer::python();
		if ( '' === $py || ! function_exists( 'ftp_connect' ) ) {
			return;
		}
		$s       = new OpexTestServer();
		$s->dir  = OpexTestServer::temp_dir();
		mkdir( $s->dir . '/in' );
		$s->port = OpexTestServer::free_port();
		register_shutdown_function( array( $s, 'stop' ) );
		self::$server = $s;
		if ( ! $s->start( array( $py, dirname( __DIR__ ) . '/servers/ftp_server.py', $s->dir, (string) $s->port, 'tester', 'pw-good' ) ) ) {
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

	protected function setUp(): void {
		delete_option( 'opex_destinations' );
		if ( ! function_exists( 'ftp_connect' ) ) {
			$this->markTestSkipped( 'The PHP ftp extension is not installed.' );
		}
		if ( ! self::$server ) {
			$this->markTestSkipped( 'pyftpdlib is not available (set OPEX_TEST_TOOLS to a folder with venv/ containing pyftpdlib).' );
		}
	}

	protected function tearDown(): void {
		if ( self::$server ) {
			foreach ( (array) glob( self::$server->dir . '/in/{,.}*', GLOB_BRACE ) as $f ) {
				is_file( $f ) && unlink( $f );
			}
		}
		$this->cleanup_jobs();
		delete_option( 'opex_destinations' );
	}

	private function dest( array $over = array() ): array {
		$rec = OPEX_Destinations::save( array_merge( array( 'name' => 't', 'type' => 'ftp', 'host' => '127.0.0.1', 'port' => self::$server->port, 'username' => 'tester', 'secret' => 'pw-good', 'path' => '/in' ), $over ) );
		$this->assertIsArray( $rec );
		return $rec;
	}

	public function test_upload_real_file() {
		$job = $this->make_job();
		$rec = $this->dest();
		$this->assertTrue( OPEX_Delivery_Ftp::send( $job, $rec, OPEX_Jobs::file_path( $job ) ) );
		$this->assertSame( "id,name\n1,Ann\n2,Bob\n", file_get_contents( self::$server->dir . '/in/Orders-May.csv' ) );
		$this->assertSame( array( 'Orders-May.csv' ), array_values( array_diff( scandir( self::$server->dir . '/in' ), array( '.', '..' ) ) ) );
		// A second run replaces the file.
		$this->assertTrue( OPEX_Delivery_Ftp::send( $job, $rec, OPEX_Jobs::file_path( $job ) ) );
	}

	public function test_process_through_delivery() {
		$job      = $this->make_job();
		$rec      = $this->dest();
		$delivery = array( 'destination' => array( 'enabled' => true, 'id' => $rec['id'] ) );
		$job['deliveries'] = OPEX_Delivery::plan( $delivery );
		$list = OPEX_Delivery::process( $job, $delivery );
		$this->assertSame( 'sent', $list[0]['status'], $list[0]['message'] );
	}

	public function test_destination_test_passes_and_leaves_no_file() {
		$rec = $this->dest();
		$res = OPEX_Destinations::test( $rec['id'] );
		$this->assertTrue( $res, is_wp_error( $res ) ? $res->get_error_code() : '' );
		$this->assertSame( array(), array_values( array_diff( scandir( self::$server->dir . '/in' ), array( '.', '..' ) ) ) );
	}

	public function test_wrong_password_missing_folder_and_dead_port() {
		$bad = $this->dest( array( 'secret' => 'nope' ) );
		$res = OPEX_Destinations::test( $bad['id'] );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertStringNotContainsString( 'nope', $res->get_error_message() );
		$this->assertStringNotContainsString( 'pw-good', $res->get_error_message() );

		$nofolder = $this->dest( array( 'path' => '/does-not-exist' ) );
		$this->assertInstanceOf( WP_Error::class, OPEX_Destinations::test( $nofolder['id'] ) );

		$dead = $this->dest( array( 'port' => OpexTestServer::free_port() ) );
		$this->assertInstanceOf( WP_Error::class, OPEX_Destinations::test( $dead['id'] ) );
	}

	public function test_unreadable_stored_password_fails() {
		$rec           = $this->dest();
		$rec['secret'] = 'opex1:garbage';
		$res           = OPEX_Delivery_Ftp::test( $rec );
		$this->assertInstanceOf( WP_Error::class, $res );
	}
}
