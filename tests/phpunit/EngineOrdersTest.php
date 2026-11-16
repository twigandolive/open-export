<?php
/**
 * WooCommerce orders: one output format on both storage
 * engines, draft orders excluded, and friendly filter values.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class EngineOrdersTest extends TestCase {

	/** @var int[] Ids of rows this test made. */
	private array $made = array();

	protected function setUp(): void {
		wp_set_current_user( 1 );
	}

	protected function tearDown(): void {
		global $wpdb;
		foreach ( $this->made as $id ) {
			if ( OPEX_Woo::use_hpos() ) {
				$wpdb->delete( OPEX_Woo::table( 'orders' ), array( 'id' => $id ) );
			}
			wp_delete_post( $id, true );
		}
		$this->made = array();
		update_option( 'timezone_string', '' );
	}

	/** Skip when WooCommerce is not on the test site. */
	private function need_woo(): void {
		if ( ! OPEX_Woo::is_active() ) {
			$this->markTestSkipped( 'WooCommerce is not active on the test site.' );
		}
	}

	/** Sanitize a config. */
	private function config( array $fields, array $rules = array() ): array {
		$config = OPEX_Exporter::sanitize_config( array( 'source' => 'woo:orders', 'fields' => $fields, 'filters' => array( 'rules' => $rules ), 'batch_size' => 100 ) );
		$this->assertIsArray( $config, is_wp_error( $config ) ? $config->get_error_message() : '' );
		return $config;
	}

	/** Add an order row in the active storage with a given status. */
	private function add_order( string $status ): void {
		global $wpdb;
		if ( OPEX_Woo::use_hpos() ) {
			// Custom-table orders take their id from a placeholder post, as WooCommerce does.
			$id  = wp_insert_post( array( 'post_type' => 'shop_order_placehold', 'post_status' => 'draft', 'post_title' => 'placeholder' ) );
			$now = gmdate( 'Y-m-d H:i:s' );
			$wpdb->insert( OPEX_Woo::table( 'orders' ), array( 'id' => $id, 'status' => $status, 'type' => 'shop_order', 'currency' => 'USD', 'total_amount' => 5, 'date_created_gmt' => $now, 'date_updated_gmt' => $now ) );
			$this->made[] = (int) $id;
			return;
		}
		$id = wp_insert_post( array( 'post_type' => 'shop_order', 'post_status' => $status, 'post_title' => 'draft order' ) );
		$this->assertGreaterThan( 0, $id );
		$this->made[] = (int) $id;
	}

	/** Money is cut to the store's decimals with string math, never floats. */
	public function test_money_uses_string_math(): void {
		$cases = array(
			'82.5'           => '82.50',
			'82.50000000'    => '82.50',
			'0'              => '0.00',
			'0.005'          => '0.01',
			'0.004'          => '0.00',
			'-1.005'         => '-1.01',
			'-0.004'         => '0.00',
			'99.995'         => '100.00',
			'12345678901234567.895' => '12345678901234567.90',
			'.5'             => '0.50',
			''               => '',
			'n/a'            => 'n/a',
		);
		foreach ( $cases as $in => $out ) {
			$this->assertSame( $out, OPEX_Money::format( (string) $in, 2 ), "bcmath path: $in" );
			$this->assertSame( $out, OPEX_Money::format( (string) $in, 2, false ), "string path: $in" );
		}
		$this->assertSame( '83', OPEX_Money::format( '82.5', 0 ) );
		$this->assertSame( '82.500', OPEX_Money::format( '82.5', 3, false ) );
	}

	/** Dates become site-time text from either storage's raw value. */
	public function test_dates_become_site_time(): void {
		update_option( 'timezone_string', 'America/Los_Angeles' );
		// Custom tables keep UTC.
		$this->assertSame( '2026-06-29 05:00:00', OPEX_Woo_Format::value( 'woo:date_created', '2026-06-29 12:00:00', true ) );
		$this->assertSame( '2026-06-29 05:00:00', OPEX_Woo_Format::value( 'woo:date_paid', '2026-06-29 12:00:00', true ) );
		$this->assertSame( '', OPEX_Woo_Format::value( 'woo:date_completed', '', true ) );
		$this->assertSame( '', OPEX_Woo_Format::value( 'woo:date_paid', '0000-00-00 00:00:00', true ) );
		// Classic storage: post_date is already site time, paid and completed are timestamps.
		$this->assertSame( '2026-06-29 05:00:00', OPEX_Woo_Format::value( 'woo:date_created', '2026-06-29 05:00:00', false ) );
		$this->assertSame( '2026-06-29 05:00:00', OPEX_Woo_Format::value( 'woo:date_paid', (string) gmmktime( 12, 0, 0, 6, 29, 2026 ), false ) );
		$this->assertSame( '', OPEX_Woo_Format::value( 'woo:date_completed', '', false ) );
		// Not a date at all: left alone.
		$this->assertSame( 'soon', OPEX_Woo_Format::value( 'woo:date_paid', 'soon', true ) );
	}

	/** Flags print as yes or no whatever the engine stored. */
	public function test_flags_print_yes_or_no(): void {
		foreach ( array( '1', 'yes', 'YES', 'true' ) as $raw ) {
			$this->assertSame( 'yes', OPEX_Woo_Format::value( 'woo:stock_reduced', $raw, true ), $raw );
		}
		foreach ( array( '0', 'no', '', 'false' ) as $raw ) {
			$this->assertSame( 'no', OPEX_Woo_Format::value( 'woo:prices_include_tax', $raw, false ), $raw );
		}
		$this->assertSame( 'wc-completed', OPEX_Woo_Format::value( 'woo:status', 'wc-completed', true ) );
	}

	/** The same output on both storage engines. */
	public function test_orders_export_as_plain_text(): void {
		$this->need_woo();
		$fields = array( 'woo:order_id', 'woo:total', 'woo:tax_total', 'woo:discount_total', 'woo:date_created', 'woo:date_paid', 'woo:prices_include_tax', 'woo:stock_reduced', 'woo:is_guest' );
		$items  = OPEX_Query::batch( $this->config( $fields ), 0, 100 )['items'];
		$this->assertNotEmpty( $items );
		foreach ( $items as $item ) {
			$this->assertMatchesRegularExpression( '/^-?\d+\.\d{2}$/', $item['woo:total'], 'money has the store decimals' );
			$this->assertMatchesRegularExpression( '/^-?\d+\.\d{2}$/', $item['woo:tax_total'] );
			$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $item['woo:date_created'] );
			$this->assertContains( $item['woo:prices_include_tax'], array( 'yes', 'no' ) );
			$this->assertContains( $item['woo:stock_reduced'], array( 'yes', 'no' ) );
			$this->assertContains( $item['woo:is_guest'], array( 'yes', 'no' ) );
			if ( '' !== $item['woo:date_paid'] ) {
				$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $item['woo:date_paid'] );
			}
		}
	}

	/** Auto-drafts and checkout drafts are carts, not orders, on both storages. */
	public function test_draft_orders_are_excluded(): void {
		$this->need_woo();
		$config = $this->config( array( 'woo:order_id' ) );
		$before = OPEX_Query::count( $config );
		$this->add_order( 'auto-draft' );
		$this->add_order( 'checkout-draft' );
		$this->assertSame( $before, OPEX_Query::count( $config ) );
		$this->assertCount( $before, OPEX_Query::batch( $config, 0, 500 )['items'] );
		$this->add_order( 'wc-processing' );
		$this->assertSame( $before + 1, OPEX_Query::count( $config ), 'a real order is counted' );
		$picker = OPEX_Woo::source_item();
		$this->assertSame( $before + 1, $picker['count'], 'the picker count agrees' );
	}

	/** A status filter works with or without the wc- prefix. */
	public function test_status_filter_with_and_without_prefix(): void {
		$this->need_woo();
		$count = function ( string $op, string $value ): int {
			return OPEX_Query::count( $this->config( array( 'woo:order_id' ), array( array( 'field' => 'woo:status', 'op' => $op, 'value' => $value ) ) ) );
		};
		$this->assertGreaterThan( 0, $count( 'eq', 'wc-completed' ) );
		$this->assertSame( $count( 'eq', 'wc-completed' ), $count( 'eq', 'completed' ) );
		$this->assertSame( $count( 'neq', 'wc-completed' ), $count( 'neq', 'completed' ) );
		$this->assertSame( $count( 'in', 'wc-completed,wc-processing' ), $count( 'in', 'completed, processing' ) );
		$this->assertSame( $count( 'not_in', 'wc-completed' ), $count( 'not_in', 'completed' ) );
		$this->assertSame( $count( 'eq', 'wc-completed' ) + $count( 'neq', 'completed' ), $count( 'neq', 'nothing-like-this' ) );
	}

	/** The guest flag filters with yes or no, matching what the column shows. */
	public function test_guest_filter_takes_yes_and_no(): void {
		$this->need_woo();
		$all   = OPEX_Query::batch( $this->config( array( 'woo:order_id', 'woo:is_guest' ) ), 0, 500 )['items'];
		$yes   = count( array_filter( $all, static function ( $row ) {
			return 'yes' === $row['woo:is_guest'];
		} ) );
		$count = function ( string $op, string $value ): int {
			return OPEX_Query::count( $this->config( array( 'woo:order_id' ), array( array( 'field' => 'woo:is_guest', 'op' => $op, 'value' => $value ) ) ) );
		};
		$this->assertSame( $yes, $count( 'eq', 'yes' ) );
		$this->assertSame( count( $all ) - $yes, $count( 'eq', 'no' ) );
		$this->assertSame( count( $all ) - $yes, $count( 'neq', 'yes' ) );
		$this->assertSame( $yes, $count( 'neq', 'no' ) );
		$this->assertSame( count( $all ), $count( 'in', 'yes,no' ) );
		$this->assertSame( $yes, $count( 'in', 'true' ) );
		$this->assertSame( 0, $count( 'in', ' , ' ), 'an empty list matches nothing' );
	}
}
