<?php
/**
 * REST routes for schedules (admin only).
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * List, create, read, update, delete, run now and rotate the trigger key.
 * Responses use OPEX_Schedules::expose, which never holds a secret except the
 * trigger URL.
 */
class OPEX_Rest_Schedules {

	private const NS = 'open-export/v1';
	private const ID = '(?P<id>s[a-f0-9]{12})';

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		$can = array( __CLASS__, 'can' );

		register_rest_route(
			self::NS,
			'/schedules',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => $can,
					'callback'            => array( __CLASS__, 'index' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => $can,
					'callback'            => array( __CLASS__, 'create' ),
					'args'                => self::args( true ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/schedules/' . self::ID,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => $can,
					'callback'            => array( __CLASS__, 'read' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => $can,
					'callback'            => array( __CLASS__, 'update' ),
					'args'                => self::args( false ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'permission_callback' => $can,
					'callback'            => array( __CLASS__, 'remove' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/schedules/' . self::ID . '/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $can,
				'callback'            => array( __CLASS__, 'run' ),
			)
		);

		register_rest_route(
			self::NS,
			'/schedules/' . self::ID . '/rotate-key',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $can,
				'callback'            => array( __CLASS__, 'rotate_key' ),
			)
		);
	}

	/**
	 * Permission check shared by every route here.
	 *
	 * @return bool
	 */
	public static function can(): bool {
		return current_user_can( opex_capability() );
	}

	/**
	 * Argument declarations for create and update.
	 *
	 * @param bool $creating True when creating (name is required).
	 * @return array
	 */
	private static function args( bool $creating ): array {
		return self::with_schema( array(
			'name'        => array(
				'type'              => 'string',
				'required'          => $creating,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $v ): bool {
					return is_string( $v ) && '' !== trim( $v ) && mb_strlen( $v ) <= 100;
				},
			),
			'config'      => array(
				'type'     => 'object',
				'required' => false,
			),
			'frequency'   => array(
				'type'              => 'string',
				'enum'              => array( 'manual', 'hourly', 'daily', 'weekly', 'monthly' ),
				'sanitize_callback' => 'sanitize_key',
			),
			'time'        => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $v ): bool {
					return is_string( $v ) && 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $v );
				},
			),
			'weekday'     => array(
				'type'              => 'integer',
				'minimum'           => 0,
				'maximum'           => 6,
				'sanitize_callback' => 'absint',
			),
			'monthday'    => array(
				'type'              => array( 'integer', 'string' ),
				'validate_callback' => static function ( $v ): bool {
					return 'last' === $v || ( is_numeric( $v ) && (int) $v >= 1 && (int) $v <= 28 );
				},
			),
			'enabled'     => array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'incremental' => array(
				'type' => 'object',
			),
			'delivery'    => array(
				'type' => 'object',
			),
			'filename'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		) );
	}

	/**
	 * Adds schema validation and parsing to every argument that has no
	 * callback of its own (WordPress skips validation once a sanitize callback
	 * is set without a validate callback).
	 *
	 * @param array $args Argument declarations.
	 * @return array
	 */
	private static function with_schema( array $args ): array {
		foreach ( $args as $key => $arg ) {
			if ( ! isset( $arg['validate_callback'] ) ) {
				$args[ $key ]['validate_callback'] = 'rest_validate_request_arg';
			}
			if ( ! isset( $arg['sanitize_callback'] ) ) {
				$args[ $key ]['sanitize_callback'] = 'rest_parse_request_arg';
			}
		}
		return $args;
	}

	/**
	 * Picks only the keys a person may set.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private static function raw( WP_REST_Request $request ): array {
		$params = $request->get_params();
		$raw    = array();
		foreach ( array( 'name', 'config', 'frequency', 'time', 'weekday', 'monthday', 'enabled', 'incremental', 'delivery', 'filename' ) as $key ) {
			if ( array_key_exists( $key, $params ) ) {
				$raw[ $key ] = $params[ $key ];
			}
		}
		return $raw;
	}

	/**
	 * Gives errors a status so REST never answers 500 for bad input.
	 *
	 * @param WP_Error $error Error from a class.
	 * @return WP_Error
	 */
	private static function fail( WP_Error $error ): WP_Error {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
		return new WP_Error( $error->get_error_code(), $error->get_error_message(), array( 'status' => $status ) );
	}

	/**
	 * Error for an id that does not exist.
	 *
	 * @return WP_Error
	 */
	private static function missing(): WP_Error {
		return new WP_Error( 'opex_missing', __( 'Schedule not found.', 'open-export' ), array( 'status' => 404 ) );
	}

	/**
	 * GET /schedules.
	 *
	 * @return WP_REST_Response
	 */
	public static function index(): WP_REST_Response {
		$list = array_map( array( 'OPEX_Schedules', 'expose' ), array_values( OPEX_Schedules::all() ) );
		return rest_ensure_response( $list );
	}

	/**
	 * POST /schedules.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$saved = OPEX_Schedules::save( self::raw( $request ) );
		if ( is_wp_error( $saved ) ) {
			return self::fail( $saved );
		}
		$response = rest_ensure_response( OPEX_Schedules::expose( $saved ) );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * GET /schedules/<id>.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function read( WP_REST_Request $request ) {
		$s = OPEX_Schedules::get( (string) $request['id'] );
		return $s ? rest_ensure_response( OPEX_Schedules::expose( $s ) ) : self::missing();
	}

	/**
	 * POST /schedules/<id>. A blank webhook secret keeps the stored one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update( WP_REST_Request $request ) {
		$id     = (string) $request['id'];
		$stored = OPEX_Schedules::get( $id );
		if ( ! $stored ) {
			return self::missing();
		}
		$raw = self::raw( $request );
		if ( isset( $raw['delivery'] ) && is_array( $raw['delivery'] ) && is_array( $raw['delivery']['webhook'] ?? null ) ) {
			$hook = $raw['delivery']['webhook'];
			if ( ! empty( $hook['clear_secret'] ) ) {
				$hook['secret'] = '';
			} elseif ( '' === (string) ( $hook['secret'] ?? '' ) ) {
				$hook['secret'] = (string) $stored['delivery']['webhook']['secret'];
			}
			unset( $hook['clear_secret'], $hook['has_secret'] );
			$raw['delivery']['webhook'] = $hook;
		}
		$saved = OPEX_Schedules::save( $raw, $id );
		return is_wp_error( $saved ) ? self::fail( $saved ) : rest_ensure_response( OPEX_Schedules::expose( $saved ) );
	}

	/**
	 * DELETE /schedules/<id>.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function remove( WP_REST_Request $request ) {
		$id = (string) $request['id'];
		if ( ! OPEX_Schedules::get( $id ) ) {
			return self::missing();
		}
		OPEX_Schedules::delete( $id );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * POST /schedules/<id>/run: start now with origin manual.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function run( WP_REST_Request $request ) {
		$job = OPEX_Schedules::start( (string) $request['id'], 'manual' );
		return is_wp_error( $job ) ? self::fail( $job ) : rest_ensure_response( $job );
	}

	/**
	 * POST /schedules/<id>/rotate-key: new trigger key, shown only in trigger_url.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rotate_key( WP_REST_Request $request ) {
		$id = (string) $request['id'];
		if ( ! OPEX_Schedules::get( $id ) ) {
			return self::missing();
		}
		$saved = OPEX_Schedules::save( array( 'rotate_key' => true ), $id );
		return is_wp_error( $saved ) ? self::fail( $saved ) : rest_ensure_response( OPEX_Schedules::expose( $saved ) );
	}
}
