<?php
/**
 * Tests for OPEX_Settings.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Settings defaults and clamping.
 */
class SettingsTest extends TestCase {

	protected function setUp(): void {
		delete_option( 'opex_settings' );
	}

	protected function tearDown(): void {
		delete_option( 'opex_settings' );
	}

	public function test_defaults() {
		$this->assertSame( 30, OPEX_Settings::get( 'retention_days' ) );
		$this->assertSame( 7, OPEX_Settings::get( 'link_days' ) );
		$this->assertFalse( OPEX_Settings::get( 'background' ) );
		$this->assertNull( OPEX_Settings::get( 'nope' ) );
	}

	public function test_save_clamps() {
		$out = OPEX_Settings::save( array( 'retention_days' => 9999, 'link_days' => 0, 'background' => '1', 'junk' => 1 ) );
		$this->assertSame( array( 'retention_days' => 365, 'link_days' => 1, 'background' => true, 'keep_data_on_uninstall' => false ), $out );
		$this->assertSame( 365, OPEX_Settings::get( 'retention_days' ) );
		$out = OPEX_Settings::save( array( 'retention_days' => -5, 'link_days' => 99, 'background' => 'false' ) );
		$this->assertSame( 1, $out['retention_days'] );
		$this->assertSame( 30, $out['link_days'] );
		$this->assertFalse( $out['background'] );
	}

	public function test_garbage_falls_back_and_partial_save_keeps_others() {
		OPEX_Settings::save( array( 'link_days' => 12 ) );
		OPEX_Settings::save( array( 'retention_days' => 'abc' ) );
		$this->assertSame( 12, OPEX_Settings::get( 'link_days' ) );
		$this->assertSame( 30, OPEX_Settings::get( 'retention_days' ) );
		update_option( 'opex_settings', 'corrupt' );
		$this->assertSame( 7, OPEX_Settings::get( 'link_days' ) );
	}

	public function test_option_is_not_autoloaded() {
		OPEX_Settings::save( array( 'link_days' => 3 ) );
		global $wpdb;
		$this->assertContains( $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'opex_settings' ) ), array( 'no', 'off' ) );
	}
}
