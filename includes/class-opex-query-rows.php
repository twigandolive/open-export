<?php
/**
 * Turns database rows into export rows keyed by field id.
 *
 * Part of the query compartment. Reached only through OPEX_Query.
 *
 * @package Open_Export
 */

// No strict_types here on purpose: strict types would change how this code coerces arguments.

defined( 'ABSPATH' ) || exit;

/**
 * Turns database rows into export rows keyed by field id.
 */
class OPEX_Query_Rows {

	/** Deepest nesting of a stored serialized value that is read as JSON. */
	private const MAX_DEPTH = 32;

	/** Most parts of a stored serialized value that are walked before it is read as text. */
	private const MAX_NODES = 100000;

	/**
	 * Turn raw DB rows into export items keyed by field id, prefetching all
	 * meta and taxonomy values for the batch in one query each.
	 *
	 * Internal to the query compartment: other code calls OPEX_Query.
	 *
	 * @param array         $src    Parsed source.
	 * @param array         $rows   Database rows.
	 * @param string[]      $fields Field ids in output order.
	 * @param callable|null $tick   Called during long batches so the job lock stays fresh.
	 * @return array[] One item per row, keyed by field id.
	 */
	public static function assemble( array $src, array $rows, array $fields, $tick = null ): array {
		if ( ! $rows ) {
			return array();
		}

		$provider = OPEX_Sources::for_src( $src );
		$id_prop  = $provider ? $provider->id_prop() : ( array(
			'posts'    => 'ID',
			'users'    => 'ID',
			'comments' => 'comment_ID',
			'terms'    => 'term_id',
		)[ $src['type'] ] ?? '' );
		$ids      = array();
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
		$tax = array();
		foreach ( $tax_names as $tax_name ) {
			$tax[ $tax_name ] = self::prefetch_terms( $ids, $tax_name );
		}

		$items = array();
		$n     = 0;
		foreach ( $rows as $row ) {
			// Let a long batch keep its lock alive.
			if ( is_callable( $tick ) && 0 === $n % 100 ) {
				call_user_func( $tick );
			}
			++$n;
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
	 *
	 * @param array  $src   Parsed source.
	 * @param object $row   Database row.
	 * @param string $field Field id.
	 * @param int    $id    Row id.
	 * @param array  $meta  Meta values of the batch, by row id and key.
	 * @param array  $tax   Term names of the batch, by taxonomy and row id.
	 * @return string The cell text.
	 */
	private static function field_value( array $src, object $row, string $field, int $id, array $meta, array $tax ): string {
		$value    = self::raw_value( $src, $row, $field, $id, $meta, $tax );
		$provider = OPEX_Sources::for_src( $src );
		return $provider ? $provider->format_value( $field, $value ) : $value;
	}

	/**
	 * The value of one field for one row, before transforms.
	 *
	 * @param array  $src   Parsed source.
	 * @param object $row   Database row.
	 * @param string $field Field id.
	 * @param int    $id    Row id.
	 * @param array  $meta  Meta values of the batch, by row id and key.
	 * @param array  $tax   Term names of the batch, by row id and taxonomy.
	 * @return string The cell text before transforms.
	 */
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
		$value = self::resolved_value( $row, OPEX_Query::resolve_field( $src, $field ), $id, $meta, $tax );
		return $provider ? $provider->derive_value( $field, $value ) : $value;
	}

	/**
	 * One cell read by its storage id (core, col, meta, tax or computed).
	 *
	 * @param object $row   Database row.
	 * @param string $field Field id.
	 * @param int    $id    Row id.
	 * @param array  $meta  Meta values of the batch, by row id and key.
	 * @param array  $tax   Term names of the batch, by taxonomy and row id.
	 * @return string The cell text.
	 */
	private static function resolved_value( object $row, string $field, int $id, array $meta, array $tax ): string {
		if ( 0 === strpos( $field, 'core:' ) ) {
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
			return self::computed_value( $row, substr( $field, 9 ), $id );
		}
		return '';
	}

	/**
	 * A computed field: a value the database does not hold as such, like a permalink or roles.
	 *
	 * @param object $row  Database row.
	 * @param string $what Computed field name, without the prefix.
	 * @param int    $id   Row id.
	 * @return string The cell text.
	 */
	private static function computed_value( object $row, string $what, int $id ): string {
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

	/**
	 * One query fetches every requested meta value for the whole batch.
	 *
	 * @param array    $src  Parsed source, which names the meta table.
	 * @param int[]    $ids  Row ids of the batch.
	 * @param string[] $keys Meta keys the export needs.
	 * @return array Meta values by row id and key.
	 */
	private static function prefetch_meta( array $src, array $ids, array $keys ): array {
		global $wpdb;

		$map      = array(
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

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table and column names come from the fixed map above, and the placeholder lists are built above.
			"SELECT {$fk} AS oid, meta_key, meta_value FROM {$table} WHERE {$fk} IN ({$id_holders}) AND meta_key IN ({$key_holders})",
			array_merge( $ids, $keys )
		);

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared above: the table and key column come from the fixed meta map and the IN lists hold only placeholders. Streaming export batches are inherently uncached.
		$rows = $wpdb->get_results( $sql );
		OPEX_Query::throw_on_db_error();

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->oid ][ $row->meta_key ][] = $row->meta_value;
		}
		return $out;
	}

