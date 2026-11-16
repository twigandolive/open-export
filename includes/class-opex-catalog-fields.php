<?php
/**
 * The field groups each source offers to the picker: core columns, computed
 * fields, taxonomies, meta keys and raw table columns.
 *
 * Part of the catalog compartment. Other code reaches it through OPEX_Catalog.
 *
 * @package Open_Export
 */

// No strict_types here on purpose: strict types would change how this code coerces arguments.

defined( 'ABSPATH' ) || exit;

/**
 * Builds the field groups of a source for the picker.
 */
class OPEX_Catalog_Fields {

	/**
	 * Field groups for a source, ready for the picker UI. Each field:
	 * { id: "core:post_title" | "meta:_sku" | "tax:category" | "computed:permalink" | "col:option_name", label }
	 *
	 * @param string $source_id Source to list the fields of.
	 * @return array[] Field groups for the picker. Empty for an unknown source.
	 */
	public static function fields( string $source_id ): array {
		$src = OPEX_Query::parse_source( $source_id );
		if ( ! $src ) {
			return array();
		}

		$provider = OPEX_Sources::for_src( $src );
		if ( $provider ) {
			return $provider->field_groups();
		}

		switch ( $src['type'] ) {
			case 'posts':
				return OPEX_Acf::add_group( $src, self::post_fields( $src['post_type'] ) );
			case 'users':
				return OPEX_Acf::add_group( $src, self::user_fields() );
			case 'comments':
				return self::comment_fields();
			case 'terms':
				return OPEX_Acf::add_group( $src, self::term_fields( $src['taxonomy'] ) );
			case 'table':
				return self::table_fields( $src['table'] );
		}
		return array();
	}

	/**
	 * Field groups of a post type: core columns, computed fields, taxonomies and meta.
	 *
	 * @param string $post_type Post type.
	 * @return array[] Field groups for the picker.
	 */
	private static function post_fields( string $post_type ): array {
		$core = OPEX_Core_Columns::for_type( 'posts' );

		$groups   = array();
		$groups[] = array(
			'label'  => __( 'Core Fields', 'open-export' ),
			'fields' => array_map( array( __CLASS__, 'core_field' ), $core ),
		);

		$computed = array(
			array(
				'id'    => 'computed:permalink',
				'label' => 'permalink',
			),
			array(
				'id'    => 'computed:featured_image_url',
				'label' => 'featured_image_url',
			),
			array(
				'id'    => 'computed:author_display_name',
				'label' => 'author_display_name',
			),
			array(
				'id'    => 'computed:author_email',
				'label' => 'author_email',
			),
		);
		if ( 'attachment' === $post_type ) {
			$computed[] = array(
				'id'    => 'computed:attachment_url',
				'label' => 'attachment_url',
			);
		}
		$groups[] = array(
			'label'  => __( 'Computed', 'open-export' ),
			'fields' => $computed,
		);

		$tax_fields = array();
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $tax ) {
			$tax_fields[] = array(
				'id'    => 'tax:' . $tax->name,
				'label' => $tax->name,
			);
		}
		if ( $tax_fields ) {
			$groups[] = array(
				'label'  => __( 'Taxonomies', 'open-export' ),
				'fields' => $tax_fields,
			);
		}

		return array_merge( $groups, OPEX_Catalog::grouped_meta( OPEX_Catalog::sample_meta_keys( 'posts', $post_type ), OPEX_Catalog::acf_keys() ) );
	}

	/**
	 * Field groups of users. Credential columns are left out.
	 *
	 * @return array[] Field groups for the picker.
	 */
	private static function user_fields(): array {
		$core = OPEX_Core_Columns::for_type( 'users' );

		$groups   = array();
		$groups[] = array(
			'label'  => __( 'Core Fields', 'open-export' ),
			'fields' => array_map( array( __CLASS__, 'core_field' ), $core ),
		);
		$groups[] = array(
			'label'  => __( 'Computed', 'open-export' ),
			'fields' => array(
				array(
					'id'    => 'computed:roles',
					'label' => 'roles',
				),
			),
		);

		return array_merge( $groups, OPEX_Catalog::grouped_meta( OPEX_Access::user_meta_keys( OPEX_Catalog::sample_meta_keys( 'users' ) ), OPEX_Catalog::acf_keys() ) );
	}

	/**
	 * Field groups of comments.
	 *
	 * @return array[] Field groups for the picker.
	 */
	private static function comment_fields(): array {
		$core = OPEX_Core_Columns::for_type( 'comments' );

		$groups   = array();
		$groups[] = array(
			'label'  => __( 'Core Fields', 'open-export' ),
			'fields' => array_map( array( __CLASS__, 'core_field' ), $core ),
		);

		return array_merge( $groups, OPEX_Catalog::grouped_meta( OPEX_Catalog::sample_meta_keys( 'comments' ), array() ) );
	}

	/**
	 * Field groups of a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array[] Field groups for the picker.
	 */
	private static function term_fields( string $taxonomy ): array {
		$core = OPEX_Core_Columns::for_type( 'terms' );

		$groups   = array();
		$groups[] = array(
			'label'  => __( 'Core Fields', 'open-export' ),
			'fields' => array_map( array( __CLASS__, 'core_field' ), $core ),
		);

		return array_merge( $groups, OPEX_Catalog::grouped_meta( OPEX_Catalog::sample_meta_keys( 'terms', $taxonomy ), OPEX_Catalog::acf_keys() ) );
	}

	/**
	 * The one field group of a raw table: its columns.
	 *
	 * @param string $table Table name, already checked.
	 * @return array[] Field groups for the picker.
	 */
	private static function table_fields( string $table ): array {
		$fields = array();
		foreach ( OPEX_Catalog::table_columns( $table ) as $col ) {
			if ( OPEX_Query::is_forbidden_column( $col['name'] ) ) {
				continue;
			}
			$fields[] = array(
				'id'    => 'col:' . $col['name'],
				'label' => $col['name'] . ' (' . $col['type'] . ')',
			);
		}
		return array(
			array(
				'label'  => __( 'Columns', 'open-export' ),
				'fields' => $fields,
			),
		);
	}

	/**
	 * A picker entry for a core column.
	 *
	 * @param string $col Column name.
	 * @return array Picker entry with the field id and label.
	 */
	private static function core_field( string $col ): array {
		return array(
			'id'    => 'core:' . $col,
			'label' => $col,
		);
	}
}
