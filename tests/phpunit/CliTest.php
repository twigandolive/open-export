<?php
/**
 * WP-CLI commands, run as real `wp` processes against the test site.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class CliTest extends TestCase {

	private array $template_ids = array();
	private array $schedule_ids = array();
	private array $job_ids_before = array();
	private string $tmp = '';

	protected function setUp(): void {
		$this->bust();
		$this->job_ids_before = array_keys( OPEX_Jobs::all() );
		$this->tmp            = sys_get_temp_dir() . '/opex-cli-' . wp_generate_password( 8, false );
		mkdir( $this->tmp );
		if ( ! $this->wp_binary() ) {
			$this->markTestSkipped( 'The wp command is not installed.' );
		}
	}

	protected function tearDown(): void {
		$this->bust();
		foreach ( $this->template_ids as $id ) {
			OPEX_Templates::delete( $id );
		}
		foreach ( $this->schedule_ids as $id ) {
			OPEX_Schedules::delete( $id );
		}
		foreach ( array_keys( OPEX_Jobs::all() ) as $id ) {
			if ( ! in_array( $id, $this->job_ids_before, true ) ) {
				OPEX_Jobs::delete( (string) $id );
			}
		}
		$this->template_ids = array();
		$this->schedule_ids = array();
		foreach ( (array) glob( $this->tmp . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->tmp );
	}

	/** The child process changes options, so drop this process's copies. */
	private function bust(): void {
		foreach ( array( 'opex_jobs', 'opex_templates', 'opex_schedules' ) as $key ) {
			wp_cache_delete( $key, 'options' );
		}
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	private function wp_binary(): string {
		return (string) trim( (string) shell_exec( 'command -v wp 2>/dev/null' ) );
	}

	/**
	 * Run `wp opex ...` in a child process.
	 *
	 * @return array { code, out, err }
	 */
	private function wp( array $args, string $stdin = '' ): array {
		$cmd = array_merge(
			array(
				PHP_BINARY,
				'-d',
				'memory_limit=1G',
				'-d',
				'error_reporting=22527',
				$this->wp_binary(),
				// The active plugin registers `wp opex` itself, so the command is not added again here.
				'--path=' . rtrim( (string) getenv( 'OPEX_TEST_SITE' ), '/' ) . '/wordpress',
			),
			$args
		);
		$proc = proc_open( $cmd, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $proc );
		fwrite( $pipes[0], $stdin );
		fclose( $pipes[0] );
		$out = stream_get_contents( $pipes[1] );
		$err = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return array( 'code' => proc_close( $proc ), 'out' => (string) $out, 'err' => (string) $err );
	}

	private function template( string $name = 'CLI test template' ): array {
		$config = array( 'source' => 'posts:post', 'fields' => array( 'core:ID', 'core:post_title' ), 'batch_size' => 10, 'format' => 'csv' );
		$saved  = OPEX_Templates::save( $name, $config );
		$this->assertIsArray( $saved );
		$this->template_ids[] = $saved['id'];
		return $saved;
	}

	private function expected_rows( array $template ): int {
		return (int) OPEX_Query::count( $template['config'] );
	}

	private function csv_rows( string $path ): int {
		$fh   = fopen( $path, 'rb' );
		$rows = -1; // Header.
		while ( false !== fgetcsv( $fh, 0, ',', '"', '\\' ) ) {
			$rows++;
		}
		fclose( $fh );
		return $rows;
	}

	private function json_out( array $res ): array {
		$this->assertSame( 0, $res['code'], $res['err'] );
		$data = json_decode( $res['out'], true );
		$this->assertIsArray( $data, $res['out'] );
		return $data;
	}

	public function test_sources_json() {
		$rows = $this->json_out( $this->wp( array( 'opex', 'sources', '--format=json' ) ) );
		$ids  = array_column( $rows, 'id' );
		$this->assertContains( 'posts:post', $ids );
		$this->assertIsInt( $rows[0]['rows'] );
	}

	public function test_templates_json() {
		$tpl  = $this->template();
		$rows = $this->json_out( $this->wp( array( 'opex', 'templates', '--format=json' ) ) );
		$mine = array_values( array_filter( $rows, static fn( $r ) => $r['id'] === $tpl['id'] ) );
		$this->assertCount( 1, $mine );
		$this->assertSame( 'CLI test template', $mine[0]['name'] );
		$this->assertSame( 'posts:post', $mine[0]['source'] );
		$this->assertSame( 'csv', $mine[0]['format'] );
	}

	public function test_schedules_json() {
		$rec = OPEX_Schedules::save(
			array(
				'name'      => 'CLI schedule',
				'config'    => array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ) ),
				'frequency' => 'daily',
				'time'      => '03:30',
				'enabled'   => true,
			)
		);
		$this->assertIsArray( $rec );
		$this->schedule_ids[] = $rec['id'];
		$rows                 = $this->json_out( $this->wp( array( 'opex', 'schedules', '--format=json' ) ) );
		$mine                 = array_values( array_filter( $rows, static fn( $r ) => $r['id'] === $rec['id'] ) );
		$this->assertCount( 1, $mine );
		$this->assertSame( 'daily', $mine[0]['frequency'] );
		$this->assertSame( 'yes', $mine[0]['enabled'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $mine[0]['next_run'] );
	}

	public function test_run_writes_output_and_jobs_lists_it() {
		$tpl = $this->template();
		$out = $this->tmp . '/posts.csv';
		$res = $this->wp( array( 'opex', 'run', $tpl['id'], '--output=' . $out ) );
		$this->assertSame( 0, $res['code'], $res['err'] );
		$this->assertFileExists( $out );
		$this->assertSame( $this->expected_rows( $tpl ), $this->csv_rows( $out ) );
		$this->assertStringContainsString( $out, $res['out'] );

		$jobs = $this->json_out( $this->wp( array( 'opex', 'jobs', '--status=done', '--format=json' ) ) );
		$mine = array_filter( $jobs, static fn( $r ) => 'CLI test template' === $r['label'] );
		$this->assertNotEmpty( $mine );
		$first = array_values( $mine )[0];
		$this->assertSame( 'done', $first['status'] );
		$this->assertSame( 'csv', $first['format'] );
		$this->assertSame( $this->expected_rows( $tpl ), $first['rows'] );
	}

	public function test_run_a_schedule_with_delivery_name_and_watermark() {
		$rec = OPEX_Schedules::save(
			array(
				'name'        => 'CLI run schedule',
				'config'      => array( 'source' => 'posts:post', 'fields' => array( 'core:ID', 'core:post_title' ), 'batch_size' => 10 ),
				'frequency'   => 'daily',
				'time'        => '03:30',
				'enabled'     => true,
				'incremental' => array( 'enabled' => true, 'field' => 'core:post_date_gmt' ),
				'filename'    => 'cli-{label}',
			)
		);
		$this->assertIsArray( $rec );
		$this->schedule_ids[] = $rec['id'];
		$out = $this->tmp . '/schedule.csv';
		$res = $this->wp( array( 'opex', 'run', $rec['id'], '--output=' . $out ) );
		$this->assertSame( 0, $res['code'], $res['err'] );
		$this->assertSame( (int) OPEX_Query::count( $rec['config'] ), $this->csv_rows( $out ) );

		$this->bust();
		$after = OPEX_Schedules::get( $rec['id'] );
		$this->assertSame( 'done', $after['last_run']['status'] );
		$job = OPEX_Jobs::get( $after['last_run']['job_id'] );
		$this->assertSame( 'cli', $job['origin'] );
		$this->assertSame( $rec['id'], $job['schedule_id'] );
		$this->assertSame( 'background', $job['mode'] );
		$this->assertSame( 'cli-CLI-run-schedule', $job['delivery_name'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $job['created'] ), $after['watermark'] );
		$this->assertGreaterThan( time(), $after['next_run'], 'the next run stays set' );

		// A schedule keeps its own settings.
		$res = $this->wp( array( 'opex', 'run', $rec['id'], '--since=2020-01-01' ) );
		$this->assertSame( 1, $res['code'] );
		$this->assertStringContainsString( 'templates only', $res['err'] );
	}

	public function test_run_with_since_exports_only_newer_rows() {
		global $wpdb;
		$tpl = $this->template();
		$cut = (string) $wpdb->get_var( "SELECT post_date FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status <> 'auto-draft' ORDER BY post_date ASC LIMIT 1 OFFSET 20" );
		$this->assertNotSame( '', $cut );
		$expect = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status <> 'auto-draft' AND post_date > %s", $cut ) );
		$this->assertGreaterThan( 0, $expect );
		$this->assertLessThan( $this->expected_rows( $tpl ), $expect );

		$out = $this->tmp . '/since.csv';
		$res = $this->wp( array( 'opex', 'run', $tpl['id'], '--since=' . $cut, '--output=' . $out ) );
		$this->assertSame( 0, $res['code'], $res['err'] );
		$this->assertSame( $expect, $this->csv_rows( $out ) );

		$none = $this->tmp . '/none.csv';
		$res  = $this->wp( array( 'opex', 'run', $tpl['id'], '--since=2999-01-01', '--output=' . $none ) );
		$this->assertSame( 0, $res['code'], $res['err'] );
		$this->assertSame( 0, $this->csv_rows( $none ) );

		$res = $this->wp( array( 'opex', 'run', $tpl['id'], '--since=yesterday' ) );
		$this->assertSame( 1, $res['code'] );
		$this->assertStringContainsString( 'YYYY-MM-DD', $res['err'] );
	}

	public function test_run_by_name_and_refuses_overwrite_without_force() {
		$tpl = $this->template();
		$out = $this->tmp . '/keep.csv';
		file_put_contents( $out, 'original' );
		$res = $this->wp( array( 'opex', 'run', 'CLI test template', '--output=' . $out ) );
		$this->assertSame( 1, $res['code'] );
		$this->assertStringContainsString( 'already exists', $res['err'] );
		$this->assertSame( 'original', file_get_contents( $out ) );

		$res = $this->wp( array( 'opex', 'run', 'CLI test template', '--output=' . $out, '--force' ) );
		$this->assertSame( 0, $res['code'], $res['err'] );
		$this->assertSame( $this->expected_rows( $tpl ), $this->csv_rows( $out ) );
	}

	public function test_unknown_name_fails() {
		$res = $this->wp( array( 'opex', 'run', 'No such thing' ) );
		$this->assertSame( 1, $res['code'] );
		$this->assertStringContainsString( 'No template or schedule', $res['err'] );
		$this->assertSame( '', trim( $res['out'] ) );
	}

	public function test_ambiguous_name_lists_ids() {
		$a = $this->template( 'Twin' );
		// Saving the same name now updates, so an older duplicate is added to the option directly.
		$b          = $a;
		$b['id']    = 't' . substr( md5( 'twin' ), 0, 12 );
		$templates  = OPEX_Templates::all();
		$templates[ $b['id'] ] = $b;
		update_option( 'opex_templates', $templates, false );
		$this->template_ids[] = $b['id'];
		$res = $this->wp( array( 'opex', 'run', 'Twin' ) );
		$this->assertSame( 1, $res['code'] );
		$this->assertStringContainsString( $a['id'], $res['err'] );
		$this->assertStringContainsString( $b['id'], $res['err'] );
	}

	public function test_bad_output_paths_fail_before_running() {
		$this->template();
		$res = $this->wp( array( 'opex', 'run', 'CLI test template', '--output=' . $this->tmp . '/missing/x.csv' ) );
		$this->assertSame( 1, $res['code'] );
		$this->assertStringContainsString( 'does not exist', $res['err'] );

		$res = $this->wp( array( 'opex', 'run', 'CLI test template', '--output=' . dirname( __DIR__, 2 ) . '/includes/x.csv' ) );
		$this->assertSame( 1, $res['code'] );
		$this->assertStringContainsString( 'plugin folder', $res['err'] );
		$this->assertFileDoesNotExist( dirname( __DIR__, 2 ) . '/includes/x.csv' );
	}

	public function test_format_override_xlsx_and_bad_format() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is missing.' );
		}
		$probe = OPEX_Exporter::sanitize_config( array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ), 'format' => 'xlsx' ) );
		if ( is_wp_error( $probe ) || 'xlsx' !== $probe['format'] ) {
			// The engine's sanitize_config accepts xlsx in a later wave. The CLI must then refuse, not fall back to csv.
			$this->template();
			$res = $this->wp( array( 'opex', 'run', 'CLI test template', '--format=xlsx' ) );
			$this->assertSame( 1, $res['code'] );
			$this->assertStringContainsString( 'does not accept', $res['err'] );
			$this->markTestSkipped( 'sanitize_config does not accept xlsx yet, so only the refusal was checked.' );
		}
		$this->template();
		$out = $this->tmp . '/posts.xlsx';
		$res = $this->wp( array( 'opex', 'run', 'CLI test template', '--format=xlsx', '--output=' . $out ) );
		$this->assertSame( 0, $res['code'], $res['err'] );
		$this->assertSame( 'PK', substr( (string) file_get_contents( $out ), 0, 2 ) );

		$res = $this->wp( array( 'opex', 'run', 'CLI test template', '--format=pdf' ) );
		$this->assertSame( 1, $res['code'] );
		$this->assertStringContainsString( 'Unknown format', $res['err'] );
	}

	public function test_export_from_config_file() {
		$cfg = $this->tmp . '/config.json';
		file_put_contents( $cfg, wp_json_encode( array( 'source' => 'posts:post', 'fields' => array( 'core:ID', 'core:post_title' ), 'format' => 'csv' ) ) );
		$out = $this->tmp . '/one-off.csv';
		$res = $this->wp( array( 'opex', 'export', '--config=' . $cfg, '--output=' . $out ) );
		$this->assertSame( 0, $res['code'], $res['err'] );
		$count = (int) OPEX_Query::count( OPEX_Exporter::sanitize_config( array( 'source' => 'posts:post', 'fields' => array( 'core:ID' ) ) ) );
		$this->assertSame( $count, $this->csv_rows( $out ) );
	}

	/** --output gets the export under the name given, while the copy in the export folder ends in .opex. */
	public function test_output_gets_the_export_for_html_and_zip() {
		$cases = array(
			'posts.html'    => array( 'html', 'none', '.html.opex' ),
			'posts.csv.zip' => array( 'csv', 'zip', '.csv.zip.opex' ),
		);
		$made  = array();
		foreach ( $cases as $file => $case ) {
			if ( 'zip' === $case[1] && ! OPEX_Compress::available() ) {
				continue;
			}
			$cfg = $this->tmp . '/config.json';
			file_put_contents( $cfg, wp_json_encode( array( 'source' => 'posts:post', 'fields' => array( 'core:ID', 'core:post_title' ), 'format' => $case[0], 'compress' => $case[1] ) ) );
			$out = $this->tmp . '/' . $file;
			$this->bust();
			$known = array_keys( OPEX_Jobs::all() );
			$res   = $this->wp( array( 'opex', 'export', '--config=' . $cfg, '--output=' . $out ) );
			$this->assertSame( 0, $res['code'], $res['err'] );
			$this->bust();
			// The one export this run made. Two runs can start in the same second, so no time order.
			$new = array_values( array_filter( OPEX_Jobs::all(), static fn( $j ) => ! in_array( $j['id'], $known, true ) ) );
			$this->assertCount( 1, $new );
			$job = $new[0];
			$this->assertSame( 'done', $job['status'] );
			$this->assertStringEndsWith( $case[2], $job['filename'] );
			$this->assertSame( file_get_contents( OPEX_Jobs::file_path( $job ) ), file_get_contents( $out ), $file );
			$this->assertStringStartsWith( 'zip' === $case[1] ? 'PK' : '<!DOCTYPE html>', (string) file_get_contents( $out ) );
			unlink( $cfg );
			$made[] = $file;
		}
		sort( $made );
		$this->assertSame( $made, array_values( array_diff( scandir( $this->tmp ), array( '.', '..' ) ) ), 'Nothing else is left next to the output.' );
	}

	public function test_export_recipe_from_stdin() {
		$recipe = wp_json_encode( array( 'opex_recipe' => 1, 'name' => 'Recipe test', 'source' => 'posts:post', 'fields' => array( 'core:ID', 'core:post_title' ) ) );
		$out    = $this->tmp . '/recipe.csv';
		$res    = $this->wp( array( 'opex', 'export', '--config=-', '--output=' . $out ), (string) $recipe );
		$this->assertSame( 0, $res['code'], $res['err'] );
		$this->assertGreaterThan( 0, $this->csv_rows( $out ) );

		$res = $this->wp( array( 'opex', 'export', '--config=-' ), 'not json' );
		$this->assertSame( 1, $res['code'] );
		$this->assertStringContainsString( 'not valid JSON', $res['err'] );
	}

	public function test_schema_is_json() {
		$doc = $this->json_out( $this->wp( array( 'opex', 'schema' ) ) );
		$this->assertArrayHasKey( 'opex_schema', $doc );
		$this->assertNotEmpty( $doc['all_sources'] );
	}

	public function test_porcelain_prints_one_line() {
		$this->template();
		$res   = $this->wp( array( 'opex', 'run', 'CLI test template', '--porcelain' ) );
		$this->assertSame( 0, $res['code'], $res['err'] );
		$lines = array_values( array_filter( explode( "\n", $res['out'] ), static fn( $l ) => '' !== $l ) );
		$this->assertCount( 1, $lines );
		$this->assertFileExists( $lines[0] );
	}

	public function test_clean_reports() {
		$res = $this->wp( array( 'opex', 'clean' ) );
		$this->assertSame( 0, $res['code'], $res['err'] );
		$this->assertStringContainsString( 'Removed', $res['out'] );
	}
}
