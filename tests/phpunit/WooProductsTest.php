<?php
/**
 * The woo:products source: products and variations in one export.
 *
 * Fixtures: a draft simple product, a variable product with a taxonomy
 * attribute and a custom one, and two variations. All are made in setUp and
 * deleted in tearDown, so the seeded store and its goldens never change.
 *
 * @package Open_Export
 */

require_once __DIR__ . '/WooSourceCase.php';

/**
 * Products through the real engine.
 */
class WooProductsTest extends WooSourceCase {

	/** @var array<string, int> Fixture ids by name. */
	private $ids = array();

	/** @var int Attribute taxonomy id. */
	private $attribute = 0;

	/** Makes the fixtures. */
	protected function setUp(): void {
		parent::setUp();
		$this->attribute = (int) wc_create_attribute( array( 'name' => 'OPEX Color', 'slug' => 'opexcolor' ) );
		register_taxonomy( 'pa_opexcolor', array( 'product' ), array( 'hierarchical' => false ) );
		// WooCommerce fills this list at init. A taxonomy registered later must join it by hand.
		$GLOBALS['wc_product_attributes']['pa_opexcolor'] = wc_get_attribute( $this->attribute );
		$red  = wp_insert_term( 'Red', 'pa_opexcolor' );
		$blue = wp_insert_term( 'Blue', 'pa_opexcolor' );
		$cat  = wp_insert_term( 'OPEX Test Cat', 'product_cat' );
		$tag  = wp_insert_term( 'OPEX Tag', 'product_tag' );
		$this->ids['cat'] = (int) $cat['term_id'];
		$this->ids['tag'] = (int) $tag['term_id'];
		foreach ( array( 'img1', 'img2', 'img3' ) as $name ) {
			$this->ids[ $name ] = (int) wp_insert_attachment( array( 'post_title' => $name, 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ), 'opex-test/' . $name . '.jpg' );
		}

		$simple = new WC_Product_Simple();
		$simple->set_props( array(
			'name'              => 'OPEX Simple',
			'sku'               => 'OPEX-SIMPLE',
			'status'            => 'draft',
			'regular_price'     => '12.345',
			'short_description' => 'Short',
			'description'       => 'Long',
			'tax_status'        => 'shipping',
			'tax_class'         => 'reduced-rate',
			'category_ids'      => array( $this->ids['cat'] ),
			'catalog_visibility' => 'hidden',
			'total_sales'       => 7,
			'date_created'      => '2020-01-15 10:00:00',
		) );
		$this->ids['simple'] = (int) $simple->save();

		$color = new WC_Product_Attribute();
		$color->set_id( $this->attribute );
		$color->set_name( 'pa_opexcolor' );
		$color->set_options( array( (int) $red['term_id'], (int) $blue['term_id'] ) );
		$color->set_variation( true );
		$size = new WC_Product_Attribute();
		$size->set_name( 'Size' );
		$size->set_options( array( 'Small', 'Large' ) );
		$size->set_variation( true );

		$parent = new WC_Product_Variable();
		$parent->set_props( array(
			'name'               => 'OPEX Variable',
			'slug'               => 'opex-variable',
			'sku'                => 'OPEX-VAR',
			'description'        => 'Parent text',
			'category_ids'       => array( $this->ids['cat'] ),
			'tag_ids'            => array( $this->ids['tag'] ),
			'featured'           => true,
			'catalog_visibility' => 'catalog',
			'image_id'           => $this->ids['img1'],
			'gallery_image_ids'  => array( $this->ids['img2'], $this->ids['img3'] ),
		) );
		$parent->set_attributes( array( $color, $size ) );
		$this->ids['variable'] = (int) $parent->save();

		$red_variation = new WC_Product_Variation();
		$red_variation->set_props( array(
			'parent_id'         => $this->ids['variable'],
			'sku'               => 'OPEX-VAR-RED',
			'regular_price'     => '10.50',
			'sale_price'        => '9.995',
			'date_on_sale_from' => '2020-01-01 00:00:00',
			'date_on_sale_to'   => '2030-12-31 23:59:59',
		) );
		$red_variation->set_attributes( array( 'pa_opexcolor' => 'red', 'size' => 'Small' ) );
		$this->ids['red'] = (int) $red_variation->save();

		$blue_variation = new WC_Product_Variation();
		$blue_variation->set_props( array(
			'parent_id'      => $this->ids['variable'],
			'sku'            => 'OPEX-VAR-BLUE',
			'regular_price'  => '20',
			'manage_stock'   => true,
			'stock_quantity' => 5,
			'weight'         => '1.5',
			'description'    => 'Blue variation text',
		) );
		$blue_variation->set_attributes( array( 'pa_opexcolor' => 'blue', 'size' => 'Large' ) );
		$this->ids['blue'] = (int) $blue_variation->save();
		WC_Product_Variable::sync( $this->ids['variable'] );
	}

