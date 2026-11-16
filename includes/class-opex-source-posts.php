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

	/**
	 * The post type this source reads.
	 *
	 * @return string The post type.
	 */
	abstract public function post_type(): string;

	/**
	 * {@inheritDoc}
	 *
	 * @return array The parsed source.
	 */
	public function src(): array {
		return array(
			'type'           => 'posts',
			'post_type'      => $this->post_type(),
			'provider'       => $this->id(),
			'provider_class' => get_class( $this ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string The FROM clause.
	 */
	public function from(): string {
		global $wpdb;
		return "{$wpdb->posts} p";
	}

	/**
	 * Extra base conditions beyond the post type and auto-drafts.
	 *
	 * @return string[] Extra SQL conditions.
	 */
	protected function extra_where(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array The base WHERE conditions and the values for their placeholders.
	 */
	public function base_where(): array {
		$base = array_merge( array( 'p.post_type = %s', "p.post_status <> 'auto-draft'" ), $this->extra_where() );
		return array( $base, array( $this->post_type() ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string The key column for cursor paging.
	 */
	public function primary_key(): string {
		return 'p.ID';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string The key property name on a fetched row.
	 */
	public function id_prop(): string {
		return 'ID';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string[] $fields Field ids the export asked for.
	 * @return string The SELECT list.
	 */
	public function select_list( array $fields ): string {
		return 'p.*';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array|null The meta table, its foreign key and the outer id, or null when the source has no meta.
	 */
	public function meta_table(): ?array {
		global $wpdb;
		return array( $wpdb->postmeta, 'post_id', 'p.ID' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array> Date fields by field id, each with a label and how its time is stored.
	 */
	public function date_fields(): array {
		return OPEX_Query_Dates::date_fields(
			array(
				'type'      => 'posts',
				'post_type' => $this->post_type(),
			)
		);
	}
}
