<?php
/**
 * FTP and FTPS delivery.
 *
 * @package OpenExport
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Uploads with the PHP ftp extension. The file goes up under a temporary
 * name and is renamed at the end, so a watcher never sees half a file.
 * Note: PHP's ftp extension does not verify the FTPS server certificate.
 */
class OPEX_Delivery_Ftp {

	private const CONNECT_TIMEOUT = 15;
	private const IO_TIMEOUT      = 60;

	/**
	 * Uploads the export.
	 *
	 * @param array  $job         Job record.
	 * @param array  $destination Destination record (secret encrypted).
	 * @param string $file_path   Export file path.
	 * @return true|WP_Error
	 */
	public static function send( array $job, array $destination, string $file_path ) {
		$conn = self::open( $destination );
		if ( is_wp_error( $conn ) ) {
			return $conn;
		}
		try {
			return self::upload( $conn, $file_path, OPEX_Delivery::remote_name( $job ) );
		} finally {
			@ftp_close( $conn ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Connects, logs in, and proves the folder is writable.
	 *
	 * @param array $destination Destination record.
	 * @return true|WP_Error
	 */
	public static function test( array $destination ) {
		$conn = self::open( $destination );
		if ( is_wp_error( $conn ) ) {
			return $conn;
		}
		try {
			$probe = tempnam( get_temp_dir(), 'opex' );
			if ( ! $probe || false === file_put_contents( $probe, 'ok' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				return new WP_Error( 'opex_ftp_local', __( 'Could not create a local test file.', 'open-export' ) );
			}
			$name = 'opex-test-' . bin2hex( random_bytes( 6 ) ) . '.tmp';
			try {
				$result = self::upload( $conn, $probe, $name );
			} finally {
				wp_delete_file( $probe );
			}
			if ( true === $result ) {
				@ftp_delete( $conn, $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			return $result;
		} finally {
			@ftp_close( $conn ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Opens, logs in and changes to the remote folder.
	 *
	 * @param array $d Destination record.
	 * @return resource|object|WP_Error
	 */
	private static function open( array $d ) {
		if ( ! function_exists( 'ftp_connect' ) ) {
			return new WP_Error( 'opex_ftp_missing', __( 'The PHP ftp extension is not installed on this server.', 'open-export' ) );
		}
		$password = OPEX_Secrets::decrypt( (string) ( $d['secret'] ?? '' ) );
		if ( null === $password ) {
			return new WP_Error( 'opex_ftp_secret', __( 'The saved password cannot be read. Enter it again.', 'open-export' ) );
		}
		$host = (string) ( $d['host'] ?? '' );
		$port = (int) ( $d['port'] ?? 21 );

		if ( 'ftps' === ( $d['type'] ?? '' ) ) {
			if ( ! function_exists( 'ftp_ssl_connect' ) ) {
				return new WP_Error( 'opex_ftp_ssl', __( 'This server cannot make secure FTP connections.', 'open-export' ) );
			}
			$conn = @ftp_ssl_connect( $host, $port, self::CONNECT_TIMEOUT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} else {
			$conn = @ftp_connect( $host, $port, self::CONNECT_TIMEOUT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( ! $conn ) {
			return new WP_Error( 'opex_ftp_connect', __( 'Could not connect to the server.', 'open-export' ) );
		}
		@ftp_set_option( $conn, FTP_TIMEOUT_SEC, self::IO_TIMEOUT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! @ftp_login( $conn, (string) ( $d['username'] ?? '' ), $password ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@ftp_close( $conn ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'opex_ftp_login', __( 'The server refused the user name or password.', 'open-export' ) );
		}
		if ( ! @ftp_pasv( $conn, ! isset( $d['passive'] ) || (bool) $d['passive'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@ftp_close( $conn ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'opex_ftp_pasv', __( 'Could not set the transfer mode.', 'open-export' ) );
		}
		$path = (string) ( $d['path'] ?? '/' );
		if ( '' !== $path && '/' !== $path && ! @ftp_chdir( $conn, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@ftp_close( $conn ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'opex_ftp_path', __( 'The remote folder was not found.', 'open-export' ) );
		}
		return $conn;
	}

	/**
	 * Uploads to a temporary name, then renames.
	 *
	 * @param resource|object $conn  Connection.
	 * @param string          $local Local file.
	 * @param string          $name  Final remote name.
	 * @return true|WP_Error
	 */
	private static function upload( $conn, string $local, string $name ) {
		$temp = '.' . $name . '.' . bin2hex( random_bytes( 4 ) ) . '.part';
		if ( ! @ftp_put( $conn, $temp, $local, FTP_BINARY ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@ftp_delete( $conn, $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'opex_ftp_put', __( 'The upload failed. Check that the folder is writable.', 'open-export' ) );
		}
		if ( ! @ftp_rename( $conn, $temp, $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			// Some servers refuse to rename over an existing file.
			@ftp_delete( $conn, $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! @ftp_rename( $conn, $temp, $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@ftp_delete( $conn, $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return new WP_Error( 'opex_ftp_rename', __( 'The file was uploaded but could not be renamed.', 'open-export' ) );
			}
		}
		return true;
	}
}
