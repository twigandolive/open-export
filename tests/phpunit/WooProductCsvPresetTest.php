<?php
/**
 * The "WooCommerce product CSV" preset, read back by WooCommerce's own
 * importer code, and the GTIN and item group columns of the feed presets.
 *
 * Fixtures, made in setUp through the WooCommerce product API and removed in
 * tearDown: a category, a tag, two image attachments, a published simple
 * product with a GTIN, a private product, a draft, a trashed product, and a
 * variable product with one variation. Every SKU starts with OPEX-CSV-.
 *
 * The importer only parses the file here. Nothing is imported.
 *
 * @package Open_Export
 */

require_once __DIR__ . '/WooSourceCase.php';

/**
 * Product CSV preset and feed GTINs.
 */
class WooProductCsvPresetTest extends WooSourceCase {

	/** @var array<string, int> Fixture ids. */
	private $ids = array();

	/**
	 * A copy of the export under its downloaded .csv name, removed in tearDown.
	 *
	 * @var string
	 */
	private $import = '';

	/** Makes the fixtures. */
	protected function setUp(): void {
		parent::setUp();
		$cat              = wp_insert_term( 'OPEX CSV Cat', 'product_cat' );
		$tag              = wp_insert_term( 'OPEX CSV Tag', 'product_tag' );
		$this->ids['cat'] = (int) $cat['term_id'];
		$this->ids['tag'] = (int) $tag['term_id'];
		foreach ( array( 'img1', 'img2' ) as $name ) {
			$this->ids[ $name ] = (int) wp_insert_attachment( array( 'post_title' => $name, 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ), 'opex-csv/' . $name . '.jpg' );
		}

		$simple = new WC_Product_Simple();
		$simple->set_props(
			array(
				'name'              => 'OPEX CSV Simple',
				'sku'               => 'OPEX-CSV-S',
				'status'            => 'publish',
				'featured'          => true,
				'regular_price'     => '12.5',
				'sale_price'        => '10',
				'manage_stock'      => true,
				'stock_quantity'    => 3,
				'backorders'        => 'notify',
				'sold_individually' => true,
				'weight'            => '1.5',
				'reviews_allowed'   => false,
				'purchase_note'     => 'Thanks',
				'menu_order'        => 4,
				'category_ids'      => array( $this->ids['cat'] ),
				'tag_ids'           => array( $this->ids['tag'] ),
				'image_id'          => $this->ids['img1'],
				'gallery_image_ids' => array( $this->ids['img2'] ),
			)
		);
		if ( method_exists( $simple, 'set_global_unique_id' ) ) {
			$simple->set_global_unique_id( '012345678905' );
		}
		$this->ids['simple'] = (int) $simple->save();

		foreach ( array( 'private', 'draft', 'trash' ) as $status ) {
			$other = new WC_Product_Simple();
			$other->set_props(
				array(
					'name'          => 'OPEX CSV ' . $status,
					'sku'           => 'OPEX-CSV-' . strtoupper( $status ),
					'status'        => $status,
					'regular_price' => '3',
				)
			);
			$this->ids[ $status ] = (int) $other->save();
		}

		$parent = new WC_Product_Variable();
		$parent->set_props(
			array(
				'name'   => 'OPEX CSV Variable',
				'sku'    => 'OPEX-CSV-P',
				'status' => 'publish',
			)
		);
		$size = new WC_Product_Attribute();
		$size->set_name( 'Size' );
		$size->set_options( array( 'Small' ) );
		$size->set_variation( true );
		$parent->set_attributes( array( $size ) );
		$this->ids['variable'] = (int) $parent->save();
		$variation             = new WC_Product_Variation();
		$variation->set_props(
			array(
				'parent_id'     => $this->ids['variable'],
				'sku'           => 'OPEX-CSV-V1',
				'regular_price' => '7',
			)
		);
		$variation->set_attributes( array( 'size' => 'Small' ) );
		if ( method_exists( $variation, 'set_global_unique_id' ) ) {
			$variation->set_global_unique_id( '4006381333931' );
		}
		$this->ids['variation'] = (int) $variation->save();
		WC_Product_Variable::sync( $this->ids['variable'] );
	}

