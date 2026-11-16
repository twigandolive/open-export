<?php
/**
 * Product feed presets: complete export configs for Google Merchant Center,
 * Meta catalogs and Pinterest catalogs, all on the woo:products source.
 *
 * Data only. Each preset is already in the shape sanitize_config returns, so
 * applying one and saving it changes nothing. The few values that come from
 * the store (its name, address and currency) are read when the list is built.
 *
 * What the plugin has no field for is left out and named in the description:
 * condition (no product field holds it, and guessing "new" would be inventing
 * data) and, when the store has no brand taxonomy, brand.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * The feed presets.
 */
class OPEX_Presets {

	/** Google's product namespace, used by the Google and Pinterest XML feeds. */
	const GOOGLE_NS = 'http://base.google.com/ns/1.0';

	/**
	 * Every preset as { id, label, description, config }. Empty when
	 * WooCommerce is not active, because all of them read woo:products.
	 *
	 * @return array[]
	 */
	public static function all(): array {
		if ( ! OPEX_Woo::is_active() || ! OPEX_Query::parse_source( 'woo:products' ) ) {
			return array();
		}
		$brand = taxonomy_exists( 'product_brand' );
		$gaps  = $brand
			? __( 'Not included because no product field holds it: condition. Brand comes from the WooCommerce Brands taxonomy, which variations do not carry.', 'open-export' )
			: __( 'Not included because no product field holds them: brand and condition.', 'open-export' );

		return array(
			array(
				'id'          => 'google-merchant',
				'label'       => __( 'Google Merchant Center', 'open-export' ),
				'description' => __( 'An RSS 2.0 product feed in the Google format: id, title, description, link, image link, availability and price, for published simple, external and variation products.', 'open-export' ) . ' ' . $gaps,
				'config'      => self::xml_feed(
					array(
						'instock'     => 'in_stock',
						'outofstock'  => 'out_of_stock',
						'onbackorder' => 'backorder',
					),
					150,
					5000,
					$brand
				),
			),
			array(
				'id'          => 'meta-catalog',
				'label'       => __( 'Meta catalog (Facebook and Instagram)', 'open-export' ),
				'description' => __( 'A CSV data feed with the Meta column names: id, title, description, availability, price, link and image_link, for published simple, external and variation products.', 'open-export' ) . ' ' . $gaps,
				'config'      => self::meta_feed( $brand ),
			),
			array(
				'id'          => 'pinterest-catalog',
				'label'       => __( 'Pinterest catalog', 'open-export' ),
				'description' => __( 'An RSS 2.0 feed in the Google shape, which Pinterest reads: id, title, description, link, image link, availability and price. Pinterest has no backorder value, so backordered products are listed as preorder.', 'open-export' ) . ' ' . $gaps,
				'config'      => self::xml_feed(
					array(
						'instock'     => 'in stock',
						'outofstock'  => 'out of stock',
						'onbackorder' => 'preorder',
					),
					500,
					10000,
					$brand
				),
			),
		);
	}

	/**
	 * The product fields every feed reads, with brand when the store has it.
	 *
	 * @param bool $brand Whether the brand taxonomy exists.
	 * @return string[]
	 */
	private static function fields( bool $brand ): array {
		$fields = array( 'woo:id', 'woo:name', 'woo:description', 'woo:permalink', 'woo:image_url', 'woo:stock_status', 'woo:price' );
		if ( $brand ) {
			$fields[] = 'tax:product_brand';
		}
		return $fields;
	}

	/**
	 * Published products a shopper can buy one by one. A variable product's
	 * own row is left out because each variation is listed, and a grouped
	 * product has no price of its own.
	 *
	 * @return array
	 */
	private static function filters(): array {
		return array(
			'relation' => 'AND',
			'rules'    => array(
				array(
					'field' => 'woo:status',
					'op'    => 'eq',
					'value' => 'publish',
				),
				array(
					'field' => 'woo:type',
					'op'    => 'in',
					'value' => 'simple,external,variation',
				),
				array(
					'field' => 'woo:catalog_visibility',
					'op'    => 'neq',
					'value' => 'hidden',
				),
			),
		);
	}

