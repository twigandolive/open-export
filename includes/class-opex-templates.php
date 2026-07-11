<?php
/**
 * Saved export templates: named configs users can reload or re-run.
 */

defined( 'ABSPATH' ) || exit;

class OPEX_Templates {

	private const OPTION = 'opex_templates';
	private const MAX    = 100;

	public static function all(): array {
		$templates = get_option( self::OPTION, array() );
		return is_array( $templates ) ? $templates : array();
	}

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
		$id        = 't' . substr( md5( $name . wp_json_encode( $config ) . microtime( true ) ), 0, 12 );
		$now       = time();

		$template = array(
			'id'      => $id,
			'name'    => $name,
			'config'  => $config,
			'created' => $now,
			'updated' => $now,
		);

		$templates[ $id ] = $template;

		if ( count( $templates ) > self::MAX ) {
			uasort( $templates, static function ( $a, $b ) {
				return $a['updated'] <=> $b['updated'];
			} );
			while ( count( $templates ) > self::MAX ) {
				unset( $templates[ array_key_first( $templates ) ] );
			}
		}

		update_option( self::OPTION, $templates, false );

		return self::expose( $template );
	}

	public static function delete( string $id ): void {
		$templates = self::all();
		if ( isset( $templates[ $id ] ) ) {
			unset( $templates[ $id ] );
			update_option( self::OPTION, $templates, false );
		}
	}

	public static function expose( array $template ): array {
		return array(
			'id'      => $template['id'],
			'name'    => $template['name'],
			'config'  => $template['config'],
			'created' => (int) $template['created'],
			'updated' => (int) $template['updated'],
		);
	}
}
