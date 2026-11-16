<?php
/**
 * I-2: a long FTP or SFTP upload renews the delivery lock while it runs, and
 * stops when the lock is lost, removing only its own partial upload.
 *
 * The lock is played by the step callback of OPEX_Delivery::process(), the
 * same seam OPEX_Runner uses: keep_alive() reaches it as `$step( -1, null )`.
 * The servers are the plain tests/servers scripts.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/servers/helpers.php';

/**
 * Keep-alive during FTP and SFTP transfers.
 */
class CodexIntegrationTransferTest extends TestCase {

	use OpexDeliveryFixtures;

	/** @var OpexTestServer|null */
	private $server = null;

	/** @var string Folder the server writes into. */
	private $folder = '';

	protected function setUp(): void {
		delete_option( 'opex_destinations' );
		if ( '' === OpexTestServer::python() ) {
			$this->markTestSkipped( 'Delivery test tools are not available (set OPEX_TEST_TOOLS to a folder with venv/ containing pyftpdlib and asyncssh).' );
		}
		$this->set_static( OPEX_Delivery::class, 'beat', 0.0 );
	}

	protected function tearDown(): void {
		$this->set_static( OPEX_Delivery::class, 'beat', 5.0 );
		if ( $this->server ) {
			$this->server->stop();
			$this->server = null;
		}
		$this->cleanup_jobs();
		delete_option( 'opex_destinations' );
	}

	/**
	 * Sets a private static property.
	 *
	 * @param string $class Class name.
	 * @param string $name  Property name.
	 * @param mixed  $value New value.
	 */
	private function set_static( string $class, string $name, $value ): void {
		if ( ! property_exists( $class, $name ) ) {
			return; // Code from before the fix, run to see these tests fail.
		}
		$prop = new ReflectionProperty( $class, $name );
		if ( PHP_VERSION_ID < 80100 ) {
			$prop->setAccessible( true );
		}
		$prop->setValue( null, $value );
	}

	/**
	 * A done job whose file is large enough to need many transfer steps.
	 *
	 * @param int $bytes File size.
	 * @return array The job.
	 */
	private function big_job( int $bytes ): array {
		$job  = $this->make_job();
		$path = OPEX_Jobs::file_path( $job );
		file_put_contents( $path, str_repeat( "1,abcdefghijklmnopqrstuvwxyz0123456789\n", (int) ceil( $bytes / 39 ) ) );
		return $job;
	}

	/** Starts the plain FTP server. */
	private function start_ftp(): void {
		if ( ! function_exists( 'ftp_connect' ) ) {
			$this->markTestSkipped( 'The PHP ftp extension is not installed.' );
		}
		$s       = new OpexTestServer();
		$s->dir  = OpexTestServer::temp_dir();
		$s->port = OpexTestServer::free_port();
		mkdir( $s->dir . '/in' );
		$this->server = $s;
		if ( ! $s->start( array( OpexTestServer::python(), dirname( __DIR__ ) . '/servers/ftp_server.py', $s->dir, (string) $s->port, 'tester', 'pw-good' ) ) ) {
			$this->markTestSkipped( 'Could not start the FTP server.' );
		}
		$this->folder = $s->dir . '/in';
	}

	/** Starts the plain SFTP server. */
	private function start_sftp(): void {
		$s       = new OpexTestServer();
		$s->dir  = OpexTestServer::temp_dir();
		$s->port = OpexTestServer::free_port();
		mkdir( $s->dir . '/root/in', 0700, true );
		$this->server = $s;
		exec( 'ssh-keygen -q -t ed25519 -N "" -C opex-test -f ' . escapeshellarg( $s->dir . '/k' ) . ' 2>&1', $out, $rc );
		if ( 0 !== $rc ) {
			$this->markTestSkipped( 'ssh-keygen failed, cannot make a test key.' );
		}
		rename( $s->dir . '/k.pub', $s->dir . '/authorized_keys' );
		if ( ! $s->start( array( OpexTestServer::python(), dirname( __DIR__ ) . '/servers/sftp_server.py', $s->dir . '/root', (string) $s->port, 'tester', 'pw-good', $s->dir . '/authorized_keys' ) ) ) {
			$this->markTestSkipped( 'Could not start the SFTP server.' );
		}
		$this->folder = $s->dir . '/root/in';
	}

