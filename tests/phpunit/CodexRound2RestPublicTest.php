<?php
/**
 * Codex R2-1.1: the trigger URL's counters are read from the database under
 * their lock, even when this request already cached them as missing.
 *
 * Two requests are played in one process. This one reads the counter first,
 * when it does not exist yet, so WordPress caches it as missing. The other
 * request then saves its count straight to the database, as a separate PHP
 * process would.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class CodexRound2RestPublicTest extends TestCase {

	/** The schedule under test. */
	private string $id = '';

	protected function setUp(): void {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'A shared object cache keeps transients outside the options table.' );
		}
		$rec = OPEX_Schedules::save(
			array(
				'name'   => 'Codex round 2 trigger',
				'config' => array(
					'source'     => 'posts:post',
					'fields'     => array( 'core:ID' ),
					'batch_size' => 500,
				),
			)
		);
		$this->assertIsArray( $rec );
		$this->id = $rec['id'];
	}

	protected function tearDown(): void {
		if ( '' === $this->id ) {
			return;
		}
		delete_transient( 'opex_trig_fail_' . $this->id );
		delete_transient( 'opex_trigger_' . $this->id );
		delete_transient( $this->ip_key() );
		foreach ( OPEX_Jobs::all() as $job ) {
			if ( $this->id === (string) $job['schedule_id'] ) {
				OPEX_Jobs::delete( $job['id'] );
			}
		}
		wp_clear_scheduled_hook( 'opex_run_schedule', array( $this->id ) );
		OPEX_Schedules::delete( $this->id );
	}

	/** The transient name of this test's address. */
	private function ip_key(): string {
		return 'opex_trig_ip_' . substr( hash_hmac( 'sha256', (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), wp_salt( 'nonce' ) ), 0, 32 );
	}

	/**
	 * A trigger request.
	 *
	 * @param string $key Key to send.
	 * @return WP_REST_Request The request.
	 */
	private function request( string $key ): WP_REST_Request {
		$req = new WP_REST_Request( 'POST', '/open-export/v1/schedules/' . $this->id . '/trigger' );
		$req->set_url_params( array( 'id' => $this->id ) );
		$req->set_param( 'key', $key );
		return $req;
	}

	/**
	 * Makes this request cache a transient as missing, then lets another
	 * request save it straight to the database.
	 *
	 * @param string $name  Transient name.
	 * @param mixed  $value Value the other request saves.
	 */
	private function saved_elsewhere( string $name, $value ): void {
		global $wpdb;
		delete_transient( $name );
		$this->assertFalse( get_transient( $name ) );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		$this->assertArrayHasKey( '_transient_' . $name, (array) $notoptions, 'This request now believes the counter is missing.' );
		$wpdb->insert( $wpdb->options, array( 'option_name' => '_transient_timeout_' . $name, 'option_value' => (string) ( time() + 600 ), 'autoload' => 'off' ) );
		$wpdb->insert( $wpdb->options, array( 'option_name' => '_transient_' . $name, 'option_value' => maybe_serialize( $value ), 'autoload' => 'off' ) );
	}

	public function test_a_failure_counted_by_another_request_is_kept(): void {
		$key = 'opex_trig_fail_' . $this->id;
		$this->saved_elsewhere(
			$key,
			array(
				'count' => 3,
				'until' => time() + 600,
			)
		);
		$this->saved_elsewhere(
			$this->ip_key(),
			array(
				'count' => 2,
				'until' => time() + 600,
			)
		);

		$out = OPEX_Rest_Public::can_trigger( $this->request( 'wrong key' ) );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 4, (int) get_transient( $key )['count'], 'The other request counted 3, this one adds 1.' );
		$this->assertSame( 3, (int) get_transient( $this->ip_key() )['count'] );
	}

	public function test_a_start_gap_reserved_by_another_request_holds(): void {
		$this->saved_elsewhere( 'opex_trigger_' . $this->id, time() + 30 );
		$key = (string) OPEX_Schedules::get( $this->id )['trigger_key'];

		$out = OPEX_Rest_Public::trigger( $this->request( $key ) );

		$this->assertInstanceOf( WP_REST_Response::class, $out );
		$this->assertSame( 429, $out->get_status(), 'The other request already took the start.' );
	}
}
