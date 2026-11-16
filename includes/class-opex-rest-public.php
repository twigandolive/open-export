<?php
/**
 * Public REST routes: the cron trigger URL and the token download.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Both routes work without a login, so each permission callback is a real
 * check that gives one generic answer for every kind of failure. That keeps
 * ids and keys from being probed. Neither route starts a session or sets a
 * cookie.
 */
class OPEX_Rest_Public {

	private const NS         = 'open-export/v1';
	private const START_GAP  = 30;
	private const WORK_SECS  = 20;
	private const DUMMY_KEY  = 'opex-no-such-schedule-key-for-timing-0000';
	private const TRANSIENT  = 'opex_trigger_';
	private const FAIL_KEY   = 'opex_trig_fail_';
	private const FAIL_LIMIT = 10;
	private const FAIL_SPAN  = 600;

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/schedules/(?P<id>s[a-f0-9]{12})/trigger',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'permission_callback' => array( __CLASS__, 'can_trigger' ),
				'callback'            => array( __CLASS__, 'trigger' ),
				'args'                => array(
					'key' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => array( __CLASS__, 'plain_string' ),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/file/(?P<id>j[a-f0-9]{12})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'can_download' ),
				'callback'            => array( __CLASS__, 'download' ),
				'args'                => array(
					'token' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => array( __CLASS__, 'plain_string' ),
					),
				),
			)
		);
	}

	/**
	 * Keeps a value as a short plain string. Anything else becomes ''.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function plain_string( $value ): string {
		return is_string( $value ) && strlen( $value ) <= 200 ? $value : '';
	}

	/**
	 * The one answer for every failed check.
	 *
	 * @param int $status 403 or 404.
	 * @return WP_Error
	 */
	private static function denied( int $status ): WP_Error {
		if ( 404 === $status ) {
			return new WP_Error( 'opex_not_found', __( 'Not found.', 'open-export' ), array( 'status' => 404 ) );
		}
		return new WP_Error( 'opex_forbidden', __( 'Access denied.', 'open-export' ), array( 'status' => 403 ) );
	}

	/**
	 * Permission for the trigger URL: the key must match, compared in
	 * constant time. An unknown id compares against a dummy key so the work
	 * is the same.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function can_trigger( WP_REST_Request $request ) {
		$id       = (string) $request['id'];
		$schedule = OPEX_Schedules::get( $id );
		$stored   = $schedule ? (string) $schedule['trigger_key'] : '';
		$known    = '' !== $stored ? $stored : self::DUMMY_KEY;
		$given    = self::plain_string( $request->get_param( 'key' ) );
		$match    = hash_equals( $known, $given );

		// Known and unknown ids take this same path. After too many wrong keys
		// the id answers 429 until the window ends, even for the right key.
		$fails = self::failures( $id );
		if ( $fails['count'] >= self::FAIL_LIMIT ) {
			return new WP_Error(
				'opex_too_many',
				__( 'Too many tries. Wait a few minutes.', 'open-export' ),
				array( 'status' => 429 )
			);
		}
		if ( $match && $schedule && '' !== $stored ) {
			return true;
		}
		self::count_failure( $id, $fails );
		return self::denied( 403 );
	}

	/**
	 * The failed-key counter for one schedule id.
	 *
	 * @param string $id Schedule id from the URL.
	 * @return array count and until (end of the window).
	 */
	private static function failures( string $id ): array {
		$saved = get_transient( self::FAIL_KEY . $id );
		if ( is_array( $saved ) && (int) ( $saved['until'] ?? 0 ) > time() ) {
			return array( 'count' => (int) ( $saved['count'] ?? 0 ), 'until' => (int) $saved['until'] );
		}
		return array( 'count' => 0, 'until' => 0 );
	}

	/**
	 * Adds one failed key. The window starts at the first failure and does not
	 * move, so a steady trickle of guesses cannot keep it open forever.
	 *
	 * @param string $id    Schedule id.
	 * @param array  $fails Current counter.
	 */
	private static function count_failure( string $id, array $fails ): void {
		$until = $fails['until'] > 0 ? $fails['until'] : time() + self::FAIL_SPAN;
		set_transient( self::FAIL_KEY . $id, array( 'count' => $fails['count'] + 1, 'until' => $until ), max( 1, $until - time() ) );
	}

	/**
	 * Continues a running job of the schedule, or starts a new one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function trigger( WP_REST_Request $request ) {
		$id      = (string) $request['id'];
		$running = '';
		foreach ( OPEX_Jobs::all() as $job ) {
			if ( ( $job['schedule_id'] ?? '' ) === $id && 'running' === ( $job['status'] ?? '' ) ) {
				$running = (string) $job['id'];
				break;
			}
		}

		if ( '' === $running ) {
			$tkey = self::TRANSIENT . $id;
			$next = (int) get_transient( $tkey );
			if ( $next > time() ) {
				$response = new WP_REST_Response( array( 'status' => 'rate_limited', 'processed' => 0, 'total' => 0 ), 429 );
				$response->header( 'Retry-After', (string) max( 1, $next - time() ) );
				$response->header( 'Cache-Control', 'no-store' );
				return $response;
			}
			set_transient( $tkey, time() + self::START_GAP, self::START_GAP );
			$started = OPEX_Schedules::start( $id, 'trigger' );
			if ( is_wp_error( $started ) ) {
				self::log( $started );
				return self::failed();
			}
			$running = (string) $started['id'];
		}

		$progress = OPEX_Runner::work( $running, self::WORK_SECS );
		if ( is_wp_error( $progress ) ) {
			self::log( $progress );
			return self::failed();
		}
		$response = new WP_REST_Response(
			array(
				'status'    => (string) $progress['status'],
				'processed' => (int) $progress['processed'],
				'total'     => (int) $progress['total'],
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * A generic failure that tells the caller nothing about the cause.
	 *
	 * @return WP_Error
	 */
	private static function failed(): WP_Error {
		return new WP_Error( 'opex_failed', __( 'The export could not run.', 'open-export' ), array( 'status' => 500 ) );
	}

	/**
	 * Logs detail only when debug logging is on.
	 *
	 * @param WP_Error $error Error to log.
	 * @return void
	 */
	private static function log( WP_Error $error ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( 'Open Export trigger: ' . $error->get_error_code() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	/**
	 * Permission for the file route: the job must be done, its file must
	 * exist, and the token must verify. Every failure is the same 404.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function can_download( WP_REST_Request $request ) {
		$job   = OPEX_Jobs::get( (string) $request['id'] );
		$token = self::plain_string( $request->get_param( 'token' ) );
		$valid = OPEX_Download_Tokens::verify( is_array( $job ) ? $job : array(), $token );
		if ( $valid && is_array( $job ) && 'done' === ( $job['status'] ?? '' ) && '' !== (string) ( $job['filename'] ?? '' ) && is_file( OPEX_Jobs::file_path( $job ) ) ) {
			return true;
		}
		return self::denied( 404 );
	}

	/**
	 * Streams the file. Does not return.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error Only when the job vanished after the permission check.
	 */
	public static function download( WP_REST_Request $request ) {
		$job = OPEX_Jobs::get( (string) $request['id'] );
		if ( ! $job ) {
			return self::denied( 404 );
		}
		OPEX_Download::stream( $job, OPEX_Download::file_name( $job ) );
		return self::denied( 404 );
	}
}
