<?php
/**
 * Plugin settings (option opex_settings).
 *
 * @package OpenExport
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Reads and saves the small global settings with clamping.
 */
class OPEX_Settings {

	private const OPTION = 'opex_settings';

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'retention_days'         => 30,
			'link_days'              => 7,
			'background'             => false,
			'keep_data_on_uninstall' => false,
		);
	}

	/**
	 * All settings merged over the defaults, clamped.
	 *
	 * @return array
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		return self::clean( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed Null for an unknown key.
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Sanitizes and saves. Unknown keys are dropped.
	 *
	 * @param array $raw Raw input.
	 * @return array The saved settings.
	 */
	public static function save( array $raw ): array {
		$clean = self::clean( array_merge( self::all(), $raw ) );
		update_option( self::OPTION, $clean, false );
		return $clean;
	}

	/**
	 * Clamps values over the defaults.
	 *
	 * @param array $in Input.
	 * @return array
	 */
	private static function clean( array $in ): array {
		$d = self::defaults();
		return array(
			'retention_days'         => self::clamp( $in['retention_days'] ?? $d['retention_days'], 1, 365, $d['retention_days'] ),
			'link_days'              => self::clamp( $in['link_days'] ?? $d['link_days'], 1, 30, $d['link_days'] ),
			'background'             => self::flag( $in, 'background' ),
			// uninstall.php reads this key straight from the option, because it cannot load this class.
			'keep_data_on_uninstall' => self::flag( $in, 'keep_data_on_uninstall' ),
		);
	}

	/**
	 * A yes or no setting, false when it is missing.
	 *
	 * @param array  $in  Input.
	 * @param string $key Setting key.
	 * @return bool
	 */
	private static function flag( array $in, string $key ): bool {
		return isset( $in[ $key ] ) ? (bool) filter_var( $in[ $key ], FILTER_VALIDATE_BOOLEAN ) : (bool) self::defaults()[ $key ];
	}

	/**
	 * Clamps a number to a range.
	 *
	 * @param mixed $value    Input.
	 * @param int   $min      Minimum.
	 * @param int   $max      Maximum.
	 * @param int   $fallback Used when the input is not numeric.
	 * @return int
	 */
	private static function clamp( $value, int $min, int $max, int $fallback ): int {
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}
		return max( $min, min( $max, (int) $value ) );
	}
}
