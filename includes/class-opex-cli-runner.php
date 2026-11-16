<?php
/**
 * Helpers for the WP-CLI commands: find the target, check options, work a job
 * to the end and copy the file. Kept apart so OPEX_CLI stays a thin command list.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Worker side of the `wp opex run` and `wp opex export` commands.
 */
class OPEX_CLI_Runner {

	/** Stop with an error when a plugin call returned WP_Error. */
	public static function fail_on_error( $value ): void {
		if ( is_wp_error( $value ) ) {
			WP_CLI::error( $value->get_error_message() );
		}
	}

	/** Check an export format against what this server can write. */
	public static function check_format( string $format ): string {
		$formats = OPEX_Writer::formats();
		if ( ! isset( $formats[ $format ] ) ) {
			WP_CLI::error( sprintf( 'Unknown format "%s". Use one of: %s.', $format, implode( ', ', array_keys( $formats ) ) ) );
		}
		if ( empty( $formats[ $format ]['available'] ) ) {
			WP_CLI::error( sprintf( 'The %s format is not available on this server.', $format ) );
		}
		return $format;
	}

	/**
	 * Find a template or schedule by exact id, then by exact name.
	 *
	 * @return array { kind: template|schedule, id, name, config? }
	 */
	public static function find_target( string $key ): array {
		if ( '' === $key ) {
			WP_CLI::error( 'Pass the id or exact name of a template or schedule.' );
		}
		$tpl = OPEX_Templates::get( $key );
		if ( $tpl ) {
			return array(
				'kind'   => 'template',
				'id'     => $tpl['id'],
				'name'   => $tpl['name'],
				'config' => $tpl['config'],
			);
		}
		$sched = OPEX_Schedules::get( $key );
		if ( $sched ) {
			return array(
				'kind' => 'schedule',
				'id'   => $sched['id'],
				'name' => $sched['name'],
			);
		}
		$found = array();
		foreach ( OPEX_Templates::all() as $t ) {
			if ( (string) $t['name'] === $key ) {
				$found[] = array(
					'kind'   => 'template',
					'id'     => $t['id'],
					'name'   => $t['name'],
					'config' => $t['config'],
				);
			}
		}
		foreach ( OPEX_Schedules::all() as $s ) {
			if ( (string) $s['name'] === $key ) {
				$found[] = array(
					'kind' => 'schedule',
					'id'   => $s['id'],
					'name' => $s['name'],
				);
			}
		}
		if ( 1 === count( $found ) ) {
			return $found[0];
		}
		if ( count( $found ) > 1 ) {
			$ids = implode( ', ', wp_list_pluck( $found, 'id' ) );
			WP_CLI::error( sprintf( 'More than one template or schedule is named "%1$s". Use an id: %2$s', $key, $ids ) );
		}
		WP_CLI::error( sprintf( 'No template or schedule has the id or name "%s". Try `wp opex templates` or `wp opex schedules`.', $key ) );
	}

	/** Build the job's since value from --since (site time) on the first date field. */
	public static function since( string $text, array $config ): array {
		$tz   = wp_timezone();
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $text ) ? $text . ' 00:00:00' : $text, $tz );
		if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== ( 10 === strlen( $text ) ? $text . ' 00:00:00' : $text ) ) {
			WP_CLI::error( 'Use --since="YYYY-MM-DD" or --since="YYYY-MM-DD HH:MM:SS" in site time.' );
		}
		$src   = OPEX_Query::parse_source( (string) $config['source'] );
		$dates = $src ? array_keys( OPEX_Query::date_fields( $src ) ) : array();
		$field = '';
		foreach ( (array) $config['fields'] as $id ) {
			if ( in_array( $id, $dates, true ) ) {
				$field = (string) $id;
				break;
			}
		}
		$field = '' !== $field ? $field : (string) ( $dates[0] ?? '' );
		if ( '' === $field ) {
			WP_CLI::error( 'This source has no date field, so --since cannot be used.' );
		}
		return array(
			'field' => $field,
			'after' => $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
		);
	}

	/** Check --output before any work starts. Returns the full path or ''. */
	public static function check_output( array $assoc_args ): string {
		$out = (string) ( $assoc_args['output'] ?? '' );
		if ( '' === $out ) {
			return '';
		}
		$folder = realpath( dirname( $out ) );
		if ( false === $folder || ! is_dir( $folder ) ) {
			WP_CLI::error( 'The folder for --output does not exist.' );
		}
		if ( ! is_writable( $folder ) ) {
			WP_CLI::error( 'The folder for --output is not writable.' );
		}
		$full   = trailingslashit( $folder ) . basename( $out );
		$plugin = realpath( dirname( __DIR__ ) );
		if ( false !== $plugin && 0 === strpos( trailingslashit( $folder ), trailingslashit( $plugin ) ) ) {
			WP_CLI::error( 'Do not write --output inside the plugin folder.' );
		}
		if ( is_dir( $full ) ) {
			WP_CLI::error( 'The --output path is a folder.' );
		}
		if ( file_exists( $full ) && empty( $assoc_args['force'] ) ) {
			WP_CLI::error( 'The --output file already exists. Add --force to overwrite it.' );
		}
		return $full;
	}

	/** Work a started job to the end, deliver, copy the file and report. */
	public static function finish_job( array $job, string $dest, array $assoc_args ): void {
		$porcelain = ! empty( $assoc_args['porcelain'] );
		$id        = (string) $job['id'];
		$bar       = ( $porcelain || 'running' !== $job['status'] ) ? null : \WP_CLI\Utils\make_progress_bar( 'Exporting rows', max( 1, (int) $job['total'] ) );
		$last      = 0;
		$idle      = 0;

		while ( 'running' === $job['status'] ) {
			$job = OPEX_Runner::work( $id, 5 );
			self::fail_on_error( $job );
			$moved = (int) $job['processed'] - $last;
			if ( $moved > 0 && $bar ) {
				$bar->tick( $moved );
			}
			$last = (int) $job['processed'];
			$idle = $moved > 0 ? 0 : $idle + 1;
			if ( 'running' === $job['status'] && $idle > 2 ) {
				sleep( 1 ); // Another worker holds the lock. Wait instead of spinning.
			}
		}
		if ( $bar ) {
			$bar->finish();
		}
		if ( 'done' !== $job['status'] ) {
			WP_CLI::error( '' !== (string) $job['error'] ? (string) $job['error'] : sprintf( 'The export ended with status "%s".', (string) $job['status'] ) );
		}

		OPEX_Runner::deliver( $id );
		$record = OPEX_Jobs::get( $id );
		$path   = $record ? OPEX_Jobs::file_path( $record ) : '';
		if ( '' === $path || ! is_file( $path ) ) {
			WP_CLI::error( 'The export finished but its file is missing.' );
		}
		if ( '' !== $dest ) {
			$part = $dest . '.opex-part';
			if ( ! copy( $path, $part ) || ! rename( $part, $dest ) ) {
				wp_delete_file( $part );
				WP_CLI::error( 'Could not write the --output file.' );
			}
			$path = $dest;
		}
		foreach ( (array) ( $record['deliveries'] ?? array() ) as $item ) {
			if ( 'failed' === ( $item['status'] ?? '' ) ) {
				WP_CLI::warning( sprintf( 'Delivery by %1$s failed: %2$s', (string) $item['channel'], (string) ( $item['message'] ?? '' ) ) );
			}
		}
		if ( $porcelain ) {
			WP_CLI::line( $path );
			return;
		}
		WP_CLI::success( sprintf( 'Exported %1$d row(s) to %2$s', (int) $job['processed'], $path ) );
	}
}
