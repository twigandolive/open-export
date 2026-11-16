<?php
/**
 * Tests for the data the 2.0 screen reads from existing routes: options,
 * field types and group ids, preview and schedule notes, job fields, the
 * network refusal, and destination host key reset and last test.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Additive response fields from spec 3.5.
 */
class ScreenDataTest extends TestCase {

	/** Plugin names the catalog uses as meta group labels, never translated. */
	const PLUGIN_GROUPS = array( 'WooCommerce', 'ACF', 'Yoast SEO', 'Rank Math', 'All in One SEO', 'SEOPress', 'Elementor', 'Divi', 'WPBakery', 'Jetpack', 'WPML', 'Polylang', 'WordPress Core' );

	/** @var string[] */
	private $jobs = array();

	/** @var string[] */
	private $schedules = array();

	protected function setUp(): void {
		delete_option( 'opex_destinations' );
		// Other tests remove the plugin's own route hooks in tearDown, so put back any that are missing.
		foreach ( array( 'OPEX_Rest', 'OPEX_Rest_Schedules', 'OPEX_Rest_Delivery' ) as $class ) {
			if ( false === has_action( 'rest_api_init', array( $class, 'register_routes' ) ) ) {
				add_action( 'rest_api_init', array( $class, 'register_routes' ) );
			}
		}
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
		wp_set_current_user( 1 );
	}

	protected function tearDown(): void {
		remove_all_filters( 'gettext' );
		foreach ( $this->jobs as $id ) {
			wp_clear_scheduled_hook( 'opex_run_job', array( $id ) );
			OPEX_Jobs::delete( $id );
		}
		foreach ( $this->schedules as $id ) {
			OPEX_Schedules::delete( $id );
		}
		delete_option( 'opex_destinations' );
		$GLOBALS['wp_rest_server'] = null;
	}

	/**
	 * Runs a request.
	 *
	 * @param string $method Method.
	 * @param string $path   Path below the namespace.
	 * @param array  $body   JSON body.
	 * @return WP_REST_Response
	 */
	private function call( string $method, string $path, array $body = array() ) {
		$query = '';
		if ( false !== strpos( $path, '?' ) ) {
			list( $path, $query ) = explode( '?', $path, 2 );
		}
		$req = new WP_REST_Request( $method, '/open-export/v1' . $path );
		if ( '' !== $query ) {
			parse_str( $query, $params );
			$req->set_query_params( $params );
		}
		if ( $body ) {
			$req->set_header( 'content-type', 'application/json' );
			$req->set_body( wp_json_encode( $body ) );
		}
		return rest_do_request( $req );
	}

	/**
	 * Fields of a source by id, from GET /fields.
	 *
	 * @param string $source Source id.
	 * @return array
	 */
	private function fields( string $source ): array {
		$res = $this->call( 'GET', '/fields?source=' . rawurlencode( $source ) );
		$this->assertSame( 200, $res->get_status() );
		$out = array();
		foreach ( $res->get_data()['groups'] as $group ) {
			foreach ( $group['fields'] as $field ) {
				$out[ $field['id'] ] = $field;
			}
		}
		return $out;
	}

	public function test_options_adds_operators_limits_and_super_admin(): void {
		$o = $this->call( 'GET', '/options' )->get_data();
		$this->assertCount( 14, $o['operators'] );
		$this->assertSame( OPEX_Query::ops(), array_column( $o['operators'], 'id' ) );
		foreach ( $o['operators'] as $op ) {
			$this->assertSame( array( 'id', 'label', 'needs_value' ), array_keys( $op ) );
			$this->assertNotSame( '', $op['label'] );
			$this->assertSame( ! in_array( $op['id'], array( 'empty', 'not_empty' ), true ), $op['needs_value'] );
		}
		$this->assertSame( 'equals', $o['operators'][0]['label'] );
		$this->assertSame( 500, $o['limits']['transform_text'] );
		$this->assertSame( 200, $o['limits']['map_pairs'] );
		$this->assertSame( 20000, $o['limits']['xml_part'] );
		$this->assertSame( 100, $o['limits']['custom_label'] );
		$this->assertSame( 500, $o['limits']['custom_template'] );
		$this->assertSame( OPEX_Filter_Tree::MAX_DEPTH, $o['limits']['filter_depth'], 'existing limits stay' );
		$this->assertSame( is_multisite() && is_super_admin(), $o['is_super_admin'] );
	}

