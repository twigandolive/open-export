<?php
/**
 * On a filesystem without hard links, --output is still never
 * written in place. The finished temporary copy is renamed into the final
 * path, so no reader sees a half-written file.
 *
 * The seam is OPEX_CLI_Runner::$link, the function publish() uses to make
 * the hard link. The test swaps it for one that fails the way link() does
 * on such a filesystem, and remembers which file it was asked to link.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Publishing --output without hard links.
 */
class CodexRound2CliPublishTest extends TestCase {

	private string $dir = '';

	private string $source = '';

	/** @var array Inode and path of each file the stand-in was asked to link. */
	public static array $asked = array();

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/opex-r2pub-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir );
		$this->source = $this->dir . '/export-source.csv';
		file_put_contents( $this->source, "id\n1\n2\n" );
		self::$asked = array();
		$this->set_link(
			static function ( string $target, string $link ): bool {
				clearstatcache();
				self::$asked[] = array(
					'path' => $target,
					'ino'  => fileinode( $target ),
				);
				return false;
			}
		);
	}

	protected function tearDown(): void {
		$this->set_link( 'link' );
		foreach ( (array) scandir( $this->dir ) as $file ) {
			if ( '.' !== $file && '..' !== $file ) {
				unlink( $this->dir . '/' . $file );
			}
		}
		rmdir( $this->dir );
	}

	/**
	 * Sets the function publish() makes hard links with.
	 *
	 * @param callable $link The function.
	 */
	private function set_link( $link ): void {
		$prop = new ReflectionProperty( OPEX_CLI_Runner::class, 'link' );
		if ( PHP_VERSION_ID < 80100 ) {
			$prop->setAccessible( true );
		}
		$prop->setValue( null, $link );
	}

	public function test_without_hard_links_the_finished_copy_is_renamed_into_place() {
		$dest = $this->dir . '/out.csv';
		$this->assertTrue( OPEX_CLI_Runner::publish( $this->source, $dest, false ) );
		$this->assertCount( 1, self::$asked, 'publish() tried a hard link first' );
		clearstatcache();
		$this->assertSame( self::$asked[0]['ino'], fileinode( $dest ), 'The final file is the finished copy, not a second stream into the final path.' );
		$this->assertSame( "id\n1\n2\n", file_get_contents( $dest ) );
		$this->assertSame( array( 'export-source.csv', 'out.csv' ), array_values( array_diff( scandir( $this->dir ), array( '.', '..' ) ) ), 'no temporary file is left' );
	}

	public function test_without_hard_links_an_existing_file_is_kept() {
		$dest = $this->dir . '/out.csv';
		file_put_contents( $dest, 'written by someone else' );
		$res = OPEX_CLI_Runner::publish( $this->source, $dest, false );
		$this->assertIsString( $res );
		$this->assertStringContainsString( '--force', $res );
		$this->assertSame( 'written by someone else', file_get_contents( $dest ) );
		$this->assertSame( array( 'export-source.csv', 'out.csv' ), array_values( array_diff( scandir( $this->dir ), array( '.', '..' ) ) ) );
	}
}
