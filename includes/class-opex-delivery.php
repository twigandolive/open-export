<?php
/**
 * Delivery planning and processing for finished exports.
 *
 * @package OpenExport
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Sanitizes delivery settings, plans the items for a job and runs each once.
 * A failure never stops the export itself: it only marks one item failed.
 */
class OPEX_Delivery {

	public const MAX_ATTEMPTS = 3;
	private const CHANNELS    = array( 'email', 'webhook', 'destination' );

	/**
	 * Cleans delivery settings from the browser or a schedule.
	 *
	 * @param mixed $raw   Raw input.
	 * @param array $notes Plain-language notes, added to.
	 * @return array Keys email, webhook and destination.
	 */
	public static function sanitize( $raw, array &$notes ): array {
		$raw = is_array( $raw ) ? $raw : array();

		$email   = is_array( $raw['email'] ?? null ) ? $raw['email'] : array();
		$to      = array();
		$source  = isset( $email['to'] ) ? ( is_array( $email['to'] ) ? $email['to'] : preg_split( '/[\s,;]+/', (string) $email['to'] ) ) : array();
		foreach ( $source as $address ) {
			$address = is_string( $address ) ? trim( $address ) : '';
			if ( '' === $address ) {
				continue;
			}
			if ( false === is_email( $address ) || 1 === preg_match( '/[\r\n]/', $address ) ) {
				$notes[] = __( 'An invalid email address was removed.', 'open-export' );
				continue;
			}
			$to[] = $address;
		}
		$to = array_slice( array_values( array_unique( $to ) ), 0, 10 );
		$on = ! empty( $email['enabled'] );
		if ( $on && ! $to ) {
			$on      = false;
			$notes[] = __( 'Email delivery was turned off because it has no valid address.', 'open-export' );
		}
		$mode = ( $email['mode'] ?? 'link' );

		$hook   = is_array( $raw['webhook'] ?? null ) ? $raw['webhook'] : array();
		$url    = esc_url_raw( trim( (string) ( $hook['url'] ?? '' ) ), array( 'http', 'https' ) );
		$hon    = ! empty( $hook['enabled'] );
		if ( '' !== $url && false === wp_http_validate_url( $url ) && $hon ) {
			$hon     = false;
			$notes[] = __( 'Webhook delivery was turned off because its URL is not allowed.', 'open-export' );
		} elseif ( $hon && '' === $url ) {
			$hon     = false;
			$notes[] = __( 'Webhook delivery was turned off because it has no URL.', 'open-export' );
		}
		$secret = (string) ( $hook['secret'] ?? '' );
		if ( '' !== $secret && ! OPEX_Secrets::is_encrypted( $secret ) ) {
			$secret = OPEX_Secrets::encrypt( mb_substr( $secret, 0, 256 ) );
		}

		$dest = is_array( $raw['destination'] ?? null ) ? $raw['destination'] : array();
		$did  = (string) ( $dest['id'] ?? '' );
		$don  = ! empty( $dest['enabled'] );
		if ( $don && ( 1 !== preg_match( '/^d[0-9a-f]{12}$/', $did ) || null === OPEX_Destinations::get( $did ) ) ) {
			$don     = false;
			$notes[] = __( 'Destination delivery was turned off because the destination does not exist.', 'open-export' );
		}

		return array(
			'email'       => array(
				'enabled' => $on,
				'to'      => $to,
				'mode'    => 'attach' === $mode ? 'attach' : 'link',
				'subject' => mb_substr( trim( preg_replace( '/[\r\n]+/', ' ', sanitize_text_field( (string) ( $email['subject'] ?? '' ) ) ) ), 0, 200 ),
			),
			'webhook'     => array(
				'enabled' => $hon,
				'url'     => $url,
				'secret'  => $secret,
			),
			'destination' => array(
				'enabled' => $don,
				'id'      => 1 === preg_match( '/^d[0-9a-f]{12}$/', $did ) ? $did : '',
			),
		);
	}

