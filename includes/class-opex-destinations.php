<?php
/**
 * Saved FTP, FTPS and SFTP destinations (option opex_destinations).
 *
 * @package OpenExport
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Stores destination records. The password or private key is kept encrypted
 * and never leaves this class in clear text except to the delivery channels.
 *
 * Private and loopback addresses are allowed for FTP and SFTP hosts on
 * purpose: a server on the local network is a normal place to deliver files.
 * The webhook is different. It posts to a URL a person typed and can be
 * pointed at internal services, so it goes through wp_safe_remote_post and
 * refuses private addresses.
 */
class OPEX_Destinations {

	private const OPTION       = 'opex_destinations';
	public const MAX           = 20;
	private const MAX_KEY_SIZE = 16384;
	private const MAX_PASSWORD = 1024;
	private const TYPES        = array( 'ftp', 'ftps', 'sftp' );

	/**
	 * All destinations keyed by id.
	 *
	 * @return array
	 */
	public static function all(): array {
		return OPEX_Store::read( self::OPTION );
	}

	/**
	 * One destination, or null.
	 *
	 * @param string $id Destination id.
	 * @return array|null
	 */
	public static function get( string $id ): ?array {
		$records = self::all();
		return isset( $records[ $id ] ) && is_array( $records[ $id ] ) ? $records[ $id ] : null;
	}

	/**
	 * Validates and saves a destination. A blank secret keeps the stored one.
	 * The saved SFTP host key is cleared when the host, port or type changes,
	 * or when the input has reset_host_key set to true.
	 *
	 * @param array  $raw Raw input.
	 * @param string $id  Existing id to update, or blank to create.
	 * @return array|WP_Error The saved record.
	 */
	public static function save( array $raw, string $id = '' ) {
		// Built under the registry lock from the list as stored now, so a host
		// key pinned or a test recorded a moment ago is kept.
		return OPEX_Store::change(
			self::OPTION,
			static function ( array &$records ) use ( $raw, $id ) {
				return self::build( $records, $raw, $id );
			}
		);
	}

	/**
	 * Validates a destination and adds it to the list.
	 *
	 * @param array  $records Stored destinations, changed in place on success.
	 * @param array  $raw  Raw input.
	 * @param string $id   Existing id to update, or blank to create.
	 * @return array|WP_Error The saved record.
	 */
	private static function build( array &$records, array $raw, string $id ) {
		$existing = '' !== $id ? ( $records[ $id ] ?? null ) : null;
		if ( '' !== $id && ! is_array( $existing ) ) {
			return new WP_Error( 'opex_destination_missing', __( 'That destination does not exist.', 'open-export' ) );
		}
		if ( null === $existing && count( $records ) >= self::MAX ) {
			return new WP_Error( 'opex_destination_limit', __( 'You can save at most 20 destinations.', 'open-export' ) );
		}

		$type = strtolower( (string) ( $raw['type'] ?? '' ) );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error( 'opex_destination_type', __( 'Choose FTP, FTPS or SFTP.', 'open-export' ) );
		}
		$host = self::clean_host( (string) ( $raw['host'] ?? '' ) );
		if ( '' === $host ) {
			return new WP_Error( 'opex_destination_host', __( 'Enter a host name or IP address only, without a scheme, path or credentials.', 'open-export' ) );
		}
		$port = self::clean_port( $raw['port'] ?? '', $type );
		if ( null === $port ) {
			return new WP_Error( 'opex_destination_port', __( 'The port must be a number from 1 to 65535.', 'open-export' ) );
		}
		$username = trim( (string) ( $raw['username'] ?? '' ) );
		if ( '' === $username || strlen( $username ) > 128 || 1 === preg_match( '/[\x00-\x1f\x7f]/', $username ) ) {
			return new WP_Error( 'opex_destination_username', __( 'Enter a user name (up to 128 characters).', 'open-export' ) );
		}
		$path = self::clean_path( (string) ( $raw['path'] ?? '/' ) );
		if ( null === $path ) {
			return new WP_Error( 'opex_destination_path', __( 'The remote folder cannot contain ".." or control characters.', 'open-export' ) );
		}
		$auth = strtolower( (string) ( $raw['auth'] ?? 'password' ) );
		if ( ! in_array( $auth, array( 'password', 'key' ), true ) || ( 'key' === $auth && 'sftp' !== $type ) ) {
			return new WP_Error( 'opex_destination_auth', __( 'Key login works with SFTP only.', 'open-export' ) );
		}

		$secret = self::clean_secret( $auth, (string) ( $raw['secret'] ?? '' ), $existing );
		if ( is_wp_error( $secret ) ) {
			return $secret;
		}

		$now  = time();
		$name = sanitize_text_field( (string) ( $raw['name'] ?? '' ) );
		$id   = null === $existing ? 'd' . bin2hex( random_bytes( 6 ) ) : $id;

