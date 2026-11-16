<?php
/**
 * WP-CLI commands: list sources, templates, schedules and jobs, run exports
 * from a terminal or a server cron job, print the schema and sweep old jobs.
 * This class only parses arguments, calls the plugin's classes and prints.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-opex-cli-runner.php';

/**
 * Runs Open Export from the command line.
 */
class OPEX_CLI {

	/** Add the `wp opex` command. Does nothing when WP-CLI is not running. */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		WP_CLI::add_command( 'opex', 'OPEX_CLI' );
	}

	/**
	 * Lists the data sources that can be exported, with row counts.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : How to print the list. One of: table, json, csv, ids, count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp opex sources
	 *     wp opex sources --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function sources( $args, $assoc_args ): void {
		$items = array();
		foreach ( OPEX_Catalog::sources() as $group ) {
			foreach ( (array) $group['items'] as $item ) {
				$items[] = array(
					'id'    => (string) $item['id'],
					'label' => (string) $item['label'],
					'rows'  => (int) $item['count'],
				);
			}
		}
		$this->print_items( $items, array( 'id', 'label', 'rows' ), $assoc_args );
	}

	/**
	 * Lists saved export templates.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : How to print the list. One of: table, json, csv, ids, count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp opex templates
	 *     wp opex templates --format=ids
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function templates( $args, $assoc_args ): void {
		$items = array();
		foreach ( OPEX_Templates::all() as $tpl ) {
			$items[] = array(
				'id'     => (string) $tpl['id'],
				'name'   => (string) $tpl['name'],
				'source' => (string) ( $tpl['config']['source'] ?? '' ),
				'format' => (string) ( $tpl['config']['format'] ?? 'csv' ),
			);
		}
		$this->print_items( $items, array( 'id', 'name', 'source', 'format' ), $assoc_args );
	}

	/**
	 * Lists schedules, with the next run in site time and the last run status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : How to print the list. One of: table, json, csv, ids, count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp opex schedules
	 *     wp opex schedules --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function schedules( $args, $assoc_args ): void {
		$items = array();
		foreach ( OPEX_Schedules::all() as $s ) {
			$next    = (int) $s['next_run'];
			$items[] = array(
				'id'              => (string) $s['id'],
				'name'            => (string) $s['name'],
				'frequency'       => (string) $s['frequency'],
				'enabled'         => $s['enabled'] ? 'yes' : 'no',
				'next_run'        => $next > 0 ? wp_date( 'Y-m-d H:i:s', $next ) : '',
				'last_run_status' => (string) $s['last_run']['status'],
			);
		}
		$this->print_items( $items, array( 'id', 'name', 'frequency', 'enabled', 'next_run', 'last_run_status' ), $assoc_args );
	}

	/**
	 * Lists export jobs, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Only show jobs with this status, for example running, done, error or paused.
	 *
	 * [--format=<format>]
	 * : How to print the list. One of: table, json, csv, ids, count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp opex jobs
	 *     wp opex jobs --status=error --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function jobs( $args, $assoc_args ): void {
		$status = isset( $assoc_args['status'] ) ? (string) $assoc_args['status'] : '';
		$jobs   = OPEX_Jobs::all();
		uasort(
			$jobs,
			static function ( $a, $b ) {
				return (int) $b['created'] <=> (int) $a['created'];
			}
		);
		$items = array();
		foreach ( $jobs as $job ) {
			if ( '' !== $status && $status !== (string) $job['status'] ) {
				continue;
			}
			$items[] = array(
				'id'      => (string) $job['id'],
				'label'   => (string) $job['label'],
				'status'  => (string) $job['status'],
				'rows'    => (int) $job['processed'],
				'format'  => (string) ( $job['config']['format'] ?? 'csv' ),
				'created' => wp_date( 'Y-m-d H:i:s', (int) $job['created'] ),
			);
		}
		$this->print_items( $items, array( 'id', 'label', 'status', 'rows', 'format', 'created' ), $assoc_args );
	}

	/**
	 * Runs a saved template or a schedule to completion in this process.
	 *
	 * Finds the target by exact id first, then by exact name. A name that
	 * matches more than one template or schedule is an error that lists the ids.
	 * A schedule starts as if triggered from the command line, so its delivery
	 * and incremental settings apply. Exit code 0 on success, 1 on any failure.
	 *
	 * ## OPTIONS
	 *
	 * <name-or-id>
	 * : The id or exact name of a template or schedule.
	 *
	 * [--format=<format>]
	 * : Export file format for a template: csv, json, xml, xlsx or html.
	 * Overrides the template's own format. Not allowed for a schedule.
	 *
	 * [--output=<file>]
	 * : Copy the finished file to this path. The folder must exist and be
	 * writable, and the path cannot be inside the plugin folder.
	 *
	 * [--force]
	 * : Overwrite the --output file if it already exists.
	 *
	 * [--since=<datetime>]
	 * : Only export rows newer than this site-time value, as YYYY-MM-DD or
	 * YYYY-MM-DD HH:MM:SS. It is passed to the job as its "since" on the
	 * first date field of the template's source, and the export keeps only
	 * rows whose date is after it. Not allowed for a schedule, which keeps its own watermark.
	 *
	 * [--network]
	 * : On a multisite network, export the template from every public site
	 * into one file, with site_id and site_url columns first. A template saved
	 * as a network export already runs that way. Not allowed for a schedule.
	 *
	 * [--porcelain]
	 * : Print only the final file path.
	 *
	 * ## EXAMPLES
	 *
	 *     wp opex run "Weekly orders"
	 *     wp opex run t1a2b3c4d5e6f --format=xlsx --output=/tmp/orders.xlsx --force
	 *     wp opex run "Nightly posts" --porcelain
	 *     wp opex run "Weekly orders" --since="2026-11-01 00:00:00"
	 *     wp opex run "All posts" --network
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function run( $args, $assoc_args ): void {
		$porcelain = ! empty( $assoc_args['porcelain'] );
		$target    = OPEX_CLI_Runner::find_target( (string) ( $args[0] ?? '' ) );
		$dest      = OPEX_CLI_Runner::check_output( $assoc_args );

		if ( 'schedule' === $target['kind'] ) {
			foreach ( array( 'format', 'since', 'network' ) as $flag ) {
				if ( isset( $assoc_args[ $flag ] ) ) {
					WP_CLI::error( sprintf( 'The --%s option works with templates only. A schedule keeps its own settings.', $flag ) );
				}
			}
			$job = OPEX_Schedules::start( $target['id'], 'cli' );
			OPEX_CLI_Runner::fail_on_error( $job );
		} else {
			$config = $target['config'];
			if ( isset( $assoc_args['format'] ) ) {
				$wanted           = OPEX_CLI_Runner::check_format( (string) $assoc_args['format'] );
				$config['format'] = $wanted;
				$config           = $this->sanitized( $config );
				if ( $wanted !== $config['format'] ) {
					WP_CLI::error( sprintf( 'The export engine does not accept the %s format on this site.', $wanted ) );
				}
			}
			if ( ! empty( $assoc_args['network'] ) ) {
				$config['network'] = true;
				$config            = $this->sanitized( $config );
			}
			$extra = array( 'origin' => 'cli' );
			if ( isset( $assoc_args['since'] ) ) {
				$extra['since'] = OPEX_CLI_Runner::since( (string) $assoc_args['since'], $config );
			}
			$job = OPEX_Exporter::create( $config, $target['name'], $extra );
			OPEX_CLI_Runner::fail_on_error( $job );
		}

		OPEX_CLI_Runner::finish_job( $job, $dest, $assoc_args );
	}

	/**
	 * Runs a one-off export from a JSON file.
	 *
	 * The file holds either an export config or an assistant recipe (it has an
	 * "opex_recipe" key). Notes about a recipe print as warnings.
	 *
	 * ## OPTIONS
	 *
	 * --config=<file>
	 * : Path to the JSON file, or - to read the JSON from STDIN.
	 *
	 * [--output=<file>]
	 * : Copy the finished file to this path. The folder must exist and be
	 * writable, and the path cannot be inside the plugin folder.
	 *
	 * [--force]
	 * : Overwrite the --output file if it already exists.
	 *
	 * [--porcelain]
	 * : Print only the final file path.
	 *
	 * ## EXAMPLES
	 *
	 *     wp opex export --config=/path/to/config.json --output=/tmp/posts.csv
	 *     cat recipe.json | wp opex export --config=- --porcelain
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function export( $args, $assoc_args ): void {
		$dest = OPEX_CLI_Runner::check_output( $assoc_args );
		$path = (string) ( $assoc_args['config'] ?? '' );
		if ( '' === $path ) {
			WP_CLI::error( 'Pass --config=<file>, or --config=- to read STDIN.' );
		}
		$json = '-' === $path ? stream_get_contents( STDIN ) : ( is_readable( $path ) ? file_get_contents( $path ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file the shell user named.
		if ( ! is_string( $json ) ) {
			WP_CLI::error( 'Could not read the config.' );
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			WP_CLI::error( 'The config is not valid JSON.' );
		}

		$label = 'CLI export';
		if ( isset( $data['opex_recipe'] ) ) {
			$notes  = array();
			$result = OPEX_Recipe::to_config( $data, $notes );
			OPEX_CLI_Runner::fail_on_error( $result );
			foreach ( array_merge( $notes, (array) ( $result['notes'] ?? array() ) ) as $note ) {
				WP_CLI::warning( (string) $note );
			}
			if ( empty( $result['ok'] ) ) {
				WP_CLI::error( (string) ( $result['report']['reason'] ?? 'The recipe could not be turned into an export.' ) );
			}
			$config = $result['config'];
			$label  = '' !== (string) $result['name'] ? (string) $result['name'] : $label;
		} else {
			$config = $this->sanitized( $data );
		}

		$job = OPEX_Exporter::create( $config, $label, array( 'origin' => 'cli' ) );
		OPEX_CLI_Runner::fail_on_error( $job );
		OPEX_CLI_Runner::finish_job( $job, $dest, $assoc_args );
	}

	/**
	 * Prints the capability document as JSON on STDOUT.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<id>]
	 * : Describe this source in full, for example posts:post.
	 *
	 * [--all]
	 * : Describe every source in full. The output can be large.
	 *
	 * ## EXAMPLES
	 *
	 *     wp opex schema > schema.json
	 *     wp opex schema --source=users
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function schema( $args, $assoc_args ): void {
		$doc = OPEX_Schema::build( (string) ( $assoc_args['source'] ?? '' ), ! empty( $assoc_args['all'] ) );
		WP_CLI::line( (string) wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Runs the maintenance sweep and reports what it removed.
	 *
	 * Removes expired jobs and their files, restarts stalled background jobs,
	 * re-arms schedules and sends pending deliveries.
	 *
	 * ## EXAMPLES
	 *
	 *     wp opex clean
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function clean( $args, $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI passes both; this command takes none.
		$before = array_keys( OPEX_Jobs::all() );
		OPEX_Runner::maintenance();
		$removed = count( array_diff( $before, array_keys( OPEX_Jobs::all() ) ) );
		WP_CLI::success( sprintf( 'Maintenance finished. Removed %d expired job(s).', $removed ) );
	}

	/**
	 * Print rows in the chosen display format.
	 *
	 * @param array    $items      Rows to print.
	 * @param string[] $fields     Columns to show, in order.
	 * @param array    $assoc_args Named command line arguments, read for --format.
	 */
	private function print_items( array $items, array $fields, array $assoc_args ): void {
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}

	/**
	 * Sanitize a config, stopping on error.
	 *
	 * @param array $config Raw config to clean.
	 * @return array The clean config.
	 */
	private function sanitized( array $config ): array {
		$notes = array();
		$clean = OPEX_Exporter::sanitize_config( $config, $notes );
		OPEX_CLI_Runner::fail_on_error( $clean );
		foreach ( $notes as $note ) {
			WP_CLI::warning( (string) $note );
		}
		return $clean;
	}
}
