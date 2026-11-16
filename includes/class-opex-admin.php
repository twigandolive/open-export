<?php
/**
 * Admin screen: a single page that boots the JS app. All data flows
 * through the REST API, so this stays a thin shell.
 *
 * Two screens exist while the new one is built. The current screen
 * (assets/admin.js) loads by default. The new screen (assets/app/) loads
 * only when the page URL has opex_ui=next.
 */

defined( 'ABSPATH' ) || exit;

class OPEX_Admin {

	private const SLUG = 'open-export';

	/**
	 * Scripts of the new screen in load order: handle => array( file, deps ).
	 * Each file adds to window.opexApp, so each one depends on the files it uses.
	 */
	private const NEXT_SCRIPTS = array(
		'opex-app-core'              => array( 'core.js', array( 'wp-i18n', 'wp-date' ) ),
		'opex-app-store'             => array( 'store.js', array( 'opex-app-core' ) ),
		'opex-app-api'               => array( 'api.js', array( 'opex-app-store', 'wp-api-fetch' ) ),
		'opex-app-icons'             => array( 'icons.js', array( 'opex-app-core' ) ),
		'opex-app-ui'                => array( 'ui.js', array( 'opex-app-icons' ) ),
		'opex-app-ui-widgets'        => array( 'ui-widgets.js', array( 'opex-app-ui' ) ),
		'opex-app-jobs'              => array( 'jobs.js', array( 'opex-app-api' ) ),
		'opex-app-history-view'      => array( 'history-view.js', array( 'opex-app-ui', 'opex-app-store' ) ),
		'opex-app-history-actions'   => array( 'history-actions.js', array( 'opex-app-jobs', 'opex-app-ui-widgets' ) ),
		'opex-app-tab-history'       => array( 'tab-history.js', array( 'opex-app-history-view', 'opex-app-history-actions' ) ),
		'opex-app-destination-parts' => array( 'destination-parts.js', array( 'opex-app-ui' ) ),
		'opex-app-destination-form'  => array( 'destination-form.js', array( 'opex-app-destination-parts', 'opex-app-api', 'opex-app-ui-widgets' ) ),
		'opex-app-tab-destinations'  => array( 'tab-destinations.js', array( 'opex-app-destination-form' ) ),
		'opex-app-tab-settings'      => array( 'tab-settings.js', array( 'opex-app-ui', 'opex-app-api' ) ),
		'opex-app-builder-parts'     => array( 'builder-parts.js', array( 'opex-app-ui' ) ),
		'opex-app-builder'           => array( 'builder.js', array( 'opex-app-builder-parts', 'opex-app-api', 'opex-app-store' ) ),
		'opex-app-builder-source'    => array( 'builder-source.js', array( 'opex-app-builder' ) ),
		'opex-app-builder-pairs'     => array( 'builder-pairs.js', array( 'opex-app-builder-parts' ) ),
		'opex-app-builder-transforms' => array( 'builder-transforms.js', array( 'opex-app-builder-pairs' ) ),
		'opex-app-builder-custom'    => array( 'builder-custom.js', array( 'opex-app-builder-parts' ) ),
		'opex-app-builder-chosen'    => array( 'builder-chosen.js', array( 'opex-app-builder-transforms', 'opex-app-builder-custom' ) ),
		'opex-app-builder-columns'   => array( 'builder-columns.js', array( 'opex-app-builder', 'opex-app-builder-chosen' ) ),
		'opex-app-builder-dates'     => array( 'builder-dates.js', array( 'opex-app-builder-parts' ) ),
		'opex-app-builder-filters'   => array( 'builder-filters.js', array( 'opex-app-builder', 'opex-app-builder-dates' ) ),
		'opex-app-builder-format'    => array( 'builder-format.js', array( 'opex-app-builder' ) ),
		'opex-app-builder-preview'   => array( 'builder-preview.js', array( 'opex-app-builder', 'opex-app-ui-widgets' ) ),
		'opex-app-delivery'          => array( 'delivery.js', array( 'opex-app-ui', 'opex-app-api' ) ),
		'opex-app-export-recipe'     => array( 'export-recipe.js', array( 'opex-app-ui', 'opex-app-api' ) ),
		'opex-app-export-start'      => array( 'export-start.js', array( 'opex-app-ui-widgets', 'opex-app-api' ) ),
		'opex-app-export-run-view'   => array( 'export-run-view.js', array( 'opex-app-history-view' ) ),
		'opex-app-export-run'        => array( 'export-run.js', array( 'opex-app-jobs', 'opex-app-delivery', 'opex-app-export-run-view' ) ),
		'opex-app-tab-export'        => array(
			'tab-export.js',
			array( 'opex-app-builder-source', 'opex-app-builder-columns', 'opex-app-builder-filters', 'opex-app-builder-format', 'opex-app-builder-preview', 'opex-app-export-recipe', 'opex-app-export-start', 'opex-app-export-run' ),
		),
		'opex-app-schedule-parts'    => array( 'schedule-parts.js', array( 'opex-app-history-view', 'opex-app-jobs' ) ),
		'opex-app-schedule-card'     => array( 'schedule-card.js', array( 'opex-app-schedule-parts' ) ),
		'opex-app-schedule-timing'   => array( 'schedule-timing.js', array( 'opex-app-schedule-parts', 'opex-app-ui-widgets', 'opex-app-api' ) ),
		'opex-app-schedule-trigger'  => array( 'schedule-trigger.js', array( 'opex-app-ui-widgets', 'opex-app-api' ) ),
		'opex-app-schedule-form'     => array(
			'schedule-form.js',
			array( 'opex-app-schedule-timing', 'opex-app-schedule-trigger', 'opex-app-builder-source', 'opex-app-builder-columns', 'opex-app-builder-filters', 'opex-app-builder-format', 'opex-app-builder-preview', 'opex-app-delivery' ),
		),
		'opex-app-schedule-actions'  => array( 'schedule-actions.js', array( 'opex-app-schedule-parts', 'opex-app-api' ) ),
		'opex-app-tab-schedules'     => array( 'tab-schedules.js', array( 'opex-app-schedule-card', 'opex-app-schedule-form', 'opex-app-schedule-actions' ) ),
		'opex-app-main'              => array( 'main.js', array( 'opex-app-tab-export', 'opex-app-tab-history', 'opex-app-tab-schedules', 'opex-app-tab-destinations', 'opex-app-tab-settings' ) ),
	);

