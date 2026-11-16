<?php
/**
 * REST answers for the job routes: list, start, run a batch, pause, read,
 * rerun, delete and download. OPEX_Rest registers the routes, with the same
 * capability check and nonce as every other admin route.
 *
 * Part of the REST compartment. Routes reach it through OPEX_Rest.
 *
 * @package Open_Export
 */

// No strict_types here on purpose: strict types would change how this code coerces arguments.

defined( 'ABSPATH' ) || exit;

/**
 * Answers the job routes of the admin screen.
 */
class OPEX_Rest_Jobs {

	/**
	 * GET /jobs: every job, newest first.
	 *
	 * @return WP_REST_Response The jobs as the screen shows them.
	 */
	public static function jobs(): WP_REST_Response {
		$jobs = array_map( array( 'OPEX_Jobs', 'expose' ), array_values( OPEX_Jobs::all() ) );
		usort(
			$jobs,
			static function ( $a, $b ) {
				return $b['created'] <=> $a['created'];
			}
		);
		return rest_ensure_response( $jobs );
	}

	/**
	 * POST /jobs: starts an export from a config, in the browser or in the background.
	 *
	 * @param WP_REST_Request $request Request with config, label, mode and delivery.
	 * @return array|WP_Error
	 */
	public static function create_job( WP_REST_Request $request ) {
		$body   = (array) $request->get_json_params();
		$notes  = array();
		$config = OPEX_Exporter::sanitize_config( $body, $notes );
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$label = sanitize_text_field( (string) ( $body['label'] ?? '' ) );
		$label = $label ? $label : $config['source'];

		// Without a mode the Background setting decides. The screen sends "browser" and paces the run itself.
		$mode = isset( $body['mode'] ) && is_string( $body['mode'] ) ? $body['mode'] : ( OPEX_Settings::get( 'background' ) ? 'background' : 'browser' );
		if ( ! in_array( $mode, array( 'browser', 'background' ), true ) ) {
			return new WP_Error( 'opex_bad_mode', __( 'The mode must be browser or background.', 'open-export' ), array( 'status' => 400 ) );
		}

		$extra = array(
			'mode'   => $mode,
			'origin' => 'manual',
			'notes'  => $notes,
		);
		if ( isset( $body['delivery'] ) && is_array( $body['delivery'] ) ) {
			$extra['delivery'] = $body['delivery'];
		}

		$result = OPEX_Exporter::create( $config, $label, $extra );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 'background' === $mode && 'running' === $result['status'] ) {
			OPEX_Runner::dispatch( (string) $result['id'] );
		}
		return rest_ensure_response( $result );
	}

	/**
	 * POST /jobs/{id}/run: runs one batch. Only the Resume button passes resume, so a late
	 * request from another window cannot undo a pause.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function run_job( WP_REST_Request $request ) {
		// Only the Resume button sends resume, so a late run call cannot undo a pause.
		$resume = rest_sanitize_boolean( $request->get_param( 'resume' ) );
		$result = OPEX_Exporter::run( (string) $request['id'], $resume );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// A background job is driven by the runner, not the browser. Without
		// this, a resumed background job ran one batch and then waited for the
		// hourly maintenance to notice it had stalled.
		$job = 'running' === $result['status'] ? OPEX_Jobs::get( (string) $request['id'] ) : null;
		if ( $job && 'background' === $job['mode'] ) {
			OPEX_Runner::dispatch( (string) $job['id'] );
		}
		return rest_ensure_response( $result );
	}

	/**
	 * POST /jobs/{id}/pause.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function pause_job( WP_REST_Request $request ) {
		$result = OPEX_Exporter::pause( (string) $request['id'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * GET /jobs/{id}: one job with its config, for reloading it in the builder.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function get_job( WP_REST_Request $request ) {
		$job = OPEX_Jobs::get( (string) $request['id'] );
		if ( ! $job ) {
			return new WP_Error( 'opex_missing', __( 'That export does not exist.', 'open-export' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( OPEX_Jobs::expose_detail( $job ) );
	}

	/**
	 * POST /jobs/{id}/rerun: a new job from an old job's config.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function rerun_job( WP_REST_Request $request ) {
		$result = OPEX_Exporter::rerun( (string) $request['id'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * DELETE /jobs/{id}: removes the job and its file.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response Confirmation that the job is gone.
	 */
	public static function delete_job( WP_REST_Request $request ): WP_REST_Response {
		OPEX_Jobs::delete( (string) $request['id'] );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Stream a finished file. The permission callback and REST cookie auth
	 * check the capability and the nonce before this runs.
	 *
	 * @param WP_REST_Request $request Request with the job id.
	 * @return void|WP_Error Nothing when the file streams, or an error when it cannot.
	 */
	public static function download( WP_REST_Request $request ) {
		$job = OPEX_Jobs::get( (string) $request['id'] );
		if ( ! $job || 'done' !== $job['status'] ) {
			return new WP_Error( 'opex_not_ready', __( 'The export is not finished yet.', 'open-export' ), array( 'status' => 404 ) );
		}

		$path = OPEX_Jobs::file_path( $job );
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'opex_gone', __( 'The export file no longer exists.', 'open-export' ), array( 'status' => 404 ) );
		}

		OPEX_Download::stream( $job, OPEX_Download::file_name( $job ) );
		return new WP_Error( 'opex_gone', __( 'The export file no longer exists.', 'open-export' ), array( 'status' => 404 ) );
	}
}
