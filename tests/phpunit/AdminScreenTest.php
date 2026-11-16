<?php
/**
 * Tests for the admin screen loader (OPEX_Admin::enqueue).
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * The page loads the screen in assets/app/ in dependency order, the old
 * one-page screen is gone, and nothing loads on other admin pages.
 */
class AdminScreenTest extends TestCase {

	private const HOOK = 'tools_page_open-export';

	protected function setUp(): void {
		$this->reset_assets();
	}

	protected function tearDown(): void {
		$this->reset_assets();
	}

	/**
	 * Fresh script and style registries, so each test sees only its own enqueues.
	 */
	private function reset_assets(): void {
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
	}

	/**
	 * Every opex handle in the queue or the resolved load order.
	 *
	 * @param string[] $handles Handles.
	 * @return string[]
	 */
	private function ours( array $handles ): array {
		return array_values(
			array_filter(
				$handles,
				function ( $handle ) {
					return 0 === strpos( $handle, 'opex-' );
				}
			)
		);
	}

	public function test_old_screen_is_gone() {
		OPEX_Admin::enqueue( self::HOOK );

		$this->assertFalse( wp_script_is( 'opex-admin', 'registered' ) );
		$this->assertFalse( wp_style_is( 'opex-admin', 'registered' ) );
		$this->assertFalse( file_exists( OPEX_DIR . 'assets/admin.js' ) );
		$this->assertFalse( file_exists( OPEX_DIR . 'assets/admin.css' ) );
	}

	public function test_a_leftover_preview_parameter_changes_nothing() {
		$_GET['opex_ui'] = 'old';
		OPEX_Admin::enqueue( self::HOOK );
		unset( $_GET['opex_ui'] );

		$this->assertTrue( wp_script_is( 'opex-app-main', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'opex-admin', 'registered' ) );
	}

	public function test_screen_loads_in_dependency_order() {
		OPEX_Admin::enqueue( self::HOOK );

		$this->assertTrue( wp_style_is( 'opex-app', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'opex-app-main', 'enqueued' ) );

		$scripts = wp_scripts();
		$scripts->all_deps( $scripts->queue );
		$order   = $this->ours( $scripts->to_do );
		$handles = OPEX_Admin::script_handles();

		// Every file loads, and each one after the opex files it depends on.
		$this->assertEqualsCanonicalizing( $handles, $order );
		foreach ( $order as $position => $handle ) {
			$this->assertStringContainsString( 'assets/app/', $scripts->registered[ $handle ]->src, $handle );
			$this->assertSame( 1, (int) $scripts->get_data( $handle, 'group' ), $handle . ' loads in the footer' );
			foreach ( $this->ours( $scripts->registered[ $handle ]->deps ) as $dep ) {
				$this->assertLessThan( $position, array_search( $dep, $order, true ), $handle . ' needs ' . $dep . ' first' );
			}
		}
		$this->assertSame( 'opex-app-core', $order[0] );
		$this->assertSame( 'opex-app-main', end( $order ) );
		$this->assertContains( 'wp-api-fetch', $scripts->to_do );
		$this->assertContains( 'wp-i18n', $scripts->to_do );
		$this->assertContains( 'wp-date', $scripts->to_do );

		// OPEX_BOOT rides on the first file and keeps its keys.
		$boot = (string) $scripts->get_data( 'opex-app-core', 'data' );
		foreach ( array( 'restBase', 'restNonce', 'restRoot', 'adminUrl' ) as $key ) {
			$this->assertStringContainsString( '"' . $key . '"', $boot );
		}
		$this->assertStringContainsString( 'tools.php?page=open-export', $boot );
	}

