<?php
/**
 * Admin screen: a single page that boots the JS app. All data flows
 * through the REST API, so this stays a thin shell.
 */

defined( 'ABSPATH' ) || exit;

class OPEX_Admin {

	private const SLUG = 'open-export';

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

		wp_enqueue_style( 'opex-admin', OPEX_URL . 'assets/admin.css', array(), OPEX_VERSION );
		wp_enqueue_script( 'opex-admin', OPEX_URL . 'assets/admin.js', array( 'wp-api-fetch' ), OPEX_VERSION, true );

		wp_localize_script( 'opex-admin', 'OPEX_BOOT', array(
			'restBase'  => 'open-export/v1',
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'restRoot'  => esc_url_raw( rest_url( 'open-export/v1' ) ),
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
