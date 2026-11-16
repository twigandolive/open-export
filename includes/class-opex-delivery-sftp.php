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
	private const KEY_LOADER      = 'phpseclib3\\Crypt\\PublicKeyLoader';

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
				return new WP_Error( 'opex_sftp_local', __( 'Open Export could not create a local test file.', 'open-export' ) );
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
	 * The record this connection used goes along, so the pin is dropped when
	 * someone pointed the destination at another server in the meantime.
	 *
	 * @param array         $d           Destination record the connection used.
	 * @param string        $fingerprint Fingerprint seen on this connection.
	 * @param true|WP_Error $result      Outcome of the transfer.
	 * @return void
	 */
	private static function remember( array $d, string $fingerprint, $result ): void {
		if ( true === $result && '' === (string) ( $d['host_key'] ?? '' ) && '' !== (string) ( $d['id'] ?? '' ) && '' !== $fingerprint ) {
			OPEX_Destinations::pin_host_key( (string) $d['id'], $fingerprint, $d );
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
		// The host key arrives base64 encoded, and fingerprints are written in base64 too (as ssh-keygen shows them).
		$blob = isset( $parts[1] ) ? base64_decode( $parts[1], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding a public host key.
		if ( false === $blob || '' === $blob ) {
			return '';
		}
		return 'SHA256:' . rtrim( base64_encode( hash( 'sha256', $blob, true ) ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the standard SHA256 fingerprint form.
	}

	/**
	 * Loads phpseclib 3 for a send.
	 *
	 * The bundled copy is not prefixed, so it shares the phpseclib3 namespace
	 * with any copy another plugin brings. When another copy is already loaded,
	 * or an autoloader that is already registered can load it, that copy is
	 * used for every class and the bundled autoloader is never registered.
	 * Two copies loading classes side by side is what goes wrong. The bundled
	 * copy loads only when nothing else provides phpseclib 3.
	 *
	 * @return bool
	 */
	private static function load_library(): bool {
		if ( class_exists( self::SFTP_CLASS ) && class_exists( self::KEY_LOADER ) ) {
			return true;
		}
		$autoload = dirname( __DIR__ ) . '/lib/vendor/autoload.php';
		if ( ! is_readable( $autoload ) ) {
			return false;
		}
		require_once $autoload;
		return class_exists( self::SFTP_CLASS ) && class_exists( self::KEY_LOADER );
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
			return new WP_Error( 'opex_sftp_lib', __( 'The SFTP library is missing from Open Export. Install the plugin again.', 'open-export' ) );
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
				return new WP_Error( 'opex_sftp_key', __( 'Open Export could not read the private key. It cannot use a key that has a passphrase.', 'open-export' ) );
			}
		}

		$class = self::SFTP_CLASS;
		$sftp  = new $class( (string) ( $d['host'] ?? '' ), (int) ( $d['port'] ?? 22 ), self::CONNECT_TIMEOUT );
		$sftp->setTimeout( self::IO_TIMEOUT );

		// Check who the server is before any credential leaves this machine.
		$fingerprint = self::fingerprint( $sftp );
		if ( '' === $fingerprint ) {
			$sftp->disconnect();
			return new WP_Error( 'opex_sftp_connect', __( 'Open Export could not connect to the server.', 'open-export' ) );
		}
		$pinned = trim( (string) ( $d['host_key'] ?? '' ) );
		if ( '' !== $pinned && ! hash_equals( $pinned, $fingerprint ) ) {
			$sftp->disconnect();
			return new WP_Error(
				'opex_sftp_hostkey',
				__( 'The server\'s identity has changed since this destination was set up. Open Export sent nothing, not even your password. If someone rebuilt the server on purpose, reset the saved fingerprint on the destination and test it again.', 'open-export' )
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
				$connected ? __( 'The server refused the login.', 'open-export' ) : __( 'Open Export could not connect to the server.', 'open-export' )
			);
		}
		$path = (string) ( $d['path'] ?? '/' );
		if ( '' !== $path && ! $sftp->chdir( $path ) ) {
			$sftp->disconnect();
			return new WP_Error( 'opex_sftp_path', __( 'Open Export could not find the remote folder.', 'open-export' ) );
		}
		return $sftp;
	}

	/**
	 * Uploads to a temporary name, then renames it to the final name.
	 *
	 * The plain SFTP rename never replaces a file. When the server offers
	 * the posix-rename extension (OpenSSH does), that replaces the old file
	 * in one step. Otherwise the old file is renamed aside to a backup name,
	 * the upload takes its place, and only then is the backup removed. If
	 * the upload cannot take its place, the backup is renamed back. The old
	 * file is never deleted before the new one is in place, and on any
	 * failure the only file removed is this run's own temporary upload.
	 *
	 * @param object $sftp  Connected SFTP object.
	 * @param string $local Local file.
	 * @param string $name  Final remote name.
	 * @return true|WP_Error True, or why the file is not in place.
	 */
	private static function upload( $sftp, string $local, string $name ) {
		$tag  = bin2hex( random_bytes( 4 ) );
		$temp = '.' . $name . '.' . $tag . '.part';
		$sent = self::put( $sftp, $temp, $local );
		if ( 'sent' !== $sent ) {
			$sftp->delete( $temp );
			return 'lost' === $sent ? OPEX_Delivery::lost_error( 'opex_sftp_lost' ) : new WP_Error( 'opex_sftp_put', __( 'The upload failed. Check that the folder is writable.', 'open-export' ) );
		}
		if ( self::posix_rename( $sftp, $temp, $name ) || $sftp->rename( $temp, $name ) ) {
			return true;
		}
		// The rename failed. If a file has the final name, move it aside, never delete it first.
		$backup = '.' . $name . '.' . $tag . '.old';
		if ( ! $sftp->rename( $name, $backup ) ) {
			$sftp->delete( $temp );
			return new WP_Error( 'opex_sftp_rename', __( 'Open Export uploaded the file but could not rename it. The server may not allow renaming files in this folder. Open Export removed the upload, so nothing on the server changed.', 'open-export' ) );
		}
		if ( $sftp->rename( $temp, $name ) ) {
			$sftp->delete( $backup );
			return true;
		}
		$restored = $sftp->rename( $backup, $name );
		$sftp->delete( $temp );
		return OPEX_Delivery_Ftp::rename_failed( 'opex_sftp_rename', $restored, $backup );
	}

	/**
	 * Sends the file one packet at a time through a data callback, so the
	 * delivery lock can be renewed while a long upload runs. When the lock is
	 * lost, the callback ends the data. phpseclib then closes the remote file
	 * normally, and the same connection can remove the partial upload.
	 * phpseclib's progress callback cannot stop an upload without a throw,
	 * which would leave unread replies on the connection.
	 *
	 * @param object $sftp  Connected SFTP object.
	 * @param string $temp  Remote temporary name.
	 * @param string $local Local file.
	 * @return string 'sent', 'failed' or 'lost' (the lock was lost).
	 */
	private static function put( $sftp, string $temp, string $local ): string {
		$fp = @fopen( $local, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- the export file, read packet by packet.
		if ( false === $fp ) {
			return 'failed';
		}
		$state = 'sent';
		$beat  = microtime( true );
		$read  = static function ( $length ) use ( $fp, &$state, &$beat ) {
			if ( 'sent' !== $state ) {
				return null;
			}
			if ( ! OPEX_Delivery::transfer_alive( $beat ) ) {
				$state = 'lost';
				return null;
			}
			$chunk = fread( $fp, max( 1, (int) $length ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $chunk ) {
				$state = 'failed';
				return null;
			}
			// Null, not an empty string, is what tells phpseclib the data ended.
			return '' === $chunk ? null : $chunk;
		};
		try {
			$ok = $sftp->put( $temp, $read, \phpseclib3\Net\SFTP::SOURCE_CALLBACK );
		} finally {
			fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		return $ok ? $state : ( 'lost' === $state ? 'lost' : 'failed' );
	}

	/**
	 * Renames over an existing file with the posix-rename extension, when
	 * the server offers it and the loaded phpseclib knows it.
	 *
	 * @param object $sftp Connected SFTP object.
	 * @param string $from Temporary name.
	 * @param string $to   Final name.
	 * @return bool True when the file now has the final name.
	 */
	private static function posix_rename( $sftp, string $from, string $to ): bool {
		if ( ! method_exists( $sftp, 'posix_rename' ) || ! method_exists( $sftp, 'getSupportedExtensions' ) ) {
			return false;
		}
		try {
			$extensions = $sftp->getSupportedExtensions();
			if ( ! is_array( $extensions ) || '1' !== (string) ( $extensions['posix-rename@openssh.com'] ?? '' ) ) {
				return false;
			}
			return true === $sftp->posix_rename( $from, $to );
		} catch ( Throwable $e ) {
			return false;
		}
	}
}
