<?php
/**
 * Shared helpers for the delivery tests: throwaway servers and job fixtures.
 */

/**
 * Starts and stops a helper process bound to 127.0.0.1.
 */
class OpexTestServer {

	/** @var resource|null */
	private $proc = null;
	/** @var array */
	private $pipes = array();
	/** @var string */
	public $dir = '';
	/** @var int */
	public $port = 0;

	/**
	 * Finds a free high port.
	 */
	public static function free_port(): int {
		$s = stream_socket_server( 'tcp://127.0.0.1:0' );
		$n = stream_socket_get_name( $s, false );
		fclose( $s );
		return (int) substr( $n, strrpos( $n, ':' ) + 1 );
	}

	/**
	 * Path of the venv python, or '' when the tools are not installed.
	 */
	public static function python(): string {
		$tools = (string) getenv( 'OPEX_TEST_TOOLS' );
		$py    = $tools . '/venv/bin/python';
		return ( '' !== $tools && is_executable( $py ) ) ? $py : '';
	}

	/**
	 * Makes a temp folder.
	 */
	public static function temp_dir(): string {
		$d = sys_get_temp_dir() . '/opex-test-' . bin2hex( random_bytes( 6 ) );
		mkdir( $d, 0700, true );
		return $d;
	}

	/**
	 * Starts a command and waits for a line containing READY (or a port to open).
	 *
	 * @param array $cmd  Command and arguments.
	 * @param array $env  Extra environment.
	 * @param bool  $wait_port Wait for the port instead of READY.
	 */
	public function start( array $cmd, array $env = array(), bool $wait_port = false ): bool {
		$this->proc = proc_open( $cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'file', '/dev/null', 'a' ) ), $this->pipes, null, array_merge( getenv(), $env ) );
		if ( ! is_resource( $this->proc ) ) {
			return false;
		}
		stream_set_blocking( $this->pipes[1], false );
		$deadline = microtime( true ) + 15;
		while ( microtime( true ) < $deadline ) {
			if ( $wait_port ) {
				$c = @stream_socket_client( 'tcp://127.0.0.1:' . $this->port, $en, $es, 0.2 );
				if ( $c ) {
					fclose( $c );
					return true;
				}
			} elseif ( false !== strpos( (string) fgets( $this->pipes[1] ), 'READY' ) ) {
				return true;
			}
			if ( ! proc_get_status( $this->proc )['running'] ) {
				return false;
			}
			usleep( 100000 );
		}
		return false;
	}

	/**
	 * Kills the process and removes the temp folder.
	 */
	public function stop(): void {
		if ( is_resource( $this->proc ) ) {
			proc_terminate( $this->proc, 9 );
			foreach ( $this->pipes as $p ) {
				if ( is_resource( $p ) ) {
					fclose( $p );
				}
			}
			proc_close( $this->proc );
			$this->proc = null;
		}
		if ( '' !== $this->dir && is_dir( $this->dir ) ) {
			self::rrmdir( $this->dir );
		}
	}

	/**
	 * Removes a folder tree.
	 */
	public static function rrmdir( string $dir ): void {
		foreach ( (array) scandir( $dir ) as $f ) {
			if ( '.' === $f || '..' === $f ) {
				continue;
			}
			$p = $dir . '/' . $f;
			is_dir( $p ) && ! is_link( $p ) ? self::rrmdir( $p ) : unlink( $p );
		}
		rmdir( $dir );
	}
}

/**
 * Builds and removes job records with real files.
 */
trait OpexDeliveryFixtures {

	/** @var array */
	protected $made_jobs = array();

	/**
	 * Creates a done job with a small CSV file.
	 */
	protected function make_job( array $over = array() ): array {
		$id   = 'jtest' . bin2hex( random_bytes( 4 ) );
		$name = 'orders-' . bin2hex( random_bytes( 4 ) ) . '.csv';
		$body = "id,name\n1,Ann\n2,Bob\n";
		file_put_contents( trailingslashit( opex_export_dir() ) . $name, $body );
		$job = array_merge(
			array(
				'id'            => $id,
				'label'         => 'Orders',
				'config'        => array( 'format' => 'csv' ),
				'headers'       => array(),
				'status'        => 'done',
				'total'         => 2,
				'processed'     => 2,
				'cursor'        => 0,
				'created'       => time(),
				'filename'      => $name,
				'filesize'      => strlen( $body ),
				'error'         => '',
				'finished'      => time(),
				'delivery_name' => 'Orders May',
			),
			$over
		);
		OPEX_Jobs::save( $job );
		$this->made_jobs[] = $id;
		return $job;
	}

	/**
	 * Removes the jobs and their files.
	 */
	protected function cleanup_jobs(): void {
		foreach ( $this->made_jobs as $id ) {
			OPEX_Jobs::delete( $id );
		}
		$this->made_jobs = array();
	}
}
