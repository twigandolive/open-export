<?php
/**
 * Plugin Name:       Open Export
 * Plugin URI:        https://twigandolive.com
 * Description:       Export anything from your WordPress database — posts, users, comments, terms, or any table — with smart field discovery, powerful filters, host-friendly pacing, and CSV / JSON / XML output. Everything included. No upsells, no add-ons.
 * Version:           1.1.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Twig & Olive
 * Author URI:        https://twigandolive.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       open-export
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'OPEX_VERSION', '1.1.1' );
define( 'OPEX_FILE', __FILE__ );
define( 'OPEX_DIR', plugin_dir_path( __FILE__ ) );
define( 'OPEX_URL', plugin_dir_url( __FILE__ ) );
define( 'OPEX_BASENAME', plugin_basename( __FILE__ ) );

require_once OPEX_DIR . 'includes/class-opex-catalog.php';
require_once OPEX_DIR . 'includes/class-opex-query.php';
require_once OPEX_DIR . 'includes/class-opex-writers.php';
require_once OPEX_DIR . 'includes/class-opex-jobs.php';
require_once OPEX_DIR . 'includes/class-opex-templates.php';
require_once OPEX_DIR . 'includes/class-opex-exporter.php';
require_once OPEX_DIR . 'includes/class-opex-rest.php';
require_once OPEX_DIR . 'includes/class-opex-admin.php';

/**
 * Capability required for every Open Export screen, endpoint, and download.
 * Raw table access means this must stay an administrator-level capability.
 */
function opex_capability(): string {
	return apply_filters( 'opex_capability', 'manage_options' );
}

/**
 * Directory where export files are written, created on demand and protected
 * with an index.php and an .htaccess deny rule. Filenames also carry a
 * random suffix so they cannot be guessed on servers that ignore .htaccess.
 */
function opex_export_dir(): string {
	$uploads = wp_upload_dir();
	$dir     = trailingslashit( $uploads['basedir'] ) . 'open-export';

	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	// phpcs:disable WordPress.WP.AlternativeFunctions.file_ops_file_put_contents -- writing two tiny guard files into our own uploads subdirectory; WP_Filesystem is not viable for streamed export output either.
	if ( ! file_exists( $dir . '/index.php' ) ) {
		file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
	}
	if ( ! file_exists( $dir . '/.htaccess' ) ) {
		file_put_contents( $dir . '/.htaccess', "Require all denied\n" );
	}
	// phpcs:enable

	return $dir;
}

/**
 * Load plugin translations.
 */
function opex_load_textdomain(): void {
	load_plugin_textdomain( 'open-export', false, dirname( OPEX_BASENAME ) . '/languages' );
}

/**
 * Release per-job locks when the plugin is deactivated.
 */
function opex_deactivate(): void {
	foreach ( array_keys( OPEX_Jobs::all() ) as $id ) {
		OPEX_Jobs::release_lock( $id );
	}
}

/**
 * "Open Export" shortcut on the Plugins screen.
 *
 * @param string[] $links Existing action links.
 * @return string[]
 */
function opex_plugin_action_links( array $links ): array {
	$export_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'admin.php?page=open-export' ) ),
		esc_html__( 'Open Export', 'open-export' )
	);

	return array_merge( array( $export_link ), $links );
}

/**
 * Meta links shown beneath the plugin description on the Plugins screen.
 *
 * @param string[] $links Existing meta links.
 * @param string   $file  Plugin basename.
 * @return string[]
 */
function opex_plugin_row_meta( array $links, string $file ): array {
	if ( OPEX_BASENAME !== $file ) {
		return $links;
	}

	return array_merge(
		$links,
		array(
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( __( 'https://twigandolive.com/open-export/', 'open-export' ) ),
				esc_html__( 'Documentation', 'open-export' )
			),
		)
	);
}

add_action( 'init', 'opex_load_textdomain' );
add_action( 'rest_api_init', array( 'OPEX_Rest', 'register_routes' ) );
add_action( 'admin_menu', array( 'OPEX_Admin', 'register_menu' ) );
add_action( 'admin_enqueue_scripts', array( 'OPEX_Admin', 'enqueue' ) );
add_filter( 'plugin_action_links_' . OPEX_BASENAME, 'opex_plugin_action_links' );
add_filter( 'plugin_row_meta', 'opex_plugin_row_meta', 10, 2 );

register_activation_hook( __FILE__, 'opex_export_dir' );
register_deactivation_hook( __FILE__, 'opex_deactivate' );
