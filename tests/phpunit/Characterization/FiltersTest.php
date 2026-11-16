<?php
/**
 * Characterization: filter operators, relations and relative date tokens.
 *
 * @package Open_Export
 */

require_once __DIR__ . '/CharacterizationCase.php';

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every operator on core, meta and taxonomy fields.
 */
class FiltersTest extends CharacterizationCase {

	/**
	 * Run one rule set and describe the rows it returns.
	 *
	 * @param string $source   Source.
	 * @param array  $rules    Rules.
	 * @param string $relation AND or OR.
	 * @return array
	 */
	private function describe( string $source, array $rules, string $relation = 'AND' ): array {
		$config = $this->config( array(
			'source'  => $source,
			'fields'  => array( $this->first_field( $source ) ),
			'filters' => array( 'relation' => $relation, 'rules' => $rules ),
		) );
		$ids   = $this->ids_for( $config );
		$count = OPEX_Query::count( $config );
		$this->assertSame( $count, count( $ids ), 'count equals fetched rows' );
		return array(
			'count' => $count,
			'first' => $ids[0] ?? null,
			'last'  => $ids ? end( $ids ) : null,
			'sha1'  => sha1( implode( ',', $ids ) ),
		);
	}

	/**
	 * Run all 14 operators on one field.
	 *
	 * @param string $source Source.
	 * @param string $field  Field id.
	 * @param array  $values Operator => value.
	 * @return array
	 */
	private function all_ops( string $source, string $field, array $values ): array {
		$out = array();
		foreach ( OPEX_Query::ops() as $op ) {
			$out[ $op ] = $this->describe( $source, array( array( 'field' => $field, 'op' => $op, 'value' => $values[ $op ] ?? '' ) ) );
		}
		return $out;
	}

	/**
	 * The 14 operators in 1.3.1.
	 */
	public function test_operator_list(): void {
		$this->assertSame(
			array( 'eq', 'neq', 'contains', 'not_contains', 'starts_with', 'ends_with', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'empty', 'not_empty' ),
			OPEX_Query::ops()
		);
		$this->assertFalse( OPEX_Query::is_valid_op( 'regex' ) );
	}

	/**
	 * Operators on a text core field.
	 */
	public function test_core_text_field(): void {
		$r = $this->all_ops( 'posts:post', 'core:post_title', array(
			'eq' => 'Test post 7', 'neq' => 'Test post 7', 'contains' => 'post 1', 'not_contains' => 'post 1',
			'starts_with' => 'Test post 1', 'ends_with' => '5', 'gt' => 'Test post 5', 'gte' => 'Test post 5',
			'lt' => 'Test post 5', 'lte' => 'Test post 5', 'in' => 'Test post 1, Test post 2 ,Test post 3', 'not_in' => 'Test post 1,Test post 2',
		) );
		$this->assertSame( 1, $r['eq']['count'] );
		$this->assertSame( 59, $r['neq']['count'] );
		$this->assertSame( 3, $r['in']['count'] );
		$this->assertSame( 0, $r['empty']['count'] );
		$this->assertSame( 60, $r['not_empty']['count'] );
		$this->assert_golden_json( 'filters-core-text', $r );
	}

	/**
	 * Operators on a numeric core field (cast to decimal for comparisons).
	 */
	public function test_core_numeric_field(): void {
		$r = $this->all_ops( 'posts:post', 'core:ID', array(
			'eq' => '20', 'neq' => '20', 'contains' => '2', 'not_contains' => '2', 'starts_with' => '2', 'ends_with' => '0',
			'gt' => '40', 'gte' => '40', 'lt' => '20', 'lte' => '20', 'in' => '10,11,12', 'not_in' => '10,11,12',
		) );
		$this->assert_golden_json( 'filters-core-numeric', $r );
	}

	/**
	 * Operators on a meta field with numeric values.
	 */
	public function test_meta_numeric_field(): void {
		$r = $this->all_ops( 'posts:post', 'meta:opex_rating', array(
			'eq' => '3', 'neq' => '3', 'contains' => '1', 'not_contains' => '1', 'starts_with' => '2', 'ends_with' => '4',
			'gt' => '2', 'gte' => '2', 'lt' => '2', 'lte' => '2', 'in' => '1,2', 'not_in' => '1,2',
		) );
		$this->assertSame( 10, $r['eq']['count'] );
		$this->assertSame( 50, $r['neq']['count'] );
		$this->assert_golden_json( 'filters-meta-numeric', $r );
	}

