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

	/** Most email recipients for one delivery. */
	public const MAX_RECIPIENTS = 10;

	/** Longest email subject, in characters. */
	public const MAX_SUBJECT = 200;

	/** Longest webhook secret, in characters. */
	public const MAX_SECRET = 256;

	private const RETRY_WAITS = array(
		1 => 300,
		2 => 1800,
	);
	private const CHANNELS    = array( 'email', 'webhook', 'destination' );

	/**
	 * Renews the lock of the delivery run in progress, or null. See keep_alive().
	 *
	 * @var callable|null
	 */
	private static $keep_alive = null;

	/**
	 * Cleans delivery settings from the browser or a schedule.
	 *
	 * A blank webhook secret keeps the one in $previous. The webhook input
	 * may set clear_secret to true to remove it on purpose.
	 *
	 * @param mixed $raw      Raw input.
	 * @param array $notes    Plain-language notes, added to.
	 * @param array $previous Delivery settings stored before, optional.
	 * @return array Keys email, webhook and destination.
	 */
	public static function sanitize( $raw, array &$notes, array $previous = array() ): array {
		$raw = is_array( $raw ) ? $raw : array();

		$email  = is_array( $raw['email'] ?? null ) ? $raw['email'] : array();
		$to     = array();
		$source = isset( $email['to'] ) ? ( is_array( $email['to'] ) ? $email['to'] : preg_split( '/[\s,;]+/', (string) $email['to'] ) ) : array();
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
		$to = array_slice( array_values( array_unique( $to ) ), 0, self::MAX_RECIPIENTS );
		$on = ! empty( $email['enabled'] );
		if ( $on && ! $to ) {
			$on      = false;
			$notes[] = __( 'Email delivery was turned off because it has no valid address.', 'open-export' );
		}
		$mode = ( $email['mode'] ?? 'link' );

		$hook = is_array( $raw['webhook'] ?? null ) ? $raw['webhook'] : array();
		$url  = esc_url_raw( trim( (string) ( $hook['url'] ?? '' ) ), array( 'http', 'https' ) );
		$hon  = ! empty( $hook['enabled'] );
		if ( '' !== $url && false === wp_http_validate_url( $url ) && $hon ) {
			$hon     = false;
			$notes[] = __( 'Webhook delivery was turned off because its URL is not allowed.', 'open-export' );
		} elseif ( $hon && '' === $url ) {
			$hon     = false;
			$notes[] = __( 'Webhook delivery was turned off because it has no URL.', 'open-export' );
		}
		if ( $hon && '' !== $url && ! OPEX_Delivery_Webhook::transport_allowed( $url ) ) {
			$hon     = false;
			$notes[] = __( 'Webhook delivery was turned off because its URL must use https unless it points at this site.', 'open-export' );
		}
		$secret = (string) ( $hook['secret'] ?? '' );
		$lost   = false;
		if ( '' !== $secret && ! OPEX_Secrets::is_encrypted( $secret ) ) {
			$secret = static::encrypt_secret( mb_substr( $secret, 0, self::MAX_SECRET ) );
			// A secret that was given but cannot be stored safely is not the same as no secret.
			$lost = '' === $secret;
		}
		if ( '' === $secret ) {
			$kept = (string) ( $previous['webhook']['secret'] ?? '' );
			if ( '' !== $kept && ! OPEX_Secrets::is_encrypted( $kept ) ) {
				$kept = static::encrypt_secret( mb_substr( $kept, 0, self::MAX_SECRET ) ); // A plain value saved by an older build.
				$lost = $lost || ( '' === $kept && empty( $hook['clear_secret'] ) );
			}
			$secret = empty( $hook['clear_secret'] ) ? $kept : '';
		}
		// Without its secret the webhook would go out unsigned, so it stays off until the secret is saved.
		if ( $lost && $hon ) {
			$hon     = false;
			$notes[] = __( 'Webhook delivery was turned off because its signing secret could not be stored securely. This server needs the sodium extension. Save the secret again once it is available.', 'open-export' );
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
				'subject' => mb_substr( trim( preg_replace( '/[\r\n]+/', ' ', sanitize_text_field( (string) ( $email['subject'] ?? '' ) ) ) ), 0, self::MAX_SUBJECT ),
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
	 * Encrypts a webhook secret. Kept apart so a test can stand in for a
	 * server where encryption fails.
	 *
	 * @param string $plain The secret as typed.
	 * @return string The stored form, or '' when it cannot be encrypted.
	 */
	protected static function encrypt_secret( string $plain ): string {
		return OPEX_Secrets::encrypt( $plain );
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
	 * With $step, the caller keeps its delivery lock alive and saves as it
	 * goes. It is called before each channel as `$step( $i, null )` and after
	 * it as `$step( $i, $item )` with the item's new result, which it should
	 * save at once, so a crash later never sends that channel again. A
	 * channel that calls keep_alive() during a long transfer reaches it as
	 * `$step( -1, null )`, which should only renew the lock. When it
	 * returns false (the caller lost its lock), processing stops: nothing
	 * more is sent and the list comes back as it is.
	 *
	 * @param array         $job      Job record (uses its deliveries list when present).
	 * @param array         $delivery Delivery settings the items came from.
	 * @param bool          $now      True when a person asked to retry now, so the wait between attempts is skipped.
	 * @param callable|null $step     Optional. See above.
	 * @return array The updated deliveries list.
	 */
	public static function process( array $job, array $delivery, bool $now = false, ?callable $step = null ): array {
		$items = isset( $job['deliveries'] ) && is_array( $job['deliveries'] ) ? array_values( $job['deliveries'] ) : self::plan( $delivery );
		$path  = OPEX_Jobs::file_path( $job );

		$outer            = self::$keep_alive;
		self::$keep_alive = null === $step ? null : static function () use ( $step ): bool {
			return (bool) $step( -1, null );
		};
		try {
			foreach ( $items as $i => $item ) {
				if ( ! is_array( $item ) || ! isset( $item['channel'] ) ) {
					continue;
				}
				$status   = (string) ( $item['status'] ?? 'pending' );
				$attempts = (int) ( $item['attempts'] ?? 0 );
				if ( 'sent' === $status || $attempts >= self::MAX_ATTEMPTS || ! in_array( $status, array( 'pending', 'failed' ), true ) ) {
					continue;
				}
				if ( ! $now && $attempts > 0 && self::due_at( $item ) > time() ) {
					continue;
				}
				if ( null !== $step && ! $step( $i, null ) ) {
					break;
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
				if ( null !== $step && ! $step( $i, $item ) ) {
					break;
				}
			}
		} finally {
			self::$keep_alive = $outer;
		}
		return $items;
	}

	/**
	 * For a channel in the middle of a long transfer: renews the lock of the
	 * delivery run that called it. A channel may call it between chunks and
	 * stop when it returns false, because another worker owns the delivery.
	 * Outside a run with a step callback it does nothing and returns true.
	 *
	 * @return bool False when the delivery run lost its lock.
	 */
	public static function keep_alive(): bool {
		return null === self::$keep_alive || (bool) ( self::$keep_alive )();
	}

	/**
	 * When the next attempt may run, or 0 when nothing is left to try.
	 * The runner uses this to schedule the next opex_deliver event.
	 *
	 * @param array $deliveries The job's deliveries list.
	 * @return int Unix time, or 0.
	 */
	public static function next_attempt_at( array $deliveries ): int {
		$next = 0;
		foreach ( $deliveries as $item ) {
			if ( ! is_array( $item ) || ! in_array( (string) ( $item['status'] ?? '' ), array( 'pending', 'failed' ), true ) || (int) ( $item['attempts'] ?? 0 ) >= self::MAX_ATTEMPTS ) {
				continue;
			}
			$due  = (int) ( $item['attempts'] ?? 0 ) > 0 ? self::due_at( $item ) : time();
			$next = ( 0 === $next || $due < $next ) ? $due : $next;
		}
		return $next;
	}

	/**
	 * Earliest time a failed item may be tried again: 5 minutes after the
	 * first attempt, 30 minutes after the second. Filter opex_delivery_retry_wait
	 * changes the wait (seconds), given the attempts made so far.
	 *
	 * @param array $item Delivery item.
	 * @return int Unix time.
	 */
	private static function due_at( array $item ): int {
		$attempts = (int) ( $item['attempts'] ?? 0 );
		$default  = self::RETRY_WAITS[ min( $attempts, 2 ) ] ?? 1800;
		$wait     = (int) apply_filters( 'opex_delivery_retry_wait', $default, $attempts );
		return (int) ( $item['time'] ?? 0 ) + max( 0, $wait );
	}

	/**
	 * Safe file name shown to people and used remotely: delivery_name plus extension.
	 *
	 * @param array $job Job record.
	 * @return string
	 */
	public static function remote_name( array $job ): string {
		$disk = (string) ( $job['filename'] ?? 'export' );
		// A compressed file keeps the export's own extension before ".zip", as in orders.csv.zip.
		$zip  = OPEX_Compress::is_compressed( $job );
		$disk = $zip ? substr( $disk, 0, -strlen( OPEX_Compress::SUFFIX ) ) : $disk;
		$ext  = strtolower( (string) pathinfo( $disk, PATHINFO_EXTENSION ) );
		$name = sanitize_file_name( (string) ( $job['delivery_name'] ?? '' ) );
		if ( '' === $name ) {
			$name = sanitize_file_name( $disk );
		} elseif ( '' !== $ext && strtolower( substr( $name, -strlen( $ext ) - 1 ) ) !== '.' . $ext ) {
			$name .= '.' . $ext;
		}
		return $zip ? $name . OPEX_Compress::SUFFIX : $name;
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
