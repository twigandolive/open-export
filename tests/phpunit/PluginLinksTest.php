<?php
/**
 * Tests for the links the plugin adds to the Plugins screen.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Plugins-screen links.
 */
class PluginLinksTest extends TestCase {

	/**
	 * The action link opens the screen under Tools, where the page is registered.
	 */
	public function test_action_link_points_at_the_tools_screen() {
		$links = opex_plugin_action_links( array( 'deactivate' => '<a href="#">Deactivate</a>' ) );

		$this->assertCount( 2, $links );
		$first = (string) reset( $links );
		$this->assertStringContainsString( 'tools.php?page=open-export', $first );
		$this->assertStringNotContainsString( 'admin.php?page=', $first );
	}
}
