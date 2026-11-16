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
				'delivery'  => array( 'webhook' => array( 'enabled' => true, 'url' => 'https://hooks.example.org/in', 'secret' => 'WEBHOOK-SECRET-VALUE' ) ),
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
}
