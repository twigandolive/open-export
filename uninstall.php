<?php
/**
 * Uninstall: remove everything Open Export stored. On multisite the cleanup
 * runs for each site, since options, cron events and export files are per site.
 * A site whose settings say "keep data" keeps its options and files, and loses
 * only its cron events.
 *
 * This file cannot load the plugin's classes, so it uses option names and
 * WordPress functions directly. The names below are shipped: keep them in step
 * with the plugin.
 *
 * @package Open_Export
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Whether the current site asked to keep its data when the plugin is
 * deleted (setting keep_data_on_uninstall in the opex_settings option).
 *
 * @return bool True when this site keeps its data on uninstall.
 */
function opex_uninstall_keeps_data(): bool {
	$settings = get_option( 'opex_settings', array() );
	if ( ! is_array( $settings ) || ! isset( $settings['keep_data_on_uninstall'] ) ) {
		return false;
	}
	return (bool) filter_var( $settings['keep_data_on_uninstall'], FILTER_VALIDATE_BOOLEAN );
}

/**
 * Remove every cron event whose hook starts with opex_, whatever its arguments.
 * This always runs, even when data is kept, because the callbacks are gone.
 */
function opex_uninstall_cron(): void {
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
}

/**
 * Wipe all plugin data for the current site, unless the site asked to keep it.
 * Then only the cron events go.
 *
 * @param string $dir Export folder. Empty means the folder under uploads. Tests pass their own.
 */
function opex_uninstall_site( string $dir = '' ): void {
	global $wpdb;

	opex_uninstall_cron();
	if ( opex_uninstall_keeps_data() ) {
		return;
	}

	foreach ( array( 'opex_jobs', 'opex_templates', 'opex_schedules', 'opex_destinations', 'opex_settings' ) as $option ) {
		delete_option( $option );
	}

	// Every transient with the opex_ prefix (opex_table_status, opex_keys_*, and so on).
	$like         = $wpdb->esc_like( '_transient_opex_' ) . '%';
	$like_timeout = $wpdb->esc_like( '_transient_timeout_opex_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup of our own prefixed transients.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $like_timeout ) );

	// Lock rows. They are plain options, written straight to the table so that taking one is atomic.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup of our own prefixed lock rows.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'opex_lock_' ) . '%' ) );

	if ( '' === $dir ) {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'open-export';
	}
	if ( ! is_dir( $dir ) ) {
		return;
	}

	// Every file: exports and their work files (*.opex, and the 2.0.0 names without it) and the guard files.
	foreach ( (array) scandir( $dir ) as $name ) {
		if ( '.' !== $name && '..' !== $name && is_file( $dir . '/' . $name ) ) {
			wp_delete_file( $dir . '/' . $name );
		}
	}
	// The folder stays if something else was put in it. That is fine, so the warning is silenced.
	@rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- removing our own export folder.
}

// Tests load this file for the function only.
if ( defined( 'OPEX_UNINSTALL_FUNCTIONS_ONLY' ) ) {
	return;
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $opex_site_id ) {
		switch_to_blog( (int) $opex_site_id );
		opex_uninstall_site();
		restore_current_blog();
	}
} else {
	opex_uninstall_site();
}
