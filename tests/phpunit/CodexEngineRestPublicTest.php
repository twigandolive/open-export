<?php
/**
 * R1-5.4: the trigger URL's wrong-key counters and its start gap are read
 * and written under a lock, so requests at the same moment cannot lose a
 * count or both start. The other request is played inside a filter that
 * fires at the moment it would run, and it takes the same lock a real
 * request would.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class CodexEngineRestPublicTest extends TestCase {

	/** Filters to remove, as [ hook, callback ]. */
	private array $filters = array();

	/** The schedule under test. */
	private string $id = '';

	protected function setUp(): void {
		$rec = OPEX_Schedules::save(
			array(
				'name'   => 'Codex trigger',
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
		foreach ( $this->filters as $pair ) {
			remove_filter( $pair[0], $pair[1] );
		}
		$this->filters = array();
		delete_transient( 'opex_trig_fail_' . $this->id );
		delete_transient( 'opex_trigger_' . $this->id );
		delete_transient( $this->ip_key() );
		foreach ( OPEX_Jobs::all() as $job ) {
			if ( $this->id === (string) $job['schedule_id'] ) {
				OPEX_Jobs::delete( $job['id'] );
			}
		}
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
	 * Adds a filter that tearDown removes.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 */
	private function filter( string $hook, callable $callback ): void {
		add_filter( $hook, $callback );
		$this->filters[] = array( $hook, $callback );
	}

	public function test_a_wrong_key_sent_at_the_same_moment_is_still_counted() {
		$key   = 'opex_trig_fail_' . $this->id;
		$fired = false;
		// Another request with a wrong key runs while this one checks: it takes
		// the counter's lock if it can, reads and adds one.
		$this->filter(
			'transient_' . $key,
			static function ( $value ) use ( &$fired, $key ) {
				if ( $fired ) {
					return $value;
				}
				$fired = true;
				$lock  = 'trig_' . md5( $key );
				$token = OPEX_Lock::acquire( $lock, 5 );
				if ( false !== $token ) {
					$now = get_transient( $key );
					$now = is_array( $now ) ? $now : array(
						'count' => 0,
						'until' => time() + 600,
					);
					++$now['count'];
					set_transient( $key, $now, 600 );
					OPEX_Lock::release( $lock, $token );
				}
				return $value;
			}
		);
		set_transient(
			$key,
			array(
				'count' => 3,
				'until' => time() + 600,
			),
			600
		);

		$out = OPEX_Rest_Public::can_trigger( $this->request( 'wrong key' ) );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertTrue( $fired );
		$this->assertSame( 5, (int) get_transient( $key )['count'], 'Both wrong keys count: 3 + 1 + 1.' );
	}

	public function test_counters_are_written_under_their_lock() {
		$key  = 'opex_trig_fail_' . $this->id;
		$held = array();
		foreach ( array( $key, $this->ip_key() ) as $name ) {
			$this->filter(
				'pre_set_transient_' . $name,
				static function ( $value ) use ( &$held, $name ) {
					$held[ $name ] = OPEX_Lock::is_held( 'trig_' . md5( $name ) );
					return $value;
				}
			);
		}
		OPEX_Rest_Public::can_trigger( $this->request( 'wrong key' ) );
		$this->assertSame(
			array(
				$key           => true,
				$this->ip_key() => true,
			),
			$held
		);
	}

	public function test_two_starts_at_the_same_moment_do_not_both_pass_the_gap() {
		$gap      = 'opex_trigger_' . $this->id;
		$id       = $this->id;
		$fired    = false;
		$reserved = false;
		// A second valid request arrives while this one checks the gap. It may
		// reserve the start only if it can take the gap's lock.
		$this->filter(
			'transient_' . $gap,
			static function ( $value ) use ( &$fired, &$reserved, $gap, $id ) {
				if ( $fired ) {
					return $value;
				}
				$fired = true;
				$token = OPEX_Lock::acquire( 'trig_start_' . $id, 5 );
				if ( false !== $token ) {
					if ( (int) get_transient( $gap ) <= time() ) {
						set_transient( $gap, time() + 30, 30 );
						$reserved = true;
					}
					OPEX_Lock::release( 'trig_start_' . $id, $token );
				}
				return $value;
			}
		);
		$key = (string) OPEX_Schedules::get( $this->id )['trigger_key'];

		$out = OPEX_Rest_Public::trigger( $this->request( $key ) );

		$this->assertTrue( $fired );
		$started = $out instanceof WP_REST_Response && 429 !== $out->get_status();
		$this->assertFalse( $reserved && $started, 'Only one of the two requests may start an export.' );
	}

	public function test_the_gap_lock_is_not_held_while_the_export_starts() {
		$id   = $this->id;
		$held = null;
		$this->filter(
			'opex_job_done',
			static function ( $job ) use ( &$held, $id ) {
				$held = OPEX_Lock::is_held( 'trig_start_' . $id );
				return $job;
			}
		);
		$key = (string) OPEX_Schedules::get( $this->id )['trigger_key'];
		$out = OPEX_Rest_Public::trigger( $this->request( $key ) );
		$this->assertInstanceOf( WP_REST_Response::class, $out );
		$this->assertSame( 200, $out->get_status() );
		$this->assertFalse( $held );

		$again = OPEX_Rest_Public::trigger( $this->request( $key ) );
		$this->assertSame( 429, $again->get_status(), 'The gap still holds after the first start.' );
	}
}
