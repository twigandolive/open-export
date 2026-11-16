<?php
/**
 * Plugin Name:       Open Export
 * Plugin URI:        https://twigandolive.com/shop/
 * Description:       Export anything from your WordPress database: posts, users, comments, terms, or any table. Smart field discovery, powerful filters, host-friendly pacing, and CSV / JSON / XML output. Everything included. No upsells, no add-ons.
 * Version:           1.3.1
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            Twig & Olive
 * Author URI:        https://twigandolive.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       open-export
 */

defined( 'ABSPATH' ) || exit;

define( 'OPEX_VERSION', '1.3.1' );
define( 'OPEX_FILE', __FILE__ );
define( 'OPEX_DIR', plugin_dir_path( __FILE__ ) );
define( 'OPEX_URL', plugin_dir_url( __FILE__ ) );
define( 'OPEX_BASENAME', plugin_basename( __FILE__ ) );

require_once OPEX_DIR . 'includes/class-opex-dates.php';
require_once OPEX_DIR . 'includes/class-opex-woo.php';
require_once OPEX_DIR . 'includes/class-opex-money.php';
require_once OPEX_DIR . 'includes/class-opex-decimal.php';
require_once OPEX_DIR . 'includes/class-opex-woo-format.php';
require_once OPEX_DIR . 'includes/class-opex-source-provider.php';
require_once OPEX_DIR . 'includes/class-opex-source-posts.php';
require_once OPEX_DIR . 'includes/class-opex-source-table.php';
require_once OPEX_DIR . 'includes/class-opex-source-mapped.php';
require_once OPEX_DIR . 'includes/class-opex-source-woo-orders.php';
require_once OPEX_DIR . 'includes/class-opex-source-woo-orders-legacy.php';
require_once OPEX_DIR . 'includes/class-opex-source-woo-orders-rules.php';
require_once OPEX_DIR . 'includes/class-opex-source-woo-order-items.php';
require_once OPEX_DIR . 'includes/class-opex-source-woo-customers.php';
require_once OPEX_DIR . 'includes/class-opex-source-woo-product-values.php';
require_once OPEX_DIR . 'includes/class-opex-source-woo-products.php';
require_once OPEX_DIR . 'includes/class-opex-source-woo-coupons.php';
require_once OPEX_DIR . 'includes/class-opex-sources.php';
require_once OPEX_DIR . 'includes/class-opex-core-columns.php';
require_once OPEX_DIR . 'includes/class-opex-acf-location.php';
require_once OPEX_DIR . 'includes/class-opex-acf.php';
require_once OPEX_DIR . 'includes/class-opex-catalog.php';
require_once OPEX_DIR . 'includes/class-opex-field-types.php';
require_once OPEX_DIR . 'includes/class-opex-source-labels.php';
require_once OPEX_DIR . 'includes/class-opex-query.php';
require_once OPEX_DIR . 'includes/class-opex-query-filters.php';
require_once OPEX_DIR . 'includes/class-opex-filter-tree.php';
require_once OPEX_DIR . 'includes/class-opex-query-dates.php';
require_once OPEX_DIR . 'includes/class-opex-query-rows.php';
require_once OPEX_DIR . 'includes/class-opex-writers.php';
require_once OPEX_DIR . 'includes/class-opex-writer-xlsx.php';
require_once OPEX_DIR . 'includes/class-opex-writer-xml-template.php';
require_once OPEX_DIR . 'includes/class-opex-custom-columns.php';
require_once OPEX_DIR . 'includes/class-opex-acf-spread.php';
require_once OPEX_DIR . 'includes/class-opex-transforms.php';
require_once OPEX_DIR . 'includes/class-opex-lock.php';
require_once OPEX_DIR . 'includes/class-opex-jobs.php';
require_once OPEX_DIR . 'includes/class-opex-templates.php';
require_once OPEX_DIR . 'includes/class-opex-secrets.php';
require_once OPEX_DIR . 'includes/class-opex-settings.php';
require_once OPEX_DIR . 'includes/class-opex-destinations.php';
require_once OPEX_DIR . 'includes/class-opex-download-tokens.php';
require_once OPEX_DIR . 'includes/class-opex-delivery-email.php';
require_once OPEX_DIR . 'includes/class-opex-delivery-webhook.php';
require_once OPEX_DIR . 'includes/class-opex-delivery-ftp.php';
require_once OPEX_DIR . 'includes/class-opex-delivery-sftp.php';
require_once OPEX_DIR . 'includes/class-opex-delivery.php';
require_once OPEX_DIR . 'includes/class-opex-filename.php';
require_once OPEX_DIR . 'includes/class-opex-schedule-clock.php';
require_once OPEX_DIR . 'includes/class-opex-schedules.php';
require_once OPEX_DIR . 'includes/class-opex-runner.php';
require_once OPEX_DIR . 'includes/class-opex-config.php';
require_once OPEX_DIR . 'includes/class-opex-network.php';
require_once OPEX_DIR . 'includes/class-opex-access.php';
require_once OPEX_DIR . 'includes/class-opex-exporter.php';
require_once OPEX_DIR . 'includes/class-opex-schema.php';
require_once OPEX_DIR . 'includes/class-opex-schema-document.php';
require_once OPEX_DIR . 'includes/class-opex-recipe-rules.php';
require_once OPEX_DIR . 'includes/class-opex-recipe.php';
require_once OPEX_DIR . 'includes/class-opex-presets.php';
require_once OPEX_DIR . 'includes/class-opex-download.php';
require_once OPEX_DIR . 'includes/class-opex-rest.php';
require_once OPEX_DIR . 'includes/class-opex-rest-schedules.php';
require_once OPEX_DIR . 'includes/class-opex-rest-delivery.php';
require_once OPEX_DIR . 'includes/class-opex-rest-public.php';
require_once OPEX_DIR . 'includes/class-opex-rest-screen.php';
require_once OPEX_DIR . 'includes/class-opex-admin.php';
require_once OPEX_DIR . 'includes/class-opex-privacy.php';
require_once OPEX_DIR . 'includes/class-opex-site-health.php';
require_once OPEX_DIR . 'includes/class-opex-abilities.php';
require_once OPEX_DIR . 'includes/class-opex-cli.php';