		$keep_key = null !== $existing
			&& empty( $raw['reset_host_key'] )
			&& ( $existing['host'] ?? '' ) === $host
			&& (int) ( $existing['port'] ?? 0 ) === $port
			&& ( $existing['type'] ?? '' ) === $type;

		$record = array(
			'id'       => $id,
			'name'     => '' !== $name ? mb_substr( $name, 0, 100 ) : $host,
			'type'     => $type,
			'host'     => $host,
			'port'     => $port,
			'username' => $username,
			'auth'     => $auth,
			'secret'   => $secret,
			'path'     => $path,
			'host_key' => ( $keep_key && 'sftp' === $type ) ? (string) ( $existing['host_key'] ?? '' ) : '',
			'passive'  => ! isset( $raw['passive'] ) || (bool) filter_var( $raw['passive'], FILTER_VALIDATE_BOOLEAN ),
			'created'  => null === $existing ? $now : (int) ( $existing['created'] ?? $now ),
			'updated'  => $now,
		);
		// The last test speaks for the connection it tried, so a change to it drops the result.
		$new_secret = '' !== (string) ( $raw['secret'] ?? '' );
		if ( null !== $existing && isset( $existing['last_test'] ) && self::same_connection( $existing, $record, $new_secret ) ) {
			$record['last_test'] = self::clean_test( $existing['last_test'] );
		}

