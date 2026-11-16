<?php
/**
 * Provider base for sources that are one post type in wp_posts.
 *
 * The posts table is read under the alias `p`. A friendly field id resolves to
 * a core column or a meta key, so the generic post code reads it.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Aliased posts: one post type, read from wp_posts.
 */
abstract class OPEX_Source_Posts extends OPEX_Source_Provider {

	/** The post type this source reads. */
	abstract public function post_type(): string;

	/** {@inheritDoc} */
	public function src(): array {
		return array(
			'type'           => 'posts',
			'post_type'      => $this->post_type(),
			'provider'       => $this->id(),
			'provider_class' => get_class( $this ),
		);
	}

	/** {@inheritDoc} */
	public function from(): string {
		global $wpdb;
		return "{$wpdb->posts} p";
	}

	/** Extra base conditions beyond the post type and auto-drafts. */
	protected function extra_where(): array {
		return array();
	}

	/** {@inheritDoc} */
	public function base_where(): array {
		$base = array_merge( array( 'p.post_type = %s', "p.post_status <> 'auto-draft'" ), $this->extra_where() );
		return array( $base, array( $this->post_type() ) );
	}

	/** {@inheritDoc} */
	public function primary_key(): string {
		return 'p.ID';
	}

	/** {@inheritDoc} */
	public function id_prop(): string {
		return 'ID';
	}

	/** {@inheritDoc} */
	public function select_list( array $fields ): string {
		return 'p.*';
	}

	/** {@inheritDoc} */
	public function meta_table(): ?array {
		global $wpdb;
		return array( $wpdb->postmeta, 'post_id', 'p.ID' );
	}

	/** {@inheritDoc} */
	public function date_fields(): array {
		return OPEX_Query_Dates::date_fields(
			array(
				'type'      => 'posts',
				'post_type' => $this->post_type(),
			)
		);
	}
}
