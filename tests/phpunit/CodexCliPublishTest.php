<?php
/**
 * Codex R1-5.2 and R1-5.3: --output is written through a unique temporary
 * file of its own, and without --force a file that appeared while the
 * export ran is never replaced.
 *
 * OPEX_CLI_Runner::publish() is the step that finish_job() runs at the end,
 * so these tests call it in this process, with the target file created
 * between the start check and the publish, as a slow export would see it.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Publishing the --output file.
 */
class CodexCliPublishTest extends TestCase {

	private string $dir = '';

	private string $source = '';

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/opex-pub-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir );
		$this->source = $this->dir . '/export-source.csv';
		file_put_contents( $this->source, "id\n1\n2\n" );
	}

	protected function tearDown(): void {
		foreach ( (array) scandir( $this->dir ) as $file ) {
			if ( '.' !== $file && '..' !== $file ) {
				unlink( $this->dir . '/' . $file );
			}
		}
		rmdir( $this->dir );
	}

	/**
	 * Files in the folder other than the source.
	 *
	 * @return string[]
	 */
	private function others(): array {
		$names = array_values( array_diff( scandir( $this->dir ), array( '.', '..', 'export-source.csv' ) ) );
		sort( $names );
		return $names;
	}

	public function test_a_new_file_is_published_and_no_temp_is_left() {
		$dest = $this->dir . '/out.csv';
		$this->assertTrue( OPEX_CLI_Runner::publish( $this->source, $dest, false ) );
		$this->assertSame( "id\n1\n2\n", file_get_contents( $dest ) );
		$this->assertSame( array( 'out.csv' ), $this->others() );
		$this->assertFileExists( $this->source, 'the export file itself stays' );
	}

	public function test_a_file_that_appeared_during_the_run_is_kept_without_force() {
		$dest = $this->dir . '/out.csv';
		file_put_contents( $dest, 'written by someone else' );
		$res = OPEX_CLI_Runner::publish( $this->source, $dest, false );
		$this->assertIsString( $res );
		$this->assertStringContainsString( '--force', $res );
		$this->assertSame( 'written by someone else', file_get_contents( $dest ) );
		$this->assertSame( array( 'out.csv' ), $this->others(), 'the temporary copy was removed' );
	}

	public function test_force_replaces_the_file() {
		$dest = $this->dir . '/out.csv';
		file_put_contents( $dest, 'old' );
		$this->assertTrue( OPEX_CLI_Runner::publish( $this->source, $dest, true ) );
		$this->assertSame( "id\n1\n2\n", file_get_contents( $dest ) );
		$this->assertSame( array( 'out.csv' ), $this->others() );
	}

	public function test_the_old_shared_temp_name_is_never_touched() {
		$dest   = $this->dir . '/out.csv';
		$shared = $dest . '.opex-part';
		file_put_contents( $shared, 'another run is writing this' );
		$this->assertTrue( OPEX_CLI_Runner::publish( $this->source, $dest, true ) );
		$this->assertSame( 'another run is writing this', file_get_contents( $shared ) );
		$this->assertSame( "id\n1\n2\n", file_get_contents( $dest ) );
	}
}
