<?php
/**
 * Saved export templates: named configs users can reload or re-run.
 *
 * @package Open_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores named export configs in one option and keeps the newest when the list is full.
 */
class OPEX_Templates {

	private const OPTION = 'opex_templates';
	private const MAX    = 100;

	/**
	 * Every saved template, by id.
	 *
	 * @return array Templates keyed by id.
	 */
	public static function all(): array {
		$templates = get_option( self::OPTION, array() );
		return is_array( $templates ) ? $templates : array();
	}

	/**
	 * One template, or null.
	 *
	 * @param string $id Template id.
	 * @return array|null The template, or null.
	 */
	public static function get( string $id ): ?array {
		$templates = self::all();
		return $templates[ $id ] ?? null;
	}

	/**
	 * Save or update a named template. Returns the stored record.
	 *
	 * @param string $name   Template name people see.
	 * @param array  $config Export config to save.
	 * @return array|WP_Error The template as the screen shows it, or an error.
	 * @throws OPEX_User_Error Any error from the store other than busy, which becomes a WP_Error.
	 */
	public static function save( string $name, array $config ) {
		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return new WP_Error( 'opex_tpl_name', __( 'Template name is required.', 'open-export' ), array( 'status' => 400 ) );
		}

		$config = OPEX_Exporter::sanitize_config( $config );
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		try {
			$template = OPEX_Store::change(
				self::OPTION,
				static function ( array &$templates ) use ( $name, $config ): array {
					return self::put( $templates, $name, $config );
				}
			);
		} catch ( OPEX_User_Error $e ) {
			if ( ! OPEX_Store::is_busy( $e ) ) {
				throw $e;
			}
			return new WP_Error( 'opex_busy', $e->getMessage(), array( 'status' => 503 ) );
		}

		return self::expose( $template );
	}

	/**
	 * Removes a template.
	 *
	 * @param string $id Template id.
	 * @return void
	 * @throws OPEX_User_Error When the delete could not be saved (see OPEX_Store::change).
	 */
	public static function delete( string $id ): void {
		OPEX_Store::change(
			self::OPTION,
			static function ( array &$templates ) use ( $id ): void {
				unset( $templates[ $id ] );
			}
		);
	}

	/**
	 * Adds or updates a template in the stored list and trims the list to
	 * MAX. Runs under the option's lock, on the list as stored now, so a
	 * template another request saved or deleted meanwhile stays that way.
	 *
	 * @param array  $templates Stored templates, changed in place.
	 * @param string $name      Clean template name.
	 * @param array  $config    Clean export config.
	 * @return array The stored template.
	 */
	private static function put( array &$templates, string $name, array $config ): array {
		$now = time();

		// Saving under a name that already exists updates that template. If
		// older duplicates exist, the most recently updated one wins.
		$existing = null;
		foreach ( $templates as $candidate ) {
			if ( is_array( $candidate ) && (string) ( $candidate['name'] ?? '' ) === $name
				&& ( null === $existing || (int) ( $candidate['updated'] ?? 0 ) > (int) ( $existing['updated'] ?? 0 ) ) ) {
				$existing = $candidate;
			}
		}

		$id       = $existing ? (string) $existing['id'] : 't' . substr( md5( $name . wp_json_encode( $config ) . microtime( true ) ), 0, 12 );
		$template = array(
			'id'      => $id,
			'name'    => $name,
			'config'  => $config,
			'created' => $existing ? (int) ( $existing['created'] ?? $now ) : $now,
			'updated' => $now,
		);

		$templates[ $id ] = $template;

		if ( count( $templates ) > self::MAX ) {
			uasort(
				$templates,
				static function ( $a, $b ) {
					return $a['updated'] <=> $b['updated'];
				}
			);
			// Oldest first, so the newest MAX stay.
			$templates = array_slice( $templates, -self::MAX, null, true );
		}
		return $template;
	}

	/**
	 * Public template shape. The source id and its translated name let the
	 * screen show what each template exports without reading its config.
	 *
	 * @param array $template Stored template.
	 * @return array
	 */
	public static function expose( array $template ): array {
		$source = (string) ( $template['config']['source'] ?? '' );
		return array(
			'id'           => $template['id'],
			'name'         => $template['name'],
			'config'       => $template['config'],
			'created'      => (int) $template['created'],
			'updated'      => (int) $template['updated'],
			'source'       => $source,
			'source_label' => OPEX_Source_Labels::of( $source ),
		);
	}
}
