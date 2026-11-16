<?php
/**
 * SFTP delivery.
 *
 * @package OpenExport
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Uploads with phpseclib 3, loaded only when a send or test runs. If another
 * plugin already loaded phpseclib 3, that copy is used. The upload goes to a
 * temporary name and is renamed at the end.
 *
 * The server's host key is pinned on first use. The fingerprint is checked
 * before any credential is sent. A destination with no stored fingerprint
 * accepts the server once and stores the fingerprint after a good transfer.
 */
class OPEX_Delivery_Sftp {

	private const CONNECT_TIMEOUT = 15;
	private const IO_TIMEOUT      = 60;
	private const SFTP_CLASS      = 'phpseclib3\\Net\\SFTP';

	/**
	 * Uploads the export.
	 *
	 * @param array  $job         Job record.
	 * @param array  $destination Destination record (secret encrypted).
	 * @param string $file_path   Export file path.
	 * @return true|WP_Error
	 */
	public static function send( array $job, array $destination, string $file_path ) {
		$fingerprint = '';
		$sftp        = self::open( $destination, $fingerprint );
		if ( is_wp_error( $sftp ) ) {
			return $sftp;
		}
		try {
			$result = self::upload( $sftp, $file_path, OPEX_Delivery::remote_name( $job ) );
			self::remember( $destination, $fingerprint, $result );
			return $result;
		} finally {
			$sftp->disconnect();
		}
	}