	/**
	 * Styles of the new screen in load order: handle => array( file, deps ).
	 */
	private const NEXT_STYLES = array(
		'opex-app'         => array( 'app.css', array( 'dashicons' ) ),
		'opex-app-builder' => array( 'builder.css', array( 'opex-app' ) ),
	);

	public static function register_menu(): void {
		add_management_page(
			__( 'Open Export', 'open-export' ),
			__( 'Open Export', 'open-export' ),
			opex_capability(),
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function enqueue( string $hook ): void {
		if ( 'tools_page_' . self::SLUG !== $hook ) {
			return;
		}

		if ( self::is_next() ) {
			self::enqueue_next();
			return;
		}

		wp_enqueue_style( 'opex-admin', OPEX_URL . 'assets/admin.css', array(), OPEX_VERSION );
		wp_enqueue_script( 'opex-admin', OPEX_URL . 'assets/admin.js', array( 'wp-api-fetch', 'wp-i18n' ), OPEX_VERSION, true );
		wp_set_script_translations( 'opex-admin', 'open-export' );

		wp_localize_script( 'opex-admin', 'OPEX_BOOT', array(
			'restBase'  => 'open-export/v1',
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'restRoot'  => esc_url_raw( rest_url( 'open-export/v1' ) ),
		) );
	}

	/**
	 * Whether this request asked for the new screen.
	 *
	 * @return bool
	 */
	public static function is_next(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks which read-only assets load, nothing is saved.
		return isset( $_GET['opex_ui'] ) && 'next' === sanitize_key( wp_unslash( $_GET['opex_ui'] ) );
	}

	/**
	 * Handles of the new screen's scripts, in load order.
	 *
	 * @return string[]
	 */
	public static function next_handles(): array {
		return array_keys( self::NEXT_SCRIPTS );
	}

	/**
	 * Handles of the new screen's styles, in load order.
	 *
	 * @return string[]
	 */
	public static function next_style_handles(): array {
		return array_keys( self::NEXT_STYLES );
	}

	/**
	 * Loads the new screen. OPEX_BOOT keeps the keys of the current screen
	 * and adds adminUrl, the link back to the current screen.
	 *
	 * @return void
	 */
	private static function enqueue_next(): void {
		$base = OPEX_URL . 'assets/app/';
		foreach ( self::NEXT_STYLES as $handle => $style ) {
			wp_enqueue_style( $handle, $base . $style[0], $style[1], OPEX_VERSION );
		}

		foreach ( self::NEXT_SCRIPTS as $handle => $script ) {
			wp_register_script( $handle, $base . $script[0], $script[1], OPEX_VERSION, true );
			wp_set_script_translations( $handle, 'open-export' );
		}
		wp_enqueue_script( 'opex-app-main' );

		wp_localize_script( 'opex-app-core', 'OPEX_BOOT', array(
			'restBase'  => 'open-export/v1',
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'restRoot'  => esc_url_raw( rest_url( 'open-export/v1' ) ),
			'adminUrl'  => esc_url_raw( admin_url( 'tools.php?page=' . self::SLUG ) ),
		) );
	}

	public static function render(): void {
		if ( ! current_user_can( opex_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to use Open Export.', 'open-export' ) );
		}
		?>
		<div class="wrap opex-wrap">
			<div id="opex-app">
				<p><?php esc_html_e( 'Loading Open Export…', 'open-export' ); ?></p>
				<noscript><?php esc_html_e( 'Open Export needs JavaScript enabled.', 'open-export' ); ?></noscript>
			</div>
		</div>
		<?php
	}
}
