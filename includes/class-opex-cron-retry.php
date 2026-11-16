<?php
/**
 * Tries a cron event again a minute later when its work found the store
 * busy, a bounded number of times in a row.
 *
 * Part of the background runner. Reached only through OPEX_Runner.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Counts and queues the retries of OPEX_Runner's cron callbacks.
 */
class OPEX_Cron_Retry {

	/** Seconds until the next try. */
	private const AFTER = 60;

	/**
	 * Most tries in a row of one cron event. A store that stays busy cannot
	 * bring an event back every minute for good: after this the hourly
	 * maintenance run is the backstop.
	 */
	public const LIMIT = 5;

	/**
	 * Whether a cron callback's outcome is worth another try in a minute: a
	 * throw, or the busy error of the store (status 503). A 409 busy error
	 * means another start or worker owns the work, so it is not tried again.
	 * The busy error goes to the debug log here. A throw is logged by its
	 * caller.
	 *
	 * @param mixed $result What the work returned or threw.
	 * @return bool True to try again.
	 */
	public static function retryable( $result ): bool {
		if ( $result instanceof Throwable ) {
			return true;
		}
		if ( ! is_wp_error( $result ) || 'opex_busy' !== $result->get_error_code() ) {
			return false;
		}
		$data = $result->get_error_data();
		if ( ! is_array( $data ) || 503 !== (int) ( $data['status'] ?? 0 ) ) {
			return false;
		}
		OPEX_Exporter::log_exception( new RuntimeException( $result->get_error_message() ) );
		return true;
	}

	/**
	 * Queues the cron event a minute from now, unless one is queued already,
	 * and counts the try.
	 *
	 * @param string $hook Cron hook.
	 * @param string $id   The one argument of the event.
	 * @return bool False when LIMIT tries were made already, so nothing was queued.
	 */
	public static function later( string $hook, string $id ): bool {
		$key   = self::key( $hook, $id );
		$tries = (int) get_transient( $key ) + 1;
		if ( $tries > self::LIMIT ) {
			delete_transient( $key );
			return false;
		}
		set_transient( $key, $tries, DAY_IN_SECONDS );
		if ( ! wp_next_scheduled( $hook, array( $id ) ) ) {
			wp_schedule_single_event( time() + self::AFTER, $hook, array( $id ) );
		}
		return true;
	}

	/**
	 * Forgets the tries of a cron event once its work ran without a busy store.
	 *
	 * @param string $hook Cron hook.
	 * @param string $id   The one argument of the event.
	 * @return void
	 */
	public static function settled( string $hook, string $id ): void {
		delete_transient( self::key( $hook, $id ) );
	}

	/**
	 * The transient that counts the tries of one cron event. The uninstall
	 * clean-up removes it with every other opex_ transient.
	 *
	 * @param string $hook Cron hook.
	 * @param string $id   The one argument of the event.
	 * @return string Transient name.
	 */
	private static function key( string $hook, string $id ): string {
		return 'opex_retry_' . md5( $hook . '|' . $id );
	}
}
