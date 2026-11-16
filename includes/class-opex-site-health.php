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

	/** A background job with no batch for this long counts as stuck. */
	private const STUCK_AFTER = HOUR_IN_SECONDS;

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
			'version'       => array(
				'label' => __( 'Plugin version', 'open-export' ),
				'value' => defined( 'OPEX_VERSION' ) ? OPEX_VERSION : '',
			),
			'folder'        => array(
				'label'   => __( 'Export folder', 'open-export' ),
				'value'   => $dir,
				'private' => true,
			),
			'writable'      => self::yes_no( __( 'Export folder is writable', 'open-export' ), $disk['writable'] ),
			'protected'     => self::protection_field( $disk['rules'] ),
			'files'         => array(
				'label' => __( 'Stored export files', 'open-export' ),
				'value' => $disk['files'],
				'debug' => $disk['files'],
			),
			'size'          => array(
				'label' => __( 'Size of stored export files', 'open-export' ),
				'value' => size_format( $disk['bytes'] ),
				'debug' => $disk['bytes'],
			),
			'retention'     => array(
				'label' => __( 'Keep finished exports (days)', 'open-export' ),
				'value' => (int) OPEX_Settings::get( 'retention_days' ),
			),
			'schedules'     => array(
				'label' => __( 'Schedules', 'open-export' ),
				'value' => count( $all ),
				'debug' => count( $all ),
			),
			'schedules_on'  => array(
				'label' => __( 'Schedules turned on', 'open-export' ),
				'value' => $on,
				'debug' => $on,
			),
			'next_run'      => self::next_run_field( $all ),
			'cron_disabled' => self::yes_no( __( 'WP-Cron is turned off', 'open-export' ), self::cron_disabled() ),
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

		$tests['direct']['open_export_cron']  = array(
			'label' => __( 'Open Export can run scheduled exports', 'open-export' ),
			'test'  => array( __CLASS__, 'test_cron' ),
		);
		$tests['direct']['open_export_stuck'] = array(
			'label' => __( 'Open Export background exports are moving', 'open-export' ),
			'test'  => array( __CLASS__, 'test_stuck_jobs' ),
		);
		return $tests;
	}

	/**
	 * Warns about background exports that show "running" but have not
	 * finished a batch for over an hour. The hourly maintenance restarts a
	 * stalled job after ten minutes, so an hour means the restarts are not
	 * helping, often because WP-Cron does not run.
	 *
	 * @return array
	 */
	public static function test_stuck_jobs(): array {
		$stuck = 0;
		foreach ( OPEX_Jobs::all() as $job ) {
			if ( 'background' !== $job['mode'] || 'running' !== $job['status'] ) {
				continue;
			}
			$last   = (int) $job['heartbeat'] > 0 ? (int) $job['heartbeat'] : (int) $job['created'];
			$stuck += time() - $last > self::STUCK_AFTER ? 1 : 0;
		}

		$result = array(
			'label'       => __( 'Open Export background exports are moving', 'open-export' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Open Export', 'open-export' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'No background export has been waiting for more than an hour.', 'open-export' ) . '</p>',
			'actions'     => '',
			'test'        => 'open_export_stuck',
		);
		if ( $stuck > 0 ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'An Open Export background export is stuck', 'open-export' );
			$result['description'] = '<p>' . esc_html(
				sprintf(
					/* translators: %d: number of exports. */
					_n(
						'%d background export has not moved for over an hour.',
						'%d background exports have not moved for over an hour.',
						$stuck,
						'open-export'
					),
					$stuck
				)
			) . '</p><p>' . esc_html__( 'Background exports run on WP-Cron. Check that WP-Cron runs on this site. Then pause and resume the export on the History tab of Tools > Open Export, or start it again.', 'open-export' ) . '</p>';
		}
		return $result;
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
			$result['description'] = '<p>' . esc_html__( 'You have schedules turned on, but WP-Cron is turned off on this site (DISABLE_WP_CRON), so WordPress does not start them by itself.', 'open-export' ) . '</p>'
				. '<p>' . esc_html__( 'Either call wp-cron.php from a server cron job every few minutes, or call the trigger URL of each schedule on its own timetable. You can copy the trigger URL on the Schedules tab of Tools > Open Export.', 'open-export' ) . '</p>';
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
	 * Reads the folder: writable, which deny rules are in it, file count and total size.
	 *
	 * @param string $dir Folder path.
	 * @return array
	 */
	private static function folder_stats( string $dir ): array {
		$stats = array(
			'writable' => false,
			'rules'    => array(),
			'files'    => 0,
			'bytes'    => 0,
		);
		if ( ! is_dir( $dir ) ) {
			return $stats;
		}
		$stats['writable'] = wp_is_writable( $dir );
		foreach ( array_keys( opex_export_dir_rules() ) as $name ) {
			if ( is_file( $dir . '/' . $name ) ) {
				$stats['rules'][] = $name;
			}
		}
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			if ( is_file( $file ) && self::is_export_file( basename( $file ) ) ) {
				++$stats['files'];
				$stats['bytes'] += (int) filesize( $file );
			}
		}
		return $stats;
	}

	/**
	 * Whether a file in the export folder is an export: its name ends in the
	 * extension of a format, maybe followed by ".zip". A running job's lock
	 * file, the Excel writer's row files, a half-built zip and the folder's
	 * own rule files are not exports.
	 *
	 * @param string $name File name.
	 * @return bool True for an export file.
	 */
	private static function is_export_file( string $name ): bool {
		$name = strtolower( $name );
		$zip  = strtolower( OPEX_Compress::SUFFIX );
		if ( strlen( $name ) > strlen( $zip ) && substr( $name, -strlen( $zip ) ) === $zip ) {
			$name = substr( $name, 0, -strlen( $zip ) );
		}
		$ext = (string) pathinfo( $name, PATHINFO_EXTENSION );
		foreach ( OPEX_Writer::formats() as $format ) {
			if ( '' !== $ext && strtolower( (string) $format['extension'] ) === $ext ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * What protects the folder, said plainly. The rule files only work on
	 * the servers that read them, so this never claims more than that.
	 *
	 * @param string[] $rules Rule files found in the folder.
	 * @return array
	 */
	private static function protection_field( array $rules ): array {
		if ( ! $rules ) {
			$value = __( 'Open Export has not written a deny rule yet. Only their random names protect the files.', 'open-export' );
		} else {
			$value = sprintf(
				/* translators: %s: file names, such as ".htaccess, web.config". */
				__( 'Deny rules written (%s) for Apache, LiteSpeed and IIS. nginx ignores these files and needs its own rule to block the folder.', 'open-export' ),
				implode( ', ', $rules )
			);
		}
		return array(
			'label' => __( 'Export folder protection', 'open-export' ),
			'value' => $value,
			'debug' => $rules ? implode( ',', $rules ) : 'none',
		);
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
