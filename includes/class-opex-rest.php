<?php
/**
 * REST API. Every route requires the admin capability, and WordPress cookie
 * auth additionally enforces the wp_rest nonce (sent as X-WP-Nonce by
 * apiFetch, or as _wpnonce on download links).
 *
 * The job routes are answered in OPEX_Rest_Jobs.
 *
 * @package Open_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and answers the core admin routes: sources, fields, preview, jobs and templates.
 */
class OPEX_Rest {

	private const NS = 'open-export/v1';

	/**
	 * Registers the core admin routes. Every one needs the plugin capability.
	 */
	public static function register_routes(): void {
		$can = static function (): bool {
			return current_user_can( opex_capability() );
		};

		register_rest_route(
			self::NS,
			'/sources',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $can,
				'callback'            => array( __CLASS__, 'sources' ),
			)
		);

		register_rest_route(
			self::NS,
			'/fields',
			array(
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
			)
		);

		register_rest_route(
			self::NS,
			'/schema',
			array(
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
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/recipe',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $can,
				'callback'            => array( __CLASS__, 'recipe' ),
			)
		);

		register_rest_route(
			self::NS,
			'/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $can,
				'callback'            => array( __CLASS__, 'preview' ),
			)
		);

		register_rest_route(
			self::NS,
			'/jobs',
			array(
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
			)
		);

		register_rest_route(
			self::NS,
			'/jobs/(?P<id>j[a-f0-9]{12})',
			array(
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
			)
		);

		register_rest_route(
			self::NS,
			'/jobs/(?P<id>j[a-f0-9]{12})/rerun',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $can,
				'callback'            => array( __CLASS__, 'rerun_job' ),
			)
		);

		register_rest_route(
			self::NS,
			'/jobs/(?P<id>j[a-f0-9]{12})/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $can,
				'callback'            => array( __CLASS__, 'run_job' ),
			)
		);

		register_rest_route(
			self::NS,
			'/jobs/(?P<id>j[a-f0-9]{12})/pause',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $can,
				'callback'            => array( __CLASS__, 'pause_job' ),
			)
		);

		register_rest_route(
			self::NS,
			'/jobs/(?P<id>j[a-f0-9]{12})/download',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $can,
				'callback'            => array( __CLASS__, 'download' ),
			)
		);

		register_rest_route(
			self::NS,
			'/templates',
			array(
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
			)
		);

		register_rest_route(
			self::NS,
			'/templates/(?P<id>t[a-f0-9]{12})',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'permission_callback' => $can,
				'callback'            => array( __CLASS__, 'delete_template' ),
			)
		);

		OPEX_Rest_Screen::register_routes();
		// Every route of the plugin, these and the other classes' ones, answers a throw with a WP_Error.
		OPEX_Rest_Guard::register();
	}

	/**
	 * GET /sources: the sources this person may export, grouped for the picker.
	 *
	 * @return WP_REST_Response The source groups.
	 */
	public static function sources(): WP_REST_Response {
		return rest_ensure_response( OPEX_Catalog::sources() );
	}

	/**
	 * Field groups for a source, plus everything the date-range control needs:
	 * which columns are real dates for this source and what the presets are
	 * called. One round trip instead of three.
	 *
	 * @param WP_REST_Request $request Request with the source id.
	 * @return WP_REST_Response The field groups, the date fields and the date presets.
	 */
	public static function fields( WP_REST_Request $request ): WP_REST_Response {
		$source = (string) $request['source'];
		$src    = OPEX_Query::parse_source( $source );

		$date_fields = array();
		if ( $src ) {
			foreach ( OPEX_Query::date_fields( $src ) as $id => $def ) {
				$date_fields[] = array(
					'id'    => $id,
					'label' => $def['label'],
				);
			}
		}

		$presets = array();
		foreach ( OPEX_Dates::PRESETS as $preset ) {
			$presets[] = array(
				'id'    => $preset,
				'label' => OPEX_Dates::preset_label( $preset ),
			);
		}

		return rest_ensure_response(
			array(
				'groups'      => OPEX_Field_Types::decorate( $source, OPEX_Catalog::fields( $source ) ),
				'date_fields' => $date_fields,
				'presets'     => $presets,
				'timezone'    => wp_timezone_string(),
			)
		);
	}

	/**
	 * The site's export surface as JSON, for handing to an AI assistant.
	 * ?scope=all describes every source, which is large on a busy site.
	 *
	 * @param WP_REST_Request $request Request with the optional source and scope.
	 * @return WP_REST_Response The capability document.
	 */
	public static function schema( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response(
			OPEX_Schema::build( (string) $request['source'], 'all' === (string) $request['scope'] )
		);
	}

	/**
	 * Turn an assistant's recipe into a validated config for the UI to apply.
	 * This configures the screen. It does not start an export.
	 *
	 * @param WP_REST_Request $request Request with the recipe text.
	 * @return WP_REST_Response|WP_Error The config and report, or an error when the recipe cannot be read.
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
		$result = OPEX_Recipe::to_config( $body, $notes );

		// A recipe that resolves to nothing is a reportable outcome, not a
		// transport error: the client needs the report to show the user which
		// ids failed and why. Only a malformed request is an error status.
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * POST /preview: the first rows and the row count for a config.
	 *
	 * @param WP_REST_Request $request Request with the config as JSON.
	 * @return array|WP_Error
	 */
	public static function preview( WP_REST_Request $request ) {
		$notes  = array();
		$config = OPEX_Exporter::sanitize_config( (array) $request->get_json_params(), $notes );
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		$result = OPEX_Exporter::preview( $config );
		if ( is_array( $result ) ) {
			// The same notes a job would carry, so the screen can say what was ignored before anything runs.
			$result['notes'] = array_values( array_unique( array_map( 'strval', array_merge( OPEX_Query::export_notes( $config ), $notes, (array) ( $result['notes'] ?? array() ) ) ) ) );
		}
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * GET /jobs: every job, newest first. See OPEX_Rest_Jobs.
	 *
	 * @return WP_REST_Response The jobs as the screen shows them.
	 */
	public static function jobs(): WP_REST_Response {
		return OPEX_Rest_Jobs::jobs();
	}

	/**
	 * POST /jobs: starts an export from a config. See OPEX_Rest_Jobs.
	 *
	 * @param WP_REST_Request $request Request with config, label, mode and delivery.
	 * @return array|WP_Error
	 */
	public static function create_job( WP_REST_Request $request ) {
		return OPEX_Rest_Jobs::create_job( $request );
	}

	/**
	 * POST /jobs/{id}/run: runs one batch. See OPEX_Rest_Jobs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function run_job( WP_REST_Request $request ) {
		return OPEX_Rest_Jobs::run_job( $request );
	}

	/**
	 * POST /jobs/{id}/pause. See OPEX_Rest_Jobs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function pause_job( WP_REST_Request $request ) {
		return OPEX_Rest_Jobs::pause_job( $request );
	}

	/**
	 * GET /jobs/{id}: one job with its config. See OPEX_Rest_Jobs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function get_job( WP_REST_Request $request ) {
		return OPEX_Rest_Jobs::get_job( $request );
	}

	/**
	 * POST /jobs/{id}/rerun: a new job from an old job's config. See OPEX_Rest_Jobs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function rerun_job( WP_REST_Request $request ) {
		return OPEX_Rest_Jobs::rerun_job( $request );
	}

	/**
	 * DELETE /jobs/{id}: removes the job and its file. See OPEX_Rest_Jobs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response Confirmation that the job is gone.
	 */
	public static function delete_job( WP_REST_Request $request ): WP_REST_Response {
		return OPEX_Rest_Jobs::delete_job( $request );
	}

	/**
	 * Stream a finished file. See OPEX_Rest_Jobs.
	 *
	 * @param WP_REST_Request $request Request with the job id.
	 * @return void|WP_Error Nothing when the file streams, or an error when it cannot.
	 */
	public static function download( WP_REST_Request $request ) {
		return OPEX_Rest_Jobs::download( $request );
	}

	/**
	 * GET /templates: every template, most recently changed first.
	 *
	 * @return WP_REST_Response The templates as the screen shows them.
	 */
	public static function templates(): WP_REST_Response {
		$list = array_map( array( 'OPEX_Templates', 'expose' ), array_values( OPEX_Templates::all() ) );
		usort(
			$list,
			static function ( $a, $b ) {
				return $b['updated'] <=> $a['updated'];
			}
		);
		return rest_ensure_response( $list );
	}

	/**
	 * POST /templates: saves a template. A name that exists updates that template.
	 *
	 * @param WP_REST_Request $request Request with name and config.
	 * @return array|WP_Error
	 */
	public static function save_template( WP_REST_Request $request ) {
		$body   = (array) $request->get_json_params();
		$name   = sanitize_text_field( (string) ( $body['name'] ?? '' ) );
		$config = (array) ( $body['config'] ?? array() );
		$result = OPEX_Templates::save( $name, $config );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * DELETE /templates/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response Confirmation that the template is gone.
	 */
	public static function delete_template( WP_REST_Request $request ): WP_REST_Response {
		OPEX_Templates::delete( (string) $request['id'] );
		return rest_ensure_response( array( 'deleted' => true ) );
	}
}