	/** Deletes the fixtures. */
	protected function tearDown(): void {
		foreach ( array( 'red', 'blue', 'variable', 'simple' ) as $name ) {
			$product = isset( $this->ids[ $name ] ) ? wc_get_product( $this->ids[ $name ] ) : null;
			if ( $product ) {
				$product->delete( true );
			}
		}
		foreach ( array( 'img1', 'img2', 'img3' ) as $name ) {
			wp_delete_post( $this->ids[ $name ] ?? 0, true );
		}
		if ( isset( $this->ids['cat'] ) ) {
			wp_delete_term( $this->ids['cat'], 'product_cat' );
		}
		if ( isset( $this->ids['tag'] ) ) {
			wp_delete_term( $this->ids['tag'], 'product_tag' );
		}
		if ( $this->attribute ) {
			wc_delete_attribute( $this->attribute );
		}
		unregister_taxonomy( 'pa_opexcolor' );
		unset( $GLOBALS['wc_product_attributes']['pa_opexcolor'] );
		$this->ids       = array();
		$this->attribute = 0;
		parent::tearDown();
	}

	/**
	 * SKUs of the fixture rows a rule keeps, in id order.
	 *
	 * @param array $rule Rule or group.
	 * @return string[]
	 */
	private function skus( array $rule ): array {
		$only = array( 'field' => 'woo:sku', 'op' => 'starts_with', 'value' => 'OPEX-' );
		return $this->pluck( 'woo:products', 'woo:sku', array( 'relation' => 'AND', 'rules' => array( $only, $rule ) ) );
	}

