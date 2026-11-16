<?php
/**
 * A server without the PHP zip extension: Excel and zip compression are
 * refused with a clear reason. The extension cannot be removed in a test, so
 * the opex_zip_available filter turns zip output off instead.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class NoZipTest extends TestCase {

	protected function setUp(): void {
		add_filter( 'opex_zip_available', '__return_false' );
	}

	protected function tearDown(): void {
		remove_filter( 'opex_zip_available', '__return_false' );
	}

	/** A post export config with extra keys. */
	private function raw( array $over ): array {
		return array_merge(
			array(
				'source' => 'posts:post',
				'fields' => array( 'core:ID' ),
			),
			$over
		);
	}

	public function test_excel_is_refused() {
		$res = OPEX_Exporter::sanitize_config( $this->raw( array( 'format' => 'xlsx' ) ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'opex_no_zip', $res->get_error_code() );
		$this->assertSame( 400, $res->get_error_data()['status'] );
		$this->assertStringContainsString( 'zip extension', $res->get_error_message() );
	}

	public function test_compression_is_refused() {
		$res = OPEX_Exporter::sanitize_config( $this->raw( array( 'compress' => 'zip' ) ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'opex_no_zip', $res->get_error_code() );
		$this->assertStringContainsString( 'Turn compression off', $res->get_error_message() );
		$this->assertIsArray( OPEX_Exporter::sanitize_config( $this->raw( array( 'compress' => 'none' ) ) ), 'without compression the config is fine' );
	}

	public function test_the_screen_and_the_writer_say_why() {
		$formats = OPEX_Writer::formats();
		$this->assertFalse( $formats['xlsx']['available'] );
		$this->assertNotSame( '', $formats['xlsx']['reason'] );
		$this->assertTrue( $formats['csv']['available'] );

		$options = OPEX_Rest_Screen::extend_options( array() );
		$this->assertFalse( $options['compression']['available'] );
		$this->assertNotSame( '', $options['compression']['reason'] );

		$this->expectException( OPEX_User_Error::class );
		OPEX_Writer::make( 'xlsx', trailingslashit( get_temp_dir() ) . 'opex-nozip.xlsx', array( 'a' => 'A' ) );
	}

	public function test_the_filter_cannot_turn_zip_on() {
		remove_filter( 'opex_zip_available', '__return_false' );
		add_filter( 'opex_zip_available', '__return_true' );
		try {
			$this->assertSame( class_exists( 'ZipArchive' ), OPEX_Compress::available() );
		} finally {
			remove_filter( 'opex_zip_available', '__return_true' );
		}
	}
}
