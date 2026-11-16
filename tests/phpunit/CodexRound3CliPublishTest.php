<?php
/**
 * On a filesystem without hard links, two runs that publish
 * --output to the same path without --force can never both succeed. The
 * first claims the name with an exclusive create, the second then fails
 * with the usual "use --force" error, and the first file stays whole.
 *
 * Two seams: OPEX_CLI_Runner::$link fails the way link() does on such a
 * filesystem, and OPEX_CLI_Runner::$rename lets the test run the second
 * publish at the moment before the first one renames its copy into place,
 * which is the moment a second run could slip in. It can also fail the rename.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Publishing --output without hard links, with two runs at once.
 */
class CodexRound3CliPublishTest extends TestCase {

	private string $dir = '';

	/** @var callable|null What the rename stand-in does before the real rename, once. */
	public static $before = null;

	/** @var bool When true, the rename stand-in fails without renaming. */
	public static bool $fail = false;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/opex-r3pub-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir );
		self::$before = null;
		self::$fail   = false;
		$this->set_seam(
			'link',
			static function (): bool {
				return false;
			}
		);
		$this->set_seam(
			'rename',
			static function ( string $from, string $to ): bool {
				$before       = self::$before;
				self::$before = null;
				if ( null !== $before ) {
					$before();
				}
				return self::$fail ? false : rename( $from, $to );
			}
		);
	}

	protected function tearDown(): void {
		$this->set_seam( 'link', 'link' );
		$this->set_seam( 'rename', 'rename' );
		foreach ( (array) scandir( $this->dir ) as $file ) {
			if ( '.' !== $file && '..' !== $file ) {
				unlink( $this->dir . '/' . $file );
			}
		}
		rmdir( $this->dir );
	}

	/**
	 * Sets one of the functions publish() uses.
	 *
	 * @param string   $name Property name.
	 * @param callable $fn   The function.
	 */
	private function set_seam( string $name, $fn ): void {
		$prop = new ReflectionProperty( OPEX_CLI_Runner::class, $name );
		if ( PHP_VERSION_ID < 80100 ) {
			$prop->setAccessible( true );
		}
		$prop->setValue( null, $fn );
	}

	/**
	 * A finished export file.
	 *
	 * @param string $name Name in the test folder.
	 * @param string $text Content.
	 * @return string Path.
	 */
	private function source( string $name, string $text ): string {
		file_put_contents( $this->dir . '/' . $name, $text );
		return $this->dir . '/' . $name;
	}

	/**
	 * Names in the test folder.
	 *
	 * @return string[] Sorted names.
	 */
	private function names(): array {
		$names = array_values( array_diff( scandir( $this->dir ), array( '.', '..' ) ) );
		sort( $names );
		return $names;
	}

	public function test_a_second_run_in_the_window_before_the_rename_fails_and_the_first_file_stays(): void {
		$first  = $this->source( 'first.csv', "id\n1\n" );
		$second = $this->source( 'second.csv', "id\n2\n" );
		$dest   = $this->dir . '/out.csv';
		$result = null;
		// The second run starts and finishes while the first is about to rename.
		self::$before = static function () use ( $second, $dest, &$result ): void {
			$result = OPEX_CLI_Runner::publish( $second, $dest, false );
		};

		$this->assertTrue( OPEX_CLI_Runner::publish( $first, $dest, false ), 'The first run publishes.' );

		$this->assertIsString( $result, 'The second run fails.' );
		$this->assertStringContainsString( '--force', $result );
		$this->assertSame( "id\n1\n", file_get_contents( $dest ), 'The first run\'s file is the one in place, whole.' );
		$this->assertSame( array( 'first.csv', 'out.csv', 'second.csv' ), $this->names(), 'No temporary file or placeholder is left.' );
	}

	public function test_a_failed_rename_leaves_no_placeholder(): void {
		$first      = $this->source( 'first.csv', "id\n1\n" );
		$dest       = $this->dir . '/out.csv';
		self::$fail = true;

		$result = OPEX_CLI_Runner::publish( $first, $dest, false );

		$this->assertSame( 'Could not write the --output file.', $result );
		$this->assertFileDoesNotExist( $dest, 'The empty placeholder is removed.' );
		$this->assertSame( array( 'first.csv' ), $this->names(), 'No temporary file is left.' );
	}

	public function test_a_failed_rename_keeps_a_file_someone_else_put_in_place(): void {
		$first        = $this->source( 'first.csv', "id\n1\n" );
		$dest         = $this->dir . '/out.csv';
		self::$fail   = true;
		self::$before = static function () use ( $dest ): void {
			// Another program replaces the placeholder with its own file.
			$other = $dest . '.other';
			file_put_contents( $other, 'written by someone else' );
			rename( $other, $dest );
		};

		$this->assertSame( 'Could not write the --output file.', OPEX_CLI_Runner::publish( $first, $dest, false ) );
		$this->assertSame( 'written by someone else', file_get_contents( $dest ), 'Only this run\'s own empty placeholder is ever removed.' );
	}

	public function test_force_still_replaces_the_file(): void {
		$first = $this->source( 'first.csv', "id\n1\n" );
		$dest  = $this->dir . '/out.csv';
		file_put_contents( $dest, 'old' );

		$this->assertTrue( OPEX_CLI_Runner::publish( $first, $dest, true ) );
		$this->assertSame( "id\n1\n", file_get_contents( $dest ) );
		$this->assertSame( array( 'first.csv', 'out.csv' ), $this->names() );
	}
}
