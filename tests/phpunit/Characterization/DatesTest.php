<?php
/**
 * Characterization: OPEX_Dates boundaries, parsing and presets.
 *
 * @package Open_Export
 */

require_once __DIR__ . '/CharacterizationCase.php';

/**
 * Pure date helpers with fixed inputs, in UTC and New York.
 */
class DatesTest extends CharacterizationCase {

	/**
	 * Original timezone settings.
	 *
	 * @var array
	 */
	private $saved_tz = array();

	/**
	 * Remember the timezone.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saved_tz = array( get_option( 'timezone_string' ), get_option( 'gmt_offset' ) );
	}

	/**
	 * Restore the timezone.
	 */
	protected function tearDown(): void {
		update_option( 'timezone_string', $this->saved_tz[0] );
		update_option( 'gmt_offset', $this->saved_tz[1] );
		parent::tearDown();
	}

	/**
	 * Set the site timezone.
	 *
	 * @param string $tz Timezone id.
	 */
	private function set_tz( string $tz ): void {
		update_option( 'timezone_string', $tz );
	}

	/**
	 * Run the fixed-input helpers under the current timezone.
	 *
	 * @return array
	 */
	private function sample(): array {
		$out   = array();
		$dates = array( '2026-06-15', '2026-03-08', '2026-11-01', '2026-12-31', '2024-02-29', '2026-02-29', '2026-13-01', '2026-6-5', '', 'garbage', ' 2026-06-15 ', '2026-06-15 10:00' );
		foreach ( $dates as $d ) {
			$out['normalize'][ $d ] = OPEX_Dates::normalize_date( $d );
			foreach ( array( OPEX_Dates::TZ_LOCAL, OPEX_Dates::TZ_UTC ) as $tz ) {
				foreach ( array( 0, 1, -1 ) as $off ) {
					$out['boundary'][ "$d|$tz|$off" ] = OPEX_Dates::boundary( $d, $tz, $off );
				}
			}
			foreach ( array( 0, 1 ) as $off ) {
				$out['epoch'][ "$d|$off" ] = OPEX_Dates::epoch( $d, $off );
			}
		}
		foreach ( array( '2026-06-15 09:30:00', '2026-03-08 02:30:00', '2026-11-01 01:30:00', '', 'nope' ) as $dt ) {
			foreach ( array( OPEX_Dates::TZ_LOCAL, OPEX_Dates::TZ_UTC ) as $tz ) {
				$out['to_column'][ "$dt|$tz" ] = OPEX_Dates::to_column( $dt, $tz );
			}
		}
		foreach ( array( '2026-06-15', '20260615', '2026-06-15 10:00', '2026-06-15T10:00:30', '2026-02-30', '99999999', 'today', '2026-06', '15/06/2026' ) as $v ) {
			foreach ( array( OPEX_Dates::TZ_LOCAL, OPEX_Dates::TZ_UTC ) as $tz ) {
				$out['parse_value'][ "$v|$tz" ] = OPEX_Dates::parse_value( $v, $tz );
			}
		}
		$out['timezone'] = OPEX_Dates::site_timezone()->getName();
		return $out;
	}

	/**
	 * Fixed inputs in a UTC site.
	 */
	public function test_fixed_inputs_utc(): void {
		$this->set_tz( 'UTC' );
		$this->assert_golden_json( 'dates-fixed-utc', $this->sample() );
	}

	/**
	 * Fixed inputs in New York, across both DST changes.
	 */
	public function test_fixed_inputs_new_york(): void {
		$this->set_tz( 'America/New_York' );
		$s = $this->sample();
		$this->assertSame( '2026-06-15 04:00:00', $s['boundary']['2026-06-15|utc|0'] );
		$this->assertSame( '2026-06-15 00:00:00', $s['boundary']['2026-06-15|local|0'] );
		$this->assert_golden_json( 'dates-fixed-new-york', $s );
	}

	/**
	 * A fixed-offset site (no timezone string).
	 */
	public function test_fixed_offset_site(): void {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', '5.5' );
		$this->assertSame( '2026-06-14 18:30:00', OPEX_Dates::boundary( '2026-06-15', OPEX_Dates::TZ_UTC, 0 ) );
		$this->assertSame( '+05:30', OPEX_Dates::site_timezone()->getName() );
	}