	/** Every field, read from a simple product, a variable product and its variations. */
	public function test_rows_hold_every_field(): void {
		$fields = array_keys( OPEX_Catalog::field_ids( 'woo:products' ) );
		$fields = array_values( array_filter( $fields, static function ( $id ) {
			return 0 === strpos( $id, 'woo:' );
		} ) );
		$this->assertCount( 35, $fields );
		$rows = $this->rows( $this->config( 'woo:products', array(
			'fields'  => $fields,
			'filters' => array( 'rules' => array( array( 'field' => 'woo:sku', 'op' => 'starts_with', 'value' => 'OPEX-' ) ) ),
		) ) );
		$by = array_column( $rows, null, 'woo:sku' );
		$this->assertSame( array( 'OPEX-SIMPLE', 'OPEX-VAR', 'OPEX-VAR-RED', 'OPEX-VAR-BLUE' ), array_column( $rows, 'woo:sku' ) );

		$simple = $by['OPEX-SIMPLE'];
		$this->assertSame( 'simple', $simple['woo:type'] );
		$this->assertSame( '0', $simple['woo:parent_id'] );
		$this->assertSame( '', $simple['woo:parent_sku'] );
		$this->assertSame( 'draft', $simple['woo:status'] );
		$this->assertSame( '12.35', $simple['woo:regular_price'], 'money rounds half away from zero with string math' );
		$this->assertSame( '12.35', $simple['woo:price'] );
		$this->assertSame( '', $simple['woo:sale_price'] );
		$this->assertSame( '', $simple['woo:date_on_sale_from'] );
		$this->assertSame( 'no', $simple['woo:manage_stock'] );
		$this->assertSame( 'shipping', $simple['woo:tax_status'] );
		$this->assertSame( 'reduced-rate', $simple['woo:tax_class'] );
		$this->assertSame( 'Short', $simple['woo:short_description'] );
		$this->assertSame( 'Long', $simple['woo:description'] );
		$this->assertSame( '', $simple['woo:attributes'] );
		$this->assertSame( 'OPEX Test Cat', $simple['woo:categories'] );
		$this->assertSame( '', $simple['woo:tags'] );
		$this->assertSame( 'no', $simple['woo:featured'] );
		$this->assertSame( 'hidden', $simple['woo:catalog_visibility'] );
		$this->assertSame( '7', $simple['woo:total_sales'] );
		$this->assertSame( '2020-01-15 10:00:00', $simple['woo:date_created'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $simple['woo:date_modified'] );

		$parent = $by['OPEX-VAR'];
		$this->assertSame( 'variable', $parent['woo:type'] );
		$this->assertSame( 'opex-variable', $parent['woo:slug'] );
		$this->assertSame( '10.00', $parent['woo:price'], 'a variable product shows its lowest price' );
		$this->assertSame( 'OPEX Tag', $parent['woo:tags'] );
		$this->assertSame( 'yes', $parent['woo:featured'] );
		$this->assertSame( 'catalog', $parent['woo:catalog_visibility'] );
		$this->assertSame( 'Parent text', $parent['woo:description'] );
		$this->assertSame( (string) wp_get_attachment_url( $this->ids['img1'] ), $parent['woo:image_url'] );
		$this->assertSame( wp_get_attachment_url( $this->ids['img2'] ) . ', ' . wp_get_attachment_url( $this->ids['img3'] ), $parent['woo:gallery_urls'] );
		$attributes = json_decode( $parent['woo:attributes'], true );
		$this->assertSame( array( 'OPEX Color', 'Size' ), array_keys( $attributes ) );
		sort( $attributes['OPEX Color'] );
		$this->assertSame( array( 'Blue', 'Red' ), $attributes['OPEX Color'] );
		$this->assertSame( array( 'Small', 'Large' ), $attributes['Size'] );
		$this->assertNotSame( '', $parent['woo:permalink'] );

		$red = $by['OPEX-VAR-RED'];
		$this->assertSame( 'variation', $red['woo:type'] );
		$this->assertSame( (string) $this->ids['variable'], $red['woo:parent_id'] );
		$this->assertSame( 'OPEX-VAR', $red['woo:parent_sku'] );
		$this->assertSame( '10.50', $red['woo:regular_price'] );
		$this->assertSame( '10.00', $red['woo:sale_price'], '9.995 rounds up, where a float would print 9.99' );
		$this->assertSame( '10.00', $red['woo:price'] );
		$this->assertSame( '2020-01-01 00:00:00', $red['woo:date_on_sale_from'] );
		$this->assertSame( '2030-12-31 23:59:59', $red['woo:date_on_sale_to'] );
		$this->assertSame( array( 'OPEX Color' => 'Red', 'Size' => 'Small' ), json_decode( $red['woo:attributes'], true ) );
		$this->assertSame( 'OPEX Test Cat', $red['woo:categories'], 'a variation takes its parent categories' );
		$this->assertSame( 'OPEX Tag', $red['woo:tags'] );
		$this->assertSame( 'yes', $red['woo:featured'] );
		$this->assertSame( 'catalog', $red['woo:catalog_visibility'] );
		$this->assertStringContainsString( 'attribute_pa_opexcolor=red', $red['woo:permalink'] );

		$blue = $by['OPEX-VAR-BLUE'];
		$this->assertSame( 'yes', $blue['woo:manage_stock'] );
		$this->assertSame( '5', $blue['woo:stock_quantity'] );
		$this->assertSame( '1.5', $blue['woo:weight'] );
		$this->assertSame( 'instock', $blue['woo:stock_status'] );
		$this->assertSame( 'Blue variation text', $blue['woo:description'] );
		$this->assertSame( '20.00', $blue['woo:price'] );
	}

	/** Rules on every kind of field, including term lists that a variation inherits. */
	public function test_filters(): void {
		$all = array( 'OPEX-SIMPLE', 'OPEX-VAR', 'OPEX-VAR-RED', 'OPEX-VAR-BLUE' );
		$var = array( 'OPEX-VAR', 'OPEX-VAR-RED', 'OPEX-VAR-BLUE' );
		$cases = array(
			array( 'woo:type', 'eq', 'variation', array( 'OPEX-VAR-RED', 'OPEX-VAR-BLUE' ) ),
			array( 'woo:type', 'in', 'simple,variable', array( 'OPEX-SIMPLE', 'OPEX-VAR' ) ),
			array( 'woo:type', 'neq', 'variation', array( 'OPEX-SIMPLE', 'OPEX-VAR' ) ),
			array( 'woo:price', 'gt', '15', array( 'OPEX-VAR', 'OPEX-VAR-BLUE' ) ),
			array( 'woo:regular_price', 'lte', '10.5', array( 'OPEX-VAR-RED' ) ),
			array( 'woo:featured', 'eq', 'yes', $var ),
			array( 'woo:featured', 'neq', 'yes', array( 'OPEX-SIMPLE' ) ),
			array( 'woo:categories', 'eq', 'OPEX Test Cat', $all ),
			array( 'woo:categories', 'in', 'opex-test-cat,nothing', $all ),
			array( 'woo:tags', 'contains', 'Tag', $var ),
			array( 'woo:tags', 'empty', '', array( 'OPEX-SIMPLE' ) ),
			array( 'woo:catalog_visibility', 'eq', 'hidden', array( 'OPEX-SIMPLE' ) ),
			array( 'woo:catalog_visibility', 'not_in', 'hidden,search', $var ),
			array( 'woo:manage_stock', 'eq', 'yes', array( 'OPEX-VAR-BLUE' ) ),
			array( 'woo:manage_stock', 'eq', 'no', array( 'OPEX-SIMPLE', 'OPEX-VAR', 'OPEX-VAR-RED' ) ),
			array( 'woo:date_on_sale_to', 'gte', '2030-12-31', array( 'OPEX-VAR-RED' ) ),
			array( 'woo:date_on_sale_from', 'eq', '2020-01-01', array( 'OPEX-VAR-RED' ) ),
			array( 'woo:date_on_sale_from', 'lt', '2020-01-01', array() ),
			array( 'woo:date_on_sale_to', 'not_empty', '', array( 'OPEX-VAR-RED' ) ),
			array( 'woo:date_created', 'lt', '2021-01-01', array( 'OPEX-SIMPLE' ) ),
			array( 'woo:status', 'eq', 'draft', array( 'OPEX-SIMPLE' ) ),
			array( 'woo:parent_sku', 'eq', 'OPEX-VAR', array( 'OPEX-VAR-RED', 'OPEX-VAR-BLUE' ) ),
			array( 'woo:parent_id', 'gt', '0', array( 'OPEX-VAR-RED', 'OPEX-VAR-BLUE' ) ),
			array( 'woo:description', 'contains', 'Blue variation', array( 'OPEX-VAR-BLUE' ) ),
			array( 'woo:name', 'starts_with', 'OPEX Variable', $var ),
			array( 'woo:stock_status', 'eq', 'instock', $all ),
			array( 'woo:sku', 'ends_with', '-RED', array( 'OPEX-VAR-RED' ) ),
			array( 'meta:_sku', 'eq', 'OPEX-SIMPLE', array( 'OPEX-SIMPLE' ) ),
		);
		foreach ( $cases as $case ) {
			list( $field, $op, $value, $expect ) = $case;
			$this->assertSame( $expect, $this->skus( array( 'field' => $field, 'op' => $op, 'value' => $value ) ), "$field $op $value" );
		}

		$nested = array(
			'relation' => 'OR',
			'rules'    => array(
				array( 'field' => 'woo:type', 'op' => 'eq', 'value' => 'simple' ),
				array( 'relation' => 'AND', 'rules' => array(
					array( 'field' => 'woo:type', 'op' => 'eq', 'value' => 'variation' ),
					array( 'field' => 'woo:manage_stock', 'op' => 'eq', 'value' => 'yes' ),
				) ),
			),
		);
		$this->assertSame( array( 'OPEX-SIMPLE', 'OPEX-VAR-BLUE' ), $this->skus( $nested ) );

		// A value built in PHP cannot be filtered, so the rule is refused.
		$refused = OPEX_Exporter::sanitize_config( array( 'source' => 'woo:products', 'fields' => array( 'woo:sku' ), 'filters' => array( 'rules' => array( array( 'field' => 'woo:attributes', 'op' => 'contains', 'value' => 'Red' ) ) ) ) );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'opex_bad_filter', $refused->get_error_code() );
	}

