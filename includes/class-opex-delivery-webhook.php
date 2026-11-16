<?php
/**
 * Webhook delivery.
 *
 * @package OpenExport
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Posts a JSON notice with a signed body and a download link.
 *
 * How a receiver verifies the signature: read the raw request body bytes
 * before parsing them, compute hash_hmac( 'sha256', $raw_body, $shared_secret ),
 * and compare it to the part after "sha256=" in the X-Opex-Signature header
 * with a constant-time comparison (hash_equals in PHP). Reject on mismatch.
 */
class OPEX_Delivery_Webhook {

	/**
	 * Whether a webhook URL may carry the signed payload: https, or plain
	 * http only to this site's own host or a loopback address of this
	 * machine. Plain http to another machine would send the payload and its
	 * download link in the clear. Saving and sending both use this one rule.
	 * (A loopback URL still has to pass wp_http_validate_url, which refuses it
	 * unless a site opts in through the http_request_host_is_external filter.)
	 *
	 * @param string $url Webhook URL.
	 * @return bool
	 */
	public static function transport_allowed( string $url ): bool {
		if ( 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return true;
		}
		$host = strtolower( trim( (string) wp_parse_url( $url, PHP_URL_HOST ), '[]' ) );
		if ( '' === $host ) {
			return false;
		}
		if ( 'localhost' === $host || '::1' === $host || ( false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && 0 === strpos( $host, '127.' ) ) ) {
			return true;
		}
		return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === $host;
	}

	/**
	 * Sends the notice.
	 *
	 * @param array  $job       Job record.
	 * @param array  $settings  Webhook settings: url, secret (encrypted).
	 * @param string $file_path Export file path (not uploaded, only linked).
	 * @return true|WP_Error
	 */
	public static function send( array $job, array $settings, string $file_path ) {
		$url = trim( (string) ( $settings['url'] ?? '' ) );
		if ( 1 !== preg_match( '#^https?://#i', $url ) || false === wp_http_validate_url( $url ) ) {
			return new WP_Error( 'opex_webhook_url', __( 'The webhook URL is not allowed.', 'open-export' ) );
		}
		// Checked again here because a stored URL may predate the rule or be edited in the database.
		if ( ! self::transport_allowed( $url ) ) {
			return new WP_Error( 'opex_webhook_url', __( 'The webhook URL must use https unless it points at this site.', 'open-export' ) );
		}

		$secret = '';
		if ( ! empty( $settings['secret'] ) ) {
			$plain = OPEX_Secrets::decrypt( (string) $settings['secret'] );
			if ( null === $plain ) {
				return new WP_Error( 'opex_webhook_secret', __( 'The webhook secret cannot be read. Enter it again.', 'open-export' ) );
			}
			$secret = $plain;
		}

		$token = OPEX_Download_Tokens::issue_saved( $job, DAY_IN_SECONDS * (int) OPEX_Settings::get( 'link_days' ) );
		$body  = wp_json_encode(
			array(
				'event'        => 'export.completed',
				'job'          => array(
					'id'       => (string) ( $job['id'] ?? '' ),
					'label'    => (string) ( $job['label'] ?? '' ),
					'format'   => (string) ( $job['config']['format'] ?? '' ),
					'rows'     => (int) ( $job['processed'] ?? 0 ),
					'filesize' => (int) ( $job['filesize'] ?? 0 ),
					'finished' => (int) ( $job['finished'] ?? 0 ),
				),
				'download_url' => OPEX_Download_Tokens::url( $job, $token ),
				'site'         => home_url(),
			)
		);
		if ( ! is_string( $body ) ) {
			return new WP_Error( 'opex_webhook_body', __( 'The webhook message could not be built.', 'open-export' ) );
		}

		$headers = array(
			'Content-Type' => 'application/json; charset=utf-8',
			'User-Agent'   => 'Open-Export/' . ( defined( 'OPEX_VERSION' ) ? OPEX_VERSION : '2' ),
		);
		if ( '' !== $secret ) {
			$headers['X-Opex-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		}

		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout'            => 15,
				'redirection'        => 0,
				'reject_unsafe_urls' => true,
				'headers'            => $headers,
				'body'               => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'opex_webhook_http', __( 'The webhook could not be reached.', 'open-export' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code > 299 ) {
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'opex_webhook_status', sprintf( __( 'The webhook answered with status %d.', 'open-export' ), $code ) );
		}
		return true;
	}
}
