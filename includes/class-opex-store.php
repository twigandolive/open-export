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

	/** Seconds a write lock lives. A write takes milliseconds, so this only matters after a crash. */
	private const LOCK_TTL = 15;

	/** Seconds a writer waits for the lock before it writes without it. */
	private const WAIT = 20;

	/** Microseconds between tries while waiting. */
	private const PAUSE = 25000;

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
	 * @param string   $option Option name.
	 * @param callable $change Receives `array &$value` and returns any result.
	 * @return mixed What the callback returned.
	 */
	public static function change( string $option, callable $change ) {
		if ( isset( self::$held[ $option ] ) ) {
			return self::apply( $option, $change );
		}
		$name  = 'store_' . $option;
		$token = self::take( $name );
		if ( '' !== $token ) {
			self::$held[ $option ] = $token;
		}
		try {
			return self::apply( $option, $change );
		} finally {
			unset( self::$held[ $option ] );
			if ( '' !== $token ) {
				OPEX_Lock::release( $name, $token );
			}
		}
	}

	/**
	 * Reads, runs the callback and writes when something changed.
	 *
	 * @param string   $option Option name.
	 * @param callable $change The edit.
	 * @return mixed
	 */
	private static function apply( string $option, callable $change ) {
		$value  = self::read( $option );
		$before = $value;
		$result = $change( $value );
		if ( $value !== $before ) {
			update_option( $option, $value, false );
		}
		return $result;
	}

	/**
	 * Waits for the lock. Gives up after WAIT seconds and returns '', so a
	 * lock left by a crashed process can delay a save but never lose it.
	 * By then the stale lock has expired and the next writer takes it over.
	 *
	 * @param string $name Lock name.
	 * @return string The owner token, or '' when the lock could not be had.
	 */
	private static function take( string $name ): string {
		$until = microtime( true ) + self::WAIT;
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
	 * @param string $option Option name.
	 */
	private static function forget( string $option ): void {
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
