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
