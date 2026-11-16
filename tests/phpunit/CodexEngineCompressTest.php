<?php
/**
 * R1-4.2c: a zip that cannot be written (a full disk shows up when the zip
 * is closed) stops the finish with a plain message. The plain file and the
 * job record stay as they were, so finishing again retries.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class CodexEngineCompressTest extends TestCase {

	/** Job ids to remove. */
	private array $jobs = array();

	protected function setUp(): void {
		if ( ! OPEX_Compress::available() ) {
			$this->markTestSkipped( 'No zip extension.' );
		}
	}

	protected function tearDown(): void {
		$this->fail_close( false );
		foreach ( $this->jobs as $id ) {
			OPEX_Jobs::delete( $id );
		}
		$this->jobs = array();
	}

	/**
	 * Makes the close step of the zip fail, as on a full disk.
	 *
	 * @param bool $fail True to fail.
	 */
	private function fail_close( bool $fail ): void {
		$prop = new ReflectionProperty( OPEX_Compress::class, 'fail_close' );
		if ( PHP_VERSION_ID < 80100 ) {
			$prop->setAccessible( true );
		}
		$prop->setValue( null, $fail );
	}

	/**
	 * A zipped post export.
	 *
	 * @return array The job as stored.
	 */
	private function make_job(): array {
		$config = OPEX_Exporter::sanitize_config(
			array(
				'source'     => 'posts:post',
				'fields'     => array( 'core:ID', 'core:post_title' ),
				'batch_size' => 500,
				'format'     => 'csv',
				'compress'   => 'zip',
			)
		);
		$this->assertIsArray( $config );
		$job = OPEX_Exporter::create( $config, 'Codex compress' );
		$this->assertIsArray( $job );
		$this->jobs[] = $job['id'];
		$this->assertSame( 'running', $job['status'], 'The test site needs posts.' );
		return OPEX_Jobs::get( $job['id'] );
	}

	public function test_a_zip_that_cannot_be_written_leaves_the_plain_file_and_retries() {
		$job   = $this->make_job();
		$plain = OPEX_Jobs::file_path( $job );
		$this->fail_close( true );

		$out = OPEX_Exporter::run( $job['id'] );

		$this->assertIsArray( $out );
		$stored = OPEX_Jobs::get( $job['id'] );
		$this->assertSame( 'error', $stored['status'] );
		$this->assertSame( 'The export file could not be compressed. The disk may be full.', $stored['error'] );
		$this->assertSame( $job['filename'], $stored['filename'], 'The job keeps its plain file name.' );
		$this->assertFileExists( $plain, 'The plain file stays.' );
		$this->assertFileDoesNotExist( $plain . '.zip' );
		$this->assertFileDoesNotExist( $plain . '.zip.part' );

		$this->fail_close( false );
		$again = OPEX_Exporter::run( $job['id'] );
		$this->assertSame( 'done', $again['status'] );
		$done = OPEX_Jobs::get( $job['id'] );
		$this->assertSame( $job['filename'] . '.zip', $done['filename'] );
		$this->assertFileDoesNotExist( $plain, 'The plain file goes once the zip is saved.' );
		$this->assertGreaterThan( 0, (int) $done['filesize'] );

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $plain . '.zip', ZipArchive::CHECKCONS ) );
		$this->assertSame( 1, $zip->count() );
		$this->assertSame( (int) $stored['processed'], substr_count( (string) $zip->getFromIndex( 0 ), "\n" ) - 1, 'The retried zip holds every row once.' );
		$zip->close();
	}
}
