<?php
/**
 * The multisite data boundary: a person who is not a super admin reaches only
 * the current site's users, user meta and tables.
 *
 * Runs on the multisite test site (tests/bin/setup-site.sh --multisite) and
 * skips itself on a single site, where nothing is restricted.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class NetworkAccessTest extends TestCase {

	/** @var int[] */
	private array $users = array();

	/** @var array<int, string[]> Job ids by site. */
	private array $jobs = array();

	protected function setUp(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'The access boundary exists only on a multisite network.' );
		}
		wp_set_current_user( 1 );
		$GLOBALS['wp_rest_server'] = null;
	}

	protected function tearDown(): void {
		if ( ! is_multisite() ) {
			return;
		}
		while ( ms_is_switched() ) {
			restore_current_blog();
		}
		wp_set_current_user( 1 );
		foreach ( $this->jobs as $site => $ids ) {
			switch_to_blog( $site );
			foreach ( $ids as $id ) {
				OPEX_Jobs::delete( $id );
			}
			foreach ( array_keys( OPEX_Schedules::all() ) as $sid ) {
				OPEX_Schedules::delete( (string) $sid );
			}
			delete_option( 'opex_schedules' );
			restore_current_blog();
		}
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		// WooCommerce's own delete hook queries its customer table on sites where it is not active.
		$GLOBALS['wpdb']->suppress_errors( true );
		foreach ( $this->users as $uid ) {
			wpmu_delete_user( $uid );
		}
		$GLOBALS['wpdb']->suppress_errors( false );
		$GLOBALS['wpdb']->last_error = '';
		OPEX_Source_Labels::reset();
		$GLOBALS['wp_rest_server'] = null;
	}

	/** An administrator of one site only, never a super admin. */
	private function site_admin( int $site ): int {
		$uid = wp_insert_user( array( 'user_login' => 'opexsiteadmin' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'opexsiteadmin' . wp_rand() . '@example.test', 'role' => 'administrator' ) );
		$this->assertIsInt( $uid );
		$this->users[] = $uid;
		if ( 1 !== $site ) {
			remove_user_from_blog( $uid, 1 );
			add_user_to_blog( $site, $uid, 'administrator' );
		}
		$this->assertFalse( is_super_admin( $uid ) );
		return $uid;
	}

	/** Table ids offered in the picker now. */
	private function table_ids(): array {
		foreach ( OPEX_Catalog::sources() as $group ) {
			if ( 'tables' === $group['id'] ) {
				return array_values( array_filter( array_column( $group['items'], 'id' ), static function ( $id ) {
					return 0 === strpos( $id, 'table:' );
				} ) );
			}
		}
		return array();
	}

	/** The users tile count in the picker. */
	private function user_tile(): int {
		foreach ( OPEX_Catalog::sources() as $group ) {
			foreach ( $group['items'] as $item ) {
				if ( 'users' === $item['id'] ) {
					return (int) $item['count'];
				}
			}
		}
		return -1;
	}

	/** Member ids of the current site, as WordPress counts them. */
	private function members(): array {
		$ids = array_map( 'intval', get_users( array( 'blog_id' => get_current_blog_id(), 'fields' => 'ID' ) ) );
		sort( $ids );
		return $ids;
	}

	/** Run a job to the end and return the first column of its CSV rows. */
	private function export_ids( $job ): array {
		$this->assertIsArray( $job, is_wp_error( $job ) ? $job->get_error_message() : '' );
		$this->jobs[ get_current_blog_id() ][] = $job['id'];
		for ( $i = 0; $i < 100 && 'running' === $job['status']; $i++ ) {
			$job = OPEX_Exporter::run( $job['id'] );
		}
		$this->assertSame( 'done', $job['status'], (string) $job['error'] );
		$lines = array_slice( array_filter( explode( "\n", (string) file_get_contents( OPEX_Jobs::file_path( OPEX_Jobs::get( $job['id'] ) ) ) ) ), 1 );
		$ids   = array_map( static function ( $line ) {
			return (int) strtok( $line, ',' );
		}, $lines );
		sort( $ids );
		return $ids;
	}

	/** A sanitized users config. */
	private function users_config( array $fields = array( 'core:ID' ) ) {
		return OPEX_Exporter::sanitize_config( array( 'source' => 'users', 'fields' => $fields, 'batch_size' => 10 ) );
	}

	/** Every shared table name on this network. */
	private function shared_tables(): array {
		global $wpdb;
		return array_merge( array( $wpdb->users, $wpdb->usermeta ), array_values( $wpdb->tables( 'global' ) ), array_values( $wpdb->tables( 'ms_global' ) ) );
	}

	public function test_a_sub_site_admin_sees_only_that_site(): void {
		global $wpdb;
		$uid = $this->site_admin( 2 );
		switch_to_blog( 2 );
		wp_set_current_user( $uid );
		$this->assertTrue( current_user_can( 'manage_options' ) );
		$this->assertTrue( OPEX_Access::restricted() );

		$tables = $this->table_ids();
		$this->assertNotEmpty( $tables );
		foreach ( $tables as $id ) {
			$this->assertStringStartsWith( 'table:' . $wpdb->base_prefix . '2_', $id );
		}
		$this->assertSame( count( $this->members() ), $this->user_tile(), 'the users tile counts this site\'s members' );

		$refused = array_merge( $this->shared_tables(), array( $wpdb->base_prefix . 'posts', $wpdb->base_prefix . '3_posts', $wpdb->base_prefix . 'options' ) );
		foreach ( $refused as $table ) {
			$this->assertNull( OPEX_Query::parse_source( 'table:' . $table ), $table );
			$error = OPEX_Exporter::sanitize_config( array( 'source' => 'table:' . $table, 'fields' => array( 'col:ID' ) ) );
			$this->assertInstanceOf( WP_Error::class, $error, $table );
			$this->assertSame( 'opex_bad_source', $error->get_error_code(), $table );
		}
		$own = OPEX_Exporter::sanitize_config( array( 'source' => 'table:' . $wpdb->posts, 'fields' => array( 'col:ID' ) ) );
		$this->assertIsArray( $own, 'the site\'s own tables still work' );
		$this->assertArrayNotHasKey( 'wide', $own );

		$config = $this->users_config();
		$this->assertArrayNotHasKey( 'wide', $config );
		$this->assertSame( $this->members(), $this->export_ids( OPEX_Exporter::create( $config, 'members' ) ) );
		$this->assertSame( count( $this->members() ), OPEX_Query::count( $config ) );
		$preview = OPEX_Exporter::preview( $config );
		$this->assertSame( count( $this->members() ), $preview['total'] );

		// REST answers the same way.
		$response = rest_do_request( new WP_REST_Request( 'GET', '/open-export/v1/sources' ) );
		$this->assertSame( 200, $response->get_status() );
		$ids = array();
		foreach ( (array) $response->get_data() as $group ) {
			$ids = array_merge( $ids, array_column( (array) ( $group['items'] ?? array() ), 'id' ) );
		}
		$this->assertContains( 'table:' . $wpdb->posts, $ids );
		$this->assertNotContains( 'table:' . $wpdb->users, $ids );
		$this->assertNotContains( 'table:' . $wpdb->base_prefix . 'sitemeta', $ids );
	}

	public function test_user_meta_of_other_sites_is_not_offered_or_exported(): void {
		global $wpdb;
		$uid = $this->site_admin( 2 );
		switch_to_blog( 2 );
		wp_set_current_user( $uid );
		$base  = $wpdb->base_prefix;
		$own   = $base . '2_capabilities';
		$other = array( $base . 'capabilities', $base . 'user_level', $base . '3_capabilities' );

		$ids = array_keys( OPEX_Catalog::field_ids( 'users' ) );
		foreach ( $other as $key ) {
			$this->assertNotContains( 'meta:' . $key, $ids, $key );
		}
		$notes  = array();
		$config = OPEX_Exporter::sanitize_config( array( 'source' => 'users', 'fields' => array_merge( array( 'core:ID', 'meta:' . $own ), array_map( static function ( $k ) {
			return 'meta:' . $k;
		}, $other ) ) ), $notes );
		$this->assertSame( array( 'core:ID', 'meta:' . $own ), $config['fields'], 'another site\'s keys are dropped' );
		$this->assertCount( 3, $notes );
		$filter = OPEX_Exporter::sanitize_config( array( 'source' => 'users', 'fields' => array( 'core:ID' ), 'filters' => array( 'relation' => 'AND', 'rules' => array( array( 'field' => 'meta:' . $base . '3_capabilities', 'op' => 'not_empty' ) ) ) ) );
		$this->assertSame( 'opex_bad_filter', $filter->get_error_code() );

		// A stored config that still names such a key exports it empty.
		$stored  = array_merge( $config, array( 'fields' => array( 'core:ID', 'meta:' . $base . 'capabilities' ) ) );
		$batch   = OPEX_Query::batch( $stored, 0, 50 );
		$values  = array_filter( array_column( $batch['items'], 'meta:' . $base . 'capabilities' ) );
		$this->assertSame( array(), $values );
	}

	public function test_the_main_site_admin_does_not_reach_sub_site_or_shared_tables(): void {
		global $wpdb;
		wp_set_current_user( $this->site_admin( 1 ) );
		$tables = $this->table_ids();
		$this->assertContains( 'table:' . $wpdb->posts, $tables );
		foreach ( $tables as $id ) {
			$this->assertDoesNotMatchRegularExpression( '/^table:' . preg_quote( $wpdb->base_prefix, '/' ) . '\d+_/', $id );
		}
		foreach ( array_merge( $this->shared_tables(), array( $wpdb->base_prefix . '2_posts' ) ) as $table ) {
			$this->assertNotContains( 'table:' . $table, $tables, $table );
			$this->assertSame( 'opex_bad_source', OPEX_Exporter::sanitize_config( array( 'source' => 'table:' . $table, 'fields' => array( 'col:ID' ) ) )->get_error_code(), $table );
		}
		$this->assertSame( $this->members(), $this->export_ids( OPEX_Exporter::create( $this->users_config(), 'main members' ) ) );
	}

	public function test_a_super_admin_still_reaches_everything(): void {
		global $wpdb;
		switch_to_blog( 2 );
		$this->assertFalse( OPEX_Access::restricted() );
		$tables = $this->table_ids();
		foreach ( array( $wpdb->users, $wpdb->base_prefix . 'sitemeta', $wpdb->base_prefix . 'posts', $wpdb->base_prefix . '3_posts' ) as $table ) {
			$this->assertContains( 'table:' . $table, $tables, $table );
		}
		$all = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
		$this->assertSame( $all, $this->user_tile() );
		$config = $this->users_config( array( 'core:ID', 'meta:' . $wpdb->base_prefix . 'capabilities' ) );
		$this->assertArrayNotHasKey( 'wide', $config, 'the saved config shape does not change' );
		$job = OPEX_Exporter::create( $config, 'every user', array( 'mode' => 'background' ) );
		$this->assertTrue( OPEX_Jobs::get( $job['id'] )['wide'], 'the job remembers that its creator may reach every user' );
		$this->assertArrayNotHasKey( 'wide', $job, 'the flag is not exposed' );

		// A background job continues from cron, where there is no user, and still reads every user.
		wp_set_current_user( 0 );
		$this->assertCount( $all, $this->export_ids( $job ) );
	}

	public function test_cron_keeps_a_site_admin_schedule_inside_the_site(): void {
		$uid = $this->site_admin( 2 );
		switch_to_blog( 2 );
		wp_set_current_user( $uid );
		$schedule = OPEX_Schedules::save( array( 'name' => 'Members', 'config' => array( 'source' => 'users', 'fields' => array( 'core:ID' ) ), 'frequency' => 'daily', 'time' => '03:00', 'enabled' => true ) );
		$this->assertIsArray( $schedule, is_wp_error( $schedule ) ? $schedule->get_error_message() : '' );
		$this->assertArrayNotHasKey( 'wide', get_option( 'opex_schedules' )[ $schedule['id'] ], 'a site admin\'s schedule is not wide' );
		$members = $this->members();

		wp_set_current_user( 0 );
		$this->assertTrue( OPEX_Access::restricted(), 'cron has no user, so it is restricted' );
		$this->assertSame( $members, $this->export_ids( OPEX_Schedules::start( $schedule['id'], 'cron' ) ) );
	}

	public function test_a_super_admin_schedule_keeps_working_from_cron_only(): void {
		global $wpdb;
		switch_to_blog( 2 );
		$sitemeta = $wpdb->base_prefix . 'sitemeta';
		$schedule = OPEX_Schedules::save( array( 'name' => 'Network meta', 'config' => array( 'source' => 'table:' . $sitemeta, 'fields' => array( 'col:meta_id', 'col:meta_key' ) ), 'frequency' => 'daily', 'time' => '03:00', 'enabled' => true ) );
		$this->assertIsArray( $schedule, is_wp_error( $schedule ) ? $schedule->get_error_message() : '' );
		$this->assertTrue( get_option( 'opex_schedules' )[ $schedule['id'] ]['wide'], 'stored on the schedule record' );
		$this->assertArrayNotHasKey( 'wide', OPEX_Schedules::expose( $schedule ), 'and never exposed' );
		$this->assertArrayNotHasKey( 'wide', $schedule['config'], 'the config keeps its shape' );
		$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sitemeta}" );

		wp_set_current_user( 0 );
		$this->assertCount( $rows, $this->export_ids( OPEX_Schedules::start( $schedule['id'], 'cron' ) ), 'cron honors the stored wide flag' );
		$this->assertTrue( OPEX_Access::restricted(), 'the flag is lifted only while the job runs' );

		// A site admin cannot use the stored flag: a manual start is refused.
		wp_set_current_user( $this->site_admin( 2 ) );
		$refused = OPEX_Schedules::start( $schedule['id'], 'manual' );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'opex_bad_source', $refused->get_error_code() );

		// Saving it again as a site admin is refused too: the table is not this site's.
		$this->assertInstanceOf( WP_Error::class, OPEX_Schedules::save( array( 'name' => 'Network meta', 'config' => $schedule['config'], 'frequency' => 'daily', 'time' => '03:00' ), $schedule['id'] ) );
	}

	public function test_wide_is_never_taken_from_input(): void {
		$uid = $this->site_admin( 2 );
		switch_to_blog( 2 );
		wp_set_current_user( $uid );
		$config = OPEX_Exporter::sanitize_config( array( 'source' => 'users', 'fields' => array( 'core:ID' ), 'wide' => true ) );
		$this->assertArrayNotHasKey( 'wide', $config );
		// Asking for it in the job settings does nothing for a person.
		$job = OPEX_Exporter::create( $config, 'asks for wide', array( 'wide' => true, 'origin' => 'manual' ) );
		$this->assertFalse( OPEX_Jobs::get( $job['id'] )['wide'] );
		$this->assertSame( $this->members(), $this->export_ids( $job ) );
	}
}
