<?php
/**
 * Uninstall: remove the job registry, transients, and every generated
 * export file. Leaves nothing behind.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'opex_jobs' );
delete_option( 'opex_templates' );
delete_transient( 'opex_table_status' );
delete_transient( 'opex_acf_keys' );

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup of our own prefixed transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_opex\\_%' OR option_name LIKE '\\_transient\\_timeout\\_opex\\_%'" );

$uploads = wp_upload_dir();
$dir     = trailingslashit( $uploads['basedir'] ) . 'open-export';

if ( is_dir( $dir ) ) {
	foreach ( (array) glob( $dir . '/*' ) as $file ) {
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing our own empty export directory on uninstall.
	@rmdir( $dir );
}
