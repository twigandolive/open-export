<?php
/**
 * Uninstall: remove the job registry, saved templates, transients, and every
 * generated export file. On multisite the cleanup runs for each site, since
 * jobs and templates are stored per site. Leaves nothing behind.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Wipe all plugin data for the current site.
 */
function opex_uninstall_site(): void {
	global $wpdb;

	delete_option( 'opex_jobs' );
	delete_option( 'opex_templates' );
	delete_transient( 'opex_table_status' );
	delete_transient( 'opex_acf_keys' );

	// Catch the remaining hashed cache keys (opex_keys_*, opex_lock_*).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup of our own prefixed transients.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_opex\\_%' OR option_name LIKE '\\_transient\\_timeout\\_opex\\_%'" );

	$uploads = wp_upload_dir();
	$dir     = trailingslashit( $uploads['basedir'] ) . 'open-export';

	if ( ! is_dir( $dir ) ) {
		return;
	}

	foreach ( (array) glob( $dir . '/*' ) as $file ) {
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}
	foreach ( array( $dir . '/.htaccess', $dir . '/index.php' ) as $guard ) {
		if ( is_file( $guard ) ) {
			wp_delete_file( $guard );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing our own export directory on uninstall; fails silently only if a stray file remains.
	@rmdir( $dir );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $opex_site_id ) {
		switch_to_blog( (int) $opex_site_id );
		opex_uninstall_site();
		restore_current_blog();
	}
} else {
	opex_uninstall_site();
}
