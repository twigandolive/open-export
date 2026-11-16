<?php
/**
 * FTP: a failed rename never deletes the file already on the server, and
 * Open Export changes into a configured "/" folder.
 *
 * Each test starts its own pyftpdlib server from tests/servers/ftp_server.py,
 * with one function patched before the script runs (the same way
 * DeliveryFtpTest patches the passive address), so no new server script is needed.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/servers/helpers.php';

/**
 * FTP rename and folder handling.
 */
class CodexDeliveryFtpTest extends TestCase {

	use OpexDeliveryFixtures;

	/** @var OpexTestServer|null */
	private $server = null;

	protected function setUp(): void {
		delete_option( 'opex_destinations' );
		if ( ! function_exists( 'ftp_connect' ) ) {
			$this->markTestSkipped( 'The PHP ftp extension is not installed.' );
		}
		if ( '' === OpexTestServer::python() ) {
			$this->markTestSkipped( 'pyftpdlib is not available (set OPEX_TEST_TOOLS to a folder with venv/ containing pyftpdlib).' );
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
	 * Starts an FTP server with a patch.
	 *
	 * @param string $mode rename: ok, all (refuse every rename), exists (refuse
	 *                     a rename onto an existing file, as IIS does), part
	 *                     (refuse renaming a .part upload and onto an existing file).
	 *                     Add "+in" to start each login in /in instead of /.
	 */
	private function start( string $mode ): OpexTestServer {
		$s       = new OpexTestServer();
		$s->dir  = OpexTestServer::temp_dir();
		$s->port = OpexTestServer::free_port();
		mkdir( $s->dir . '/in' );
		$code = implode(
			"\n",
			array(
				'import os, sys, runpy',
				'from pyftpdlib.filesystems import AbstractedFS',
				'mode = sys.argv[1]',
				'orig_rename = AbstractedFS.rename',
				'def rename(self, src, dst):',
				'    m = mode.split("+")[0]',
				'    if m == "all" or (m in ("exists", "part") and os.path.exists(dst)) or (m == "part" and src.endswith(".part")):',
				'        raise OSError(1, "rename refused")',
				'    return orig_rename(self, src, dst)',
				'AbstractedFS.rename = rename',
				'orig_init = AbstractedFS.__init__',
				'def init(self, root, cmd_channel):',
				'    orig_init(self, root, cmd_channel)',
				'    if mode.endswith("+in"):',
				'        self._cwd = "/in"',
				'AbstractedFS.__init__ = init',
				'sys.argv = sys.argv[2:]',
				'runpy.run_path(sys.argv[0], run_name="__main__")',
			)
		);
		$ok = $s->start( array( OpexTestServer::python(), '-c', $code, $mode, dirname( __DIR__ ) . '/servers/ftp_server.py', $s->dir, (string) $s->port, 'tester', 'pw-good' ) );
		$this->server = $s;
		if ( ! $ok ) {
			$this->markTestSkipped( 'Could not start the patched FTP server.' );
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
		$rec = OPEX_Destinations::save( array_merge( array( 'name' => 't', 'type' => 'ftp', 'host' => '127.0.0.1', 'port' => $this->server->port, 'username' => 'tester', 'secret' => 'pw-good', 'path' => '/in' ), $over ) );
		$this->assertIsArray( $rec );
		return $rec;
	}

	/**
	 * Files in a server folder, hidden ones included.
	 *
	 * @param string $sub Folder under the root.
	 * @return string[]
	 */
	private function listing( string $sub = '/in' ): array {
		$names = array_values( array_diff( scandir( $this->server->dir . $sub ), array( '.', '..' ) ) );
		sort( $names );
		return $names;
	}

	public function test_a_server_that_refuses_every_rename_keeps_the_old_file() {
		$this->start( 'all' );
		file_put_contents( $this->server->dir . '/in/Orders-May.csv', 'old export' );
		$job = $this->make_job();
		$res = OPEX_Delivery_Ftp::send( $job, $this->dest(), OPEX_Jobs::file_path( $job ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'opex_ftp_rename', $res->get_error_code() );
		$this->assertSame( 'old export', file_get_contents( $this->server->dir . '/in/Orders-May.csv' ), 'the old file is never deleted' );
		$this->assertSame( array( 'Orders-May.csv' ), $this->listing(), 'only the temporary upload was removed' );
	}

	public function test_a_server_that_will_not_rename_onto_a_file_gets_it_replaced_safely() {
		$this->start( 'exists' );
		file_put_contents( $this->server->dir . '/in/Orders-May.csv', 'old export' );
		$job = $this->make_job();
		$res = OPEX_Delivery_Ftp::send( $job, $this->dest(), OPEX_Jobs::file_path( $job ) );
		$this->assertTrue( $res, is_wp_error( $res ) ? $res->get_error_message() : '' );
		$this->assertSame( "id,name\n1,Ann\n2,Bob\n", file_get_contents( $this->server->dir . '/in/Orders-May.csv' ) );
		$this->assertSame( array( 'Orders-May.csv' ), $this->listing(), 'the backup was removed after the new file took its place' );
	}

	public function test_when_the_upload_cannot_take_the_name_the_old_file_comes_back() {
		$this->start( 'part' );
		file_put_contents( $this->server->dir . '/in/Orders-May.csv', 'old export' );
		$job = $this->make_job();
		$res = OPEX_Delivery_Ftp::send( $job, $this->dest(), OPEX_Jobs::file_path( $job ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertStringContainsString( 'It kept the old file', $res->get_error_message() );
		$this->assertSame( 'old export', file_get_contents( $this->server->dir . '/in/Orders-May.csv' ) );
		$this->assertSame( array( 'Orders-May.csv' ), $this->listing() );
	}

	public function test_a_new_file_still_uploads_on_every_server_kind() {
		foreach ( array( 'ok', 'exists' ) as $mode ) {
			$this->start( $mode );
			$job = $this->make_job();
			$this->assertTrue( OPEX_Delivery_Ftp::send( $job, $this->dest(), OPEX_Jobs::file_path( $job ) ), $mode );
			$this->assertSame( array( 'Orders-May.csv' ), $this->listing(), $mode );
			$this->server->stop();
			$this->server = null;
			delete_option( 'opex_destinations' );
		}
	}

	public function test_a_slash_path_changes_to_the_root_folder() {
		$this->start( 'ok+in' );
		$job = $this->make_job();
		$res = OPEX_Delivery_Ftp::send( $job, $this->dest( array( 'path' => '/' ) ), OPEX_Jobs::file_path( $job ) );
		$this->assertTrue( $res, is_wp_error( $res ) ? $res->get_error_message() : '' );
		$this->assertFileExists( $this->server->dir . '/Orders-May.csv', 'the file lands in /, not in the login folder' );
		$this->assertSame( array(), $this->listing( '/in' ) );

		// A blank path still means the login folder.
		$res = OPEX_Delivery_Ftp::send( $job, array_merge( $this->dest(), array( 'path' => '' ) ), OPEX_Jobs::file_path( $job ) );
		$this->assertTrue( $res, is_wp_error( $res ) ? $res->get_error_message() : '' );
		$this->assertSame( array( 'Orders-May.csv' ), $this->listing( '/in' ) );
	}
}
