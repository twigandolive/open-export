<?php
/**
 * The "WooCommerce product CSV" preset: products and variations with the
 * column names WooCommerce's own product CSV importer (Products, Import)
 * matches by itself, and values in the form it reads.
 *
 * Data only, like OPEX_Presets. The column names are WooCommerce's English
 * names, read from its importer's column map (WC_Product_CSV_Importer_Controller
 * auto_map_columns and wc_importer_default_english_mappings), so they stay in
 * English on every site, as WooCommerce's own exporter writes them. The weight
 * and size columns carry the store's units, as the importer expects.
 *
 * Values the importer reads differently from how the plugin stores them are
 * converted with transforms: Published is 1, 0, -1 or 2, yes and no become
 * 1 and 0, stock status becomes 1, 0 or "backorder", the parent is
 * "id:<number>", and Images is the main image followed by the gallery.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Builds the product importer preset.
 */
class OPEX_Preset_Product_Csv {

	/** The preset id. */
	const ID = 'woocommerce-product-csv';

	/** The custom column that joins the main image and the gallery. */
	const IMAGES = 'custom:images';

	/**
	 * The preset as { id, label, description, config }.
	 *
	 * @return array
	 */
	public static function preset(): array {
		return array(
			'id'          => self::ID,
			'label'       => __( 'WooCommerce product CSV (importer columns)', 'open-export' ),
			'description' => __( 'A CSV file that WooCommerce\'s own product importer (Products, Import) reads, with its column names: ID, Type, SKU, Name, Published, featured, catalog visibility, both descriptions, sale dates, tax, stock, weight and size, reviews, purchase note, prices, categories, tags, shipping class, images, parent, external URL, button text and position, for every product and variation that is not in the trash.', 'open-export' )
				. ' ' . __( 'The GTIN, UPC, EAN or ISBN column is included, but the importer may not match it by itself: if it shows "Do not import", choose "GTIN, UPC, EAN, or ISBN" for it once.', 'open-export' )
				. ' ' . __( 'Not included because the plugin has no field in the form the importer reads: attributes, the virtual and downloadable flags, downloads, upsells, cross-sells, grouped products, download limits and other meta. Categories are written by name, so a subcategory comes back as a top-level category of the same name.', 'open-export' ),
			'config'      => self::config(),
		);
	}

	/**
	 * The export config, already in the shape sanitize_config returns.
	 *
	 * @return array
	 */
	public static function config(): array {
		$columns = self::columns();
		return array(
			'source'         => 'woo:products',
			'fields'         => array_keys( $columns ),
			'filters'        => array(
				'relation' => 'AND',
				'rules'    => array(
					array(
						'field' => 'woo:status',
						'op'    => 'neq',
						'value' => 'trash',
					),
				),
			),
			'date_range'     => array(
				'field'  => '',
				'preset' => 'all_time',
				'from'   => '',
				'to'     => '',
			),
			'format'         => 'csv',
			'batch_size'     => 500,
			'csv_delimiter'  => 'comma',
			'column_labels'  => array_diff_key( $columns, array( self::IMAGES => true ) ),
			'transforms'     => self::transforms(),
			'custom_columns' => array(
				array(
					'id'       => self::IMAGES,
					'label'    => 'Images',
					'template' => '{woo:image_url}, {woo:gallery_urls}',
				),
			),
		);
	}

	/**
	 * Field id => importer column name, in the importer's own order.
	 *
	 * @return array<string, string>
	 */
	public static function columns(): array {
		$weight = (string) get_option( 'woocommerce_weight_unit', 'kg' );
		$size   = (string) get_option( 'woocommerce_dimension_unit', 'cm' );
		$out    = array(
			'woo:id'                     => 'ID',
			'woo:type'                   => 'Type',
			'woo:sku'                    => 'SKU',
			'woo:global_unique_id'       => 'GTIN, UPC, EAN, or ISBN',
			'woo:name'                   => 'Name',
			'woo:status'                 => 'Published',
			'woo:featured'               => 'Is featured?',
			'woo:catalog_visibility'     => 'Visibility in catalog',
			'woo:short_description'      => 'Short description',
			'woo:description'            => 'Description',
			'woo:date_on_sale_from'      => 'Date sale price starts',
			'woo:date_on_sale_to'        => 'Date sale price ends',
			'woo:tax_status'             => 'Tax status',
			'woo:tax_class'              => 'Tax class',
			'woo:stock_status'           => 'In stock?',
			'woo:stock_quantity'         => 'Stock',
			'meta:_backorders'           => 'Backorders allowed?',
			'meta:_low_stock_amount'     => 'Low stock amount',
			'meta:_sold_individually'    => 'Sold individually?',
			'woo:weight'                 => sprintf( 'Weight (%s)', $weight ),
			'woo:length'                 => sprintf( 'Length (%s)', $size ),
			'woo:width'                  => sprintf( 'Width (%s)', $size ),
			'woo:height'                 => sprintf( 'Height (%s)', $size ),
			'core:comment_status'        => 'Allow customer reviews?',
			'meta:_purchase_note'        => 'Purchase note',
			'woo:sale_price'             => 'Sale price',
			'woo:regular_price'          => 'Regular price',
			'woo:categories'             => 'Categories',
			'woo:tags'                   => 'Tags',
			'tax:product_shipping_class' => 'Shipping class',
			self::IMAGES                 => 'Images',
			'woo:parent_id'              => 'Parent',
			'meta:_product_url'          => 'External URL',
			'meta:_button_text'          => 'Button text',
			'core:menu_order'            => 'Position',
		);
		if ( taxonomy_exists( 'product_brand' ) ) {
			$out['tax:product_brand'] = 'Brands';
		}
		return $out;
	}

	/**
	 * Steps that turn stored values into the importer's values.
	 *
	 * @return array
	 */
	private static function transforms(): array {
		$yes_no = array(
			array(
				'type'  => 'map',
				'pairs' => array(
					'yes' => '1',
					'no'  => '0',
				),
			),
		);
		return array(
			'woo:status'              => array(
				array(
					'type'     => 'map',
					'pairs'    => array(
						'publish' => '1',
						'private' => '0',
						'pending' => '2',
					),
					// Draft, scheduled and anything else import as a draft, as WooCommerce's own exporter writes them.
					'fallback' => '-1',
				),
			),
			'woo:featured'            => $yes_no,
			'woo:stock_status'        => array(
				array(
					'type'  => 'map',
					'pairs' => array(
						'instock'     => '1',
						'outofstock'  => '0',
						'onbackorder' => 'backorder',
					),
				),
			),
			// "notify" passes through unchanged, which the importer also reads.
			'meta:_backorders'        => $yes_no,
			'meta:_sold_individually' => $yes_no,
			'core:comment_status'     => array(
				array(
					'type'  => 'map',
					'pairs' => array(
						'open'   => '1',
						'closed' => '0',
					),
				),
			),
			// The importer reads a bare number as a SKU, so the parent is written as id:<number>.
			'woo:parent_id'           => array(
				array(
					'type' => 'prefix',
					'text' => 'id:',
				),
				array(
					'type'  => 'map',
					'pairs' => array( 'id:0' => '' ),
				),
			),
			// With no images at all the template gives ", ". These steps make that an empty cell, which the importer leaves alone.
			self::IMAGES              => array(
				array( 'type' => 'trim' ),
				array(
					'type'  => 'map',
					'pairs' => array( ',' => '' ),
				),
			),
		);
	}
}