	/**
	 * Labels and the preset list.
	 */
	public function test_preset_list_and_labels(): void {
		$labels = array();
		foreach ( OPEX_Dates::PRESETS as $p ) {
			$labels[ $p ] = OPEX_Dates::preset_label( $p );
		}
		$labels['nope'] = OPEX_Dates::preset_label( 'nope' );
		$this->assert_golden_json( 'dates-presets', array( 'presets' => OPEX_Dates::PRESETS, 'labels' => $labels ) );
	}

	/**
	 * Preset ranges relate to today in fixed ways. The "this_*" presets end today, not at the period end.
	 */
	public function test_preset_ranges_relative_to_today(): void {
		$this->set_tz( 'UTC' );
		$today = new DateTimeImmutable( 'today', new DateTimeZone( 'UTC' ) );
		$ymd   = static function ( $d ) {
			return $d->format( 'Y-m-d' );
		};
		$this->assertSame( array( '', '' ), OPEX_Dates::preset_range( 'all_time' ) );
		$this->assertSame( array( '', '' ), OPEX_Dates::preset_range( 'custom' ) );
		$this->assertSame( array( '', '' ), OPEX_Dates::preset_range( 'bogus' ) );
		$this->assertSame( array( $ymd( $today ), $ymd( $today ) ), OPEX_Dates::preset_range( 'today' ) );
		$y = $ymd( $today->modify( '-1 day' ) );
		$this->assertSame( array( $y, $y ), OPEX_Dates::preset_range( 'yesterday' ) );
		$this->assertSame( array( $ymd( $today->modify( '-6 days' ) ), $ymd( $today ) ), OPEX_Dates::preset_range( 'last_7_days' ) );
		$this->assertSame( array( $ymd( $today->modify( '-29 days' ) ), $ymd( $today ) ), OPEX_Dates::preset_range( 'last_30_days' ) );
		$this->assertSame( array( $ymd( $today->modify( '-89 days' ) ), $ymd( $today ) ), OPEX_Dates::preset_range( 'last_90_days' ) );
		$this->assertSame( array( $ymd( $today->modify( 'first day of this month' ) ), $ymd( $today ) ), OPEX_Dates::preset_range( 'this_month' ) );
		$this->assertSame( array( $ymd( $today->modify( 'first day of last month' ) ), $ymd( $today->modify( 'last day of last month' ) ) ), OPEX_Dates::preset_range( 'last_month' ) );
		$this->assertSame( array( $today->format( 'Y' ) . '-01-01', $ymd( $today ) ), OPEX_Dates::preset_range( 'this_year' ) );
		$last = (int) $today->format( 'Y' ) - 1;
		$this->assertSame( array( $last . '-01-01', $last . '-12-31' ), OPEX_Dates::preset_range( 'last_year' ) );
		$q = (int) ceil( (int) $today->format( 'n' ) / 3 );
		$qs = new DateTimeImmutable( $today->format( 'Y' ) . '-' . ( ( $q - 1 ) * 3 + 1 ) . '-01' );
		$this->assertSame( array( $ymd( $qs ), $ymd( $today ) ), OPEX_Dates::preset_range( 'this_quarter' ) );
		$ranges = array();
		foreach ( OPEX_Dates::PRESETS as $p ) {
			$r = OPEX_Dates::preset_range( $p );
			if ( '' !== $r[0] ) {
				$this->assertLessThanOrEqual( $r[1], $r[0], $p );
			}
		}
		$mon = OPEX_Dates::preset_range( 'this_week' );
		$this->assertSame( (int) get_option( 'start_of_week', 1 ), (int) ( new DateTimeImmutable( $mon[0] ) )->format( 'w' ), 'this_week starts on the configured weekday' );
		$this->assertSame( $ymd( $today ), $mon[1], 'this_week runs from the week start to today' );
		$last_week = OPEX_Dates::preset_range( 'last_week' );
		$this->assertSame( ( new DateTimeImmutable( $mon[0] ) )->modify( '-7 days' )->format( 'Y-m-d' ), $last_week[0] );
	}
}