	/**
	 * Builds the pending items for the enabled channels.
	 *
	 * @param array $delivery Sanitized delivery settings.
	 * @return array
	 */
	public static function plan( array $delivery ): array {
		$items = array();
		foreach ( self::CHANNELS as $channel ) {
			if ( ! empty( $delivery[ $channel ]['enabled'] ) ) {
				$items[] = array(
					'channel'  => $channel,
					'status'   => 'pending',
					'attempts' => 0,
					'message'  => '',
					'time'     => 0,
				);
			}
		}
		return $items;
	}

	/**
	 * Runs each pending or retryable item once.
	 *
	 * @param array $job      Job record (uses its deliveries list when present).
	 * @param array $delivery Delivery settings the items came from.
	 * @return array The updated deliveries list. The caller saves it.
	 */
	public static function process( array $job, array $delivery ): array {
		$items = isset( $job['deliveries'] ) && is_array( $job['deliveries'] ) ? array_values( $job['deliveries'] ) : self::plan( $delivery );
		$path  = OPEX_Jobs::file_path( $job );

		foreach ( $items as $i => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['channel'] ) ) {
				continue;
			}
			$status   = (string) ( $item['status'] ?? 'pending' );
			$attempts = (int) ( $item['attempts'] ?? 0 );
			if ( 'sent' === $status || $attempts >= self::MAX_ATTEMPTS || ! in_array( $status, array( 'pending', 'failed' ), true ) ) {
				continue;
			}

			$result = self::run_channel( (string) $item['channel'], $job, $delivery, $path );

			$item['attempts'] = $attempts + 1;
			$item['time']     = time();
			if ( true === $result ) {
				$item['status']  = 'sent';
				$item['message'] = '';
			} else {
				$item['status']  = 'failed';
				$item['message'] = mb_substr( is_wp_error( $result ) ? $result->get_error_message() : __( 'The delivery failed.', 'open-export' ), 0, 300 );
			}
			$items[ $i ] = $item;
		}
		return $items;
	}

	/**
	 * Safe file name shown to people and used remotely: delivery_name plus extension.
	 *
	 * @param array $job Job record.
	 * @return string
	 */
	public static function remote_name( array $job ): string {
		$disk = (string) ( $job['filename'] ?? 'export' );
		$ext  = strtolower( (string) pathinfo( $disk, PATHINFO_EXTENSION ) );
		$name = sanitize_file_name( (string) ( $job['delivery_name'] ?? '' ) );
		if ( '' === $name ) {
			return sanitize_file_name( $disk );
		}
		if ( '' !== $ext && '.' . $ext !== strtolower( substr( $name, -strlen( $ext ) - 1 ) ) ) {
			$name .= '.' . $ext;
		}
		return $name;
	}

	/**
	 * Calls one channel and turns every failure into a WP_Error.
	 *
	 * @param string $channel  Channel name.
	 * @param array  $job      Job record.
	 * @param array  $delivery Delivery settings.
	 * @param string $path     Export file path.
	 * @return true|WP_Error
	 */
	private static function run_channel( string $channel, array $job, array $delivery, string $path ) {
		try {
			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				return new WP_Error( 'opex_delivery_file', __( 'The export file is missing.', 'open-export' ) );
			}
			if ( 'email' === $channel ) {
				return OPEX_Delivery_Email::send( $job, (array) ( $delivery['email'] ?? array() ), $path );
			}
			if ( 'webhook' === $channel ) {
				return OPEX_Delivery_Webhook::send( $job, (array) ( $delivery['webhook'] ?? array() ), $path );
			}
			if ( 'destination' === $channel ) {
				$record = OPEX_Destinations::get( (string) ( $delivery['destination']['id'] ?? '' ) );
				if ( null === $record ) {
					return new WP_Error( 'opex_delivery_destination', __( 'The destination no longer exists.', 'open-export' ) );
				}
				return 'sftp' === $record['type'] ? OPEX_Delivery_Sftp::send( $job, $record, $path ) : OPEX_Delivery_Ftp::send( $job, $record, $path );
			}
			return new WP_Error( 'opex_delivery_channel', __( 'Unknown delivery channel.', 'open-export' ) );
		} catch ( Throwable $e ) {
			return new WP_Error( 'opex_delivery_error', __( 'The delivery failed unexpectedly.', 'open-export' ) );
		}
	}
}
