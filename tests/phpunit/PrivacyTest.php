<?php
/**
 * Tests for OPEX_Privacy.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Suggested privacy policy text.
 */
class PrivacyTest extends TestCase {

	protected function setUp(): void {
		delete_option( 'opex_settings' );
	}

	protected function tearDown(): void {
		delete_option( 'opex_settings' );
		unset( $GLOBALS['current_screen'] );
	}

	/** Stored policy entries, read from the private WordPress list. */
	private function entries(): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		$prop = new ReflectionProperty( 'WP_Privacy_Policy_Content', 'policy_content' );
		return $prop->getValue();
	}

	/** Fire only our admin_init callback, not every plugin's. */
	private function fire_admin_init(): void {
		global $wp_filter;
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		set_current_screen( 'dashboard' );
		$saved = $wp_filter['admin_init'] ?? null;
		unset( $wp_filter['admin_init'] );
		OPEX_Privacy::register();
		do_action( 'admin_init' );
		unset( $wp_filter['admin_init'] );
		if ( null !== $saved ) {
			$wp_filter['admin_init'] = $saved;
		}
	}

	public function test_register_hooks_admin_init() {
		OPEX_Privacy::register();
		$this->assertNotFalse( has_action( 'admin_init', array( 'OPEX_Privacy', 'add_policy_content' ) ) );
	}

	public function test_policy_text_is_registered_with_retention() {
		OPEX_Settings::save( array( 'retention_days' => 17 ) );
		$this->fire_admin_init();
		$found = null;
		foreach ( $this->entries() as $entry ) {
			if ( 'Open Export' === $entry['plugin_name'] && false !== strpos( $entry['policy_text'], '17 days' ) ) {
				$found = $entry['policy_text'];
			}
		}
		$this->assertNotNull( $found, 'Policy text with the retention number is registered.' );
		$this->assertStringContainsString( 'personal data', $found );
		$this->assertStringContainsString( 'protected folder', $found );
		$this->assertStringContainsString( 'does not send usage data', $found );
		$this->assertStringContainsString( 'FTP or SFTP', $found );
	}

	public function test_text_follows_the_setting_and_singular() {
		OPEX_Settings::save( array( 'retention_days' => 1 ) );
		$this->assertStringContainsString( 'after 1 day.', OPEX_Privacy::policy_text() );
		OPEX_Settings::save( array( 'retention_days' => 90 ) );
		$this->assertStringContainsString( 'after 90 days.', OPEX_Privacy::policy_text() );
	}

	public function test_no_exporter_or_eraser_registered() {
		$this->assertSame( array(), array_filter( array_keys( apply_filters( 'wp_privacy_personal_data_exporters', array() ) ), static function ( $k ) {
			return false !== stripos( (string) $k, 'open-export' );
		} ) );
		$this->assertSame( array(), array_filter( array_keys( apply_filters( 'wp_privacy_personal_data_erasers', array() ) ), static function ( $k ) {
			return false !== stripos( (string) $k, 'open-export' );
		} ) );
	}
}
