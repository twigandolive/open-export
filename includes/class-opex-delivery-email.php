<?php
/**
 * Email delivery.
 *
 * @package OpenExport
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Sends a finished export by email, as a download link or an attachment.
 */
class OPEX_Delivery_Email {

	private const MAX_ATTACH = 10 * MB_IN_BYTES;
	private const MAX_TO     = 10;

	/**
	 * Sends the email.
	 *
	 * @param array  $job       Job record.
	 * @param array  $settings  Email settings: to, mode, subject.
	 * @param string $file_path Export file path.
	 * @return true|WP_Error
	 */
	public static function send( array $job, array $settings, string $file_path ) {
		$to = array();
		foreach ( (array) ( $settings['to'] ?? array() ) as $address ) {
			$address = is_string( $address ) ? trim( $address ) : '';
			if ( '' !== $address && false !== is_email( $address ) && 1 !== preg_match( '/[\r\n,;<>]/', $address ) ) {
				$to[] = $address;
			}
		}
		$to = array_slice( array_values( array_unique( $to ) ), 0, self::MAX_TO );
		if ( ! $to ) {
			return new WP_Error( 'opex_email_to', __( 'The email delivery has no valid address.', 'open-export' ) );
		}

		$name   = '' !== (string) ( $job['delivery_name'] ?? '' ) ? (string) $job['delivery_name'] : (string) ( $job['label'] ?? '' );
		$rows   = (int) ( $job['processed'] ?? 0 );
		$size   = (int) ( $job['filesize'] ?? 0 );
		$finish = (int) ( $job['finished'] ?? 0 );
		$when   = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $finish > 0 ? $finish : time() );

		$attach_ok = 'attach' === ( $settings['mode'] ?? 'link' ) && $size <= self::MAX_ATTACH && filesize( $file_path ) <= self::MAX_ATTACH;

		$subject = trim( (string) preg_replace( '/[\r\n]+/', ' ', (string) ( $settings['subject'] ?? '' ) ) );
		if ( '' === $subject ) {
			/* translators: %s: export name. */
			$subject = sprintf( __( 'Your export is ready: %s', 'open-export' ), $name );
		}
		$subject = (string) preg_replace( '/[\r\n]+/', ' ', $subject );

		/* translators: 1: export name, 2: row count, 3: file size, 4: finish time. */
		$lines   = array( sprintf( __( 'Your export "%1$s" finished on %4$s. It has %2$s rows and is %3$s.', 'open-export' ), self::one_line( $name ), number_format_i18n( $rows ), size_format( $size ), $when ), '' );
		$attachs = array();
		$temp    = '';

		if ( $attach_ok ) {
			$temp = self::temp_copy( $file_path, OPEX_Delivery::remote_name( $job ) );
			if ( '' === $temp ) {
				$attach_ok = false;
			} else {
				$attachs[] = $temp;
				$lines[]   = __( 'The file is attached to this email.', 'open-export' );
			}
		}
		if ( ! $attach_ok ) {
			$days    = (int) OPEX_Settings::get( 'link_days' );
			$token   = OPEX_Download_Tokens::issue_saved( $job, DAY_IN_SECONDS * $days );
			$expires = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), time() + DAY_IN_SECONDS * $days );
			$lines[] = __( 'To download the file, open this link:', 'open-export' );
			$lines[] = OPEX_Download_Tokens::url( $job, $token );
			$lines[] = '';
			/* translators: %s: expiry date and time. */
			$lines[] = sprintf( __( 'The link stops working on %s.', 'open-export' ), $expires );
		}

		try {
			$sent = wp_mail( $to, $subject, implode( "\n", $lines ), array( 'Content-Type: text/plain; charset=UTF-8' ), $attachs );
		} finally {
			self::remove_temp( $temp );
		}
		return $sent ? true : new WP_Error( 'opex_email_failed', __( 'The mail system of the site did not accept the email. Check the email settings of the site.', 'open-export' ) );
	}

	/**
	 * Strips line breaks from a value used in text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function one_line( string $text ): string {
		return trim( (string) preg_replace( '/[\r\n]+/', ' ', $text ) );
	}

	/**
	 * Copies the export to a private temp folder under its friendly name.
	 *
	 * @param string $path Source file.
	 * @param string $name Attachment name.
	 * @return string Copy path, or an empty string on failure.
	 */
	private static function temp_copy( string $path, string $name ): string {
		$dir = trailingslashit( get_temp_dir() ) . 'opex-' . bin2hex( random_bytes( 8 ) );
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		$dest = $dir . '/' . $name;
		if ( ! copy( $path, $dest ) ) {
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			return '';
		}
		return $dest;
	}

	/**
	 * Removes the temp copy and its folder.
	 *
	 * @param string $copy Copy path or blank.
	 * @return void
	 */
	private static function remove_temp( string $copy ): void {
		if ( '' === $copy ) {
			return;
		}
		wp_delete_file( $copy );
		rmdir( dirname( $copy ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
