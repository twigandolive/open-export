<?php
/**
 * Every file Open Export writes into the export folder ends in ".opex".
 *
 * Some hosts put nginx in front of Apache. nginx then serves files with a
 * known static ending (.html, .zip, .txt and others) straight from disk and
 * never asks Apache, so the .htaccess deny rule does not apply to them. An
 * ending no web server treats as a static file keeps every request on Apache.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs a real export of every format, lists the folder after every batch and
 * at the end, and checks the name of every file that appeared.
 */
class ExportFolderNamesTest extends TestCase {

	/** The guard files, the only files without the ending. */
	private const GUARDS = array( '.htaccess', 'index.php', 'web.config' );

	/** @var string[] Job ids to remove. */
	private array $jobs = array();

	/** @var string[] Folders a test made where a work file belongs. */
	private array $dirs = array();

	protected function setUp(): void {
		wp_set_current_user( 1 );
		opex_export_dir();
	}

	protected function tearDown(): void {
		foreach ( $this->dirs as $dir ) {
			if ( is_dir( $dir ) ) {
				rmdir( $dir );
			}
		}
		$this->dirs = array();
		foreach ( $this->jobs as $id ) {
			OPEX_Jobs::delete( $id );
		}
		$this->jobs = array();
	}

	/**
	 * The formats to run: format and compression.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function formats(): array {
		return array(
			'csv'          => array( 'csv', 'none' ),
			'json'         => array( 'json', 'none' ),
			'xml'          => array( 'xml', 'none' ),
			'xlsx'         => array( 'xlsx', 'none' ),
			'html'         => array( 'html', 'none' ),
			'csv with zip' => array( 'csv', 'zip' ),
		);
	}

	/**
	 * Names of the files in the export folder, dot files included.
	 *
	 * @return string[]
	 */
	private function listing(): array {
		$dir   = opex_export_dir();
		$names = array();
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( '.' !== $name && '..' !== $name && is_file( $dir . '/' . $name ) ) {
				$names[] = (string) $name;
			}
		}
		return $names;
	}

	/**
	 * Starts an export of the seeded posts in small batches.
	 *
	 * @param string $format   Format id.
	 * @param string $compress Compression.
	 * @return array The stored job.
	 */
	private function start( string $format, string $compress ): array {
		if ( ( 'xlsx' === $format || 'zip' === $compress ) && ! OPEX_Compress::available() ) {
			$this->markTestSkipped( 'The PHP zip extension is not installed.' );
		}
		$config = OPEX_Exporter::sanitize_config(
			array(
				'source'     => 'posts:post',
				'fields'     => array( 'core:ID', 'core:post_title' ),
				'format'     => $format,
				'compress'   => $compress,
				'batch_size' => 10,
			)
		);
		$this->assertIsArray( $config );
		$job = OPEX_Exporter::create( $config, 'Folder names ' . $format );
		$this->assertIsArray( $job, is_wp_error( $job ) ? $job->get_error_message() : '' );
		$this->jobs[] = $job['id'];
		$this->assertSame( 'running', $job['status'], 'The test site needs more than one batch of posts.' );
		return OPEX_Jobs::get( $job['id'] );
	}

	/**
	 * Every file in the folder after a real export of each format, seen after
	 * each batch and at the end, ends in ".opex".
	 *
	 * @dataProvider formats
	 * @param string $format   Format id.
	 * @param string $compress Compression.
	 */
	#[DataProvider( 'formats' )]
	public function test_every_file_written_into_the_folder_ends_in_opex( string $format, string $compress ): void {
		$before = $this->listing();
		$job    = $this->start( $format, $compress );
		$seen   = array_diff( $this->listing(), $before );
		for ( $i = 0; $i < 100 && 'running' === $job['status']; $i++ ) {
			$job  = OPEX_Exporter::run( $job['id'] );
			$seen = array_merge( $seen, array_diff( $this->listing(), $before ) );
		}
		$this->assertSame( 'done', $job['status'] );
		$seen = array_values( array_unique( $seen ) );

		$this->assertNotEmpty( $seen );
		foreach ( $seen as $name ) {
			$this->assertNotContains( $name, self::GUARDS );
			$this->assertStringEndsWith( '.opex', $name, 'A file in the export folder has a static ending: ' . $name );
		}
		// The listing really caught the working files: the lock between batches, and the Excel rows.
		$this->assertNotEmpty( preg_grep( '/\.lock\.opex$/', $seen ), 'The lock file was seen.' );
		if ( 'xlsx' === $format ) {
			$this->assertNotEmpty( preg_grep( '/\.xlsx\.rows\.opex$/', $seen ) );
			$this->assertNotEmpty( preg_grep( '/\.xlsx\.rows\.n\.opex$/', $seen ) );
		}
		$stored = OPEX_Jobs::get( $job['id'] );
		$ending = '.' . $format . ( 'zip' === $compress ? '.zip' : '' ) . '.opex';
		$this->assertStringEndsWith( $ending, $stored['filename'] );
		$this->assertSame( array( $stored['filename'] ), array_values( array_diff( $this->listing(), $before ) ), 'Only the export file is left.' );

		OPEX_Jobs::delete( $job['id'] );
		$this->assertSame( array(), array_values( array_diff( $this->listing(), $before ) ), 'Deleting the export removes every file it wrote.' );
	}

	/**
	 * Fills the place of a work file with a folder, so writing it fails.
	 *
	 * @param string $path Work file path.
	 */
	private function block( string $path ): void {
		$this->assertTrue( mkdir( $path ) );
		$this->dirs[] = $path;
	}

	/**
	 * Takes the folder away again.
	 *
	 * @param string $path Work file path.
	 */
	private function unblock( string $path ): void {
		rmdir( $path );
		$this->dirs = array_values( array_diff( $this->dirs, array( $path ) ) );
	}

	/**
	 * Runs a job until it stops running.
	 *
	 * @param string $id Job id.
	 * @return array The exposed job.
	 */
	private function run_out( string $id ): array {
		$job = OPEX_Jobs::expose( OPEX_Jobs::get( $id ) );
		for ( $i = 0; $i < 100 && 'running' === $job['status']; $i++ ) {
			$job = OPEX_Exporter::run( $id );
		}
		return $job;
	}

	/**
	 * The short-lived work files (the Excel sheet, the Excel zip being built,
	 * the compressed zip being built) exist only inside one call. A folder in
	 * the place of each name makes that step fail, which proves the step uses
	 * exactly that name. Taking the folder away lets the export finish.
	 */
	public function test_the_short_lived_work_files_end_in_opex(): void {
		$cases = array(
			array( 'xlsx', 'none', '.sheet' ),
			array( 'xlsx', 'none', '.zip.tmp' ),
			array( 'csv', 'zip', '.zip.part' ),
		);
		foreach ( $cases as $case ) {
			list( $format, $compress, $tag ) = $case;
			$job  = $this->start( $format, $compress );
			$path = OPEX_Jobs::file_path( $job );
			$this->assertStringEndsWith( '.' . $format . '.opex', $path );
			$work = substr( $path, 0, -strlen( '.opex' ) ) . $tag . '.opex';
			$this->assertSame( $work, OPEX_Jobs::beside( $path, $tag ) );

			$this->block( $work );
			// Opening a folder as a file warns before it fails. The export
			// turns the failure into its error status, which is what counts here.
			set_error_handler(
				static function (): bool {
					return true;
				},
				E_WARNING
			);
			try {
				$failed = $this->run_out( $job['id'] );
			} finally {
				restore_error_handler();
			}
			$this->assertSame( 'error', $failed['status'], $tag . ' is written under ' . basename( $work ) );
			$this->unblock( $work );
			$done = OPEX_Exporter::run( $job['id'] );
			$this->assertSame( 'done', $done['status'], $tag );
			$this->assertStringEndsWith( '.opex', OPEX_Jobs::get( $job['id'] )['filename'] );
		}
	}

	/** Every work file name a writer, the zip step or the lock can use ends in ".opex". */
	public function test_every_work_file_name_ends_in_opex(): void {
		$dir   = trailingslashit( opex_export_dir() );
		$names = array_merge(
			OPEX_Writer_XLSX::work_files( $dir . 'a-b.xlsx.opex' ),
			OPEX_Compress::related_files( $dir . 'a-b.csv.opex' ),
			array( OPEX_File_Lock::path( $dir . 'a-b.csv.opex' ), OPEX_File_Lock::path( $dir . 'a-b.csv.zip.opex' ) )
		);
		$this->assertCount( 8, $names );
		foreach ( $names as $name ) {
			$this->assertStringEndsWith( '.opex', $name );
		}
	}
}
