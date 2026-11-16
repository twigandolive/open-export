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
	 * Seconds an upload may make no progress before it is given up. Only tests change it, through reflection.
	 *
	 * @var int|float
	 */
	private static $stall = self::IO_TIMEOUT;

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
				return new WP_Error( 'opex_ftp_local', __( 'Open Export could not create a local test file.', 'open-export' ) );
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
			return new WP_Error( 'opex_ftp_connect', __( 'Open Export could not connect to the server.', 'open-export' ) );
		}
		@ftp_set_option( $conn, FTP_TIMEOUT_SEC, self::IO_TIMEOUT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( defined( 'FTP_USEPASVADDRESS' ) ) {
			// Connect the data channel to the server Open Export logged in to, not to the
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
			return new WP_Error( 'opex_ftp_pasv', __( 'Open Export could not set the transfer mode on the server.', 'open-export' ) );
		}
		// "/" is changed to as well: a login folder is often not the server's root.
		$path = (string) ( $d['path'] ?? '/' );
		if ( '' !== $path && ! @ftp_chdir( $conn, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@ftp_close( $conn ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'opex_ftp_path', __( 'Open Export could not find the remote folder.', 'open-export' ) );
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
		$sent = self::put( $conn, $temp, $local );
		if ( 'lost' === $sent ) {
			@ftp_delete( $conn, $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return OPEX_Delivery::lost_error( 'opex_ftp_lost' );
		}
		if ( 'sent' !== $sent ) {
			// A stalled transfer still holds the connection, so a delete there would only wait.
			if ( 'failed' === $sent ) {
				@ftp_delete( $conn, $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			return new WP_Error( 'opex_ftp_put', __( 'The upload failed. Check that the folder is writable.', 'open-export' ) );
		}
		if ( @ftp_rename( $conn, $temp, $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return true;
		}
		// The rename failed. If a file has the final name, move it aside, never delete it first.
		$backup = '.' . $name . '.' . $tag . '.old';
		if ( ! @ftp_rename( $conn, $name, $backup ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@ftp_delete( $conn, $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'opex_ftp_rename', __( 'Open Export uploaded the file but could not rename it. The server may not allow renaming files in this folder. Open Export removed the upload, so nothing on the server changed.', 'open-export' ) );
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
	 * Sends the file without blocking, so the delivery lock can be renewed
	 * while a long upload runs. When the lock is lost, the read position
	 * jumps to the end of the file: the upload then ends at once but
	 * cleanly, and the connection stays usable to remove the partial file.
	 *
	 * @param resource|object $conn  Connection, with passive mode and FTPS already set by open().
	 * @param string          $temp  Remote temporary name.
	 * @param string          $local Local file.
	 * @return string 'sent', 'failed', 'stalled' (no progress for too long) or 'lost' (the lock was lost).
	 */
	private static function put( $conn, string $temp, string $local ): string {
		$fp = @fopen( $local, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- the export file, streamed to the FTP data connection.
		if ( false === $fp ) {
			return 'failed';
		}
		try {
			$state = 'sent';
			$beat  = microtime( true );
			$moved = $beat;
			$at    = 0;
			$ret   = @ftp_nb_fput( $conn, $temp, $fp, FTP_BINARY ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			while ( FTP_MOREDATA === $ret ) {
				$pos = (int) ftell( $fp );
				if ( $pos !== $at ) {
					$at    = $pos;
					$moved = microtime( true );
				} elseif ( microtime( true ) - $moved > self::$stall ) {
					// ftp_put gave up after the timeout. The loop must too.
					return 'stalled';
				}
				if ( 'sent' === $state && ! OPEX_Delivery::transfer_alive( $beat ) ) {
					$state = 'lost';
					fseek( $fp, 0, SEEK_END );
				}
				$ret = @ftp_nb_continue( $conn ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			if ( FTP_FINISHED !== $ret ) {
				return 'lost' === $state ? 'lost' : 'failed';
			}
			return $state;
		} finally {
			fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
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
			return new WP_Error( $code, __( 'Open Export uploaded the file but could not replace the file already on the server. It kept the old file.', 'open-export' ) );
		}
		return new WP_Error(
			$code,
			sprintf(
				/* translators: %s: the name the old file is kept under on the server. */
				__( 'Open Export uploaded the file but could not replace the file already on the server. It kept the old file on the server as %s.', 'open-export' ),
				$backup
			)
		);
	}
}
