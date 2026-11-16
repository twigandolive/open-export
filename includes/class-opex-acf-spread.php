<?php
/**
 * Spreads selected ACF fields into one column per row and sub field.
 *
 * A field listed in the config's `acf_flatten` is written as columns named
 * "<label> <n> <sub>", for n from 1 to the largest row count found when the
 * job is created (at most 20). The columns are fixed in the job's headers,
 * so every batch writes the same columns. Fields not listed stay one JSON
 * column.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Plans and fills the spread columns of ACF fields.
 */
class OPEX_Acf_Spread {

	/** The most rows spread into columns for one field. */
	public const MAX_ROWS = 20;

	/** Separates the parts of a spread column key: "acf:<name>|<n>|<sub>". ACF names never hold it. */
	private const SEP = '|';

	/**
	 * Clean the acf_flatten list: selected acf: fields only, each once.
	 *
	 * @param mixed    $raw    Raw list.
	 * @param string[] $fields Selected field ids.
	 * @return string[]
	 */
	public static function sanitize( $raw, array $fields ): array {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $field ) {
			$field = is_string( $field ) ? $field : '';
			if ( 0 === strpos( $field, OPEX_Acf::PREFIX ) && in_array( $field, $fields, true ) && ! in_array( $field, $out, true ) ) {
				$out[] = $field;
			}
		}
		return $out;
	}

	/**
	 * Headers with each spread field replaced by its columns. The row counts
	 * are read now, so the columns stay the same for the whole job.
	 *
	 * @param array    $config  Export config.
	 * @param array    $headers Field id => label.
	 * @param string[] $notes   Receives notes.
	 * @return array Column key => label.
	 * @throws RuntimeException When the database reports an error.
	 */
	public static function headers( array $config, array $headers, array &$notes ): array {
		$spread = (array) ( $config['acf_flatten'] ?? array() );
		$src    = $spread ? OPEX_Query::parse_source( (string) ( $config['source'] ?? '' ) ) : null;
		if ( ! $src ) {
			return $headers;
		}
		$out  = array();
		$used = array();
		foreach ( $headers as $field => $label ) {
			$def = in_array( $field, $spread, true ) ? OPEX_Acf::definition( $src, (string) $field ) : null;
			if ( ! $def ) {
				$out[ $field ]  = $label;
				$used[ $label ] = true;
				continue;
			}
			$max = self::max_rows( $src, $def );
			if ( $max > self::MAX_ROWS ) {
				/* translators: 1: column name, 2: largest row count, 3: the cap. */
				$notes[] = sprintf( __( '"%1$s" has up to %2$d rows. Open Export spreads only the first %3$d into columns.', 'open-export' ), $label, $max, self::MAX_ROWS );
			}
			$count = min( $max, self::MAX_ROWS );
			if ( 0 === $count ) {
				/* translators: %s: column name. */
				$notes[] = sprintf( __( 'No record has rows in "%s", so it adds no columns.', 'open-export' ), $label );
			}
			for ( $n = 1; $n <= $count; $n++ ) {
				foreach ( OPEX_Acf::sub_names( $def ) as $sub ) {
					$name = $label . ' ' . $n . ' ' . $sub;
					$base = $name;
					for ( $k = 2; isset( $used[ $name ] ); $k++ ) {
						$name = $base . '_' . $k;
					}
					$used[ $name ]                                     = true;
					$out[ $field . self::SEP . $n . self::SEP . $sub ] = $name;
				}
			}
		}
		return $out;
	}

	/**
	 * Rows ready for the writer: transforms applied, then spread columns filled.
	 *
	 * @param array $items   Rows from the query, keyed by field id.
	 * @param array $config  Export config.
	 * @param array $headers Job headers (column key => label).
	 * @return array Rows to hand to the writer.
	 */
	public static function output( array $items, array $config, array $headers ): array {
		return self::fill( OPEX_Transforms::apply( $items, $config ), $items, $headers );
	}

	/**
	 * Fill spread columns from the raw JSON of each field. Transforms act on
	 * the JSON column, so spread cells hold the stored sub values.
	 *
	 * @param array $rows    Transformed rows.
	 * @param array $items   Raw rows, in the same order.
	 * @param array $headers Column key => label.
	 * @return array The transformed rows with their spread columns filled.
	 */
	public static function fill( array $rows, array $items, array $headers ): array {
		$cells = array();
		foreach ( array_keys( $headers ) as $key ) {
			$parts = explode( self::SEP, (string) $key );
			if ( 3 === count( $parts ) && 0 === strpos( $parts[0], OPEX_Acf::PREFIX ) ) {
				$cells[ (string) $key ] = array( $parts[0], (int) $parts[1] - 1, $parts[2] );
			}
		}
		if ( ! $cells ) {
			return $rows;
		}
		foreach ( $rows as $i => $row ) {
			$decoded = array();
			foreach ( $cells as $key => list( $field, $index, $sub ) ) {
				if ( ! array_key_exists( $field, $decoded ) ) {
					$data              = json_decode( (string) ( $items[ $i ][ $field ] ?? '' ), true );
					$decoded[ $field ] = is_array( $data ) ? $data : array();
				}
				$value       = $decoded[ $field ][ $index ][ $sub ] ?? '';
				$row[ $key ] = is_array( $value ) ? (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : (string) $value;
			}
			$rows[ $i ] = $row;
		}
		return $rows;
	}

	/**
	 * The largest row count stored for a field across the source's records.
	 * Only the start of each value is read: a count, or a serialized list's length.
	 *
	 * @param array $src Parsed source.
	 * @param array $def ACF field definition.
	 * @return int The largest row count found, or 0 when none is stored.
	 * @throws RuntimeException When the database reports an error.
	 */
	private static function max_rows( array $src, array $def ): int {
		global $wpdb;

		$name = (string) $def['name'];
		switch ( $src['type'] ) {
			case 'posts':
				$sql = $wpdb->prepare( "SELECT SUBSTR(m.meta_value, 1, 24) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_type = %s", $name, (string) $src['post_type'] );
				break;
			case 'terms':
				$sql = $wpdb->prepare( "SELECT SUBSTR(m.meta_value, 1, 24) FROM {$wpdb->termmeta} m INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = m.term_id WHERE m.meta_key = %s AND tt.taxonomy = %s", $name, (string) $src['taxonomy'] );
				break;
			case 'users':
				// The same members-only scope the users export has, so a site admin on a
				// network never sizes the columns, or reads a note, from another site's users.
				list( $terms, $scope_args ) = OPEX_Access::users_scope();
				$where                      = $terms ? ' AND ' . implode( ' AND ', $terms ) : '';
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the tables come from $wpdb and the scope terms from OPEX_Access hold only placeholders. Every value is bound.
				$sql = $wpdb->prepare( "SELECT SUBSTR(m.meta_value, 1, 24) FROM {$wpdb->usermeta} m INNER JOIN {$wpdb->users} u ON u.ID = m.user_id WHERE m.meta_key = %s{$where}", array_merge( array( $name ), $scope_args ) );
				break;
			default:
				return 0;
		}
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared above: the tables come from $wpdb and the scope terms from OPEX_Access hold only placeholders. Read once at job creation.
		$values = $wpdb->get_col( $sql );
		OPEX_Query::throw_on_db_error();
		$max = 0;
		foreach ( (array) $values as $value ) {
			$max = max( $max, OPEX_Acf::row_count( $def, (string) $value ) );
		}
		return $max;
	}
}
