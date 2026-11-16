<?php
/**
 * Atomic named locks kept in the options table.
 *
 * A lock is one non-autoloaded option, `opex_lock_<name>`, whose value is
 * `<expiry>:<owner token>`. Taking it is a single INSERT IGNORE, the same
 * pattern WordPress core uses for its own upgrade lock, so two requests can
 * never both win. A lock that has expired is taken over with a compare-and-swap
 * UPDATE. The token proves ownership: a holder whose lock went stale and was
 * taken over can no longer refresh or release the new holder's lock. Every call
 * talks to the database directly, so an object cache can never show a stale
 * answer.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Takes, refreshes and releases a lock.
 */
class OPEX_Lock {

	/**
	 * Option name for a lock.
	 *
	 * @param string $name Lock name, such as a job id.
	 */
	public static function option_name( string $name ): string {
		return 'opex_lock_' . $name;
	}

	/**
	 * Tries to take a lock.
	 *
	 * @param string $name Lock name.
	 * @param int    $ttl  Seconds until it expires unless refreshed.
	 * @return string|false The owner token when this caller now holds the lock, else false.
	 */
	public static function acquire( string $name, int $ttl ) {
		global $wpdb;

		$key   = self::option_name( $name );
		$now   = time();
		$token = wp_generate_password( 16, false );
		$value = ( $now + $ttl ) . ':' . $token;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an atomic lock cannot go through the options API.
		$taken = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
				$key,
				$value
			)
		);
		if ( 1 === (int) $taken ) {
			return $token;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reading the lock row itself.
		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
		if ( null === $held || (int) $held >= $now ) {
			return false;
		}

		// The holder is gone. Swap only if nobody else already took over.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-swap on the lock row.
		$swapped = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				$key,
				(string) $held
			)
		);
		return 1 === (int) $swapped ? $token : false;
	}

	/**
	 * Pushes the expiry of a lock this caller holds.
	 *
	 * @param string $name  Lock name.
	 * @param string $token Owner token from acquire().
	 * @param int    $ttl   Seconds from now.
	 * @return bool False when the lock is no longer this caller's.
	 */
	public static function refresh( string $name, string $token, int $ttl ): bool {
		global $wpdb;

		if ( '' === $token ) {
			return false;
		}
		$key = self::option_name( $name );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- touching the lock row.
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s",
				( time() + $ttl ) . ':' . $token,
				$key,
				'%:' . $wpdb->esc_like( $token )
			)
		);
		if ( (int) $changed > 0 ) {
			return true;
		}
		// Some drivers report 0 changed rows when the value did not change, so look.
		return self::owns( $name, $token );
	}

	/**
	 * Gives a lock up, but only when this caller still owns it.
	 *
	 * @param string $name  Lock name.
	 * @param string $token Owner token from acquire().
	 */
	public static function release( string $name, string $token ): void {
		global $wpdb;

		if ( '' === $token ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- removing the lock row.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
				self::option_name( $name ),
				'%:' . $wpdb->esc_like( $token )
			)
		);
	}

	/**
	 * Removes a lock whoever holds it. For cleanup only: deleting a job,
	 * deactivating the plugin and tests. A running worker never calls this.
	 *
	 * @param string $name Lock name.
	 */
	public static function force_release( string $name ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- removing the lock row.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::option_name( $name ) ) );
	}

	/**
	 * Whether the stored lock carries this token.
	 *
	 * @param string $name  Lock name.
	 * @param string $token Owner token.
	 */
	public static function owns( string $name, string $token ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reading the lock row.
		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::option_name( $name ) ) );
		return '' !== $token && is_string( $held ) && substr( $held, -strlen( $token ) - 1 ) === ':' . $token;
	}

	/**
	 * Whether a live lock exists.
	 *
	 * @param string $name Lock name.
	 */
	public static function is_held( string $name ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reading the lock row.
		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::option_name( $name ) ) );
		return null !== $held && (int) $held >= time();
	}
}