	public function test_export_tab_and_builder_load_before_main_with_translations() {
		OPEX_Admin::enqueue( self::HOOK );

		$scripts = wp_scripts();
		$scripts->all_deps( $scripts->queue );
		$order = $this->ours( $scripts->to_do );

		$stage2 = array(
			'opex-app-builder-parts', 'opex-app-builder', 'opex-app-builder-source', 'opex-app-builder-pairs',
			'opex-app-builder-transforms', 'opex-app-builder-custom', 'opex-app-builder-chosen', 'opex-app-builder-columns',
			'opex-app-builder-dates', 'opex-app-builder-filters', 'opex-app-builder-format', 'opex-app-builder-preview',
			'opex-app-delivery', 'opex-app-export-recipe', 'opex-app-export-start', 'opex-app-export-run-view',
			'opex-app-export-run', 'opex-app-tab-export',
		);
		$main = array_search( 'opex-app-main', $order, true );
		foreach ( $stage2 as $handle ) {
			$this->assertContains( $handle, $order, $handle );
			$this->assertLessThan( $main, array_search( $handle, $order, true ), $handle . ' loads before main' );
		}
		// The tab registers itself, so main must wait for it.
		$this->assertContains( 'opex-app-tab-export', $scripts->registered['opex-app-main']->deps );

		// Every file of the new screen gets its translations.
		foreach ( OPEX_Admin::script_handles() as $handle ) {
			$this->assertSame( 'open-export', $scripts->registered[ $handle ]->textdomain, $handle );
			$this->assertFileExists( OPEX_DIR . 'assets/app/' . basename( (string) wp_parse_url( $scripts->registered[ $handle ]->src, PHP_URL_PATH ) ), $handle );
		}
	}

	public function test_schedules_tab_loads_after_the_builder_sections_and_before_main() {
		OPEX_Admin::enqueue( self::HOOK );

		$scripts = wp_scripts();
		$scripts->all_deps( $scripts->queue );
		$order = $this->ours( $scripts->to_do );
		$at    = function ( $handle ) use ( $order ) {
			return array_search( $handle, $order, true );
		};

		$stage3 = array( 'opex-app-schedule-parts', 'opex-app-schedule-card', 'opex-app-schedule-timing', 'opex-app-schedule-trigger', 'opex-app-schedule-form', 'opex-app-schedule-actions', 'opex-app-tab-schedules' );
		foreach ( $stage3 as $handle ) {
			$this->assertContains( $handle, $order, $handle );
			$this->assertLessThan( $at( 'opex-app-main' ), $at( $handle ), $handle . ' loads before main' );
		}
		// The form mounts the builder, so every builder section must have registered first.
		foreach ( array( 'opex-app-builder-source', 'opex-app-builder-columns', 'opex-app-builder-filters', 'opex-app-builder-format', 'opex-app-builder-preview', 'opex-app-delivery' ) as $section ) {
			$this->assertLessThan( $at( 'opex-app-schedule-form' ), $at( $section ), $section . ' loads before the schedule form' );
		}
		$this->assertContains( 'opex-app-tab-schedules', $scripts->registered['opex-app-main']->deps );
	}

	public function test_styles_load_in_order() {
		OPEX_Admin::enqueue( self::HOOK );

		$this->assertSame( array( 'opex-app', 'opex-app-builder' ), OPEX_Admin::style_handles() );
		$styles = wp_styles();
		foreach ( OPEX_Admin::style_handles() as $handle ) {
			$this->assertTrue( wp_style_is( $handle, 'enqueued' ), $handle );
			$this->assertStringContainsString( 'assets/app/', $styles->registered[ $handle ]->src, $handle );
		}
		$styles->all_deps( $styles->queue );
		$order = $this->ours( $styles->to_do );
		$this->assertLessThan( array_search( 'opex-app-builder', $order, true ), array_search( 'opex-app', $order, true ), 'builder.css loads after app.css' );
		// Source tiles use the Dashicons font.
		$this->assertContains( 'dashicons', $styles->to_do );
	}

	public function test_nothing_loads_on_other_admin_pages() {
		foreach ( array( 'index.php', 'tools_page_something-else', 'tools_page_open-export-other' ) as $hook ) {
			$this->reset_assets();
			OPEX_Admin::enqueue( $hook );

			$this->assertSame( array(), $this->ours( wp_scripts()->queue ), $hook );
			$this->assertSame( array(), $this->ours( wp_styles()->queue ), $hook );
			$this->assertSame( array(), $this->ours( array_keys( wp_scripts()->registered ) ), $hook );
		}
	}
}
