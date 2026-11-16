<?php
/**
 * Characterization: date range presets and custom ranges on posts and users.
 *
 * @package Open_Export
 */

require_once __DIR__ . '/CharacterizationCase.php';

/**
 * Date ranges are half-open intervals, so the end day is inclusive.
 */
class DateRangesTest extends CharacterizationCase {

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
	 * Count rows in a range.
	 *
	 * @param string $source Source.
	 * @param string $field  Date field id.
	 * @param string $preset Preset.
	 * @param string $from   From.
	 * @param string $to     To.
	 * @param array  $rules  Extra filter rules.
	 * @return int
	 */
	private function in_range( string $source, string $field, string $preset, string $from = '', string $to = '', array $rules = array() ): int {
		return $this->count_for( $source, $rules, 'AND', array( 'field' => $field, 'preset' => $preset, 'from' => $from, 'to' => $to ) );
	}

	/**
	 * Custom ranges on seeded posts: one post per day at 12:00.
	 */
	public function test_custom_range_posts(): void {
		$this->assertSame( 10, $this->in_range( 'posts:post', 'core:post_date', 'custom', '2026-06-01', '2026-06-10' ) );
		$this->assertSame( 1, $this->in_range( 'posts:post', 'core:post_date', 'custom', '2026-06-10', '2026-06-10' ), 'a one-day range includes that day' );
		$this->assertSame( 9, $this->in_range( 'posts:post', 'core:post_date', 'custom', '2026-06-01', '2026-06-09' ) );
		$this->assertSame( 10, $this->in_range( 'posts:post', 'core:post_date', 'custom', '2026-06-10', '2026-06-01' ), 'from and to are swapped when reversed' );
		$this->assertSame( 60, $this->in_range( 'posts:post', 'core:post_date', 'custom', '2000-01-01', '2100-01-01' ) );
		$this->assertSame( 15, $this->in_range( 'posts:post', 'core:post_date', 'custom', '2026-06-15', '' ), 'open end' );
		$this->assertSame( 46, $this->in_range( 'posts:post', 'core:post_date', 'custom', '', '2026-06-15' ), 'open start' );
		$this->assertSame( 60, $this->in_range( 'posts:post', 'core:post_date', 'custom', '', '' ), 'both ends empty means no range' );
		$this->assertSame( 60, $this->in_range( 'posts:post', 'core:post_date', 'custom', 'bad', 'worse' ), 'invalid dates are ignored' );
		$this->assertSame( 10, $this->in_range( 'posts:post', 'core:post_date_gmt', 'custom', '2026-06-01', '2026-06-10' ) );
		$this->assertSame( 60, $this->in_range( 'posts:post', 'core:post_modified', 'custom', '2026-01-01', '2026-12-31' ) );
		$this->assertSame( 1, $this->in_range( 'posts:post', 'core:post_date', 'custom', '2026-06-10', '2026-06-10', array( array( 'field' => 'meta:opex_rating', 'op' => 'gte', 'value' => '0' ) ) ), 'the range is its own AND term' );
	}

	/**
	 * OR filters never widen a date range.
	 */
	public function test_or_filter_cannot_widen_range(): void {
		$n = $this->count_for(
			'posts:post',
			array( array( 'field' => 'core:ID', 'op' => 'gt', 'value' => '0' ), array( 'field' => 'core:post_title', 'op' => 'eq', 'value' => 'x' ) ),
			'OR',
			array( 'field' => 'core:post_date', 'preset' => 'custom', 'from' => '2026-06-10', 'to' => '2026-06-10' )
		);
		$this->assertSame( 1, $n );
	}

