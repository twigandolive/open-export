<?php
/**
 * Describes one source for the capability document: its fields, their
 * type hints, its date columns and the legal values for the fields where a
 * wrong guess would match nothing.
 *
 * Part of the schema compartment. Other code reaches it through OPEX_Schema.
 */

// No strict_types here on purpose: this file holds moved code, and strict types would change how arguments are coerced.

defined( 'ABSPATH' ) || exit;

class OPEX_Schema_Sources {

	/** Values are only enumerated up to this many per field. */
	private const MAX_VALUES = 100;

	/**
	 * Meta fields listed per source when describing several at once. A single
	 * source is always described in full; this only bounds the overview, which
	 * says so in the payload and points at the per-source call.
	 */
	private const MAX_META_IN_OVERVIEW = 120;

	/**
	 * Full description of one source: its fields, date columns, and value hints.
	 *
	 * @param string $source_id Source to describe.
	 * @param array  $index     all_sources entries, for the label, tier and count.
	 * @param bool   $overview  Trim the custom-field list. A single-source
	 *                          request never trims; the overview says when it did.
	 *
	 * @internal Called by OPEX_Schema::build only.
	 */
	public static function describe_source( string $source_id, array $index = array(), bool $overview = false ): ?array {
		$src = OPEX_Query::parse_source( $source_id );
		if ( ! $src ) {
			return null;
		}

		$fields     = array();
		$meta_shown = 0;
		$meta_total = 0;

		foreach ( OPEX_Catalog::fields( $source_id ) as $group ) {
			foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
				$id = (string) $field['id'];

				if ( 0 === strpos( $id, 'meta:' ) ) {
					++$meta_total;
					if ( $overview && $meta_shown >= self::MAX_META_IN_OVERVIEW ) {
						continue;
					}
					++$meta_shown;
				}

				$entry = array(
					'id'    => $id,
					'label' => $field['label'],
					'group' => $group['label'],
					'type'  => $field['type'] ?? self::field_type( $src, $id ),
				);

				$values = self::field_values( $src, $id );
				if ( $values ) {
					$entry['values'] = $values;
				}

				$fields[] = $entry;
			}
		}

		$dates = array();
		foreach ( OPEX_Query::date_fields( $src ) as $id => $def ) {
			$dates[] = array(
				'id'        => $id,
				'label'     => $def['label'],
				'stored_in' => OPEX_Dates::TZ_UTC === $def['tz'] ? 'UTC' : 'site timezone',
			);
		}

		$entry_meta = array();
		foreach ( $index as $row ) {
			if ( $row['id'] === $source_id ) {
				$entry_meta = $row;
				break;
			}
		}

		$out = array(
			'id'          => $source_id,
			'label'       => $entry_meta['label'] ?? self::source_label( $source_id ),
			'group'       => $entry_meta['group'] ?? '',
			'tier'        => $entry_meta['tier'] ?? 'common',
			'kind'        => $src['type'],
			'rows'        => (int) ( $entry_meta['rows'] ?? 0 ),
			'fields'      => $fields,
			'date_fields' => $dates,
		);

		if ( $overview && $meta_total > $meta_shown ) {
			$out['fields_truncated']   = true;
			$out['custom_field_count'] = $meta_total;
			// rest_url knows plain permalinks and custom REST prefixes. Append the source by hand: add_query_arg would re-encode the route.
			$schema_url               = rest_url( 'open-export/v1/schema' );
			$out['to_see_all_fields'] = $schema_url . ( false === strpos( $schema_url, '?' ) ? '?' : '&' ) . 'source=' . rawurlencode( $source_id );
		}

