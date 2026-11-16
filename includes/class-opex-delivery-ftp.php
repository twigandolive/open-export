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
		if ( defined( 'FTP_USEPASVADDRESS' ) ) {
			// Connect the data channel to the server we logged in to, not to the
			// address its passive reply names, which a hostile server could point
			// at another machine on this network. Must be set before ftp_pasv().
			@ftp_set_option( $conn, FTP_USEPASVADDRESS, false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( ! @ftp_login( $conn, (string) ( $d['username'] ?? '' ), $password ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@ftp_close( $conn ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'opex_ftp_login', __( 'The server refused the user name or password.', 'open-export' ) );
		}
		if ( ! @ftp_pasv( $conn, ! isset( $d['passive'] ) || (bool) $d['passive'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@ftp_close( $conn ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'opex_ftp_pasv', __( 'Could not set the transfer mode.', 'open-export' ) );
		}
		// "/" is changed to as well: a login folder is often not the server's root.
		$path = (string) ( $d['path'] ?? '/' );
		if ( '' !== $path && ! @ftp_chdir( $conn, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@ftp_close( $conn ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'opex_ftp_path', __( 'The remote folder was not found.', 'open-export' ) );
		}
		return $conn;
	}

	/**
	 * Uploads to a temporary name, then renames it to the final name.
	 *
	 * Most servers rename over an existing file. Some refuse to (IIS does).
	 * Then the old file is first renamed aside to a backup name, the upload
	 * takes its place, and only then is the backup removed. If the upload
	 * cannot take its place, the backup is renamed back. The old file is
	 * never deleted before the new one is in place, and on any failure the
	 * only file removed is this run's own temporary upload.
	 *
	 * @param resource|object $conn  Connection.
	 * @param string          $local Local file.
	 * @param string          $name  Final remote name.
	 * @return true|WP_Error True, or why the file is not in place.
	 */
	private static function upload( $conn, string $local, string $name ) {
		$tag  = bin2hex( random_bytes( 4 ) );
		$temp = '.' . $name . '.' . $tag . '.part';
		if ( ! @ftp_put( $conn, $temp, $local, FTP_BINARY ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@ftp_delete( $conn, $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'opex_ftp_put', __( 'The upload failed. Check that the folder is writable.', 'open-export' ) );
		}
		if ( @ftp_rename( $conn, $temp, $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return true;
		}
		// The rename failed. If a file has the final name, move it aside, never delete it first.
		$backup = '.' . $name . '.' . $tag . '.old';
		if ( ! @ftp_rename( $conn, $name, $backup ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@ftp_delete( $conn, $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'opex_ftp_rename', __( 'The file was uploaded but could not be renamed. The server may not allow renaming files in this folder. Nothing on the server was changed.', 'open-export' ) );
		}
		if ( @ftp_rename( $conn, $temp, $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@ftp_delete( $conn, $backup ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return true;
		}
		$restored = @ftp_rename( $conn, $backup, $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@ftp_delete( $conn, $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return self::rename_failed( 'opex_ftp_rename', $restored, $backup );
	}

	/**
	 * The error for an upload that could not take the final name.
	 *
	 * @param string $code     Error code.
	 * @param bool   $restored True when the old file is back under its name.
	 * @param string $backup   Name the old file is kept under when it is not.
	 * @return WP_Error The error to report.
	 */
	public static function rename_failed( string $code, bool $restored, string $backup ): WP_Error {
		if ( $restored ) {
			return new WP_Error( $code, __( 'The file was uploaded but could not replace the file already on the server. The old file was kept.', 'open-export' ) );
		}
		return new WP_Error(
			$code,
			sprintf(
				/* translators: %s: the name the old file is kept under on the server. */
				__( 'The file was uploaded but could not replace the file already on the server. The old file is kept on the server as %s.', 'open-export' ),
				$backup
			)
		);
	}
}
