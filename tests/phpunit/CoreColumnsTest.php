<?php
/**
 * S-15: the picker, the capability document and the query engine share one
 * list of core columns per source, and the query engine's list is the truth.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class CoreColumnsTest extends TestCase {

	protected function setUp(): void {
		wp_set_current_user( 1 );
	}

	/**
	 * Core field ids the picker lists for a source.
	 *
	 * @param string $source Source id.
	 * @return string[]
	 */
	private function picker_core( string $source ): array {
		return array_values( array_filter( array_keys( OPEX_Catalog::field_ids( $source ) ), static function ( $id ) {
			return 0 === strpos( $id, 'core:' );
		} ) );
	}

	/** The picker lists exactly the columns the engine accepts, for every core source type. */
	public function test_picker_matches_the_engine(): void {
		$sources = array(
			'posts:post'     => array( 'type' => 'posts' ),
			'posts:page'     => array( 'type' => 'posts' ),
			'users'          => array( 'type' => 'users' ),
			'comments'       => array( 'type' => 'comments' ),
			'terms:category' => array( 'type' => 'terms' ),
		);
		foreach ( $sources as $source => $info ) {
			$expected = array_map( static function ( $col ) {
				return 'core:' . $col;
			}, OPEX_Core_Columns::for_type( $info['type'] ) );
			$this->assertSame( $expected, $this->picker_core( $source ), $source );
			$src = OPEX_Query::parse_source( $source );
			foreach ( $expected as $field ) {
				$this->assertTrue( OPEX_Query::is_valid_field( $src, $field ), "$source accepts $field" );
			}
		}
	}

	/** The columns the picker used to miss are listed and described now. */
	public function test_columns_the_picker_gained(): void {
		$this->assertContains( 'core:post_type', $this->picker_core( 'posts:post' ) );
		$this->assertContains( 'core:post_mime_type', $this->picker_core( 'posts:post' ) );
		$this->assertContains( 'core:comment_date_gmt', $this->picker_core( 'comments' ) );
		$this->assertContains( 'core:comment_karma', $this->picker_core( 'comments' ) );

		$doc   = OPEX_Schema::build( 'comments' );
		$types = array();
		foreach ( $doc['sources'] as $entry ) {
			if ( 'comments' === $entry['id'] ) {
				$types = array_column( $entry['fields'], 'type', 'id' );
			}
		}
		$this->assertSame( 'date', $types['core:comment_date_gmt'] ?? null );
		$this->assertSame( 'number', $types['core:comment_karma'] ?? null );
	}

	/** No list ever holds a column that must never leave the database. */
	public function test_no_forbidden_column(): void {
		foreach ( array( 'posts', 'users', 'comments', 'terms' ) as $type ) {
			$this->assertSame( array(), array_intersect( OPEX_Query::FORBIDDEN, OPEX_Core_Columns::for_type( $type ) ), $type );
		}
		$this->assertSame( array(), OPEX_Core_Columns::for_type( 'table' ) );
		$this->assertFalse( OPEX_Query::valid_core_col( 'users', 'user_pass' ) );
	}
}
