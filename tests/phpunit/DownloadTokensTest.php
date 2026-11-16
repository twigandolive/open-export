<?php
/**
 * Tests for OPEX_Download_Tokens.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/servers/helpers.php';

/**
 * Token issue and verify.
 */
class DownloadTokensTest extends TestCase {

	use OpexDeliveryFixtures;

	protected function tearDown(): void {
		$this->cleanup_jobs();
	}

	public function test_issue_stores_only_the_hash() {
		$job   = array( 'id' => 'j1' );
		$token = OPEX_Download_Tokens::issue( $job, 3600 );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $token );
		$this->assertCount( 1, $job['tokens'] );
		$this->assertSame( hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ), $job['tokens'][0]['hash'] );
		$this->assertStringNotContainsString( $token, wp_json_encode( $job ) );
		$this->assertGreaterThan( time() + 3000, $job['tokens'][0]['expires'] );
		$this->assertTrue( OPEX_Download_Tokens::verify( $job, $token ) );
	}

	public function test_verify_rejects_wrong_expired_and_malformed() {
		$job   = array( 'id' => 'j1' );
		$token = OPEX_Download_Tokens::issue( $job, 3600 );
		$this->assertFalse( OPEX_Download_Tokens::verify( $job, str_repeat( 'a', 64 ) ) );
		$this->assertFalse( OPEX_Download_Tokens::verify( $job, 'short' ) );
		$this->assertFalse( OPEX_Download_Tokens::verify( $job, '' ) );
		$this->assertFalse( OPEX_Download_Tokens::verify( array( 'id' => 'j1' ), $token ) );
		$job['tokens'][0]['expires'] = time() - 1;
		$this->assertFalse( OPEX_Download_Tokens::verify( $job, $token ) );
	}

	public function test_keeps_at_most_five() {
		$job    = array( 'id' => 'j1' );
		$tokens = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$tokens[] = OPEX_Download_Tokens::issue( $job, 3600 );
		}
		$this->assertCount( 5, $job['tokens'] );
		$this->assertFalse( OPEX_Download_Tokens::verify( $job, $tokens[0] ) );
		$this->assertTrue( OPEX_Download_Tokens::verify( $job, $tokens[6] ) );
	}

	public function test_url() {
		$url = OPEX_Download_Tokens::url( array( 'id' => 'jabc' ), 'tok' );
		$this->assertStringContainsString( 'open-export/v1/file/jabc', urldecode( $url ) );
		$this->assertStringContainsString( 'token=tok', $url );
	}

	public function test_issue_saved_persists_on_the_stored_job() {
		$job   = $this->make_job();
		$token = OPEX_Download_Tokens::issue_saved( $job, 3600 );
		$this->assertTrue( OPEX_Download_Tokens::verify( OPEX_Jobs::get( $job['id'] ), $token ) );
	}
}
