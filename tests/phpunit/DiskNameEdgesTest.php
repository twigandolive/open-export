<?php
/**
 * Tests for two edges of the on-disk ".opex" names.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * A resumed 2.0.0 export leaves nothing servable behind, and the REST API
 * keeps showing a file name with the real extension.
 */
class DiskNameEdgesTest extends TestCase {

	/**
	 * Ids of the exports each test made.
	 *
	 * @var string[]
	 */
	private $jobs = array();

	/**
	 * Makes sure the export folder exists.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		opex_export_dir();
	}

	/**
	 * Removes every export a test made.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( $this->jobs as $id ) {
			OPEX_Jobs::delete( $id );
		}
		parent::tearDown();
	}

	/**
	 * Writes a small file into the export folder.
	 *
	 * @param string $name File name in the folder.
	 * @return string The full path.
	 */
	private function put( string $name ): string {
		$path = trailingslashit( opex_export_dir() ) . $name;
		file_put_contents( $path, "ID\n1\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test fixture in the plugin's own folder.
		return $path;
	}

	/**
	 * Saves a finished export record that points at a file in the folder.
	 *
	 * @param string $filename The on-disk name the record holds.
	 * @return array The saved record.
	 */
	private function done_job( string $filename ): array {
		$job = array(
			'id'            => 'j' . substr( md5( uniqid( '', true ) ), 0, 12 ),
			'label'         => 'Edge',
			'config'        => array(
				'source' => 'posts:post',
				'fields' => array( 'core:ID' ),
			),
			'headers'       => array( 'core:ID' => 'ID' ),
			'status'        => 'done',
			'total'         => 1,
			'processed'     => 1,
			'cursor'        => 0,
			'created'       => time(),
			'filename'      => $filename,
			'filesize'      => 6,
			'error'         => '',
			'finished'      => time(),
			'delivery_name' => 'Edge',
		);
		OPEX_Jobs::save( $job );
		$this->jobs[] = $job['id'];
		return OPEX_Jobs::get( $job['id'] );
	}

	/**
	 * A 2.0.0 export that crashed while zipping and was finished after the
	 * update: deleting it removes the old zip and the old half-built zip too.
	 *
	 * @return void
	 */
	public function test_deleting_a_resumed_2_0_0_zip_export_removes_its_old_zip_files(): void {
		$stem   = 'edge-' . substr( md5( uniqid( '', true ) ), 0, 12 ) . '.csv';
		$plain  = $this->put( $stem );
		$oldzip = $this->put( $stem . '.zip' );
		$oldpar = $this->put( $stem . '.zip.part' );
		$newzip = $this->put( $stem . '.zip.opex' );
		$job    = $this->done_job( $stem . '.zip.opex' );

		OPEX_Jobs::delete( $job['id'] );

		foreach ( array( $plain, $oldzip, $oldpar, $newzip ) as $path ) {
			$this->assertFileDoesNotExist( $path, basename( $path ) . ' was left behind' );
		}
	}

	/**
	 * The exposed record names the file with its real extension, never the
	 * on-disk ".opex" ending.
	 *
	 * @return void
	 */
	public function test_the_exposed_filename_keeps_the_real_extension(): void {
		$stem = 'edge-' . substr( md5( uniqid( '', true ) ), 0, 12 ) . '.csv';
		$this->put( $stem . OPEX_Jobs::DISK_SUFFIX );
		$job = $this->done_job( $stem . OPEX_Jobs::DISK_SUFFIX );

		$this->assertSame( $stem . OPEX_Jobs::DISK_SUFFIX, $job['filename'], 'the stored record keeps the on-disk name' );
		$this->assertSame( $stem, OPEX_Jobs::expose( $job )['filename'] );
	}
}
