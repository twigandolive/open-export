<?php
/**
 * Declarative value transforms for Open Export.
 *
 * Fixed step types only: no user code, no user regular expressions, no
 * expressions. Custom (template) columns live in OPEX_Custom_Columns.
 *
 * @package OpenExport
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Sanitizes and applies transform steps and custom columns.
 */
class OPEX_Transforms {

	const MAX_STEPS    = 8;
	const MAX_STRING   = 500;
	const MAX_PAIRS    = 200;
	const MAX_PAIR_LEN = 200;
	const MAX_DATE_FMT = 40;
	const FIELD_ID     = '/^(core|meta|tax|computed|col|woo|acf|custom):\S/';

	/**
	 * Step types, for the UI and the schema document.
	 *
	 * @return array<string,array> Type => array( label, args ).
	 */
	public static function types(): array {
		$text = array(
			'type' => 'text',
			'max'  => self::MAX_STRING,
		);
		return array(
			'trim'         => array(
				'label' => __( 'Trim spaces', 'open-export' ),
				'args'  => array(),
			),
			'case'         => array(
				'label' => __( 'Change case', 'open-export' ),
				'args'  => array(
					'mode' => array(
						'type'    => 'select',
						'label'   => __( 'Case', 'open-export' ),
						'options' => array(
							'upper' => __( 'UPPERCASE', 'open-export' ),
							'lower' => __( 'lowercase', 'open-export' ),
							'title' => __( 'Title Case', 'open-export' ),
						),
					),
				),
			),
			'prefix'       => array(
				'label' => __( 'Add text before', 'open-export' ),
				'args'  => array( 'text' => $text + array( 'label' => __( 'Text', 'open-export' ) ) ),
			),
			'suffix'       => array(
				'label' => __( 'Add text after', 'open-export' ),
				'args'  => array( 'text' => $text + array( 'label' => __( 'Text', 'open-export' ) ) ),
			),
			'find_replace' => array(
				'label' => __( 'Find and replace', 'open-export' ),
				'args'  => array(
					'find'    => $text + array( 'label' => __( 'Find', 'open-export' ) ),
					'replace' => $text + array( 'label' => __( 'Replace with', 'open-export' ) ),
				),
			),
			'date_format'  => array(
				'label' => __( 'Format a date', 'open-export' ),
				'args'  => array(
					'format' => array(
						'type'  => 'text',
						'max'   => self::MAX_DATE_FMT,
						'label' => __( 'PHP date format', 'open-export' ),
					),
				),
			),
			'number'       => array(
				'label' => __( 'Format a number', 'open-export' ),
				'args'  => array(
					'decimals'      => array(
						'type'  => 'int',
						'min'   => 0,
						'max'   => 6,
						'label' => __( 'Decimals', 'open-export' ),
					),
					'dec_point'     => $text + array( 'label' => __( 'Decimal point', 'open-export' ) ),
					'thousands_sep' => $text + array( 'label' => __( 'Thousands separator', 'open-export' ) ),
				),
			),
			'map'          => array(
				'label' => __( 'Map values', 'open-export' ),
				'args'  => array(
					'pairs'    => array(
						'type'  => 'map',
						'max'   => self::MAX_PAIRS,
						'label' => __( 'Value pairs', 'open-export' ),
					),
					'fallback' => $text + array(
						'label'    => __( 'Otherwise use', 'open-export' ),
						'optional' => true,
					),
				),
			),
			'default'      => array(
				'label' => __( 'Fill in when empty', 'open-export' ),
				'args'  => array( 'text' => $text + array( 'label' => __( 'Text', 'open-export' ) ) ),
			),
			'strip_html'   => array(
				'label' => __( 'Remove HTML', 'open-export' ),
				'args'  => array(),
			),
			'truncate'     => array(
				'label' => __( 'Shorten', 'open-export' ),
				'args'  => array(
					'length' => array(
						'type'  => 'int',
						'min'   => 1,
						'max'   => 10000,
						'label' => __( 'Characters', 'open-export' ),
					),
				),
			),
		);
	}

