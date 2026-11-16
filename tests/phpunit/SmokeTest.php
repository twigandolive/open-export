<?php
/**
 * Smoke test: the harness loads WordPress with the plugin active.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Basic wiring checks.
 */
class SmokeTest extends TestCase {

	/**
	 * The plugin is loaded.
	 */
	public function test_plugin_is_loaded() {
		$this->assertTrue( defined( 'OPEX_VERSION' ) );
		$this->assertContains( 'open-export/open-export.php', (array) get_option( 'active_plugins' ) );
	}

	/**
	 * The sources route answers for the admin.
	 */
	public function test_sources_route_returns_200_for_admin() {
		wp_set_current_user( 1 );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/open-export/v1/sources' ) );
		$this->assertSame( 200, $response->get_status() );
	}
}
