<?php
/**
 * Numeric filter values are bound as text and cast by the database, never
 * turned into PHP floats, so money and long amounts compare exactly.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class FilterExactNumberTest extends TestCase {

	/** Post created for the test. */
	private int $post = 0;

	protected function tearDown(): void {
		if ( $this->post ) {
			wp_delete_post( $this->post, true );
			$this->post = 0;
		}
	}

	/** Whether the site runs on the SQLite drop-in. */
	private function on_sqlite(): bool {
		global $wpdb;
		return defined( 'SQLITE_DB_DROPIN_VERSION' ) || $wpdb instanceof WP_SQLite_DB;
	}

	public function test_the_value_is_bound_as_text_and_cast_in_sql() {
		$src  = OPEX_Query::parse_source( 'posts:post' );
		$args = array();
		$sql  = OPEX_Query_Filters::rule_clause(
			$src,
			array(
				'field' => 'core:ID',
				'op'    => 'gte',
				'value' => '12.10',
			),
			$args
		);

		$this->assertStringContainsString( 'CAST(%s AS DECIMAL(24,6))', $sql );
		$this->assertStringNotContainsString( '%f', $sql );
		$this->assertSame( array( '12.10' ), $args );
	}

	/**
	 * Counts the test post when the rule matches it.
	 *
	 * @param string $op    Operator.
	 * @param string $value Filter value.
	 */
	private function count_matches( string $op, string $value ): int {
		$config = OPEX_Exporter::sanitize_config(
			array(
				'source'  => 'posts:post',
				'fields'  => array( 'core:ID' ),
				'filters' => array(
					'relation' => 'AND',
					'rules'    => array(
						array(
							'field' => 'core:ID',
							'op'    => 'eq',
							'value' => (string) $this->post,
						),
						array(
							'field' => 'meta:opex_exact_amount',
							'op'    => $op,
							'value' => $value,
						),
					),
				),
			)
		);
		$this->assertIsArray( $config );
		return (int) OPEX_Query::count( $config );
	}

	/** Adds the post with a 17-digit amount. */
	private function make_post(): void {
		$this->post = (int) wp_insert_post(
			array(
				'post_title'  => 'Exact amount',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $this->post, 'opex_exact_amount', '12345678901234567.89' );
	}

	public function test_an_amount_matches_itself_and_its_bounds() {
		$this->make_post();

		$this->assertSame( 1, $this->count_matches( 'gte', '12345678901234567.89' ) );
		$this->assertSame( 1, $this->count_matches( 'lte', '12345678901234567.89' ) );
		$this->assertSame( 0, $this->count_matches( 'gt', '12345678901234567.89' ) );
		$this->assertSame( 1, $this->count_matches( 'gt', '0.1' ) );
	}

	public function test_long_amounts_compare_beyond_float_precision() {
		if ( $this->on_sqlite() ) {
			$this->markTestSkipped( 'SQLite keeps DECIMAL as a floating-point number, so this runs on the MariaDB lane.' );
		}
		$this->make_post();

		// A float turns ...567.88 into ...568, which would wrongly match.
		$this->assertSame( 0, $this->count_matches( 'lte', '12345678901234567.88' ) );
		$this->assertSame( 1, $this->count_matches( 'gt', '12345678901234567.88' ) );
	}
}
