<?php
/**
 * Exports saved by Open Export 2.0.0 keep working after the ".opex" ending.
 *
 * 2.0.0 saved files under their real ending ("orders-<random>.csv", a zip as
 * "orders-<random>.csv.zip", Excel rows in "<file>.rows"). The job record
 * holds that name in "filename". Open Export never renames these files: it
 * downloads, delivers, finishes, compresses, sweeps and deletes them as they are.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Builds 2.0.0 records and files by hand and runs every path over them.
 */
class LegacyDiskNamesTest extends TestCase {

	/** @var string[] Job ids to remove. */
	private array $jobs = array();

	/** @var string[] Files to remove. */
	private array $files = array();

	/** @var array Captured mail. */
	private array $mail = array();

	protected function setUp(): void {
		wp_set_current_user( 1 );
		opex_export_dir();
		$this->mail = array();
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
	}

	protected function tearDown(): void {
		remove_all_filters( 'pre_wp_mail' );
		foreach ( $this->jobs as $id ) {
			OPEX_Jobs::delete( $id );
		}
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->jobs  = array();
		$this->files = array();
		delete_option( 'opex_settings' );
	}

	/**
	 * Writes a file into the export folder.
	 *
	 * @param string $name File name.
	 * @param string $body Contents.
	 * @return string Full path.
	 */
	private function put( string $name, string $body ): string {
		$path = trailingslashit( opex_export_dir() ) . $name;
		file_put_contents( $path, $body );
		$this->files[] = $path;
		return $path;
	}

	/**
	 * A random 2.0.0 style base name, without an ending.
	 *
	 * @return string
	 */
	private function base(): string {
		return 'legacy-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 12, false );
	}