	/**
	 * How sanitize_config normalizes a date range.
	 */
	public function test_date_range_sanitizing(): void {
		$cases = array(
			'custom ok'        => array( 'core:post_date', 'custom', '2026-06-01', '2026-06-10' ),
			'swapped'          => array( 'core:post_date', 'custom', '2026-06-10', '2026-06-01' ),
			'unknown preset'   => array( 'core:post_date', 'forever', '2026-06-01', '2026-06-10' ),
			'all_time'         => array( 'core:post_date', 'all_time', '2026-06-01', '2026-06-10' ),
			'unknown field'    => array( 'core:nope', 'custom', '2026-06-01', '2026-06-10' ),
			'bad dates'        => array( 'core:post_date', 'custom', 'x', 'y' ),
			'one bad date'     => array( 'core:post_date', 'custom', '2026-02-30', '2026-06-10' ),
			'preset keeps no dates' => array( 'core:post_date', 'last_7_days', '2026-06-01', '2026-06-10' ),
			'no field'         => array( '', 'custom', '2026-06-01', '2026-06-10' ),
		);
		$out = array();
		foreach ( $cases as $name => $c ) {
			$config       = $this->config( array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'date_range' => array( 'field' => $c[0], 'preset' => $c[1], 'from' => $c[2], 'to' => $c[3] ) ) );
			$out[ $name ] = $config['date_range'];
		}
		$config = $this->config( array( 'source' => 'terms:category', 'fields' => array( 'core:term_id' ), 'date_range' => array( 'field' => 'core:post_date', 'preset' => 'custom', 'from' => '2026-06-01', 'to' => '2026-06-10' ) ) );
		$out['source without date fields'] = $config['date_range'];
		$this->assert_golden_json( 'dateranges-sanitize', $out );
	}

	/**
	 * Presets freeze to concrete dates at job creation but keep their name.
	 */
	public function test_freeze_date_range(): void {
		$config = $this->config( array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'date_range' => array( 'field' => 'core:post_date', 'preset' => 'last_7_days' ) ) );
		$frozen = OPEX_Exporter::freeze_date_range( $config );
		$range  = OPEX_Dates::preset_range( 'last_7_days' );
		$this->assertSame( 'last_7_days', $frozen['date_range']['preset'] );
		$this->assertSame( $range[0], $frozen['date_range']['from'] );
		$this->assertSame( $range[1], $frozen['date_range']['to'] );
		$custom = $this->config( array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'date_range' => array( 'field' => 'core:post_date', 'preset' => 'custom', 'from' => '2026-06-01', 'to' => '2026-06-10' ) ) );
		$this->assertSame( $custom, OPEX_Exporter::freeze_date_range( $custom ) );
	}

	/**
	 * Every relative preset on posts created at known ages.
	 */
	public function test_presets_on_posts(): void {
		$ages  = array( 0, 1.5, 3.5, 6.5, 12.5, 25.5, 60.5, 120.5, 200.5, 400.5, 800.5 );
		$dates = array();
		foreach ( $ages as $d ) {
			$id      = $this->temp_post( "range $d", $d, array( 'post_excerpt' => 'opexrange' ) );
			$dates[] = substr( get_post_field( 'post_date', $id ), 0, 10 );
		}
		$tz   = OPEX_Dates::site_timezone()->getName();
		$base = array( array( 'field' => 'core:post_excerpt', 'op' => 'eq', 'value' => 'opexrange' ) );
		$got  = array();
		foreach ( OPEX_Dates::PRESETS as $preset ) {
			if ( 'custom' === $preset ) {
				continue;
			}
			$n    = $this->in_range( 'posts:post', 'core:post_date', $preset, '', '', $base );
			$r    = OPEX_Dates::preset_range( $preset );
			$want = 0;
			foreach ( $dates as $day ) {
				if ( ( '' === $r[0] || $day >= $r[0] ) && ( '' === $r[1] || $day <= $r[1] ) ) {
					++$want;
				}
			}
			$this->assertSame( $want, $n, "preset $preset ($tz)" );
			$got[ $preset ] = $n > 0;
		}
		$this->assertSame( 11, $this->in_range( 'posts:post', 'core:post_date', 'all_time', '', '', $base ) );
		$this->assertTrue( $got['today'] );
	}

	/**
	 * Users: the range compares user_registered (stored in UTC).
	 */
	public function test_custom_range_users(): void {
		$this->single_site_only( 'the users table is shared by every site of the network.' );
		global $wpdb;
		$want = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_registered >= '2026-03-01 00:00:00' AND user_registered < '2026-04-01 00:00:00'" );
		$this->assertGreaterThan( 0, $want );
		$this->assertSame( $want, $this->in_range( 'users', 'core:user_registered', 'custom', '2026-03-01', '2026-03-31' ) );
		$this->assertSame( 1, $this->in_range( 'users', 'core:user_registered', 'custom', '2026-03-21', '2026-03-21' ) );
		$this->assertSame( 26, $this->in_range( 'users', 'core:user_registered', 'custom', '2020-01-01', '2030-01-01' ) );
	}

	/**
	 * Comments: both comment date columns.
	 */
	public function test_custom_range_comments(): void {
		global $wpdb;
		$want = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_date >= '2026-06-01 00:00:00' AND comment_date < '2026-06-11 00:00:00'" );
		$this->assertSame( $want, $this->in_range( 'comments', 'core:comment_date', 'custom', '2026-06-01', '2026-06-10' ) );
		$this->assertSame( $want, $this->in_range( 'comments', 'core:comment_date_gmt', 'custom', '2026-06-01', '2026-06-10' ) );
	}

	/**
	 * A raw table range uses its datetime columns.
	 */
	public function test_custom_range_raw_table(): void {
		global $wpdb;
		$source = 'table:' . $wpdb->posts;
		$n      = $this->in_range( $source, 'col:post_date', 'custom', '2026-06-01', '2026-06-10', array( array( 'field' => 'col:post_type', 'op' => 'eq', 'value' => 'post' ) ) );
		$this->assertSame( 10, $n );
		$gmt = $this->in_range( $source, 'col:post_date_gmt', 'custom', '2026-06-01', '2026-06-10', array( array( 'field' => 'col:post_type', 'op' => 'eq', 'value' => 'post' ) ) );
		$this->assertSame( 10, $gmt );
	}

	/**
	 * A New York site: local columns use local days, gmt columns shift by the offset.
	 */
	public function test_custom_range_in_new_york(): void {
		update_option( 'timezone_string', 'America/New_York' );
		$out = array();
		foreach ( array( 'core:post_date', 'core:post_date_gmt' ) as $field ) {
			foreach ( array( array( '2026-06-10', '2026-06-10' ), array( '2026-06-01', '2026-06-10' ), array( '2026-06-29', '2026-06-29' ) ) as $r ) {
				$out[ "$field {$r[0]}..{$r[1]}" ] = $this->in_range( 'posts:post', $field, 'custom', $r[0], $r[1] );
			}
		}
		$out['users'] = $this->in_range( 'users', 'core:user_registered', 'custom', '2026-03-21', '2026-03-21' );
		$this->assert_golden_json( 'dateranges-new-york', $out );
	}
}
