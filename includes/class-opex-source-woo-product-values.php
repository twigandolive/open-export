<?php
/**
 * Product values that no single column holds, built in PHP per row.
 *
 * Attributes come from WooCommerce's own product objects, so labels and term
 * names match what the store shows. Images and terms read the caches that
 * prime() fills once per batch, so a batch costs a few queries, not a few per row.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Builds attribute JSON, term lists, image URLs and permalinks for products.
 */
class OPEX_Source_Woo_Product_Values {

	/**
	 * Loads the posts, meta and terms a batch reads, plus parents and images.
	 *
	 * @param int[] $ids Product and variation ids.
	 */
	public static function prime( array $ids ): void {
		if ( ! $ids || ! function_exists( '_prime_post_caches' ) ) {
			return;
		}
		_prime_post_caches( $ids, true, true );
		$more = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post && $post->post_parent ) {
				$more[] = (int) $post->post_parent;
			}
			$more[] = (int) get_post_meta( $id, '_thumbnail_id', true );
			$more   = array_merge( $more, self::gallery_ids( $id ) );
		}
		$more = array_values( array_unique( array_filter( $more ) ) );
		if ( $more ) {
			_prime_post_caches( $more, true, true );
		}
	}

	/**
	 * Term names of a product, sorted and joined with ", ". A variation takes its parent's.
	 *
	 * @param object $row      Fetched post row.
	 * @param string $taxonomy Taxonomy name.
	 * @return string The names, or '' when there are none.
	 */
	public static function term_names( object $row, string $taxonomy ): string {
		$terms = get_the_terms( self::owner_id( $row ), $taxonomy );
		if ( ! is_array( $terms ) ) {
			return '';
		}
		$names = array();
		foreach ( $terms as $term ) {
			$names[] = (string) $term->name;
		}
		// Sorting here keeps the order the same on every database collation.
		sort( $names, SORT_NATURAL | SORT_FLAG_CASE );
		return implode( ', ', $names );
	}

	/**
	 * URL of the row's own image, or ''.
	 *
	 * @param int $id Product or variation id.
	 * @return string The URL, or ''.
	 */
	public static function image_url( int $id ): string {
		$thumb = (int) get_post_meta( $id, '_thumbnail_id', true );
		return $thumb ? (string) wp_get_attachment_url( $thumb ) : '';
	}

	/**
	 * Gallery image URLs joined with ", ".
	 *
	 * @param int $id Product id.
	 * @return string The URLs, or '' when there are none.
	 */
	public static function gallery_urls( int $id ): string {
		$urls = array();
		foreach ( self::gallery_ids( $id ) as $image ) {
			$url = wp_get_attachment_url( $image );
			if ( $url ) {
				$urls[] = (string) $url;
			}
		}
		return implode( ', ', $urls );
	}

	/**
	 * The product page. A variation's link carries its attribute choices, as WooCommerce builds it.
	 *
	 * @param int $id Product or variation id.
	 * @return string The product page URL.
	 */
	public static function permalink( int $id ): string {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
		return $product ? (string) $product->get_permalink() : (string) get_permalink( $id );
	}

	/**
	 * Attributes as one JSON object keyed by attribute label. A product lists
	 * every value it offers. A variation gives its own single value, and an
	 * empty value means the variation matches any value.
	 *
	 * @param int $id Product or variation id.
	 * @return string The JSON text, or '' when there are no attributes.
	 */
	public static function attributes_json( int $id ): string {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
		if ( ! $product ) {
			return '';
		}
		$out = array();
		foreach ( $product->get_attributes() as $name => $attribute ) {
			if ( $attribute instanceof WC_Product_Attribute ) {
				$label         = wc_attribute_label( $attribute->get_name(), $product );
				$out[ $label ] = $attribute->is_taxonomy()
					? array_values( (array) wc_get_product_terms( $id, $attribute->get_name(), array( 'fields' => 'names' ) ) )
					: array_values( array_map( 'strval', $attribute->get_options() ) );
				continue;
			}
			// A variation stores one value per attribute: a term slug or plain text.
			$name  = (string) $name;
			$value = (string) $attribute;
			if ( '' !== $value && taxonomy_exists( $name ) ) {
				$term  = get_term_by( 'slug', $value, $name );
				$value = $term ? (string) $term->name : $value;
			}
			$out[ wc_attribute_label( $name, $product ) ] = $value;
		}
		return $out ? (string) wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '';
	}

	/**
	 * The row whose categories, tags and visibility a row shows: a variation's parent, or itself.
	 *
	 * @param object $row Fetched post row.
	 * @return int The id of the owner row.
	 */
	public static function owner_id( object $row ): int {
		$is_variation = 'product_variation' === (string) ( $row->post_type ?? '' );
		return $is_variation ? (int) ( $row->post_parent ?? 0 ) : (int) ( $row->ID ?? 0 );
	}

	/**
	 * Attachment ids in a product's gallery.
	 *
	 * @param int $id Product id.
	 * @return int[]
	 */
	private static function gallery_ids( int $id ): array {
		$raw = (string) get_post_meta( $id, '_product_image_gallery', true );
		return array_values( array_filter( array_map( 'intval', explode( ',', $raw ) ) ) );
	}
}
