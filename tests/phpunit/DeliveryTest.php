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

		$out = OPEX_Delivery::sanitize( array( 'webhook' => array( 'enabled' => true, 'url' => 'https://example.com/hook', 'secret' => 'abc' ) ), $notes );
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
}