	/** Deletes the fixtures. */
	protected function tearDown(): void {
		foreach ( array( 'variation', 'variable', 'simple', 'private', 'draft', 'trash' ) as $name ) {
			$product = isset( $this->ids[ $name ] ) ? wc_get_product( $this->ids[ $name ] ) : null;
			if ( $product ) {
				$product->delete( true );
			}
		}
		foreach ( array( 'img1', 'img2' ) as $name ) {
			wp_delete_post( $this->ids[ $name ] ?? 0, true );
		}
		wp_delete_term( $this->ids['cat'] ?? 0, 'product_cat' );
		wp_delete_term( $this->ids['tag'] ?? 0, 'product_tag' );
		$this->ids = array();
		if ( '' !== $this->import && file_exists( $this->import ) ) {
			unlink( $this->import );
		}
		$this->import = '';
		parent::tearDown();
	}

	/**
	 * A preset's config with one more rule: only the fixtures.
	 *
	 * @param string $id Preset id.
	 */
	private function fixture_config( string $id ): array {
		$presets = array_column( OPEX_Presets::all(), null, 'id' );
		$config  = $presets[ $id ]['config'];

		$config['filters']['rules'][] = array(
			'field' => 'woo:sku',
			'op'    => 'starts_with',
			'value' => 'OPEX-CSV-',
		);
		return $config;
	}

