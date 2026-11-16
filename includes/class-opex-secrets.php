<?php
/**
 * Encrypts small secrets (webhook keys, passwords, private keys) for storage.
 *
 * @package OpenExport
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Libsodium secretbox with a key derived from the WordPress salts. If the
 * salts change, old values stop decrypting and the person re-enters them.
 */
class OPEX_Secrets {

	private const PREFIX  = 'opex1:';
	private const CONTEXT = 'open-export secrets v1';

	/**
	 * Encrypts a value. Returns an empty string for an empty value or on failure.
	 *
	 * @param string $plain Plain text.
	 * @return string Prefixed base64 string.
	 */
	public static function encrypt( string $plain ): string {
		if ( '' === $plain || ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return '';
		}
		try {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plain, $nonce, self::key() );
			return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		} catch ( Throwable $e ) {
			return '';
		}
	}

	/**
	 * Decrypts a stored value. Returns null on any failure and never throws.
	 *
	 * @param string $stored Value from encrypt().
	 * @return string|null Plain text, or null.
	 */
	public static function decrypt( string $stored ): ?string {
		if ( 0 !== strpos( $stored, self::PREFIX ) || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return null;
		}
		try {
			$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
				return null;
			}
			$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
			return false === $plain ? null : $plain;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Tells whether a value looks like an encrypted secret.
	 *
	 * @param string $value Stored value.
	 * @return bool
	 */
	public static function is_encrypted( string $value ): bool {
		return 0 === strpos( $value, self::PREFIX );
	}

	/**
	 * Derives the 32-byte key.
	 *
	 * @return string
	 */
	private static function key(): string {
		return sodium_crypto_generichash( self::CONTEXT . '|' . wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