	/**
	 * Operators on a meta field with text values.
	 */
	public function test_meta_text_field(): void {
		$r = $this->all_ops( 'posts:post', 'meta:opex_note', array(
			'eq' => 'note 5', 'neq' => 'note 5', 'contains' => 'note 1', 'not_contains' => 'note 1', 'starts_with' => 'note 2', 'ends_with' => '9',
			'gt' => 'note 3', 'gte' => 'note 3', 'lt' => 'note 3', 'lte' => 'note 3', 'in' => 'note 1,note 2', 'not_in' => 'note 1,note 2',
		) );
		$this->assert_golden_json( 'filters-meta-text', $r );
	}

	/**
	 * A meta key that no row has: negative operators match every row.
	 */
	public function test_meta_missing_key(): void {
		$r = $this->all_ops( 'posts:post', 'meta:no_such_key', array( 'eq' => 'x', 'neq' => 'x', 'contains' => 'x', 'not_contains' => 'x', 'not_in' => 'x,y', 'in' => 'x,y' ) );
		$this->assertSame( 0, $r['eq']['count'] );
		$this->assertSame( 60, $r['neq']['count'] );
		$this->assertSame( 60, $r['not_contains']['count'] );
		$this->assertSame( 60, $r['not_in']['count'] );
		$this->assert_golden_json( 'filters-meta-missing', $r );
	}

	/**
	 * Negative meta operators also match users that lack the key.
	 */
	public function test_meta_on_users(): void {
		$this->single_site_only( 'the users table is shared by every site of the network.' );
		$r = $this->all_ops( 'users', 'meta:opex_plan', array( 'eq' => 'pro', 'neq' => 'pro', 'contains' => 'e', 'not_contains' => 'e', 'starts_with' => 'f', 'ends_with' => 'm', 'gt' => 'free', 'gte' => 'free', 'lt' => 'pro', 'lte' => 'pro', 'in' => 'free,team', 'not_in' => 'free,team' ) );
		$this->assert_golden_json( 'filters-meta-users', $r );
	}

	/**
	 * Operators on taxonomy fields.
	 */
	public function test_taxonomy_fields(): void {
		$cat = $this->all_ops( 'posts:post', 'tax:category', array( 'eq' => 'News', 'neq' => 'News', 'contains' => 'ew', 'not_contains' => 'ew', 'starts_with' => 'Ne', 'ends_with' => 'ws', 'gt' => 'A', 'gte' => 'A', 'lt' => 'Z', 'lte' => 'Z', 'in' => 'News,Guides', 'not_in' => 'News' ) );
		$tag = $this->all_ops( 'posts:post', 'tax:post_tag', array( 'eq' => 'alpha', 'neq' => 'alpha', 'contains' => 'a', 'not_contains' => 'a', 'starts_with' => 'a', 'ends_with' => 'a', 'gt' => 'a', 'gte' => 'a', 'lt' => 'z', 'lte' => 'z', 'in' => 'alpha,beta', 'not_in' => 'alpha,beta' ) );
		$this->assert_golden_json( 'filters-tax-category', $cat );
		$this->assert_golden_json( 'filters-tax-post-tag', $tag );
	}

	/**
	 * The roles filter on users.
	 */
	public function test_computed_roles(): void {
		$this->single_site_only( 'the users table is shared by every site of the network.' );
		$r = $this->all_ops( 'users', 'computed:roles', array( 'eq' => 'editor', 'neq' => 'editor', 'contains' => 'edit', 'not_contains' => 'edit', 'starts_with' => 'ed', 'ends_with' => 'or', 'gt' => 'a', 'gte' => 'a', 'lt' => 'z', 'lte' => 'z', 'in' => 'editor,author', 'not_in' => 'editor,author' ) );
		$this->assertSame( 5, $r['eq']['count'] );
		$this->assertSame( 21, $r['neq']['count'] );
		$this->assertSame( 10, $r['in']['count'], 'in takes a list of roles: editor or author' );
		$this->assertSame( 16, $r['not_in']['count'], 'not_in excludes every listed role' );
		$this->assert_golden_json( 'filters-computed-roles', $r );
	}

