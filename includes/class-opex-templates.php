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
	 */
	public static function all(): array {
		$templates = get_option( self::OPTION, array() );
		return is_array( $templates ) ? $templates : array();
	}

	/**
	 * One template, or null.
	 *
	 * @param string $id Template id.
	 */
	public static function get( string $id ): ?array {
		$templates = self::all();
		return $templates[ $id ] ?? null;
	}

	/** Save or update a named template. Returns the stored record. */
	public static function save( string $name, array $config ) {
		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return new WP_Error( 'opex_tpl_name', __( 'Template name is required.', 'open-export' ), array( 'status' => 400 ) );
		}

		$config = OPEX_Exporter::sanitize_config( $config );
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$templates = self::all();
		$now       = time();

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

		update_option( self::OPTION, $templates, false );

		return self::expose( $template );
	}

	/**
	 * Removes a template.
	 *
	 * @param string $id Template id.
	 */
	public static function delete( string $id ): void {
		$templates = self::all();
		if ( isset( $templates[ $id ] ) ) {
			unset( $templates[ $id ] );
			update_option( self::OPTION, $templates, false );
		}
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
