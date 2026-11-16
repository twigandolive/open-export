<?php
/**
 * Deliveries: each channel's result is saved as soon as it is known, a
 * delivery run that lost its lock stops and saves nothing, and a webhook
 * whose secret cannot be encrypted is turned off with a note, never sent
 * unsigned.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/servers/helpers.php';

/**
 * Stands in for a server where encrypting a secret fails.
 */
class Codex_Engine_No_Crypto_Delivery extends OPEX_Delivery {

	/**
	 * Encryption that always fails.
	 *
	 * @param string $plain The secret.
	 * @return string Always ''.
	 */
	protected static function encrypt_secret( string $plain ): string {
		unset( $plain );
		return '';
	}
}

class CodexEngineDeliveryTest extends TestCase {

	use OpexDeliveryFixtures;

	protected function tearDown(): void {
		remove_all_filters( 'pre_wp_mail' );
		foreach ( $this->made_jobs as $id ) {
			OPEX_Lock::force_release( 'deliver_' . $id );
		}
		$this->cleanup_jobs();
	}

	/**
	 * A done job with two email deliveries waiting.
	 *
	 * @return array The job.
	 */
	private function job_with_two_emails(): array {
		$delivery = array(
			'email' => array(
				'enabled' => true,
				'to'      => array( 'a@example.com' ),
				'mode'    => 'link',
				'subject' => '',
			),
		);
		$item     = OPEX_Delivery::plan( $delivery )[0];
		return $this->make_job(
			array(
				'delivery'   => $delivery,
				'deliveries' => array( $item, $item ),
			)
		);
	}

	public function test_each_channel_is_saved_before_the_next_one_runs() {
		$job   = $this->job_with_two_emails();
		$calls = 0;
		$seen  = '';
		add_filter(
			'pre_wp_mail',
			static function () use ( &$calls, &$seen, $job ) {
				++$calls;
				if ( 2 === $calls ) {
					// If this worker died now, what would the next one find?
					$seen = (string) OPEX_Jobs::get( $job['id'] )['deliveries'][0]['status'];
				}
				return true;
			}
		);

		OPEX_Runner::deliver( $job['id'] );

		$this->assertSame( 2, $calls );
		$this->assertSame( 'sent', $seen, 'The first email must be saved as sent before the second channel starts.' );
		$this->assertSame( array( 'sent', 'sent' ), array_column( OPEX_Jobs::get( $job['id'] )['deliveries'], 'status' ) );
	}

	public function test_a_run_that_lost_its_lock_sends_no_more_and_saves_nothing() {
		$job   = $this->job_with_two_emails();
		$calls = 0;
		add_filter(
			'pre_wp_mail',
			static function () use ( &$calls, $job ) {
				++$calls;
				// The transfer outlasted the lock: another worker took the delivery over.
				OPEX_Lock::force_release( 'deliver_' . $job['id'] );
				OPEX_Lock::acquire( 'deliver_' . $job['id'], 300 );
				return true;
			}
		);

		OPEX_Runner::deliver( $job['id'] );

		$this->assertSame( 1, $calls, 'The second channel belongs to the new owner.' );
		$this->assertSame( array( 'pending', 'pending' ), array_column( OPEX_Jobs::get( $job['id'] )['deliveries'], 'status' ), 'The old owner must not save over the new one.' );
	}

	public function test_retry_now_also_saves_each_channel_at_once() {
		$job   = $this->job_with_two_emails();
		$calls = 0;
		$seen  = '';
		add_filter(
			'pre_wp_mail',
			static function () use ( &$calls, &$seen, $job ) {
				++$calls;
				if ( 2 === $calls ) {
					$seen = (string) OPEX_Jobs::get( $job['id'] )['deliveries'][0]['status'];
				}
				return true;
			}
		);
		$out = OPEX_Runner::deliver_now( $job['id'] );
		$this->assertIsArray( $out );
		$this->assertSame( 'sent', $seen );
	}

	public function test_keep_alive_renews_the_lock_of_the_run_in_progress() {
		$job     = $this->job_with_two_emails();
		$renewed = null;
		add_filter(
			'pre_wp_mail',
			static function () use ( &$renewed, $job ) {
				global $wpdb;
				$name = 'opex_lock_deliver_' . $job['id'];
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = CONCAT( '1', SUBSTR( option_value, INSTR( option_value, ':' ) ) ) WHERE option_name = %s", $name ) );
				$alive   = OPEX_Delivery::keep_alive();
				$expiry  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
				$renewed = $alive && $expiry > time();
				return true;
			}
		);
		OPEX_Runner::deliver( $job['id'] );
		$this->assertTrue( $renewed );
		$this->assertTrue( OPEX_Delivery::keep_alive(), 'Outside a run it does nothing.' );
	}

	public function test_a_secret_that_cannot_be_encrypted_turns_the_webhook_off() {
		$notes = array();
		$out   = Codex_Engine_No_Crypto_Delivery::sanitize(
			array(
				'webhook' => array(
					'enabled' => true,
					'url'     => 'https://93.184.216.34/hook',
					'secret'  => 'sign me',
				),
			),
			$notes
		);
		$this->assertFalse( $out['webhook']['enabled'], 'An unsigned webhook must not be sent.' );
		$this->assertSame( '', $out['webhook']['secret'] );
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'could not store the signing secret securely', $notes[0] );
	}

	public function test_a_new_secret_that_cannot_be_encrypted_keeps_the_old_one_and_turns_off() {
		$notes    = array();
		$previous = OPEX_Delivery::sanitize(
			array(
				'webhook' => array(
					'enabled' => true,
					'url'     => 'https://93.184.216.34/hook',
					'secret'  => 'old secret',
				),
			),
			$notes
		);
		$this->assertTrue( $previous['webhook']['enabled'] );

		$notes = array();
		$out   = Codex_Engine_No_Crypto_Delivery::sanitize(
			array(
				'webhook' => array(
					'enabled' => true,
					'url'     => 'https://93.184.216.34/hook',
					'secret'  => 'new secret',
				),
			),
			$notes,
			$previous
		);
		$this->assertFalse( $out['webhook']['enabled'] );
		$this->assertSame( $previous['webhook']['secret'], $out['webhook']['secret'], 'The stored secret is not lost.' );
		$this->assertCount( 1, $notes );
	}

	public function test_a_plain_old_secret_that_cannot_be_encrypted_turns_the_webhook_off() {
		$notes = array();
		$out   = Codex_Engine_No_Crypto_Delivery::sanitize(
			array(
				'webhook' => array(
					'enabled' => true,
					'url'     => 'https://93.184.216.34/hook',
				),
			),
			$notes,
			array( 'webhook' => array( 'secret' => 'plain from an old build' ) )
		);
		$this->assertFalse( $out['webhook']['enabled'] );
		$this->assertCount( 1, $notes );
	}

	public function test_a_blank_secret_with_nothing_stored_stays_on() {
		$notes = array();
		$out   = Codex_Engine_No_Crypto_Delivery::sanitize(
			array(
				'webhook' => array(
					'enabled' => true,
					'url'     => 'https://93.184.216.34/hook',
				),
			),
			$notes
		);
		$this->assertTrue( $out['webhook']['enabled'], 'No secret asked for, so nothing was lost.' );
		$this->assertSame( array(), $notes );
	}
}
