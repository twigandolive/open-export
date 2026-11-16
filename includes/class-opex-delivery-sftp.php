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
 * temporary name and is renamed at the end. The server host key is not pinned
 * (the destination record has no field for it).
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
		$sftp = self::open( $destination );
		if ( is_wp_error( $sftp ) ) {
			return $sftp;
		}
		try {
			return self::upload( $sftp, $file_path, OPEX_Delivery::remote_name( $job ) );
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
		$sftp = self::open( $destination );
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
			return $result;
		} finally {
			$sftp->disconnect();
		}
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
	 * @param array $d Destination record.
	 * @return object|WP_Error An SFTP object.
	 */
	private static function open( array $d ) {
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