	/**
	 * Clean the per-field step lists.
	 *
	 * @param mixed    $raw             Raw map of field id => list of steps.
	 * @param string[] $selected_fields Field ids that are in the export.
	 * @param string[] $notes           Receives plain-language notes.
	 * @return array<string,array[]> Field id => clean steps. Fields with no steps are left out.
	 */
	public static function sanitize( $raw, array $selected_fields, array &$notes ): array {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		$selected = array_flip( array_map( 'strval', $selected_fields ) );
		foreach ( $raw as $field => $steps ) {
			$field = (string) $field;
			if ( ! preg_match( self::FIELD_ID, $field ) || ! isset( $selected[ $field ] ) || ! is_array( $steps ) ) {
				continue;
			}
			$clean = array();
			foreach ( array_values( $steps ) as $step ) {
				if ( count( $clean ) >= self::MAX_STEPS ) {
					$notes[] = sprintf(
						/* translators: %s: field id. */
						__( 'Field "%s" keeps its first 8 steps, the rest were dropped.', 'open-export' ),
						$field
					);
					break;
				}
				$one = self::sanitize_step( $step, $field, $notes );
				if ( $one ) {
					$clean[] = $one;
				}
			}
			if ( $clean ) {
				$out[ $field ] = $clean;
			}
		}
		return $out;
	}

	/**
	 * Clean one step, or return an empty array to drop it.
	 *
	 * @param mixed    $step  Raw step.
	 * @param string   $field Field id, for notes.
	 * @param string[] $notes Notes.
	 * @return array
	 */
	private static function sanitize_step( $step, string $field, array &$notes ): array {
		$type = is_array( $step ) && isset( $step['type'] ) && is_string( $step['type'] ) ? $step['type'] : '';
		if ( '' === $type || ! array_key_exists( $type, self::types() ) ) {
			$notes[] = sprintf(
				/* translators: %s: field id. */
				__( 'A step on "%s" was dropped: it is not a known step type.', 'open-export' ),
				$field
			);
			return array();
		}
		$out = array( 'type' => $type );
		switch ( $type ) {
			case 'case':
				$mode = isset( $step['mode'] ) && is_string( $step['mode'] ) ? $step['mode'] : '';
				if ( ! in_array( $mode, array( 'upper', 'lower', 'title' ), true ) ) {
					return self::drop( $type, $field, $notes );
				}
				$out['mode'] = $mode;
				break;
			case 'prefix':
			case 'suffix':
			case 'default':
				$out['text'] = self::clean_string( $step['text'] ?? '' );
				break;
			case 'find_replace':
				$out['find'] = self::clean_string( $step['find'] ?? '' );
				if ( '' === $out['find'] ) {
					return self::drop( $type, $field, $notes );
				}
				$out['replace'] = self::clean_string( $step['replace'] ?? '' );
				break;
			case 'date_format':
				$format = self::clean_string( $step['format'] ?? '', self::MAX_DATE_FMT );
				if ( '' === $format || ! preg_match( '/^[dDjlNSwzWFmMntLoYyaABgGhHisuveIOPpTZcrU\\\\ \-\/.,:;()#@_\[\]]+$/', $format ) ) {
					return self::drop( $type, $field, $notes );
				}
				$out['format'] = $format;
				break;
			case 'number':
				$out['decimals']      = max( 0, min( 6, (int) ( is_scalar( $step['decimals'] ?? 0 ) ? $step['decimals'] : 0 ) ) );
				$out['dec_point']     = isset( $step['dec_point'] ) ? self::clean_string( $step['dec_point'] ) : '.';
				$out['thousands_sep'] = isset( $step['thousands_sep'] ) ? self::clean_string( $step['thousands_sep'] ) : ',';
				break;
			case 'map':
				$out['pairs'] = self::clean_pairs( $step['pairs'] ?? array() );
				if ( isset( $step['fallback'] ) && is_scalar( $step['fallback'] ) ) {
					$out['fallback'] = self::clean_string( $step['fallback'] );
				}
				break;
			case 'truncate':
				$length        = is_scalar( $step['length'] ?? 100 ) ? (int) $step['length'] : 100;
				$out['length'] = max( 1, min( 10000, $length ) );
				break;
		}
		return $out;
	}

