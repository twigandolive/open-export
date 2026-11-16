<?php
/**
 * Codex R1-5.6: `wp opex run` exits with code 1 when a delivery failed,
 * after it printed the file path and each delivery error, with and
 * without --porcelain. Runs real `wp` processes against the test site.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/servers/helpers.php';

/**
 * Exit code after a failed delivery.
 */
class CodexCliDeliveryExitTest extends TestCase {

	private string $schedule = '';

	private array $jobs_before = array();

	protected function setUp(): void {
		if ( '' === trim( (string) shell_exec( 'command -v wp 2>/dev/null' ) ) ) {
			$this->markTestSkipped( 'The wp command is not installed.' );
		}
		$this->bust();
		delete_option( 'opex_destinations' );
		$this->jobs_before = array_keys( OPEX_Jobs::all() );
	}

	protected function tearDown(): void {
		$this->bust();
		if ( '' !== $this->schedule ) {
			OPEX_Schedules::delete( $this->schedule );
		}
		foreach ( array_keys( OPEX_Jobs::all() ) as $id ) {
			if ( ! in_array( $id, $this->jobs_before, true ) ) {
				wp_clear_scheduled_hook( 'opex_deliver', array( (string) $id ) );
				OPEX_Jobs::delete( (string) $id );
			}
		}
		delete_option( 'opex_destinations' );
	}

	/** The child process changes options, so drop this process's copies. */
	private function bust(): void {
		foreach ( array( 'opex_jobs', 'opex_templates', 'opex_schedules', 'opex_destinations' ) as $key ) {
			wp_cache_delete( $key, 'options' );
		}
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * Runs `wp ...` in a child process.
	 *
	 * @param array $args Arguments.
	 * @return array { code, out, err }
	 */
	private function wp( array $args ): array {
		$cmd  = array_merge(
			array( PHP_BINARY, '-d', 'memory_limit=1G', '-d', 'error_reporting=22527', trim( (string) shell_exec( 'command -v wp' ) ), '--path=' . rtrim( (string) getenv( 'OPEX_TEST_SITE' ), '/' ) . '/wordpress' ),
			$args
		);
		$proc = proc_open( $cmd, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $proc );
		fclose( $pipes[0] );
		$out = stream_get_contents( $pipes[1] );
		$err = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return array(
			'code' => proc_close( $proc ),
			'out'  => (string) $out,
			'err'  => (string) $err,
		);
	}

	/**
	 * A schedule that delivers to an FTP server nobody runs.
	 *
	 * @return string Schedule id.
	 */
	private function failing_schedule(): string {
		$dest = OPEX_Destinations::save(
			array(
				'name'     => 'Nobody home',
				'type'     => 'ftp',
				'host'     => '127.0.0.1',
				'port'     => OpexTestServer::free_port(),
				'username' => 'u',
				'secret'   => 'pw',
				'path'     => '/',
			)
		);
		$this->assertIsArray( $dest );
		$rec = OPEX_Schedules::save(
			array(
				'name'      => 'CLI failing delivery',
				'config'    => array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'batch_size' => 50 ),
				'frequency' => 'manual',
				'enabled'   => true,
				'delivery'  => array(
					'destination' => array(
						'enabled' => true,
						'id'      => $dest['id'],
					),
				),
			)
		);
		$this->assertIsArray( $rec );
		$this->assertTrue( $rec['delivery']['destination']['enabled'] );
		$this->schedule = $rec['id'];
		return $rec['id'];
	}

	public function test_porcelain_prints_the_path_then_fails() {
		$id  = $this->failing_schedule();
		$res = $this->wp( array( 'opex', 'run', $id, '--porcelain' ) );
		$this->assertSame( 1, $res['code'], $res['out'] . $res['err'] );
		$lines = array_values( array_filter( explode( "\n", $res['out'] ), static fn( $l ) => '' !== $l ) );
		$this->assertCount( 1, $lines, 'STDOUT holds only the path' );
		$this->assertFileExists( $lines[0] );
		$this->assertStringContainsString( 'Delivery by destination failed', $res['err'] );
		$this->assertStringContainsString( 'Error:', $res['err'] );
	}

	public function test_normal_output_reports_the_file_then_fails() {
		$id  = $this->failing_schedule();
		$res = $this->wp( array( 'opex', 'run', $id ) );
		$this->assertSame( 1, $res['code'], $res['out'] . $res['err'] );
		$this->assertMatchesRegularExpression( '/Exported \d+ row\(s\) to \S+/', $res['out'] );
		$this->assertStringNotContainsString( 'Success:', $res['out'] );
		$this->assertStringContainsString( 'Delivery by destination failed', $res['err'] );
		$this->assertStringContainsString( '1 delivery(ies) failed', $res['err'] );
	}
}
