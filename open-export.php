<?php
/**
 * Plugin Name:       Open Export
 * Plugin URI:        https://twigandolive.com/shop/
 * Description:       Export posts, users, orders, products or any table to CSV, Excel, JSON, XML or HTML. Schedule and send the file. Every feature included, no add-ons.
 * Version:           2.0.0
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            Twig & Olive
 * Author URI:        https://twigandolive.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       open-export
 *
 * @package Open_Export
 */

defined( 'ABSPATH' ) || exit;

define( 'OPEX_VERSION', '2.0.0' );
define( 'OPEX_FILE', __FILE__ );
define( 'OPEX_DIR', plugin_dir_path( __FILE__ ) );
define( 'OPEX_URL', plugin_dir_url( __FILE__ ) );
define( 'OPEX_BASENAME', plugin_basename( __FILE__ ) );

require_once OPEX_DIR . 'includes/class-opex-dates.php';
require_once OPEX_DIR . 'includes/class-opex-woo.php';
require_once OPEX_DIR . 'includes/class-opex-woo-order-picker.php';
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
require_once OPEX_DIR . 'includes/class-opex-catalog-meta.php';
require_once OPEX_DIR . 'includes/class-opex-catalog-fields.php';
require_once OPEX_DIR . 'includes/class-opex-field-types.php';
require_once OPEX_DIR . 'includes/class-opex-source-labels.php';
require_once OPEX_DIR . 'includes/class-opex-query.php';
require_once OPEX_DIR . 'includes/class-opex-query-fetch.php';
require_once OPEX_DIR . 'includes/class-opex-query-filters.php';
require_once OPEX_DIR . 'includes/class-opex-query-relations.php';
require_once OPEX_DIR . 'includes/class-opex-filter-tree.php';
require_once OPEX_DIR . 'includes/class-opex-query-dates.php';
require_once OPEX_DIR . 'includes/class-opex-query-rows.php';
require_once OPEX_DIR . 'includes/class-opex-writer.php';
require_once OPEX_DIR . 'includes/class-opex-writer-csv.php';
require_once OPEX_DIR . 'includes/class-opex-writer-json.php';
require_once OPEX_DIR . 'includes/class-opex-writer-xml.php';
require_once OPEX_DIR . 'includes/class-opex-writer-xlsx.php';
require_once OPEX_DIR . 'includes/class-opex-writer-xml-template.php';
require_once OPEX_DIR . 'includes/class-opex-writer-html.php';
require_once OPEX_DIR . 'includes/class-opex-custom-columns.php';
require_once OPEX_DIR . 'includes/class-opex-acf-spread.php';
require_once OPEX_DIR . 'includes/class-opex-transforms.php';
require_once OPEX_DIR . 'includes/class-opex-lock.php';
require_once OPEX_DIR . 'includes/class-opex-store.php';
require_once OPEX_DIR . 'includes/class-opex-file-lock.php';
require_once OPEX_DIR . 'includes/class-opex-user-error.php';
require_once OPEX_DIR . 'includes/class-opex-jobs.php';
require_once OPEX_DIR . 'includes/class-opex-compress.php';
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
require_once OPEX_DIR . 'includes/class-opex-schedule-start.php';
require_once OPEX_DIR . 'includes/class-opex-schedule-rules.php';
require_once OPEX_DIR . 'includes/class-opex-schedule-owed.php';
require_once OPEX_DIR . 'includes/class-opex-cron-retry.php';
require_once OPEX_DIR . 'includes/class-opex-runner.php';
require_once OPEX_DIR . 'includes/class-opex-config.php';
require_once OPEX_DIR . 'includes/class-opex-config-output.php';
require_once OPEX_DIR . 'includes/class-opex-network.php';
require_once OPEX_DIR . 'includes/class-opex-access.php';
require_once OPEX_DIR . 'includes/class-opex-exporter.php';
require_once OPEX_DIR . 'includes/class-opex-export-run.php';
require_once OPEX_DIR . 'includes/class-opex-schema.php';
require_once OPEX_DIR . 'includes/class-opex-schema-sources.php';
require_once OPEX_DIR . 'includes/class-opex-schema-resolver.php';
require_once OPEX_DIR . 'includes/class-opex-schema-document.php';
require_once OPEX_DIR . 'includes/class-opex-recipe-rules.php';
require_once OPEX_DIR . 'includes/class-opex-recipe.php';
require_once OPEX_DIR . 'includes/class-opex-presets.php';
require_once OPEX_DIR . 'includes/class-opex-preset-product-csv.php';
require_once OPEX_DIR . 'includes/class-opex-download.php';
require_once OPEX_DIR . 'includes/class-opex-rest.php';
require_once OPEX_DIR . 'includes/class-opex-rest-jobs.php';
require_once OPEX_DIR . 'includes/class-opex-rest-schedules.php';
require_once OPEX_DIR . 'includes/class-opex-rest-delivery.php';
require_once OPEX_DIR . 'includes/class-opex-rest-public.php';
require_once OPEX_DIR . 'includes/class-opex-rest-screen.php';
require_once OPEX_DIR . 'includes/class-opex-rest-guard.php';
require_once OPEX_DIR . 'includes/class-opex-admin.php';
require_once OPEX_DIR . 'includes/class-opex-privacy.php';
require_once OPEX_DIR . 'includes/class-opex-site-health.php';
require_once OPEX_DIR . 'includes/class-opex-abilities.php';
require_once OPEX_DIR . 'includes/class-opex-cli.php';