	/**
	 * Term names per object id for one taxonomy, one query per batch.
	 *
	 * @param int[]  $ids      Object ids of the batch.
	 * @param string $taxonomy Taxonomy name.
	 * @return array<int, string[]> Term names by object id.
	 */
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

	/**
	 * Decode serialized values to JSON for readable output.
	 *
	 * Stored text can come from any contributor, so it is read the way
	 * maybe_unserialize() reads it but with classes turned off: PHP never
	 * builds an object, so no class code (__wakeup, __destruct) can run. Only
	 * stdClass is allowed, since it has no code. A value that holds any other
	 * object, or that refers to itself or is nested too deep to walk, is
	 * exported as its stored text.
	 *
	 * @param mixed $value Stored value.
	 * @return string The value as text, with arrays and plain objects as JSON.
	 */
	private static function flatten( $value ): string {
		if ( is_null( $value ) ) {
			return '';
		}
		$text = (string) $value;
		if ( ! is_serialized( $text ) ) {
			return $text;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors -- read with classes off, as maybe_unserialize() would but safely. A broken value reads as its text.
		$data = @unserialize( trim( $text ), array( 'allowed_classes' => array( 'stdClass' ) ) );
		if ( ( is_array( $data ) || is_object( $data ) ) && ! self::holds_class_object( $data ) ) {
			return (string) wp_json_encode( $data );
		}
		return $text;
	}

	/**
	 * Whether an unserialized value holds an object of a class that was not
	 * allowed, which PHP leaves as __PHP_Incomplete_Class.
	 *
	 * The walk is bounded. A stored value can refer to itself ("R:1"), and
	 * walking that would never end. So a value nested deeper than MAX_DEPTH,
	 * or with more than MAX_NODES parts, counts as unsafe too, and the
	 * caller exports its stored text as it is.
	 *
	 * @param mixed $data  Unserialized value.
	 * @param int   $depth How deep this part sits. 0 for the whole value.
	 * @param int   $nodes Parts seen so far in this walk.
	 * @return bool True when the value holds such an object at any depth, or is too deep or too large to walk.
	 */
	private static function holds_class_object( $data, int $depth = 0, int &$nodes = 0 ): bool {
		if ( ++$nodes > self::MAX_NODES || $depth > self::MAX_DEPTH ) {
			return true;
		}
		if ( is_object( $data ) && ! $data instanceof stdClass ) {
			return true;
		}
		if ( is_array( $data ) || is_object( $data ) ) {
			foreach ( (array) $data as $item ) {
				if ( self::holds_class_object( $item, $depth + 1, $nodes ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Several values in one cell, joined with " | ".
	 *
	 * @param array $values Values.
	 * @return string The values in one cell.
	 */
	private static function flatten_many( array $values ): string {
		return implode( ' | ', array_map( array( __CLASS__, 'flatten' ), $values ) );
	}
}