	/**
	 * Saves a finished 2.0.0 job record, exactly the keys 2.0.0 wrote.
	 *
	 * @param string $filename On-disk name, without ".opex".
	 * @param array  $config   Config keys.
	 * @param int    $finished When it finished.
	 * @return array The record.
	 */
	private function done_job( string $filename, array $config, int $finished = 0 ): array {
		$job = array(
			'id'            => 'j' . substr( md5( uniqid( '', true ) ), 0, 12 ),
			'label'         => 'Legacy',
			'config'        => array_merge( array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ) ), $config ),
			'headers'       => array( 'core:ID' => 'ID' ),
			'status'        => 'done',
			'total'         => 2,
			'processed'     => 2,
			'cursor'        => 0,
			'created'       => $finished ? $finished : time(),
			'filename'      => $filename,
			'filesize'      => (int) filesize( trailingslashit( opex_export_dir() ) . $filename ),
			'error'         => '',
			'finished'      => $finished ? $finished : time(),
			'delivery_name' => 'Legacy May',
		);
		OPEX_Jobs::save( $job );
		$this->jobs[] = $job['id'];
		return OPEX_Jobs::get( $job['id'] );
	}

	/**
	 * A zip that holds one file.
	 *
	 * @param string $path  Zip path.
	 * @param string $entry Entry name.
	 * @param string $body  Entry contents.
	 */
	private function zip( string $path, string $entry, string $body ): void {
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		$zip->addFromString( $entry, $body );
		$zip->close();
		$this->files[] = $path;
	}

	/**
	 * Sends the job by email in attach mode and returns name => bytes.
	 *
	 * @param array $job Job record.
	 * @return array<string, string>
	 */
	private function attached( array $job ): array {
		$this->mail = array();
		$res        = OPEX_Delivery_Email::send( $job, array( 'to' => array( 'a@example.com' ), 'mode' => 'attach' ), OPEX_Jobs::file_path( $job ) );
		$this->assertTrue( $res );
		return $this->mail;
	}

	/** A finished 2.0.0 CSV downloads and delivers under its friendly name and type. */
	public function test_a_finished_2_0_0_csv_downloads_and_delivers(): void {
		$name = $this->base() . '.csv';
		$body = "ID\n1\n2\n";
		$this->put( $name, $body );
		$job = $this->done_job( $name, array( 'format' => 'csv' ) );

		$this->assertSame( $name, $job['filename'], 'The record keeps its 2.0.0 name.' );
		$this->assertFileExists( OPEX_Jobs::file_path( $job ) );
		$this->assertSame( 'Legacy May.csv', OPEX_Download::file_name( $job ) );
		$this->assertContains( 'Content-Type: text/csv; charset=utf-8', OPEX_Download::headers( $job, OPEX_Download::file_name( $job ), 1 ) );
		$this->assertSame( 'Legacy-May.csv', OPEX_Delivery::remote_name( $job ) );
		$this->assertSame( array( 'Legacy-May.csv' => $body ), $this->attached( $job ) );

		// The token link (webhook and email link mode) reaches the file.
		$token   = OPEX_Download_Tokens::issue_saved( $job, 600 );
		$request = new WP_REST_Request( 'GET', '/open-export/v1/file/' . $job['id'] );
		$request->set_url_params( array( 'id' => $job['id'] ) );
		$request->set_param( 'token', $token );
		$this->assertTrue( OPEX_Rest_Public::can_download( $request ) );
	}

	/** A finished 2.0.0 zip downloads and delivers as a zip. */
	public function test_a_finished_2_0_0_zip_downloads_and_delivers(): void {
		if ( ! OPEX_Compress::available() ) {
			$this->markTestSkipped( 'The PHP zip extension is not installed.' );
		}
		$name = $this->base() . '.csv.zip';
		$this->zip( trailingslashit( opex_export_dir() ) . $name, 'Legacy May.csv', "ID\n1\n" );
		$job = $this->done_job( $name, array( 'format' => 'csv', 'compress' => 'zip' ) );

		$this->assertTrue( OPEX_Compress::is_compressed( $job ) );
		$this->assertSame( 'Legacy May.csv.zip', OPEX_Download::file_name( $job ) );
		$this->assertContains( 'Content-Type: application/zip', OPEX_Download::headers( $job, OPEX_Download::file_name( $job ), 1 ) );
		$this->assertSame( 'Legacy-May.csv.zip', OPEX_Delivery::remote_name( $job ) );
		$this->assertSame( array( 'Legacy-May.csv.zip' ), array_keys( $this->attached( $job ) ) );
	}

	/**
	 * Turns a job that 2.0.1 started back into what 2.0.0 left on disk: the
	 * files and the record lose the ".opex" ending.
	 *
	 * @param array $job Stored job.
	 * @return array The stored job with its 2.0.0 name.
	 */
	private function as_2_0_0( array $job ): array {
		$dir  = trailingslashit( opex_export_dir() );
		$name = (string) $job['filename'];
		if ( '.opex' !== substr( $name, -5 ) ) {
			return $job; // Already a 2.0.0 name.
		}
		$old = substr( $name, 0, -5 );
		foreach ( array( '', '.rows', '.rows.n', '.lock' ) as $tag ) {
			if ( file_exists( $dir . $old . $tag . '.opex' ) ) {
				rename( $dir . $old . $tag . '.opex', $dir . $old . $tag );
			}
		}
		$job['filename'] = $old;
		OPEX_Jobs::save( $job );
		return OPEX_Jobs::get( $job['id'] );
	}

	/**
	 * Starts an export of the seeded posts and runs its first batch.
	 *
	 * @param array $config Raw config keys.
	 * @return array The stored running job.
	 */
	private function started( array $config ): array {
		$config = OPEX_Exporter::sanitize_config( array_merge( array( 'source' => 'posts:post', 'fields' => array( 'core:ID', 'core:post_title' ), 'batch_size' => 10 ), $config ) );
		$this->assertIsArray( $config );
		$job = OPEX_Exporter::create( $config, 'Legacy run', array( 'delivery_name' => 'Legacy May' ) );
		$this->assertIsArray( $job );
		$this->jobs[] = $job['id'];
		$this->assertSame( 'running', OPEX_Exporter::run( $job['id'] )['status'], 'The test site needs several batches of posts.' );
		return OPEX_Jobs::get( $job['id'] );
	}

	/**
	 * Runs a job to the end.
	 *
	 * @param string $id Job id.
	 * @return array The stored job.
	 */
	private function finish( string $id ): array {
		for ( $i = 0; $i < 100 && 'running' === OPEX_Jobs::get( $id )['status']; $i++ ) {
			OPEX_Exporter::run( $id );
		}
		return OPEX_Jobs::get( $id );
	}

	/**
	 * A 2.0.0 CSV job that was still running at the update finishes and is
	 * compressed. The zip is a new file, so it gets the ".opex" ending.
	 */
	public function test_a_running_2_0_0_job_finishes_and_compresses(): void {
		if ( ! OPEX_Compress::available() ) {
			$this->markTestSkipped( 'The PHP zip extension is not installed.' );
		}
		$job   = $this->as_2_0_0( $this->started( array( 'compress' => 'zip' ) ) );
		$plain = OPEX_Jobs::file_path( $job );
		$this->assertStringEndsWith( '.csv', $plain );

		$done = $this->finish( $job['id'] );
		$this->assertSame( 'done', $done['status'] );
		$this->assertSame( $job['filename'] . '.zip.opex', $done['filename'] );
		$this->assertFileDoesNotExist( $plain, 'The plain file goes once the zip is saved.' );
		$this->assertFileDoesNotExist( $plain . '.lock' );
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( OPEX_Jobs::file_path( $done ) ) );
		$this->assertSame( 'Legacy May.csv', $zip->getNameIndex( 0 ) );
		$this->assertSame( (int) $done['processed'] + 1, substr_count( (string) $zip->getFromIndex( 0 ), "\n" ) );
		$zip->close();
		$this->assertSame( 'Legacy May.csv.zip', OPEX_Download::file_name( $done ) );
		$this->assertContains( 'Content-Type: application/zip', OPEX_Download::headers( $done, 'x', 1 ) );
		$this->assertSame( 'Legacy-May.csv.zip', OPEX_Delivery::remote_name( $done ) );
	}

	/** A 2.0.0 Excel job that was running keeps using its 2.0.0 row files and finishes whole. */
	public function test_a_running_2_0_0_excel_job_finishes_from_its_row_files(): void {
		if ( ! OPEX_Compress::available() ) {
			$this->markTestSkipped( 'The PHP zip extension is not installed.' );
		}
		$job  = $this->as_2_0_0( $this->started( array( 'format' => 'xlsx' ) ) );
		$path = OPEX_Jobs::file_path( $job );
		$this->assertStringEndsWith( '.xlsx', $path );
		$this->assertFileExists( $path . '.rows' );

		$done = $this->finish( $job['id'] );
		$this->assertSame( 'done', $done['status'] );
		$this->assertSame( $job['filename'], $done['filename'] );
		$this->assertFileDoesNotExist( $path . '.rows' );
		$this->assertFileDoesNotExist( $path . '.rows.n' );
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $path ) );
		$this->assertSame( (int) $done['processed'] + 1, substr_count( (string) $zip->getFromName( 'xl/worksheets/sheet1.xml' ), '<row ' ), 'Every row, header included, once.' );
		$zip->close();
		$this->assertSame( 'Legacy May.xlsx', OPEX_Download::file_name( $done ) );
	}

	/**
	 * Deleting a 2.0.0 job, and the retention sweep, remove its file and
	 * every 2.0.0 work file next to it.
	 */
	public function test_delete_and_sweep_remove_2_0_0_files(): void {
		foreach ( array( 'delete', 'sweep' ) as $how ) {
			$base  = $this->base();
			$paths = array(
				$this->put( $base . '.xlsx', 'x' ),
				$this->put( $base . '.xlsx.rows', 'x' ),
				$this->put( $base . '.xlsx.rows.n', '3' ),
				$this->put( $base . '.xlsx.lock', '' ),
			);
			$job   = $this->done_job( $base . '.xlsx', array( 'format' => 'xlsx' ), 'sweep' === $how ? time() - 400 * DAY_IN_SECONDS : 0 );
			$zbase = $this->base();
			$paths = array_merge(
				$paths,
				array(
					$this->put( $zbase . '.json.zip', 'x' ),
					$this->put( $zbase . '.json', 'left behind' ),
					$this->put( $zbase . '.json.zip.part', 'half' ),
					$this->put( $zbase . '.json.lock', '' ),
				)
			);
			$zjob  = $this->done_job( $zbase . '.json.zip', array( 'format' => 'json', 'compress' => 'zip' ), 'sweep' === $how ? time() - 400 * DAY_IN_SECONDS : 0 );
			if ( 'delete' === $how ) {
				OPEX_Jobs::delete( $job['id'] );
				OPEX_Jobs::delete( $zjob['id'] );
			} else {
				OPEX_Jobs::sweep();
			}
			$this->assertNull( OPEX_Jobs::get( $job['id'] ) );
			$this->assertNull( OPEX_Jobs::get( $zjob['id'] ) );
			foreach ( $paths as $path ) {
				$this->assertFileDoesNotExist( $path, $how . ' leaves ' . basename( $path ) );
			}
		}
	}

	/** Site Health counts exports under both names, and no work file. */
	public function test_site_health_counts_old_and_new_names(): void {
		$count = static function (): array {
			$fields = OPEX_Site_Health::add_section( array() )['open-export']['fields'];
			return array( (int) $fields['files']['debug'], (int) $fields['size']['debug'] );
		};
		$before = $count();
		$old    = $this->base();
		$new    = $this->base();
		$this->put( $old . '.csv', str_repeat( 'x', 1 ) );
		$this->put( $old . '.html.zip', str_repeat( 'x', 2 ) );
		$this->put( $new . '.html.opex', str_repeat( 'x', 4 ) );
		$this->put( $new . '.json.zip.opex', str_repeat( 'x', 8 ) );
		$this->put( $new . '.xlsx.rows.opex', str_repeat( 'x', 100 ) );
		$this->put( $new . '.xlsx.rows.n.opex', str_repeat( 'x', 100 ) );
		$this->put( $new . '.csv.lock.opex', '' );
		$this->put( $new . '.csv.zip.part.opex', str_repeat( 'x', 100 ) );
		$this->put( $new . '.xlsx.sheet.opex', str_repeat( 'x', 100 ) );
		$after = $count();
		$this->assertSame( $before[0] + 4, $after[0] );
		$this->assertSame( $before[1] + 15, $after[1] );
	}
}
