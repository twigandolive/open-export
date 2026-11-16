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

	/**
	 * Default values for every key of a schedule record.
	 *
	 * @return array A schedule record with every key at its default.
	 */
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
			// Added in 2.0.0. Changes when the watermark is reset or its meaning
			// changes, so a run started before that cannot set the watermark again.
			'generation'  => '',
		);
	}

	/**
	 * Delivery block with everything off.
	 *
	 * @return array The delivery settings with every channel off.
	 */
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
				return new WP_Error( 'opex_missing', __( 'That schedule does not exist.', 'open-export' ), array( 'status' => 404 ) );
			}
			$base = $all[ $id ];
		} else {
			if ( count( $all ) >= OPEX_Schedules::MAX ) {
				return new WP_Error( 'opex_limit', __( 'You can have at most 50 schedules. Delete one to add another.', 'open-export' ), array( 'status' => 400 ) );
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
			return self::invalid( __( 'Open Export does not know this frequency.', 'open-export' ) );
		}
		$rec['frequency'] = $freq;

		$time = (string) ( $raw['time'] ?? $base['time'] );
		if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			return self::invalid( __( 'Enter a time such as 14:30.', 'open-export' ) );
		}
		$rec['time'] = $time;

		$weekday = $raw['weekday'] ?? $base['weekday'];
		if ( ! is_numeric( $weekday ) || (int) $weekday < 0 || (int) $weekday > 6 ) {
			return self::invalid( __( 'The weekday must be a number from 0 to 6.', 'open-export' ) );
		}
		$rec['weekday'] = (int) $weekday;

		$monthday = $raw['monthday'] ?? $base['monthday'];
		if ( 'last' === $monthday ) {
			$rec['monthday'] = 'last';
		} elseif ( is_numeric( $monthday ) && (int) $monthday >= 1 && (int) $monthday <= 28 ) {
			$rec['monthday'] = (int) $monthday;
		} else {
			return self::invalid( __( 'The day of the month must be a number from 1 to 28, or last.', 'open-export' ) );
		}

		$rec['enabled'] = isset( $raw['enabled'] ) ? (bool) rest_sanitize_boolean( $raw['enabled'] ) : (bool) $base['enabled'];

		$inc       = is_array( $raw['incremental'] ?? null ) ? $raw['incremental'] : $base['incremental'];
		$inc_field = sanitize_text_field( (string) ( $inc['field'] ?? '' ) );
		if ( '' !== $inc_field && ! preg_match( '/^(core|meta|tax|computed|col|woo):\S/', $inc_field ) ) {
			return self::invalid( __( 'The Changes only date field is not a field id Open Export knows.', 'open-export' ) );
		}
		$rec['incremental'] = array(
			'enabled' => (bool) rest_sanitize_boolean( $inc['enabled'] ?? false ),
			'field'   => $inc_field,
		);
		// Checked when the source or the field may have changed. A record saved
		// before this check keeps working until one of them is edited.
		$touched = '' === $id || isset( $raw['config'] ) || is_array( $raw['incremental'] ?? null );
		if ( $rec['incremental']['enabled'] && $touched && ! self::is_date_field( (string) ( $rec['config']['source'] ?? '' ), $inc_field ) ) {
			return self::invalid( __( 'Changes only needs one of the date fields of this source. Choose one, or turn Changes only off.', 'open-export' ) );
		}
		self::follow_watermark( $rec, $base, '' !== $id, $notes );

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

	/**
	 * Whether a field is one of the date fields of a source.
	 *
	 * @param string $source Source id.
	 * @param string $field  Field id.
	 * @return bool True when the source has that date field.
	 */
	private static function is_date_field( string $source, string $field ): bool {
		$src = OPEX_Query::parse_source( $source );
		return '' !== $field && null !== $src && isset( OPEX_Query::date_fields( $src )[ $field ] );
	}

	/**
	 * A watermark belongs to one source and one date field. When an edit
	 * changes either, the watermark is cleared, so the next run exports every
	 * row of what is now asked for, and the generation changes, so a run that
	 * started before the edit cannot set the watermark again when it ends.
	 *
	 * @param array    $rec    Record being built, changed in place.
	 * @param array    $base   Record as stored before the edit.
	 * @param bool     $update True when an existing schedule is edited.
	 * @param string[] $notes  Receives a note when a watermark was cleared.
	 * @return void
	 */
	private static function follow_watermark( array &$rec, array $base, bool $update, array &$notes ): void {
		$source = (string) ( $rec['config']['source'] ?? '' ) !== (string) ( $base['config']['source'] ?? '' );
		$field  = (string) ( $base['incremental']['field'] ?? '' ) !== (string) $rec['incremental']['field'];
		if ( ! $update || ( ! $source && ! $field ) ) {
			return;
		}
		if ( '' !== (string) $rec['watermark'] ) {
			$rec['watermark'] = '';
			$notes[]          = __( 'The source or the Changes only date field changed, so Open Export forgot where the last run stopped. The next run exports every row.', 'open-export' );
		}
		$rec['generation'] = self::new_generation();
	}

	/**
	 * A new generation id for a schedule's watermark.
	 *
	 * @return string A short random id.
	 *
	 * @internal Shared with OPEX_Schedules only.
	 */
	public static function new_generation(): string {
		return 'g' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 12 );
	}

	/**
	 * Build a 400 error.
	 *
	 * @param string $message What is wrong, for the person who sent it.
	 * @return WP_Error The error to return.
	 */
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
	 * @return array The clean delivery settings.
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
