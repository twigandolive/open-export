<?php
/**
 * Site Health information and test for Open Export.
 *
 * The section never holds a secret, a trigger key or the host name of a
 * delivery destination. Only counts and yes or no answers are reported.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Adds the debug information section and one direct test.
 */
class OPEX_Site_Health {

	/**
	 * Adds the hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'debug_information', array( __CLASS__, 'add_section' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'add_test' ) );
	}

	/**
	 * Whether WP-Cron is switched off. Filterable so tests need no constant.
	 *
	 * @return bool
	 */
	public static function cron_disabled(): bool {
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		return (bool) apply_filters( 'opex_cron_disabled', $disabled );
	}

	/**
	 * Adds the "Open Export" section.
	 *
	 * @param mixed $info Debug information from WordPress.
	 * @return array
	 */
	public static function add_section( $info ): array {
		$info = is_array( $info ) ? $info : array();
		$dir  = self::export_dir();
		$disk = self::folder_stats( $dir );
		$all  = OPEX_Schedules::all();
		$on   = 0;
		foreach ( $all as $schedule ) {
			$on += ! empty( $schedule['enabled'] ) ? 1 : 0;
		}

		$fields = array(
			'version'       => array( 'label' => __( 'Plugin version', 'open-export' ), 'value' => defined( 'OPEX_VERSION' ) ? OPEX_VERSION : '' ),
			'folder'        => array( 'label' => __( 'Export folder', 'open-export' ), 'value' => $dir, 'private' => true ),
			'writable'      => self::yes_no( __( 'Export folder is writable', 'open-export' ), $disk['writable'] ),
			'protected'     => self::yes_no( __( 'Export folder is protected', 'open-export' ), $disk['protected'] ),
			'files'         => array( 'label' => __( 'Stored export files', 'open-export' ), 'value' => $disk['files'], 'debug' => $disk['files'] ),
			'size'          => array( 'label' => __( 'Size of stored export files', 'open-export' ), 'value' => size_format( $disk['bytes'] ), 'debug' => $disk['bytes'] ),
			'retention'     => array( 'label' => __( 'Retention (days)', 'open-export' ), 'value' => (int) OPEX_Settings::get( 'retention_days' ) ),
			'schedules'     => array( 'label' => __( 'Schedules', 'open-export' ), 'value' => count( $all ), 'debug' => count( $all ) ),
			'schedules_on'  => array( 'label' => __( 'Enabled schedules', 'open-export' ), 'value' => $on, 'debug' => $on ),
			'next_run'      => self::next_run_field( $all ),
			'cron_disabled' => self::yes_no( __( 'WP-Cron is disabled', 'open-export' ), self::cron_disabled() ),
		);

		foreach ( array( 'zip', 'sodium', 'ftp', 'mbstring', 'iconv', 'dom' ) as $ext ) {
			$loaded                  = extension_loaded( $ext );
			$fields[ 'ext_' . $ext ] = array(
				/* translators: %s: PHP extension name. */
				'label' => sprintf( __( 'PHP extension %s', 'open-export' ), $ext ),
				'value' => $loaded ? __( 'Present', 'open-export' ) : __( 'Missing', 'open-export' ),
				'debug' => $loaded ? 'present' : 'missing',
			);
		}

		if ( OPEX_Woo::is_active() ) {
			$hpos                  = OPEX_Woo::hpos_enabled();
			$fields['woo_storage'] = array(
				'label' => __( 'WooCommerce order storage', 'open-export' ),
				'value' => $hpos ? __( 'High-performance order storage', 'open-export' ) : __( 'Legacy posts storage', 'open-export' ),
				'debug' => $hpos ? 'hpos' : 'legacy',
			);
		}

		$info['open-export'] = array(
			'label'  => __( 'Open Export', 'open-export' ),
			'fields' => $fields,
		);
		return $info;
	}

	/**
	 * Adds the direct test.
	 *
	 * @param mixed $tests Site Health tests.
	 * @return array
	 */
	public static function add_test( $tests ): array {
		$tests = is_array( $tests ) ? $tests : array();

		$tests['direct']['open_export_cron'] = array(
			'label' => __( 'Open Export can run scheduled exports', 'open-export' ),
			'test'  => array( __CLASS__, 'test_cron' ),
		);
		return $tests;
	}

	/**
	 * The test result.
	 *
	 * @return array
	 */
	public static function test_cron(): array {
		$enabled = 0;
		foreach ( OPEX_Schedules::all() as $schedule ) {
			$enabled += ! empty( $schedule['enabled'] ) ? 1 : 0;
		}

		$result = array(
			'label'       => __( 'Open Export can run scheduled exports', 'open-export' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Open Export', 'open-export' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Scheduled exports can run on time.', 'open-export' ) . '</p>',
			'actions'     => '',
			'test'        => 'open_export_cron',
		);

		if ( $enabled > 0 && self::cron_disabled() ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'Open Export scheduled exports need a server cron job', 'open-export' );
			$result['description'] = '<p>' . esc_html__( 'You have enabled schedules, but WP-Cron is turned off on this site (DISABLE_WP_CRON), so WordPress will not start them by itself.', 'open-export' ) . '</p>'
				. '<p>' . esc_html__( 'Either call wp-cron.php from a server cron job every few minutes, or call the trigger URL of each schedule on its own timetable. You can copy the trigger URL from the Schedules screen.', 'open-export' ) . '</p>';
		}
		return $result;
	}

	/**
	 * The export folder path, without creating it.
	 *
	 * @return string
	 */
	private static function export_dir(): string {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( (string) $uploads['basedir'] ) . 'open-export';
	}

	/**
	 * Reads the folder: writable, guarded, file count and total size.
	 *
	 * @param string $dir Folder path.
	 * @return array
	 */
	private static function folder_stats( string $dir ): array {
		$stats = array(
			'writable'  => false,
			'protected' => false,
			'files'     => 0,
			'bytes'     => 0,
		);
		if ( ! is_dir( $dir ) ) {
			return $stats;
		}
		$stats['writable']  = wp_is_writable( $dir );
		$stats['protected'] = is_file( $dir . '/.htaccess' ) && is_file( $dir . '/index.php' );
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			if ( is_file( $file ) && 'index.php' !== basename( $file ) ) {
				++$stats['files'];
				$stats['bytes'] += (int) filesize( $file );
			}
		}
		return $stats;
	}

	/**
	 * A yes or no field.
	 *
	 * @param string $label Field label.
	 * @param bool   $yes   The answer.
	 * @return array
	 */
	private static function yes_no( string $label, bool $yes ): array {
		return array(
			'label' => $label,
			'value' => $yes ? __( 'Yes', 'open-export' ) : __( 'No', 'open-export' ),
			'debug' => $yes,
		);
	}

	/**
	 * The next scheduled run of any enabled schedule, in site time.
	 *
	 * @param array $schedules All schedules.
	 * @return array
	 */
	private static function next_run_field( array $schedules ): array {
		$next = 0;
		foreach ( $schedules as $id => $schedule ) {
			if ( empty( $schedule['enabled'] ) ) {
				continue;
			}
			$at = (int) wp_next_scheduled( 'opex_run_schedule', array( (string) $id ) );
			$at = $at > 0 ? $at : (int) ( $schedule['next_run'] ?? 0 );
			if ( $at > 0 && ( 0 === $next || $at < $next ) ) {
				$next = $at;
			}
		}
		return array(
			'label' => __( 'Next scheduled run (site time)', 'open-export' ),
			'value' => $next > 0 ? wp_date( 'Y-m-d H:i', $next ) : __( 'None', 'open-export' ),
			'debug' => $next,
		);
	}
}