/**
 * Capability check for all screens, endpoints, and downloads. Administrator-level;
 * the plugin exposes raw table access.
 */
function opex_capability(): string {
	return apply_filters( 'opex_capability', 'manage_options' );
}

/**
 * Export directory under uploads. Created on demand; protected by index.php,
 * an .htaccess deny rule, and randomized filenames.
 */
function opex_export_dir(): string {
	$uploads = wp_upload_dir();
	$dir     = trailingslashit( $uploads['basedir'] ) . 'open-export';

	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	// phpcs:disable WordPress.WP.AlternativeFunctions.file_ops_file_put_contents -- Guard file generation; WP_Filesystem unviable for streaming.
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
 * Release per-job locks when the plugin is deactivated.
 */
function opex_deactivate(): void {
	foreach ( array_keys( OPEX_Jobs::all() ) as $id ) {
		OPEX_Jobs::release_lock( $id );
	}
	OPEX_Runner::unregister();
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
		esc_url( admin_url( 'tools.php?page=open-export' ) ),
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

	// WordPress already renders a "Visit plugin site" link from Plugin URI, so
	// these add only what it does not cover.
	$meta = array(
		array( __( 'https://twigandolive.com/contact-twig-and-olive/', 'open-export' ), __( 'Support', 'open-export' ) ),
		array( __( 'https://twigandolive.com/privacy-policy/', 'open-export' ), __( 'Privacy', 'open-export' ) ),
	);

	foreach ( $meta as $item ) {
		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $item[0] ),
			esc_html( $item[1] )
		);
	}

	return $links;
}

add_action( 'rest_api_init', array( 'OPEX_Rest', 'register_routes' ) );
add_action( 'rest_api_init', array( 'OPEX_Rest_Schedules', 'register_routes' ) );
add_action( 'rest_api_init', array( 'OPEX_Rest_Delivery', 'register_routes' ) );
add_action( 'rest_api_init', array( 'OPEX_Rest_Public', 'register_routes' ) );
add_action( 'opex_job_done', array( 'OPEX_Schedules', 'on_job_done' ) );
add_action( 'admin_menu', array( 'OPEX_Admin', 'register_menu' ) );
add_action( 'admin_enqueue_scripts', array( 'OPEX_Admin', 'enqueue' ) );
add_filter( 'plugin_action_links_' . OPEX_BASENAME, 'opex_plugin_action_links' );
add_filter( 'plugin_row_meta', 'opex_plugin_row_meta', 10, 2 );

OPEX_Privacy::register();
OPEX_Site_Health::register();
OPEX_Abilities::register();
OPEX_Runner::register();
OPEX_CLI::register();

register_activation_hook( __FILE__, 'opex_export_dir' );
register_deactivation_hook( __FILE__, 'opex_deactivate' );