	/**
	 * Connects, logs in, and proves the folder is writable.
	 *
	 * @param array $destination Destination record.
	 * @return true|WP_Error
	 */
	public static function test( array $destination ) {
		$fingerprint = '';
		$sftp        = self::open( $destination, $fingerprint );
		if ( is_wp_error( $sftp ) ) {
			return $sftp;
		}
		try {
			$probe = tempnam( get_temp_dir(), 'opex' );
			if ( ! $probe || false === file_put_contents( $probe, 'ok' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				return new WP_Error( 'opex_sftp_local', __( 'Could not create a local test file.', 'open-export' ) );
			}
			$name = 'opex-test-' . bin2hex( random_bytes( 6 ) ) . '.tmp';
			try {
				$result = self::upload( $sftp, $probe, $name );
			} finally {
				wp_delete_file( $probe );
			}
			if ( true === $result ) {
				$sftp->delete( $name );
			}
			self::remember( $destination, $fingerprint, $result );
			return $result;
		} finally {
			$sftp->disconnect();
		}
	}

	/**
	 * Pins the fingerprint after a good transfer when none is stored yet.
	 *
	 * @param array         $d           Destination record.
	 * @param string        $fingerprint Fingerprint seen on this connection.
	 * @param true|WP_Error $result      Outcome of the transfer.
	 * @return void
	 */
	private static function remember( array $d, string $fingerprint, $result ): void {
		if ( true === $result && '' === (string) ( $d['host_key'] ?? '' ) && '' !== (string) ( $d['id'] ?? '' ) && '' !== $fingerprint ) {
			OPEX_Destinations::pin_host_key( (string) $d['id'], $fingerprint );
		}
	}

	/**
	 * SHA-256 fingerprint of the server's host key, OpenSSH style: the
	 * base64 of the hash of the key blob, without padding. The key comes
	 * from SSH2::getServerPublicHostKey(), which connects, finishes the key
	 * exchange and checks the server's signature, but sends no credential.
	 *
	 * @param object $sftp SFTP object, not yet logged in.
	 * @return string Empty when the key could not be read.
	 */
	private static function fingerprint( $sftp ): string {
		try {
			$line = $sftp->getServerPublicHostKey();
		} catch ( Throwable $e ) {
			return '';
		}
		$parts = is_string( $line ) ? explode( ' ', $line ) : array();
		$blob  = isset( $parts[1] ) ? base64_decode( $parts[1], true ) : false;
		if ( false === $blob || '' === $blob ) {
			return '';
		}
		return 'SHA256:' . rtrim( base64_encode( hash( 'sha256', $blob, true ) ), '=' );
	}

	/**
	 * Loads phpseclib when no other copy is present.
	 *
	 * @return bool
	 */
	private static function load_library(): bool {
		if ( class_exists( self::SFTP_CLASS, false ) ) {
			return true;
		}
		$autoload = dirname( __DIR__ ) . '/lib/vendor/autoload.php';
		if ( ! is_readable( $autoload ) ) {
			return false;
		}
		require_once $autoload;
		return class_exists( self::SFTP_CLASS );
	}

	/**
	 * Opens, logs in and changes to the remote folder.
	 *
	 * @param array  $d           Destination record.
	 * @param string $fingerprint Set to the server's fingerprint.
	 * @return object|WP_Error An SFTP object.
	 */
	private static function open( array $d, string &$fingerprint ) {
		if ( ! self::load_library() ) {
			return new WP_Error( 'opex_sftp_lib', __( 'The SFTP library is missing from the plugin.', 'open-export' ) );
		}
		$secret = OPEX_Secrets::decrypt( (string) ( $d['secret'] ?? '' ) );
		if ( null === $secret ) {
			return new WP_Error( 'opex_sftp_secret', __( 'The saved password or key cannot be read. Enter it again.', 'open-export' ) );
		}
		$credential = $secret;
		if ( 'key' === ( $d['auth'] ?? 'password' ) ) {
			try {
				$credential = \phpseclib3\Crypt\PublicKeyLoader::load( $secret );
			} catch ( Throwable $e ) {
				return new WP_Error( 'opex_sftp_key', __( 'The private key could not be read. Keys with a passphrase are not supported.', 'open-export' ) );
			}
		}

		$class = self::SFTP_CLASS;
		$sftp  = new $class( (string) ( $d['host'] ?? '' ), (int) ( $d['port'] ?? 22 ), self::CONNECT_TIMEOUT );
		$sftp->setTimeout( self::IO_TIMEOUT );

		// Check who the server is before any credential leaves this machine.
		$fingerprint = self::fingerprint( $sftp );
		if ( '' === $fingerprint ) {
			$sftp->disconnect();
			return new WP_Error( 'opex_sftp_connect', __( 'Could not connect to the server.', 'open-export' ) );
		}
		$pinned = trim( (string) ( $d['host_key'] ?? '' ) );
		if ( '' !== $pinned && ! hash_equals( $pinned, $fingerprint ) ) {
			$sftp->disconnect();
			return new WP_Error(
				'opex_sftp_hostkey',
				__( 'The server\'s identity has changed since this destination was set up. Nothing was sent, not even your password. If the server was rebuilt on purpose, reset the saved host key on the destination and test it again.', 'open-export' )
			);
		}

		try {
			$ok = $sftp->login( (string) ( $d['username'] ?? '' ), $credential );
		} catch ( Throwable $e ) {
			$ok = false;
		}
		if ( ! $ok ) {
			$connected = $sftp->isConnected();
			$sftp->disconnect();
			return new WP_Error(
				'opex_sftp_login',
				$connected ? __( 'The server refused the login.', 'open-export' ) : __( 'Could not connect to the server.', 'open-export' )
			);
		}
		$path = (string) ( $d['path'] ?? '/' );
		if ( '' !== $path && ! $sftp->chdir( $path ) ) {
			$sftp->disconnect();
			return new WP_Error( 'opex_sftp_path', __( 'The remote folder was not found.', 'open-export' ) );
		}
		return $sftp;
	}

	/**
	 * Uploads to a temporary name, then renames.
	 *
	 * @param object $sftp  Connected SFTP object.
	 * @param string $local Local file.
	 * @param string $name  Final remote name.
	 * @return true|WP_Error
	 */
	private static function upload( $sftp, string $local, string $name ) {
		$temp = '.' . $name . '.' . bin2hex( random_bytes( 4 ) ) . '.part';
		if ( ! $sftp->put( $temp, $local, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE ) ) {
			$sftp->delete( $temp );
			return new WP_Error( 'opex_sftp_put', __( 'The upload failed. Check that the folder is writable.', 'open-export' ) );
		}
		if ( ! $sftp->rename( $temp, $name ) ) {
			$sftp->delete( $name );
			if ( ! $sftp->rename( $temp, $name ) ) {
				$sftp->delete( $temp );
				return new WP_Error( 'opex_sftp_rename', __( 'The file was uploaded but could not be renamed.', 'open-export' ) );
			}
		}
		return true;
	}
}
