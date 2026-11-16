<?php
/**
 * Provider for WooCommerce products and their variations, in one source.
 *
 * Products and variations are posts, so most fields resolve to a post column
 * or a meta key and the shared post code reads and filters them. The rest are
 * SQL expressions (type, visibility) or values built in PHP (attributes,
 * images). A variation takes its parent's categories, tags and visibility,
 * because WooCommerce keeps those on the parent only.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Products and variations read from wp_posts and postmeta.
 */
class OPEX_Source_Woo_Products extends OPEX_Source_Posts {

	use OPEX_Source_Mapped;

	/** The source id saved configs use. */
	const SOURCE = 'woo:products';

	/** The row whose terms a row shows: a variation's parent, or itself. */
	const OWNER = "(CASE WHEN p.post_type = 'product_variation' THEN p.post_parent ELSE p.ID END)";

	/** The provider when WooCommerce is active, else null. */
	public static function resolve(): ?OPEX_Source_Provider {
		return OPEX_Woo::is_active() ? OPEX_Sources::instance( __CLASS__ ) : null;
	}

	/** @inheritDoc */
	public function id(): string {
		return self::SOURCE;
	}

	/** @inheritDoc */
	public function post_type(): string {
		return 'product';
	}

	/** @inheritDoc */
	public function base_where(): array {
		return array( array( "p.post_type IN ('product', 'product_variation')", "p.post_status <> 'auto-draft'" ), array() );
	}

	/** @inheritDoc */
	protected function base_select(): string {
		return 'p.*';
	}

	/** @inheritDoc */
	protected function term_object(): string {
		return self::OWNER;
	}

	/** @inheritDoc */
	public function label(): string {
		return __( 'WooCommerce Products', 'open-export' );
	}

	/** @inheritDoc */
	public function picker_item(): ?array {
		return array(
			'id'    => self::SOURCE,
			'label' => __( 'WooCommerce Products', 'open-export' ),
			'icon'  => 'dashicons-products',
			'count' => $this->count_rows(),
		);
	}

	/** @inheritDoc */
	public function date_fields(): array {
		global $wpdb;
		$epoch = array(
			'tz'    => OPEX_Dates::TZ_LOCAL,
			'kind'  => 'meta_epoch',
			'table' => $wpdb->postmeta,
			'fk'    => 'post_id',
			'outer' => 'p.ID',
		);
		return array(
			'woo:date_created'      => array(
				'label' => __( 'Date created', 'open-export' ),
				'tz'    => OPEX_Dates::TZ_LOCAL,
				'kind'  => 'column',
				'sql'   => 'p.post_date',
			),
			'woo:date_modified'     => array(
				'label' => __( 'Last modified', 'open-export' ),
				'tz'    => OPEX_Dates::TZ_LOCAL,
				'kind'  => 'column',
				'sql'   => 'p.post_modified',
			),
			'woo:date_on_sale_from' => array(
				'label' => __( 'Sale starts', 'open-export' ),
				'key'   => '_sale_price_dates_from',
			) + $epoch,
			'woo:date_on_sale_to'   => array(
				'label' => __( 'Sale ends', 'open-export' ),
				'key'   => '_sale_price_dates_to',
			) + $epoch,
		);
	}

	/** @inheritDoc */
	public function prime( array $ids ): void {
		OPEX_Source_Woo_Product_Values::prime( $ids );
	}

	/** @inheritDoc */
	public function compute_value( object $row, string $field, int $id ): ?string {
		switch ( $field ) {
			case 'woo:attributes':
				return OPEX_Source_Woo_Product_Values::attributes_json( $id );
			case 'woo:categories':
				return OPEX_Source_Woo_Product_Values::term_names( $row, 'product_cat' );
			case 'woo:tags':
				return OPEX_Source_Woo_Product_Values::term_names( $row, 'product_tag' );
			case 'woo:image_url':
				return OPEX_Source_Woo_Product_Values::image_url( $id );
			case 'woo:gallery_urls':
				return OPEX_Source_Woo_Product_Values::gallery_urls( $id );
			case 'woo:permalink':
				return OPEX_Source_Woo_Product_Values::permalink( $id );
		}
		return null;
	}

	/** @inheritDoc */
	public function derive_value( string $field, string $value ): string {
		// A variable product keeps one _price row per distinct variation price.
		// WooCommerce shows the lowest as its "from" price, so this does too.
		if ( 'woo:price' !== $field || false === strpos( $value, ' | ' ) ) {
			return $value;
		}
		$low = null;
		foreach ( explode( ' | ', $value ) as $part ) {
			$plain = OPEX_Decimal::normalize( $part );
			if ( null === $plain ) {
				return $value;
			}
			$low = ( null === $low || OPEX_Decimal::compare( $plain, $low ) < 0 ) ? $plain : $low;
		}
		return (string) $low;
	}

