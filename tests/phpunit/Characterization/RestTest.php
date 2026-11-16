<?php
/**
 * Characterization: REST permissions and responses.
 *
 * @package Open_Export
 */

require_once __DIR__ . '/CharacterizationCase.php';

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every 1.3.1 route denies logged-out and subscriber requests.
 */
class RestTest extends CharacterizationCase {

	/**
	 * Subscriber user id made for the test.
	 *
	 * @var int
	 */
	private $subscriber = 0;

	/**
	 * A done job to address in routes.
	 *
	 * @var string
	 */
	private $job_id = '';

	/**
	 * Create a subscriber and a job.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->subscriber = (int) wp_insert_user( array( 'user_login' => 'opex_sub_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
		$job              = OPEX_Exporter::create( $this->config( array( 'source' => 'terms:category', 'fields' => array( 'core:term_id' ) ) ), 'rest' );
		$this->job_id     = $job['id'];
		$this->job_ids[]  = $job['id'];
	}

	/**
	 * Remove the subscriber.
	 */
	protected function tearDown(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $this->subscriber );
		parent::tearDown();
	}

	/**
	 * All routes: method, path (with {job} and {tpl} placeholders), body.
	 *
	 * @return array
	 */
	public static function routes(): array {
		return array(
			'sources'         => array( 'GET', '/open-export/v1/sources', null ),
			'fields'          => array( 'GET', '/open-export/v1/fields?source=users', null ),
			'schema'          => array( 'GET', '/open-export/v1/schema', null ),
			'recipe'          => array( 'POST', '/open-export/v1/recipe', array( 'source' => 'users', 'fields' => array( 'core:ID' ) ) ),
			'preview'         => array( 'POST', '/open-export/v1/preview', array( 'source' => 'users', 'fields' => array( 'core:ID' ) ) ),
			'jobs list'       => array( 'GET', '/open-export/v1/jobs', null ),
			'jobs create'     => array( 'POST', '/open-export/v1/jobs', array( 'source' => 'users', 'fields' => array( 'core:ID' ) ) ),
			'job get'         => array( 'GET', '/open-export/v1/jobs/{job}', null ),
			'job delete'      => array( 'DELETE', '/open-export/v1/jobs/{job}', null ),
			'job rerun'       => array( 'POST', '/open-export/v1/jobs/{job}/rerun', null ),
			'job run'         => array( 'POST', '/open-export/v1/jobs/{job}/run', null ),
			'job pause'       => array( 'POST', '/open-export/v1/jobs/{job}/pause', null ),
			'job download'    => array( 'GET', '/open-export/v1/jobs/{job}/download', null ),
			'templates list'  => array( 'GET', '/open-export/v1/templates', null ),
			'templates save'  => array( 'POST', '/open-export/v1/templates', array( 'name' => 'x', 'source' => 'users', 'fields' => array( 'core:ID' ) ) ),
			'template delete' => array( 'DELETE', '/open-export/v1/templates/t000000000000', null ),
		);
	}

	/**
	 * Run a request.
	 *
	 * @param string     $method Method.
	 * @param string     $path   Path with placeholders.
	 * @param array|null $body   JSON body.
	 * @return WP_REST_Response
	 */
	private function call( string $method, string $path, $body ): WP_REST_Response {
		$path = str_replace( '{job}', $this->job_id, $path );
		$qs   = array();
		if ( false !== strpos( $path, '?' ) ) {
			list( $path, $query ) = explode( '?', $path, 2 );
			parse_str( $query, $qs );
		}
		$req = new WP_REST_Request( $method, $path );
		$req->set_query_params( $qs );
		if ( null !== $body ) {
			$req->set_header( 'content-type', 'application/json' );
			$req->set_body( wp_json_encode( $body ) );
		}
		return rest_do_request( $req );
	}

	/**
	 * Logged-out requests are refused.
	 *
	 * @param string     $method Method.
	 * @param string     $path   Path.
	 * @param array|null $body   Body.
	 * @dataProvider routes
	 */
	#[DataProvider( 'routes' )]
	public function test_logged_out_is_denied( string $method, string $path, $body ): void {
		wp_set_current_user( 0 );
		$status = $this->call( $method, $path, $body )->get_status();
		$this->assertContains( $status, array( 401, 403 ) );
		wp_set_current_user( 1 );
		$this->assertNotNull( OPEX_Jobs::get( $this->job_id ), 'the denied request changed nothing' );
	}

	/**
	 * Subscribers are refused.
	 *
	 * @param string     $method Method.
	 * @param string     $path   Path.
	 * @param array|null $body   Body.
	 * @dataProvider routes
	 */
	#[DataProvider( 'routes' )]
	public function test_subscriber_is_denied( string $method, string $path, $body ): void {
		wp_set_current_user( $this->subscriber );
		$status = $this->call( $method, $path, $body )->get_status();
		$this->assertContains( $status, array( 401, 403 ) );
		wp_set_current_user( 1 );
		$this->assertNotNull( OPEX_Jobs::get( $this->job_id ), 'the denied request changed nothing' );
	}

	/**
	 * The capability filter changes who is allowed.
	 */
	public function test_capability_filter(): void {
		wp_set_current_user( $this->subscriber );
		$this->assertSame( 403, $this->call( 'GET', '/open-export/v1/sources', null )->get_status() );
		$allow = static function () {
			return 'read';
		};
		add_filter( 'opex_capability', $allow );
		$this->assertSame( 200, $this->call( 'GET', '/open-export/v1/sources', null )->get_status() );
		remove_filter( 'opex_capability', $allow );
		$this->assertSame( 'manage_options', opex_capability() );
	}

	/**
	 * Admin GET routes answer 200 with the documented shapes.
	 */
	public function test_admin_get_routes(): void {
		$sources = $this->call( 'GET', '/open-export/v1/sources', null );
		$this->assertSame( 200, $sources->get_status() );
		$this->assertSame( array( 'id', 'tier', 'label', 'items' ), array_keys( $sources->get_data()[0] ) );

		$fields = $this->call( 'GET', '/open-export/v1/fields?source=users', null );
		$this->assertSame( 200, $fields->get_status() );
		$this->assertSame( array( 'groups', 'date_fields', 'presets', 'timezone' ), array_keys( $fields->get_data() ) );

		$this->assertSame( 400, $this->call( 'GET', '/open-export/v1/fields', null )->get_status(), 'source is required' );

		$schema = $this->call( 'GET', '/open-export/v1/schema?source=users', null );
		$this->assertSame( 200, $schema->get_status() );
		$this->assertSame( 1, $schema->get_data()['opex_schema'] );
		$this->assertSame( 400, $this->call( 'GET', '/open-export/v1/schema?scope=bogus', null )->get_status(), 'the scope enum is enforced' );

		$jobs = $this->call( 'GET', '/open-export/v1/jobs', null );
		$this->assertSame( 200, $jobs->get_status() );
		$this->assertSame( array( 'id', 'label', 'format', 'status', 'total', 'processed', 'created', 'filename', 'filesize', 'error', 'mode', 'origin', 'schedule_id', 'deliveries', 'notes', 'delivery_name', 'finished', 'heartbeat', 'schedule_name', 'source', 'source_label', 'sites', 'site_index' ), array_keys( $jobs->get_data()[0] ) );

		$job = $this->call( 'GET', '/open-export/v1/jobs/{job}', null );
		$this->assertSame( 200, $job->get_status() );
		$this->assertArrayHasKey( 'config', $job->get_data() );
		$this->assertSame( 404, $this->call( 'GET', '/open-export/v1/jobs/j000000000000', null )->get_status() );

		$tpl = $this->call( 'GET', '/open-export/v1/templates', null );
		$this->assertSame( 200, $tpl->get_status() );
	}

	/**
	 * Admin POST routes.
	 */
	public function test_admin_post_routes(): void {
		$preview = $this->call( 'POST', '/open-export/v1/preview', array( 'source' => 'users', 'fields' => array( 'core:ID', 'core:user_login' ) ) );
		$this->assertSame( 200, $preview->get_status() );
		$this->assertSame( array( 'headers', 'rows', 'total', 'notes' ), array_keys( $preview->get_data() ) );
		$this->assertSame( 400, $this->call( 'POST', '/open-export/v1/preview', array( 'source' => 'nope', 'fields' => array( 'core:ID' ) ) )->get_status() );

		$create = $this->call( 'POST', '/open-export/v1/jobs', array( 'source' => 'terms:category', 'fields' => array( 'core:term_id' ), 'label' => 'From <b>REST</b>' ) );
		$this->assertSame( 200, $create->get_status() );
		$this->job_ids[] = $create->get_data()['id'];
		$this->assertSame( 'From REST', $create->get_data()['label'] );
		$this->assertSame( 'running', $create->get_data()['status'] );

		$recipe = $this->call( 'POST', '/open-export/v1/recipe', array( 'recipe' => array( 'source' => 'users', 'fields' => array( 'core:ID' ) ) ) );
		$this->assertSame( 200, $recipe->get_status(), 'a recipe wrapped in an outer key is accepted' );
		$this->assertTrue( $recipe->get_data()['ok'] );
		$empty = $this->call( 'POST', '/open-export/v1/recipe', array( 'source' => 'users', 'fields' => array( 'core:nope' ) ) );
		$this->assertSame( 200, $empty->get_status(), 'a recipe that resolves to nothing is a 200 with ok=false' );
		$this->assertFalse( $empty->get_data()['ok'] );
		$this->assertSame( 400, $this->call( 'POST', '/open-export/v1/recipe', array( 'source' => 'nope', 'fields' => array( 'x' ) ) )->get_status() );

		$run = $this->call( 'POST', '/open-export/v1/jobs/{job}/run', null );
		$this->assertSame( 200, $run->get_status() );
		$this->assertSame( 'done', $run->get_data()['status'] );
		$this->assertSame( 404, $this->call( 'POST', '/open-export/v1/jobs/j000000000000/run', null )->get_status() );
		$this->assertSame( 404, $this->call( 'POST', '/open-export/v1/jobs/j000000000000/pause', null )->get_status() );
		$this->assertSame( 404, $this->call( 'POST', '/open-export/v1/jobs/j000000000000/rerun', null )->get_status() );
		$this->assertSame( 404, $this->call( 'GET', '/open-export/v1/jobs/j000000000000/download', null )->get_status() );
	}

	/**
	 * Route ids that do not match the pattern do not exist.
	 */
	public function test_bad_route_ids(): void {
		$this->assertSame( 404, $this->call( 'GET', '/open-export/v1/jobs/not-a-job', null )->get_status() );
		$this->assertSame( 404, $this->call( 'DELETE', '/open-export/v1/templates/xyz', null )->get_status() );
	}

	/**
	 * The set of registered 1.3.1 routes and methods.
	 */
	public function test_registered_routes(): void {
		$routes = rest_get_server()->get_routes( 'open-export/v1' );
		$map    = array();
		foreach ( $routes as $pattern => $handlers ) {
			$methods = array();
			foreach ( $handlers as $h ) {
				$methods = array_merge( $methods, array_keys( $h['methods'] ) );
			}
			sort( $methods );
			$map[ $pattern ] = $methods;
		}
		ksort( $map );
		foreach ( $map as $pattern => $methods ) {
			if ( 0 === strpos( $pattern, '/open-export/v1/' ) || '/open-export/v1' === $pattern ) {
				continue;
			}
		}
		$core = array_intersect_key( $map, array_flip( array(
			'/open-export/v1/sources', '/open-export/v1/fields', '/open-export/v1/schema', '/open-export/v1/recipe', '/open-export/v1/preview',
			'/open-export/v1/jobs', '/open-export/v1/jobs/(?P<id>j[a-f0-9]{12})', '/open-export/v1/jobs/(?P<id>j[a-f0-9]{12})/rerun',
			'/open-export/v1/jobs/(?P<id>j[a-f0-9]{12})/run', '/open-export/v1/jobs/(?P<id>j[a-f0-9]{12})/pause', '/open-export/v1/jobs/(?P<id>j[a-f0-9]{12})/download',
			'/open-export/v1/templates', '/open-export/v1/templates/(?P<id>t[a-f0-9]{12})',
		) ) );
		$this->assertCount( 13, $core, 'all 13 shipped route patterns still exist' );
		$this->assert_golden_json( 'rest-routes', $core );
	}
}
