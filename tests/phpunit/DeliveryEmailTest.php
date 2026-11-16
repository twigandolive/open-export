<?php
/**
 * Tests for OPEX_Delivery_Email, capturing mail with pre_wp_mail.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/servers/helpers.php';

/**
 * Email channel.
 */
class DeliveryEmailTest extends TestCase {

	use OpexDeliveryFixtures;

	/** @var array */
	private $mail = array();

	protected function setUp(): void {
		$this->mail = array();
		add_filter(
			'pre_wp_mail',
			function ( $null, $atts ) {
				$atts['attach_exists']  = array();
				foreach ( (array) $atts['attachments'] as $a ) {
					$atts['attach_exists'][ basename( $a ) ] = is_file( $a ) ? file_get_contents( $a ) : null;
				}
				$atts['attach_paths'] = (array) $atts['attachments'];
				$this->mail[]         = $atts;
				return true;
			},
			10,
			2
		);
	}

	protected function tearDown(): void {
		remove_all_filters( 'pre_wp_mail' );
		$this->cleanup_jobs();
		delete_option( 'opex_settings' );
	}

	public function test_link_mode() {
		OPEX_Settings::save( array( 'link_days' => 3 ) );
		$job = $this->make_job();
		$res = OPEX_Delivery_Email::send( $job, array( 'to' => array( 'a@example.com', 'b@example.com' ), 'mode' => 'link' ), OPEX_Jobs::file_path( $job ) );
		$this->assertTrue( $res );
		$m = $this->mail[0];
		$this->assertSame( array( 'a@example.com', 'b@example.com' ), $m['to'] );
		$this->assertStringContainsString( 'Orders May', $m['subject'] );
		$this->assertStringContainsString( 'The link stops working on', $m['message'] );
		$this->assertStringContainsString( '2', $m['message'] );
		$this->assertSame( array(), $m['attachments'] );
		$this->assertSame( 1, preg_match( '/token=([0-9a-f]{64})/', urldecode( $m['message'] ), $mm ) );
		$stored = OPEX_Jobs::get( $job['id'] );
		$this->assertTrue( OPEX_Download_Tokens::verify( $stored, $mm[1] ) );
		$exp = $stored['tokens'][0]['expires'];
		$this->assertEqualsWithDelta( time() + 3 * DAY_IN_SECONDS, $exp, 5 );
	}

	public function test_attach_mode_names_file_and_removes_copy() {
		$job = $this->make_job();
		$res = OPEX_Delivery_Email::send( $job, array( 'to' => array( 'a@example.com' ), 'mode' => 'attach' ), OPEX_Jobs::file_path( $job ) );
		$this->assertTrue( $res );
		$m = $this->mail[0];
		$this->assertSame( array( 'Orders-May.csv' ), array_keys( $m['attach_exists'] ) );
		$this->assertSame( "id,name\n1,Ann\n2,Bob\n", $m['attach_exists']['Orders-May.csv'] );
		$this->assertStringNotContainsString( 'token=', $m['message'] );
		$this->assertFileDoesNotExist( $m['attach_paths'][0] );
		$this->assertDirectoryDoesNotExist( dirname( $m['attach_paths'][0] ) );
	}

	public function test_large_file_falls_back_to_link() {
		$job  = $this->make_job();
		$path = OPEX_Jobs::file_path( $job );
		$fh   = fopen( $path, 'ab' );
		ftruncate( $fh, 11 * 1024 * 1024 );
		fclose( $fh );
		$job['filesize'] = 11 * 1024 * 1024;
		$this->assertTrue( OPEX_Delivery_Email::send( $job, array( 'to' => array( 'a@example.com' ), 'mode' => 'attach' ), $path ) );
		$this->assertSame( array(), $this->mail[0]['attachments'] );
		$this->assertStringContainsString( 'token=', urldecode( $this->mail[0]['message'] ) );
	}

	public function test_header_injection_is_blocked() {
		$job = $this->make_job();
		OPEX_Delivery_Email::send(
			$job,
			array( 'to' => array( "a@example.com\r\nBcc: evil@example.com", 'ok@example.com' ), 'mode' => 'link', 'subject' => "Hello\r\nBcc: evil@example.com" ),
			OPEX_Jobs::file_path( $job )
		);
		$m = $this->mail[0];
		$this->assertSame( array( 'ok@example.com' ), $m['to'] );
		$this->assertStringNotContainsString( "\n", $m['subject'] );
		$this->assertStringNotContainsString( "\r", $m['subject'] );
		$this->assertStringNotContainsString( "\n", implode( '', $m['to'] ) );
	}

	public function test_no_valid_address_and_mail_failure() {
		$job = $this->make_job();
		$res = OPEX_Delivery_Email::send( $job, array( 'to' => array( 'nope' ) ), OPEX_Jobs::file_path( $job ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( array(), $this->mail );

		remove_all_filters( 'pre_wp_mail' );
		add_filter( 'pre_wp_mail', '__return_false' );
		$res = OPEX_Delivery_Email::send( $job, array( 'to' => array( 'a@example.com' ), 'mode' => 'link' ), OPEX_Jobs::file_path( $job ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_more_than_ten_addresses_are_cut() {
		$job  = $this->make_job();
		$many = array();
		for ( $i = 0; $i < 14; $i++ ) {
			$many[] = "u$i@example.com";
		}
		OPEX_Delivery_Email::send( $job, array( 'to' => $many, 'mode' => 'link' ), OPEX_Jobs::file_path( $job ) );
		$this->assertCount( 10, $this->mail[0]['to'] );
	}
}
