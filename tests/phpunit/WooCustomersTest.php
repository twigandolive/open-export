<?php
/**
 * The woo:customers source.
 *
 * WooCommerce fills wc_customer_lookup and wc_order_stats from its Analytics
 * import, which runs in the background. These tests do not run that import:
 * they insert the lookup and stats rows they need directly in setUp and delete
 * them in tearDown. Order ids start at 990101 so they never meet real orders.
 *
 * @package Open_Export
 */

require_once __DIR__ . '/WooSourceCase.php';

/**
 * Customers through the real engine.
 */
class WooCustomersTest extends WooSourceCase {

	/** @var array<string, int> Customer ids by name. */
	private $ids = array();

	/** @var int[] Stats order ids made by the test. */
	private $orders = array();

	/** @var string Timezone before the test. */
	private $timezone = '';

	/** Makes the fixtures. */
	protected function setUp(): void {
		global $wpdb;
		parent::setUp();
		$this->timezone = (string) get_option( 'timezone_string' );
		update_option( 'timezone_string', 'America/New_York' );

		$people = array(
			'registered' => array( 'user_id' => 990001, 'username' => 'opexuser', 'first_name' => 'Opex', 'last_name' => 'Buyer', 'email' => 'opex-reg@example.test', 'country' => 'US', 'state' => 'NY', 'city' => 'Albany', 'postcode' => '12207', 'date_registered' => '2020-02-02 10:00:00', 'date_last_active' => '2026-01-01 15:00:00' ),
			'guest'      => array( 'user_id' => null, 'username' => '', 'first_name' => 'Opex', 'last_name' => 'Guest', 'email' => 'opex-guest@example.test', 'country' => 'CA', 'state' => 'ON', 'city' => 'Ottawa', 'postcode' => 'K1A', 'date_registered' => null, 'date_last_active' => '2025-06-01 12:00:00' ),
			'idle'       => array( 'user_id' => 990002, 'username' => 'opexidle', 'first_name' => 'Opex', 'last_name' => 'Idle', 'email' => 'opex-idle@example.test', 'country' => '', 'state' => '', 'city' => '', 'postcode' => '', 'date_registered' => '2019-01-01 00:00:00', 'date_last_active' => null ),
		);
		foreach ( $people as $name => $row ) {
			$wpdb->insert( $wpdb->prefix . 'wc_customer_lookup', $row );
			$this->ids[ $name ] = (int) $wpdb->insert_id;
		}

		$reg   = $this->ids['registered'];
		$stats = array(
			array( 990101, 0, '19.99', 'wc-completed', $reg, 0 ),
			array( 990102, 0, '0.01', 'wc-processing', $reg, 1 ),
			array( 990103, 990101, '-5', 'wc-completed', $reg, null ),
			array( 990104, 0, '0.02', 'wc-completed', $reg, 1 ),
			array( 990105, 0, '100', 'wc-pending', $reg, 1 ),
			array( 990106, 0, '50', 'wc-failed', $reg, 1 ),
			array( 990107, 0, '33.335', 'wc-completed', $this->ids['guest'], 0 ),
		);
		foreach ( $stats as $s ) {
			$wpdb->insert(
				$wpdb->prefix . 'wc_order_stats',
				array(
					'order_id'           => $s[0],
					'parent_id'          => $s[1],
					'date_created'       => '2025-01-01 00:00:00',
					'date_created_gmt'   => '2025-01-01 05:00:00',
					'num_items_sold'     => 1,
					'total_sales'        => $s[2],
					'tax_total'          => '0',
					'shipping_total'     => '0',
					'net_total'          => $s[2],
					'returning_customer' => $s[5],
					'status'             => $s[3],
					'customer_id'        => $s[4],
				)
			);
			$this->orders[] = $s[0];
		}
	}

	/** Deletes the fixtures. */
	protected function tearDown(): void {
		global $wpdb;
		foreach ( $this->orders as $order ) {
			$wpdb->delete( $wpdb->prefix . 'wc_order_stats', array( 'order_id' => $order ) );
		}
		foreach ( $this->ids as $id ) {
			$wpdb->delete( $wpdb->prefix . 'wc_customer_lookup', array( 'customer_id' => $id ) );
		}
		update_option( 'timezone_string', $this->timezone );
		$this->ids    = array();
		$this->orders = array();
		parent::tearDown();
	}

