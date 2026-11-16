<?php
/**
 * Expiring download tokens for export files.
 *
 * @package OpenExport
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Only a keyed hash of each token is stored, never the token itself.
 */
class OPEX_Download_Tokens {

	private const MAX = 5;

	/**
	 * Issues a token and records its hash on the job. The caller saves the job.
	 *
	 * @param array $job Job record, changed in place.
	 * @param int   $ttl Lifetime in seconds.
	 * @return string The raw token (64 hex characters).
	 */
	public static function issue( array &$job, int $ttl ): string {
		$token = bin2hex( random_bytes( 32 ) );
		$list  = isset( $job['tokens'] ) && is_array( $job['tokens'] ) ? array_values( $job['tokens'] ) : array();

		$list[] = array(
			'hash'    => self::hash( $token ),
			'expires' => time() + max( 60, $ttl ),
		);
		if ( count( $list ) > self::MAX ) {
			$list = array_slice( $list, -self::MAX );
		}
		$job['tokens'] = $list;
		return $token;
	}

	/**
	 * Issues a token and saves it on the stored copy of the job, so a caller
	 * that holds an older copy of the job record does not need to save it.
	 *
	 * @param array $job Job record (read only here).
	 * @param int   $ttl Lifetime in seconds.
	 * @return string Raw token.
	 */
	public static function issue_saved( array $job, int $ttl ): string {
		$token = '';
		$saved = OPEX_Jobs::update(
			(string) ( $job['id'] ?? '' ),
			static function ( array $stored ) use ( $ttl, &$token ): array {
				$token = self::issue( $stored, $ttl );
				return $stored;
			}
		);
		if ( null === $saved ) {
			// No stored job to keep it on: the token is still valid for this copy.
			$token = self::issue( $job, $ttl );
		}
		return $token;
	}

	/**
	 * Checks a token against the job.
	 *
	 * @param array  $job   Job record.
	 * @param string $token Raw token.
	 * @return bool
	 */
	public static function verify( array $job, string $token ): bool {
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $token ) || empty( $job['tokens'] ) || ! is_array( $job['tokens'] ) ) {
			return false;
		}
		$hash  = self::hash( $token );
		$found = false;
		foreach ( $job['tokens'] as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['hash'], $entry['expires'] ) ) {
				continue;
			}
			if ( hash_equals( (string) $entry['hash'], $hash ) && (int) $entry['expires'] > time() ) {
				$found = true;
			}
		}
		return $found;
	}

	/**
	 * Builds the download URL.
	 *
	 * @param array  $job   Job record.
	 * @param string $token Raw token.
	 * @return string
	 */
	public static function url( array $job, string $token ): string {
		return add_query_arg( 'token', $token, rest_url( 'open-export/v1/file/' . rawurlencode( (string) $job['id'] ) ) );
	}

	/**
	 * Keyed hash of a token.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	private static function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}
}
