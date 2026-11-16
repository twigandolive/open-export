<?php
/**
 * REST routes and screen data added for the 2.0 admin screen (admin only).
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Retry deliveries now, reset a schedule's watermark, list feed presets, and
 * the extra lists GET /options hands the screen.
 */
class OPEX_Rest_Screen {

	private const NS = 'open-export/v1';

	/**
	 * Registers the routes. OPEX_Rest::register_routes calls this, so the
	 * routes exist wherever the shipped ones do.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		$can = array( __CLASS__, 'can' );

		register_rest_route(
			self::NS,
			'/jobs/(?P<id>j[a-f0-9]{12})/deliver',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $can,
				'callback'            => array( __CLASS__, 'deliver' ),
			)
		);

		register_rest_route(
			self::NS,
			'/schedules/(?P<id>s[a-f0-9]{12})/reset-watermark',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $can,
				'callback'            => array( __CLASS__, 'reset_watermark' ),
			)
		);

		register_rest_route(
			self::NS,
			'/presets',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $can,
				'callback'            => array( __CLASS__, 'presets' ),
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
	 * POST /jobs/<id>/deliver: runs pending and failed deliveries that still
	 * have attempts left, now, without waiting for the retry delay.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function deliver( WP_REST_Request $request ) {
		$result = OPEX_Runner::deliver_now( (string) $request['id'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * POST /schedules/<id>/reset-watermark: the next incremental run exports everything again.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function reset_watermark( WP_REST_Request $request ) {
		$saved = OPEX_Schedules::reset_watermark( (string) $request['id'] );
		if ( ! $saved ) {
			return new WP_Error( 'opex_missing', __( 'Schedule not found.', 'open-export' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( OPEX_Schedules::expose( $saved ) );
	}

	/**
	 * GET /presets: ready-made product feed configs. Empty without WooCommerce.
	 *
	 * @return WP_REST_Response
	 */
	public static function presets(): WP_REST_Response {
		return rest_ensure_response( OPEX_Presets::all() );
	}

	/**
	 * The filter operators with labels a person reads, and whether each needs a value.
	 *
	 * @return array[]
	 */
	public static function operators(): array {
		$labels = array(
			'eq'           => __( 'equals', 'open-export' ),
			'neq'          => __( 'does not equal', 'open-export' ),
			'contains'     => __( 'contains', 'open-export' ),
			'not_contains' => __( 'does not contain', 'open-export' ),
			'starts_with'  => __( 'starts with', 'open-export' ),
			'ends_with'    => __( 'ends with', 'open-export' ),
			'gt'           => '>',
			'gte'          => '≥',
			'lt'           => '<',
			'lte'          => '≤',
			'in'           => __( 'is one of (comma list)', 'open-export' ),
			'not_in'       => __( 'is not one of (comma list)', 'open-export' ),
			'empty'        => __( 'is empty', 'open-export' ),
			'not_empty'    => __( 'is not empty', 'open-export' ),
		);
		$out = array();
		foreach ( OPEX_Query::ops() as $op ) {
			$out[] = array(
				'id'          => $op,
				'label'       => $labels[ $op ] ?? $op,
				'needs_value' => ! in_array( $op, array( 'empty', 'not_empty' ), true ),
			);
		}
		return $out;
	}

	/**
	 * Text limits the screen enforces before it sends anything. Each one is the
	 * server's own limit, so the two never disagree.
	 *
	 * @return array<string, int>
	 */
	public static function string_limits(): array {
		return array(
			'transform_text'  => OPEX_Transforms::MAX_STRING,
			'map_pairs'       => OPEX_Transforms::MAX_PAIRS,
			'xml_part'        => OPEX_Config::XML_PART_MAX,
			'custom_label'    => OPEX_Custom_Columns::MAX_LABEL,
			'custom_template' => OPEX_Custom_Columns::MAX_TEMPLATE,
		);
	}

	/**
	 * Adds the operators, the text limits, the super admin flag and each
	 * destination type's security note to the GET /options payload.
	 *
	 * @param array $options Options payload.
	 * @return array
	 */
	public static function extend_options( array $options ): array {
		$options['operators']      = self::operators();
		$options['limits']         = array_merge( (array) ( $options['limits'] ?? array() ), self::string_limits() );
		$options['is_super_admin'] = is_multisite() && is_super_admin();
		foreach ( (array) ( $options['destination_types'] ?? array() ) as $i => $type ) {
			$options['destination_types'][ $i ]['security_note'] = OPEX_Destinations::security_note( (string) ( $type['id'] ?? '' ) );
		}
		return $options;
	}
}
