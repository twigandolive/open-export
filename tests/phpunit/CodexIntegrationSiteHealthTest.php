<?php
/**
 * I-3: Site Health counts only export files, never a running job's lock
 * file or a writer's sidecar, and every way a job ends removes its lock file.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Export file count and lock file cleanup.
 */
class CodexIntegrationSiteHealthTest extends TestCase {

	/** @var string[] Files to remove. */
	private $files = array();

	/** @var string[] Job ids to remove. */
	private $jobs = array();

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		foreach ( $this->jobs as $id ) {
			OPEX_Jobs::delete( $id );
		}
	}

	/**
	 * Writes a file into the export folder.
	 *
	 * @param string $name  File name.
	 * @param int    $bytes Size.
	 * @return string Full path.
	 */
	private function put( string $name, int $bytes ): string {
		$path = trailingslashit( opex_export_dir() ) . $name;
		file_put_contents( $path, str_repeat( 'x', $bytes ) );
		$this->files[] = $path;
		return $path;
	}

	/**
	 * The files and size fields.
	 *
	 * @return array { files, bytes }
	 */
	private function counted(): array {
		$fields = OPEX_Site_Health::add_section( array() )['open-export']['fields'];
		return array( (int) $fields['files']['debug'], (int) $fields['size']['debug'] );
	}

	public function test_only_export_files_are_counted(): void {
		$base = $this->counted();
		$tag  = 'opex-int-' . wp_generate_password( 8, false, false );
		$this->put( $tag . '.csv', 100 );
		$this->put( $tag . '.xlsx.zip', 10 );
		$this->put( $tag . '.csv.lock', 0 );
		$this->put( $tag . '.xlsx.rows', 1000 );
		$this->put( $tag . '.xlsx.rows.n', 1000 );
		$this->put( $tag . '.json.zip.part', 1000 );
		$this->put( $tag . '.json.zip.lock', 0 );
		$after = $this->counted();
		$this->assertSame( $base[0] + 2, $after[0], 'Only the CSV and the zipped Excel file are exports.' );
		$this->assertSame( $base[1] + 110, $after[1] );
	}

	/**
	 * A running job with its file and lock file on disk.
	 *
	 * @param int $created When it was created.
	 * @return array { job, lock path }
	 */
	private function running_job( int $created ): array {
		$id   = 'j' . substr( md5( uniqid( '', true ) ), 0, 12 );
		$name = 'opex-int-' . wp_generate_password( 8, false, false ) . '.csv';
		$job  = array(
			'id'       => $id,
			'label'    => 'Lock file',
			'config'   => array( 'format' => 'csv' ),
			'status'   => 'running',
			'created'  => $created,
			'filename' => $name,
		);
		OPEX_Jobs::save( $job );
		$this->jobs[] = $id;
		$path         = $this->put( $name, 10 );
		$lock         = OPEX_File_Lock::path( $path );
		$this->put( basename( $lock ), 0 );
		return array( $job, $lock );
	}

	public function test_the_retention_sweep_removes_the_lock_file(): void {
		list( $job, $lock ) = $this->running_job( time() - 400 * DAY_IN_SECONDS );
		$this->assertFileExists( $lock );
		OPEX_Jobs::sweep();
		$this->assertNull( OPEX_Jobs::get( $job['id'] ) );
		$this->assertFileDoesNotExist( $lock );
	}

	public function test_deleting_a_job_removes_the_lock_file(): void {
		list( $job, $lock ) = $this->running_job( time() );
		OPEX_Jobs::delete( $job['id'] );
		$this->assertFileDoesNotExist( $lock );
	}
}
