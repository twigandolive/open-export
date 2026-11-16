<?php
/**
 * SFTP: a failed rename never deletes the file
 * already on the server, and a host key is pinned only onto the server
 * that produced it.
 *
 * Each test starts its own asyncssh server from tests/servers/sftp_server.py,
 * with asyncssh patched before the script runs, so no new server script is needed.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/servers/helpers.php';

/**
 * SFTP rename and host key pinning.
 */
class CodexDeliverySftpTest extends TestCase {

	use OpexDeliveryFixtures;

	/** @var OpexTestServer|null */
	private $server = null;

	protected function setUp(): void {
		delete_option( 'opex_destinations' );
		if ( '' === OpexTestServer::python() ) {
			$this->markTestSkipped( 'SFTP test tools are not available (set OPEX_TEST_TOOLS to a folder with venv/ containing asyncssh).' );
		}
	}

	protected function tearDown(): void {
		if ( $this->server ) {
			$this->server->stop();
			$this->server = null;
		}
		$this->cleanup_jobs();
		delete_option( 'opex_destinations' );
	}

	/**
	 * Starts an SFTP server with a patch.
	 *
	 * @param string $mode ok (posix-rename offered), noposix (only the plain
	 *                     rename, which never replaces a file), all (every
	 *                     rename refused), part (noposix, and a .part upload
	 *                     cannot be renamed).
	 */
	private function start( string $mode ): OpexTestServer {
		$s       = new OpexTestServer();
		$s->dir  = OpexTestServer::temp_dir();
		$s->port = OpexTestServer::free_port();
		mkdir( $s->dir . '/root/in', 0700, true );
		// asyncssh needs at least one valid authorized key, though these tests log in with a password.
		exec( 'ssh-keygen -q -t ed25519 -N "" -C opex-test -f ' . escapeshellarg( $s->dir . '/k' ) . ' 2>&1', $out, $rc );
		if ( 0 !== $rc ) {
			$s->stop();
			$this->markTestSkipped( 'ssh-keygen failed, cannot make a test key.' );
		}
		rename( $s->dir . '/k.pub', $s->dir . '/authorized_keys' );
		$code = implode(
			"\n",
			array(
				'import sys, runpy, asyncssh',
				'from asyncssh import sftp as S',
				'mode = sys.argv[1]',
				'if mode in ("noposix", "part"):',
				'    S.SFTPServerHandler._extensions = [e for e in S.SFTPServerHandler._extensions if e[0] != b"posix-rename@openssh.com"]',
				'orig_rename = asyncssh.SFTPServer.rename',
				'orig_posix = asyncssh.SFTPServer.posix_rename',
				'def rename(self, old, new):',
				'    if mode == "all" or (mode == "part" and old.endswith(b".part")):',
				'        raise asyncssh.SFTPPermissionDenied("rename refused")',
				'    return orig_rename(self, old, new)',
				'def posix_rename(self, old, new):',
				'    if mode == "all":',
				'        raise asyncssh.SFTPPermissionDenied("rename refused")',
				'    return orig_posix(self, old, new)',
				'asyncssh.SFTPServer.rename = rename',
				'asyncssh.SFTPServer.posix_rename = posix_rename',
				'sys.argv = sys.argv[2:]',
				'runpy.run_path(sys.argv[0], run_name="__main__")',
			)
		);
		$ok = $s->start( array( OpexTestServer::python(), '-c', $code, $mode, dirname( __DIR__ ) . '/servers/sftp_server.py', $s->dir . '/root', (string) $s->port, 'tester', 'pw-good', $s->dir . '/authorized_keys' ) );
		$this->server = $s;
		if ( ! $ok ) {
			$this->markTestSkipped( 'Could not start the patched SFTP server.' );
		}
		return $s;
	}

	/**
	 * Saves a destination on the running server.
	 *
	 * @param array $over Field overrides.
	 * @return array The record.
	 */
	private function dest( array $over = array() ): array {
		$rec = OPEX_Destinations::save( array_merge( array( 'name' => 't', 'type' => 'sftp', 'host' => '127.0.0.1', 'port' => $this->server->port, 'username' => 'tester', 'secret' => 'pw-good', 'path' => '/in' ), $over ) );
		$this->assertIsArray( $rec );
		return $rec;
	}

	/**
	 * Files in the upload folder, hidden ones included.
	 *
	 * @return string[]
	 */
	private function listing(): array {
		$names = array_values( array_diff( scandir( $this->server->dir . '/root/in' ), array( '.', '..' ) ) );
		sort( $names );
		return $names;
	}

