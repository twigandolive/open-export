<?php
/**
 * Registers Open Export with the WordPress Abilities API.
 *
 * Both abilities are read-only. None starts an export or returns row data.
 * The API is checked at run time: on a WordPress without it, nothing happens.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Category and abilities for assistants and other tools.
 */
class OPEX_Abilities {

	public const CATEGORY = 'open-export';

	/**
	 * Adds the hooks, only when the Abilities API exists.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Registers the category.
	 *
	 * @return void
	 */
	public static function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Open Export', 'open-export' ),
				'description' => __( 'Read-only information about what this site can export.', 'open-export' ),
			)
		);
	}

	/**
	 * Registers the two abilities.
	 *
	 * @return void
	 */
	public static function register_abilities(): void {
		$meta = array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
		);

		wp_register_ability(
			'open-export/get-schema',
			array(
				'label'               => __( 'Get the export schema', 'open-export' ),
				'description'         => __( 'Describes what this site can export: sources, fields, filter operators and date presets. Returns no row data and starts no export.', 'open-export' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'properties'           => array(
						'source' => array(
							'type'        => 'string',
							'description' => __( 'A source id to describe in full, for example posts:post. Optional.', 'open-export' ),
						),
						'all'    => array(
							'type'        => 'boolean',
							'description' => __( 'Describe every source in full. The result can be large. Optional.', 'open-export' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => __( 'The schema document.', 'open-export' ),
					'properties'  => array(
						'opex_schema'    => array( 'type' => 'integer' ),
						'plugin'         => array( 'type' => 'string' ),
						'plugin_version' => array( 'type' => 'string' ),
						'all_sources'    => array( 'type' => 'array' ),
						'sources'        => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'get_schema' ),
				'permission_callback' => array( __CLASS__, 'can_use' ),
				'meta'                => $meta,
			)
		);

		wp_register_ability(
			'open-export/list-sources',
			array(
				'label'               => __( 'List export sources', 'open-export' ),
				'description'         => __( 'Lists the groups of data this site can export, with a row count for each source. Returns no row data and starts no export.', 'open-export' ),
				'category'            => self::CATEGORY,
				'output_schema'       => array(
					'type'        => 'array',
					'description' => __( 'Source groups, each with its sources.', 'open-export' ),
					'items'       => array( 'type' => 'object' ),
				),
				'execute_callback'    => array( __CLASS__, 'list_sources' ),
				'permission_callback' => array( __CLASS__, 'can_use' ),
				'meta'                => $meta,
			)
		);
	}

	/**
	 * Permission check shared by both abilities.
	 *
	 * @return bool
	 */
	public static function can_use(): bool {
		return current_user_can( opex_capability() );
	}

	/**
	 * Runs get-schema.
	 *
	 * @param mixed $input Validated input.
	 * @return array
	 */
	public static function get_schema( $input = null ): array {
		$input = is_array( $input ) ? $input : array();
		return OPEX_Schema::build( (string) ( $input['source'] ?? '' ), ! empty( $input['all'] ) );
	}

	/**
	 * Runs list-sources.
	 *
	 * @return array
	 */
	public static function list_sources(): array {
		return OPEX_Catalog::sources();
	}
}
