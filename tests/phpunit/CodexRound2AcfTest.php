<?php
/**
 * A page_template rule with a template other than the default one can
 * apply to any post type, as ACF evaluates it. A group
 * whose only rule is "post is not X" applies to the posts source.
 *
 * The location checks need nothing. The field group checks register groups
 * in code and need ACF or Secure Custom Fields, and skip without it.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class CodexRound2AcfTest extends TestCase {

	/** @var string[] Local field group keys made by the test. */
	private $groups = array();

	protected function setUp(): void {
		OPEX_Acf::reset();
	}

	protected function tearDown(): void {
		if ( OPEX_Acf::available() ) {
			foreach ( $this->groups as $key ) {
				acf_remove_local_field_group( $key );
			}
		}
		$this->groups = array();
		OPEX_Acf::reset();
	}

	/**
	 * One rule.
	 *
	 * @param string $param    Param.
	 * @param string $value    Value.
	 * @param string $operator Operator.
	 * @return array The rule.
	 */
	private function rule( string $param, string $value, string $operator = '==' ): array {
		return array(
			'param'    => $param,
			'operator' => $operator,
			'value'    => $value,
		);
	}

	/**
	 * A posts source of a post type.
	 *
	 * @param string $type Post type.
	 * @return array Parsed source.
	 */
	private function posts( string $type ): array {
		return array( 'type' => 'posts', 'post_type' => $type );
	}

	/**
	 * A local field group with one repeater.
	 *
	 * @param string $key      Group key.
	 * @param array  $location Location.
	 * @param string $name     Repeater name.
	 */
	private function group( string $key, array $location, string $name ): void {
		acf_add_local_field_group(
			array(
				'key'      => $key,
				'title'    => $key,
				'location' => $location,
				'fields'   => array(
					array(
						'key'        => "field_{$key}",
						'name'       => $name,
						'label'      => $name,
						'type'       => 'repeater',
						'sub_fields' => array(
							array( 'key' => "field_{$key}_v", 'name' => 'v', 'label' => 'v', 'type' => 'text' ),
						),
					),
				),
			)
		);
		$this->groups[] = $key;
		OPEX_Acf::reset();
	}

	public function test_a_named_page_template_applies_to_other_post_types(): void {
		$shared = array( array( $this->rule( 'post_type', 'product' ), $this->rule( 'page_template', 'shared.php' ) ) );
		$this->assertTrue( OPEX_Acf_Location::applies( $shared, $this->posts( 'product' ) ), 'a product using a shared template holds the group' );
		$this->assertFalse( OPEX_Acf_Location::applies( $shared, $this->posts( 'page' ) ), 'the post type rule still applies' );

		$alone = array( array( $this->rule( 'page_template', 'shared.php' ) ) );
		$this->assertTrue( OPEX_Acf_Location::applies( $alone, $this->posts( 'page' ) ) );
		$this->assertTrue( OPEX_Acf_Location::applies( $alone, $this->posts( 'post' ) ) );
		$this->assertTrue( OPEX_Acf_Location::applies( array( array( $this->rule( 'page_template', 'shared.php', '!=' ) ) ), $this->posts( 'post' ) ) );
	}

	public function test_the_default_page_template_stays_with_pages(): void {
		foreach ( array( '==', '!=' ) as $op ) {
			$rule = array( array( $this->rule( 'page_template', 'default', $op ) ) );
			$this->assertTrue( OPEX_Acf_Location::applies( $rule, $this->posts( 'page' ) ), $op );
			$this->assertFalse( OPEX_Acf_Location::applies( $rule, $this->posts( 'post' ) ), $op . ': ACF shows a default template rule on pages only' );
		}
	}

	public function test_a_lone_negative_post_rule_keeps_the_posts_source(): void {
		$not_one = array( array( $this->rule( 'post', '123', '!=' ) ) );
		$this->assertTrue( OPEX_Acf_Location::applies( $not_one, $this->posts( 'post' ) ) );
		$this->assertTrue( OPEX_Acf_Location::applies( $not_one, $this->posts( 'page' ) ) );
		$this->assertFalse( OPEX_Acf_Location::applies( $not_one, array( 'type' => 'users' ) ), 'it still names post records only' );
		$this->assertFalse( OPEX_Acf_Location::applies( array( array( $this->rule( 'post_type', 'page' ), $this->rule( 'post', '123', '!=' ) ) ), $this->posts( 'post' ) ) );
	}

	public function test_field_groups_from_code_follow_the_rules(): void {
		if ( ! OPEX_Acf::available() ) {
			$this->markTestSkipped( 'ACF or Secure Custom Fields is not active.' );
		}
		$this->group( 'group_opexr2_tpl', array( array( $this->rule( 'post_type', 'post' ), $this->rule( 'page_template', 'shared.php' ) ) ), 'opexr2_tpl' );
		$this->group( 'group_opexr2_notone', array( array( $this->rule( 'post', '123', '!=' ) ) ), 'opexr2_notone' );
		$this->group( 'group_opexr2_default', array( array( $this->rule( 'page_template', 'default' ) ) ), 'opexr2_default' );

		$post = array_keys( OPEX_Acf::fields_for( $this->posts( 'post' ) ) );
		$page = array_keys( OPEX_Acf::fields_for( $this->posts( 'page' ) ) );
		$this->assertContains( 'opexr2_tpl', $post );
		$this->assertContains( 'opexr2_notone', $post );
		$this->assertNotContains( 'opexr2_default', $post );
		$this->assertNotContains( 'opexr2_tpl', $page );
		$this->assertContains( 'opexr2_notone', $page );
		$this->assertContains( 'opexr2_default', $page );
	}
}
