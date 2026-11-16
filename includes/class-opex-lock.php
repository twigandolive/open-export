<?php
/**
 * Atomic named locks kept in the options table.
 *
 * A lock is one non-autoloaded option, `opex_lock_<name>`, whose value is the
 * time it expires. Taking it is a single INSERT IGNORE, the same pattern
 * WordPress core uses for its own upgrade lock, so two requests can never both
 * win. A lock that has expired is taken over with a compare-and-swap UPDATE.
 * Every call talks to the database directly, so an object cache can never show
 * a stale answer.
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
	 * @return bool True when this caller now holds it.
	 */
	public static function acquire( string $name, int $ttl ): bool {
		global $wpdb;

		$key = self::option_name( $name );
		$now = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an atomic lock cannot go through the options API.
		$taken = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
			$key,
			(string) ( $now + $ttl )
		) );
		if ( 1 === (int) $taken ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reading the lock row itself.
		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
		if ( null === $held || (int) $held >= $now ) {
			return false;
		}

		// The holder is gone. Swap only if nobody else already took over.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-swap on the lock row.
		$swapped = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			(string) ( $now + $ttl ),
			$key,
			(string) $held
		) );
		return 1 === (int) $swapped;
	}

	/**
	 * Pushes the expiry of a lock this caller holds.
	 *
	 * @param string $name Lock name.
	 * @param int    $ttl  Seconds from now.
	 */
	public static function refresh( string $name, int $ttl ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- touching the lock row.
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
			(string) ( time() + $ttl ),
			self::option_name( $name )
		) );
	}

	/**
	 * Gives a lock up.
	 *
	 * @param string $name Lock name.
	 */
	public static function release( string $name ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- removing the lock row.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::option_name( $name ) ) );
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
