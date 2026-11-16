<?php
/**
 * CSV quoting options: csv_enclosure (double or single) and csv_quote_all.
 *
 * The writer tests write to temp files. The end-to-end test runs a real job
 * on two fixture posts made in setUp and removed in tearDown.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * The quote character, quoting every cell, and that the defaults change nothing.
 */
class CsvQuotingTest extends TestCase {

	/** @var string[] Temp files. */
	private array $files = array();

	/** @var int[] Fixture posts. */
	private array $posts = array();

	/** @var string[] Job ids. */
	private array $jobs = array();

	protected function setUp(): void {
		wp_set_current_user( 1 );
	}

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		foreach ( $this->posts as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( $this->jobs as $id ) {
			OPEX_Jobs::delete( $id );
		}
		$this->files = array();
		$this->posts = array();
		$this->jobs  = array();
	}

	/** A fresh temp path. */
	private function path(): string {
		$path          = sys_get_temp_dir() . '/opex-quote-' . uniqid( '', true ) . '.csv';
		$this->files[] = $path;
		return $path;
	}

	/**
	 * Writes a header and one batch through a CSV writer and returns the bytes.
	 *
	 * @param array $options Writer options.
	 * @param array $rows    Rows keyed by a and b.
	 */
	private function write( array $options, array $rows ): string {
		$path   = $this->path();
		$writer = OPEX_Writer::make( 'csv', $path, array( 'a' => 'A', 'b' => 'B' ), $options + array( 'csv_encoding' => 'utf8' ) );
		$writer->start();
		$writer->append( $rows, true );
		$writer->finish();
		return (string) file_get_contents( $path );
	}

	/** The defaults reproduce the 1.3.1 config shape: no new key is saved. */
	public function test_defaults_save_nothing_new(): void {
		$raw    = array(
			'source' => 'posts:post',
			'fields' => array( 'core:ID', 'core:post_title' ),
		);
		$config = OPEX_Exporter::sanitize_config( $raw );
		$this->assertIsArray( $config );
		foreach ( array( 'csv_enclosure', 'csv_quote_all', 'compress' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $config, $key );
		}
		$same = OPEX_Exporter::sanitize_config( $raw + array( 'csv_enclosure' => 'double', 'csv_quote_all' => false, 'compress' => 'none' ) );
		$this->assertSame( $config, $same, 'default values are not saved' );
	}

	/** Values are kept when set, and anything unknown falls back to the default. */
	public function test_sanitize_keeps_and_cleans(): void {
		$base   = array(
			'source' => 'posts:post',
			'fields' => array( 'core:ID' ),
		);
		$config = OPEX_Exporter::sanitize_config( $base + array( 'csv_enclosure' => 'single', 'csv_quote_all' => 'true' ) );
		$this->assertSame( 'single', $config['csv_enclosure'] );
		$this->assertTrue( $config['csv_quote_all'] );

		$config = OPEX_Exporter::sanitize_config( $base + array( 'csv_enclosure' => '`', 'csv_quote_all' => 'false' ) );
		$this->assertArrayNotHasKey( 'csv_enclosure', $config );
		$this->assertArrayNotHasKey( 'csv_quote_all', $config );

		$config = OPEX_Exporter::sanitize_config( $base + array( 'csv_enclosure' => array( 'x' ), 'csv_quote_all' => array( 1 ) ) );
		$this->assertArrayNotHasKey( 'csv_enclosure', $config, 'an array is not a quote character' );
	}

	/** Default options write exactly what fputcsv writes, so old files do not change. */
	public function test_default_writer_output_is_fputcsv(): void {
		$rows   = array(
			array(
				'a' => 'plain',
				'b' => 'with, comma and "quote"',
			),
		);
		$body   = $this->write( array(), $rows );
		$handle = fopen( 'php://temp', 'w+b' );
		fputcsv( $handle, array( 'A', 'B' ), ',', '"', '\\' );
		fputcsv( $handle, array( 'plain', 'with, comma and "quote"' ), ',', '"', '\\' );
		rewind( $handle );
		$this->assertSame( stream_get_contents( $handle ), $body );
		fclose( $handle );
	}

	/** Quote all: every cell is wrapped, inner quotes are doubled, and the formula guard still runs. */
	public function test_quote_all_double(): void {
		$body = $this->write(
			array( 'csv_quote_all' => true ),
			array(
				array(
					'a' => '12',
					'b' => 'say "hi"',
				),
				array(
					'a' => '=1+1',
					'b' => "two\nlines",
				),
				array(
					'a' => '-5',
					'b' => '',
				),
			)
		);
		$this->assertSame( "\"A\",\"B\"\n\"12\",\"say \"\"hi\"\"\"\n\"'=1+1\",\"two\nlines\"\n\"-5\",\"\"\n", $body );
	}

	/** Single quote: minimal quoting with ' as the quote, and every cell with quote all. */
	public function test_single_enclosure(): void {
		$rows = array(
			array(
				'a' => "it's",
				'b' => 'a,b',
			),
			array(
				'a' => 'say "hi"',
				'b' => 'plain',
			),
		);
		// fputcsv quotes a cell with a space in it, and a double quote is plain text here.
		$this->assertSame( "A,B\n'it''s','a,b'\n'say \"hi\"',plain\n", $this->write( array( 'csv_enclosure' => "'" ), $rows ) );
		$this->assertSame( "'A','B'\n'it''s','a,b'\n'say \"hi\"','plain'\n", $this->write( array( 'csv_enclosure' => "'", 'csv_quote_all' => true ), $rows ) );
	}

