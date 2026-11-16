<?php
/**
 * Turns database rows into export rows keyed by field id.
 *
 * Part of the query compartment. Reached only through OPEX_Query.
 */

// No strict_types here on purpose: this file holds moved code, and strict types would change how arguments are coerced.

defined( 'ABSPATH' ) || exit;

class OPEX_Query_Rows {

	/**
	 * Turn raw DB rows into export items keyed by field id, prefetching all
	 * meta and taxonomy values for the batch in one query each.
	 *
	 * Internal to the query compartment: other code calls OPEX_Query.
	 */
	public static function assemble( array $src, array $rows, array $fields, $tick = null ): array {
		if ( ! $rows ) {
			return array();
		}

		$provider = OPEX_Sources::for_src( $src );
		$id_prop  = $provider ? $provider->id_prop() : ( array( 'posts' => 'ID', 'users' => 'ID', 'comments' => 'comment_ID', 'terms' => 'term_id' )[ $src['type'] ] ?? '' );
		$ids     = array();
		if ( $id_prop ) {
			foreach ( $rows as $row ) {
				$ids[] = (int) $row->{$id_prop};
			}
		}

		if ( $provider && $ids ) {
			$provider->prime( $ids );
		}

		$meta_keys = array();
		$tax_names = array();
		foreach ( $fields as $field ) {
			$resolved = OPEX_Query::resolve_field( $src, $field );
			if ( 0 === strpos( $resolved, 'meta:' ) ) {
				$meta_keys[] = substr( $resolved, 5 );
			} elseif ( 0 === strpos( $resolved, 'tax:' ) ) {
				$tax_names[] = substr( $resolved, 4 );
			}
		}

		$meta = $meta_keys && $ids ? self::prefetch_meta( $src, $ids, $meta_keys ) : array();
		// ACF repeaters and flexible content come assembled, one JSON value per record.
		$acf = $ids ? OPEX_Acf::prefetch( $src, $ids, $fields ) : array();
		$tax  = array();
		foreach ( $tax_names as $tax_name ) {
			$tax[ $tax_name ] = self::prefetch_terms( $ids, $tax_name );
		}

		$items = array();
		$n     = 0;
		foreach ( $rows as $row ) {
			// Let a long batch keep its lock alive.
			if ( is_callable( $tick ) && 0 === ( $n++ % 100 ) ) {
				call_user_func( $tick );
			}
			$id   = $id_prop ? (int) $row->{$id_prop} : 0;
			$item = array();
			foreach ( $fields as $field ) {
				$item[ $field ] = isset( $acf[ $field ] ) ? (string) ( $acf[ $field ][ $id ] ?? '' ) : self::field_value( $src, $row, $field, $id, $meta, $tax );
			}
			$items[] = $item;
		}
		return $items;
	}

	/**
	 * One cell. Order money, dates and flags get the same text on both storage engines.
	 */
	private static function field_value( array $src, object $row, string $field, int $id, array $meta, array $tax ): string {
		$value    = self::raw_value( $src, $row, $field, $id, $meta, $tax );
		$provider = OPEX_Sources::for_src( $src );
		return $provider ? $provider->format_value( $field, $value ) : $value;
	}

	private static function raw_value( array $src, object $row, string $field, int $id, array $meta, array $tax ): string {
		$provider = OPEX_Sources::for_src( $src );

		// A field the provider keeps under its own row property.
		$alias = $provider ? $provider->row_alias( $field ) : null;
		if ( null !== $alias ) {
			return $provider->derive_value( $field, self::flatten( $row->{$alias} ?? '' ) );
		}

		// A value the provider builds in PHP.
		if ( $provider ) {
			$built = $provider->compute_value( $row, $field, $id );
			if ( null !== $built ) {
				return $provider->derive_value( $field, $built );
			}
		}

		// A friendly id reads from the post column or meta key that actually
		// holds it.
		$value = self::resolved_value( $src, $row, OPEX_Query::resolve_field( $src, $field ), $id, $meta, $tax );
		return $provider ? $provider->derive_value( $field, $value ) : $value;
	}

