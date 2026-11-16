<?php
/**
 * Uninstall: remove everything Open Export stored. On multisite the cleanup
 * runs for each site, since options, cron events and export files are per site.
 *
 * This file cannot load the plugin's classes, so it uses option names and
 * WordPress functions directly. The names below are shipped: keep them in step
 * with the plugin.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Wipe all plugin data for the current site.
 *
 * @param string $dir Export folder. Empty means the folder under uploads. Tests pass their own.
 */
function opex_uninstall_site( string $dir = '' ): void {
	global $wpdb;

	foreach ( array( 'opex_jobs', 'opex_templates', 'opex_schedules', 'opex_destinations', 'opex_settings' ) as $option ) {
		delete_option( $option );
	}

	// Every transient with our prefix (opex_table_status, opex_keys_*, and so on).
	$like = $wpdb->esc_like( '_transient_opex_' ) . '%';
	$like_timeout = $wpdb->esc_like( '_transient_timeout_opex_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup of our own prefixed transients.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $like_timeout ) );

	// Lock rows. They are plain options, written straight to the table so that taking one is atomic.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup of our own prefixed lock rows.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'opex_lock_' ) . '%' ) );

	// Every cron event whose hook starts with opex_, whatever its arguments.
	foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
		foreach ( (array) $hooks as $hook => $events ) {
			if ( 0 !== strpos( (string) $hook, 'opex_' ) ) {
				continue;
			}
			foreach ( (array) $events as $event ) {
				wp_unschedule_event( (int) $timestamp, (string) $hook, (array) ( $event['args'] ?? array() ) );
			}
		}
	}

	if ( '' === $dir ) {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'open-export';
	}
	if ( ! is_dir( $dir ) ) {
		return;
	}

	// Export files, sidecars (*.rows, *.rows.n, *.zip.tmp) and the guard files.
	foreach ( (array) scandir( $dir ) as $name ) {
		if ( '.' !== $name && '..' !== $name && is_file( $dir . '/' . $name ) ) {
			wp_delete_file( $dir . '/' . $name );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing our own export directory on uninstall; fails silently only if a stray folder remains.
	@rmdir( $dir );
}

// Tests load this file for the function only.
if ( defined( 'OPEX_UNINSTALL_FUNCTIONS_ONLY' ) ) {
	return;
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