	/**
	 * Saves a destination on the running server.
	 *
	 * @param string $type ftp or sftp.
	 * @return array The record.
	 */
	private function dest( string $type ): array {
		$rec = OPEX_Destinations::save( array( 'name' => 't', 'type' => $type, 'host' => '127.0.0.1', 'port' => $this->server->port, 'username' => 'tester', 'secret' => 'pw-good', 'path' => '/in' ) );
		$this->assertIsArray( $rec );
		return $rec;
	}

	/**
	 * Delivers a job to the destination with a step callback that answers
	 * keep-alive calls from $alive.
	 *
	 * @param array    $job   Job.
	 * @param array    $rec   Destination.
	 * @param callable $alive Gets the number of the keep-alive call (from 1), returns whether the lock still holds.
	 * @param int      $calls Set to the number of keep-alive calls.
	 * @return array The delivery item after the run.
	 */
	private function deliver( array $job, array $rec, callable $alive, int &$calls ): array {
		$calls = 0;
		$step  = static function ( int $i, ?array $item ) use ( $alive, &$calls ): bool {
			if ( $i >= 0 ) {
				return true;
			}
			++$calls;
			return (bool) $alive( $calls );
		};
		$delivery = array(
			'destination' => array(
				'enabled' => true,
				'id'      => $rec['id'],
			),
		);
		$items = OPEX_Delivery::process( $job, $delivery, true, $step );
		$this->assertCount( 1, $items );
		return $items[0];
	}

	/**
	 * Files in the server's upload folder, hidden ones included.
	 *
	 * @return string[]
	 */
	private function listing(): array {
		return array_values( array_diff( scandir( $this->folder ), array( '.', '..' ) ) );
	}

	/**
	 * The two channels.
	 *
	 * @return array
	 */
	public static function channels(): array {
		return array(
			'ftp'  => array( 'ftp' ),
			'sftp' => array( 'sftp' ),
		);
	}

	/**
	 * Starts the server of a channel.
	 *
	 * @param string $type ftp or sftp.
	 */
	private function start( string $type ): void {
		'ftp' === $type ? $this->start_ftp() : $this->start_sftp();
	}

	/**
	 * @dataProvider channels
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'channels' )]
	public function test_a_long_upload_renews_the_lock_while_it_runs( string $type ): void {
		$this->start( $type );
		$job   = $this->big_job( 2 * 1024 * 1024 );
		$calls = 0;
		$item  = $this->deliver( $job, $this->dest( $type ), '__return_true', $calls );
		$this->assertSame( 'sent', $item['status'], (string) $item['message'] );
		$this->assertGreaterThan( 3, $calls, 'The lock is renewed during the transfer, not only between channels.' );
		$this->assertSame( array( 'Orders-May.csv' ), $this->listing() );
		$this->assertSame( filesize( OPEX_Jobs::file_path( $job ) ), filesize( $this->folder . '/Orders-May.csv' ) );
	}

	/**
	 * @dataProvider channels
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'channels' )]
	public function test_a_lost_lock_stops_the_upload_and_removes_only_its_own_part( string $type ): void {
		$this->start( $type );
		// A file another run put there earlier stays untouched.
		file_put_contents( $this->folder . '/Orders-May.csv', 'old export' );
		$job   = $this->big_job( 2 * 1024 * 1024 );
		$calls = 0;
		$item  = $this->deliver(
			$job,
			$this->dest( $type ),
			static function ( int $n ): bool {
				return $n < 3;
			},
			$calls
		);
		$this->assertSame( 3, $calls, 'The transfer stops at the first keep-alive that fails.' );
		$this->assertSame( 'failed', $item['status'] );
		$this->assertStringContainsString( 'took over this delivery', (string) $item['message'] );
		$this->assertSame( array( 'Orders-May.csv' ), $this->listing(), 'The partial upload is removed.' );
		$this->assertSame( 'old export', file_get_contents( $this->folder . '/Orders-May.csv' ) );
	}

	/**
	 * @dataProvider channels
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'channels' )]
	public function test_renewals_are_spaced_out( string $type ): void {
		$this->set_static( OPEX_Delivery::class, 'beat', 5.0 );
		$this->start( $type );
		$job   = $this->big_job( 512 * 1024 );
		$calls = 0;
		$item  = $this->deliver( $job, $this->dest( $type ), '__return_true', $calls );
		$this->assertSame( 'sent', $item['status'], (string) $item['message'] );
		$this->assertSame( 0, $calls, 'A transfer shorter than the gap writes no extra renewal.' );
	}
}