	/** @inheritDoc */
	protected function extra_groups(): array {
		return OPEX_Catalog::grouped_meta( OPEX_Catalog::sample_meta_keys( 'posts', 'product' ), OPEX_Catalog::acf_keys() );
	}

	/** @inheritDoc */
	protected function field_defs(): array {
		$product  = __( 'Product', 'open-export' );
		$price    = __( 'Price', 'open-export' );
		$stock    = __( 'Inventory', 'open-export' );
		$ship     = __( 'Shipping', 'open-export' );
		$content  = __( 'Description', 'open-export' );
		$terms    = __( 'Categories and attributes', 'open-export' );
		$media    = __( 'Images and links', 'open-export' );
		$dates    = __( 'Dates', 'open-export' );
		$stati    = array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' );
		$stock_in = function_exists( 'wc_get_product_stock_status_options' ) ? array_keys( wc_get_product_stock_status_options() ) : array();

		return array(
			'woo:id'                 => array(
				'group' => $product,
				'type'  => 'id',
				'read'  => 'core:ID',
			),
			'woo:type'               => array(
				'group'  => $product,
				'type'   => 'enum',
				'sql'    => $this->type_sql(),
				'values' => array( 'simple', 'variable', 'variation', 'grouped', 'external' ),
			),
			'woo:parent_id'          => array(
				'group' => $product,
				'type'  => 'id',
				'read'  => 'core:post_parent',
			),
			'woo:parent_sku'         => array(
				'group' => $product,
				'type'  => 'text',
				'sql'   => $this->meta_sql( '_sku', 'p.post_parent' ),
			),
			'woo:name'               => array(
				'group' => $product,
				'type'  => 'text',
				'read'  => 'core:post_title',
			),
			'woo:slug'               => array(
				'group' => $product,
				'type'  => 'text',
				'read'  => 'core:post_name',
			),
			'woo:status'             => array(
				'group'  => $product,
				'type'   => 'enum',
				'read'   => 'core:post_status',
				'values' => $stati,
			),
			'woo:sku'                => array(
				'group' => $product,
				'type'  => 'text',
				'read'  => 'meta:_sku',
			),
			// WooCommerce 9.2 and later store it. On older stores the column is empty.
			'woo:global_unique_id'   => array(
				'group' => $product,
				'type'  => 'text',
				'read'  => 'meta:_global_unique_id',
				'label' => __( 'GTIN, UPC, EAN or ISBN', 'open-export' ),
			),
			'woo:featured'           => array(
				'group' => $product,
				'type'  => 'boolean',
				'sql'   => $this->visibility_sql( 'featured' ),
			),
			'woo:catalog_visibility' => array(
				'group'  => $product,
				'type'   => 'enum',
				'sql'    => $this->catalog_visibility_sql(),
				'values' => array( 'visible', 'catalog', 'search', 'hidden' ),
			),
			'woo:total_sales'        => array(
				'group' => $product,
				'type'  => 'number',
				'read'  => 'meta:total_sales',
			),
			'woo:regular_price'      => array(
				'group'  => $price,
				'type'   => 'number',
				'read'   => 'meta:_regular_price',
				'format' => 'money',
			),
			'woo:sale_price'         => array(
				'group'  => $price,
				'type'   => 'number',
				'read'   => 'meta:_sale_price',
				'format' => 'money',
			),
			'woo:price'              => array(
				'group'  => $price,
				'type'   => 'number',
				'read'   => 'meta:_price',
				'format' => 'money',
			),
			'woo:date_on_sale_from'  => array(
				'group'  => $price,
				'type'   => 'date',
				'read'   => 'meta:_sale_price_dates_from',
				'format' => 'epoch',
			),
			'woo:date_on_sale_to'    => array(
				'group'  => $price,
				'type'   => 'date',
				'read'   => 'meta:_sale_price_dates_to',
				'format' => 'epoch',
			),
			'woo:tax_status'         => array(
				'group'  => $price,
				'type'   => 'enum',
				'read'   => 'meta:_tax_status',
				'values' => array( 'taxable', 'shipping', 'none' ),
			),
			'woo:tax_class'          => array(
				'group' => $price,
				'type'  => 'text',
				'read'  => 'meta:_tax_class',
			),
			'woo:stock_status'       => array(
				'group'  => $stock,
				'type'   => 'enum',
				'read'   => 'meta:_stock_status',
				'values' => $stock_in,
			),
			'woo:stock_quantity'     => array(
				'group' => $stock,
				'type'  => 'number',
				'read'  => 'meta:_stock',
			),
			'woo:manage_stock'       => array(
				'group' => $stock,
				'type'  => 'boolean',
				'read'  => 'meta:_manage_stock',
			),
			'woo:weight'             => array(
				'group' => $ship,
				'type'  => 'number',
				'read'  => 'meta:_weight',
			),
			'woo:length'             => array(
				'group' => $ship,
				'type'  => 'number',
				'read'  => 'meta:_length',
			),
			'woo:width'              => array(
				'group' => $ship,
				'type'  => 'number',
				'read'  => 'meta:_width',
			),
			'woo:height'             => array(
				'group' => $ship,
				'type'  => 'number',
				'read'  => 'meta:_height',
			),
			'woo:short_description'  => array(
				'group' => $content,
				'type'  => 'text',
				'read'  => 'core:post_excerpt',
			),
			'woo:description'        => array(
				'group' => $content,
				'type'  => 'text',
				'sql'   => $this->description_sql(),
			),
			'woo:attributes'         => array(
				'group' => $terms,
				'type'  => 'json',
				'php'   => true,
			),
			'woo:categories'         => array(
				'group' => $terms,
				'type'  => 'text',
				'php'   => true,
				'terms' => 'product_cat',
			),
			'woo:tags'               => array(
				'group' => $terms,
				'type'  => 'text',
				'php'   => true,
				'terms' => 'product_tag',
			),
			'woo:image_url'          => array(
				'group' => $media,
				'type'  => 'url',
				'php'   => true,
			),
			'woo:gallery_urls'       => array(
				'group' => $media,
				'type'  => 'text',
				'php'   => true,
			),
			'woo:permalink'          => array(
				'group' => $media,
				'type'  => 'url',
				'php'   => true,
			),
			'woo:date_created'       => array(
				'group'  => $dates,
				'type'   => 'date',
				'read'   => 'core:post_date',
				'format' => 'local',
			),
			'woo:date_modified'      => array(
				'group'  => $dates,
				'type'   => 'date',
				'read'   => 'core:post_modified',
				'format' => 'local',
			),
		);
	}

