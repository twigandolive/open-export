<?php
/**
 * Safe reads and writes of the plugin's registry options.
 *
 * The jobs, schedules and destinations each live in one option. WordPress
 * keeps an option in memory for the rest of a request, so a long request
 * (a background run, a CLI export, the hourly sweep) would otherwise never
 * see what another process wrote, and its next save would put the old copy
 * back. Every read here asks the database again, and every change happens
 * under a short lock with a fresh read inside it.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Fresh reads and locked read-modify-write for one option.
 */
class OPEX_Store {

	/** Exception code of the error a change throws when it cannot be saved. */
	public const BUSY = 7002;

	/** Seconds a write lock lives. A write takes milliseconds, so this only matters after a crash. */
	private const LOCK_TTL = 15;

	/** Seconds a writer waits for the lock before it gives up. */
	private const WAIT = 20;

	/** Microseconds between tries while waiting. */
	private const PAUSE = 25000;

	/** Times a change runs before it gives up because others keep saving first. */
	private const TRIES = 3;

	/** Exception code that sends a change back to read again. It never leaves this class. */
	private const CONFLICT = 7003;

	/**
	 * Seconds take() waits. Only tests shorten it, through reflection.
	 *
	 * @var float
	 */
	private static $wait = self::WAIT;

	/**
	 * Lock tokens this process holds, by option name. A change made inside
	 * another change on the same option reuses the lock instead of waiting on itself.
	 *
	 * @var array<string, string>
	 */
	private static array $held = array();

	/**
	 * The option as the database holds it now, or an empty array.
	 *
	 * @param string $option Option name.
	 * @return array
	 */
	public static function read( string $option ): array {
		self::forget( $option );
		$value = get_option( $option, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Changes the option under its lock. The callback gets a fresh copy by
	 * reference and edits it. The option is written only when the copy changed.
	 * A change nested inside another change on the same option reuses the
	 * lock. The outer callback must then leave its own copy alone, because
	 * writing it would replace what the inner change saved.
	 *
	 * The write only lands when this process still holds the lock and the
	 * stored value is still the one the callback started from. Otherwise the
	 * callback runs again on a fresh copy, so it may run more than once. After
	 * TRIES runs, or when the lock cannot be had, nothing is written and an
	 * OPEX_User_Error with the code BUSY is thrown.
	 *
	 * @param string   $option Option name.
	 * @param callable $change Receives `array &$value` and returns any result.
	 * @return mixed What the callback returned.
	 * @throws OPEX_User_Error With the code BUSY when the change could not be saved.
	 */
	public static function change( string $option, callable $change ) {
		if ( isset( self::$held[ $option ] ) ) {
			return self::apply( $option, $change );
		}
		$name  = 'store_' . $option;
		$token = self::take( $name );
		if ( '' === $token ) {
			throw self::busy();
		}
		self::$held[ $option ] = $token;
		try {
			for ( $try = 1; ; $try++ ) {
				try {
					return self::apply( $option, $change );
				} catch ( RuntimeException $e ) {
					if ( self::CONFLICT !== $e->getCode() || $try >= self::TRIES ) {
						throw self::CONFLICT === $e->getCode() ? self::busy() : $e;
					}
				}
				// Another writer came first. Hold the lock again before the next run.
				if ( ! OPEX_Lock::owns( $name, self::$held[ $option ] ) ) {
					$token = self::take( $name );
					if ( '' === $token ) {
						throw self::busy();
					}
					self::$held[ $option ] = $token;
				}
			}
		} finally {
			OPEX_Lock::release( $name, self::$held[ $option ] );
			unset( self::$held[ $option ] );
		}
	}

	/**
	 * Whether an error is the one change() throws when it could not save.
	 *
	 * @param Throwable $e What was thrown.
	 * @return bool True for the busy error.
	 */
	public static function is_busy( Throwable $e ): bool {
		return $e instanceof OPEX_User_Error && self::BUSY === $e->getCode();
	}

	/**
	 * Reads, runs the callback and writes when something changed. The write
	 * compares against the value read here, so it never replaces a newer one.
	 *
	 * @param string   $option Option name.
	 * @param callable $change The edit.
	 * @return mixed What the callback returned.
	 * @throws RuntimeException With the code CONFLICT when the write did not land.
	 */
	private static function apply( string $option, callable $change ) {
		$row    = self::row( $option );
		$value  = null === $row ? array() : maybe_unserialize( $row[0] );
		$value  = is_array( $value ) ? $value : array();
		$before = $value;
		$result = $change( $value );
		if ( $value === $before ) {
			return $result;
		}
		// A lock that expired while the callback ran may belong to someone else now.
		$token = self::$held[ $option ] ?? '';
		if ( ! OPEX_Lock::refresh( 'store_' . $option, $token, self::LOCK_TTL ) || ! self::commit( $option, $row, $value ) ) {
			self::forget( $option );
			throw new RuntimeException( 'The option changed meanwhile.', self::CONFLICT ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal signal, caught in change() and never shown.
		}
		self::forget( $option );
		return $result;
	}

	/**
	 * The stored text of the option and its MD5, both from the database, or
	 * null when there is no row. The database makes the hash, so it matches
	 * what a later compare sees whatever the character sets are.
	 *
	 * @param string $option Option name.
	 * @return array|null The raw value and its hash, or null.
	 */
	private static function row( string $option ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the compare-and-swap needs the stored text, never a cached copy.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, MD5(option_value) FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option ), ARRAY_N );
		return is_array( $row ) ? array( (string) $row[0], strtolower( (string) $row[1] ) ) : null;
	}

	/**
	 * Writes the new value only if the row still holds what was read: an
	 * UPDATE that matches the old hash, or an INSERT when there was no row.
	 *
	 * @param string     $option Option name.
	 * @param array|null $row    What row() returned before the callback ran.
	 * @param array      $value  The new value.
	 * @return bool True when the write landed.
	 */
	private static function commit( string $option, ?array $row, array $value ): bool {
		global $wpdb;
		$raw = maybe_serialize( $value );
		if ( null === $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an insert that loses to any row written meanwhile.
			$done = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )", $option, $raw ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-swap on the option row.
			$done = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND MD5(option_value) = %s", $raw, $option, $row[1] ) );
		}
		if ( 1 === (int) $done ) {
			return true;
		}
		// Some drivers count no row when the stored text already equals the new text.
		$now = self::row( $option );
		return null !== $now && $now[0] === $raw;
	}

