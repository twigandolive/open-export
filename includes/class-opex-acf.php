<?php
/**
 * ACF repeater and flexible content fields as one JSON column each.
 *
 * A field id `acf:<name>` names a top-level repeater or flexible content
 * field that ACF (or Secure Custom Fields) defines for the source. The field
 * is found through ACF's own definitions, never from meta key patterns, and
 * its meta rows (`<name>`, `<name>_<n>_<sub>` and deeper) are read for a
 * whole batch in one query and assembled into a list of row objects.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Finds and reads ACF repeater and flexible content fields.
 */
class OPEX_Acf {

	/** The field id prefix. */
	public const PREFIX = 'acf:';

	/** An ACF field name: what ACF itself allows. */
	private const NAME_PATTERN = '/^[A-Za-z0-9_-]+$/';

	/** How deep nested fields are followed. ACF data deeper than this is left out. */
	private const MAX_DEPTH = 8;

	/**
	 * The most rows read from one repeater or flexible content value.
	 *
	 * The row count is stored text that anyone with edit rights can change. A
	 * broken or hostile count such as 999999999 would make every record loop
	 * that many times and build a cell too large to write. A value cut here is
	 * reported in a job note (see cut_note()), never dropped silently.
	 */
	private const MAX_ROWS = 1000;

	/** Meta table, object id column and row id column per source type. */
	private const META = array(
		'posts' => array( 'postmeta', 'post_id', 'meta_id' ),
		'terms' => array( 'termmeta', 'term_id', 'meta_id' ),
		'users' => array( 'usermeta', 'user_id', 'umeta_id' ),
	);

	/**
	 * Definitions found in this request, by source and site.
	 *
	 * @var array<string, array>
	 */
	private static $memo = array();

	/**
	 * Names of the fields that were cut at MAX_ROWS since take_cut() last ran.
	 *
	 * @var array<string, true>
	 */
	private static $cut = array();

	/**
	 * True once the value being read was cut at MAX_ROWS.
	 *
	 * @var bool
	 */
	private static $cutting = false;

	/**
	 * Whether ACF or Secure Custom Fields is active.
	 *
	 * @return bool True when an ACF-compatible plugin is active.
	 */
	public static function available(): bool {
		return function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' );
	}