	/**
	 * Reads a CSV file as a spreadsheet does.
	 *
	 * @param string $path File.
	 * @return array[] Rows, the first is the header.
	 */
	private function csv( string $path ): array {
		$handle = fopen( $path, 'rb' );
		$rows   = array();
		while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) ) {
			$rows[] = $row;
		}
		fclose( $handle );
		$rows[0][0] = preg_replace( '/^\xEF\xBB\xBF/', '', $rows[0][0] );
		return $rows;
	}

	/** Loads WooCommerce's importer classes, which only load in the admin. */
	private function load_importer(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-importer.php';
		require_once WC_ABSPATH . 'includes/import/class-wc-product-csv-importer.php';
		$this->assertTrue( class_exists( 'WC_Product_CSV_Importer_Controller' ) );
	}

	/**
	 * Calls a protected method of WooCommerce's importer controller.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private function controller( string $method, array $args ) {
		$class  = new ReflectionClass( 'WC_Product_CSV_Importer_Controller' );
		$object = $class->newInstanceWithoutConstructor();
		$call   = $class->getMethod( $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$call->setAccessible( true );
		}
		return $call->invokeArgs( $object, $args );
	}

	/** The preset is listed, is a clean config, and names what it leaves out. */
	public function test_preset_is_clean(): void {
		$presets = array_column( OPEX_Presets::all(), null, 'id' );
		$preset  = $presets['woocommerce-product-csv'];
		$notes   = array();
		$this->assertSame( $preset['config'], OPEX_Exporter::sanitize_config( $preset['config'], $notes ) );
		$this->assertSame( array(), $notes );
		$this->assertSame( 'csv', $preset['config']['format'] );
		$this->assertSame( 'woo:products', $preset['config']['source'] );
		$this->assertStringContainsString( 'attributes', $preset['description'] );
		$this->assertSame( 'WooCommerce product CSV (importer columns)', $preset['label'] );
	}

	/** Every column name is one WooCommerce's importer maps by itself, except GTIN, which its mapping list offers. */
	public function test_header_row_is_what_the_importer_reads(): void {
		$this->load_importer();
		$job    = $this->export( $this->fixture_config( 'woocommerce-product-csv' ) );
		$header = $this->csv( OPEX_Jobs::file_path( $job ) )[0];
		$this->assertSame( array_values( OPEX_Preset_Product_Csv::columns() ), $header );

		$expect = array(
			'ID'                      => 'id',
			'Type'                    => 'type',
			'SKU'                     => 'sku',
			'Name'                    => 'name',
			'Published'               => 'published',
			'Is featured?'            => 'featured',
			'Visibility in catalog'   => 'catalog_visibility',
			'Short description'       => 'short_description',
			'Description'             => 'description',
			'Date sale price starts'  => 'date_on_sale_from',
			'Date sale price ends'    => 'date_on_sale_to',
			'Tax status'              => 'tax_status',
			'Tax class'               => 'tax_class',
			'In stock?'               => 'stock_status',
			'Stock'                   => 'stock_quantity',
			'Backorders allowed?'     => 'backorders',
			'Low stock amount'        => 'low_stock_amount',
			'Sold individually?'      => 'sold_individually',
			'Allow customer reviews?' => 'reviews_allowed',
			'Purchase note'           => 'purchase_note',
			'Sale price'              => 'sale_price',
			'Regular price'           => 'regular_price',
			'Categories'              => 'category_ids',
			'Tags'                    => 'tag_ids',
			'Shipping class'          => 'shipping_class_id',
			'Images'                  => 'images',
			'Parent'                  => 'parent_id',
			'External URL'            => 'product_url',
			'Button text'             => 'button_text',
			'Position'                => 'menu_order',
		);
		$mapped = $this->controller( 'auto_map_columns', array( $header, false ) );
		foreach ( $expect as $name => $key ) {
			$this->assertSame( $key, $mapped[ $name ] ?? null, $name );
		}
		foreach ( array( 'Weight' => 'weight', 'Length' => 'length', 'Width' => 'width', 'Height' => 'height' ) as $start => $key ) {
			$found = preg_grep( '/^' . $start . ' \(/', $header );
			$this->assertCount( 1, $found, $start );
			$this->assertSame( $key, $mapped[ reset( $found ) ], $start . ' carries the store unit' );
		}
		$options = $this->controller( 'get_mapping_options', array( '' ) );
		$this->assertSame( 'GTIN, UPC, EAN, or ISBN', $options['global_unique_id'] );
		$this->assertContains( 'GTIN, UPC, EAN, or ISBN', $header );
		if ( in_array( 'Brands', $header, true ) ) {
			require_once WC_ABSPATH . 'includes/admin/class-wc-admin-brands.php';
			$brands = ( new ReflectionClass( 'WC_Brands_Admin' ) )->newInstanceWithoutConstructor();
			$this->assertSame( 'brand_ids', $brands->add_default_column_mapping( array() )['Brands'] );
		}
	}

	/** WooCommerce's importer parses the file into the values the products hold. */
	public function test_importer_reads_the_values_back(): void {
		$this->load_importer();
		$job    = $this->export( $this->fixture_config( 'woocommerce-product-csv' ) );
		// A person imports the file they downloaded, which ends in .csv. The
		// importer refuses the copy on disk, whose name ends in .opex.
		$this->import = trailingslashit( get_temp_dir() ) . 'opex-import-' . wp_generate_password( 8, false, false ) . '.csv';
		$this->assertTrue( copy( OPEX_Jobs::file_path( $job ), $this->import ) );
		$file   = $this->import;
		$header = $this->csv( $file )[0];
		$map    = $this->controller( 'auto_map_columns', array( $header, false ) );
		// What a person picks once on the mapping screen. Brands is skipped here.
		$map['GTIN, UPC, EAN, or ISBN'] = 'global_unique_id';
		$map['Brands']                  = '';
		$importer                       = new WC_Product_CSV_Importer(
			$file,
			array(
				'mapping'         => $map,
				'parse'           => true,
				'update_existing' => true,
			)
		);
		$rows                           = array_column( $importer->get_parsed_data(), null, 'sku' );
		$this->assertSame( array( 'OPEX-CSV-DRAFT', 'OPEX-CSV-P', 'OPEX-CSV-PRIVATE', 'OPEX-CSV-S', 'OPEX-CSV-V1' ), $this->sorted( array_keys( $rows ) ), 'the trashed product is left out' );

		$s = $rows['OPEX-CSV-S'];
		$this->assertSame( $this->ids['simple'], $s['id'] );
		$this->assertSame( 'simple', $s['type'] );
		$this->assertSame( 'publish', $s['status'] );
		$this->assertTrue( $s['featured'] );
		$this->assertSame( 'visible', $s['catalog_visibility'] );
		$this->assertSame( 'instock', $s['stock_status'] );
		$this->assertTrue( $s['manage_stock'] );
		$this->assertEquals( 3, $s['stock_quantity'] );
		$this->assertSame( 'notify', $s['backorders'] );
		$this->assertTrue( $s['sold_individually'] );
		$this->assertFalse( $s['reviews_allowed'] );
		$this->assertSame( 'Thanks', $s['purchase_note'] );
		$this->assertSame( '12.50', $s['regular_price'] );
		$this->assertSame( '10.00', $s['sale_price'] );
		$this->assertEquals( 1.5, $s['weight'] );
		$this->assertSame( array( $this->ids['cat'] ), array_map( 'intval', $s['category_ids'] ) );
		$this->assertSame( array( $this->ids['tag'] ), array_map( 'intval', $s['tag_ids'] ) );
		$this->assertSame( wp_get_attachment_url( $this->ids['img1'] ), $s['raw_image_id'] );
		$this->assertSame( array( wp_get_attachment_url( $this->ids['img2'] ) ), array_values( $s['raw_gallery_image_ids'] ) );
		$this->assertSame( '4', (string) $s['menu_order'] );
		if ( method_exists( 'WC_Product', 'get_global_unique_id' ) ) {
			$this->assertSame( '012345678905', $s['global_unique_id'] );
		}

		$this->assertSame( 'private', $rows['OPEX-CSV-PRIVATE']['status'] );
		$this->assertTrue( empty( $rows['OPEX-CSV-PRIVATE']['raw_image_id'] ), 'no images: the cell is empty' );
		$this->assertSame( 'draft', $rows['OPEX-CSV-DRAFT']['status'] );

		$v = $rows['OPEX-CSV-V1'];
		$this->assertSame( 'variation', $v['type'] );
		$this->assertSame( $this->ids['variable'], (int) $v['parent_id'], 'the parent is read by id' );
		$this->assertSame( 'publish', $v['status'] );
		$this->assertSame( 'variable', $rows['OPEX-CSV-P']['type'] );
		$this->assertTrue( empty( $rows['OPEX-CSV-P']['parent_id'] ) );
	}

	/** The product field reads _global_unique_id and has a label people know. */
	public function test_gtin_field(): void {
		$labels = OPEX_Catalog::field_ids( 'woo:products' );
		$this->assertSame( 'GTIN, UPC, EAN, or ISBN', $labels['woo:global_unique_id'] );
		update_post_meta( $this->ids['draft'], '_global_unique_id', '9780306406157' );
		$values = $this->pluck(
			'woo:products',
			'woo:global_unique_id',
			array(
				'rules' => array(
					array(
						'field' => 'woo:sku',
						'op'    => 'eq',
						'value' => 'OPEX-CSV-DRAFT',
					),
				),
			)
		);
		$this->assertSame( array( '9780306406157' ), $values );
	}

	/** The Meta catalog gains gtin and item_group_id: the parent for a variation, empty for a simple product. */
	public function test_meta_feed_gtin_and_group(): void {
		$job    = $this->export( $this->fixture_config( 'meta-catalog' ) );
		$rows   = $this->csv( OPEX_Jobs::file_path( $job ) );
		$header = array_shift( $rows );
		$this->assertSame( array( 'id', 'title', 'description', 'link', 'image_link', 'availability', 'price', 'gtin', 'item_group_id' ), array_slice( $header, 0, 9 ) );
		$by_id = array();
		foreach ( $rows as $row ) {
			$by_id[ (int) $row[0] ] = array_combine( array_slice( $header, 0, count( $row ) ), $row );
		}
		$this->assertSame( '', $by_id[ $this->ids['simple'] ]['item_group_id'] );
		$this->assertSame( (string) $this->ids['variable'], $by_id[ $this->ids['variation'] ]['item_group_id'] );
		if ( method_exists( 'WC_Product', 'get_global_unique_id' ) ) {
			$this->assertSame( '012345678905', $by_id[ $this->ids['simple'] ]['gtin'] );
			$this->assertSame( '4006381333931', $by_id[ $this->ids['variation'] ]['gtin'] );
		}
		$this->assertArrayNotHasKey( $this->ids['variable'], $by_id, 'the variable parent itself is not a feed item' );
	}

	/** The Google and Pinterest feeds gain g:gtin and g:item_group_id. */
	public function test_xml_feeds_gtin_and_group(): void {
		foreach ( array( 'google-merchant', 'pinterest-catalog' ) as $id ) {
			$job = $this->export( $this->fixture_config( $id ) );
			$doc = new DOMDocument();
			$this->assertTrue( $doc->loadXML( (string) file_get_contents( OPEX_Jobs::file_path( $job ) ), LIBXML_NONET ) );
			$items = array();
			foreach ( $doc->getElementsByTagName( 'item' ) as $item ) {
				$values = array();
				foreach ( $item->childNodes as $node ) {
					if ( XML_ELEMENT_NODE === $node->nodeType ) {
						$values[ $node->nodeName ] = $node->textContent;
					}
				}
				$items[ (int) $values['g:id'] ] = $values;
			}
			$this->assertSame( '', $items[ $this->ids['simple'] ]['g:item_group_id'], $id );
			$this->assertSame( (string) $this->ids['variable'], $items[ $this->ids['variation'] ]['g:item_group_id'], $id );
			if ( method_exists( 'WC_Product', 'get_global_unique_id' ) ) {
				$this->assertSame( '4006381333931', $items[ $this->ids['variation'] ]['g:gtin'], $id );
			}
		}
	}

	/**
	 * A sorted copy.
	 *
	 * @param array $list Values.
	 */
	private function sorted( array $list ): array {
		sort( $list );
		return $list;
	}
}