	/**
	 * Last names of the fixture customers a rule keeps.
	 *
	 * @param array $rule Rule or group.
	 * @return string[]
	 */
	private function people( array $rule ): array {
		$only = array( 'field' => 'woo:email', 'op' => 'starts_with', 'value' => 'opex-' );
		return $this->pluck( 'woo:customers', 'woo:last_name', array( 'relation' => 'AND', 'rules' => array( $only, $rule ) ) );
	}

	/** Every field, with exact totals and site-time dates. */
	public function test_rows_hold_every_field(): void {
		$fields = array_keys( OPEX_Catalog::field_ids( 'woo:customers' ) );
		$this->assertCount( 15, $fields );
		foreach ( $fields as $field ) {
			$this->assertDoesNotMatchRegularExpression( '/pass|session|token/i', $field );
		}
		$rows = $this->rows( $this->config( 'woo:customers', array(
			'fields'  => $fields,
			'filters' => array( 'rules' => array( array( 'field' => 'woo:email', 'op' => 'starts_with', 'value' => 'opex-' ) ) ),
		) ) );
		$this->assertSame( array( 'Buyer', 'Guest', 'Idle' ), array_column( $rows, 'woo:last_name' ) );

		list( $reg, $guest, $idle ) = $rows;
		$this->assertSame( (string) $this->ids['registered'], $reg['woo:customer_id'] );
		$this->assertSame( '990001', $reg['woo:user_id'] );
		$this->assertSame( 'opexuser', $reg['woo:username'] );
		$this->assertSame( 'Opex', $reg['woo:first_name'] );
		$this->assertSame( 'opex-reg@example.test', $reg['woo:email'] );
		$this->assertSame( array( 'US', 'NY', 'Albany', '12207' ), array( $reg['woo:country'], $reg['woo:state'], $reg['woo:city'], $reg['woo:postcode'] ) );
		$this->assertSame( '2020-02-02 10:00:00', $reg['woo:date_registered'], 'stored in site time' );
		$this->assertSame( '2026-01-01 10:00:00', $reg['woo:date_last_active'], 'stored in UTC, printed in site time' );
		$this->assertSame( '3', $reg['woo:order_count'], 'refunds and excluded statuses do not count' );
		$this->assertSame( '15.02', $reg['woo:total_spend'], 'refunds subtract, excluded statuses do not add' );
		$this->assertSame( '5.01', $reg['woo:avg_order_value'] );

		$this->assertSame( '', $guest['woo:user_id'], 'a guest has no user id' );
		$this->assertSame( '', $guest['woo:date_registered'] );
		$this->assertSame( '1', $guest['woo:order_count'] );
		$this->assertSame( '33.34', $guest['woo:total_spend'], 'string math rounds 33.335 up' );
		$this->assertSame( '33.34', $guest['woo:avg_order_value'] );

		$this->assertSame( '0', $idle['woo:order_count'] );
		$this->assertSame( '0.00', $idle['woo:total_spend'] );
		$this->assertSame( '', $idle['woo:avg_order_value'], 'no orders, no average' );
		$this->assertSame( '', $idle['woo:date_last_active'] );
	}

