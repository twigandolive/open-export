<?php
/**
 * REST API. Every route requires the admin capability; WordPress cookie
 * auth additionally enforces the wp_rest nonce (sent as X-WP-Nonce by
 * apiFetch, or as _wpnonce on download links).
 */

defined( 'ABSPATH' ) || exit;

class OPEX_Rest {

	private const NS = 'open-export/v1';

	public static function register_routes(): void {
		$can = static function (): bool {
			return current_user_can( opex_capability() );
		};

		register_rest_route( self::NS, '/sources', array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => $can,
			'callback'            => array( __CLASS__, 'sources' ),
		) );

		register_rest_route( self::NS, '/fields', array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => $can,
			'callback'            => array( __CLASS__, 'fields' ),
			'args'                => array(
				'source' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( self::NS, '/schema', array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => $can,
			'callback'            => array( __CLASS__, 'schema' ),
			'args'                => array(
				'source' => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'scope'  => array(
					'type'              => 'string',
					'default'           => 'source',
					'enum'              => array( 'source', 'all' ),
					'sanitize_callback' => 'sanitize_key',
				),
			),
		) );

		register_rest_route( self::NS, '/recipe', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => $can,
			'callback'            => array( __CLASS__, 'recipe' ),
		) );

		register_rest_route( self::NS, '/preview', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => $can,
			'callback'            => array( __CLASS__, 'preview' ),
		) );

		register_rest_route( self::NS, '/jobs', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $can,
				'callback'            => array( __CLASS__, 'jobs' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $can,
				'callback'            => array( __CLASS__, 'create_job' ),
			),
		) );

		register_rest_route( self::NS, '/jobs/(?P<id>j[a-f0-9]{12})', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $can,
				'callback'            => array( __CLASS__, 'get_job' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'permission_callback' => $can,
				'callback'            => array( __CLASS__, 'delete_job' ),
			),
		) );

		register_rest_route( self::NS, '/jobs/(?P<id>j[a-f0-9]{12})/rerun', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => $can,
			'callback'            => array( __CLASS__, 'rerun_job' ),
		) );

		register_rest_route( self::NS, '/jobs/(?P<id>j[a-f0-9]{12})/run', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => $can,
			'callback'            => array( __CLASS__, 'run_job' ),
		) );

		register_rest_route( self::NS, '/jobs/(?P<id>j[a-f0-9]{12})/pause', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => $can,
			'callback'            => array( __CLASS__, 'pause_job' ),
		) );

		register_rest_route( self::NS, '/jobs/(?P<id>j[a-f0-9]{12})/download', array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => $can,
			'callback'            => array( __CLASS__, 'download' ),
		) );

		register_rest_route( self::NS, '/templates', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $can,
				'callback'            => array( __CLASS__, 'templates' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $can,
				'callback'            => array( __CLASS__, 'save_template' ),
			),
		) );

		register_rest_route( self::NS, '/templates/(?P<id>t[a-f0-9]{12})', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'permission_callback' => $can,
			'callback'            => array( __CLASS__, 'delete_template' ),
		) );
	}

	public static function sources(): WP_REST_Response {
		return rest_ensure_response( OPEX_Catalog::sources() );
	}

	/**
	 * Field groups for a source, plus everything the date-range control needs:
	 * which columns are real dates for this source and what the presets are
	 * called. One round trip instead of three.
	 */
	public static function fields( WP_REST_Request $request ): WP_REST_Response {
		$source = (string) $request['source'];
		$src    = OPEX_Query::parse_source( $source );

		$date_fields = array();
		if ( $src ) {
			foreach ( OPEX_Query::date_fields( $src ) as $id => $def ) {
				$date_fields[] = array( 'id' => $id, 'label' => $def['label'] );
			}
		}

		$presets = array();
		foreach ( OPEX_Dates::PRESETS as $preset ) {
			$presets[] = array( 'id' => $preset, 'label' => OPEX_Dates::preset_label( $preset ) );
		}

		return rest_ensure_response( array(
			'groups'      => OPEX_Catalog::fields( $source ),
			'date_fields' => $date_fields,
			'presets'     => $presets,
			'timezone'    => function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC',
		) );
	}

	/**
	 * The site's export surface as JSON, for handing to an AI assistant.
	 * ?scope=all describes every source, which is large on a busy site.
	 */
	public static function schema( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response(
			OPEX_Schema::build( (string) $request['source'], 'all' === (string) $request['scope'] )
		);
	}

	/**
	 * Turn an assistant's recipe into a validated config for the UI to apply.
	 * This configures the screen; it does not start an export.
	 */
	public static function recipe( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'opex_recipe_json', __( 'That is not valid JSON. Paste the whole object, including its outer braces.', 'open-export' ), array( 'status' => 400 ) );
		}

		// Tolerate a recipe wrapped in an outer key, which assistants often add.
		if ( ! isset( $body['source'] ) && isset( $body['recipe'] ) && is_array( $body['recipe'] ) ) {
			$body = $body['recipe'];
		}

		$notes  = array();
		$result = OPEX_Schema::recipe_to_config( $body, $notes );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function preview( WP_REST_Request $request ) {
		$config = OPEX_Exporter::sanitize_config( (array) $request->get_json_params() );
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		return rest_ensure_response( OPEX_Exporter::preview( $config ) );
	}

	public static function jobs(): WP_REST_Response {
		OPEX_Jobs::sweep();
		$jobs = array_map( array( 'OPEX_Jobs', 'expose' ), array_values( OPEX_Jobs::all() ) );
		usort( $jobs, static function ( $a, $b ) {
			return $b['created'] <=> $a['created'];
		} );
		return rest_ensure_response( $jobs );
	}

	public static function create_job( WP_REST_Request $request ) {
		$body   = (array) $request->get_json_params();
		$config = OPEX_Exporter::sanitize_config( $body );
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$label = sanitize_text_field( (string) ( $body['label'] ?? '' ) );
		$label = $label ? $label : $config['source'];

		$result = OPEX_Exporter::create( $config, $label );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function run_job( WP_REST_Request $request ) {
		$result = OPEX_Exporter::run( (string) $request['id'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function pause_job( WP_REST_Request $request ) {
		$result = OPEX_Exporter::pause( (string) $request['id'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function get_job( WP_REST_Request $request ) {
		$job = OPEX_Jobs::get( (string) $request['id'] );
		if ( ! $job ) {
			return new WP_Error( 'opex_missing', __( 'Export job not found.', 'open-export' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( OPEX_Jobs::expose_detail( $job ) );
	}

	public static function rerun_job( WP_REST_Request $request ) {
		$result = OPEX_Exporter::rerun( (string) $request['id'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function delete_job( WP_REST_Request $request ): WP_REST_Response {
		OPEX_Jobs::delete( (string) $request['id'] );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Stream a finished file. Capability + nonce were already enforced by
	 * the permission callback and REST cookie auth before we got here.
	 */
	public static function download( WP_REST_Request $request ) {
		$job = OPEX_Jobs::get( (string) $request['id'] );
		if ( ! $job || 'done' !== $job['status'] ) {
			return new WP_Error( 'opex_not_ready', __( 'Export is not ready.', 'open-export' ), array( 'status' => 404 ) );
		}

		$path = OPEX_Jobs::file_path( $job );
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'opex_gone', __( 'Export file no longer exists.', 'open-export' ), array( 'status' => 404 ) );
		}

		$types = array( 'csv' => 'text/csv; charset=utf-8', 'json' => 'application/json; charset=utf-8', 'xml' => 'application/xml; charset=utf-8' );
		$type  = $types[ $job['config']['format'] ] ?? 'application/octet-stream';

		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $job['filename'] ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:disable WordPress.WP.AlternativeFunctions -- chunked streaming of a potentially multi-gigabyte export file.
		$handle = fopen( $path, 'rb' );
		if ( $handle ) {
			while ( ! feof( $handle ) ) {
				$chunk = fread( $handle, 1024 * 1024 );
				if ( false === $chunk ) {
					break;
				}
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary-safe file passthrough of our own generated export.
				flush();
			}
			fclose( $handle );
		}
		// phpcs:enable
		exit;
	}

	public static function templates(): WP_REST_Response {
		$list = array_map( array( 'OPEX_Templates', 'expose' ), array_values( OPEX_Templates::all() ) );
		usort( $list, static function ( $a, $b ) {
			return $b['updated'] <=> $a['updated'];
		} );
		return rest_ensure_response( $list );
	}

	public static function save_template( WP_REST_Request $request ) {
		$body   = (array) $request->get_json_params();
		$name   = sanitize_text_field( (string) ( $body['name'] ?? '' ) );
		$config = (array) ( $body['config'] ?? array() );
		$result = OPEX_Templates::save( $name, $config );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function delete_template( WP_REST_Request $request ): WP_REST_Response {
		OPEX_Templates::delete( (string) $request['id'] );
		return rest_ensure_response( array( 'deleted' => true ) );
	}
}
