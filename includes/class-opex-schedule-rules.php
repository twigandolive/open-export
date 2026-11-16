<?php
/**
 * What a schedule record holds, and how untrusted input becomes one.
 * OPEX_Schedules stores the records and runs them.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Default schedule records and the checks that turn input into a record.
 */
class OPEX_Schedule_Rules {

	private const FREQUENCIES = array( 'manual', 'hourly', 'every_6_hours', 'twicedaily', 'daily', 'weekly', 'monthly' );

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
			'incremental' => array(
				'enabled' => false,
				'field'   => '',
			),
			'delivery'    => self::default_delivery(),
			'filename'    => '{label}-{date}',
			'trigger_key' => '',
			'last_run'    => array(
				'time'   => 0,
				'job_id' => '',
				'status' => '',
				'rows'   => 0,
			),
			'watermark'   => '',
			'next_run'    => 0,
			'created'     => 0,
			'updated'     => 0,
		);
	}

	/** Delivery block with everything off. */
	private static function default_delivery(): array {
		return array(
			'email'       => array(
				'enabled' => false,
				'to'      => array(),
				'mode'    => 'link',
				'subject' => '',
			),
			'webhook'     => array(
				'enabled' => false,
				'url'     => '',
				'secret'  => '',
			),
			'destination' => array(
				'enabled' => false,
				'id'      => '',
			),
		);
	}

	/**
	 * Validates input against the stored registry and returns the record to store.
	 *
	 * @param array    $all   Stored schedules, filled.
	 * @param array    $raw   Untrusted input.
	 * @param string   $id    Existing schedule id, or '' to create.
	 * @param string[] $notes Receives cleaning notes.
	 * @return array|WP_Error
	 *
	 * @internal Called by OPEX_Schedules::save() only, under the registry lock.
	 */
	public static function build( array $all, array $raw, string $id, array &$notes ) {
		if ( '' !== $id ) {
			if ( ! isset( $all[ $id ] ) ) {
				return new WP_Error( 'opex_missing', __( 'Schedule not found.', 'open-export' ), array( 'status' => 404 ) );
			}
			$base = $all[ $id ];
		} else {
			if ( count( $all ) >= OPEX_Schedules::MAX ) {
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
			$config = OPEX_Exporter::sanitize_config( (array) ( $raw['config'] ?? array() ), $notes );
			if ( is_wp_error( $config ) ) {
				return $config;
			}
			$rec['config'] = $config;
			// Saved by a super admin with a source that reaches shared data: cron may run it in full.
			$src = OPEX_Query::parse_source( (string) $config['source'] );
			if ( $src && OPEX_Access::wide_for( $src ) ) {
				$rec['wide'] = true;
			} else {
				unset( $rec['wide'] );
			}
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

		$inc       = is_array( $raw['incremental'] ?? null ) ? $raw['incremental'] : $base['incremental'];
		$inc_field = sanitize_text_field( (string) ( $inc['field'] ?? '' ) );
		if ( '' !== $inc_field && ! preg_match( '/^(core|meta|tax|computed|col|woo):\S/', $inc_field ) ) {
			return self::invalid( __( 'Unknown date field for incremental exports.', 'open-export' ) );
		}
		$rec['incremental'] = array(
			'enabled' => (bool) rest_sanitize_boolean( $inc['enabled'] ?? false ),
			'field'   => $inc_field,
		);

		$pattern         = sanitize_text_field( (string) ( $raw['filename'] ?? $base['filename'] ) );
		$rec['filename'] = '' === $pattern ? '{label}-{date}' : mb_substr( $pattern, 0, 200 );

		$delivery        = is_array( $raw['delivery'] ?? null ) ? $raw['delivery'] : $base['delivery'];
		$rec['delivery'] = self::sanitize_delivery( $delivery, $base['delivery'], ! empty( $raw['clear_secret'] ), $notes );

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
		$rec['next_run'] = OPEX_Schedules::next_run( $rec, $now );
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
	 * @param array $notes        Receives notes, such as a channel turned off.
	 */
	private static function sanitize_delivery( array $raw, array $base, bool $clear_secret, array &$notes ): array {
		if ( $clear_secret ) {
			$raw['webhook']                 = is_array( $raw['webhook'] ?? null ) ? $raw['webhook'] : array();
			$raw['webhook']['clear_secret'] = true;
			$raw['webhook']['secret']       = '';
		}
		return OPEX_Delivery::sanitize( $raw, $notes, $base );
	}
}