	/**
	 * Operators on a user core field and a comment core field.
	 */
	public function test_other_sources_core(): void {
		$this->single_site_only( 'the users table is shared by every site of the network.' );
		$u = $this->all_ops( 'users', 'core:user_email', array( 'eq' => 'user5@example.test', 'neq' => 'user5@example.test', 'contains' => 'user1', 'not_contains' => 'user1', 'starts_with' => 'user2', 'ends_with' => '9@example.test', 'gt' => 'user5', 'gte' => 'user5', 'lt' => 'user5', 'lte' => 'user5', 'in' => 'user1@example.test,user2@example.test', 'not_in' => 'user1@example.test' ) );
		$c = $this->all_ops( 'comments', 'core:comment_approved', array( 'eq' => '0', 'neq' => '0', 'contains' => '1', 'not_contains' => '1', 'starts_with' => '1', 'ends_with' => '0', 'gt' => '0', 'gte' => '1', 'lt' => '1', 'lte' => '0', 'in' => '0,spam', 'not_in' => '0' ) );
		$this->assert_golden_json( 'filters-user-email', $u );
		$this->assert_golden_json( 'filters-comment-approved', $c );
	}

	/**
	 * Relations.
	 */
	public function test_and_or_relations(): void {
		$rules = array(
			array( 'field' => 'meta:opex_rating', 'op' => 'eq', 'value' => '3' ),
			array( 'field' => 'core:post_title', 'op' => 'contains', 'value' => 'post 1' ),
		);
		$and = $this->describe( 'posts:post', $rules, 'AND' );
		$or  = $this->describe( 'posts:post', $rules, 'OR' );
		$three = $this->describe( 'posts:post', array_merge( $rules, array( array( 'field' => 'tax:category', 'op' => 'eq', 'value' => 'News' ) ) ), 'OR' );
		$this->assertLessThan( $or['count'], $and['count'] );
		$mixed = $this->describe( 'users', array(
			array( 'field' => 'computed:roles', 'op' => 'eq', 'value' => 'editor' ),
			array( 'field' => 'meta:opex_plan', 'op' => 'eq', 'value' => 'pro' ),
		), 'OR' );
		$this->assert_golden_json( 'filters-relations', compact( 'and', 'or', 'three', 'mixed' ) );
	}

	/**
	 * Bad operators are dropped with a note. A rule on a field the source does
	 * not have is refused (S-3), and an empty in-list matches nothing.
	 */
	public function test_ignored_rules(): void {
		$all     = $this->describe( 'posts:post', array() );
		$badop   = $this->describe( 'posts:post', array( array( 'field' => 'core:ID', 'op' => 'regex', 'value' => 'x' ) ) );
		$emptyin = $this->describe( 'posts:post', array( array( 'field' => 'core:ID', 'op' => 'in', 'value' => ' , ' ) ) );
		$emptyout = $this->describe( 'posts:post', array( array( 'field' => 'core:ID', 'op' => 'not_in', 'value' => ' , ' ) ) );
		$this->assertSame( 60, $all['count'] );
		$this->assertSame( $all, $badop, 'a rule with a bad operator is dropped' );
		$this->assertSame( 0, $emptyin['count'], 'an empty in-list matches nothing' );
		$this->assertSame( $all, $emptyout, 'an empty not_in list matches everything' );

		$unknown = OPEX_Exporter::sanitize_config( array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'filters' => array( 'rules' => array( array( 'field' => 'core:nope', 'op' => 'eq', 'value' => 'x' ) ) ) ) );
		$this->assertWPError( $unknown );
		$this->assertSame( 'opex_bad_filter', $unknown->get_error_code() );
		$this->assertSame( 400, $unknown->get_error_data()['status'] );
		$this->assertStringContainsString( 'core:nope', $unknown->get_error_message() );