	/** Quote all works with the semicolon delimiter, CRLF line endings and every encoding. */
	public function test_quote_all_with_line_endings_and_encodings(): void {
		$rows = array(
			array(
				'a' => 'Café',
				'b' => "x\ny",
			),
		);
		$crlf = $this->write(
			array(
				'csv_quote_all' => true,
				'csv_eol'       => 'crlf',
				'csv_delimiter' => ';',
			),
			$rows
		);
		$this->assertSame( "\"A\";\"B\"\r\n\"Café\";\"x\ny\"\r\n", $crlf, 'only the record ends change' );

		$path   = $this->path();
		$writer = OPEX_Writer::make( 'csv', $path, array( 'a' => 'A', 'b' => 'B' ), array( 'csv_quote_all' => true, 'csv_encoding' => 'win1252' ) );
		$writer->start();
		$writer->append( $rows, true );
		$this->assertSame( "\"A\",\"B\"\n\"Caf\xE9\",\"x\ny\"\n", file_get_contents( $path ) );

		$path   = $this->path();
		$writer = OPEX_Writer::make( 'csv', $path, array( 'a' => 'A', 'b' => 'B' ), array( 'csv_quote_all' => true, 'csv_encoding' => 'utf16le' ) );
		$writer->start();
		$writer->append( $rows, true );
		$bytes = (string) file_get_contents( $path );
		$this->assertSame( "\xFF\xFE", substr( $bytes, 0, 2 ) );
		$this->assertSame( "\"A\",\"B\"\n\"Café\",\"x\ny\"\n", mb_convert_encoding( substr( $bytes, 2 ), 'UTF-8', 'UTF-16LE' ) );

		$path   = $this->path();
		$writer = OPEX_Writer::make( 'csv', $path, array( 'a' => 'A' ), array( 'csv_quote_all' => true ) );
		$writer->start();
		$this->assertSame( "\xEF\xBB\xBF\"A\"\n", file_get_contents( $path ), 'the BOM stays first' );
	}

	/** A batch replayed from a checkpoint gives the same bytes with quote all on. */
	public function test_quote_all_replays_from_a_checkpoint(): void {
		$options = array( 'csv_quote_all' => true );
		$path    = $this->path();
		$writer  = OPEX_Writer::make( 'csv', $path, array( 'a' => 'A' ), $options );
		$writer->start();
		$writer->append( array( array( 'a' => '1' ) ), true );
		$mark = $writer->checkpoint();
		$writer->append( array( array( 'a' => 'lost' ) ), false );
		$writer->restore( $mark );
		$writer->append( array( array( 'a' => '2' ) ), false );
		$this->assertSame( "\xEF\xBB\xBF\"A\"\n\"1\"\n\"2\"\n", file_get_contents( $path ) );
	}

	/** A real job with quote all reads back to the same cells as the plain job. */
	public function test_job_with_quote_all_matches_plain_job(): void {
		$this->posts[] = (int) wp_insert_post( array( 'post_title' => 'Quote "me", please', 'post_status' => 'publish' ) );
		$this->posts[] = (int) wp_insert_post( array( 'post_title' => '=HYPERLINK("x")', 'post_status' => 'publish' ) );
		$raw           = array(
			'source'     => 'posts:post',
			'fields'     => array( 'core:ID', 'core:post_title' ),
			'batch_size' => 10,
			'filters'    => array(
				'relation' => 'AND',
				'rules'    => array(
					array(
						'field' => 'core:ID',
						'op'    => 'in',
						'value' => implode( ',', $this->posts ),
					),
				),
			),
		);
		$plain         = $this->read_rows( $this->run_job( $raw ), '"' );
		$quoted        = $this->run_job( $raw + array( 'csv_quote_all' => true, 'csv_enclosure' => 'single' ) );
		$this->assertStringContainsString( "'Quote \"me\", please'", $quoted );
		$this->assertSame( $plain, $this->read_rows( $quoted, "'" ) );
		$this->assertContains( "'=HYPERLINK(\"x\")", array_column( $plain, 1 ), 'the formula guard still runs' );
	}

	/**
	 * Runs a config to done and returns the file body without the BOM.
	 *
	 * @param array $raw Raw config.
	 */
	private function run_job( array $raw ): string {
		$config = OPEX_Exporter::sanitize_config( $raw );
		$this->assertIsArray( $config );
		$job = OPEX_Exporter::create( $config, 'Quote test' );
		$this->assertIsArray( $job );
		$this->jobs[] = $job['id'];
		for ( $i = 0; $i < 50 && 'running' === $job['status']; $i++ ) {
			$job = OPEX_Exporter::run( $job['id'] );
		}
		$this->assertSame( 'done', $job['status'] );
		return substr( (string) file_get_contents( OPEX_Jobs::file_path( OPEX_Jobs::get( $job['id'] ) ) ), 3 );
	}

	/**
	 * Parses CSV text the way a spreadsheet does (RFC 4180, no escape character).
	 *
	 * @param string $body      CSV text.
	 * @param string $enclosure Quote character.
	 * @return array[]
	 */
	private function read_rows( string $body, string $enclosure ): array {
		$handle = fopen( 'php://temp', 'w+b' );
		fwrite( $handle, $body );
		rewind( $handle );
		$rows = array();
		while ( false !== ( $row = fgetcsv( $handle, 0, ',', $enclosure, '' ) ) ) {
			$rows[] = $row;
		}
		fclose( $handle );
		sort( $rows );
		return $rows;
	}
}
