<?php
/**
 * Provider for WooCommerce customers, guests included.
 *
 * Reads wc_customer_lookup, the table WooCommerce Analytics keeps, and adds
 * order count, lifetime spend and average order value from wc_order_stats,
 * counted the way the Analytics customers report counts them: refunds are
 * not orders, and the statuses the store excludes from reports are left out.
 *
 * wc_order_stats keeps totals as DOUBLE. Summing those in SQL would be float
 * math, so spend and average are added up in PHP as exact decimal strings.
 * A rule cannot use them: it would have to compare a float SQL sum, which
 * can disagree with the exact amount the export prints.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Customers from the Analytics lookup table, with their order totals.
 */
class OPEX_Source_Woo_Customers extends OPEX_Source_Table {

	use OPEX_Source_Mapped;

	/** The source id saved configs use. */
	const SOURCE = 'woo:customers';

	/**
	 * Totals of the current batch, by customer id.
	 *
	 * @var array<int, array{spend: string, count: int}>
	 */
	private $totals = array();

	/**
	 * The provider when WooCommerce is active, else null.
	 *
	 * @return OPEX_Source_Provider|null The provider, or null when WooCommerce is not active.
	 */
	public static function resolve(): ?OPEX_Source_Provider {
		return OPEX_Woo::is_active() ? OPEX_Sources::instance( __CLASS__ ) : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string The source id.
	 */
	public function id(): string {
		return self::SOURCE;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string The source type.
	 */
	public function src_type(): string {
		return 'woo_customers';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string A key that changes when the definitions must be built again.
	 */
	protected function defs_key(): string {
		global $wpdb;
		// The order count reads the orders table of the current storage engine.
		return $wpdb->prefix . ( OPEX_Woo::use_hpos() ? '|hpos' : '|legacy' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool True when the source can be used on this site.
	 */
	public function is_available(): bool {
		return OPEX_Catalog::table_exists( self::lookup_table() ) && OPEX_Catalog::table_exists( self::stats_table() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string The FROM clause.
	 */
	public function from(): string {
		return '`' . self::lookup_table() . '` c';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array The base WHERE conditions and the values for their placeholders.
	 */
	public function base_where(): array {
		return array( array(), array() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string The key column for cursor paging.
	 */
	public function primary_key(): string {
		return 'c.customer_id';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string The key property name on a fetched row.
	 */
	public function id_prop(): string {
		return 'customer_id';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string The SQL for the start of the SELECT list.
	 */
	protected function base_select(): string {
		return 'c.customer_id';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string The source name.
	 */
	public function label(): string {
		return __( 'WooCommerce Customers', 'open-export' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array|null The picker entry, or null when the source has none.
	 */
	public function picker_item(): ?array {
		return array(
			'id'    => self::SOURCE,
			'label' => __( 'WooCommerce Customers', 'open-export' ),
			'icon'  => 'dashicons-groups',
			'count' => $this->count_rows(),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array> Date fields by field id, each with a label and how its time is stored.
	 */
	public function date_fields(): array {
		// WooCommerce writes the registered date in site time and the last
		// active date in UTC.
		return array(
			'woo:date_registered'  => array(
				'label' => __( 'Date registered', 'open-export' ),
				'tz'    => OPEX_Dates::TZ_LOCAL,
				'kind'  => 'column',
				'sql'   => 'c.date_registered',
			),
			'woo:date_last_active' => array(
				'label' => __( 'Date last active', 'open-export' ),
				'tz'    => OPEX_Dates::TZ_UTC,
				'kind'  => 'column',
				'sql'   => 'c.date_last_active',
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int[] $ids Row ids of the batch.
	 */
	public function prime( array $ids ): void {
		global $wpdb;
		$this->totals = array();
		if ( ! $ids ) {
			return;
		}
		list( $status_sql, $status_args ) = $this->status_condition();
		$holders                          = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$stats                            = self::stats_table();
		$sql                              = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table names come from $wpdb->prefix, the placeholder lists are built above, and every value is bound.
			"SELECT s.customer_id, s.total_sales, {$this->countable_sql()} AS countable FROM `{$stats}` s WHERE s.customer_id IN ({$holders}) AND {$status_sql}",
			array_merge( $ids, $status_args )
		);
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above; export batches are uncached.
		$rows = $wpdb->get_results( $sql );
		OPEX_Query::throw_on_db_error();
		foreach ( (array) $rows as $row ) {
			$id = (int) $row->customer_id;
			if ( ! isset( $this->totals[ $id ] ) ) {
				$this->totals[ $id ] = array(
					'spend' => '0',
					'count' => 0,
				);
			}
			$amount = OPEX_Decimal::normalize( (string) $row->total_sales );
			if ( null !== $amount ) {
				$this->totals[ $id ]['spend'] = OPEX_Decimal::add( $this->totals[ $id ]['spend'], $amount );
			}
			$this->totals[ $id ]['count'] += (int) $row->countable;
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param object $row   Fetched row.
	 * @param string $field Field id.
	 * @param int    $id    Row id.
	 * @return string|null The cell text, or null when the field is not built in PHP.
	 */
	public function compute_value( object $row, string $field, int $id ): ?string {
		if ( 'woo:total_spend' !== $field && 'woo:avg_order_value' !== $field ) {
			return null;
		}
		$totals = $this->totals[ $id ] ?? array(
			'spend' => '0',
			'count' => 0,
		);
		if ( 'woo:total_spend' === $field ) {
			return $totals['spend'];
		}
		// No orders means no average, as in the Analytics report.
		return $totals['count'] > 0 ? OPEX_Decimal::divide( $totals['spend'], $totals['count'], OPEX_Woo_Format::price_decimals() ) : '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array> Field definitions by field id.
	 */
	protected function field_defs(): array {
		$who   = __( 'Customer', 'open-export' );
		$where = __( 'Location', 'open-export' );
		$money = __( 'Orders', 'open-export' );
		$dates = __( 'Dates', 'open-export' );

		$stats                            = self::stats_table();
		list( $status_sql, $status_args ) = $this->status_condition();
		global $wpdb;
		// Status values are bound here once, so the expressions hold no raw input.
		$scope = "s.customer_id = c.customer_id AND {$status_sql}";
		if ( $status_args ) {
			$scope = (string) $wpdb->prepare( $scope, $status_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built from a list.
		}
		$count = $this->countable_sql();

		return array(
			'woo:customer_id'      => array(
				'group' => $who,
				'type'  => 'id',
				'sql'   => 'c.customer_id',
			),
			'woo:user_id'          => array(
				'group' => $who,
				'type'  => 'id',
				'sql'   => 'c.user_id',
			),
			'woo:username'         => array(
				'group' => $who,
				'type'  => 'text',
				'sql'   => 'c.username',
			),
			'woo:first_name'       => array(
				'group' => $who,
				'type'  => 'text',
				'sql'   => 'c.first_name',
			),
			'woo:last_name'        => array(
				'group' => $who,
				'type'  => 'text',
				'sql'   => 'c.last_name',
			),
			'woo:email'            => array(
				'group' => $who,
				'type'  => 'email',
				'sql'   => 'c.email',
			),
			'woo:country'          => array(
				'group' => $where,
				'type'  => 'text',
				'sql'   => 'c.country',
			),
			'woo:state'            => array(
				'group' => $where,
				'type'  => 'text',
				'sql'   => 'c.state',
			),
			'woo:city'             => array(
				'group' => $where,
				'type'  => 'text',
				'sql'   => 'c.city',
			),
			'woo:postcode'         => array(
				'group' => $where,
				'type'  => 'text',
				'sql'   => 'c.postcode',
			),
			// Cast to text: SQLite never matches a bare COUNT against the text a rule binds.
			'woo:order_count'      => array(
				'group' => $money,
				'type'  => 'number',
				'sql'   => "CAST((SELECT COUNT(*) FROM `{$stats}` s WHERE {$scope} AND {$count} = 1) AS CHAR)",
			),
			'woo:total_spend'      => array(
				'group'  => $money,
				'type'   => 'number',
				'php'    => true,
				'format' => 'money',
			),
			'woo:avg_order_value'  => array(
				'group'  => $money,
				'type'   => 'number',
				'php'    => true,
				'format' => 'money',
			),
			'woo:date_registered'  => array(
				'group'  => $dates,
				'type'   => 'date',
				'sql'    => 'c.date_registered',
				'format' => 'local',
			),
			'woo:date_last_active' => array(
				'group'  => $dates,
				'type'   => 'date',
				'sql'    => 'c.date_last_active',
				'format' => 'utc',
			),
		);
	}

	/**
	 * SQL that is 1 for a stats row that counts as an order, as WooCommerce
	 * decides it: a row with no parent is an order, a refund is not, and a
	 * parented row counts only when the source order really is an order.
	 *
	 * @return string The SQL expression.
	 */
	private function countable_sql(): string {
		global $wpdb;
		$hpos   = OPEX_Woo::use_hpos();
		$orders = $hpos ? OPEX_Woo::table( 'orders' ) : $wpdb->posts;
		$id     = $hpos ? 'id' : 'ID';
		$type   = $hpos ? 'type' : 'post_type';
		return "(CASE WHEN s.parent_id = 0 THEN 1 WHEN s.parent_id IS NULL THEN 0 WHEN s.returning_customer IS NULL THEN 0 WHEN EXISTS (SELECT 1 FROM `{$orders}` co WHERE co.{$id} = s.order_id AND co.{$type} = 'shop_order') THEN 1 ELSE 0 END)";
	}

	/**
	 * The statuses Analytics leaves out of every report, as a condition and its values.
	 *
	 * @return array{0: string, 1: string[]}
	 */
	private function status_condition(): array {
		$excluded = (array) get_option( 'woocommerce_excluded_report_order_statuses', array( 'pending', 'cancelled', 'failed' ) );
		$excluded = array_merge( array( 'auto-draft', 'trash' ), $excluded );
		// WooCommerce's own filter: applying it keeps the totals equal to its reports.
		$excluded = (array) apply_filters( 'woocommerce_analytics_excluded_order_statuses', $excluded ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- a WooCommerce hook, applied on purpose.
		$stored   = array();
		foreach ( $excluded as $status ) {
			$status = trim( (string) $status );
			if ( '' !== $status ) {
				// Stats rows keep the "wc-" prefix in a 20 character column.
				$stored[] = substr( 0 === strpos( $status, 'wc-' ) ? $status : 'wc-' . $status, 0, 20 );
			}
		}
		$stored = array_values( array_unique( $stored ) );
		if ( ! $stored ) {
			return array( '1=1', array() );
		}
		return array( 's.status NOT IN (' . implode( ',', array_fill( 0, count( $stored ), '%s' ) ) . ')', $stored );
	}

	/**
	 * Full name of the customer lookup table.
	 *
	 * @return string The table name.
	 */
	private static function lookup_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_customer_lookup';
	}

	/**
	 * Full name of the order stats table.
	 *
	 * @return string The table name.
	 */
	private static function stats_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_order_stats';
	}
}