	public function test_fields_carry_types_and_groups_carry_stable_ids(): void {
		$res    = $this->call( 'GET', '/fields?source=posts:post' )->get_data();
		$ids    = array_column( $res['groups'], 'id' );
		$this->assertSame( array( 'core', 'computed', 'taxonomies' ), array_slice( $ids, 0, 3 ) );
		$this->assertSame( $ids, array_values( array_unique( $ids ) ), 'ids are unique' );
		// One assertion each, whatever the site's group and field counts, so every lane counts the same.
		$first_keys = array();
		$labels     = array();
		$bad_types  = array();
		foreach ( $res['groups'] as $group ) {
			$first_keys[ array_keys( $group )[0] ] = true;
			$labels[]                              = $group['label'] ?? null;
			foreach ( $group['fields'] as $field ) {
				if ( ! in_array( $field['value_type'], OPEX_Field_Types::TYPES, true ) ) {
					$bad_types[] = $field['id'];
				}
			}
		}
		$this->assertSame( array( 'id' ), array_keys( $first_keys ) );
		$this->assertNotContains( null, $labels, 'the label stays' );
		$this->assertSame( array(), $bad_types );

		$users = $this->fields( 'users' );
		$this->assertSame( 'number', $users['core:ID']['value_type'] );
		$this->assertSame( 'date', $users['core:user_registered']['value_type'] );
		$this->assertSame( 'text', $users['core:user_email']['value_type'] );
		$this->assertSame( 'text', $users['computed:roles']['value_type'] );
		$this->assertSame( array(), array_values( array_filter( $users, static function ( $f ) {
			return array_key_exists( 'type', $f ) || array_key_exists( 'schema_type', $f );
		} ) ), 'fields that had no type in 1.3.1 still have none' );
		$meta_types = array();
		foreach ( $users as $id => $field ) {
			if ( 0 === strpos( $id, 'meta:' ) ) {
				$meta_types[ $field['value_type'] ] = true;
			}
		}
		$this->assertSame( array( 'text' ), array_keys( $meta_types ), 'custom fields default to text' );

		// Translated labels change, ids do not.
		add_filter(
			'gettext',
			static function ( $translation, $text, $domain ) {
				return 'open-export' === $domain ? 'Übersetzt ' . $translation : $translation;
			},
			10,
			3
		);
		$again = $this->call( 'GET', '/fields?source=posts:post' )->get_data();
		$this->assertSame( $ids, array_column( $again['groups'], 'id' ) );
		$this->assertStringStartsWith( 'Übersetzt', $again['groups'][0]['label'] );
	}

	public function test_every_group_label_has_a_fixed_id(): void {
		$known = OPEX_Field_Types::known_labels();
		foreach ( OPEX_Catalog::sources() as $tile_group ) {
			if ( 'tables' === $tile_group['id'] ) {
				continue;
			}
			foreach ( $tile_group['items'] as $item ) {
				foreach ( OPEX_Catalog::fields( $item['id'] ) as $group ) {
					$label = (string) $group['label'];
					$this->assertTrue( in_array( $label, $known, true ) || in_array( $label, self::PLUGIN_GROUPS, true ), "group \"$label\" of {$item['id']} needs a line in OPEX_Field_Types::known_labels" );
				}
			}
		}
	}

