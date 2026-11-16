<?php
/**
 * Shared helpers for the 1.3.1 characterization tests.
 *
 * These tests pin what the plugin does today, even where it looks wrong, so a
 * later refactor of the query layer can be proven to change nothing.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Base class: runs the real engine and compares output with golden files.
 */
abstract class CharacterizationCase extends TestCase {

	/**
	 * Job ids created by the current test, removed in tear_down.
	 *
	 * @var string[]
	 */
	protected $job_ids = array();

	/**
	 * Post ids created by the current test.
	 *
	 * @var int[]
	 */
	protected $temp_posts = array();

	/**
	 * Start every test as the admin.
	 */
	protected function setUp(): void {
		wp_set_current_user( 1 );
	}

	/**
	 * Remove jobs, files and posts a test made.
	 */
	protected function tearDown(): void {
		foreach ( $this->job_ids as $id ) {
			OPEX_Jobs::delete( $id );
		}
		foreach ( $this->temp_posts as $id ) {
			wp_delete_post( $id, true );
		}
		$this->job_ids    = array();
		$this->temp_posts = array();
		wp_set_current_user( 1 );
	}

	/**
	 * Assert a value is a WP_Error.
	 *
	 * @param mixed  $value Value.
	 * @param string $msg   Message.
	 */
	public static function assertWPError( $value, string $msg = '' ): void {
		self::assertInstanceOf( WP_Error::class, $value, $msg );
	}

	/**
	 * Sanitize a raw config and fail the test if it is rejected.
	 *
	 * @param array $raw   Raw config.
	 * @param array $notes Notes out.
	 * @return array
	 */
	protected function config( array $raw, array &$notes = array() ): array {
		$config = OPEX_Exporter::sanitize_config( $raw, $notes );
		$this->assertNotInstanceOf( WP_Error::class, $config, is_wp_error( $config ) ? $config->get_error_message() : '' );
		return $config;
	}

	/**
	 * Run a raw config through sanitize, create and run-until-done.
	 *
	 * @param array  $raw   Raw config.
	 * @param string $label Job label.
	 * @return array Keys: config, job, content, batches, total.
	 */
	protected function export( array $raw, string $label = 'char' ): array {
		$config = $this->config( $raw );
		$total  = OPEX_Query::count( $config );
		$job    = OPEX_Exporter::create( $config, $label );
		$this->assertIsArray( $job, is_wp_error( $job ) ? $job->get_error_message() : '' );
		$this->job_ids[] = $job['id'];

		$batches = 0;
		while ( 'done' !== $job['status'] && $batches < 400 ) {
			$job = OPEX_Exporter::run( $job['id'] );
			$this->assertIsArray( $job, is_wp_error( $job ) ? $job->get_error_message() : '' );
			++$batches;
			$this->assertNotSame( 'error', $job['status'], (string) $job['error'] );
		}
		$this->assertSame( 'done', $job['status'] );

		$record  = OPEX_Jobs::get( $job['id'] );
		$content = (string) file_get_contents( OPEX_Jobs::file_path( $record ) );

		return array(
			'config'  => $config,
			'job'     => $job,
			'content' => $content,
			'batches' => $batches,
			'total'   => $total,
		);
	}

	/**
	 * Parse CSV text (BOM removed) into rows.
	 *
	 * @param string $content   CSV.
	 * @param string $delimiter Delimiter.
	 * @return array[]
	 */
	protected function parse_csv( string $content, string $delimiter = ',' ): array {
		if ( 0 === strpos( $content, "\xEF\xBB\xBF" ) ) {
			$content = substr( $content, 3 );
		}
		$h = fopen( 'php://memory', 'r+' );
		fwrite( $h, $content );
		rewind( $h );
		$rows = array();
		while ( false !== ( $row = fgetcsv( $h, 0, $delimiter, '"', '\\' ) ) ) {
			$rows[] = $row;
		}
		fclose( $h );
		return $rows;
	}

	/**
	 * Summarize an export result for golden comparison.
	 *
	 * @param array $res Result of export().
	 * @return array
	 */
	protected function summarize( array $res ): array {
		$format  = $res['config']['format'];
		$content = $res['content'];
		$sum     = array(
			'format'    => $format,
			'count'     => $res['total'],
			'processed' => (int) $res['job']['processed'],
			'filesize'  => strlen( $content ),
			'batches'   => $res['batches'],
			'sha256'    => hash( 'sha256', $content ),
		);
		if ( 'csv' === $format ) {
			$delims = array( 'comma' => ',', 'semicolon' => ';', 'tab' => "\t" );
			$rows   = $this->parse_csv( $content, $delims[ $res['config']['csv_delimiter'] ] );
			$sum['header'] = $rows[0] ?? array();
			$sum['first']  = $rows[1] ?? null;
			$sum['last']   = count( $rows ) > 1 ? end( $rows ) : null;
			$sum['rows']   = max( 0, count( $rows ) - 1 );
		} elseif ( 'json' === $format ) {
			$data          = json_decode( $content, true );
			$sum['rows']   = is_array( $data ) ? count( $data ) : -1;
			$sum['first']  = $data[0] ?? null;
			$sum['last']   = is_array( $data ) && $data ? end( $data ) : null;
			$sum['header'] = isset( $data[0] ) ? array_keys( $data[0] ) : array();
		} else {
			$sum['rows']  = substr_count( $content, '<record>' );
			$start        = strpos( $content, '<record>' );
			$sum['first'] = false === $start ? null : substr( $content, $start, (int) strpos( $content, '</record>', $start ) + 9 - $start );
			$start        = strrpos( $content, '<record>' );
			$sum['last']  = false === $start ? null : substr( $content, $start, (int) strpos( $content, '</record>', $start ) + 9 - $start );
		}
		return $sum;
	}

