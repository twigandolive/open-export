<?php
/**
 * Feed presets: each is a clean config and produces a real feed with at
 * least one product from the seeded store.
 *
 * Fixtures: one published simple product with a price, made in setUp through
 * the WooCommerce product API and removed in tearDown, so the feed has a row
 * whatever else the store holds.
 *
 * @package Open_Export
 */

require_once __DIR__ . '/WooSourceCase.php';

/**
 * Google Merchant Center, Meta catalog and Pinterest presets.
 */
class WooPresetsTest extends WooSourceCase {

	/** @var int Fixture product id. */
	private $product = 0;

	/** Makes the product. */
	protected function setUp(): void {
		parent::setUp();
		$product = new WC_Product_Simple();
		$product->set_props(
			array(
				'name'          => 'OPEX Feed <b>Lamp</b>',
				'status'        => 'publish',
				'regular_price' => '12.5',
				'description'   => '<p>A lamp &amp; a shade.</p>',
				'stock_status'  => 'onbackorder',
			)
		);
		$this->product = (int) $product->save();
	}

	/** Removes the product. */
	protected function tearDown(): void {
		$product = wc_get_product( $this->product );
		if ( $product ) {
			$product->delete( true );
		}
		parent::tearDown();
	}

	/**
	 * The presets by id.
	 *
	 * @return array
	 */
	private function presets(): array {
		return array_column( OPEX_Presets::all(), null, 'id' );
	}

	/** Each preset passes sanitize_config unchanged. */
	public function test_each_preset_is_a_clean_config(): void {
		$presets = $this->presets();
		$this->assertSame( array( 'google-merchant', 'meta-catalog', 'pinterest-catalog' ), array_keys( $presets ) );
		foreach ( $presets as $id => $preset ) {
			$notes = array();
			$this->assertSame( $preset['config'], OPEX_Exporter::sanitize_config( $preset['config'], $notes ), $id );
			$this->assertSame( array(), $notes, $id );
			$this->assertNotSame( '', $preset['label'] );
			$this->assertStringContainsString( 'condition', $preset['description'], 'the missing attribute is named' );
		}
		$this->assertSame( 'xml', $presets['google-merchant']['config']['format'] );
		$this->assertSame( 'csv', $presets['meta-catalog']['config']['format'] );
		$this->assertSame( 'xml', $presets['pinterest-catalog']['config']['format'] );
	}

	/**
	 * The fixture product's item in an XML feed.
	 *
	 * @param string $id Preset id.
	 * @return array<string, string> Element name => text.
	 */
	private function xml_item( string $id ): array {
		$job = $this->export( $this->presets()[ $id ]['config'] );
		$doc = new DOMDocument();
		$this->assertTrue( $doc->loadXML( (string) file_get_contents( OPEX_Jobs::file_path( $job ) ), LIBXML_NONET ), 'the feed is well-formed XML' );
		$this->assertSame( 'rss', $doc->documentElement->nodeName );
		$items = $doc->getElementsByTagName( 'item' );
		$this->assertGreaterThanOrEqual( 1, $items->length );
		foreach ( $items as $item ) {
			$values = array();
			foreach ( $item->childNodes as $node ) {
				if ( XML_ELEMENT_NODE === $node->nodeType ) {
					$values[ $node->nodeName ] = $node->textContent;
				}
			}
			if ( (string) $this->product === ( $values['g:id'] ?? '' ) ) {
				return $values;
			}
		}
		$this->fail( 'The fixture product is not in the feed.' );
	}

	/** Google Merchant Center: Google's attribute names and values. */
	public function test_google_feed(): void {
		$item     = $this->xml_item( 'google-merchant' );
		$currency = get_woocommerce_currency();
		$this->assertSame( 'OPEX Feed Lamp', $item['g:title'], 'HTML is stripped' );
		$this->assertSame( 'A lamp & a shade.', $item['g:description'] );
		$this->assertSame( get_permalink( $this->product ), $item['g:link'] );
		$this->assertArrayHasKey( 'g:image_link', $item );
		$this->assertSame( 'backorder', $item['g:availability'] );
		$this->assertSame( '12.50 ' . $currency, $item['g:price'] );
		$this->assertArrayNotHasKey( 'g:condition', $item, 'no field holds condition' );
	}

	/** Pinterest: the Google shape with Pinterest's availability words. */
	public function test_pinterest_feed(): void {
		$item = $this->xml_item( 'pinterest-catalog' );
		$this->assertSame( 'preorder', $item['g:availability'] );
		$this->assertSame( 'OPEX Feed Lamp', $item['g:title'] );
	}

	/** Meta: a CSV with Meta's column names and values. */
	public function test_meta_feed(): void {
		$job  = $this->export( $this->presets()['meta-catalog']['config'] );
		$body = (string) file_get_contents( OPEX_Jobs::file_path( $job ) );
		$this->assertStringStartsNotWith( "\xEF\xBB\xBF", $body, 'no byte order mark before "id"' );
		$lines  = array_map(
			static function ( string $line ): array {
				return str_getcsv( $line, ',', '"', '' );
			},
			array_filter( explode( "\n", $body ), 'strlen' )
		);
		$header = array_shift( $lines );
		$expect = array( 'id', 'title', 'description', 'link', 'image_link', 'availability', 'price' );
		$this->assertSame( $expect, array_slice( $header, 0, 7 ) );
		$this->assertGreaterThanOrEqual( 1, count( $lines ) );
		$rows = array();
		foreach ( $lines as $line ) {
			$rows[ $line[0] ] = array_combine( $header, $line );
		}
		$row = $rows[ (string) $this->product ];
		$this->assertSame( 'available for order', $row['availability'] );
		$this->assertSame( '12.50 ' . get_woocommerce_currency(), $row['price'] );
	}

	/** Only products a shopper buys one by one: no variable parents, no drafts. */
	public function test_feed_filters(): void {
		$draft = new WC_Product_Simple();
		$draft->set_props( array( 'name' => 'OPEX Feed Draft', 'status' => 'draft', 'regular_price' => '3' ) );
		$draft_id = (int) $draft->save();
		try {
			$job  = $this->export( $this->presets()['meta-catalog']['config'] );
			$body = (string) file_get_contents( OPEX_Jobs::file_path( $job ) );
			$this->assertStringNotContainsString( 'OPEX Feed Draft', $body );
			$this->assertStringContainsString( 'OPEX Feed Lamp', $body );
		} finally {
			$draft->delete( true );
		}
		$this->assertGreaterThan( 0, $draft_id );
	}
}
