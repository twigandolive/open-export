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
	public static function send( array $job, array $settings, string $file_path ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- every channel takes the file. The webhook only links it.
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

		$pin = self::pin_for( $url );
		if ( is_wp_error( $pin ) ) {
			return $pin;
		}
		$response = self::post_pinned(
			$url,
			$pin,
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

	/**
	 * The address a webhook send connects to, checked once.
	 *
	 * The function wp_safe_remote_post checks the address a host name resolves to, then
	 * the transport looks the name up again. A host whose DNS answer changes
	 * between the two (DNS rebinding) could slip past the check. So the name
	 * is resolved here, that exact address goes through the same private
	 * address rule and opt-in filter WordPress uses, and post_pinned() makes
	 * the connection to it.
	 *
	 * @param string $url Webhook URL, already checked by wp_http_validate_url.
	 * @return array|null|WP_Error { host, port, ip } to pin, null when there is
	 *                             nothing to pin (an IP address, or this site's
	 *                             own host that does not resolve), or an error.
	 */
	public static function pin_for( string $url ) {
		$host = trim( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ), '.' );
		if ( '' === $host || false !== filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) ) {
			return null;
		}
		$port = (int) wp_parse_url( $url, PHP_URL_PORT );
		$port = $port > 0 ? $port : ( 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ? 443 : 80 );
		$own  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === $host;

		$ip = gethostbyname( $host );
		if ( $ip === $host || false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			// WordPress does not resolve this site's own host either, so the send goes ahead unpinned.
			return $own ? null : new WP_Error( 'opex_webhook_url', __( 'The webhook host could not be found.', 'open-export' ) );
		}
		$public = false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		// This is the same opt-in wp_http_validate_url asks before it allows a private address.
		if ( ! $own && ! $public && ! apply_filters( 'http_request_host_is_external', false, $host, $url ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own opt-in, asked the same way core asks it.
			return new WP_Error( 'opex_webhook_url', __( 'The webhook URL is not allowed.', 'open-export' ) );
		}
		return array(
			'host' => $host,
			'port' => $port,
			'ip'   => $ip,
		);
	}

	/**
	 * Posts with the connection pinned to the checked address. The pin works
	 * through cURL's CURLOPT_RESOLVE and applies to this one request only: the
	 * hook matches this URL, and the entry is removed from the cURL handle
	 * afterward because WordPress reuses that handle. When WordPress uses
	 * another transport (no cURL extension), the request goes out unpinned,
	 * as it did before. The TLS check still uses the host name.
	 *
	 * @param string     $url  Webhook URL.
	 * @param array|null $pin  From pin_for(), or null.
	 * @param array      $args wp_safe_remote_post arguments.
	 * @return array|WP_Error The response.
	 */
	private static function post_pinned( string $url, ?array $pin, array $args ) {
		if ( null === $pin || ! defined( 'CURLOPT_RESOLVE' ) ) {
			return wp_safe_remote_post( $url, $args );
		}
		$entry   = $pin['host'] . ':' . $pin['port'];
		$handles = array();
		$hook    = static function ( $handle, $request, $request_url ) use ( $url, $entry, $pin, &$handles ): void {
			unset( $request );
			if ( $request_url !== $url ) {
				return;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- the pin needs a cURL option; WordPress offers no other way.
			curl_setopt( $handle, CURLOPT_RESOLVE, array( $entry . ':' . $pin['ip'] ) );
			$handles[] = $handle;
		};
		add_action( 'http_api_curl', $hook, 10, 3 );
		try {
			return wp_safe_remote_post( $url, $args );
		} finally {
			remove_action( 'http_api_curl', $hook, 10 );
			foreach ( $handles as $handle ) {
				if ( is_resource( $handle ) || $handle instanceof CurlHandle ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- removes the pin from the reused handle.
					curl_setopt( $handle, CURLOPT_RESOLVE, array( '-' . $entry ) );
				}
			}
		}
	}
}