	/**
	 * The error change() throws when it cannot save.
	 *
	 * @return OPEX_User_Error The error, with the code BUSY.
	 */
	private static function busy(): OPEX_User_Error {
		return new OPEX_User_Error( esc_html__( 'The site is busy. Try again in a moment.', 'open-export' ), self::BUSY );
	}

	/**
	 * Waits for the lock. Gives up after the wait and returns ''. A lock left
	 * by a crashed process expires before the wait ends, so only a live
	 * holder can make a writer give up.
	 *
	 * @param string $name Lock name.
	 * @return string The owner token, or '' when the lock could not be had.
	 */
	private static function take( string $name ): string {
		$until = microtime( true ) + (float) self::$wait;
		do {
			$token = OPEX_Lock::acquire( $name, self::LOCK_TTL );
			if ( false !== $token ) {
				return $token;
			}
			usleep( self::PAUSE );
		} while ( microtime( true ) < $until );
		return '';
	}

	/**
	 * Drops every copy of the option WordPress keeps in memory, so the next
	 * get_option() asks the database. With a persistent object cache this
	 * clears the shared copy too, which is what other processes read.
	 *
	 * @internal Shared with OPEX_Rest_Public only, for its transient counters.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	public static function forget( string $option ): void {
		wp_cache_delete( $option, 'options' );
		foreach ( array( 'notoptions', 'alloptions' ) as $list ) {
			$cached = wp_cache_get( $list, 'options' );
			if ( is_array( $cached ) && array_key_exists( $option, $cached ) ) {
				unset( $cached[ $option ] );
				wp_cache_set( $list, $cached, 'options' );
			}
		}
	}
}
