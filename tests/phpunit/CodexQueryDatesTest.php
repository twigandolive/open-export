<?php
/**
 * Codex round 1 fixes for dates: R1-2.5 (DATE columns) and R1-2.6 (days whose midnight does not exist).
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class CodexQueryDatesTest extends TestCase {

	/** @var string[] */
	private $tables = array();

	/** @var string Timezone before the test. */
	private $timezone = '';

	/** @var mixed UTC offset before the test. */
	private $offset = 0;

	protected function setUp(): void {
		wp_set_current_user( 1 );
		$this->timezone = (string) get_option( 'timezone_string' );
		$this->offset   = get_option( 'gmt_offset' );
	}

	protected function tearDown(): void {
		global $wpdb;
		foreach ( $this->tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		$this->tables = array();
		update_option( 'timezone_string', $this->timezone );
		update_option( 'gmt_offset', $this->offset );
		OPEX_Catalog::reset_cache();
	}

	/** Use a named timezone for the site. */
	private function zone( string $name ): void {
		update_option( 'timezone_string', $name );
	}

	/** A table with DATE columns: one read as site time, one named as UTC. */
	private function date_table(): string {
		global $wpdb;
		$full = $wpdb->prefix . 'opex_b1_dates';
		$wpdb->query( "DROP TABLE IF EXISTS `{$full}`" );
		$wpdb->query( "CREATE TABLE `{$full}` ( id INT NOT NULL PRIMARY KEY, d DATE NULL, d_gmt DATE NULL )" );
		$this->tables[] = $full;
		OPEX_Catalog::reset_cache();
		$rows = array(
			1 => '2026-03-30',
			2 => '2026-03-31',
			3 => '2026-04-01',
			4 => wp_date( 'Y-m-d' ),
			5 => wp_date( 'Y-m-d', time() - DAY_IN_SECONDS * 3 ),
		);
		foreach ( $rows as $id => $day ) {
			$wpdb->insert( $full, array( 'id' => $id, 'd' => $day, 'd_gmt' => $day ) );
		}
		return $full;
	}

	/** Ids a config returns. */
	private function ids( array $raw ): array {
		$notes  = array();
		$config = OPEX_Exporter::sanitize_config( array_merge( array( 'fields' => array( 'col:id' ), 'batch_size' => 50 ), $raw ), $notes );
		$this->assertIsArray( $config, is_wp_error( $config ) ? $config->get_error_message() : '' );
		$since = $raw['since'] ?? null;
		if ( $since ) {
			$config['since'] = $since;
		}
		$ids = array_map( 'intval', array_column( OPEX_Query::batch( $config, 0, 50 )['items'], 'col:id' ) );
		sort( $ids );
		return $ids;
	}

	/** R1-2.5: ranges, presets, rules and since on a DATE column use whole calendar days. */
	public function test_date_columns_use_calendar_days(): void {
		$this->zone( 'America/New_York' );
		$table = $this->date_table();
		$src   = array( 'source' => 'table:' . $table );
		foreach ( array( 'col:d', 'col:d_gmt' ) as $field ) {
			$range = function ( string $from, string $to, string $preset = 'custom' ) use ( $src, $field ): array {
				return $this->ids( $src + array( 'date_range' => array( 'field' => $field, 'preset' => $preset, 'from' => $from, 'to' => $to ) ) );
			};
			$this->assertSame( array( 2 ), $range( '2026-03-31', '2026-03-31' ), "$field one day" );
			$this->assertSame( array( 1, 2 ), $range( '2026-03-30', '2026-03-31' ), "$field two days" );
			$this->assertSame( array( 2, 3, 4, 5 ), $range( '2026-03-31', '' ), "$field open end" );
			$this->assertSame( array( 1, 2 ), $range( '', '2026-03-31' ), "$field open start" );
			$this->assertSame( array( 4 ), $range( '', '', 'today' ), "$field today preset" );

			$rule = function ( string $op, string $value ) use ( $src, $field ): array {
				return $this->ids( $src + array( 'filters' => array( 'rules' => array( array( 'field' => $field, 'op' => $op, 'value' => $value ) ) ) ) );
			};
			$this->assertSame( array( 2 ), $rule( 'eq', '2026-03-31' ), "$field eq" );
			$this->assertSame( array( 1, 3, 4, 5 ), $rule( 'neq', '2026-03-31' ), "$field neq" );
			$this->assertSame( array( 2, 3, 4, 5 ), $rule( 'gte', '2026-03-31' ), "$field gte" );
			$this->assertSame( array( 3, 4, 5 ), $rule( 'gt', '2026-03-31' ), "$field gt" );
			$this->assertSame( array( 1, 2 ), $rule( 'lte', '2026-03-31' ), "$field lte" );
			$this->assertSame( array( 1 ), $rule( 'lt', '2026-03-31' ), "$field lt" );
			$this->assertSame( array( 2 ), $rule( 'eq', '20260331' ), "$field compact date" );

			$since = array( 'field' => $field, 'after' => '2026-03-31 12:00:00' );
			$this->assertSame( array( 3, 4, 5 ), $this->ids( $src + array( 'since' => $since ) ), "$field since" );
		}
	}

	/** R1-2.6: a day whose midnight does not exist starts at its first moment and ends at the next midnight. */
	public function test_days_without_a_midnight(): void {
		$cases = array(
			// Zone, day with no midnight, its first moment, the next midnight.
			array( 'America/Sao_Paulo', '2018-11-04', '2018-11-04 01:00:00', '2018-11-05 00:00:00', '2018-11-05 02:00:00' ),
			array( 'America/Havana', '2023-03-12', '2023-03-12 01:00:00', '2023-03-13 00:00:00', '2023-03-13 04:00:00' ),
		);
		foreach ( $cases as $case ) {
			list( $zone, $day, $first, $next, $next_utc ) = $case;
			$this->zone( $zone );
			$before = gmdate( 'Y-m-d', strtotime( $day . ' -1 day' ) );

			$this->assertSame( $first, OPEX_Dates::boundary( $day, OPEX_Dates::TZ_LOCAL, 0 ), "$zone start" );
			$this->assertSame( $next, OPEX_Dates::boundary( $day, OPEX_Dates::TZ_LOCAL, 1 ), "$zone exclusive end" );
			$this->assertSame( $next_utc, OPEX_Dates::boundary( $day, OPEX_Dates::TZ_UTC, 1 ), "$zone exclusive end in UTC" );
			$this->assertSame( $first, OPEX_Dates::boundary( $before, OPEX_Dates::TZ_LOCAL, 1 ), "$zone end of the day before" );
			$this->assertSame( 23 * HOUR_IN_SECONDS, OPEX_Dates::epoch( $day, 1 ) - OPEX_Dates::epoch( $day, 0 ), "$zone the day is 23 hours long" );
			$this->assertSame( $next, OPEX_Dates::boundary( gmdate( 'Y-m-d', strtotime( $day . ' +1 day' ) ), OPEX_Dates::TZ_LOCAL, 0 ), "$zone next day start" );
		}
	}
}
