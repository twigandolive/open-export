<?php
/**
 * Network exports on a multisite install: one config run on every site of
 * the network in turn, into one file.
 *
 * The exporter asks here which sites to read, runs each batch inside the
 * right site, and adds the two site columns. The job stays on the site that
 * started it, so its option, lock and file never move.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Plans and runs the per-site parts of a network export.
 */
class OPEX_Network {

	/** Column key of the site id. It has no prefix, so it can never clash with a field id. */
	public const SITE_ID = 'site_id';

	/** Column key of the site address. */
	public const SITE_URL = 'site_url';

	/**
	 * Whether a config asks for a network export.
	 *
	 * @param array $config Export config.
	 */
	public static function is_network( array $config ): bool {
		return ! empty( $config['network'] );
	}

	/**
	 * Whether the current caller may ask for a network export. A super admin
	 * may, and so may WP-CLI, whose operator already has the whole install.
	 */
	public static function may_request(): bool {
		return is_multisite() && ( is_super_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) );
	}

	/**
	 * Why a source cannot run across the network, or null when it can.
	 *
	 * @param array $src Parsed source.
	 */
	public static function source_refusal( array $src ): ?WP_Error {
		$type = (string) ( $src['type'] ?? '' );
		if ( 'users' === $type ) {
			return self::refused( __( 'A network export cannot use Users: every site shares the same users, so each user would repeat once per site. Export users from one site instead.', 'open-export' ) );
		}
		if ( 'table' === $type ) {
			return self::refused( __( 'A network export cannot use a raw database table: each site has its own tables. Pick a content, comment, taxonomy or WooCommerce source instead.', 'open-export' ) );
		}
		return null;
	}

	/**
	 * The check a new network job must pass. A person must be allowed to ask
	 * for one. A schedule that runs from cron or a trigger URL was checked
	 * when a super admin saved it, so it only needs the network to exist.
	 *
	 * @param array  $config Sanitized config.
	 * @param string $origin Who starts the job: manual, cron, trigger or cli.
	 */
	public static function check_create( array $config, string $origin ): ?WP_Error {
		if ( ! is_multisite() || ( 'manual' === $origin && ! self::may_request() ) ) {
			return self::refused( __( 'Only a network super admin can export from every site, and only on a multisite network.', 'open-export' ) );
		}
		$src = OPEX_Query::parse_source( (string) ( $config['source'] ?? '' ) );
		return $src ? self::source_refusal( $src ) : null;
	}

	/**
	 * Ids of the sites a network export reads: public, not archived and not
	 * deleted, on the current network, in id order.
	 *
	 * @return int[]
	 */
	public static function site_ids(): array {
		$ids = get_sites(
			array(
				'fields'     => 'ids',
				'number'     => 0,
				'network_id' => get_current_network_id(),
				'public'     => 1,
				'archived'   => 0,
				'deleted'    => 0,
				'orderby'    => 'id',
				'order'      => 'ASC',
			)
		);
		return array_values( array_map( 'intval', (array) $ids ) );
	}

	/**
	 * The two site columns, placed before every field.
	 *
	 * @return array<string, string> Column key => header.
	 */
	public static function columns(): array {
		return array(
			self::SITE_ID  => __( 'Site ID', 'open-export' ),
			self::SITE_URL => __( 'Site URL', 'open-export' ),
		);
	}

	/**
	 * Headers with the site columns first. A field label that matches a site
	 * header gets a number, so JSON keys and XML elements stay unique.
	 *
	 * @param array $headers Field id => label.
	 * @return array Column key => label.
	 */
	public static function with_columns( array $headers ): array {
		$out  = self::columns();
		$used = array_flip( array_values( $out ) );
		foreach ( $headers as $field => $label ) {
			$base = (string) $label;
			$n    = 2;
			while ( isset( $used[ $label ] ) ) {
				$label = $base . '_' . $n;
				++$n;
			}
			$used[ $label ] = true;
			$out[ $field ]  = $label;
		}
		return $out;
	}

	/**
	 * Count every site at job creation. A site without the source counts zero
	 * and gets a note.
	 *
	 * @param array $query Config the query layer reads.
	 * @return array{sites: int[], total: int, notes: string[]}
	 */
	public static function plan( array $query ): array {
		$sites = self::site_ids();
		$total = 0;
		$notes = array();
		foreach ( $sites as $site_id ) {
			$total += (int) self::in_site(
				$site_id,
				static function () use ( $query, $site_id, &$notes ): int {
					if ( ! self::source_here( (string) $query['source'] ) ) {
						$notes[] = self::missing_note( $site_id );
						return 0;
					}
					return OPEX_Query::count( $query );
				}
			);
		}
		return array(
			'sites' => $sites,
			'total' => $total,
			'notes' => $notes,
		);
	}

	/**
	 * Read the next batch of a network job from the site it is working on.
	 * The rows come back transformed and with the site columns first.
	 *
	 * @param array    $job    Job record.
	 * @param array    $query  Config the query layer reads.
	 * @param callable $guard  Refreshes the job lock. It runs on the job's own site.
	 * @return array{items: array, cursor: int, done: bool, next_site: bool, note: string}
	 * @throws RuntimeException When the network is gone or the database reports an error.
	 */
	public static function batch( array $job, array $query, callable $guard ): array {
		$sites = array_values( array_map( 'intval', (array) $job['sites'] ) );
		$index = (int) $job['site_index'];
		$out   = array(
			'items'     => array(),
			'cursor'    => (int) $job['cursor'],
			'done'      => true,
			'next_site' => false,
			'note'      => '',
		);
		if ( ! isset( $sites[ $index ] ) ) {
			return $out;
		}
		if ( ! is_multisite() ) {
			throw new RuntimeException( 'A network export needs a multisite network.' );
		}
		$site_id             = $sites[ $index ];
		$home                = get_current_blog_id();
		$tick                = static function () use ( $guard, $home ): void {
			self::at_home( $home, $guard );
		};
		$config              = (array) $job['config'];
		$result              = self::in_site(
			$site_id,
			static function () use ( $query, $config, $job, $site_id, $tick, $out ): array {
				if ( ! self::source_here( (string) $query['source'] ) ) {
					$out['note'] = self::missing_note( $site_id );
					return $out;
				}
				$batch          = OPEX_Query::batch( $query, (int) $job['cursor'], (int) $config['batch_size'], $tick );
				$batch['items'] = self::with_site_columns( OPEX_Acf_Spread::output( $batch['items'], $config, (array) $job['headers'] ), $site_id );
				return array_merge( $out, $batch );
			}
		);
		$result['next_site'] = $result['done'] && isset( $sites[ $index + 1 ] );
		return $result;
	}

	/**
	 * Rows with the site columns placed first.
	 *
	 * @param array $rows    Transformed rows.
	 * @param int   $site_id Site the rows came from.
	 */
	public static function with_site_columns( array $rows, int $site_id ): array {
		$lead = array(
			self::SITE_ID  => (string) $site_id,
			self::SITE_URL => (string) get_home_url( $site_id ),
		);
		$out  = array();
		foreach ( $rows as $row ) {
			$out[] = $lead + (array) $row;
		}
		return $out;
	}

	/**
	 * The note a network preview carries.
	 */
	public static function preview_note(): string {
		return __( 'This preview shows rows from this site only. The export reads every site of the network.', 'open-export' );
	}

	/**
	 * Run a callback inside a site. The current site always comes back, also
	 * when the callback throws, and per-request memos are cleared on the way
	 * in and out so nothing one site found is reused for another.
	 *
	 * @param int      $site_id Site id.
	 * @param callable $callback      Work to run there.
	 * @return mixed What the callback returns.
	 */
	public static function in_site( int $site_id, callable $callback ) {
		switch_to_blog( $site_id );
		self::forget_memos();
		try {
			return $callback();
		} finally {
			restore_current_blog();
			self::forget_memos();
		}
	}

	/**
	 * Run a callback on the job's own site, where its lock lives, then go
	 * back to the site being read.
	 *
	 * @param int      $home Site id of the job.
	 * @param callable $callback   Work to run there.
	 */
	private static function at_home( int $home, callable $callback ): void {
		if ( get_current_blog_id() === $home ) {
			$callback();
			return;
		}
		switch_to_blog( $home );
		try {
			$callback();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Whether a source exists on the current site. WooCommerce code is loaded
	 * for the whole request once any site runs it, so a WooCommerce source
	 * also needs the plugin active on this site.
	 *
	 * @param string $source_id Source id.
	 */
	public static function source_here( string $source_id ): bool {
		if ( 0 === strpos( $source_id, 'woo:' ) && ! self::woo_active_here() ) {
			return false;
		}
		return null !== OPEX_Query::parse_source( $source_id );
	}

	/** Whether WooCommerce is active on the current site or for the whole network. */
	private static function woo_active_here(): bool {
		$plugin = defined( 'WC_PLUGIN_BASENAME' ) ? (string) WC_PLUGIN_BASENAME : 'woocommerce/woocommerce.php';
		if ( in_array( $plugin, (array) get_option( 'active_plugins', array() ), true ) ) {
			return true;
		}
		return isset( ( (array) get_site_option( 'active_sitewide_plugins', array() ) )[ $plugin ] );
	}

	/**
	 * The job note for a site that does not have the source.
	 *
	 * @param int $site_id Site id.
	 */
	private static function missing_note( int $site_id ): string {
		return sprintf(
			/* translators: 1: site id, 2: site address. */
			__( 'Site %1$d (%2$s) does not have this data source, so it adds no rows.', 'open-export' ),
			$site_id,
			get_home_url( $site_id )
		);
	}

	/** Clear per-request memos that hold what one site has. */
	private static function forget_memos(): void {
		OPEX_Catalog::reset_cache( true );
		OPEX_Source_Labels::reset();
	}

	/**
	 * The error for a network export that is not allowed.
	 *
	 * @param string $message Why.
	 */
	private static function refused( string $message ): WP_Error {
		return new WP_Error( 'opex_bad_network', $message, array( 'status' => 400 ) );
	}
}
