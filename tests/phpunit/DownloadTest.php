<?php
/**
 * Tests for OPEX_Download (headers, chunk loop, names).
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * The exit in stream() is not tested. Its parts are.
 */
class DownloadTest extends TestCase {

	/** @var string[] */
	private $files = array();

	protected function tearDown(): void {
		foreach ( $this->files as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		}
	}

	private function file( string $body ): string {
		$path          = trailingslashit( opex_export_dir() ) . 'dltest-' . bin2hex( random_bytes( 4 ) ) . '.bin';
		$this->files[] = $path;
		file_put_contents( $path, $body );
		return $path;
	}

	private function header_map( array $lines ): array {
		$out = array();
		foreach ( $lines as $line ) {
			list( $k, $v ) = explode( ': ', $line, 2 );
			$out[ $k ]     = $v;
		}
		return $out;
	}

	public function test_headers_for_each_format() {
		$expect = array(
			'csv'  => 'text/csv; charset=utf-8',
			'json' => 'application/json; charset=utf-8',
			'xml'  => 'application/xml; charset=utf-8',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		);
		foreach ( $expect as $format => $type ) {
			$h = $this->header_map( OPEX_Download::headers( array( 'config' => array( 'format' => $format ) ), 'a.' . $format, 1234 ) );
			$this->assertSame( $type, $h['Content-Type'], $format );
			$this->assertSame( '1234', $h['Content-Length'] );
			$this->assertSame( 'nosniff', $h['X-Content-Type-Options'] );
			$this->assertSame( 'no-store, private', $h['Cache-Control'] );
			$this->assertSame( 'attachment; filename="a.' . $format . '"', $h['Content-Disposition'] );
		}
		$h = $this->header_map( OPEX_Download::headers( array( 'config' => array( 'format' => 'csv', 'csv_encoding' => 'win1252' ) ), 'a.csv', 1 ) );
		$this->assertSame( 'text/csv; charset=windows-1252', $h['Content-Type'] );
	}

	public function test_disposition_ascii_fallback_and_rfc5987() {
		$h = $this->header_map( OPEX_Download::headers( array( 'config' => array( 'format' => 'csv' ) ), 'Café Ünï 日本.csv', 1 ) );
		$this->assertStringStartsWith( 'attachment; filename="Cafe Uni ', $h['Content-Disposition'] );
		$this->assertStringContainsString( "filename*=UTF-8''" . rawurlencode( 'Café Ünï 日本.csv' ), $h['Content-Disposition'] );
		$this->assertDoesNotMatchRegularExpression( '/[^\x20-\x7e]/', $h['Content-Disposition'] );
	}

	public function test_disposition_blocks_header_injection_and_paths() {
		$h = $this->header_map( OPEX_Download::headers( array( 'config' => array( 'format' => 'csv' ) ), "x\r\nSet-Cookie: a=b\"; ../../etc/passwd", 1 ) );
		$this->assertCount( 1, array_filter( array_keys( $h ), static function ( $k ) {
			return 'Content-Disposition' === $k;
		} ) );
		$this->assertStringNotContainsString( "\n", $h['Content-Disposition'] );
		$this->assertStringNotContainsString( '/', $h['Content-Disposition'] );
		$h = $this->header_map( OPEX_Download::headers( array( 'config' => array( 'format' => 'csv' ) ), '', 1 ) );
		$this->assertSame( 'attachment; filename="export"', $h['Content-Disposition'] );
	}

	public function test_file_name_adds_the_extension_once() {
		$this->assertSame( 'Orders May.csv', OPEX_Download::file_name( array( 'delivery_name' => 'Orders May', 'config' => array( 'format' => 'csv' ) ) ) );
		$this->assertSame( 'Orders.xlsx', OPEX_Download::file_name( array( 'delivery_name' => 'Orders.xlsx', 'config' => array( 'format' => 'xlsx' ) ) ) );
		$this->assertSame( 'My label.json', OPEX_Download::file_name( array( 'label' => 'My label', 'config' => array( 'format' => 'json' ) ) ) );
		$this->assertSame( 'export.csv', OPEX_Download::file_name( array( 'config' => array( 'format' => 'csv' ) ) ) );
		$this->assertStringNotContainsString( '/', OPEX_Download::file_name( array( 'label' => '../../a/b', 'config' => array( 'format' => 'csv' ) ) ) );
	}

	public function test_send_file_streams_exact_bytes_in_chunks() {
		$body = random_bytes( 2500 );
		$path = $this->file( $body );
		$out  = fopen( 'php://memory', 'w+b' );
		$this->assertSame( 2500, OPEX_Download::send_file( $path, $out, 1000 ) );
		rewind( $out );
		$this->assertSame( $body, stream_get_contents( $out ) );
		fclose( $out );
	}

	public function test_send_file_uses_one_megabyte_chunks_by_default() {
		$this->assertSame( 1048576, OPEX_Download::CHUNK );
		$body = str_repeat( 'abcdefgh', 200000 );
		$path = $this->file( $body );
		$out  = fopen( 'php://memory', 'w+b' );
		$this->assertSame( strlen( $body ), OPEX_Download::send_file( $path, $out ) );
		rewind( $out );
		$this->assertSame( md5( $body ), md5( (string) stream_get_contents( $out ) ) );
		fclose( $out );
	}

	public function test_send_file_handles_empty_and_missing_files() {
		$out = fopen( 'php://memory', 'w+b' );
		$this->assertSame( 0, OPEX_Download::send_file( $this->file( '' ), $out ) );
		$this->assertSame( -1, @OPEX_Download::send_file( '/no/such/file', $out ) );
		fclose( $out );
	}
}