	public function test_woo_field_types(): void {
		if ( ! OPEX_Woo::is_active() ) {
			$this->markTestSkipped( 'WooCommerce is not active.' );
		}
		$orders = $this->fields( 'woo:orders' );
		$this->assertSame( 'bool', $orders['woo:is_guest']['value_type'] );
		$this->assertSame( 'number', $orders['woo:total']['value_type'] );
		$this->assertSame( 'number', $orders['woo:order_id']['value_type'] );
		$this->assertSame( 'date', $orders['woo:date_created']['value_type'] );
		$this->assertSame( 'text', $orders['woo:status']['value_type'] );
		// `type` keeps its 1.3.1 words on every orders field.
		$this->assertSame( array( 'boolean', 'enum', 'id' ), array( $orders['woo:is_guest']['type'], $orders['woo:status']['type'], $orders['woo:order_id']['type'] ) );
		$drift = array();
		foreach ( $orders as $id => $field ) {
			if ( 0 === strpos( $id, 'woo:' ) && ( $field['type'] ?? null ) !== OPEX_Woo::field_type( $id ) ) {
				$drift[] = $id;
			}
			if ( array_key_exists( 'schema_type', $field ) ) {
				$drift[] = $id . ' schema_type';
			}
		}
		$this->assertSame( array(), $drift );

		$products = $this->fields( 'woo:products' );
		$this->assertSame( 'json', $products['woo:attributes']['value_type'] );
		$this->assertSame( 'bool', $products['woo:featured']['value_type'] );
		$this->assertSame( 'number', $products['woo:price']['value_type'] );
		$this->assertSame( 'date', $products['woo:date_on_sale_from']['value_type'] );
		$this->assertSame( 'boolean', $products['woo:featured']['type'], 'type stays as the source gave it' );
	}

	public function test_preview_returns_notes(): void {
		$res = $this->call( 'POST', '/preview', array( 'source' => 'users', 'fields' => array( 'core:ID', 'core:nope' ) ) );
		$this->assertSame( 200, $res->get_status() );
		$notes = $res->get_data()['notes'];
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'core:nope', $notes[0] );

