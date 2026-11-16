<?php
/**
 * Provider for WooCommerce coupons.
 *
 * Coupons are shop_coupon posts and every field is a post column or a meta
 * key, so the shared post code reads and filters them. This class names the
 * fields and says how each one prints.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Coupons read from wp_posts and postmeta.
 */
class OPEX_Source_Woo_Coupons extends OPEX_Source_Posts {

	use OPEX_Source_Mapped;

	/** The source id saved configs use. */
	const SOURCE = 'woo:coupons';

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
		return 'shop_coupon';
	}

	/** @inheritDoc */
	protected function base_select(): string {
		return 'p.*';
	}

	/** @inheritDoc */
	public function label(): string {
		return __( 'WooCommerce Coupons', 'open-export' );
	}

	/** @inheritDoc */
	public function picker_item(): ?array {
		return array(
			'id'    => self::SOURCE,
			'label' => __( 'WooCommerce Coupons', 'open-export' ),
			'icon'  => 'dashicons-tickets-alt',
			'count' => $this->count_rows(),
		);
	}

	/** @inheritDoc */
	public function date_fields(): array {
		global $wpdb;
		return array(
			'woo:date_created' => array( 'label' => __( 'Date created', 'open-export' ), 'tz' => OPEX_Dates::TZ_LOCAL, 'kind' => 'column', 'sql' => 'p.post_date' ),
			'woo:date_expires' => array(
				'label' => __( 'Expiry date', 'open-export' ),
				'tz'    => OPEX_Dates::TZ_LOCAL,
				'kind'  => 'meta_epoch',
				'key'   => 'date_expires',
				'table' => $wpdb->postmeta,
				'fk'    => 'post_id',
				'outer' => 'p.ID',
			),
		);
	}

	/** @inheritDoc */
	protected function extra_groups(): array {
		return OPEX_Catalog::grouped_meta( OPEX_Catalog::sample_meta_keys( 'posts', 'shop_coupon' ), OPEX_Catalog::acf_keys() );
	}

	/** @inheritDoc */
	protected function field_defs(): array {
		$coupon = __( 'Coupon', 'open-export' );
		$limits = __( 'Usage limits', 'open-export' );
		$rules  = __( 'Restrictions', 'open-export' );
		$types  = function_exists( 'wc_get_coupon_types' ) ? array_keys( wc_get_coupon_types() ) : array();

		return array(
			'woo:id'                   => array( 'group' => $coupon, 'type' => 'id', 'read' => 'core:ID' ),
			'woo:code'                 => array( 'group' => $coupon, 'type' => 'text', 'read' => 'core:post_title' ),
			'woo:description'          => array( 'group' => $coupon, 'type' => 'text', 'read' => 'core:post_excerpt' ),
			'woo:discount_type'        => array( 'group' => $coupon, 'type' => 'enum', 'read' => 'meta:discount_type', 'values' => $types ),
			'woo:amount'               => array( 'group' => $coupon, 'type' => 'number', 'read' => 'meta:coupon_amount', 'format' => 'money' ),
			'woo:date_expires'         => array( 'group' => $coupon, 'type' => 'date', 'read' => 'meta:date_expires', 'format' => 'epoch' ),
			'woo:status'               => array( 'group' => $coupon, 'type' => 'enum', 'read' => 'core:post_status', 'values' => array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' ) ),
			'woo:date_created'         => array( 'group' => $coupon, 'type' => 'date', 'read' => 'core:post_date', 'format' => 'local' ),
			'woo:usage_count'          => array( 'group' => $limits, 'type' => 'number', 'read' => 'meta:usage_count' ),
			'woo:usage_limit'          => array( 'group' => $limits, 'type' => 'number', 'read' => 'meta:usage_limit' ),
			'woo:usage_limit_per_user' => array( 'group' => $limits, 'type' => 'number', 'read' => 'meta:usage_limit_per_user' ),
			'woo:individual_use'       => array( 'group' => $rules, 'type' => 'boolean', 'read' => 'meta:individual_use' ),
			'woo:free_shipping'        => array( 'group' => $rules, 'type' => 'boolean', 'read' => 'meta:free_shipping' ),
			'woo:minimum_amount'       => array( 'group' => $rules, 'type' => 'number', 'read' => 'meta:minimum_amount', 'format' => 'money' ),
			'woo:maximum_amount'       => array( 'group' => $rules, 'type' => 'number', 'read' => 'meta:maximum_amount', 'format' => 'money' ),
			'woo:product_ids'          => array( 'group' => $rules, 'type' => 'text', 'read' => 'meta:product_ids', 'format' => 'list' ),
			'woo:excluded_product_ids' => array( 'group' => $rules, 'type' => 'text', 'read' => 'meta:exclude_product_ids', 'format' => 'list' ),
			'woo:email_restrictions'   => array( 'group' => $rules, 'type' => 'text', 'read' => 'meta:customer_email', 'format' => 'list' ),
		);
	}
}
