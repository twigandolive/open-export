<?php
/**
 * Suggested privacy policy text for Open Export.
 *
 * Decision on the personal data exporter and eraser: this class registers
 * neither (`wp_privacy_personal_data_exporters`, `wp_privacy_personal_data_erasers`).
 * Those tools answer "what do you hold about this person?". The plugin keeps no
 * records keyed to a person. It stores job records (a label, a source id and
 * counts), saved templates, schedules, FTP destinations and settings, none of
 * which is about a site visitor or customer. The only personal data it can
 * hold is inside export files, which are copies of data that stays in WordPress
 * itself, so the core exporter and eraser already cover the original. Export
 * files are deleted by the retention period, and an administrator can delete
 * any export from History. The policy text says so. Notification addresses
 * typed into a schedule are chosen by the administrator and are removed when
 * the schedule is deleted.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Adds the suggested privacy policy content.
 */
class OPEX_Privacy {

	/**
	 * Adds the hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_init', array( __CLASS__, 'add_policy_content' ) );
	}

	/**
	 * Hands the suggested text to the WordPress privacy tools.
	 *
	 * @return void
	 */
	public static function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( __( 'Open Export', 'open-export' ), self::policy_text() );
	}

	/**
	 * The suggested policy text, as HTML paragraphs.
	 *
	 * @return string
	 */
	public static function policy_text(): string {
		$days = (int) OPEX_Settings::get( 'retention_days' );

		$paragraphs = array(
			__( 'Open Export lets site administrators export data from this site to a file. An export file can contain personal data, such as names, email addresses, postal addresses and order details, taken from this site.', 'open-export' ),
			sprintf(
				/* translators: %d: number of days. */
				_n(
					'Export files are stored in a protected folder under the uploads folder of this site. They are deleted automatically after %d day.',
					'Export files are stored in a protected folder under the uploads folder of this site. They are deleted automatically after %d days.',
					$days,
					'open-export'
				),
				$days
			),
			__( 'An administrator can also delete any export at any time from the History screen.', 'open-export' ),
			__( 'Nothing leaves this site unless an administrator turns on a delivery (email, webhook, FTP or SFTP) for an export or downloads a file. When a delivery is on, the export file or a download link is sent to the address or server the administrator chose.', 'open-export' ),
			__( 'Open Export does not send usage data to its author or to any other service.', 'open-export' ),
		);

		$html = '';
		foreach ( $paragraphs as $text ) {
			$html .= '<p>' . esc_html( $text ) . '</p>' . "\n";
		}
		return $html;
	}
}
