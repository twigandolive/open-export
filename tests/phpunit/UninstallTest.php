<?php
/**
 * Tests for uninstall.php.
 *
 * The file is loaded for its function only. The test points the function at a
 * folder it created, so real export files are never touched.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Uninstall removes every stored item and nothing else.
 */
class UninstallTest extends TestCase {

	private const OPTIONS = array( 'opex_jobs', 'opex_templates', 'opex_schedules', 'opex_destinations', 'opex_settings' );

	private array $saved = array();
	private string $dir  = '';

	protected function setUp(): void {
		foreach ( self::OPTIONS as $name ) {
			$this->saved[ $name ] = get_option( $name, null );
		}
		$this->dir = trailingslashit( get_temp_dir() ) . 'opex-uninstall-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->dir );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'open-export/open-export.php' );
		}
		if ( ! defined( 'OPEX_UNINSTALL_FUNCTIONS_ONLY' ) ) {
			define( 'OPEX_UNINSTALL_FUNCTIONS_ONLY', true );
		}
		require_once dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	protected function tearDown(): void {
		foreach ( $this->saved as $name => $value ) {
			if ( null === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value, false );
			}
		}
		foreach ( (array) glob( $this->dir . '/{,.}*', GLOB_BRACE ) as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		if ( is_dir( $this->dir ) ) {
			@rmdir( $this->dir );
		}
		delete_option( 'opex_unrelated_option' );
		delete_option( 'other_plugin_opex_jobs' );
		delete_transient( 'other_transient' );
		delete_option( 'other_lock_j2' );
		OPEX_Lock::release( 'j2' );
		foreach ( array( 'opex_run_schedule' => array( 's1' ), 'opex_run_job' => array( 'j1' ), 'opex_deliver' => array( 'j1' ), 'opex_maintenance' => array(), 'other_plugin_hook' => array() ) as $hook => $args ) {
			wp_clear_scheduled_hook( $hook, $args );
		}
		wp_clear_scheduled_hook( 'opex_run_job', array( 'j2' ) );
	}

	public function test_removes_everything_and_keeps_unrelated() {
		foreach ( self::OPTIONS as $name ) {
			update_option( $name, array( 'x' => 1 ), false );
		}
		update_option( 'opex_unrelated_option', 'keep me' );
		update_option( 'other_plugin_opex_jobs', 'keep me too' );
		set_transient( 'opex_table_status', array( 1 ), 300 );
		set_transient( 'opex_keys_abc', array( 1 ), 300 );
		set_transient( 'opex_lock_j1', time(), 300 );
		set_transient( 'other_transient', 'keep', 300 );
		OPEX_Lock::acquire( 'j2', 300 );
		add_option( 'other_lock_j2', 'keep', '', false );

		$in_future = time() + 3600;
		wp_schedule_single_event( $in_future, 'opex_run_schedule', array( 's1' ) );
		wp_schedule_single_event( $in_future, 'opex_run_job', array( 'j1' ) );
		wp_schedule_single_event( $in_future + 5, 'opex_run_job', array( 'j2' ) );
		wp_schedule_single_event( $in_future, 'opex_deliver', array( 'j1' ) );
		wp_schedule_event( $in_future, 'hourly', 'opex_maintenance' );
		wp_schedule_single_event( $in_future, 'other_plugin_hook' );

		$files = array( 'export.csv', 'export.xlsx', 'export.xlsx.rows', 'export.xlsx.rows.n', 'export.zip.tmp', '.htaccess', 'index.php' );
		foreach ( $files as $file ) {
			file_put_contents( $this->dir . '/' . $file, 'data' );
		}

		opex_uninstall_site( $this->dir );

		wp_cache_flush();
		foreach ( self::OPTIONS as $name ) {
			$this->assertFalse( get_option( $name ), $name );
		}
		$this->assertFalse( get_transient( 'opex_table_status' ) );
		$this->assertFalse( get_transient( 'opex_keys_abc' ) );
		$this->assertFalse( get_transient( 'opex_lock_j1' ) );
		global $wpdb;
		$this->assertSame( '0', (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%opex\\_%'" ) );

		$this->assertFalse( wp_next_scheduled( 'opex_run_schedule', array( 's1' ) ) );
		$this->assertFalse( wp_next_scheduled( 'opex_run_job', array( 'j1' ) ) );
		$this->assertFalse( wp_next_scheduled( 'opex_run_job', array( 'j2' ) ) );
		$this->assertFalse( wp_next_scheduled( 'opex_deliver', array( 'j1' ) ) );
		$this->assertFalse( wp_next_scheduled( 'opex_maintenance' ) );

		$this->assertDirectoryDoesNotExist( $this->dir );

		$this->assertSame( 'keep me', get_option( 'opex_unrelated_option' ) );
		$this->assertSame( 'keep me too', get_option( 'other_plugin_opex_jobs' ) );
		$this->assertSame( 'keep', get_transient( 'other_transient' ) );
		$this->assertFalse( OPEX_Lock::is_held( 'j2' ) );
		$this->assertSame( '0', (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'opex\\_lock\\_%'" ) );
		$this->assertSame( 'keep', get_option( 'other_lock_j2' ) );
		$this->assertNotFalse( wp_next_scheduled( 'other_plugin_hook' ) );
	}

	public function test_missing_folder_is_fine() {
		opex_uninstall_site( $this->dir . '-absent' );
		$this->assertDirectoryExists( $this->dir );
	}
}