	/**
	 * SQL for the first value of a meta key on a given row.
	 *
	 * @param string $key    Meta key, a constant in this class.
	 * @param string $object SQL for the row id, a constant in this class.
	 */
	private function meta_sql( string $key, string $object ): string {
		global $wpdb;
		return "(SELECT pm1.meta_value FROM {$wpdb->postmeta} pm1 WHERE pm1.post_id = {$object} AND pm1.meta_key = '{$key}' ORDER BY pm1.meta_id LIMIT 1)";
	}

	/**
	 * SQL that is the text 1 when the row's owner has a product_visibility term,
	 * else 0. Text compares the same way on SQLite and MySQL.
	 *
	 * @param string $slug Term slug, a constant in this class.
	 */
	private function visibility_sql( string $slug ): string {
		global $wpdb;
		$owner = self::OWNER;
		return "(CASE WHEN EXISTS (SELECT 1 FROM {$wpdb->term_relationships} vr INNER JOIN {$wpdb->term_taxonomy} vx ON vx.term_taxonomy_id = vr.term_taxonomy_id" .
			" INNER JOIN {$wpdb->terms} vt ON vt.term_id = vx.term_id WHERE vr.object_id = {$owner} AND vx.taxonomy = 'product_visibility' AND vt.slug = '{$slug}') THEN '1' ELSE '0' END)";
	}

	/** SQL for the visibility WooCommerce derives from two product_visibility terms. */
	private function catalog_visibility_sql(): string {
		$no_shop   = $this->visibility_sql( 'exclude-from-catalog' ) . " = '1'";
		$no_search = $this->visibility_sql( 'exclude-from-search' ) . " = '1'";
		return "(CASE WHEN {$no_shop} AND {$no_search} THEN 'hidden' WHEN {$no_shop} THEN 'search' WHEN {$no_search} THEN 'catalog' ELSE 'visible' END)";
	}

	/** SQL for the product type: a variation, or the product_type term (simple when there is none). */
	private function type_sql(): string {
		global $wpdb;
		return "(CASE WHEN p.post_type = 'product_variation' THEN 'variation' ELSE COALESCE((SELECT tt1.slug FROM {$wpdb->term_relationships} tr1" .
			" INNER JOIN {$wpdb->term_taxonomy} tx1 ON tx1.term_taxonomy_id = tr1.term_taxonomy_id INNER JOIN {$wpdb->terms} tt1 ON tt1.term_id = tx1.term_id" .
			" WHERE tr1.object_id = p.ID AND tx1.taxonomy = 'product_type' ORDER BY tt1.term_id LIMIT 1), 'simple') END)";
	}

	/** SQL for the description. A variation keeps its own in meta, as WooCommerce does. */
	private function description_sql(): string {
		return "(CASE WHEN p.post_type = 'product_variation' THEN COALESCE(" . $this->meta_sql( '_variation_description', 'p.ID' ) . ", '') ELSE p.post_content END)";
	}
}