		return $out;
	}

	/** Coarse type hint so an assistant knows what a field holds. */
	public static function field_type( array $src, string $field ): string {
		if ( 0 === strpos( $field, 'woo:' ) ) {
			$provider = OPEX_Sources::for_src( $src );
			return $provider ? $provider->field_type( $field ) : 'text';
		}
		if ( 0 === strpos( $field, 'tax:' ) ) {
			return 'enum';
		}
		if ( 0 === strpos( $field, 'computed:' ) ) {
			$what = substr( $field, 9 );
			return in_array( $what, array( 'permalink', 'attachment_url', 'featured_image_url' ), true ) ? 'url' : 'text';
		}
		if ( 0 === strpos( $field, 'col:' ) ) {
			$name = substr( $field, 4 );
			foreach ( OPEX_Catalog::table_columns( $src['table'] ?? '' ) as $col ) {
				if ( $col['name'] !== $name ) {
					continue;
				}
				if ( preg_match( '/^(datetime|timestamp|date)/i', $col['type'] ) ) {
					return 'date';
				}
				if ( preg_match( '/(int|decimal|float|double|numeric)/i', $col['type'] ) ) {
					return $col['pk'] ? 'id' : 'number';
				}
				return 'text';
			}
			return 'text';
		}
		if ( 0 === strpos( $field, 'core:' ) ) {
			$col = substr( $field, 5 );
			if ( preg_match( '/(_date|_date_gmt|_modified|_modified_gmt|_registered)$/', $col ) ) {
				return 'date';
			}
			if ( in_array( $col, array( 'ID', 'comment_ID', 'term_id', 'comment_post_ID', 'post_author', 'post_parent', 'user_id', 'comment_parent' ), true ) ) {
				return 'id';
			}
			if ( in_array( $col, array( 'menu_order', 'comment_count', 'comment_karma', 'count', 'parent' ), true ) ) {
				return 'number';
			}
			if ( in_array( $col, array( 'post_status', 'post_type', 'comment_approved', 'comment_type', 'comment_status', 'ping_status', 'post_mime_type' ), true ) ) {
				return 'enum';
			}
			if ( in_array( $col, array( 'user_email', 'comment_author_email' ), true ) ) {
				return 'email';
			}
			if ( in_array( $col, array( 'guid', 'user_url', 'comment_author_url' ), true ) ) {
				return 'url';
			}
			return 'text';
		}
		return 'text';
	}

	private static function source_label( string $source_id ): string {
		foreach ( OPEX_Catalog::sources() as $group ) {
			foreach ( $group['items'] as $item ) {
				if ( $item['id'] === $source_id ) {
					return (string) $item['label'];
				}
			}
		}
		return $source_id;
	}

	/**
	 * Legal values for the handful of fields where guessing wrong yields an
	 * empty export (statuses, roles, taxonomy terms). Everything else returns
	 * an empty array rather than paying for a DISTINCT over a large column.
	 */
	private static function field_values( array $src, string $field ): array {
		// Order status, on either storage engine.
		$provider = OPEX_Sources::for_values( $src );
		if ( $provider ) {
			$values = $provider->field_values( $field );
			if ( $values ) {
				return $values;
			}
		}

		if ( 'core:post_status' === $field && 'posts' === $src['type'] ) {
			$stati = get_post_stati();
			return array_values( array_slice( array_keys( (array) $stati ), 0, self::MAX_VALUES ) );
		}

		if ( 'computed:roles' === $field && 'users' === $src['type'] ) {
			$roles = wp_roles();
			return $roles ? array_keys( (array) $roles->get_names() ) : array();
		}

		if ( 0 === strpos( $field, 'tax:' ) && 'posts' === $src['type'] ) {
			$terms = get_terms( array(
				'taxonomy'   => substr( $field, 4 ),
				'fields'     => 'slugs',
				'hide_empty' => false,
				'number'     => self::MAX_VALUES,
			) );
			return is_wp_error( $terms ) ? array() : array_values( (array) $terms );
		}

		if ( 'core:post_type' === $field && 'posts' === $src['type'] ) {
			return array( $src['post_type'] );
		}

		return array();
	}
}
