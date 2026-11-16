<?php
/**
 * Tests for OPEX_Destinations.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Destination validation and storage.
 */
class DestinationsTest extends TestCase {

	protected function setUp(): void {
		delete_option( 'opex_destinations' );
	}

	protected function tearDown(): void {
		delete_option( 'opex_destinations' );
	}

	private function raw( array $over = array() ): array {
		return array_merge(
			array( 'name' => 'Main', 'type' => 'ftp', 'host' => 'ftp.example.com', 'port' => '', 'username' => 'u', 'auth' => 'password', 'secret' => 'pw', 'path' => '/in' ),
			$over
		);
	}

	public function test_saves_with_defaults_and_encrypts() {
		$rec = OPEX_Destinations::save( $this->raw() );
		$this->assertIsArray( $rec );
		$this->assertMatchesRegularExpression( '/^d[0-9a-f]{12}$/', $rec['id'] );
		$this->assertSame( 21, $rec['port'] );
		$this->assertTrue( $rec['passive'] );
		$this->assertSame( 'pw', OPEX_Secrets::decrypt( $rec['secret'] ) );
		$this->assertSame( 22, OPEX_Destinations::save( $this->raw( array( 'type' => 'sftp' ) ) )['port'] );
		$this->assertSame( 21, OPEX_Destinations::save( $this->raw( array( 'type' => 'ftps' ) ) )['port'] );
		$this->assertSame( 990, OPEX_Destinations::save( $this->raw( array( 'port' => '990' ) ) )['port'] );
		$this->assertContains( $GLOBALS['wpdb']->get_var( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = 'opex_destinations'" ), array( 'no', 'off' ) );
	}

	public function test_rejects_bad_hosts() {
		foreach ( array( '', 'ftp://example.com', 'example.com/path', 'user:pw@example.com', 'exa mple.com', 'example.com:21', '-bad.example.com', 'a_b.example.com', "ex\nample.com" ) as $host ) {
			$this->assertWPError( OPEX_Destinations::save( $this->raw( array( 'host' => $host ) ) ), $host );
		}
		$this->assertIsArray( OPEX_Destinations::save( $this->raw( array( 'host' => '203.0.113.5' ) ) ) );
		$this->assertIsArray( OPEX_Destinations::save( $this->raw( array( 'host' => '2001:db8::1' ) ) ) );
	}

	public function test_rejects_bad_ports_types_and_paths() {
		foreach ( array( '0', '65536', '-1', 'abc', '21.5' ) as $port ) {
			$this->assertWPError( OPEX_Destinations::save( $this->raw( array( 'port' => $port ) ) ), $port );
		}
		$this->assertWPError( OPEX_Destinations::save( $this->raw( array( 'type' => 'http' ) ) ) );
		foreach ( array( '/a/../b', '..', '../x', "/a\0b", '/a\\b' ) as $path ) {
			$this->assertWPError( OPEX_Destinations::save( $this->raw( array( 'path' => $path ) ) ), $path );
		}
		$this->assertSame( '/a/b', OPEX_Destinations::save( $this->raw( array( 'path' => '/a//b/' ) ) )['path'] );
		$this->assertSame( '/', OPEX_Destinations::save( $this->raw( array( 'path' => '' ) ) )['path'] );
		$this->assertWPError( OPEX_Destinations::save( $this->raw( array( 'username' => '' ) ) ) );
	}

	public function test_key_auth_is_sftp_only_and_validated() {
		$pem = "-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n-----END OPENSSH PRIVATE KEY-----";
		$this->assertWPError( OPEX_Destinations::save( $this->raw( array( 'type' => 'ftp', 'auth' => 'key', 'secret' => $pem ) ) ) );
		$this->assertWPError( OPEX_Destinations::save( $this->raw( array( 'type' => 'sftp', 'auth' => 'key', 'secret' => 'not a key' ) ) ) );
		$this->assertWPError( OPEX_Destinations::save( $this->raw( array( 'type' => 'sftp', 'auth' => 'key', 'secret' => "-----BEGIN RSA PRIVATE KEY-----\n" . str_repeat( 'A', 17000 ) . "\n-----END RSA PRIVATE KEY-----" ) ) ) );
		$rec = OPEX_Destinations::save( $this->raw( array( 'type' => 'sftp', 'auth' => 'key', 'secret' => $pem ) ) );
		$this->assertIsArray( $rec );
		$this->assertStringContainsString( 'BEGIN OPENSSH', OPEX_Secrets::decrypt( $rec['secret'] ) );
	}

	public function test_blank_secret_keeps_stored_one_but_new_needs_one() {
		$this->assertWPError( OPEX_Destinations::save( $this->raw( array( 'secret' => '' ) ) ) );
		$rec  = OPEX_Destinations::save( $this->raw() );
		$next = OPEX_Destinations::save( $this->raw( array( 'name' => 'Renamed', 'secret' => '' ) ), $rec['id'] );
		$this->assertSame( $rec['secret'], $next['secret'] );
		$this->assertSame( 'Renamed', $next['name'] );
		$this->assertSame( $rec['created'], $next['created'] );
		$this->assertCount( 1, OPEX_Destinations::all() );
		$this->assertWPError( OPEX_Destinations::save( $this->raw(), 'dmissing' ) );
	}

	public function test_expose_hides_secret() {
		$rec = OPEX_Destinations::save( $this->raw() );
		$out = OPEX_Destinations::expose( $rec );
		$this->assertArrayNotHasKey( 'secret', $out );
		$this->assertTrue( $out['has_secret'] );
		$this->assertStringNotContainsString( 'pw"', wp_json_encode( $out ) );
	}

	public function test_limit_and_delete() {
		$ids = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$ids[] = OPEX_Destinations::save( $this->raw() )['id'];
		}
		$this->assertWPError( OPEX_Destinations::save( $this->raw() ) );
		OPEX_Destinations::delete( $ids[0] );
		$this->assertNull( OPEX_Destinations::get( $ids[0] ) );
		$this->assertIsArray( OPEX_Destinations::save( $this->raw() ) );
	}

	public function test_test_reports_unknown_and_unreachable() {
		$this->assertWPError( OPEX_Destinations::test( 'dnone' ) );
		$rec = OPEX_Destinations::save( $this->raw( array( 'host' => '127.0.0.1', 'port' => '1' ) ) );
		$res = OPEX_Destinations::test( $rec['id'] );
		$this->assertWPError( $res );
		$this->assertStringNotContainsString( 'pw', $res->get_error_message() );
	}

	public function test_security_notes_and_host_key_are_exposed() {
		$ftp  = OPEX_Destinations::expose( OPEX_Destinations::save( $this->raw() ) );
		$ftps = OPEX_Destinations::expose( OPEX_Destinations::save( $this->raw( array( 'type' => 'ftps' ) ) ) );
		$sftp = OPEX_Destinations::expose( OPEX_Destinations::save( $this->raw( array( 'type' => 'sftp' ) ) ) );
		$this->assertSame( 'FTP sends your password and file without encryption. Use SFTP if the server offers it.', $ftp['security_note'] );
		$this->assertSame( 'FTPS encrypts the connection, but PHP cannot verify the server\'s certificate. Use SFTP if the server offers it.', $ftps['security_note'] );
		$this->assertSame( '', $sftp['security_note'] );
		$this->assertSame( '', $sftp['host_key'] );
	}

	public function test_old_record_without_host_key_loads_and_pin_is_first_wins() {
		$rec = OPEX_Destinations::save( $this->raw( array( 'type' => 'sftp' ) ) );
		$all = OPEX_Destinations::all();
		unset( $all[ $rec['id'] ]['host_key'] );
		update_option( 'opex_destinations', $all, false );
		$this->assertSame( '', OPEX_Destinations::expose( OPEX_Destinations::get( $rec['id'] ) )['host_key'] );

		$one = 'SHA256:' . str_repeat( 'A', 43 );
		$two = 'SHA256:' . str_repeat( 'B', 43 );
		OPEX_Destinations::pin_host_key( $rec['id'], 'not a fingerprint' );
		$this->assertSame( '', OPEX_Destinations::get( $rec['id'] )['host_key'] ?? '' );
		OPEX_Destinations::pin_host_key( $rec['id'], $one );
		OPEX_Destinations::pin_host_key( $rec['id'], $two );
		$this->assertSame( $one, OPEX_Destinations::get( $rec['id'] )['host_key'] );
	}

	public function test_host_key_cannot_be_set_through_save() {
		$rec = OPEX_Destinations::save( $this->raw( array( 'type' => 'sftp', 'host_key' => 'SHA256:' . str_repeat( 'A', 43 ) ) ) );
		$this->assertSame( '', $rec['host_key'] );
	}

	private function assertWPError( $value, string $msg = '' ): void {
		$this->assertInstanceOf( WP_Error::class, $value, $msg );
	}
}