/**
 * Capability check for all screens, endpoints, and downloads. Administrator
 * level by default, because the plugin exposes raw table access.
 *
 * @return string The capability a user needs to use the plugin.
 */
function opex_capability(): string {
	return apply_filters( 'opex_capability', 'manage_options' );
}

/**
 * Export directory under uploads, created on demand. Files in it have
 * random names, and the folder carries deny rules for the web servers that
 * read them. nginx reads neither file and needs its own rule (see readme).
 *
 * @return string Full path of the export folder, with a trailing slash.
 */
function opex_export_dir(): string {
	static $guarded = array();

	$uploads = wp_upload_dir();
	$dir     = trailingslashit( $uploads['basedir'] ) . 'open-export';

	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	// Once per folder per request: the guard files rarely change, and this runs for every job file path.
	if ( ! isset( $guarded[ $dir ] ) ) {
		opex_guard_export_dir( $dir );
		$guarded[ $dir ] = true;
	}

	return $dir;
}

/**
 * The deny rules for the export folder, by file name. The .htaccess holds
 * both Apache syntaxes (2.4 and 2.2) inside IfModule blocks, so neither
 * version answers with an error. LiteSpeed reads it too. IIS reads
 * web.config, whose request filter refuses every file.
 *
 * @return array<string, string>
 */
function opex_export_dir_rules(): array {
	return array(
		'.htaccess'  => "# Open Export: no web access to export files.\n"
			. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
		'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
			. "<!-- Open Export: no web access to export files. -->\n"
			. "<configuration>\n\t<system.webServer>\n\t\t<security>\n\t\t\t<requestFiltering>\n"
			. "\t\t\t\t<fileExtensions allowUnlisted=\"false\" />\n"
			. "\t\t\t</requestFiltering>\n\t\t</security>\n\t</system.webServer>\n</configuration>\n",
	);
}

/**
 * Writes the guard files that are missing. A folder made by an older
 * version holds the one-line Apache 2.4 rule, which is replaced. An
 * .htaccess someone edited by hand is left alone.
 *
 * @param string $dir Export folder.
 */
function opex_guard_export_dir( string $dir ): void {
	$files = array( 'index.php' => "<?php // Silence is golden.\n" ) + opex_export_dir_rules();
	foreach ( $files as $name => $content ) {
		$path    = $dir . '/' . $name;
		$current = is_file( $path ) ? (string) file_get_contents( $path ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file in our own folder.
		if ( null === $current || ( '.htaccess' === $name && "Require all denied\n" === $current ) ) {
			file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem may need credentials, which a background run cannot ask for.
		}
	}
}

/**
 * On deactivation, releases the job locks and clears the plugin's cron
 * events. A network deactivation does this on every site, because each site
 * has its own events and the callbacks are about to go away.
 *
 * @param bool $network_wide True when deactivated for the whole network.
 */
function opex_deactivate( $network_wide = false ): void {
	if ( ! is_multisite() || ! $network_wide ) {
		opex_deactivate_site();
		return;
	}
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $site_id ) {
		switch_to_blog( (int) $site_id );
		try {
			opex_deactivate_site();
		} finally {
			restore_current_blog();
		}
	}
}

/**
 * Releases the job locks and clears the cron events of the current site.
 */
function opex_deactivate_site(): void {
	foreach ( array_keys( OPEX_Jobs::all() ) as $id ) {
		OPEX_Jobs::release_lock( (string) $id );
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
add_action( 'opex_job_failed', array( 'OPEX_Schedules', 'on_job_failed' ) );
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
