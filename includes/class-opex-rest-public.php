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
	private const IP_KEY     = 'opex_trig_ip_';
	private const IP_LIMIT   = 30;
	private const TRIGGER    = '#^/open-export/v1/schedules/s[a-f0-9]{12}/trigger$#';

	/** Seconds a counter or start-gap lock lives. Each is held for one read and one write. */
	private const LOCK_TTL = 5;

	/** Seconds a request waits for one of those locks. */
	private const LOCK_WAIT = 2;

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		OPEX_Rest_Guard::register(); // A throw in any route below becomes a WP_Error.
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
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'retry_after' ), 10, 3 );

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
	 * Wrong keys are counted twice: per schedule, only for a schedule that
	 * exists, so made-up ids cannot fill the options table, and per address,
	 * for every id, so one caller cannot guess at many schedules. Unknown and
	 * known ids get the same 403.
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

		$sched_key = self::FAIL_KEY . $id;
		$ip_key    = self::ip_key();
		$fails     = self::failures( $sched_key );
		$ip_fails  = '' !== $ip_key ? self::failures( $ip_key ) : self::failures( '' );
		// Once over a limit the answer is 429 until the window ends, even for the right key.
		if ( $fails['count'] >= self::FAIL_LIMIT || $ip_fails['count'] >= self::IP_LIMIT ) {
			$until = max( $fails['count'] >= self::FAIL_LIMIT ? $fails['until'] : 0, $ip_fails['count'] >= self::IP_LIMIT ? $ip_fails['until'] : 0 );
			return new WP_Error(
				'opex_too_many',
				__( 'Too many tries. Wait a few minutes.', 'open-export' ),
				array(
					'status'      => 429,
					'retry_after' => max( 1, $until - time() ),
				)
			);
		}
		if ( $match && $schedule && '' !== $stored ) {
			return true;
		}
		if ( $schedule ) {
			self::count_failure( $sched_key );
		}
		if ( '' !== $ip_key ) {
			self::count_failure( $ip_key );
		}
		return self::denied( 403 );
	}

	/**
	 * Transient name for the caller's address: a keyed hash, so the stored
	 * name never reveals the address. Empty when the address is unknown.
	 *
	 * The address is the connection's own (REMOTE_ADDR). Forwarding headers
	 * are not trusted, because any caller can set them. Behind a proxy every
	 * caller therefore shares one bucket, which errs on the side of stopping
	 * guesses.
	 *
	 * @return string
	 */
	private static function ip_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		return self::IP_KEY . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );
	}

	/**
	 * Adds a Retry-After header to the trigger route's 429. A permission
	 * callback can only return an error, so the error becomes a response
	 * here, after the callbacks, with the same body plus the header.
	 *
	 * @param mixed           $response Result so far: a response or a WP_Error.
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function retry_after( $response, $handler, $request ) {
		unset( $handler );
		if ( ! is_wp_error( $response ) || 'opex_too_many' !== $response->get_error_code() || ! $request instanceof WP_REST_Request || 1 !== preg_match( self::TRIGGER, $request->get_route() ) ) {
			return $response;
		}
		$after = (int) ( $response->get_error_data()['retry_after'] ?? 0 );
		$out   = rest_convert_error_to_response( $response );
		if ( $after > 0 ) {
			$out->header( 'Retry-After', (string) $after );
		}
		$out->header( 'Cache-Control', 'no-store' );
		return $out;
	}

	/**
	 * A failed-key counter.
	 *
	 * @param string $key Transient name, or '' for none.
	 * @return array count and until (end of the window).
	 */
	private static function failures( string $key ): array {
		$saved = '' !== $key ? get_transient( $key ) : false;
		if ( is_array( $saved ) && (int) ( $saved['until'] ?? 0 ) > time() ) {
			return array(
				'count' => (int) ( $saved['count'] ?? 0 ),
				'until' => (int) $saved['until'],
			);
		}
		return array(
			'count' => 0,
			'until' => 0,
		);
	}

	/**
	 * Adds one failed key. The window starts at the first failure and does not
	 * move, so a steady trickle of guesses cannot keep it open forever. The
	 * count is read again and written under a lock, so wrong keys sent at the
	 * same moment are each counted. When the lock cannot be had in time the
	 * failure is not counted, and the caller still gets its 403.
	 *
	 * @param string $key Transient name.
	 * @return void
	 */
	private static function count_failure( string $key ): void {
		self::locked(
			'trig_' . md5( $key ),
			static function () use ( $key ): void {
				self::forget_transient( $key );
				$fails = self::failures( $key );
				$until = $fails['until'] > 0 ? $fails['until'] : time() + self::FAIL_SPAN;
				set_transient(
					$key,
					array(
						'count' => $fails['count'] + 1,
						'until' => $until,
					),
					max( 1, $until - time() )
				);
			}
		);
	}

	/**
	 * Runs a short read and write under a lock of its own.
	 *
	 * @param string   $name Lock name.
	 * @param callable $work The read and write.
	 * @return bool False when the lock could not be had, so $work did not run.
	 */
	private static function locked( string $name, callable $work ): bool {
		$until = microtime( true ) + self::LOCK_WAIT;
		do {
			$token = OPEX_Lock::acquire( $name, self::LOCK_TTL );
			if ( false !== $token ) {
				try {
					$work();
				} finally {
					OPEX_Lock::release( $name, $token );
				}
				return true;
			}
			usleep( 20000 );
		} while ( microtime( true ) < $until );
		return false;
	}

	/**
	 * Takes the start gap of a schedule: at most one start every START_GAP
	 * seconds. The check and the reservation happen under one lock, so two
	 * requests at the same moment cannot both pass. The export itself starts
	 * after the lock is given up.
	 *
	 * @param string $id Schedule id.
	 * @return int 0 when this request may start, else the seconds to wait.
	 */
	private static function reserve_start( string $id ): int {
		$wait = 1;
		self::locked(
			'trig_start_' . $id,
			static function () use ( $id, &$wait ): void {
				$tkey = self::TRANSIENT . $id;
				self::forget_transient( $tkey );
				$next = (int) get_transient( $tkey );
				if ( $next > time() ) {
					$wait = max( 1, $next - time() );
					return;
				}
				set_transient( $tkey, time() + self::START_GAP, self::START_GAP );
				$wait = 0;
			}
		);
		return $wait;
	}

	/**
	 * Drops this request's cached copy of a transient, so the next read asks
	 * the database and sees what another request wrote. That includes the
	 * "missing" mark WordPress keeps for an option it did not find, or a
	 * counter another request created meanwhile would still read as zero.
	 * A shared object cache already holds the shared value, so it is left alone.
	 *
	 * @param string $key Transient name.
	 * @return void
	 */
	private static function forget_transient( string $key ): void {
		if ( ! wp_using_ext_object_cache() ) {
			OPEX_Store::forget( '_transient_' . $key );
			OPEX_Store::forget( '_transient_timeout_' . $key );
		}
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
			$wait = self::reserve_start( $id );
			if ( $wait > 0 ) {
				$response = new WP_REST_Response(
					array(
						'status'    => 'rate_limited',
						'processed' => 0,
						'total'     => 0,
					),
					429
				);
				$response->header( 'Retry-After', (string) $wait );
				$response->header( 'Cache-Control', 'no-store' );
				return $response;
			}
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
