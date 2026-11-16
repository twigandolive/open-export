<?php
/**
 * Names people see keep the real extension and the right content type.
 *
 * On disk every export ends in ".opex" (see ExportFolderNamesTest). The
 * download, the email attachment, the remote upload name and the token link
 * never show that ending: they take the name from the delivery name and the
 * format, and the content type from the format.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A real export of every format, then every way out.
 */
class FriendlyNamesTest extends TestCase {

	/** @var string[] Job ids to remove. */
	private array $jobs = array();

	/** @var array<string, string> Captured attachments: name => bytes. */
	private array $mail = array();

	protected function setUp(): void {
		wp_set_current_user( 1 );
		$this->mail = array();
		add_filter(
			'pre_wp_mail',
			function ( $none, $atts ) {
				foreach ( (array) $atts['attachments'] as $file ) {
					$this->mail[ basename( $file ) ] = (string) file_get_contents( $file );
				}
				return true;
			},
			10,
			2
		);
	}

	protected function tearDown(): void {
		remove_all_filters( 'pre_wp_mail' );
		foreach ( $this->jobs as $id ) {
			OPEX_Jobs::delete( $id );
		}
		$this->jobs = array();
	}

	/**
	 * Format, compression, the name people see, its download content type,
	 * and the type a mail program gives the attachment from its name.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
	 */
	public static function formats(): array {
		$sheet = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
		return array(
			'csv'           => array( 'csv', 'none', 'Orders May.csv', 'text/csv; charset=utf-8', 'text/csv' ),
			'json'          => array( 'json', 'none', 'Orders May.json', 'application/json; charset=utf-8', 'application/octet-stream' ),
			'xml'           => array( 'xml', 'none', 'Orders May.xml', 'application/xml; charset=utf-8', 'text/xml' ),
			'xlsx'          => array( 'xlsx', 'none', 'Orders May.xlsx', $sheet, $sheet ),
			'html'          => array( 'html', 'none', 'Orders May.html', 'text/html; charset=utf-8', 'text/html' ),
			'csv with zip'  => array( 'csv', 'zip', 'Orders May.csv.zip', 'application/zip', 'application/zip' ),
			'html with zip' => array( 'html', 'zip', 'Orders May.html.zip', 'application/zip', 'application/zip' ),
		);
	}

	/**
	 * Runs a real export of the seeded posts to the end.
	 *
	 * @param string $format   Format id.
	 * @param string $compress Compression.
	 * @return array The stored job.
	 */
	private function export( string $format, string $compress ): array {
		if ( ( 'xlsx' === $format || 'zip' === $compress ) && ! OPEX_Compress::available() ) {
			$this->markTestSkipped( 'The PHP zip extension is not installed.' );
		}
		$config = OPEX_Exporter::sanitize_config(
			array(
				'source'     => 'posts:post',
				'fields'     => array( 'core:ID', 'core:post_title' ),
				'format'     => $format,
				'compress'   => $compress,
				'batch_size' => 25,
			)
		);
		$this->assertIsArray( $config );
		$job = OPEX_Exporter::create( $config, 'Friendly', array( 'delivery_name' => 'Orders May' ) );
		$this->assertIsArray( $job );
		$this->jobs[] = $job['id'];
		for ( $i = 0; $i < 100 && 'running' === $job['status']; $i++ ) {
			$job = OPEX_Exporter::run( $job['id'] );
		}
		$this->assertSame( 'done', $job['status'] );
		return OPEX_Jobs::get( $job['id'] );
	}

	/**
	 * The download, the email attachment and the remote name for every format.
	 *
	 * @param string $format   Format id.
	 * @param string $compress Compression.
	 * @param string $name     Name people see.
	 * @param string $type     Download content type.
	 * @param string $mailtype Attachment content type a mail program picks from the name.
	 * @dataProvider formats
	 */
	#[DataProvider( 'formats' )]
	public function test_every_way_out_uses_the_real_extension( string $format, string $compress, string $name, string $type, string $mailtype ): void {
		$job   = $this->export( $format, $compress );
		$disk  = OPEX_Jobs::file_path( $job );
		$bytes = (string) file_get_contents( $disk );
		$this->assertStringEndsWith( '.opex', $job['filename'] );

		// Download: the screen's route and the token link both stream with these headers.
		$this->assertSame( $name, OPEX_Download::file_name( $job ) );
		$headers = OPEX_Download::headers( $job, OPEX_Download::file_name( $job ), strlen( $bytes ) );
		$this->assertContains( 'Content-Type: ' . $type, $headers );
		$this->assertContains( 'Content-Disposition: attachment; filename="' . $name . '"', $headers );
		$this->assertSame( array(), preg_grep( '/opex/i', $headers ), 'No header shows the on-disk ending.' );

		// Email attachment and remote uploads: the delivery name with the real extension.
		$remote = str_replace( ' ', '-', $name );
		$this->assertSame( $remote, OPEX_Delivery::remote_name( $job ) );
		$delivery          = array(
			'email' => array(
				'enabled' => true,
				'to'      => array( 'a@example.com' ),
				'mode'    => 'attach',
			),
		);
		$job['deliveries'] = OPEX_Delivery::plan( $delivery );
		$items             = OPEX_Delivery::process( $job, $delivery );
		$this->assertSame( 'sent', $items[0]['status'], (string) $items[0]['message'] );
		$this->assertSame( array( $remote => $bytes ), $this->mail );
		if ( ! class_exists( 'PHPMailer\PHPMailer\PHPMailer' ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		}
		$this->assertSame( $mailtype, PHPMailer\PHPMailer\PHPMailer::filenameToType( $remote ) );
	}

	/** The token link in a webhook or email reaches the file of a new export. */
	public function test_the_token_link_reaches_an_opex_file(): void {
		$job     = $this->export( 'html', 'none' );
		$token   = OPEX_Download_Tokens::issue_saved( $job, 600 );
		$request = new WP_REST_Request( 'GET', '/open-export/v1/file/' . $job['id'] );
		$request->set_url_params( array( 'id' => $job['id'] ) );
		$request->set_param( 'token', $token );
		$this->assertTrue( OPEX_Rest_Public::can_download( $request ) );
		// The link names the export by id, never by its file on disk.
		$this->assertStringNotContainsString( OPEX_Jobs::real_name( $job['filename'] ), OPEX_Download_Tokens::url( $job, $token ) );
	}
}