	/**
	 * Steps shared by every feed: plain-text title and description within the
	 * platform's length, availability in its words, price with the currency code.
	 *
	 * @param array $availability WooCommerce stock status => platform value.
	 * @param int   $title_max    Longest title the platform accepts.
	 * @param int   $text_max     Longest description the platform accepts.
	 * @return array
	 */
	private static function transforms( array $availability, int $title_max, int $text_max ): array {
		$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
		$out      = array(
			'woo:name'         => array(
				array( 'type' => 'strip_html' ),
				array(
					'type'   => 'truncate',
					'length' => $title_max,
				),
			),
			'woo:description'  => array(
				array( 'type' => 'strip_html' ),
				array( 'type' => 'trim' ),
				array(
					'type'   => 'truncate',
					'length' => $text_max,
				),
			),
			'woo:stock_status' => array(
				array(
					'type'  => 'map',
					'pairs' => $availability,
				),
			),
		);
		if ( '' !== $currency ) {
			$out['woo:price'] = array(
				array(
					'type' => 'suffix',
					'text' => ' ' . $currency,
				),
			);
		}
		return $out;
	}

	/**
	 * Column names, which the XML feeds keep for anyone who switches the format to CSV.
	 *
	 * @param bool $brand Whether brand is in the field list.
	 * @return array
	 */
	private static function labels( bool $brand ): array {
		$labels = array(
			'woo:id'           => 'id',
			'woo:name'         => 'title',
			'woo:description'  => 'description',
			'woo:permalink'    => 'link',
			'woo:image_url'    => 'image_link',
			'woo:stock_status' => 'availability',
			'woo:price'        => 'price',
		);
		if ( $brand ) {
			$labels['tax:product_brand'] = 'brand';
		}
		return $labels;
	}

	/**
	 * An XML feed in Google's RSS 2.0 shape.
	 *
	 * @param array $availability Stock status map.
	 * @param int   $title_max    Title length limit.
	 * @param int   $text_max     Description length limit.
	 * @param bool  $brand        Whether to include brand.
	 * @return array
	 */
	private static function xml_feed( array $availability, int $title_max, int $text_max, bool $brand ): array {
		$row                    = "<item>\n"
			. "<g:id>{woo:id}</g:id>\n"
			. "<g:title>{woo:name}</g:title>\n"
			. "<g:description>{woo:description}</g:description>\n"
			. "<g:link>{woo:permalink}</g:link>\n"
			. "<g:image_link>{woo:image_url}</g:image_link>\n"
			. "<g:availability>{woo:stock_status}</g:availability>\n"
			. "<g:price>{woo:price}</g:price>\n"
			. ( $brand ? "<g:brand>{tax:product_brand}</g:brand>\n" : '' )
			. '</item>';
		$config                 = self::base( 'xml', $brand );
		$config['xml_template'] = array(
			'header' => '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
				. '<rss xmlns:g="' . self::GOOGLE_NS . '" version="2.0">' . "\n"
				. "<channel>\n"
				. '<title>' . self::xml_text( (string) get_bloginfo( 'name' ) ) . "</title>\n"
				. '<link>' . self::xml_text( home_url( '/' ) ) . "</link>\n"
				. '<description>' . self::xml_text( (string) get_bloginfo( 'description' ) ) . '</description>',
			'row'    => $row,
			'footer' => "</channel>\n</rss>",
		);
		$config['transforms']   = self::transforms( $availability, $title_max, $text_max );
		return $config;
	}

	/**
	 * The Meta catalog CSV. Plain UTF-8 without a byte order mark, so the
	 * first column name reads "id" exactly.
	 *
	 * @param bool $brand Whether to include brand.
	 * @return array
	 */
	private static function meta_feed( bool $brand ): array {
		$config                 = self::base( 'csv', $brand );
		$config['csv_encoding'] = 'utf8';
		$config['transforms']   = self::transforms(
			array(
				'instock'     => 'in stock',
				'outofstock'  => 'out of stock',
				'onbackorder' => 'available for order',
			),
			200,
			9999
		);
		return $config;
	}

	/**
	 * The keys every saved config has, in the order sanitize_config writes them.
	 *
	 * @param string $format Format id.
	 * @param bool   $brand  Whether to include brand.
	 * @return array
	 */
	private static function base( string $format, bool $brand ): array {
		return array(
			'source'        => 'woo:products',
			'fields'        => self::fields( $brand ),
			'filters'       => self::filters(),
			'date_range'    => array(
				'field'  => '',
				'preset' => 'all_time',
				'from'   => '',
				'to'     => '',
			),
			'format'        => $format,
			'batch_size'    => 500,
			'csv_delimiter' => 'comma',
			'column_labels' => self::labels( $brand ),
		);
	}

	/**
	 * Store text for the feed header: escaped for XML, with braces written as
	 * character references so they can never read as a placeholder.
	 *
	 * @param string $text Text.
	 */
	private static function xml_text( string $text ): string {
		$text = htmlspecialchars( wp_strip_all_tags( $text ), ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		return str_replace( array( '{', '}' ), array( '&#123;', '&#125;' ), $text );
	}
}
