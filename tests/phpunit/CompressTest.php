<?php
/**
 * Zip compression of the finished file (config key compress).
 *
 * Jobs read the seeded posts in small batches, so the zip step runs after a
 * real multi-batch export. Every job and file is removed in tearDown.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * The zip holds the same bytes as the plain export, under the name people see,
 * and every way out (download, email, upload name) sends the zip.
 */
class CompressTest extends TestCase {

	/** @var string[] Job ids. */
	private array $jobs = array();

	/** @var array Captured mail. */
	private array $mail = array();

	protected function setUp(): void {
		wp_set_current_user( 1 );
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'The PHP zip extension is not installed.' );
		}
	}

	protected function tearDown(): void {
		remove_all_filters( 'pre_wp_mail' );
		foreach ( $this->jobs as $id ) {
			OPEX_Jobs::delete( $id );
		}
		$this->jobs = array();
	}

	/**
	 * A clean config on the seeded posts.
	 *
	 * @param array $extra Raw keys to add.
	 */
	private function config( array $extra = array() ): array {
		$config = OPEX_Exporter::sanitize_config(
			array_merge(
				array(
					'source'     => 'posts:post',
					'fields'     => array( 'core:ID', 'core:post_title' ),
					'batch_size' => 10,
				),
				$extra
			)
		);
		$this->assertIsArray( $config, is_wp_error( $config ) ? $config->get_error_message() : '' );
		return $config;
	}

	/**
	 * Creates a job and runs it while it is running.
	 *
	 * @param array $config Clean config.
	 * @param array $extra  Job extras.
	 * @return array The stored job.
	 */
	private function run_job( array $config, array $extra = array() ): array {
		$job = OPEX_Exporter::create( $config, 'Zip test', $extra );
		$this->assertIsArray( $job, is_wp_error( $job ) ? $job->get_error_message() : '' );
		$this->jobs[] = $job['id'];
		for ( $i = 0; $i < 100 && 'running' === $job['status']; $i++ ) {
			$job = OPEX_Exporter::run( $job['id'] );
		}
		return OPEX_Jobs::get( $job['id'] );
	}

	/**
	 * The one entry of a zip: name => bytes.
	 *
	 * @param string $path Zip path.
	 * @return array<string, string>
	 */
	private function zip_entries( string $path ): array {
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $path ) );
		$out = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$out[ (string) $zip->getNameIndex( $i ) ] = (string) $zip->getFromIndex( $i );
		}
		$zip->close();
		return $out;
	}

	/** Only none and zip are kept, and an Excel file is never zipped again. */
	public function test_sanitize(): void {
		$this->assertSame( 'zip', $this->config( array( 'compress' => 'zip' ) )['compress'] );
		$this->assertArrayNotHasKey( 'compress', $this->config( array( 'compress' => 'gzip' ) ) );

		$notes  = array();
		$config = OPEX_Exporter::sanitize_config(
			array(
				'source'   => 'posts:post',
				'fields'   => array( 'core:ID' ),
				'format'   => 'xlsx',
				'compress' => 'zip',
			),
			$notes
		);
		$this->assertArrayNotHasKey( 'compress', $config );
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'already compressed', $notes[0] );
		$this->assertFalse( OPEX_Compress::wanted( array( 'format' => 'xlsx', 'compress' => 'zip' ) ) );
	}

	/** A multi-batch CSV export: the zip holds exactly the plain file, under its friendly name. */
	public function test_zip_holds_the_plain_export(): void {
		$plain = $this->run_job( $this->config() );
		$this->assertSame( 'done', $plain['status'] );
		$bytes = (string) file_get_contents( OPEX_Jobs::file_path( $plain ) );

		$job  = $this->run_job( $this->config( array( 'compress' => 'zip' ) ) );
		$path = OPEX_Jobs::file_path( $job );
		$this->assertSame( 'done', $job['status'] );
		$this->assertStringEndsWith( '.csv.zip.opex', $job['filename'] );
		$this->assertFileExists( $path );
		$this->assertFileDoesNotExist( substr( $path, 0, -9 ) . '.opex', 'the plain file is removed' );
		$this->assertFileDoesNotExist( substr( $path, 0, -9 ) . '.zip.part.opex' );
		$this->assertSame( filesize( $path ), (int) $job['filesize'] );
		$this->assertSame( array( 'Zip test.csv' => $bytes ), $this->zip_entries( $path ) );
		$this->assertGreaterThan( 10, $job['processed'], 'several batches ran' );

		$this->assertTrue( OPEX_Compress::is_compressed( $job ) );
		$this->assertSame( 'Zip test.csv.zip', OPEX_Download::file_name( $job ) );
		$this->assertContains( 'Content-Type: application/zip', OPEX_Download::headers( $job, OPEX_Download::file_name( $job ), 10 ) );
		$this->assertSame( substr( $job['filename'], 0, -13 ) . '.csv.zip', OPEX_Delivery::remote_name( $job ) );
		$job['delivery_name'] = 'Orders May';
		$this->assertSame( 'Orders-May.csv.zip', OPEX_Delivery::remote_name( $job ) );
		$this->assertSame( 'Orders May.csv.zip', OPEX_Download::file_name( $job ) );
	}

	/** An export with no rows is done at once and is zipped too. */
	public function test_empty_export_is_zipped(): void {
		$config = $this->config(
			array(
				'compress' => 'zip',
				'format'   => 'json',
				'filters'  => array(
					'relation' => 'AND',
					'rules'    => array(
						array(
							'field' => 'core:ID',
							'op'    => 'eq',
							'value' => '0',
						),
					),
				),
			)
		);
		$job    = $this->run_job( $config );
		$this->assertSame( 'done', $job['status'] );
		$this->assertStringEndsWith( '.json.zip.opex', $job['filename'] );
		$this->assertSame( array( 'Zip test.json' => "[\n\n]\n" ), $this->zip_entries( OPEX_Jobs::file_path( $job ) ) );
		$this->assertFileDoesNotExist( substr( OPEX_Jobs::file_path( $job ), 0, -9 ) . '.opex' );
	}

	/**
	 * When zipping fails, the job keeps its plain file and stays unfinished.
	 * Running it again finishes it once: the JSON footer is not written twice.
	 */
	public function test_failed_zip_can_finish_again(): void {
		$plain = $this->run_job( $this->config( array( 'format' => 'json' ) ) );
		$want  = json_decode( (string) file_get_contents( OPEX_Jobs::file_path( $plain ) ), true );

		$job = OPEX_Exporter::create( $this->config( array( 'format' => 'json', 'compress' => 'zip' ) ), 'Zip test' );
		$this->assertIsArray( $job );
		$this->jobs[] = $job['id'];
		$disk         = OPEX_Jobs::file_path( OPEX_Jobs::get( $job['id'] ) );
		// A folder where the half-built zip goes makes the zip step fail.
		$part = substr( $disk, 0, -5 ) . '.zip.part.opex';
		mkdir( $part );
		try {
			for ( $i = 0; $i < 100 && 'running' === $job['status']; $i++ ) {
				$job = OPEX_Exporter::run( $job['id'] );
			}
		} finally {
			rmdir( $part );
		}
		$this->assertSame( 'error', $job['status'] );
		$stored = OPEX_Jobs::get( $job['id'] );
		$this->assertStringEndsWith( '.json.opex', $stored['filename'], 'the job still points at the plain file' );
		$this->assertFileExists( $disk );

		$job = OPEX_Exporter::run( $job['id'] );
		$this->assertSame( 'done', $job['status'] );
		$stored  = OPEX_Jobs::get( $job['id'] );
		$entries = $this->zip_entries( OPEX_Jobs::file_path( $stored ) );
		$this->assertSame( array( 'Zip test.json' ), array_keys( $entries ) );
		$this->assertSame( $want, json_decode( $entries['Zip test.json'], true ), 'same rows, one footer' );
		$this->assertFileDoesNotExist( $disk );

		// Zipping the same plain file twice gives the same entry.
		$copy             = $stored;
		$copy['filename'] = substr( $stored['filename'], 0, -9 ) . '.opex';
		file_put_contents( OPEX_Jobs::file_path( $copy ), 'again' );
		$this->assertSame( $stored['filename'], OPEX_Compress::apply( $copy ) );
		$this->assertSame( array( 'Zip test.json' => 'again' ), $this->zip_entries( OPEX_Jobs::file_path( $stored ) ) );
	}

	/** Deleting a job removes the zip, a plain file left behind and a half-built zip. */
	public function test_delete_removes_every_file(): void {
		$job   = $this->run_job( $this->config( array( 'compress' => 'zip' ) ) );
		$zip   = OPEX_Jobs::file_path( $job );
		$plain = substr( $zip, 0, -9 ) . '.opex';
		$part  = substr( $zip, 0, -9 ) . '.zip.part.opex';
		file_put_contents( $plain, 'left behind' );
		file_put_contents( $part, 'half' );
		OPEX_Jobs::delete( $job['id'] );
		$this->assertFileDoesNotExist( $zip );
		$this->assertFileDoesNotExist( $plain );
		$this->assertFileDoesNotExist( $part );
	}

	/** Email in attach mode sends the zip under the delivery name. */
	public function test_email_attaches_the_zip(): void {
		add_filter(
			'pre_wp_mail',
			function ( $none, $atts ) {
				foreach ( (array) $atts['attachments'] as $file ) {
					$this->mail[ basename( $file ) ] = (string) file_get_contents( $file );
				}
				return true;
			},
			10,
			2
		);
		$job = $this->run_job( $this->config( array( 'compress' => 'zip' ) ), array( 'delivery_name' => 'Orders May' ) );
		$res = OPEX_Delivery_Email::send( $job, array( 'to' => array( 'a@example.com' ), 'mode' => 'attach' ), OPEX_Jobs::file_path( $job ) );
		$this->assertTrue( $res );
		$this->assertSame( array( 'Orders-May.csv.zip' ), array_keys( $this->mail ) );
		$this->assertSame( "PK\x03\x04", substr( $this->mail['Orders-May.csv.zip'], 0, 4 ) );
	}

	/** GET /options says compression works and reports the history cap. */
	public function test_options_report_compression_and_history_cap(): void {
		$options = OPEX_Rest_Delivery::options()->get_data();
		$this->assertTrue( $options['features']['compress'] );
		$this->assertSame( 200, $options['limits']['history'] );
	}
}
