<?php
/**
 * The HTML format: one self-contained, print-friendly table with every cell escaped.
 *
 * Writer tests use temp files. The job test reads two fixture posts made in
 * the test and removed in tearDown.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * OPEX_Writer_HTML and the html format id.
 */
class WriterHtmlTest extends TestCase {

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
		$path          = sys_get_temp_dir() . '/opex-html-' . uniqid( '', true ) . '.html';
		$this->files[] = $path;
		return $path;
	}

	/**
	 * Loads HTML into a DOM, failing on any parse error.
	 *
	 * @param string $html Page.
	 */
	private function dom( string $html ): DOMDocument {
		$doc  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$this->assertTrue( $doc->loadHTML( $html, LIBXML_NONET ) );
		$errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$this->assertSame( array(), array_map( 'trim', array_column( array_map( 'get_object_vars', $errors ), 'message' ) ), 'the page parses without errors' );
		return $doc;
	}

	/** The page: title, header row, escaped cells, line breaks, no script and no remote asset. */
	public function test_page_shape_and_escaping(): void {
		$path   = $this->path();
		$writer = OPEX_Writer::make( 'html', $path, array( 'a' => 'Name <b>', 'b' => 'Notes' ), array( 'title' => 'Orders & <Refunds>' ) );
		$writer->start();
		$writer->append(
			array(
				array(
					'a' => '<script>alert(1)</script>',
					'b' => "line one\nline two & \"three\"",
				),
				array(
					'a' => "bad \xC3\x28 utf8 \x07bell",
					'b' => '<img src="https://example.com/x.png">',
				),
			),
			true
		);
		$writer->finish();
		$html = (string) file_get_contents( $path );

		$this->assertStringStartsWith( "<!DOCTYPE html>\n", $html );
		$this->assertStringContainsString( '<title>Orders &amp; &lt;Refunds&gt;</title>', $html );
		$this->assertStringContainsString( '<th scope="col">Name &lt;b&gt;</th>', $html );
		$this->assertStringContainsString( '<td>&lt;script&gt;alert(1)&lt;/script&gt;</td>', $html );
		$this->assertStringContainsString( '<td>line one<br>line two &amp; &quot;three&quot;</td>', $html );
		// WordPress drops the bad byte, or replaces it with U+FFFD on newer versions. Either way the rest is kept.
		$this->assertMatchesRegularExpression( '/<td>bad \x{FFFD}?\( utf8 bell<\/td>/u', $html, 'invalid bytes and control characters do not empty the cell' );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( '<link', $html );
		$this->assertDoesNotMatchRegularExpression( '/<[a-z][^>]*\s(src|href)=/i', $html, 'no element loads anything' );
		$this->assertStringContainsString( '@media print', $html );
		$this->assertStringEndsWith( "</tbody>\n</table>\n</body>\n</html>\n", $html );

		$doc  = $this->dom( $html );
		$rows = $doc->getElementsByTagName( 'tr' );
		$this->assertSame( 3, $rows->length );
		$this->assertSame( '<script>alert(1)</script>', $rows->item( 1 )->getElementsByTagName( 'td' )->item( 0 )->textContent );
		$this->assertSame( 'Orders & <Refunds>', $doc->getElementsByTagName( 'title' )->item( 0 )->textContent );
	}

	/** Text that looks like an entity shows as typed: "&amp;" stays "&amp;", not "&". */
	public function test_every_cell_shows_exactly_its_text(): void {
		$path   = $this->path();
		$values = array( '&amp;', 'A &lt; B', '&#8212; and &copy;', "Tom's \"quote\"" );
		$writer = OPEX_Writer::make( 'html', $path, array( 'a' => '&amp; header' ), array( 'title' => 'R&amp;D' ) );
		$writer->start();
		$rows = array();
		foreach ( $values as $value ) {
			$rows[] = array( 'a' => $value );
		}
		$writer->append( $rows, true );
		$writer->finish();
		$doc = $this->dom( (string) file_get_contents( $path ) );

		$this->assertSame( 'R&amp;D', $doc->getElementsByTagName( 'title' )->item( 0 )->textContent );
		$this->assertSame( '&amp; header', $doc->getElementsByTagName( 'th' )->item( 0 )->textContent );
		$cells = $doc->getElementsByTagName( 'td' );
		foreach ( $values as $i => $value ) {
			$this->assertSame( $value, $cells->item( $i )->textContent );
		}
	}

	/** A batch replayed from a checkpoint, and a finish replayed, give the same page. */
	public function test_checkpoint_replay(): void {
		$headers = array( 'a' => 'A' );
		$ref     = $this->path();
		$writer  = OPEX_Writer::make( 'html', $ref, $headers, array( 'title' => 'T' ) );
		$writer->start();
		$writer->append( array( array( 'a' => '1' ), array( 'a' => '2' ) ), true );
		$writer->finish();

		$path = $this->path();
		// Each call uses a new writer, as a new PHP process would.
		OPEX_Writer::make( 'html', $path, $headers, array( 'title' => 'T' ) )->start();
		OPEX_Writer::make( 'html', $path, $headers )->append( array( array( 'a' => '1' ) ), true );
		$mark = OPEX_Writer::make( 'html', $path, $headers )->checkpoint();
		OPEX_Writer::make( 'html', $path, $headers )->append( array( array( 'a' => 'lost' ) ), false );
		OPEX_Writer::make( 'html', $path, $headers )->restore( $mark );
		OPEX_Writer::make( 'html', $path, $headers )->append( array( array( 'a' => '2' ) ), false );
		$before = OPEX_Writer::make( 'html', $path, $headers )->checkpoint();
		OPEX_Writer::make( 'html', $path, $headers )->finish();
		// A finish that ran but was never saved is undone and run again.
		OPEX_Writer::make( 'html', $path, $headers )->restore( $before );
		OPEX_Writer::make( 'html', $path, $headers )->finish();
		$this->assertSame( file_get_contents( $ref ), file_get_contents( $path ) );
	}

	/** The format is listed, accepted by sanitize_config, read from recipes and described in the document. */
	public function test_format_is_offered_everywhere(): void {
		$formats = OPEX_Writer::formats();
		$this->assertSame( 'html', $formats['html']['extension'] );
		$this->assertSame( 'text/html', $formats['html']['mime'] );
		$this->assertTrue( $formats['html']['available'] );

		$config = OPEX_Exporter::sanitize_config(
			array(
				'source' => 'posts:post',
				'fields' => array( 'core:ID' ),
				'format' => 'html',
			)
		);
		$this->assertSame( 'html', $config['format'] );

		$notes  = array();
		$recipe = OPEX_Recipe::to_config(
			array(
				'source' => 'posts:post',
				'fields' => array( 'core:ID' ),
				'format' => 'html',
			),
			$notes
		);
		$this->assertTrue( $recipe['ok'] );
		$this->assertSame( 'html', $recipe['config']['format'] );

		$doc = OPEX_Schema::build();
		$this->assertContains( 'html', $doc['formats'] );
		$this->assertSame( 'html', $doc['format_options']['html']['extension'] );
		$this->assertArrayHasKey( 'html', OPEX_Rest_Delivery::options()->get_data()['formats'] );
	}

	/** A real job writes a valid page with the export name as its title, and downloads as HTML. */
	public function test_job_writes_a_page(): void {
		$this->posts[] = (int) wp_insert_post( array( 'post_title' => 'Fish & <Chips>', 'post_status' => 'publish' ) );
		$this->posts[] = (int) wp_insert_post( array( 'post_title' => 'Plain', 'post_status' => 'publish' ) );
		$config        = OPEX_Exporter::sanitize_config(
			array(
				'source'     => 'posts:post',
				'fields'     => array( 'core:ID', 'core:post_title' ),
				'format'     => 'html',
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
			)
		);
		$job           = OPEX_Exporter::create( $config, 'Menu <list>' );
		$this->assertIsArray( $job );
		$this->jobs[] = $job['id'];
		for ( $i = 0; $i < 20 && 'running' === $job['status']; $i++ ) {
			$job = OPEX_Exporter::run( $job['id'] );
		}
		$this->assertSame( 'done', $job['status'] );
		$stored = OPEX_Jobs::get( $job['id'] );
		$this->assertStringEndsWith( '.html.opex', $stored['filename'] );
		$html = (string) file_get_contents( OPEX_Jobs::file_path( $stored ) );
		$this->assertStringContainsString( '<title>Menu &lt;list&gt;</title>', $html );
		$this->assertStringContainsString( '<td>Fish &amp; &lt;Chips&gt;</td>', $html );
		$doc = $this->dom( $html );
		$this->assertSame( 3, $doc->getElementsByTagName( 'tr' )->length );
		$this->assertSame( 'Menu _list_.html', OPEX_Download::file_name( $stored ) );
		$this->assertContains( 'Content-Type: text/html; charset=utf-8', OPEX_Download::headers( $stored, 'x.html', 1 ) );
	}
}
