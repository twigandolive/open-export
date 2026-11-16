<?php
/**
 * Tests for the note a config gets when its date range is dropped.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * The "no date column" note must only appear when it is true.
 */
class ConfigDateNoteTest extends TestCase {

	/**
	 * The note text, as the config adds it.
	 *
	 * @return string The translated note.
	 */
	private function note(): string {
		return __( 'Open Export dropped the date range because this source has no date column to filter on.', 'open-export' );
	}

	/**
	 * A custom range with both dates empty on a source that has date columns
	 * means "all time", and says nothing about missing date columns.
	 *
	 * @return void
	 */
	public function test_empty_custom_range_on_a_dated_source_gets_no_note(): void {
		$notes  = array();
		$config = OPEX_Exporter::sanitize_config(
			array(
				'source'     => 'posts:post',
				'fields'     => array( 'core:ID' ),
				'date_range' => array(
					'field'  => 'core:post_date',
					'preset' => 'custom',
					'from'   => '',
					'to'     => '',
				),
			),
			$notes
		);

		$this->assertIsArray( $config );
		$this->assertSame( '', $config['date_range']['field'] ?? '' );
		$this->assertNotContains( $this->note(), $notes );
	}

	/**
	 * A source with no date column still gets the note when a range is asked for.
	 *
	 * @return void
	 */
	public function test_range_on_a_source_without_dates_gets_the_note(): void {
		$src = OPEX_Query::parse_source( 'terms:category' );
		$this->assertSame( '', OPEX_Query::date_field_or_default( $src, '' ), 'categories have no date column' );

		$notes  = array();
		$config = OPEX_Exporter::sanitize_config(
			array(
				'source'     => 'terms:category',
				'fields'     => array( 'core:term_id' ),
				'date_range' => array( 'preset' => 'last_7_days' ),
			),
			$notes
		);

		$this->assertIsArray( $config );
		$this->assertContains( $this->note(), $notes );
	}
}