		$records[ $id ] = $record;
		return $record;
	}

	/**
	 * Deletes a destination.
	 *
	 * @param string $id Destination id.
	 * @return void
	 */
	public static function delete( string $id ): void {
		OPEX_Store::change(
			self::OPTION,
			static function ( array &$records ) use ( $id ): void {
				unset( $records[ $id ] );
			}
		);
	}

	/**
	 * Shape for the UI: never the secret.
	 *
	 * @param array $record Destination record.
	 * @return array
	 */
	public static function expose( array $record ): array {
		$out               = $record;
		$out['has_secret'] = ! empty( $record['secret'] );
		$out['host_key']   = (string) ( $record['host_key'] ?? '' );
		$out['last_test']  = self::clean_test( $record['last_test'] ?? array() );
		unset( $out['secret'] );
		$out['security_note'] = self::security_note( (string) ( $record['type'] ?? '' ) );
		return $out;
	}

	/**
	 * The warning shown for a destination type, empty for SFTP. The screen
	 * shows it before a new destination is saved, too.
	 *
	 * @param string $type ftp, ftps or sftp.
	 * @return string
	 */
	public static function security_note( string $type ): string {
		if ( 'ftp' === $type ) {
			return __( 'FTP sends your password and file without encryption. Use SFTP if the server offers it.', 'open-export' );
		}
		if ( 'ftps' === $type ) {
			return __( 'FTPS encrypts the connection, but PHP cannot verify the server\'s certificate. Use SFTP if the server offers it.', 'open-export' );
		}
		return '';
	}

	/**
	 * Stores a host key fingerprint, only when none is stored yet. The first
	 * successful connection wins, so a later change can never overwrite a pin.
	 *
	 * @param string $id          Destination id.
	 * @param string $fingerprint Fingerprint such as SHA256:<base64>.
	 * @return void
	 */
	public static function pin_host_key( string $id, string $fingerprint ): void {
		if ( 1 !== preg_match( '/^SHA256:[A-Za-z0-9+\/]{43}$/', $fingerprint ) ) {
			return;
		}
		OPEX_Store::change(
			self::OPTION,
			static function ( array &$records ) use ( $id, $fingerprint ): void {
				if ( ! isset( $records[ $id ] ) || ! is_array( $records[ $id ] ) || '' !== (string) ( $records[ $id ]['host_key'] ?? '' ) ) {
					return;
				}
				$records[ $id ]['host_key'] = $fingerprint;
			}
		);
	}

	/**
	 * Connects, logs in and proves the folder is writable.
	 *
	 * @param string $id Destination id.
	 * @return true|WP_Error
	 */
	public static function test( string $id ) {
		$record = self::get( $id );
		if ( null === $record ) {
			return new WP_Error( 'opex_destination_missing', __( 'That destination does not exist.', 'open-export' ) );
		}
		try {
			return 'sftp' === $record['type'] ? OPEX_Delivery_Sftp::test( $record ) : OPEX_Delivery_Ftp::test( $record );
		} catch ( Throwable $e ) {
			return new WP_Error( 'opex_destination_test', __( 'The test failed unexpectedly.', 'open-export' ) );
		}
	}

	/**
	 * Saves the result of a connection test on the record, for the list's
	 * "Last test" column. The message is the one the test route shows.
	 *
	 * @param string $id      Destination id.
	 * @param bool   $ok      Whether the test passed.
	 * @param string $message Message shown to the person.
	 * @return void
	 */
	public static function record_test( string $id, bool $ok, string $message ): void {
		$result = self::clean_test(
			array(
				'time'    => time(),
				'ok'      => $ok,
				'message' => $message,
			)
		);
		OPEX_Store::change(
			self::OPTION,
			static function ( array &$records ) use ( $id, $result ): void {
				if ( isset( $records[ $id ] ) && is_array( $records[ $id ] ) ) {
					$records[ $id ]['last_test'] = $result;
				}
			}
		);
	}

	/**
	 * The last test result in its one shape. Time 0 means never tested, which
	 * is what a record saved before version 2.0 reads as.
	 *
	 * @param mixed $raw Stored result.
	 * @return array
	 */
	private static function clean_test( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'time'    => (int) ( $raw['time'] ?? 0 ),
			'ok'      => (bool) ( $raw['ok'] ?? false ),
			'message' => mb_substr( sanitize_text_field( (string) ( $raw['message'] ?? '' ) ), 0, 300 ),
		);
	}

	/**
	 * Whether a save left the connection as it was tested.
	 *
	 * @param array $old        Stored record.
	 * @param array $next       Record about to be saved.
	 * @param bool  $new_secret True when a new password or key was given.
	 */
	private static function same_connection( array $old, array $next, bool $new_secret ): bool {
		foreach ( array( 'type', 'host', 'port', 'username', 'auth', 'path', 'passive', 'host_key' ) as $key ) {
			if ( ( $old[ $key ] ?? null ) != $next[ $key ] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- an old record may hold the port as text.
				return false;
			}
		}
		return ! $new_secret;
	}

	/**
	 * Returns a clean host name or IP address, or an empty string.
	 *
	 * @param string $host Raw host.
	 * @return string
	 */
	private static function clean_host( string $host ): string {
		$host = trim( $host );
		if ( '' === $host || strlen( $host ) > 253 ) {
			return '';
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return $host;
		}
		$label = '[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?';
		return 1 === preg_match( '/^' . $label . '(\.' . $label . ')*$/i', $host ) ? $host : '';
	}

	/**
	 * Returns a valid port or null. Blank means the default for the type.
	 *
	 * @param mixed  $port Raw port.
	 * @param string $type Destination type.
	 * @return int|null
	 */
	private static function clean_port( $port, string $type ): ?int {
		if ( '' === $port || null === $port ) {
			return 'sftp' === $type ? 22 : 21;
		}
		if ( ! is_scalar( $port ) || 1 !== preg_match( '/^[0-9]{1,5}$/', (string) $port ) ) {
			return null;
		}
		$port = (int) $port;
		return ( $port >= 1 && $port <= 65535 ) ? $port : null;
	}

	/**
	 * Normalizes a remote path, or returns null when it is unsafe.
	 *
	 * @param string $path Raw path.
	 * @return string|null
	 */
	private static function clean_path( string $path ): ?string {
		$path = trim( $path );
		if ( strlen( $path ) > 500 || 1 === preg_match( '/[\x00-\x1f\x7f\\\\]/', $path ) ) {
			return null;
		}
		$parts = array();
		foreach ( explode( '/', $path ) as $part ) {
			if ( '..' === $part ) {
				return null;
			}
			if ( '' !== $part && '.' !== $part ) {
				$parts[] = $part;
			}
		}
		$joined = implode( '/', $parts );
		return ( 0 === strpos( $path, '/' ) || '' === $joined ) ? '/' . $joined : $joined;
	}

	/**
	 * Validates the secret and returns it encrypted.
	 *
	 * @param string     $auth     password or key.
	 * @param string     $secret   Raw secret, may be blank.
	 * @param array|null $existing Stored record when updating.
	 * @return string|WP_Error
	 */
	private static function clean_secret( string $auth, string $secret, ?array $existing ) {
		if ( '' === $secret ) {
			if ( null !== $existing && ! empty( $existing['secret'] ) && ( $existing['auth'] ?? 'password' ) === $auth ) {
				return (string) $existing['secret'];
			}
			return new WP_Error( 'opex_destination_secret', 'key' === $auth ? __( 'Paste the private key.', 'open-export' ) : __( 'Enter the password.', 'open-export' ) );
		}
		if ( 'key' === $auth ) {
			$secret = trim( str_replace( "\r\n", "\n", $secret ) ) . "\n";
			if ( strlen( $secret ) > self::MAX_KEY_SIZE || 1 !== preg_match( '/^-----BEGIN [A-Z ]*PRIVATE KEY-----\n.+-----END [A-Z ]*PRIVATE KEY-----\n$/s', $secret ) ) {
				return new WP_Error( 'opex_destination_key', __( 'The private key must be in PEM or OpenSSH form and under 16 KB.', 'open-export' ) );
			}
		} elseif ( strlen( $secret ) > self::MAX_PASSWORD ) {
			return new WP_Error( 'opex_destination_secret', __( 'The password is too long.', 'open-export' ) );
		}
		$encrypted = OPEX_Secrets::encrypt( $secret );
		if ( '' === $encrypted ) {
			return new WP_Error( 'opex_destination_secret', __( 'The secret could not be stored safely.', 'open-export' ) );
		}
		return $encrypted;
	}
}
