<?php
/**
 * Tests for OPEX_Secrets.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Secret storage.
 */
class SecretsTest extends TestCase {

	public function test_round_trip_and_prefix() {
		$stored = OPEX_Secrets::encrypt( 'hunter2' );
		$this->assertStringStartsWith( 'opex1:', $stored );
		$this->assertStringNotContainsString( 'hunter2', $stored );
		$this->assertSame( 'hunter2', OPEX_Secrets::decrypt( $stored ) );
		$this->assertTrue( OPEX_Secrets::is_encrypted( $stored ) );
	}

	public function test_random_nonce_per_value() {
		$this->assertNotSame( OPEX_Secrets::encrypt( 'same' ), OPEX_Secrets::encrypt( 'same' ) );
	}

	public function test_empty_value_stays_empty() {
		$this->assertSame( '', OPEX_Secrets::encrypt( '' ) );
	}

	public function test_decrypt_fails_closed() {
		$this->assertNull( OPEX_Secrets::decrypt( 'plain value' ) );
		$this->assertNull( OPEX_Secrets::decrypt( '' ) );
		$this->assertNull( OPEX_Secrets::decrypt( 'opex1:' ) );
		$this->assertNull( OPEX_Secrets::decrypt( 'opex1:!!!not base64!!!' ) );
		$this->assertNull( OPEX_Secrets::decrypt( 'opex1:' . base64_encode( 'short' ) ) );
	}

	public function test_tampering_is_detected() {
		$stored = OPEX_Secrets::encrypt( 'hunter2' );
		$raw    = base64_decode( substr( $stored, 6 ) );
		$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 1 );
		$this->assertNull( OPEX_Secrets::decrypt( 'opex1:' . base64_encode( $raw ) ) );
	}

	public function test_changed_salts_make_old_values_unreadable() {
		$stored = OPEX_Secrets::encrypt( 'hunter2' );
		$swap   = static function () {
			return 'a-completely-different-salt-value';
		};
		add_filter( 'salt', $swap );
		try {
			$this->assertNull( OPEX_Secrets::decrypt( $stored ) );
		} finally {
			remove_filter( 'salt', $swap );
		}
		$this->assertSame( 'hunter2', OPEX_Secrets::decrypt( $stored ) );
	}
}