	/**
	 * Top-level repeater and flexible content fields ACF defines for a source.
	 *
	 * @param array $src Parsed source.
	 * @return array<string, array> Field name => ACF field definition.
	 */
	public static function fields_for( array $src ): array {
		$type = (string) ( $src['type'] ?? '' );
		if ( ! self::available() || ! isset( self::META[ $type ] ) || ! empty( $src['provider_class'] ) ) {
			return array();
		}
		$key = md5( (string) wp_json_encode( $src ) . '|' . get_current_blog_id() );
		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}
		$out = array();
		foreach ( (array) acf_get_field_groups() as $group ) {
			if ( ! is_array( $group ) || ! OPEX_Acf_Location::applies( (array) ( $group['location'] ?? array() ), $src ) ) {
				continue;
			}
			foreach ( (array) acf_get_fields( $group ) as $field ) {
				$name = (string) ( $field['name'] ?? '' );
				if ( in_array( $field['type'] ?? '', array( 'repeater', 'flexible_content' ), true ) && preg_match( self::NAME_PATTERN, $name ) && ! isset( $out[ $name ] ) ) {
					$out[ $name ] = $field;
				}
			}
		}
		self::$memo[ $key ] = $out;
		return $out;
	}

	/**
	 * Forget definitions and cut fields found so far, for tests that add or remove field groups.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$memo = array();
		self::$cut  = array();
	}

	/**
	 * The fields cut at the row limit since the last call, and forget them.
	 * The query layer calls this around each batch.
	 *
	 * @return string[] Field names, sorted.
	 */
	public static function take_cut(): array {
		$names     = array_map( 'strval', array_keys( self::$cut ) );
		self::$cut = array();
		sort( $names );
		return $names;
	}

	/**
	 * The job note for fields cut at the row limit, or '' when none was cut.
	 *
	 * @param string[] $names Field names from take_cut().
	 * @return string The note.
	 */
	public static function cut_note( array $names ): string {
		if ( ! $names ) {
			return '';
		}
		return sprintf(
			/* translators: 1: ACF field names, joined with commas, 2: the row limit. */
			__( 'Some records hold more than %2$d rows in %1$s. Open Export exported only the first %2$d rows of each.', 'open-export' ),
			implode( ', ', $names ),
			self::MAX_ROWS
		);
	}

	/**
	 * Whether a field id is an ACF field of this source.
	 *
	 * @param array  $src   Parsed source.
	 * @param string $field Field id.
	 * @return bool True when the field id belongs to an ACF field of the source.
	 */
	public static function is_field( array $src, string $field ): bool {
		return null !== self::definition( $src, $field );
	}

	/**
	 * The ACF definition behind a field id, or null.
	 *
	 * @param array  $src   Parsed source.
	 * @param string $field Field id.
	 * @return array|null The ACF field definition, or null when the id is not an ACF field.
	 */
	public static function definition( array $src, string $field ): ?array {
		if ( 0 !== strpos( $field, self::PREFIX ) ) {
			return null;
		}
		$fields = self::fields_for( $src );
		return $fields[ substr( $field, strlen( self::PREFIX ) ) ] ?? null;
	}

	/**
	 * Add the picker group to a source's field groups when it has ACF fields.
	 *
	 * @param array $src    Parsed source.
	 * @param array $groups Field groups.
	 * @return array The field groups, with the ACF group added when the source has ACF fields.
	 */
	public static function add_group( array $src, array $groups ): array {
		$fields = array();
		foreach ( self::fields_for( $src ) as $name => $def ) {
			$label    = (string) ( $def['label'] ?? '' );
			$fields[] = array(
				'id'    => self::PREFIX . $name,
				'label' => '' !== $label ? $label : $name,
				'type'  => 'json',
			);
		}
		if ( $fields ) {
			$groups[] = array(
				'label'  => __( 'ACF repeaters and flexible content', 'open-export' ),
				'fields' => $fields,
			);
		}
		return $groups;
	}

	/**
	 * Read the ACF fields of one batch: one query for every field and record.
	 *
	 * @param array $src    Parsed source.
	 * @param int[] $ids    Record ids of the batch.
	 * @param array $fields Field ids the batch reads.
	 * @return array<string, array<int, string>> Field id => record id => JSON ('' without data).
	 * @throws RuntimeException When the database reports an error.
	 */
	public static function prefetch( array $src, array $ids, array $fields ): array {
		global $wpdb;

		$defs = array();
		foreach ( $fields as $field ) {
			$def = self::definition( $src, (string) $field );
			if ( $def ) {
				$defs[ (string) $field ] = $def;
			}
		}
		if ( ! $defs || ! $ids ) {
			return array();
		}

		list( $table, $fk, $row_id ) = self::META[ $src['type'] ];
		$table                       = $wpdb->{$table};
		$where                       = array();
		$args                        = array_map( 'intval', $ids );
		foreach ( $defs as $def ) {
			$where[] = 'meta_key = %s OR meta_key LIKE %s';
			$args[]  = (string) $def['name'];
			$args[]  = $wpdb->esc_like( $def['name'] . '_' ) . '%';
		}
		$holders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$keys    = implode( ' OR ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the table and columns come from the META constant and $keys holds only placeholders; every value is bound.
		$sql = $wpdb->prepare( "SELECT {$fk} AS oid, meta_key, meta_value FROM {$table} WHERE {$fk} IN ({$holders}) AND ({$keys}) ORDER BY {$row_id}", $args );

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared above: the table and columns come from the META constant and the key tests hold only placeholders. Export batches are uncached.
		$rows = $wpdb->get_results( $sql );
		OPEX_Query::throw_on_db_error();

		$meta = array();
		foreach ( (array) $rows as $row ) {
			$key = (string) $row->meta_key;
			if ( ! OPEX_Query::is_credential_meta( $key ) && ! isset( $meta[ (int) $row->oid ][ $key ] ) ) {
				$meta[ (int) $row->oid ][ $key ] = (string) $row->meta_value;
			}
		}

		$out = array();
		foreach ( $defs as $field => $def ) {
			foreach ( $ids as $id ) {
				$values               = $meta[ (int) $id ] ?? array();
				$name                 = (string) $def['name'];
				self::$cutting        = false;
				$out[ $field ][ $id ] = isset( $values[ $name ] ) ? (string) wp_json_encode( self::rows( $def, $name, $values, 0 ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '';
				if ( self::$cutting ) {
					// Named by its top-level field, also when a nested value was the one cut.
					self::$cut[ $name ] = true;
				}
			}
		}
		return $out;
	}

	/**
	 * Sub field names a spread column set uses, in definition order. A flexible
	 * content field starts with acf_fc_layout, then every layout's sub fields.
	 *
	 * @param array $def ACF field definition.
	 * @return string[]
	 */
	public static function sub_names( array $def ): array {
		$names = array();
		if ( 'flexible_content' === ( $def['type'] ?? '' ) ) {
			$names[] = 'acf_fc_layout';
			foreach ( (array) ( $def['layouts'] ?? array() ) as $layout ) {
				foreach ( (array) ( $layout['sub_fields'] ?? array() ) as $sub ) {
					$names[] = (string) ( $sub['name'] ?? '' );
				}
			}
		} else {
			foreach ( (array) ( $def['sub_fields'] ?? array() ) as $sub ) {
				$names[] = (string) ( $sub['name'] ?? '' );
			}
		}
		return array_values( array_unique( array_filter( $names, 'strlen' ) ) );
	}

	/**
	 * The number of rows a stored top-level value holds: the count of a
	 * repeater, or the length of a flexible content layout list.
	 *
	 * @param array  $def   ACF field definition.
	 * @param string $value Stored value (only its start is needed).
	 * @return int Number of rows stored in the value.
	 */
	public static function row_count( array $def, string $value ): int {
		if ( 'flexible_content' === ( $def['type'] ?? '' ) ) {
			return preg_match( '/^a:(\d+):/', $value, $m ) ? (int) $m[1] : 0;
		}
		return max( 0, (int) $value );
	}

	/**
	 * Rows of a repeater or flexible content field.
	 *
	 * @param array  $def    Field definition.
	 * @param string $prefix Meta key of the field.
	 * @param array  $meta   Meta of one record, key => value.
	 * @param int    $depth  Nesting depth.
	 * @return array One object per row, each keyed by sub field name.
	 */
	private static function rows( array $def, string $prefix, array $meta, int $depth ): array {
		$stored = (string) ( $meta[ $prefix ] ?? '' );
		$rows   = array();
		if ( 'flexible_content' === ( $def['type'] ?? '' ) ) {
			$layouts = array();
			foreach ( (array) ( $def['layouts'] ?? array() ) as $layout ) {
				$layouts[ (string) ( $layout['name'] ?? '' ) ] = (array) ( $layout['sub_fields'] ?? array() );
			}
			$list = self::stored_value( $stored );
			$list = is_array( $list ) ? array_values( $list ) : array();
			if ( count( $list ) > self::MAX_ROWS ) {
				self::$cutting = true;
			}
			foreach ( array_slice( $list, 0, self::MAX_ROWS ) as $i => $name ) {
				$name   = is_scalar( $name ) ? (string) $name : '';
				$rows[] = array_merge( array( 'acf_fc_layout' => $name ), self::subs( $layouts[ $name ] ?? array(), $prefix . '_' . $i, $meta, $depth ) );
			}
			return $rows;
		}
		$count = self::row_count( $def, $stored );
		if ( $count > self::MAX_ROWS ) {
			self::$cutting = true;
			$count         = self::MAX_ROWS;
		}
		for ( $i = 0; $i < $count; $i++ ) {
			$rows[] = self::subs( (array) ( $def['sub_fields'] ?? array() ), $prefix . '_' . $i, $meta, $depth );
		}
		return $rows;
	}

	/**
	 * One row object: each sub field by name.
	 *
	 * @param array  $subs  Sub field definitions.
	 * @param string $base  Meta key of the row, without the sub field name.
	 * @param array  $meta  Meta of one record.
	 * @param int    $depth Nesting depth.
	 * @return array The row, keyed by sub field name.
	 */
	private static function subs( array $subs, string $base, array $meta, int $depth ): array {
		$row = array();
		foreach ( $subs as $sub ) {
			$name = (string) ( $sub['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$key  = $base . '_' . $name;
			$type = (string) ( $sub['type'] ?? '' );
			if ( $depth >= self::MAX_DEPTH ) {
				$row[ $name ] = '';
			} elseif ( in_array( $type, array( 'repeater', 'flexible_content' ), true ) ) {
				$row[ $name ] = self::rows( $sub, $key, $meta, $depth + 1 );
			} elseif ( 'group' === $type ) {
				$row[ $name ] = (object) self::subs( (array) ( $sub['sub_fields'] ?? array() ), $key, $meta, $depth + 1 );
			} else {
				$row[ $name ] = self::stored_value( (string) ( $meta[ $key ] ?? '' ) );
			}
		}
		return $row;
	}

	/**
	 * A stored meta value: a list or map when ACF saved an array (checkboxes,
	 * galleries, relationships), else the text. Objects are never created.
	 *
	 * @param string $value Stored value.
	 * @return mixed
	 */
	private static function stored_value( string $value ) {
		if ( ! is_serialized( $value ) ) {
			return $value;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors -- stored ACF data, read with classes disabled; a broken value reads as empty.
		$data = @unserialize( trim( $value ), array( 'allowed_classes' => false ) );
		return is_array( $data ) || is_scalar( $data ) ? $data : '';
	}
}
