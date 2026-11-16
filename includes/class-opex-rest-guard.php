<?php
/**
 * One catch for every REST route of the plugin.
 *
 * WordPress does not catch an exception a route throws, so the browser would
 * get a fatal-error page. The busy error of OPEX_Store can come from any
 * route that saves. Wrapping each route once here is simpler and safer than
 * a try and catch in every callback.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Turns what a route throws into a WP_Error the REST server can answer with.
 */
class OPEX_Rest_Guard {

	/** Routes under this prefix are the plugin's own. */
	private const PREFIX = '/open-export/v1/';

	/**
	 * Adds the filter that wraps the plugin's routes. Safe to call more than once.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rest_endpoints', array( __CLASS__, 'guard_endpoints' ) );
	}

	/**
	 * Filter for `rest_endpoints`: wraps the callback and the permission
	 * callback of every handler of the plugin's routes. The server builds this
	 * list from its own registry each time, so a handler is never wrapped twice.
	 *
	 * @param mixed $endpoints Routes and their handlers, as WordPress passes them.
	 * @return mixed The same list, with the plugin's handlers wrapped.
	 */
	public static function guard_endpoints( $endpoints ) {
		if ( ! is_array( $endpoints ) ) {
			return $endpoints;
		}
		foreach ( $endpoints as $route => $handlers ) {
			if ( ! is_array( $handlers ) || 0 !== strpos( (string) $route, self::PREFIX ) ) {
				continue;
			}
			if ( isset( $handlers['callback'] ) ) {
				$endpoints[ $route ] = self::guard_handler( $handlers );
				continue;
			}
			foreach ( $handlers as $key => $handler ) {
				// Keys that are not numbers hold route options, such as the schema.
				if ( is_numeric( $key ) && is_array( $handler ) ) {
					$endpoints[ $route ][ $key ] = self::guard_handler( $handler );
				}
			}
		}
		return $endpoints;
	}

	/**
	 * The error a route answers with when something was thrown. Detail goes
	 * to the debug log only, because exception text can hold server paths.
	 *
	 * @param Throwable $e What the route threw.
	 * @return WP_Error 503 when the store was busy, 400 with the message for
	 *                  another error written for people, else a plain 500.
	 */
	public static function error_for( Throwable $e ): WP_Error {
		OPEX_Exporter::log_exception( $e );
		if ( OPEX_Store::is_busy( $e ) ) {
			return new WP_Error( 'opex_busy', __( 'The site is busy. Try again in a moment.', 'open-export' ), array( 'status' => 503 ) );
		}
		if ( $e instanceof OPEX_User_Error ) {
			return new WP_Error( 'opex_invalid', $e->getMessage(), array( 'status' => 400 ) );
		}
		return new WP_Error( 'opex_failed', __( 'Open Export could not finish this request. Try again in a moment.', 'open-export' ), array( 'status' => 500 ) );
	}

	/**
	 * Wraps the two callbacks of one handler.
	 *
	 * @param array $handler One handler of a route.
	 * @return array The handler with guarded callbacks.
	 */
	private static function guard_handler( array $handler ): array {
		foreach ( array( 'callback', 'permission_callback' ) as $key ) {
			if ( isset( $handler[ $key ] ) && is_callable( $handler[ $key ] ) ) {
				$handler[ $key ] = self::wrap( $handler[ $key ] );
			}
		}
		return $handler;
	}

	/**
	 * A callable that runs the original and turns a throw into an error.
	 *
	 * @param callable $callback The route's own callback.
	 * @return Closure The guarded callback.
	 */
	private static function wrap( callable $callback ): Closure {
		return static function ( ...$args ) use ( $callback ) {
			try {
				return call_user_func_array( $callback, $args );
			} catch ( Throwable $e ) {
				return self::error_for( $e );
			}
		};
	}
}