	/** Date ranges and since on columns and on timestamp meta. */
	public function test_date_range_and_since(): void {
		$only  = array( 'rules' => array( array( 'field' => 'woo:sku', 'op' => 'starts_with', 'value' => 'OPEX-' ) ) );
		$range = function ( string $field, string $from, string $to ): array {
			$config = $this->config( 'woo:products', array(
				'fields'     => array( 'woo:sku' ),
				'filters'    => array( 'rules' => array( array( 'field' => 'woo:sku', 'op' => 'starts_with', 'value' => 'OPEX-' ) ) ),
				'date_range' => array( 'field' => $field, 'preset' => 'custom', 'from' => $from, 'to' => $to ),
			) );
			return array_column( $this->rows( $config ), 'woo:sku' );
		};
		$this->assertSame( array( 'OPEX-SIMPLE' ), $range( 'woo:date_created', '2020-01-01', '2020-01-31' ) );
		$this->assertSame( array( 'OPEX-VAR-RED' ), $range( 'woo:date_on_sale_from', '2019-12-31', '2020-01-01' ) );
		$this->assertSame( array(), $range( 'woo:date_on_sale_to', '2020-01-01', '2020-12-31' ) );

		$job = $this->export( $this->config( 'woo:products', array( 'fields' => array( 'woo:sku' ), 'filters' => $only ) ), array( 'since' => array( 'field' => 'woo:date_created', 'after' => '2021-01-01 00:00:00' ) ) );
		$this->assertSame( 3, (int) $job['processed'] );
	}

