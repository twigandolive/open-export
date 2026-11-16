<?php
/**
 * Network exports on multisite: one config over every site into one file.
 *
 * Runs on the multisite test site (tests/bin/setup-site.sh --multisite):
 * the main site, /second/ with 3 posts and /third/ with 5, WooCommerce on
 * the main site only. Every test but the single-site refusal skips itself
 * on a single site.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class NetworkExportTest extends TestCase {

	/** @var string[] */
	private array $jobs = array();

	/** @var int[] */
	private array $users = array();

	protected function setUp(): void {
		wp_set_current_user( 1 );
		$GLOBALS['wp_rest_server'] = null;
	}

	protected function tearDown(): void {
		while ( function_exists( 'ms_is_switched' ) && ms_is_switched() ) {
			restore_current_blog();
		}
		wp_set_current_user( 1 );
		remove_all_filters( 'post_link' );
		foreach ( $this->jobs as $id ) {
			wp_clear_scheduled_hook( 'opex_run_job', array( (string) $id ) );
			OPEX_Jobs::delete( $id );
		}
		foreach ( array_keys( OPEX_Schedules::all() ) as $id ) {
			OPEX_Schedules::delete( (string) $id );
		}
		delete_option( 'opex_schedules' );
		if ( $this->users ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
			foreach ( $this->users as $uid ) {
				wpmu_delete_user( $uid );
			}
		}
		$GLOBALS['wp_rest_server'] = null;
	}

	/** Skip unless this is the multisite test network. */
	private function needs_network(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network exports need the multisite test site.' );
		}
		$this->assertGreaterThanOrEqual( 3, count( OPEX_Network::site_ids() ), 'the test network has three sites' );
	}

	/** A sanitized network config over posts. */
	private function config( array $over = array() ): array {
		$config = OPEX_Exporter::sanitize_config( array_merge(
			array( 'source' => 'posts:post', 'fields' => array( 'core:ID', 'core:post_title' ), 'batch_size' => 10, 'format' => 'csv', 'network' => true ),
			$over
		) );
		$this->assertIsArray( $config, is_wp_error( $config ) ? $config->get_error_message() : '' );
		return $config;
	}

	/** Create a job and remember it for clean up. */
	private function job( array $config, string $origin = 'manual' ): array {
		$job = OPEX_Exporter::create( $config, 'Network test', array( 'origin' => $origin ) );
		$this->assertIsArray( $job, is_wp_error( $job ) ? $job->get_error_message() : '' );
		$this->jobs[] = $job['id'];
		return $job;
	}

	/** Run a job until it stops running, checking the current site after each batch. */
	private function finish( string $id ): array {
		for ( $i = 0; $i < 200; $i++ ) {
			$job = OPEX_Exporter::run( $id );
			$this->assertIsArray( $job );
			$this->assertSame( 1, get_current_blog_id(), 'the current site comes back after every batch' );
			$this->assertFalse( ms_is_switched() );
			if ( 'running' !== $job['status'] ) {
				return $job;
			}
		}
		$this->fail( 'The job did not finish.' );
	}

	/** File content of a job. */
	private function content( string $id ): string {
		return (string) file_get_contents( OPEX_Jobs::file_path( OPEX_Jobs::get( $id ) ) );
	}

	/** CSV rows of a job, header first. */
	private function csv( string $id ): array {
		$text   = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $this->content( $id ) );
		$handle = fopen( 'php://memory', 'r+' );
		fwrite( $handle, $text );
		rewind( $handle );
		$rows = array();
		while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) ) {
			$rows[] = $row;
		}
		fclose( $handle );
		return $rows;
	}

	/** Posts the main site exports for the default config, counted on the main site alone. */
	private function main_site_posts(): int {
		$single = $this->config( array( 'network' => false ) );
		return OPEX_Query::count( $single );
	}

	/** A network administrator of the main site who is not a super admin. */
	private function site_admin(): int {
		$uid = wp_insert_user( array( 'user_login' => 'opexnetadmin' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'opexnetadmin' . wp_rand() . '@example.test', 'role' => 'administrator' ) );
		$this->assertIsInt( $uid );
		$this->users[] = $uid;
		$this->assertFalse( is_super_admin( $uid ) );
		return $uid;
	}

	public function test_rows_from_every_site_in_one_file(): void {
		$this->needs_network();
		$job = $this->job( $this->config() );
		$this->assertSame( 3, $job['sites'] );
		$this->assertSame( 0, $job['site_index'] );
		$main = $this->main_site_posts();
		$this->assertSame( $main + 3 + 5, $job['total'], 'the total is the sum of every site at creation' );

		$done = $this->finish( $job['id'] );
		$this->assertSame( 'done', $done['status'] );
		$this->assertSame( $main + 8, $done['processed'] );

		$rows   = $this->csv( $job['id'] );
		$header = array_shift( $rows );
		$this->assertSame( array( 'Site ID', 'Site URL' ), array_slice( $header, 0, 2 ), 'the site columns come first' );
		$this->assertCount( 4, $header );
		$by_site = array();
		foreach ( $rows as $row ) {
			$by_site[ $row[0] ][] = $row;
			$this->assertSame( get_home_url( (int) $row[0] ), $row[1] );
		}
		$this->assertSame( array( '1', '2', '3' ), array_map( 'strval', array_keys( $by_site ) ), 'sites in id order' );
		$this->assertCount( $main, $by_site['1'] );
		$this->assertCount( 3, $by_site['2'] );
		$this->assertCount( 5, $by_site['3'] );
		foreach ( $by_site['2'] as $row ) {
			$this->assertStringStartsWith( 'Sub-site second post', $row[3] );
		}
		foreach ( $by_site['3'] as $row ) {
			$this->assertStringStartsWith( 'Sub-site third post', $row[3] );
		}
		$this->assertStringContainsString( '/second', $by_site['2'][0][1] );
	}

	public function test_json_and_xml_template_carry_the_site_columns(): void {
		$this->needs_network();
		$json = $this->job( $this->config( array( 'format' => 'json' ) ) );
		$this->finish( $json['id'] );
		$data = json_decode( $this->content( $json['id'] ), true );
		$this->assertIsArray( $data );
		$this->assertSame( array( 'Site ID', 'Site URL', 'ID', 'post_title' ), array_slice( array_keys( $data[0] ), 0, 4 ) );
		$this->assertSame( '3', $data[ count( $data ) - 1 ]['Site ID'] );

		$xml = $this->job( $this->config( array( 'format' => 'xml', 'xml_template' => array( 'header' => '<items>', 'row' => '<item site="{site_id}">{core:post_title}</item>', 'footer' => '</items>' ) ) ) );
		$this->finish( $xml['id'] );
		$this->assertStringContainsString( '<item site="2">Sub-site second post', $this->content( $xml['id'] ) );
		$this->assertNotFalse( simplexml_load_string( $this->content( $xml['id'] ) ) );
	}

	public function test_counts_and_progress_across_the_site_change(): void {
		$this->needs_network();
		$job   = $this->job( $this->config() );
		$total = $job['total'];
		$last  = array( 'site_index' => 0, 'processed' => 0 );
		$moves = 0;
		for ( $i = 0; $i < 50; $i++ ) {
			$now = OPEX_Exporter::run( $job['id'] );
			$rec = OPEX_Jobs::get( $job['id'] );
			$this->assertSame( $total, $now['total'], 'the total stays fixed' );
			$this->assertGreaterThanOrEqual( $last['processed'], $now['processed'], 'processed only grows' );
			$this->assertGreaterThanOrEqual( $last['site_index'], $now['site_index'] );
			if ( $now['site_index'] > $last['site_index'] ) {
				++$moves;
				$this->assertSame( $last['site_index'] + 1, $now['site_index'], 'one site at a time' );
				$this->assertSame( 0, (int) $rec['cursor'], 'the cursor restarts for the next site' );
			}
			$this->assertSame( array_values( OPEX_Network::site_ids() ), array_map( 'intval', $rec['sites'] ), 'the site list is fixed at creation' );
			$last = $now;
			if ( 'running' !== $now['status'] ) {
				break;
			}
		}
		$this->assertSame( 'done', $last['status'] );
		$this->assertSame( 2, $moves );
		$this->assertSame( $total, $last['processed'] );
	}

	public function test_a_replayed_batch_at_a_site_boundary_writes_rows_once(): void {
		$this->needs_network();
		// A batch size that leaves rows in the main site's last batch, so the batch that ends it is not empty.
		$size  = 0 === $this->main_site_posts() % 10 ? 11 : 10;
		$clean = $this->job( $this->config( array( 'batch_size' => $size ) ) );
		$this->finish( $clean['id'] );

		$crash   = $this->job( $this->config( array( 'batch_size' => $size ) ) );
		$noop    = static function (): void {};
		$crashed = 0;
		for ( $i = 0; $i < 50; $i++ ) {
			$rec  = OPEX_Jobs::get( $crash['id'] );
			$peek = OPEX_Network::batch( $rec, $rec['config'], $noop );
			if ( $peek['next_site'] || ( (int) $rec['site_index'] > 0 && 0 === (int) $rec['cursor'] ) ) {
				// The process dies after the file write but before the job save: the batch that
				// ends a site, and the first batch of the next one.
				$this->assertNotEmpty( $peek['items'] );
				OPEX_Writer::make( 'csv', OPEX_Jobs::file_path( $rec ), $rec['headers'] )->append( $peek['items'], false );
				++$crashed;
			}
			$now = OPEX_Exporter::run( $crash['id'] );
			if ( 'running' !== $now['status'] ) {
				break;
			}
		}
		$this->assertSame( 3, $crashed, 'the end of site 1, site 2 (first and last batch at once) and the start of site 3' );
		$this->assertSame( 'done', $now['status'] );
		$this->assertSame( $this->content( $clean['id'] ), $this->content( $crash['id'] ), 'every row is written once' );
		$this->assertSame( $clean['total'], $now['processed'] );
	}

	public function test_a_paused_network_job_resumes_where_it_stopped(): void {
		$this->needs_network();
		$clean = $this->job( $this->config() );
		$this->finish( $clean['id'] );

		$job = $this->job( $this->config() );
		for ( $i = 0; $i < 50 && 0 === OPEX_Jobs::get( $job['id'] )['site_index']; $i++ ) {
			OPEX_Exporter::run( $job['id'] );
		}
		$before = OPEX_Jobs::get( $job['id'] );
		$this->assertSame( 1, (int) $before['site_index'] );
		$this->assertSame( 'paused', OPEX_Exporter::pause( $job['id'] )['status'] );
		$still = OPEX_Exporter::run( $job['id'] );
		$this->assertSame( 'paused', $still['status'], 'a late run does not undo the pause' );
		$this->assertSame( (int) $before['processed'], $still['processed'] );

		$resumed = OPEX_Exporter::run( $job['id'], true );
		$this->assertSame( 'running', $resumed['status'] );
		$this->assertSame( 2, $resumed['site_index'], 'the second site has 3 rows, so one batch moves on' );
		$done = $this->finish( $job['id'] );
		$this->assertSame( 'done', $done['status'] );
		$this->assertSame( $this->content( $clean['id'] ), $this->content( $job['id'] ) );
	}

	public function test_a_site_without_the_source_adds_no_rows_and_a_note(): void {
		$this->needs_network();
		if ( ! OPEX_Woo::is_active() ) {
			$this->markTestSkipped( 'WooCommerce is not active on the main site.' );
		}
		foreach ( array( 'woo:orders' => 'woo:order_id', 'woo:customers' => 'woo:customer_id' ) as $source => $field ) {
			$config = $this->config( array( 'source' => $source, 'fields' => array( $field ) ) );
			$single = OPEX_Query::count( array_merge( $config, array( 'network' => false ) ) );
			$job    = $this->job( $config );
			$this->assertSame( $single, $job['total'], $source . ': only the main site counts' );
			$done = $this->finish( $job['id'] );
			$this->assertSame( 'done', $done['status'], $source . ': ' . $done['error'] );
			$this->assertSame( $single, $done['processed'] );
			$notes = implode( "\n", $done['notes'] );
			$this->assertStringContainsString( 'Site 2 (' . get_home_url( 2 ) . ')', $notes, $source );
			$this->assertStringContainsString( 'Site 3 (' . get_home_url( 3 ) . ')', $notes, $source );
			$this->assertStringNotContainsString( 'Site 1 (', $notes );
			$this->assertCount( 2, $done['notes'], 'each note once, not once per batch' );
			foreach ( array_slice( $this->csv( $job['id'] ), 1 ) as $row ) {
				$this->assertSame( '1', $row[0], $source . ': rows come from the main site only' );
			}
		}
	}

	/** A post type with content on the main site only: the other sites add no rows, and each says why once. */
	public function test_a_site_without_the_post_type_gets_a_note(): void {
		$this->needs_network();
		register_post_type( 'opex_netprobe', array( 'public' => true ) );
		$post = (int) wp_insert_post(
			array(
				'post_type'   => 'opex_netprobe',
				'post_title'  => 'Only on site 1',
				'post_status' => 'publish',
			)
		);
		try {
			$this->assertSame( 'ok', OPEX_Network::source_state( 'posts:opex_netprobe' ) );
			switch_to_blog( 2 );
			$state = OPEX_Network::source_state( 'posts:opex_netprobe' );
			restore_current_blog();
			$this->assertSame( 'empty', $state, 'registered for the request, but no content on site 2' );

			$job  = $this->job( $this->config( array( 'source' => 'posts:opex_netprobe' ) ) );
			$done = $this->finish( $job['id'] );
			$this->assertSame( 'done', $done['status'], $done['error'] );
			$this->assertSame( 1, $done['processed'] );
			$notes = implode( "\n", $done['notes'] );
			$this->assertStringContainsString( 'Site 2 (' . get_home_url( 2 ) . ') has no content of this type', $notes );
			$this->assertStringContainsString( 'Site 3 (' . get_home_url( 3 ) . ') has no content of this type', $notes );
			$this->assertStringNotContainsString( 'Site 1 (', $notes );
		} finally {
			wp_delete_post( $post, true );
			unregister_post_type( 'opex_netprobe' );
		}
	}

	public function test_preview_reads_this_site_only_and_says_so(): void {
		$this->needs_network();
		$config  = $this->config();
		$preview = OPEX_Exporter::preview( $config );
		$this->assertSame( array( 'Site ID', 'Site URL', 'ID', 'post_title' ), $preview['headers'] );
		$this->assertSame( $this->main_site_posts(), $preview['total'] );
		$this->assertSame( array( '1' ), array_values( array_unique( array_column( $preview['rows'], 0 ) ) ) );
		$this->assertSame( array( OPEX_Network::preview_note() ), $preview['notes'] );
		$renamed = OPEX_Exporter::preview( $this->config( array( 'column_labels' => array( 'core:ID' => 'Site ID' ) ) ) );
		$this->assertSame( array( 'Site ID', 'Site URL', 'Site ID_2', 'post_title' ), $renamed['headers'], 'a label that clashes with a site column gets a number' );

		$request = new WP_REST_Request( 'POST', '/open-export/v1/preview' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $config ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( OPEX_Network::preview_note(), $response->get_data()['notes'] );
	}

	public function test_refusals(): void {
		$this->needs_network();
		foreach ( array( 'users' => array( 'core:ID' ), 'table:' . $GLOBALS['wpdb']->posts => array( 'col:ID' ) ) as $source => $fields ) {
			$refused = OPEX_Exporter::sanitize_config( array( 'source' => $source, 'fields' => $fields, 'network' => true ) );
			$this->assertInstanceOf( WP_Error::class, $refused, $source );
			$this->assertSame( 'opex_bad_network', $refused->get_error_code() );
			$this->assertStringContainsString( 'users' === $source ? 'shares the same users' : 'its own tables', $refused->get_error_message() );
			$plain = OPEX_Exporter::sanitize_config( array( 'source' => $source, 'fields' => $fields ) );
			$this->assertIsArray( $plain );
			$this->assertSame( 'opex_bad_network', OPEX_Exporter::create( array_merge( $plain, array( 'network' => true ) ), 'x', array( 'origin' => 'cron' ) )->get_error_code(), 'create refuses it too' );
		}

		$config = $this->config();
		wp_set_current_user( $this->site_admin() );
		$this->assertTrue( current_user_can( 'manage_options' ) );
		$refused = OPEX_Exporter::sanitize_config( $config );
		$this->assertSame( 'opex_bad_network', $refused->get_error_code(), 'a site admin who is not a super admin is refused' );
		$this->assertSame( 'opex_bad_network', OPEX_Exporter::create( $config, 'x' )->get_error_code(), 'a person cannot create one from a stored config' );
		$this->assertIsArray( OPEX_Exporter::sanitize_config( array_merge( $config, array( 'network' => false ) ) ) );
		$schedule = OPEX_Schedules::save( array( 'name' => 'Net', 'config' => $config, 'frequency' => 'daily', 'time' => '03:00', 'enabled' => true ) );
		$this->assertInstanceOf( WP_Error::class, $schedule );
	}

	public function test_single_site_refuses_a_network_export(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'This check is for a single site.' );
		}
		$refused = OPEX_Exporter::sanitize_config( array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'network' => true ) );
		$this->assertSame( 'opex_bad_network', $refused->get_error_code() );
		$plain = OPEX_Exporter::sanitize_config( array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ) ) );
		foreach ( array( 'manual', 'cron', 'cli' ) as $origin ) {
			$this->assertSame( 'opex_bad_network', OPEX_Exporter::create( array_merge( $plain, array( 'network' => true ) ), 'x', array( 'origin' => $origin ) )->get_error_code() );
		}
	}

	public function test_a_schedule_keeps_running_from_cron(): void {
		$this->needs_network();
		$schedule = OPEX_Schedules::save( array( 'name' => 'Network nightly', 'config' => $this->config(), 'frequency' => 'daily', 'time' => '03:00', 'enabled' => true ) );
		$this->assertIsArray( $schedule, is_wp_error( $schedule ) ? $schedule->get_error_message() : '' );
		$this->assertTrue( $schedule['config']['network'] );

		wp_set_current_user( 0 );
		$job = OPEX_Schedules::start( $schedule['id'], 'cron' );
		$this->assertIsArray( $job, is_wp_error( $job ) ? $job->get_error_message() : '' );
		$this->jobs[] = $job['id'];
		$this->assertSame( 'cron', $job['origin'] );
		$this->assertSame( 3, $job['sites'] );
		$done = OPEX_Runner::work( $job['id'], 60 );
		$this->assertSame( 'done', $done['status'], (string) $done['error'] );
		$this->assertSame( 1, get_current_blog_id() );
		$this->assertSame( $job['total'], $done['processed'] );
		$sites = array_unique( array_column( array_slice( $this->csv( $job['id'] ), 1 ), 0 ) );
		$this->assertSame( array( '1', '2', '3' ), array_values( $sites ) );
	}

	public function test_the_current_site_comes_back_after_a_forced_error(): void {
		$this->needs_network();
		$job = $this->job( $this->config( array( 'fields' => array( 'core:ID', 'computed:permalink' ) ) ) );
		add_filter( 'post_link', static function ( $link ) {
			if ( 3 === get_current_blog_id() ) {
				throw new RuntimeException( 'forced failure on the third site' );
			}
			return $link;
		} );
		$failed = null;
		for ( $i = 0; $i < 50; $i++ ) {
			$failed = OPEX_Exporter::run( $job['id'] );
			$this->assertSame( 1, get_current_blog_id(), 'the current site comes back, also after an error' );
			$this->assertFalse( ms_is_switched() );
			if ( 'running' !== $failed['status'] ) {
				break;
			}
		}
		$this->assertSame( 'error', $failed['status'] );
		$this->assertSame( 2, $failed['site_index'] );
		$this->assertSame( 'error', OPEX_Jobs::get( $job['id'] )['status'], 'the error is saved on the job\'s own site' );

		remove_all_filters( 'post_link' );
		$done = $this->finish( $job['id'] );
		$this->assertSame( 'done', $done['status'] );
		$rows = array_slice( $this->csv( $job['id'] ), 1 );
		$this->assertCount( $job['total'], $rows, 'every row once after the retry' );
		$third = array_values( array_filter( $rows, static function ( $row ) {
			return '3' === $row[0];
		} ) );
		$this->assertCount( 5, $third );
		$this->assertStringContainsString( '/third/', $third[0][3], 'permalinks are built inside their own site' );
	}

	public function test_cli_trust_recipe_and_document(): void {
		$this->needs_network();
		$doc = OPEX_Schema::build();
		$this->assertTrue( $doc['network']['available'] );
		$this->assertSame( array( 'site_id', 'site_url' ), $doc['network']['columns'] );
		$this->assertArrayHasKey( 'network', $doc['recipe_format'] );

		$recipe = array( 'opex_recipe' => 1, 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'network' => true );
		$result = OPEX_Recipe::to_config( $recipe );
		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['config']['network'] );

		wp_set_current_user( $this->site_admin() );
		$notes  = array();
		$result = OPEX_Recipe::to_config( $recipe, $notes );
		$this->assertTrue( $result['ok'], 'the recipe still imports' );
		$this->assertArrayNotHasKey( 'network', $result['config'], 'without the network flag' );
		$this->assertStringContainsString( 'this site only', implode( ' ', array_merge( $notes, $result['notes'] ) ) );
		$this->assertFalse( OPEX_Schema::build()['network']['available'] );
	}

	/** Run WP-CLI against the test site with the plugin's commands loaded, as CliTest does. */
	private function wp_cli( array $args, string $stdin = '' ): array {
		$cmd  = array_merge(
			array(
				PHP_BINARY,
				'-d',
				'memory_limit=1G',
				'-d',
				'error_reporting=22527',
				trim( (string) shell_exec( 'command -v wp 2>/dev/null' ) ),
				'--path=' . rtrim( (string) getenv( 'OPEX_TEST_SITE' ), '/' ) . '/wordpress',
				'--exec=WP_CLI::add_hook("after_wp_load", function(){ require_once "' . dirname( __DIR__, 2 ) . '/includes/class-opex-cli.php"; OPEX_CLI::register(); });',
			),
			$args
		);
		$proc = proc_open( $cmd, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $proc );
		fwrite( $pipes[0], $stdin );
		fclose( $pipes[0] );
		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return array( 'code' => proc_close( $proc ), 'out' => trim( $out ), 'err' => $err );
	}

	public function test_cli_runs_network_exports(): void {
		$this->needs_network();
		if ( '' === trim( (string) shell_exec( 'command -v wp 2>/dev/null' ) ) ) {
			$this->markTestSkipped( 'WP-CLI is not installed.' );
		}
		$before   = array_keys( OPEX_Jobs::all() );
		$template = OPEX_Templates::save( 'Network CLI template', array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'batch_size' => 10 ) );
		$this->assertIsArray( $template );
		try {
			$config = (string) wp_json_encode( array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'network' => true ) );
			$runs   = array(
				'config file, no --user' => $this->wp_cli( array( 'opex', 'export', '--config=-', '--porcelain' ), $config ),
				'template with --network' => $this->wp_cli( array( 'opex', 'run', $template['id'], '--network', '--porcelain' ) ),
			);
			foreach ( $runs as $what => $res ) {
				$this->assertSame( 0, $res['code'], $what . ': ' . $res['err'] );
				$this->assertFileExists( $res['out'], $what );
				$lines = array_slice( array_filter( explode( "\n", (string) file_get_contents( $res['out'] ) ) ), 1 );
				$sites = array_values( array_unique( array_map( static function ( $line ) {
					return (string) strtok( (string) preg_replace( '/^\xEF\xBB\xBF/', '', $line ), ',' );
				}, $lines ) ) );
				$this->assertSame( array( '1', '2', '3' ), $sites, $what );
			}
		} finally {
			OPEX_Templates::delete( $template['id'] );
			foreach ( array_diff( array_keys( OPEX_Jobs::all() ), $before ) as $id ) {
				OPEX_Jobs::delete( (string) $id );
			}
		}
	}

	public function test_plain_configs_keep_their_shape(): void {
		$this->needs_network();
		$plain = OPEX_Exporter::sanitize_config( array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ) ) );
		$this->assertArrayNotHasKey( 'network', $plain, 'a config without network saves as before' );
		$job = OPEX_Exporter::create( $plain, 'plain' );
		$this->jobs[] = $job['id'];
		$stored = get_option( 'opex_jobs' )[ $job['id'] ];
		$this->assertArrayNotHasKey( 'sites', $stored );
		$this->assertArrayNotHasKey( 'site_index', $stored );
		$this->assertSame( 0, $job['sites'] );
		$this->assertSame( array( 'ID' ), array_values( OPEX_Jobs::get( $job['id'] )['headers'] ) );
	}
}
