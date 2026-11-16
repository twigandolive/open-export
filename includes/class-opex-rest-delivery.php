<?php
/**
 * REST routes for destinations, settings and screen options (admin only).
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Destinations never return their password or key: OPEX_Destinations::expose
 * drops it and adds has_secret.
 */
class OPEX_Rest_Delivery {

	private const NS = 'open-export/v1';
	private const ID = '(?P<id>d[a-f0-9]{12})';

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		$can = array( __CLASS__, 'can' );

		register_rest_route(
			self::NS,
			'/destinations',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => $can,
					'callback'            => array( __CLASS__, 'destinations' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => $can,
					'callback'            => array( __CLASS__, 'create_destination' ),
					'args'                => self::destination_args(),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/destinations/' . self::ID,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => $can,
					'callback'            => array( __CLASS__, 'update_destination' ),
					'args'                => self::destination_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'permission_callback' => $can,
					'callback'            => array( __CLASS__, 'delete_destination' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/destinations/' . self::ID . '/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $can,
				'callback'            => array( __CLASS__, 'test_destination' ),
			)
		);

		register_rest_route(
			self::NS,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => $can,
					'callback'            => array( __CLASS__, 'get_settings' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => $can,
					'callback'            => array( __CLASS__, 'save_settings' ),
					'args'                => self::with_schema( array(
						'retention_days' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 365,
						),
						'link_days'      => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 30,
						),
						'background'     => array(
							'type'              => 'boolean',
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					) ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/options',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $can,
				'callback'            => array( __CLASS__, 'options' ),
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
	 * Argument declarations for a destination. The secret is not sanitized
	 * because a private key needs its line breaks.
	 *
	 * @return array
	 */
	private static function destination_args(): array {
		$text = static function ( $v ): bool {
			return is_string( $v ) && strlen( $v ) <= 500;
		};
		return self::with_schema( array(
			'name'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => $text,
			),
			'type'     => array(
				'type' => 'string',
				'enum' => array( 'ftp', 'ftps', 'sftp' ),
			),
			'host'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => $text,
			),
			'port'     => array(
				'type'              => array( 'integer', 'string' ),
				'validate_callback' => static function ( $v ): bool {
					return '' === $v || ( is_numeric( $v ) && (int) $v >= 1 && (int) $v <= 65535 );
				},
			),
			'username' => array(
				'type'              => 'string',
				'validate_callback' => static function ( $v ): bool {
					return is_string( $v ) && strlen( $v ) <= 128;
				},
			),
			'auth'     => array(
				'type' => 'string',
				'enum' => array( 'password', 'key' ),
			),
			'secret'   => array(
				'type'              => 'string',
				'sanitize_callback' => static function ( $v ): string {
					return is_string( $v ) ? $v : '';
				},
				'validate_callback' => static function ( $v ): bool {
					return is_string( $v ) && strlen( $v ) <= 20000;
				},
			),
			'path'     => array(
				'type'              => 'string',
				'validate_callback' => $text,
			),
			'passive'  => array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
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
		foreach ( array( 'name', 'type', 'host', 'port', 'username', 'auth', 'secret', 'path', 'passive' ) as $key ) {
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
		return new WP_Error( 'opex_missing', __( 'Destination not found.', 'open-export' ), array( 'status' => 404 ) );
	}

	/**
	 * GET /destinations.
	 *
	 * @return WP_REST_Response
	 */
	public static function destinations(): WP_REST_Response {
		return rest_ensure_response( array_map( array( 'OPEX_Destinations', 'expose' ), array_values( OPEX_Destinations::all() ) ) );
	}

	/**
	 * POST /destinations.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_destination( WP_REST_Request $request ) {
		$saved = OPEX_Destinations::save( self::raw( $request ) );
		if ( is_wp_error( $saved ) ) {
			return self::fail( $saved );
		}
		$response = rest_ensure_response( OPEX_Destinations::expose( $saved ) );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * POST /destinations/<id>. Missing keys keep their stored value.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_destination( WP_REST_Request $request ) {
		$id     = (string) $request['id'];
		$stored = OPEX_Destinations::get( $id );
		if ( ! $stored ) {
			return self::missing();
		}
		unset( $stored['secret'] );
		$saved = OPEX_Destinations::save( array_merge( $stored, self::raw( $request ) ), $id );
		return is_wp_error( $saved ) ? self::fail( $saved ) : rest_ensure_response( OPEX_Destinations::expose( $saved ) );
	}

	/**
	 * DELETE /destinations/<id>.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_destination( WP_REST_Request $request ) {
		$id = (string) $request['id'];
		if ( ! OPEX_Destinations::get( $id ) ) {
			return self::missing();
		}
		OPEX_Destinations::delete( $id );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * POST /destinations/<id>/test.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function test_destination( WP_REST_Request $request ) {
		$id = (string) $request['id'];
		if ( ! OPEX_Destinations::get( $id ) ) {
			return self::missing();
		}
		$result = OPEX_Destinations::test( $id );
		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => $result->get_error_message() ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'message' => __( 'The connection works.', 'open-export' ) ) );
	}

	/**
	 * GET /settings.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_settings(): WP_REST_Response {
		return rest_ensure_response( OPEX_Settings::all() );
	}

	/**
	 * POST /settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_params();
		$raw    = array();
		foreach ( array( 'retention_days', 'link_days', 'background' ) as $key ) {
			if ( array_key_exists( $key, $params ) ) {
				$raw[ $key ] = $params[ $key ];
			}
		}
		return rest_ensure_response( OPEX_Settings::save( $raw ) );
	}

	/**
	 * GET /options: what the screen needs to draw itself.
	 *
	 * @return WP_REST_Response
	 */
	public static function options(): WP_REST_Response {
		global $wp_locale;
		$weekdays = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$weekdays[] = array( 'value' => $i, 'label' => $wp_locale->get_weekday( $i ) );
		}
		$list = static function ( array $labels ): array {
			$out = array();
			foreach ( $labels as $id => $label ) {
				$out[] = array( 'id' => (string) $id, 'label' => $label );
			}
			return $out;
		};
		return rest_ensure_response(
			array(
				'formats'          => OPEX_Writer::formats(),
				'transforms'       => OPEX_Transforms::types(),
				'csv_encodings'    => $list(
					array(
						'utf8_bom' => __( 'UTF-8 with BOM (best for Excel)', 'open-export' ),
						'utf8'     => __( 'UTF-8', 'open-export' ),
						'win1252'  => __( 'Windows-1252', 'open-export' ),
						'utf16le'  => __( 'UTF-16 LE', 'open-export' ),
					)
				),
				'csv_eols'         => $list(
					array(
						'lf'   => __( 'Unix (LF)', 'open-export' ),
						'crlf' => __( 'Windows (CRLF)', 'open-export' ),
					)
				),
				'frequencies'      => $list(
					array(
						'manual'  => __( 'Manual only', 'open-export' ),
						'hourly'  => __( 'Hourly', 'open-export' ),
						'daily'   => __( 'Daily', 'open-export' ),
						'weekly'  => __( 'Weekly', 'open-export' ),
						'monthly' => __( 'Monthly', 'open-export' ),
					)
				),
				'destination_types' => $list(
					array(
						'ftp'  => __( 'FTP', 'open-export' ),
						'ftps' => __( 'FTP over TLS', 'open-export' ),
						'sftp' => __( 'SFTP', 'open-export' ),
					)
				),
				'weekdays'         => $weekdays,
				'timezone'         => wp_timezone_string(),
				'features'         => array(
					'multisite'     => is_multisite(),
					'woocommerce'   => class_exists( 'WooCommerce' ),
					'ftp'           => extension_loaded( 'ftp' ),
					'sodium'        => function_exists( 'sodium_crypto_secretbox' ),
					'zip'           => class_exists( 'ZipArchive' ),
					'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				),
			)
		);
	}
}