	/**
	 * Note a dropped step and return the drop marker.
	 *
	 * @param string   $type  Step type.
	 * @param string   $field Field id.
	 * @param string[] $notes Notes.
	 * @return array Always empty.
	 */
	private static function drop( string $type, string $field, array &$notes ): array {
		$notes[] = sprintf(
			/* translators: 1: step type, 2: field id. */
			__( 'The "%1$s" step on "%2$s" was dropped: its settings are not valid.', 'open-export' ),
			$type,
			$field
		);
		return array();
	}

	/**
	 * Clean map pairs: strings, capped, at most 200.
	 *
	 * @param mixed $pairs Raw pairs.
	 * @return array<string,string>
	 */
	private static function clean_pairs( $pairs ): array {
		$out = array();
		if ( ! is_array( $pairs ) ) {
			return $out;
		}
		foreach ( $pairs as $from => $to ) {
			if ( count( $out ) >= self::MAX_PAIRS ) {
				break;
			}
			if ( is_scalar( $to ) ) {
				$out[ self::clean_string( (string) $from, self::MAX_PAIR_LEN ) ] = self::clean_string( $to, self::MAX_PAIR_LEN );
			}
		}
		return $out;
	}

	/**
	 * Make a string safe to store: valid UTF-8, no control characters, capped.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Maximum characters.
	 * @return string
	 */
	public static function clean_string( $value, int $max = self::MAX_STRING ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = wp_check_invalid_utf8( (string) $value, true );
		$value = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
		return mb_substr( $value, 0, $max );
	}

	/**
	 * Clean the custom column list. See OPEX_Custom_Columns::sanitize().
	 *
	 * @param mixed    $raw            Raw list.
	 * @param callable $is_valid_field Receives a field id, returns bool.
	 * @param string[] $notes          Notes.
	 * @return array[]
	 */
	public static function sanitize_custom_columns( $raw, callable $is_valid_field, array &$notes ): array {
		return OPEX_Custom_Columns::sanitize( $raw, $is_valid_field, $notes );
	}

	/**
	 * Field ids named in custom column templates.
	 *
	 * @param array[] $custom_columns Clean custom columns.
	 * @return string[]
	 */
	public static function referenced_fields( array $custom_columns ): array {
		return OPEX_Custom_Columns::referenced( $custom_columns );
	}

	/**
	 * Build output rows: one key per config field, in order.
	 *
	 * Templates read the original source values, before any step runs.
	 *
	 * @param array $rows   Rows keyed by field id.
	 * @param array $config Export config.
	 * @return array[]
	 */
	public static function apply( array $rows, array $config ): array {
		$fields    = array_map( 'strval', (array) ( $config['fields'] ?? array() ) );
		$templates = OPEX_Custom_Columns::compile( (array) ( $config['custom_columns'] ?? array() ) );
		$plans     = self::prepare( (array) ( $config['transforms'] ?? array() ), $fields );

		$out = array();
		foreach ( $rows as $row ) {
			$row    = (array) $row;
			$result = array();
			foreach ( $fields as $field ) {
				if ( isset( $templates[ $field ] ) ) {
					$value = OPEX_Custom_Columns::render( $templates[ $field ], $row );
				} elseif ( isset( $row[ $field ] ) && is_scalar( $row[ $field ] ) ) {
					$value = (string) $row[ $field ];
				} else {
					$value = '';
				}
				if ( isset( $plans[ $field ] ) ) {
					foreach ( $plans[ $field ] as $step ) {
						$value = self::run( $step, $value );
					}
				}
				$result[ $field ] = $value;
			}
			$out[] = $result;
		}
		return $out;
	}

	/**
	 * Resolve the step lists once per call.
	 *
	 * @param array    $transforms Field id => steps.
	 * @param string[] $fields     Output fields.
	 * @return array<string,array[]>
	 */
	private static function prepare( array $transforms, array $fields ): array {
		$plans = array();
		$tz    = null;
		foreach ( $fields as $field ) {
			if ( empty( $transforms[ $field ] ) || ! is_array( $transforms[ $field ] ) ) {
				continue;
			}
			foreach ( $transforms[ $field ] as $step ) {
				if ( ! is_array( $step ) || ! isset( $step['type'] ) ) {
					continue;
				}
				if ( 'date_format' === $step['type'] ) {
					$tz         = $tz ? $tz : wp_timezone();
					$step['tz'] = $tz;
				}
				$plans[ $field ][] = $step;
			}
		}
		return $plans;
	}

