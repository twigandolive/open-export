<?php
/**
 * The bundled phpseclib stays out of the way of a copy another plugin brings.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class SftpLibraryTest extends TestCase {

	/**
	 * Another plugin registered a phpseclib 3 autoloader but has not loaded
	 * the classes yet. The plugin must use that copy and never register the
	 * bundled autoloader, so the two copies never mix. It runs in its own
	 * process because a class, once loaded, stays loaded.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_a_copy_another_plugin_registered_is_used() {
		if ( class_exists( 'phpseclib3\\Net\\SFTP', false ) ) {
			$this->markTestSkipped( 'phpseclib is already loaded in this process.' );
		}
		$base = dirname( __DIR__, 2 ) . '/lib/vendor/phpseclib/phpseclib/phpseclib/';
		$seen = array();
		spl_autoload_register(
			static function ( $class ) use ( $base, &$seen ) {
				if ( 0 !== strpos( $class, 'phpseclib3\\' ) ) {
					return;
				}
				$file = $base . str_replace( '\\', '/', substr( $class, strlen( 'phpseclib3\\' ) ) ) . '.php';
				if ( is_file( $file ) ) {
					$seen[] = $class;
					require $file;
				}
			}
		);

		$load = new ReflectionMethod( 'OPEX_Delivery_Sftp', 'load_library' );
		if ( PHP_VERSION_ID < 80100 ) {
			$load->setAccessible( true ); // Needed before PHP 8.1 only, and deprecated since.
		}

		$this->assertTrue( $load->invoke( null ) );
		$this->assertContains( 'phpseclib3\\Net\\SFTP', $seen, 'The other copy served the class.' );
		$bundled = realpath( dirname( __DIR__, 2 ) . '/lib/vendor/autoload.php' );
		$this->assertNotContains( $bundled, get_included_files(), 'The bundled autoloader was never loaded.' );
	}
}
