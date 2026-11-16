<?php
/**
 * Saved export schedules. Stored in one non-autoloaded option. The next-run
 * math lives in OPEX_Schedule_Clock, the cron work in OPEX_Runner.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class OPEX_Schedules {

	private const OPTION     = 'opex_schedules';
	private const MAX        = 50;
	private const FREQUENCIES = array( 'manual', 'hourly', 'daily', 'weekly', 'monthly' );
	private const HOOK       = 'opex_run_schedule';

	/** Default values for every key of a schedule record. */
	public static function defaults(): array {
		return array(
			'id'          => '',
			'name'        => '',
			'config'      => array(),
			'frequency'   => 'manual',
			'time'        => '03:00',
			'weekday'     => 1,
			'monthday'    => 1,
			'enabled'     => false,
			'incremental' => array( 'enabled' => false, 'field' => '' ),
			'delivery'    => self::default_delivery(),
			'filename'    => '{label}-{date}',
			'trigger_key' => '',
			'last_run'    => array( 'time' => 0, 'job_id' => '', 'status' => '', 'rows' => 0 ),
			'watermark'   => '',
			'next_run'    => 0,
			'created'     => 0,
			'updated'     => 0,
		);
	}

	/** Delivery block with everything off. */
	private static function default_delivery(): array {
		return array(
			'email'       => array( 'enabled' => false, 'to' => array(), 'mode' => 'link', 'subject' => '' ),
			'webhook'     => array( 'enabled' => false, 'url' => '', 'secret' => '' ),
			'destination' => array( 'enabled' => false, 'id' => '' ),
		);
	}

	/** All schedules keyed by id, defaults filled. */
	public static function all(): array {
		$raw = get_option( self::OPTION, array() );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $id => $rec ) {
			if ( is_array( $rec ) ) {
				$out[ (string) $id ] = self::fill( $rec, (string) $id );
			}
		}
		return $out;
	}

	/** One schedule or null. */
	public static function get( string $id ): ?array {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	/** Fill missing keys so an old or partial record never causes a notice. */
	private static function fill( array $rec, string $id ): array {
		$def = self::defaults();
		$out = array_merge( $def, $rec );
		foreach ( array( 'incremental', 'last_run' ) as $k ) {
			$out[ $k ] = array_merge( $def[ $k ], is_array( $rec[ $k ] ?? null ) ? $rec[ $k ] : array() );
		}
		$delivery = is_array( $rec['delivery'] ?? null ) ? $rec['delivery'] : array();
		foreach ( $def['delivery'] as $ch => $d ) {
			$delivery[ $ch ] = array_merge( $d, is_array( $delivery[ $ch ] ?? null ) ? $delivery[ $ch ] : array() );
		}
		$out['delivery'] = $delivery;
		$out['config']   = is_array( $out['config'] ) ? $out['config'] : array();
		$out['id']       = '' !== (string) $out['id'] ? (string) $out['id'] : $id;
		return $out;
	}

	/** Write the whole registry. */
	private static function write( array $all ): void {
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Validate and save. Returns the record or a WP_Error.
	 *
	 * @param array  $raw Untrusted input. Missing keys keep their stored value on update.
	 * @param string $id  Existing schedule id, or '' to create.
	 * @return array|WP_Error
	 */
	public static function save( array $raw, string $id = '' ) {
		$all = self::all();
		if ( '' !== $id ) {
			if ( ! isset( $all[ $id ] ) ) {
				return new WP_Error( 'opex_missing', __( 'Schedule not found.', 'open-export' ), array( 'status' => 404 ) );
			}
			$base = $all[ $id ];
		} else {
			if ( count( $all ) >= self::MAX ) {
				return new WP_Error( 'opex_limit', __( 'You have reached the limit of 50 schedules.', 'open-export' ), array( 'status' => 400 ) );
			}
			$base = self::defaults();
		}

		$rec = $base;

		$name = sanitize_text_field( (string) ( $raw['name'] ?? $base['name'] ) );
		if ( '' === $name || mb_strlen( $name ) > 100 ) {
			return self::invalid( __( 'Give the schedule a name of up to 100 characters.', 'open-export' ) );
		}
		$rec['name'] = $name;

		if ( isset( $raw['config'] ) || '' === $id ) {
			$config = OPEX_Exporter::sanitize_config( (array) ( $raw['config'] ?? array() ) );
			if ( is_wp_error( $config ) ) {
				return $config;
			}
			$rec['config'] = $config;
		}

		$freq = (string) ( $raw['frequency'] ?? $base['frequency'] );
		if ( ! in_array( $freq, self::FREQUENCIES, true ) ) {
			return self::invalid( __( 'Unknown frequency.', 'open-export' ) );
		}
		$rec['frequency'] = $freq;

		$time = (string) ( $raw['time'] ?? $base['time'] );
		if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			return self::invalid( __( 'Time must look like 14:30.', 'open-export' ) );
		}
		$rec['time'] = $time;

		$weekday = $raw['weekday'] ?? $base['weekday'];
		if ( ! is_numeric( $weekday ) || (int) $weekday < 0 || (int) $weekday > 6 ) {
			return self::invalid( __( 'Weekday must be 0 to 6.', 'open-export' ) );
		}
		$rec['weekday'] = (int) $weekday;

		$monthday = $raw['monthday'] ?? $base['monthday'];
		if ( 'last' === $monthday ) {
			$rec['monthday'] = 'last';
		} elseif ( is_numeric( $monthday ) && (int) $monthday >= 1 && (int) $monthday <= 28 ) {
			$rec['monthday'] = (int) $monthday;
		} else {
			return self::invalid( __( 'Day of month must be 1 to 28 or last.', 'open-export' ) );
		}

		$rec['enabled'] = isset( $raw['enabled'] ) ? (bool) rest_sanitize_boolean( $raw['enabled'] ) : (bool) $base['enabled'];

		$inc = is_array( $raw['incremental'] ?? null ) ? $raw['incremental'] : $base['incremental'];
		$inc_field = sanitize_text_field( (string) ( $inc['field'] ?? '' ) );
		if ( '' !== $inc_field && ! preg_match( '/^(core|meta|tax|computed|col|woo):\S/', $inc_field ) ) {
			return self::invalid( __( 'Unknown date field for incremental exports.', 'open-export' ) );
		}
		$rec['incremental'] = array(
			'enabled' => (bool) rest_sanitize_boolean( $inc['enabled'] ?? false ),
			'field'   => $inc_field,
		);

		$pattern = sanitize_text_field( (string) ( $raw['filename'] ?? $base['filename'] ) );
		$rec['filename'] = '' === $pattern ? '{label}-{date}' : mb_substr( $pattern, 0, 200 );

		$delivery        = is_array( $raw['delivery'] ?? null ) ? $raw['delivery'] : $base['delivery'];
		$rec['delivery'] = self::sanitize_delivery( $delivery, $base['delivery'], ! empty( $raw['clear_secret'] ) );

		$now = time();
		if ( '' === $id ) {
			$id                 = 's' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 12 );
			$rec['id']          = $id;
			$rec['created']     = $now;
			$rec['trigger_key'] = wp_generate_password( 40, false );
		} elseif ( ! empty( $raw['rotate_key'] ) || '' === (string) $rec['trigger_key'] ) {
			$rec['trigger_key'] = wp_generate_password( 40, false );
		}
		$rec['updated']  = $now;
		$rec['next_run'] = self::next_run( $rec, $now );

		$all[ $id ] = $rec;
		self::write( $all );
		self::arm( $rec );
		return $rec;
	}

	/** Build a 400 error. */
	private static function invalid( string $message ): WP_Error {
		return new WP_Error( 'opex_invalid', $message, array( 'status' => 400 ) );
	}

	/**
	 * Clean the delivery block. The webhook secret is always stored encrypted,
	 * a blank one keeps the stored secret, and $clear_secret removes it.
	 *
	 * @param array $raw          Untrusted delivery settings.
	 * @param array $base         Stored settings, used to keep a webhook secret left blank.
	 * @param bool  $clear_secret True to remove the stored webhook secret.
	 */
	private static function sanitize_delivery( array $raw, array $base, bool $clear_secret ): array {
		if ( $clear_secret ) {
			$raw['webhook']                 = is_array( $raw['webhook'] ?? null ) ? $raw['webhook'] : array();
			$raw['webhook']['clear_secret'] = true;
			$raw['webhook']['secret']       = '';
		}
		$notes = array();
		return OPEX_Delivery::sanitize( $raw, $notes, $base );
	}

	/** Delete a schedule and its cron event. */
	public static function delete( string $id ): void {
		self::disarm( $id );
		$all = self::all();
		if ( isset( $all[ $id ] ) ) {
			unset( $all[ $id ] );
			self::write( $all );
		}
	}

	/** Public shape: no key, no webhook secret, plus the trigger URL. */
	public static function expose( array $schedule ): array {
		$s   = self::fill( $schedule, (string) ( $schedule['id'] ?? '' ) );
		$key = (string) $s['trigger_key'];
		unset( $s['trigger_key'] );
		$s['delivery']['webhook']['has_secret'] = '' !== (string) $s['delivery']['webhook']['secret'];
		unset( $s['delivery']['webhook']['secret'] );
		$s['trigger_url'] = add_query_arg( 'key', $key, rest_url( 'open-export/v1/schedules/' . $s['id'] . '/trigger' ) );
		return $s;
	}

	/** Next run as a UTC timestamp. See OPEX_Schedule_Clock. */
	public static function next_run( array $schedule, int $now, ?DateTimeZone $tz = null ): int {
		return OPEX_Schedule_Clock::next_run( $schedule, $now, $tz );
	}

	/** Schedule one cron event at next_run, replacing any existing one. */
	public static function arm( array $schedule ): void {
		$id = (string) ( $schedule['id'] ?? '' );
		if ( '' === $id ) {
			return;
		}
		self::disarm( $id );
		$at = (int) ( $schedule['next_run'] ?? 0 );
		if ( $at > 0 ) {
			wp_schedule_single_event( $at, self::HOOK, array( $id ) );
		}
	}

	/** Remove the cron event for a schedule. */
	public static function disarm( string $id ): void {
		wp_clear_scheduled_hook( self::HOOK, array( $id ) );
	}

	/** Recompute next_run from now, store it and re-arm. Returns the record. */
	public static function refresh( array $schedule ): array {
		$schedule['next_run'] = self::next_run( $schedule, time() );
		$all                  = self::all();
		if ( isset( $all[ $schedule['id'] ] ) ) {
			$all[ $schedule['id'] ] = $schedule;
			self::write( $all );
		}
		self::arm( $schedule );
		return $schedule;
	}

	/**
	 * Start a run now.
	 *
	 * @param string $id     Schedule id.
	 * @param string $origin 'cron', 'trigger', 'manual' or 'cli'.
	 * @return array|WP_Error Exposed job or error.
	 */
	public static function start( string $id, string $origin ) {
		$s = self::get( $id );
		if ( ! $s ) {
			return new WP_Error( 'opex_missing', __( 'Schedule not found.', 'open-export' ), array( 'status' => 404 ) );
		}
		if ( 'cron' === $origin && ! $s['enabled'] ) {
			return new WP_Error( 'opex_disabled', __( 'This schedule is turned off.', 'open-export' ), array( 'status' => 409 ) );
		}
		// The check and the job creation happen under one lock, so two starts
		// (the trigger URL, cron, a person) cannot both see "idle".
		$token = OPEX_Lock::acquire( 'sched_' . $id, 60 );
		if ( false === $token ) {
			return self::busy();
		}
		try {
			foreach ( OPEX_Jobs::all() as $job ) {
				if ( ( $job['schedule_id'] ?? '' ) === $id && 'running' === $job['status'] ) {
					return self::busy();
				}
			}
			$job = OPEX_Exporter::create( $s['config'], $s['name'], self::job_extra( $s, $origin ) );
		} finally {
			OPEX_Lock::release( 'sched_' . $id, $token );
		}
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		// A job that finished inside create() already updated the schedule through
		// opex_job_done, so read the record again before saving the next run.
		$s = self::get( $id ) ?? $s;
		if ( 'running' === $job['status'] ) {
			$s['last_run'] = array( 'time' => time(), 'job_id' => (string) $job['id'], 'status' => (string) $job['status'], 'rows' => (int) $job['processed'] );
		}
		self::refresh( $s );

		if ( 'running' === $job['status'] ) {
			OPEX_Runner::dispatch( (string) $job['id'] );
		}
		return $job;
	}

	/** The error for a schedule that is already running. */
	private static function busy(): WP_Error {
		return new WP_Error( 'opex_busy', __( 'This schedule is still running.', 'open-export' ), array( 'status' => 409 ) );
	}

	/**
	 * What a schedule hands to OPEX_Exporter::create().
	 *
	 * @param array  $s      Schedule record.
	 * @param string $origin Who started it.
	 */
	private static function job_extra( array $s, string $origin ): array {
		$extra = array(
			'mode'          => 'background',
			'schedule_id'   => (string) $s['id'],
			'origin'        => in_array( $origin, array( 'manual', 'cron', 'trigger', 'cli' ), true ) ? $origin : 'manual',
			'delivery'      => $s['delivery'],
			'delivery_name' => OPEX_Filename::render(
				(string) $s['filename'],
				array(
					'label'  => $s['name'],
					'source' => (string) ( $s['config']['source'] ?? '' ),
					'id'     => (string) $s['id'],
				)
			),
		);
		$field = (string) $s['incremental']['field'];
		if ( $s['incremental']['enabled'] && '' !== $field && '' !== (string) $s['watermark'] ) {
			$extra['since'] = array( 'field' => $field, 'after' => (string) $s['watermark'] );
		}
		return $extra;
	}

	/**
	 * Hook for `opex_job_done`. Updates last_run and, on success, the watermark
	 * to the job's start time so rows changed during the run are picked up next time.
	 */
	public static function on_job_done( array $job ): void {
		$sid = (string) ( $job['schedule_id'] ?? '' );
		$s   = '' === $sid ? null : self::get( $sid );
		if ( ! $s ) {
			return;
		}
		$s['last_run'] = array(
			'time'   => (int) ( $job['created'] ?? time() ),
			'job_id' => (string) ( $job['id'] ?? '' ),
			'status' => (string) ( $job['status'] ?? '' ),
			'rows'   => (int) ( $job['processed'] ?? 0 ),
		);
		if ( 'done' === ( $job['status'] ?? '' ) ) {
			$mark = gmdate( 'Y-m-d H:i:s', (int) ( $job['created'] ?? time() ) );
			if ( $mark > (string) $s['watermark'] ) {
				$s['watermark'] = $mark;
			}
		}
		$all = self::all();
		if ( isset( $all[ $sid ] ) ) {
			$all[ $sid ] = $s;
			self::write( $all );
		}
	}
}