	/**
	 * Run one prepared step on one value.
	 *
	 * @param array  $s Step.
	 * @param string $v Value.
	 * @return string
	 */
	private static function run( array $s, string $v ): string {
		switch ( $s['type'] ) {
			case 'trim':
				$trimmed = preg_replace( '/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $v );
				return null === $trimmed ? trim( $v ) : $trimmed;
			case 'case':
				if ( 'upper' === $s['mode'] ) {
					return mb_strtoupper( $v );
				}
				return 'lower' === $s['mode'] ? mb_strtolower( $v ) : mb_convert_case( $v, MB_CASE_TITLE );
			case 'prefix':
				return '' === $v ? $v : (string) $s['text'] . $v;
			case 'suffix':
				return '' === $v ? $v : $v . (string) $s['text'];
			case 'find_replace':
				return '' === (string) $s['find'] ? $v : str_replace( (string) $s['find'], (string) ( $s['replace'] ?? '' ), $v );
			case 'date_format':
				return self::format_date( $v, (string) $s['format'], $s['tz'] );
			case 'number':
				return self::format_number( $v, (int) $s['decimals'], (string) $s['dec_point'], (string) $s['thousands_sep'] );
			case 'map':
				if ( array_key_exists( $v, (array) $s['pairs'] ) ) {
					return (string) $s['pairs'][ $v ];
				}
				return isset( $s['fallback'] ) ? (string) $s['fallback'] : $v;
			case 'default':
				return '' === $v ? (string) $s['text'] : $v;
			case 'strip_html':
				return html_entity_decode( wp_strip_all_tags( $v ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			case 'truncate':
				return mb_substr( $v, 0, (int) $s['length'] );
		}
		return $v;
	}

	/**
	 * Format a plain decimal number with string math, never floats, so a price
	 * or a 20-digit id keeps every digit. Rounds half away from zero at
	 * $decimals (OPEX_Money), then groups the whole part by thousands.
	 * Anything that is not a plain decimal number is returned unchanged.
	 *
	 * @param string $v             Value.
	 * @param int    $decimals      Places, 0 to 6.
	 * @param string $dec_point     Decimal point.
	 * @param string $thousands_sep Thousands separator.
	 * @return string
	 */
	private static function format_number( string $v, int $decimals, string $dec_point, string $thousands_sep ): string {
		$text = trim( $v );
		if ( 1 !== preg_match( '/^[+-]?(\d+|\d*\.\d+)$/', $text ) ) {
			return $v;
		}
		$rounded  = OPEX_Money::format( $text, max( 0, min( 6, $decimals ) ) );
		$negative = '-' === $rounded[0];
		$parts    = explode( '.', ltrim( $rounded, '-' ), 2 );
		$whole    = $parts[0];
		if ( '' !== $thousands_sep && strlen( $whole ) > 3 ) {
			$head   = strlen( $whole ) % 3;
			$groups = $head ? array( substr( $whole, 0, $head ) ) : array();
			$whole  = implode( $thousands_sep, array_merge( $groups, str_split( substr( $whole, $head ), 3 ) ) );
		}
		$out = ( $negative ? '-' : '' ) . $whole;
		return isset( $parts[1] ) ? $out . $dec_point . $parts[1] : $out;
	}

	/**
	 * Reformat a date value in the site timezone. Unparseable input is returned as is.
	 *
	 * @param string       $v      Value.
	 * @param string       $format PHP date format.
	 * @param DateTimeZone $tz     Site timezone.
	 * @return string
	 */
	private static function format_date( string $v, string $format, DateTimeZone $tz ): string {
		$text = trim( $v );
		try {
			if ( preg_match( '/^\d{9,11}$/', $text ) ) {
				$ts = (int) $text;
			} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}:\d{2}|T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,9})?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/', $text ) ) {
				$date   = new DateTimeImmutable( $text, $tz );
				$errors = DateTimeImmutable::getLastErrors();
				if ( $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) {
					return $v;
				}
				$ts = $date->getTimestamp();
			} else {
				return $v;
			}
		} catch ( Exception $e ) {
			return $v;
		}
		$formatted = wp_date( $format, $ts, $tz );
		return is_string( $formatted ) ? $formatted : $v;
	}
}