		$config = $this->config( array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'filters' => array( 'relation' => 'XOR', 'rules' => array( array( 'field' => 'core:ID', 'op' => 'eq', 'value' => '10' ) ) ) ) );
		$this->assertSame( 'AND', $config['filters']['relation'] );
	}

	/**
	 * At most 50 rules survive sanitizing (the 1.3.1 limit was 20).
	 */
	public function test_rule_cap(): void {
		$rules = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$rules[] = array( 'field' => 'core:ID', 'op' => 'gt', 'value' => (string) $i );
		}
		$config = $this->config( array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'filters' => array( 'relation' => 'AND', 'rules' => $rules ) ) );
		$this->assertCount( 50, $config['filters']['rules'] );
	}

	/**
	 * S-5: a text value that looks like a date token stays text. Only a date column reads tokens.
	 */
	public function test_date_token_in_text_field_stays_text(): void {
		$this->temp_post( 'today', 1, array( 'post_excerpt' => 'opextok' ) );
		$r = $this->describe( 'posts:post', array( array( 'field' => 'core:post_title', 'op' => 'eq', 'value' => 'today' ) ) );
		$this->assertSame( 1, $r['count'], 'a title of "today" is matched as text' );
		$meta = $this->describe( 'posts:post', array( array( 'field' => 'core:post_excerpt', 'op' => 'eq', 'value' => 'opextok' ), array( 'field' => 'core:post_date', 'op' => 'gte', 'value' => 'today' ) ) );
		$this->assertLessThanOrEqual( 1, $meta['count'], 'a date column still reads the token' );
	}

	/**
	 * Relative date tokens against posts created at known ages.
	 */
	public function test_relative_date_tokens(): void {
		$ages = array( 0, 1.5, 3.5, 10.5, 45.5, 400.5 );
		$ids  = array();
		foreach ( $ages as $d ) {
			$ids[ (string) $d ] = $this->temp_post( "tok $d", $d, array( 'post_excerpt' => 'opextok' ) );
		}
		$tz    = OPEX_Dates::site_timezone();
		$now   = new DateTimeImmutable( 'now', $tz );
		$today = $now->setTime( 0, 0, 0 );
		$bound = array(
			'today'        => $today,
			'yesterday'    => $today->modify( '-1 day' ),
			'last 7 days'  => $now->modify( '-7 days' ),
			'last 2 weeks' => $now->modify( '-14 days' ),
			'last 2 months' => $now->modify( '-2 months' ),
			'-3 days'      => $now->modify( '-3 days' ),
			'this month'   => $now->modify( 'first day of this month' )->setTime( 0, 0, 0 ),
			'last month'   => $now->modify( 'first day of last month' )->setTime( 0, 0, 0 ),
			'this year'    => $now->modify( 'first day of january this year' )->setTime( 0, 0, 0 ),
			'last year'    => $now->modify( 'first day of january last year' )->setTime( 0, 0, 0 ),
		);
		$stamps = array();
		foreach ( $ages as $d ) {
			$stamps[ (string) $d ] = new DateTimeImmutable( get_post_field( 'post_date', $ids[ (string) $d ] ), $tz );
		}
		$base = array( 'field' => 'core:post_excerpt', 'op' => 'eq', 'value' => 'opextok' );
		foreach ( $bound as $token => $when ) {
			$expected = 0;
			foreach ( $stamps as $s ) {
				if ( $s >= $when ) {
					++$expected;
				}
			}
			$got = $this->describe( 'posts:post', array( $base, array( 'field' => 'core:post_date', 'op' => 'gte', 'value' => $token ) ) );
			$this->assertSame( $expected, $got['count'], "token '$token' with gte" );
		}
		$lt = $this->describe( 'posts:post', array( $base, array( 'field' => 'core:post_date', 'op' => 'lt', 'value' => 'last 30 days' ) ) );
		$this->assertSame( 2, $lt['count'] );
		unset( $ids );
	}

	/**
	 * Date comparisons on a date column use whole-day semantics.
	 */
	public function test_date_column_whole_day_semantics(): void {
		$r = array();
		foreach ( array( 'eq', 'neq', 'gt', 'gte', 'lt', 'lte' ) as $op ) {
			foreach ( array( '2026-06-15', '20260615', '2026-06-15 12:00', '2026-06-15 11:59:00' ) as $value ) {
				$r[ $op . ' ' . $value ] = $this->describe( 'posts:post', array( array( 'field' => 'core:post_date', 'op' => $op, 'value' => $value ) ) );
			}
		}
		$r['contains 2026-06'] = $this->describe( 'posts:post', array( array( 'field' => 'core:post_date', 'op' => 'contains', 'value' => '2026-06' ) ) );
		$r['empty']            = $this->describe( 'posts:post', array( array( 'field' => 'core:post_date', 'op' => 'empty', 'value' => '' ) ) );
		$this->assert_golden_json( 'filters-date-column', $r );
	}
}
