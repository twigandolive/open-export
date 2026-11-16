<?php
/**
 * Tests for OPEX_Delivery (sanitize, plan, process).
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/servers/helpers.php';

/**
 * Delivery orchestration.
 */
class DeliveryTest extends TestCase {

	use OpexDeliveryFixtures;

	protected function setUp(): void {
		delete_option( 'opex_destinations' );
	}

	protected function tearDown(): void {
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'opex_delivery_retry_wait' );
		$this->cleanup_jobs();
		delete_option( 'opex_destinations' );
	}

	public function test_sanitize_emails() {
		$notes = array();
		$out   = OPEX_Delivery::sanitize(
			array( 'email' => array( 'enabled' => true, 'to' => 'a@example.com, bad, a@example.com;b@example.com', 'mode' => 'weird', 'subject' => "Hi\r\nBcc: x@y.com" ) ),
			$notes
		);
		$this->assertSame( array( 'a@example.com', 'b@example.com' ), $out['email']['to'] );
		$this->assertSame( 'link', $out['email']['mode'] );
		$this->assertStringNotContainsString( "\n", $out['email']['subject'] );
		$this->assertNotEmpty( $notes );

		$many = array();
		for ( $i = 0; $i < 15; $i++ ) {
			$many[] = "u$i@example.com";
		}
		$out = OPEX_Delivery::sanitize( array( 'email' => array( 'enabled' => true, 'to' => $many ) ), $notes );
		$this->assertCount( 10, $out['email']['to'] );

		$out = OPEX_Delivery::sanitize( array( 'email' => array( 'enabled' => true, 'to' => array( 'nope' ) ) ), $notes );
		$this->assertFalse( $out['email']['enabled'] );
	}

	public function test_sanitize_webhook_and_destination() {
		$notes = array();
		$out   = OPEX_Delivery::sanitize( array( 'webhook' => array( 'enabled' => true, 'url' => 'ftp://example.com/x', 'secret' => 'abc' ) ), $notes );
		$this->assertFalse( $out['webhook']['enabled'] );
		$this->assertSame( '', $out['webhook']['url'] );

		$out = OPEX_Delivery::sanitize( array( 'webhook' => array( 'enabled' => true, 'url' => 'https://93.184.216.34/hook', 'secret' => 'abc' ) ), $notes );
		$this->assertSame( 'abc', OPEX_Secrets::decrypt( $out['webhook']['secret'] ) );
		$again = OPEX_Delivery::sanitize( array( 'webhook' => $out['webhook'] ), $notes );
		$this->assertSame( $out['webhook']['secret'], $again['webhook']['secret'] );

		$out = OPEX_Delivery::sanitize( array( 'destination' => array( 'enabled' => true, 'id' => 'dffffffffffff' ) ), $notes );
		$this->assertFalse( $out['destination']['enabled'] );
		$this->assertSame( array( 'email', 'webhook', 'destination' ), array_keys( OPEX_Delivery::sanitize( null, $notes ) ) );
	}

	public function test_plan() {
		$items = OPEX_Delivery::plan( array( 'email' => array( 'enabled' => true ), 'webhook' => array( 'enabled' => false ), 'destination' => array( 'enabled' => true ) ) );
		$this->assertSame( array( 'email', 'destination' ), array_column( $items, 'channel' ) );
		$this->assertSame( array( 'pending', 'pending' ), array_column( $items, 'status' ) );
		$this->assertSame( array( 0, 0 ), array_column( $items, 'attempts' ) );
	}

	public function test_remote_name() {
		$this->assertSame( 'Orders-May.csv', OPEX_Delivery::remote_name( array( 'filename' => 'x-abc.csv', 'delivery_name' => 'Orders May' ) ) );
		$this->assertSame( 'orders.csv', OPEX_Delivery::remote_name( array( 'filename' => 'x-abc.csv', 'delivery_name' => 'orders.csv' ) ) );
		$this->assertSame( 'x-abc.csv', OPEX_Delivery::remote_name( array( 'filename' => 'x-abc.csv' ) ) );
		$this->assertStringNotContainsString( '/', OPEX_Delivery::remote_name( array( 'filename' => 'x.csv', 'delivery_name' => '../../etc/passwd' ) ) );
	}

	public function test_process_runs_once_and_never_resends_sent() {
		$job   = $this->make_job();
		$calls = 0;
		add_filter(
			'pre_wp_mail',
			static function () use ( &$calls ) {
				++$calls;
				return true;
			}
		);
		$delivery = array( 'email' => array( 'enabled' => true, 'to' => array( 'a@example.com' ), 'mode' => 'link' ) );
		$job['deliveries'] = OPEX_Delivery::plan( $delivery );
		$list = OPEX_Delivery::process( $job, $delivery );
		$this->assertSame( 'sent', $list[0]['status'] );
		$this->assertSame( 1, $list[0]['attempts'] );
		$this->assertGreaterThan( 0, $list[0]['time'] );
		$this->assertSame( 1, $calls );

		$job['deliveries'] = $list;
		$again             = OPEX_Delivery::process( $job, $delivery );
		$this->assertSame( $list, $again );
		$this->assertSame( 1, $calls );
	}

	public function test_failure_retries_up_to_three_attempts() {
		$job = $this->make_job();
		add_filter( 'pre_wp_mail', '__return_false' );
		add_filter( 'opex_delivery_retry_wait', '__return_zero' );
		$delivery          = array( 'email' => array( 'enabled' => true, 'to' => array( 'a@example.com' ), 'mode' => 'link' ) );
		$job['deliveries'] = OPEX_Delivery::plan( $delivery );
		for ( $i = 1; $i <= 4; $i++ ) {
			$job['deliveries'] = OPEX_Delivery::process( $job, $delivery );
			$this->assertSame( 'failed', $job['deliveries'][0]['status'] );
			$this->assertSame( min( $i, 3 ), $job['deliveries'][0]['attempts'] );
		}
		$this->assertNotSame( '', $job['deliveries'][0]['message'] );
	}

	public function test_throwable_becomes_generic_failure() {
		$job = $this->make_job();
		add_filter(
			'pre_wp_mail',
			static function () {
				throw new RuntimeException( 'secret detail pw123' );
			}
		);
		$delivery          = array( 'email' => array( 'enabled' => true, 'to' => array( 'a@example.com' ), 'mode' => 'link' ) );
		$job['deliveries'] = OPEX_Delivery::plan( $delivery );
		$list              = OPEX_Delivery::process( $job, $delivery );
		$this->assertSame( 'failed', $list[0]['status'] );
		$this->assertStringNotContainsString( 'pw123', $list[0]['message'] );
		$this->assertSame( 1, $list[0]['attempts'] );
	}

	public function test_missing_file_and_missing_destination_fail_cleanly() {
		$job = $this->make_job();
		$delivery = array( 'destination' => array( 'enabled' => true, 'id' => 'dffffffffffff' ) );
		$job['deliveries'] = OPEX_Delivery::plan( $delivery );
		$list = OPEX_Delivery::process( $job, $delivery );
		$this->assertSame( 'failed', $list[0]['status'] );

		wp_delete_file( OPEX_Jobs::file_path( $job ) );
		$list = OPEX_Delivery::process( $job, $delivery );
		$this->assertSame( 'failed', $list[0]['status'] );
	}

	public function test_retry_waits_five_then_thirty_minutes() {
		$job = $this->make_job();
		add_filter( 'pre_wp_mail', '__return_false' );
		$delivery          = array( 'email' => array( 'enabled' => true, 'to' => array( 'a@example.com' ), 'mode' => 'link' ) );
		$job['deliveries'] = OPEX_Delivery::plan( $delivery );

		$job['deliveries'] = OPEX_Delivery::process( $job, $delivery );
		$first             = $job['deliveries'][0];
		$this->assertSame( 1, $first['attempts'] );
		$this->assertSame( $first['time'] + 300, OPEX_Delivery::next_attempt_at( $job['deliveries'] ) );

		// Too early: nothing runs.
		$this->assertSame( $job['deliveries'], OPEX_Delivery::process( $job, $delivery ) );

		// Past five minutes: the second attempt runs, and the next wait is thirty minutes.
		$job['deliveries'][0]['time'] = time() - 301;
		$job['deliveries']            = OPEX_Delivery::process( $job, $delivery );
		$second                       = $job['deliveries'][0];
		$this->assertSame( 2, $second['attempts'] );
		$this->assertSame( $second['time'] + 1800, OPEX_Delivery::next_attempt_at( $job['deliveries'] ) );

		$job['deliveries'][0]['time'] = time() - 1000;
		$this->assertSame( $job['deliveries'], OPEX_Delivery::process( $job, $delivery ), 'Thirty minutes have not passed.' );
		$job['deliveries'][0]['time'] = time() - 1801;
		$job['deliveries']            = OPEX_Delivery::process( $job, $delivery );
		$this->assertSame( 3, $job['deliveries'][0]['attempts'] );
		$this->assertSame( 0, OPEX_Delivery::next_attempt_at( $job['deliveries'] ), 'Out of attempts.' );
	}

	public function test_next_attempt_at_ignores_sent_and_handles_pending() {
		$this->assertSame( 0, OPEX_Delivery::next_attempt_at( array() ) );
		$this->assertSame( 0, OPEX_Delivery::next_attempt_at( array( array( 'channel' => 'email', 'status' => 'sent', 'attempts' => 1, 'time' => time() ) ) ) );
		$pending = OPEX_Delivery::next_attempt_at( array( array( 'channel' => 'email', 'status' => 'pending', 'attempts' => 0, 'time' => 0 ) ) );
		$this->assertEqualsWithDelta( time(), $pending, 2 );
	}

	public function test_retry_wait_filter_changes_the_wait() {
		add_filter( 'opex_delivery_retry_wait', static fn() => 60 );
		$item = array( 'channel' => 'email', 'status' => 'failed', 'attempts' => 1, 'time' => 1000 );
		$this->assertSame( 1060, OPEX_Delivery::next_attempt_at( array( $item ) ) );
	}

	public function test_webhook_must_be_https_unless_it_is_this_site() {
		$notes = array();
		$out   = OPEX_Delivery::sanitize( array( 'webhook' => array( 'enabled' => true, 'url' => 'http://93.184.216.34/hook' ) ), $notes );
		$this->assertFalse( $out['webhook']['enabled'] );
		$this->assertNotEmpty( $notes );

		$notes = array();
		$out   = OPEX_Delivery::sanitize( array( 'webhook' => array( 'enabled' => true, 'url' => 'https://93.184.216.34/hook' ) ), $notes );
		$this->assertTrue( $out['webhook']['enabled'] );

		$notes = array();
		$own   = set_url_scheme( home_url( '/hook' ), 'http' );
		$out   = OPEX_Delivery::sanitize( array( 'webhook' => array( 'enabled' => true, 'url' => $own ) ), $notes );
		$this->assertTrue( $out['webhook']['enabled'], $own );
	}

	public function test_blank_webhook_secret_keeps_the_previous_one_and_clear_removes_it() {
		$notes = array();
		$first = OPEX_Delivery::sanitize( array( 'webhook' => array( 'enabled' => true, 'url' => 'https://93.184.216.34/h', 'secret' => 'abc' ) ), $notes );
		$kept  = OPEX_Delivery::sanitize( array( 'webhook' => array( 'enabled' => true, 'url' => 'https://93.184.216.34/h', 'secret' => '' ) ), $notes, $first );
		$this->assertSame( $first['webhook']['secret'], $kept['webhook']['secret'] );
		$none  = OPEX_Delivery::sanitize( array( 'webhook' => array( 'enabled' => true, 'url' => 'https://93.184.216.34/h' ) ), $notes );
		$this->assertSame( '', $none['webhook']['secret'] );
		$gone  = OPEX_Delivery::sanitize( array( 'webhook' => array( 'enabled' => true, 'url' => 'https://93.184.216.34/h', 'clear_secret' => true ) ), $notes, $first );
		$this->assertSame( '', $gone['webhook']['secret'] );
	}
}