	/**
	 * Sends the job's file and returns the result.
	 *
	 * @return true|WP_Error
	 */
	private function send_over_old_file() {
		file_put_contents( $this->server->dir . '/root/in/Orders-May.csv', 'old export' );
		$job = $this->make_job();
		return OPEX_Delivery_Sftp::send( $job, $this->dest(), OPEX_Jobs::file_path( $job ) );
	}

	public function test_posix_rename_replaces_the_old_file() {
		$this->start( 'ok' );
		$res = $this->send_over_old_file();
		$this->assertTrue( $res, is_wp_error( $res ) ? $res->get_error_message() : '' );
		$this->assertSame( "id,name\n1,Ann\n2,Bob\n", file_get_contents( $this->server->dir . '/root/in/Orders-May.csv' ) );
		$this->assertSame( array( 'Orders-May.csv' ), $this->listing() );
	}

	public function test_without_posix_rename_the_old_file_is_moved_aside_then_replaced() {
		$this->start( 'noposix' );
		$res = $this->send_over_old_file();
		$this->assertTrue( $res, is_wp_error( $res ) ? $res->get_error_message() : '' );
		$this->assertSame( "id,name\n1,Ann\n2,Bob\n", file_get_contents( $this->server->dir . '/root/in/Orders-May.csv' ) );
		$this->assertSame( array( 'Orders-May.csv' ), $this->listing() );
	}

	public function test_a_server_that_refuses_every_rename_keeps_the_old_file() {
		$this->start( 'all' );
		$res = $this->send_over_old_file();
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'opex_sftp_rename', $res->get_error_code() );
		$this->assertSame( 'old export', file_get_contents( $this->server->dir . '/root/in/Orders-May.csv' ), 'the old file is never deleted' );
		$this->assertSame( array( 'Orders-May.csv' ), $this->listing(), 'only the temporary upload was removed' );
	}

	public function test_when_the_upload_cannot_take_the_name_the_old_file_comes_back() {
		$this->start( 'part' );
		$res = $this->send_over_old_file();
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertStringContainsString( 'It kept the old file', $res->get_error_message() );
		$this->assertSame( 'old export', file_get_contents( $this->server->dir . '/root/in/Orders-May.csv' ) );
		$this->assertSame( array( 'Orders-May.csv' ), $this->listing() );
	}

	public function test_a_key_is_not_pinned_onto_a_destination_moved_to_another_host_mid_transfer() {
		$this->start( 'ok' );
		$rec = $this->dest();
		// Someone points the destination at another host while this transfer, which read the old record, runs.
		$moved = OPEX_Destinations::save( array( 'type' => 'sftp', 'host' => 'localhost', 'port' => $this->server->port, 'username' => 'tester', 'secret' => '', 'path' => '/in', 'name' => 't' ), $rec['id'] );
		$this->assertIsArray( $moved );
		$job = $this->make_job();
		$res = OPEX_Delivery_Sftp::send( $job, $rec, OPEX_Jobs::file_path( $job ) );
		$this->assertTrue( $res, is_wp_error( $res ) ? $res->get_error_message() : '' );
		$this->assertSame( '', OPEX_Destinations::get( $rec['id'] )['host_key'], 'the key of 127.0.0.1 is not pinned onto localhost' );

		// The same transfer against the unchanged record still pins.
		$res = OPEX_Delivery_Sftp::send( $job, OPEX_Destinations::get( $rec['id'] ), OPEX_Jobs::file_path( $job ) );
		$this->assertTrue( $res, is_wp_error( $res ) ? $res->get_error_message() : '' );
		$this->assertMatchesRegularExpression( '/^SHA256:/', OPEX_Destinations::get( $rec['id'] )['host_key'] );
	}

	public function test_pin_checks_type_host_and_port_under_the_lock() {
		$this->start( 'ok' );
		$rec = $this->dest();
		$fp  = 'SHA256:' . str_repeat( 'B', 43 );
		foreach ( array( array( 'port' => 1 ), array( 'host' => '127.0.0.2' ), array( 'type' => 'ftp' ) ) as $change ) {
			OPEX_Destinations::pin_host_key( $rec['id'], $fp, array_merge( $rec, $change ) );
			$this->assertSame( '', OPEX_Destinations::get( $rec['id'] )['host_key'], wp_json_encode( $change ) );
		}
		OPEX_Destinations::pin_host_key( $rec['id'], $fp, $rec );
		$this->assertSame( $fp, OPEX_Destinations::get( $rec['id'] )['host_key'] );
	}
}
