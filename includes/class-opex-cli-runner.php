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

	/**
	 * The function publish() makes a hard link with. Only tests change it,
	 * through reflection, to act like a filesystem without hard links.
	 *
	 * @var callable
	 */
	private static $link = 'link';

	/**
	 * Stop with an error when a plugin call returned WP_Error.
	 *
	 * @param mixed $value Result of a plugin call, checked for WP_Error.
	 */
	public static function fail_on_error( $value ): void {
		if ( is_wp_error( $value ) ) {
			WP_CLI::error( $value->get_error_message() );
		}
	}

	/**
	 * Stop with exit code 1 after something a command called threw. A
	 * message written for people (such as the busy error of the store) is
	 * printed as it is. Anything else prints a plain message, and the detail
	 * goes to the debug log, as it does for the screen.
	 *
	 * @param Throwable $e What was thrown.
	 * @return void
	 */
	public static function fail_on_exception( Throwable $e ): void {
		OPEX_Exporter::log_exception( $e );
		WP_CLI::error( $e instanceof OPEX_User_Error ? $e->getMessage() : 'Open Export could not finish. Turn on WP_DEBUG_LOG for details.' );
	}

	/**
	 * Check an export format against what this server can write.
	 *
	 * @param string $format Format id given on the command line.
	 * @return string The format id, once it is known and available.
	 */
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
	 * @param string $key Id or exact name given on the command line.
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

	/**
	 * Build the job's since value from --since (site time) on the first date field.
	 *
	 * @param string $text   Value of --since, in site time.
	 * @param array  $config Template config, used to find the first date field.
	 * @return array The since value for the job: the date field and the UTC time rows must be after.
	 */
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

	/**
	 * Check --output before any work starts. Returns the full path or ''.
	 *
	 * @param array $assoc_args Named command line arguments, read for --output and --force.
	 * @return string Full output path, or '' when no --output was given.
	 */
	public static function check_output( array $assoc_args ): string {
		$out = (string) ( $assoc_args['output'] ?? '' );
		if ( '' === $out ) {
			return '';
		}
		$folder = realpath( dirname( $out ) );
		if ( false === $folder || ! is_dir( $folder ) ) {
			WP_CLI::error( 'The folder for --output does not exist.' );
		}
		if ( ! wp_is_writable( $folder ) ) {
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

	/**
	 * Work a started job to the end, deliver, copy the file and report. A
	 * failed delivery prints the file path and each delivery error, then
	 * exits with code 1.
	 *
	 * @param array  $job        Job that has started.
	 * @param string $dest       Path to copy the finished file to, or '' for none.
	 * @param array  $assoc_args Named command line arguments, read for --porcelain and --force.
	 * @return void
	 */
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

		OPEX_Runner::deliver_pending( $id );
		$record = OPEX_Jobs::get( $id );
		$path   = $record ? OPEX_Jobs::file_path( $record ) : '';
		if ( '' === $path || ! is_file( $path ) ) {
			WP_CLI::error( 'The export finished but its file is missing.' );
		}
		if ( '' !== $dest ) {
			$published = self::publish( $path, $dest, ! empty( $assoc_args['force'] ) );
			if ( true !== $published ) {
				WP_CLI::error( $published );
			}
			$path = $dest;
		}
		$failed = 0;
		foreach ( (array) ( $record['deliveries'] ?? array() ) as $item ) {
			if ( 'failed' === ( $item['status'] ?? '' ) ) {
				++$failed;
				WP_CLI::warning( sprintf( 'Delivery by %1$s failed: %2$s', (string) $item['channel'], (string) ( $item['message'] ?? '' ) ) );
			}
		}
		if ( $porcelain ) {
			WP_CLI::line( $path );
		} elseif ( 0 === $failed ) {
			WP_CLI::success( sprintf( 'Exported %1$d row(s) to %2$s', (int) $job['processed'], $path ) );
		} else {
			WP_CLI::log( sprintf( 'Exported %1$d row(s) to %2$s', (int) $job['processed'], $path ) );
		}
		if ( $failed > 0 ) {
			// A shell script must see a failed delivery, so the command fails after reporting the file.
			WP_CLI::error( sprintf( '%d delivery(ies) failed. The export file was written, and failed deliveries are tried again later.', $failed ) );
		}
	}

	/**
	 * Copy the finished file to --output without ever leaving it half written.
	 *
	 * The copy goes to a temporary file of its own in the target folder,
	 * made with an exclusive create under a random name, so two runs never
	 * share one. With --force it is renamed over the target. Without it the
	 * copy is published with link(), which fails when a file already has the
	 * target name, so a file that appeared while the export ran is never
	 * replaced. The final path is never written in place, so no reader sees
	 * a half-written file.
	 *
	 * Where the file system has no hard links, publish() checks that the
	 * target still does not exist and then renames the finished copy into
	 * place. That fallback has a short window between the check and the
	 * rename in which another process could create the target, which the
	 * rename would then replace. File systems with hard links do not have
	 * that window. The temporary file is removed on every path.
	 *
	 * @param string $source Finished export file.
	 * @param string $dest   Full --output path.
	 * @param bool   $force  True when --force was given.
	 * @return true|string True, or the error to print.
	 */
	public static function publish( string $source, string $dest, bool $force ) {
		$temp = self::temp_copy( $source, dirname( $dest ) . '/.' . basename( $dest ) );
		if ( '' === $temp ) {
			return 'Could not write the --output file.';
		}
		try {
			// WP_Filesystem is not used: it may ask for FTP credentials, which a shell run cannot answer.
			if ( $force ) {
				return rename( $temp, $dest ) ? true : 'Could not write the --output file.'; // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- see above.
			}
			if ( @call_user_func( self::$link, $temp, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a refusal is the expected answer when the file exists.
				return true;
			}
			if ( file_exists( $dest ) || is_link( $dest ) ) {
				return 'The --output file appeared while the export ran. Add --force to overwrite it.';
			}
			// No hard links here. The copy is finished and sits in the same folder, so the rename is atomic.
			return rename( $temp, $dest ) ? true : 'Could not write the --output file.'; // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- see above.
		} finally {
			if ( file_exists( $temp ) ) {
				wp_delete_file( $temp );
			}
		}
	}

	/**
	 * Copy a file to a new, unique file whose name starts with a prefix.
	 *
	 * @param string $source File to copy.
	 * @param string $prefix Path and start of the name for the copy.
	 * @return string Path of the copy, or '' when it could not be made.
	 */
	private static function temp_copy( string $source, string $prefix ): string {
		for ( $try = 0; $try < 5; $try++ ) {
			$temp = $prefix . '.' . bin2hex( random_bytes( 6 ) ) . '.opex-part';
			if ( self::exclusive_copy( $source, $temp ) ) {
				return $temp;
			}
			if ( ! file_exists( $temp ) ) {
				return ''; // Not a name clash: the folder refused the file.
			}
		}
		return '';
	}

	/**
	 * Copy a file to a path that must not exist yet. The target is created
	 * with an exclusive create, so an existing file is never touched, and a
	 * partial copy is removed.
	 *
	 * @param string $source File to copy.
	 * @param string $target New file.
	 * @return bool True when every byte was copied.
	 */
	private static function exclusive_copy( string $source, string $target ): bool {
		// phpcs:disable WordPress.WP.AlternativeFunctions -- a local file the shell user named, see publish().
		$out = @fopen( $target, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an existing file is an expected answer.
		if ( false === $out ) {
			return false;
		}
		$in     = fopen( $source, 'rb' );
		$copied = false === $in ? false : stream_copy_to_stream( $in, $out );
		$ok     = false !== $copied && (int) filesize( $source ) === (int) $copied;
		if ( false !== $in ) {
			fclose( $in );
		}
		$ok = fclose( $out ) && $ok;
		// phpcs:enable
		if ( ! $ok ) {
			wp_delete_file( $target );
		}
		return $ok;
	}
}