	/**
	 * Compare an export with its golden files (summary JSON plus raw output).
	 *
	 * Golden files are written only when OPEX_UPDATE_GOLDEN=1 and the file is
	 * missing. Otherwise a missing file fails the test.
	 *
	 * @param string $name Golden name.
	 * @param array  $res  Result of export().
	 */
	protected function assert_golden( string $name, array $res ): void {
		$sum = $this->summarize( $res );
		$this->assertSame( $res['total'], $sum['rows'], "$name: row count equals OPEX_Query::count" );
		$this->assert_golden_data( $name . '.summary.json', wp_json_encode( $sum, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
		$this->assert_golden_data( $name . '.out', $res['content'] );
	}

	/**
	 * Compare a PHP value with a JSON golden file.
	 *
	 * @param string $name Golden name (without extension).
	 * @param mixed  $data Value.
	 */
	protected function assert_golden_json( string $name, $data ): void {
		$this->assert_golden_data( $name . '.json', wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
	}

	/**
	 * Compare a string with a golden file.
	 *
	 * @param string $file    File name inside golden/.
	 * @param string $content Actual content.
	 */
	protected function assert_golden_data( string $file, string $content ): void {
		$main    = __DIR__ . '/golden/' . $file;
		$variant = __DIR__ . '/golden/' . $this->db_flavor() . '/' . $file;
		$path    = file_exists( $variant ) ? $variant : $main;
		if ( ! file_exists( $main ) ) {
			if ( getenv( 'OPEX_UPDATE_GOLDEN' ) ) {
				file_put_contents( $main, $content );
				$this->addToAssertionCount( 1 );
				return;
			}
			$this->fail( "Missing golden file $file (run with OPEX_UPDATE_GOLDEN=1 to create it)." );
		}
		if ( 'variant' === getenv( 'OPEX_UPDATE_GOLDEN' ) && 'sqlite' !== $this->db_flavor() && file_get_contents( $path ) !== $content ) {
			// Record a per-database variant: the output on this database differs from the SQLite golden.
			wp_mkdir_p( dirname( $variant ) );
			file_put_contents( $variant, $content );
			$this->addToAssertionCount( 1 );
			return;
		}
		$this->assertSame( file_get_contents( $path ), $content, "Golden mismatch: $file ({$this->db_flavor()})" );
	}

	/**
	 * Skips a test whose golden output was recorded on a single site. On a
	 * multisite network the same code sees network-wide users, every site's
	 * tables and other seed data, so the output differs without a defect.
	 *
	 * @param string $why What differs on a network.
	 */
	protected function single_site_only( string $why ): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single-site golden: ' . $why );
		}
	}

	/**
	 * Database flavor: sqlite or mariadb. Variants live in golden/<flavor>/.
	 *
	 * @return string
	 */
	protected function db_flavor(): string {
		global $wpdb;
		return ( defined( 'SQLITE_DB_DROPIN_VERSION' ) || $wpdb instanceof WP_SQLite_DB ) ? 'sqlite' : 'mariadb';
	}

	/**
	 * Create a post at a given age (days ago, from now) and track it.
	 *
	 * @param string $title Title.
	 * @param int|float $days Days ago.
	 * @param array  $extra Extra post args.
	 * @return int
	 */
	protected function temp_post( string $title, $days, array $extra = array() ): int {
		$when = gmdate( 'Y-m-d H:i:s', time() - (int) round( $days * DAY_IN_SECONDS ) );
		$id   = wp_insert_post( array_merge( array(
			'post_title'    => $title,
			'post_status'   => 'publish',
			'post_date'     => $when,
			'post_date_gmt' => $when,
		), $extra ) );
		$this->temp_posts[] = $id;
		return $id;
	}

	/**
	 * Count rows for a posts config built from a field list and filters.
	 *
	 * @param string $source  Source id.
	 * @param array  $rules   Filter rules.
	 * @param string $rel     AND or OR.
	 * @param array  $date    Date range.
	 * @return int
	 */
	protected function count_for( string $source, array $rules, string $rel = 'AND', array $date = array() ): int {
		$raw = array(
			'source'     => $source,
			'fields'     => array( $this->first_field( $source ) ),
			'filters'    => array( 'relation' => $rel, 'rules' => $rules ),
			'date_range' => $date,
		);
		$config = $this->config( $raw );
		return OPEX_Query::count( $config );
	}

	/**
	 * A field that every source has.
	 *
	 * @param string $source Source id.
	 * @return string
	 */
	protected function first_field( string $source ): string {
		if ( 0 === strpos( $source, 'table:' ) ) {
			return 'col:ID';
		}
		if ( 'users' === $source ) {
			return 'core:ID';
		}
		if ( 'comments' === $source ) {
			return 'core:comment_ID';
		}
		if ( 'woo:orders' === $source ) {
			return 'woo:order_id';
		}
		return 'core:ID';
	}

	/**
	 * Ordered ids of rows a config returns (first field, one batch).
	 *
	 * @param array $config Sanitized config.
	 * @return string[]
	 */
	protected function ids_for( array $config ): array {
		$batch = OPEX_Query::batch( $config, 0, 5000 );
		$field = $config['fields'][0];
		return array_map( static function ( $row ) use ( $field ) {
			return (string) $row[ $field ];
		}, $batch['items'] );
	}

	/**
	 * Order storage mode this run expects.
	 *
	 * @return string hpos or legacy.
	 */
	protected function storage_mode(): string {
		return 'legacy' === getenv( 'OPEX_ORDER_STORAGE' ) ? 'legacy' : 'hpos';
	}
}
