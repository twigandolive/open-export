<?php
/**
 * Tests for OPEX_Abilities.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Category, abilities, permissions and execution.
 */
class AbilitiesTest extends TestCase {

	private int $admin_id = 0;
	private array $made   = array();

	protected function setUp(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API is not available.' );
		}
		$this->admin_id = get_current_user_id();
		$this->reset_registry();
		OPEX_Abilities::register();
		// The registries may already be built, so run the init actions by hand.
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
	}

	protected function tearDown(): void {
		wp_set_current_user( $this->admin_id );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		// On a network wp_delete_user only removes the user from this site, so the user would stay.
		if ( is_multisite() ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
		}
		foreach ( $this->made as $id ) {
			is_multisite() ? wpmu_delete_user( $id ) : wp_delete_user( $id );
		}
		$this->reset_registry();
	}

	private function reset_registry(): void {
		foreach ( array( 'open-export/get-schema', 'open-export/list-sources' ) as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}
		if ( wp_has_ability_category( 'open-export' ) ) {
			wp_unregister_ability_category( 'open-export' );
		}
	}

	public function test_registered_with_labels_and_annotations() {
		$this->assertTrue( wp_has_ability_category( 'open-export' ) );
		foreach ( array( 'open-export/get-schema', 'open-export/list-sources' ) as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name );
			$this->assertSame( 'open-export', $ability->get_category() );
			$this->assertNotSame( '', $ability->get_label() );
			$this->assertNotSame( '', $ability->get_description() );
			$this->assertNotEmpty( $ability->get_output_schema() );
			$annotations = $ability->get_meta()['annotations'];
			$this->assertTrue( $annotations['readonly'] );
			$this->assertFalse( $annotations['destructive'] );
		}
		$this->assertSame( 'string', wp_get_ability( 'open-export/get-schema' )->get_input_schema()['properties']['source']['type'] );
		$this->assertSame( 'boolean', wp_get_ability( 'open-export/get-schema' )->get_input_schema()['properties']['all']['type'] );
	}

	public function test_only_two_read_only_abilities() {
		$ours = array();
		foreach ( wp_get_abilities() as $name => $ability ) {
			if ( 0 === strpos( (string) $name, 'open-export/' ) ) {
				$ours[] = $name;
			}
		}
		sort( $ours );
		$this->assertSame( array( 'open-export/get-schema', 'open-export/list-sources' ), $ours );
	}

	public function test_permissions() {
		$ability = wp_get_ability( 'open-export/get-schema' );
		wp_set_current_user( 1 );
		$this->assertTrue( $ability->check_permissions( array() ) );

		$subscriber = $this->make_user();
		wp_set_current_user( $subscriber );
		$this->assertFalse( $ability->check_permissions( array() ) );
		$denied = $ability->execute( array() );
		$this->assertTrue( is_wp_error( $denied ) || false === $denied || null === $denied );
		$this->assertFalse( is_array( $denied ) );

		wp_set_current_user( 0 );
		$this->assertFalse( $ability->check_permissions( array() ) );
		$this->assertFalse( is_array( wp_get_ability( 'open-export/list-sources' )->execute() ) );
	}

	public function test_permission_follows_capability_filter() {
		$subscriber = $this->make_user();
		wp_set_current_user( $subscriber );
		add_filter( 'opex_capability', array( $this, 'read_cap' ) );
		$this->assertTrue( wp_get_ability( 'open-export/get-schema' )->check_permissions( array() ) );
		remove_filter( 'opex_capability', array( $this, 'read_cap' ) );
	}

	/** Filter callback: a capability every user has. */
	public function read_cap(): string {
		return 'read';
	}

	public function test_get_schema_returns_schema_keys() {
		wp_set_current_user( 1 );
		$out = wp_get_ability( 'open-export/get-schema' )->execute( array( 'source' => 'posts:post' ) );
		$this->assertIsArray( $out );
		foreach ( array( 'opex_schema', 'plugin', 'plugin_version', 'operators', 'all_sources', 'sources' ) as $key ) {
			$this->assertArrayHasKey( $key, $out, $key );
		}
		$this->assertSame( 'posts:post', $out['sources'][0]['id'] );
		$this->assertIsArray( wp_get_ability( 'open-export/get-schema' )->execute( array( 'all' => true ) ) );
		$this->assertIsArray( wp_get_ability( 'open-export/get-schema' )->execute() );
	}

	public function test_get_schema_rejects_bad_input() {
		wp_set_current_user( 1 );
		$this->assertInstanceOf( WP_Error::class, wp_get_ability( 'open-export/get-schema' )->execute( array( 'all' => 'maybe-not', 'extra' => 1 ) ) );
	}

	public function test_list_sources_matches_catalog() {
		wp_set_current_user( 1 );
		$out = wp_get_ability( 'open-export/list-sources' )->execute();
		$this->assertIsArray( $out );
		$this->assertSame( count( OPEX_Catalog::sources() ), count( $out ) );
	}

	/** Creates a subscriber and returns the id. */
	private function make_user(): int {
		$id = wp_insert_user(
			array(
				'user_login' => 'opex_sub_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password( 20 ),
				'user_email' => 'sub' . wp_generate_password( 6, false ) . '@example.test',
				'role'       => 'subscriber',
			)
		);
		$this->made[] = (int) $id;
		return (int) $id;
	}
}