		$clean = $this->call( 'POST', '/preview', array( 'source' => 'users', 'fields' => array( 'core:ID' ) ) );
		$this->assertSame( array(), $clean->get_data()['notes'] );
	}

	public function test_schedule_responses_return_config_and_delivery_notes(): void {
		$res = $this->call(
			'POST',
			'/schedules',
			array(
				'name'     => 'Notes check',
				'config'   => array( 'source' => 'users', 'fields' => array( 'core:ID', 'core:nope' ) ),
				'delivery' => array( 'email' => array( 'enabled' => true, 'to' => array( 'not-an-address' ) ) ),
			)
		);
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$data              = $res->get_data();
		$this->schedules[] = $data['id'];
		$joined            = implode( ' | ', $data['notes'] );
		$this->assertStringContainsString( 'core:nope', $joined, 'a config note' );
		$this->assertGreaterThanOrEqual( 2, count( $data['notes'] ), 'and a delivery note' );
		$this->assertFalse( $data['delivery']['email']['enabled'] );

		$upd = $this->call( 'POST', '/schedules/' . $data['id'], array( 'name' => 'Renamed' ) );
		$this->assertSame( 200, $upd->get_status() );
		$this->assertSame( array(), $upd->get_data()['notes'] );
		$this->assertSame( 'Renamed', $upd->get_data()['name'] );
	}

	public function test_job_exposes_finished_heartbeat_and_schedule_name(): void {
		$sched = OPEX_Schedules::save( array( 'name' => 'Weekly terms', 'config' => array( 'source' => 'terms:category', 'fields' => array( 'core:term_id' ) ) ) );
		$this->assertIsArray( $sched );
		$this->schedules[] = $sched['id'];

		$config = OPEX_Exporter::sanitize_config( array( 'source' => 'terms:category', 'fields' => array( 'core:term_id' ) ) );
		$job    = OPEX_Exporter::create( $config, 'From schedule', array( 'schedule_id' => $sched['id'], 'mode' => 'background' ) );
		$this->assertIsArray( $job );
		$this->jobs[] = $job['id'];
		$this->assertSame( 'Weekly terms', $job['schedule_name'] );
		$this->assertGreaterThan( 0, $job['heartbeat'], 'a background job has a heartbeat' );
		for ( $i = 0; $i < 50 && 'running' === $job['status']; $i++ ) {
			$job = OPEX_Exporter::run( $job['id'] );
		}
		$got = $this->call( 'GET', '/jobs/' . $job['id'] )->get_data();
		$this->assertSame( 'done', $got['status'] );
		$this->assertGreaterThan( 0, $got['finished'] );
		$this->assertSame( 'Weekly terms', $got['schedule_name'] );

		OPEX_Schedules::delete( $sched['id'] );
		$this->assertSame( '', OPEX_Jobs::expose( OPEX_Jobs::get( $job['id'] ) )['schedule_name'], 'a deleted schedule leaves no name' );
		$plain = OPEX_Jobs::expose( array( 'id' => 'jold', 'label' => 'Old', 'config' => array( 'format' => 'csv' ), 'status' => 'done', 'total' => 0, 'processed' => 0, 'created' => 1, 'filename' => 'x.csv' ) );
		$this->assertSame( array( 0, 0, '' ), array( $plain['finished'], $plain['heartbeat'], $plain['schedule_name'] ), 'a 1.3.1 job record reads with defaults' );
	}

	public function test_job_exposes_source_and_its_label(): void {
		$config = OPEX_Exporter::sanitize_config( array( 'source' => 'terms:category', 'fields' => array( 'core:term_id' ) ) );
		$job    = OPEX_Exporter::create( $config, 'Labels' );
		$this->jobs[] = $job['id'];
		$this->assertSame( 'terms:category', $job['source'] );
		$this->assertSame( get_taxonomy( 'category' )->labels->name, $job['source_label'] );
		// Find the job by id: another test's job made in the same second may sort first.
		$listed = array();
		foreach ( $this->call( 'GET', '/jobs' )->get_data() as $row ) {
			if ( $row['id'] === $job['id'] ) {
				$listed = $row;
			}
		}
		$this->assertSame( 'terms:category', $listed['source'] ?? null );

		$expect = array(
			'users'       => __( 'Users', 'open-export' ),
			'comments'    => __( 'Comments', 'open-export' ),
			'posts:page'  => get_post_type_object( 'page' )->labels->name,
			'posts:gone'  => '',
			'table:nope'  => '',
			''            => '',
		);
		if ( OPEX_Woo::is_active() ) {
			$expect['woo:orders']   = __( 'WooCommerce Orders', 'open-export' );
			$expect['woo:products'] = __( 'WooCommerce Products', 'open-export' );
		}
		foreach ( $expect as $source => $label ) {
			$this->assertSame( $label, OPEX_Source_Labels::of( (string) $source ), (string) $source );
		}
		$old = OPEX_Jobs::expose( array( 'id' => 'jold', 'label' => 'Old', 'config' => array( 'format' => 'csv' ), 'status' => 'done', 'total' => 0, 'processed' => 0, 'created' => 1, 'filename' => 'x.csv' ) );
		$this->assertSame( array( '', '' ), array( $old['source'], $old['source_label'] ), 'a record with no source reads as empty' );
	}

	public function test_options_destination_types_carry_security_notes(): void {
		$types = array_column( $this->call( 'GET', '/options' )->get_data()['destination_types'], null, 'id' );
		foreach ( array( 'ftp', 'ftps', 'sftp' ) as $type ) {
			$this->assertSame( array( 'id', 'label', 'security_note' ), array_keys( $types[ $type ] ) );
			$this->assertSame( OPEX_Destinations::security_note( $type ), $types[ $type ]['security_note'] );
		}
		$this->assertSame( '', $types['sftp']['security_note'] );
		$this->assertStringContainsString( 'without encryption', $types['ftp']['security_note'] );
		$saved = OPEX_Destinations::save( array( 'type' => 'ftps', 'host' => '127.0.0.1', 'username' => 'u', 'secret' => 'PW-SECRET-3' ) );
		$this->assertSame( $types['ftps']['security_note'], OPEX_Destinations::expose( $saved )['security_note'], 'the same text a saved destination shows' );
	}

	public function test_network_is_refused_outside_a_network_super_admin(): void {
		if ( is_multisite() && is_super_admin() ) {
			$this->markTestSkipped( 'This site is a network and the user is a super admin.' );
		}
		$res = $this->call( 'POST', '/preview', array( 'source' => 'users', 'fields' => array( 'core:ID' ), 'network' => true ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'opex_bad_network', $res->get_data()['code'] );
		$job = $this->call( 'POST', '/jobs', array( 'source' => 'users', 'fields' => array( 'core:ID' ), 'network' => true ) );
		$this->assertSame( 400, $job->get_status() );
		$this->assertSame( 200, $this->call( 'POST', '/preview', array( 'source' => 'users', 'fields' => array( 'core:ID' ), 'network' => false ) )->get_status() );
	}

	public function test_destination_reset_host_key(): void {
		$res = $this->call( 'POST', '/destinations', array( 'name' => 'Key box', 'type' => 'sftp', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'u', 'auth' => 'password', 'secret' => 'PW-SECRET-1', 'path' => '/' ) );
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$id = $res->get_data()['id'];
		OPEX_Destinations::pin_host_key( $id, 'SHA256:' . str_repeat( 'A', 43 ) );

		$keep = $this->call( 'POST', '/destinations/' . $id, array( 'name' => 'Renamed' ) )->get_data();
		$this->assertSame( 'SHA256:' . str_repeat( 'A', 43 ), $keep['host_key'], 'a plain update keeps the pin' );
		$reset = $this->call( 'POST', '/destinations/' . $id, array( 'reset_host_key' => true ) );
		$this->assertSame( 200, $reset->get_status() );
		$this->assertSame( '', $reset->get_data()['host_key'] );
		$this->assertSame( '', OPEX_Destinations::get( $id )['host_key'] );
	}

	public function test_destination_last_test(): void {
		$res = $this->call( 'POST', '/destinations', array( 'name' => 'Box', 'type' => 'ftp', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'u', 'auth' => 'password', 'secret' => 'PW-SECRET-2', 'path' => '/' ) );
		$id  = $res->get_data()['id'];
		$this->assertSame( array( 'time' => 0, 'ok' => false, 'message' => '' ), $res->get_data()['last_test'], 'never tested' );

		$test = $this->call( 'POST', '/destinations/' . $id . '/test' )->get_data();
		$this->assertFalse( $test['ok'] );
		$list = $this->call( 'GET', '/destinations' )->get_data();
		$last = $list[0]['last_test'];
		$this->assertGreaterThan( time() - 120, $last['time'] );
		$this->assertFalse( $last['ok'] );
		$this->assertSame( $test['message'], $last['message'] );
		$this->assertStringNotContainsString( 'PW-SECRET-2', wp_json_encode( $list ) );

		$this->call( 'POST', '/destinations/' . $id, array( 'name' => 'Renamed box' ) );
		$this->assertSame( $last, OPEX_Destinations::expose( OPEX_Destinations::get( $id ) )['last_test'], 'a new name keeps the result' );
		$this->call( 'POST', '/destinations/' . $id, array( 'host' => 'example.org' ) );
		$this->assertSame( 0, OPEX_Destinations::expose( OPEX_Destinations::get( $id ) )['last_test']['time'], 'a new host drops it' );

		// A record saved before 2.0 has no last_test and still reads.
		$old = OPEX_Destinations::get( $id );
		unset( $old['last_test'] );
		update_option( 'opex_destinations', array( $id => $old ), false );
		$this->assertSame( 0, $this->call( 'GET', '/destinations' )->get_data()[0]['last_test']['time'] );
	}
}