	/** Rules on lookup columns, totals and dates. */
	public function test_filters(): void {
		$cases = array(
			array( 'woo:order_count', 'gt', '1', array( 'Buyer' ) ),
			array( 'woo:order_count', 'eq', '0', array( 'Idle' ) ),
			array( 'woo:total_spend', 'gte', '15', array( 'Buyer', 'Guest' ) ),
			array( 'woo:total_spend', 'lt', '1', array( 'Idle' ) ),
			array( 'woo:avg_order_value', 'gt', '10', array( 'Guest' ) ),
			array( 'woo:avg_order_value', 'empty', '', array( 'Idle' ) ),
			array( 'woo:user_id', 'empty', '', array( 'Guest' ) ),
			array( 'woo:user_id', 'not_empty', '', array( 'Buyer', 'Idle' ) ),
			array( 'woo:email', 'ends_with', '@example.test', array( 'Buyer', 'Guest', 'Idle' ) ),
			array( 'woo:country', 'in', 'US,CA', array( 'Buyer', 'Guest' ) ),
			array( 'woo:city', 'neq', 'Albany', array( 'Guest', 'Idle' ) ),
			array( 'woo:username', 'contains', 'idle', array( 'Idle' ) ),
			array( 'woo:date_registered', 'lt', '2021-01-01', array( 'Buyer', 'Idle' ) ),
			array( 'woo:date_last_active', 'eq', '2026-01-01', array( 'Buyer' ) ),
			array( 'woo:customer_id', 'eq', (string) $this->ids['guest'], array( 'Guest' ) ),
		);
		foreach ( $cases as $case ) {
			list( $field, $op, $value, $expect ) = $case;
			$this->assertSame( $expect, $this->people( array( 'field' => $field, 'op' => $op, 'value' => $value ) ), "$field $op $value" );
		}
		$nested = array( 'relation' => 'OR', 'rules' => array(
			array( 'field' => 'woo:user_id', 'op' => 'empty', 'value' => '' ),
			array( 'relation' => 'AND', 'rules' => array(
				array( 'field' => 'woo:order_count', 'op' => 'eq', 'value' => '0' ),
				array( 'field' => 'woo:date_registered', 'op' => 'lt', 'value' => '2019-06-01' ),
			) ),
		) );
		$this->assertSame( array( 'Guest', 'Idle' ), $this->people( $nested ) );

		// Customers have no custom fields, and credentials never resolve.
		$notes  = array();
		$config = $this->config( 'woo:customers', array( 'fields' => array( 'woo:email', 'meta:session_tokens', 'core:user_pass', 'meta:billing_phone' ) ), $notes );
		$this->assertSame( array( 'woo:email' ), $config['fields'] );
		$this->assertCount( 3, $notes );
	}

	/** Date ranges and since on both dates. */
	public function test_date_range_and_since(): void {
		$only  = array( 'rules' => array( array( 'field' => 'woo:email', 'op' => 'starts_with', 'value' => 'opex-' ) ) );
		$range = function ( string $field, string $from, string $to ) use ( $only ): array {
			return array_column( $this->rows( $this->config( 'woo:customers', array(
				'fields'     => array( 'woo:last_name' ),
				'filters'    => $only,
				'date_range' => array( 'field' => $field, 'preset' => 'custom', 'from' => $from, 'to' => $to ),
			) ) ), 'woo:last_name' );
		};
		$this->assertSame( array( 'Guest' ), $range( 'woo:date_last_active', '2025-06-01', '2025-06-01' ) );
		$this->assertSame( array( 'Buyer' ), $range( 'woo:date_registered', '2020-01-01', '2020-12-31' ) );

		$config = $this->config( 'woo:customers', array( 'fields' => array( 'woo:email' ), 'filters' => $only ) );
		$this->assertSame( 1, (int) $this->export( $config, array( 'since' => array( 'field' => 'woo:date_registered', 'after' => '2020-01-01 00:00:00' ) ) )['processed'] );
		$this->assertSame( 2, (int) $this->export( $config, array( 'since' => array( 'field' => 'woo:date_last_active', 'after' => '2025-01-01 00:00:00' ) ) )['processed'] );
	}

	/** Every format, transforms, custom columns and preview. */
	public function test_formats(): void {
		$this->assert_all_formats(
			'woo:customers',
			array( 'woo:email', 'woo:total_spend', 'woo:avg_order_value', 'woo:order_count', 'woo:date_last_active' ),
			array( 'rules' => array( array( 'field' => 'woo:email', 'op' => 'eq', 'value' => 'opex-reg@example.test' ) ) )
		);
	}

	/** The picker, the capability document and recipe import. */
	public function test_surface(): void {
		$this->assert_surface( 'woo:customers', array(
			'woo:customer_id'     => 'id',
			'woo:user_id'         => 'id',
			'woo:email'           => 'email',
			'woo:order_count'     => 'number',
			'woo:total_spend'     => 'number',
			'woo:avg_order_value' => 'number',
			'woo:date_registered' => 'date',
		) );
	}
}