	/** One cell read by its storage id (core, col, meta, tax or computed). */
	private static function resolved_value( array $src, object $row, string $field, int $id, array $meta, array $tax ): string {		if ( 0 === strpos( $field, 'core:' ) ) {
			$col = substr( $field, 5 );
			return OPEX_Query::is_forbidden_column( $col ) ? '' : self::flatten( $row->{$col} ?? '' );
		}
		if ( 0 === strpos( $field, 'col:' ) ) {
			$col = substr( $field, 4 );
			return OPEX_Query::is_forbidden_column( $col ) ? '' : self::flatten( $row->{$col} ?? '' );
		}
		if ( 0 === strpos( $field, 'meta:' ) ) {
			$key = substr( $field, 5 );
			return isset( $meta[ $id ][ $key ] ) ? self::flatten_many( $meta[ $id ][ $key ] ) : '';
		}
		if ( 0 === strpos( $field, 'tax:' ) ) {
			$name = substr( $field, 4 );
			return isset( $tax[ $name ][ $id ] ) ? implode( ' | ', $tax[ $name ][ $id ] ) : '';
		}
		if ( 0 === strpos( $field, 'computed:' ) ) {
			return self::computed_value( $src, $row, substr( $field, 9 ), $id );
		}
		return '';
	}

	private static function computed_value( array $src, object $row, string $what, int $id ): string {
		switch ( $what ) {
			case 'permalink':
				return (string) get_permalink( $id );
			case 'attachment_url':
				return (string) wp_get_attachment_url( $id );
			case 'featured_image_url':
				$thumb = get_post_thumbnail_id( $id );
				return $thumb ? (string) wp_get_attachment_url( $thumb ) : '';
			case 'author_display_name':
			case 'author_email':
				$user = get_userdata( (int) ( $row->post_author ?? 0 ) );
				if ( ! $user ) {
					return '';
				}
				return 'author_email' === $what ? (string) $user->user_email : (string) $user->display_name;
			case 'roles':
				$user = get_userdata( $id );
				return $user ? implode( ' | ', $user->roles ) : '';
		}
		return '';
	}

	/** One query fetches every requested meta value for the whole batch. */
	private static function prefetch_meta( array $src, array $ids, array $keys ): array {
		global $wpdb;

		$map = array(
			'posts'    => array( $wpdb->postmeta, 'post_id' ),
			'users'    => array( $wpdb->usermeta, 'user_id' ),
			'comments' => array( $wpdb->commentmeta, 'comment_id' ),
			'terms'    => array( $wpdb->termmeta, 'term_id' ),
		);
		$provider = OPEX_Sources::for_src( $src );
		$found    = $provider ? $provider->meta_table() : ( $map[ $src['type'] ] ?? null );
		if ( null === $found ) {
			return array();
		}
		list( $table, $fk ) = $found;

		$keys = array_values( array_diff( array_unique( $keys ), OPEX_Query::FORBIDDEN, OPEX_Query::CREDENTIAL_META_KEYS ) );
		$keys = 'users' === ( $src['type'] ?? '' ) ? OPEX_Access::user_meta_keys( $keys ) : $keys;
		if ( ! $keys ) {
			return array();
		}

		$id_holders  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$key_holders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder lists built above; every value is bound.
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column names come from the fixed map above.
			"SELECT {$fk} AS oid, meta_key, meta_value FROM {$table} WHERE {$fk} IN ({$id_holders}) AND meta_key IN ({$key_holders})",
			array_merge( $ids, $keys )
		);

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above; streaming export batches are inherently uncached.
		$rows = $wpdb->get_results( $sql );
		OPEX_Query::throw_on_db_error();

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->oid ][ $row->meta_key ][] = $row->meta_value;
		}
		return $out;
	}

	/** Term names per object id for one taxonomy, one query per batch. */
	private static function prefetch_terms( array $ids, string $taxonomy ): array {
		if ( ! $ids || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$terms = wp_get_object_terms( $ids, $taxonomy, array( 'fields' => 'all_with_object_id' ) );
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$out = array();
		foreach ( $terms as $term ) {
			$out[ (int) $term->object_id ][] = $term->name;
		}
		return $out;
	}

	/** Decode serialized values to JSON for readable output. */
	private static function flatten( $value ): string {
		if ( is_null( $value ) ) {
			return '';
		}
		$maybe = maybe_unserialize( (string) $value );
		if ( is_array( $maybe ) || is_object( $maybe ) ) {
			return (string) wp_json_encode( $maybe );
		}
		return (string) $value;
	}

	private static function flatten_many( array $values ): string {
		return implode( ' | ', array_map( array( __CLASS__, 'flatten' ), $values ) );
	}
}