	/** Every format, transforms, custom columns and preview. */
	public function test_formats(): void {
		$this->assert_all_formats(
			'woo:products',
			array( 'woo:sku', 'woo:price', 'woo:attributes', 'woo:categories', 'woo:date_on_sale_to' ),
			array( 'rules' => array( array( 'field' => 'woo:sku', 'op' => 'eq', 'value' => 'OPEX-VAR-RED' ) ) )
		);
	}

	/** The picker, the capability document and recipe import. */
	public function test_surface(): void {
		$this->assert_surface( 'woo:products', array(
			'woo:id'                 => 'id',
			'woo:type'               => 'enum',
			'woo:sku'                => 'text',
			'woo:price'              => 'number',
			'woo:featured'           => 'boolean',
			'woo:date_on_sale_from'  => 'date',
			'woo:image_url'          => 'url',
			'woo:attributes'         => 'text',
			'woo:catalog_visibility' => 'enum',
		) );
		$doc    = OPEX_Schema::build( 'woo:products' );
		$values = array();
		foreach ( $doc['sources'] as $entry ) {
			if ( 'woo:products' === $entry['id'] ) {
				$this->assertSame( 'posts', $entry['kind'] );
				$values = array_column( $entry['fields'], 'values', 'id' );
			}
		}
		$this->assertSame( array( 'simple', 'variable', 'variation', 'grouped', 'external' ), $values['woo:type'] );
	}
}
