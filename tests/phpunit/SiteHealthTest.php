<?php
/**
 * Tests for OPEX_Site_Health.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Debug information section and the cron test.
 */
class SiteHealthTest extends TestCase {

	protected function setUp(): void {
		$this->clean();
	}

	protected function tearDown(): void {
		$this->clean();
		remove_all_filters( 'opex_cron_disabled' );
	}

	private function clean(): void {
		foreach ( array_keys( OPEX_Schedules::all() ) as $id ) {
			OPEX_Schedules::delete( $id );
		}
		delete_option( 'opex_schedules' );
		delete_option( 'opex_destinations' );
		delete_option( 'opex_settings' );
	}

	private function schedule( bool $enabled ): array {
		$rec = OPEX_Schedules::save(
			array(
				'name'      => 'Health check',
				'config'    => array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'batch_size' => 10 ),
				'frequency' => 'daily',
				'enabled'   => $enabled,
				'delivery'  => array( 'webhook' => array( 'enabled' => true, 'url' => 'https://93.184.216.34/in', 'secret' => 'WEBHOOK-SECRET-VALUE' ) ),
			)
		);
		$this->assertIsArray( $rec );
		return $rec;
	}

	public function test_section_keys() {
		$info = OPEX_Site_Health::add_section( array( 'wp-core' => array() ) );
		$this->assertArrayHasKey( 'wp-core', $info, 'Other sections are kept.' );
		$section = $info['open-export'];
		$this->assertSame( 'Open Export', $section['label'] );
		foreach ( array( 'version', 'folder', 'writable', 'protected', 'files', 'size', 'retention', 'schedules', 'schedules_on', 'next_run', 'cron_disabled', 'ext_zip', 'ext_sodium', 'ext_ftp', 'ext_mbstring', 'ext_iconv', 'ext_dom' ) as $key ) {
			$this->assertArrayHasKey( $key, $section['fields'], $key );
			$this->assertArrayHasKey( 'label', $section['fields'][ $key ] );
			$this->assertArrayHasKey( 'value', $section['fields'][ $key ] );
		}
		$this->assertSame( OPEX_VERSION, $section['fields']['version']['value'] );
		$this->assertTrue( $section['fields']['folder']['private'] );
		$this->assertSame( 30, $section['fields']['retention']['value'] );
		$this->assertSame( 0, $section['fields']['schedules']['value'] );
		$this->assertSame( OPEX_Woo::is_active(), isset( $section['fields']['woo_storage'] ) );
	}

	public function test_counts_schedules_and_folder_files() {
		$this->schedule( true );
		$this->schedule( false );
		$dir  = trailingslashit( wp_upload_dir()['basedir'] ) . 'open-export';
		$file = $dir . '/opex-health-test-' . wp_generate_password( 8, false ) . '.csv';
		$had  = is_dir( $dir );
		$base = OPEX_Site_Health::add_section( array() )['open-export']['fields'];
		wp_mkdir_p( $dir );
		file_put_contents( $file, str_repeat( 'x', 100 ) );
		$fields = OPEX_Site_Health::add_section( array() )['open-export']['fields'];
		wp_delete_file( $file );
		if ( ! $had ) {
			@rmdir( $dir );
		}
		$this->assertSame( 2, $fields['schedules']['value'] );
		$this->assertSame( 1, $fields['schedules_on']['value'] );
		$this->assertSame( $base['files']['value'] + 1, $fields['files']['value'] );
		$this->assertSame( $base['size']['debug'] + 100, $fields['size']['debug'] );
		$this->assertNotSame( 'None', $fields['next_run']['value'] );
	}

	public function test_no_secret_in_section() {
		$destination = OPEX_Destinations::save(
			array( 'name' => 'Main', 'type' => 'ftp', 'host' => 'secret-host.example.net', 'username' => 'ftp-user-name', 'auth' => 'password', 'secret' => 'FTP-SECRET-VALUE', 'path' => '/in' )
		);
		$this->assertIsArray( $destination );
		$schedule = $this->schedule( true );

		$dump = wp_json_encode( OPEX_Site_Health::add_section( array() ) ) . wp_json_encode( OPEX_Site_Health::test_cron() );
		foreach ( array( 'WEBHOOK-SECRET-VALUE', 'FTP-SECRET-VALUE', $schedule['trigger_key'], 'secret-host.example.net', 'hooks.example.org', 'ftp-user-name', $destination['secret'] ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $dump, $needle );
		}
	}

	public function test_hooks_registered() {
		OPEX_Site_Health::register();
		$this->assertNotFalse( has_filter( 'debug_information', array( 'OPEX_Site_Health', 'add_section' ) ) );
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array(), 'async' => array() ) );
		$this->assertSame( 'Open Export can run scheduled exports', $tests['direct']['open_export_cron']['label'] );
		$this->assertIsCallable( $tests['direct']['open_export_cron']['test'] );
	}

	public function test_status_good_without_schedules_even_when_cron_is_off() {
		add_filter( 'opex_cron_disabled', '__return_true' );
		$this->assertSame( 'good', OPEX_Site_Health::test_cron()['status'] );
	}

	public function test_status_follows_cron_setting() {
		$this->schedule( true );
		$this->assertSame( 'good', OPEX_Site_Health::test_cron()['status'] );

		add_filter( 'opex_cron_disabled', '__return_true' );
		$result = OPEX_Site_Health::test_cron();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'trigger URL', $result['description'] );
		$this->assertSame( 'open_export_cron', $result['test'] );

		remove_all_filters( 'opex_cron_disabled' );
		add_filter( 'opex_cron_disabled', '__return_false' );
		$this->assertSame( 'good', OPEX_Site_Health::test_cron()['status'] );
	}

	public function test_disabled_schedule_does_not_trigger_advice() {
		$this->schedule( false );
		add_filter( 'opex_cron_disabled', '__return_true' );
		$this->assertSame( 'good', OPEX_Site_Health::test_cron()['status'] );
	}

	/** The folder field says which rules exist and never claims nginx is covered. */
	public function test_protection_names_the_rules_and_the_nginx_limit() {
		opex_export_dir();
		$field = OPEX_Site_Health::add_section( array() )['open-export']['fields']['protected'];
		$this->assertSame( '.htaccess,web.config', $field['debug'] );
		$this->assertStringContainsString( 'nginx', $field['value'] );
		$this->assertNotSame( __( 'Yes', 'open-export' ), $field['value'] );
	}

	/** New folders get both rule files, an old one-line rule is upgraded, and a hand-edited one is kept. */
	public function test_guard_files_are_written_and_upgraded() {
		$dir = trailingslashit( get_temp_dir() ) . 'opex-guard-' . wp_generate_password( 8, false );
		wp_mkdir_p( $dir );
		$rules = opex_export_dir_rules();
		try {
			opex_guard_export_dir( $dir );
			$this->assertSame( $rules['.htaccess'], file_get_contents( $dir . '/.htaccess' ) );
			$this->assertSame( $rules['web.config'], file_get_contents( $dir . '/web.config' ) );
			$this->assertStringContainsString( 'Require all denied', $rules['.htaccess'] );
			$this->assertStringContainsString( 'Deny from all', $rules['.htaccess'] );
			$this->assertNotFalse( simplexml_load_string( $rules['web.config'] ), 'web.config is well-formed XML' );

			file_put_contents( $dir . '/.htaccess', "Require all denied\n" );
			wp_delete_file( $dir . '/web.config' );
			opex_guard_export_dir( $dir );
			$this->assertSame( $rules['.htaccess'], file_get_contents( $dir . '/.htaccess' ), 'the 2.0 rule replaces the old one' );
			$this->assertFileExists( $dir . '/web.config' );

			file_put_contents( $dir . '/.htaccess', "# Edited by the host.\nRequire all denied\n" );
			opex_guard_export_dir( $dir );
			$this->assertSame( "# Edited by the host.\nRequire all denied\n", file_get_contents( $dir . '/.htaccess' ) );
		} finally {
			foreach ( array( 'index.php', '.htaccess', 'web.config' ) as $name ) {
				if ( is_file( $dir . '/' . $name ) ) {
					wp_delete_file( $dir . '/' . $name );
				}
			}
			@rmdir( $dir );
		}
	}

	/** A background export with no batch for over an hour is reported. */
	public function test_stuck_background_job_is_reported() {
		$this->assertSame( 'good', OPEX_Site_Health::test_stuck_jobs()['status'] );
		$config = OPEX_Exporter::sanitize_config(
			array(
				'source'     => 'posts:post',
				'fields'     => array( 'core:ID' ),
				'batch_size' => 10,
			)
		);
		$job = OPEX_Exporter::create( $config, 'Stuck test', array( 'mode' => 'background' ) );
		$this->assertSame( 'running', $job['status'] );
		try {
			OPEX_Jobs::update(
				$job['id'],
				static function ( array $stored ): array {
					$stored['heartbeat'] = time() - 1800;
					return $stored;
				}
			);
			$this->assertSame( 'good', OPEX_Site_Health::test_stuck_jobs()['status'], 'half an hour is not stuck yet' );

			OPEX_Jobs::update(
				$job['id'],
				static function ( array $stored ): array {
					$stored['heartbeat'] = time() - 2 * HOUR_IN_SECONDS;
					return $stored;
				}
			);
			$result = OPEX_Site_Health::test_stuck_jobs();
			$this->assertSame( 'recommended', $result['status'] );
			$this->assertStringContainsString( '1 background export', $result['description'] );
			$this->assertSame( 'open_export_stuck', $result['test'] );
		} finally {
			OPEX_Jobs::delete( $job['id'] );
			wp_clear_scheduled_hook( 'opex_run_job', array( $job['id'] ) );
		}
	}
}
