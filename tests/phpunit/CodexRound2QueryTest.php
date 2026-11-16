<?php
/**
 * Codex R2-2.1: a stored serialized value that refers to itself, or that is
 * nested very deep, is exported as its stored text. Walking it must not run
 * until memory runs out.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class CodexRound2QueryTest extends TestCase {

	/** @var int[] */
	private $posts = array();

	protected function setUp(): void {
		wp_set_current_user( 1 );
	}

	protected function tearDown(): void {
		foreach ( $this->posts as $id ) {
			wp_delete_post( $id, true );
		}
		$this->posts = array();
	}

	/**
	 * Exports one post with the given meta values and returns its row.
	 *
	 * @param array<string, string> $meta Meta key => stored text, written as is.
	 * @return array The exported row.
	 */
	private function row( array $meta ): array {
		global $wpdb;
		$id = wp_insert_post( array( 'post_title' => 'opex r2 serialized', 'post_status' => 'publish', 'post_type' => 'post' ) );
		$this->assertIsInt( $id );
		$this->posts[] = $id;
		$fields        = array( 'core:ID' );
		foreach ( $meta as $key => $text ) {
			$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $id, 'meta_key' => $key, 'meta_value' => $text ) );
			$fields[] = 'meta:' . $key;
		}
		clean_post_cache( $id );
		$config = OPEX_Exporter::sanitize_config(
			array(
				'source'  => 'posts:post',
				'fields'  => $fields,
				'filters' => array( 'rules' => array( array( 'field' => 'core:ID', 'op' => 'eq', 'value' => (string) $id ) ) ),
			)
		);
		$this->assertIsArray( $config );
		$rows = OPEX_Query::batch( $config, 0, 10 )['items'];
		$this->assertCount( 1, $rows );
		return $rows[0];
	}

	/**
	 * A serialized array nested to a depth.
	 *
	 * @param int $depth Levels of arrays.
	 * @return string The serialized text.
	 */
	private function nested( int $depth ): string {
		$value = 'end';
		for ( $i = 0; $i < $depth; $i++ ) {
			$value = array( $value );
		}
		return serialize( $value );
	}

	public function test_a_value_that_refers_to_itself_is_exported_as_its_text(): void {
		$self = 'a:1:{i:0;R:1;}';
		$fan  = 'a:2:{i:0;R:1;i:1;R:1;}';
		$obj  = 'O:8:"stdClass":1:{s:1:"a";r:1;}';
		$row  = $this->row(
			array(
				'opex_r2_self' => $self,
				'opex_r2_fan'  => $fan,
				'opex_r2_obj'  => $obj,
			)
		);
		$this->assertSame( $self, $row['meta:opex_r2_self'] );
		$this->assertSame( $fan, $row['meta:opex_r2_fan'] );
		$this->assertSame( $obj, $row['meta:opex_r2_obj'] );
	}

	public function test_a_very_deep_value_is_exported_as_its_text(): void {
		$deep    = $this->nested( 40 );
		$shallow = $this->nested( 5 );
		$row     = $this->row(
			array(
				'opex_r2_deep'    => $deep,
				'opex_r2_shallow' => $shallow,
			)
		);
		$this->assertSame( $deep, $row['meta:opex_r2_deep'] );
		$this->assertSame( '[[[[["end"]]]]]', $row['meta:opex_r2_shallow'], 'an ordinary nested value still reads as JSON' );
	}

	public function test_a_wide_value_still_reads_as_json(): void {
		$list = range( 1, 5000 );
		$row  = $this->row( array( 'opex_r2_wide' => serialize( array( 'ids' => $list ) ) ) );
		$this->assertSame( (string) wp_json_encode( array( 'ids' => $list ) ), $row['meta:opex_r2_wide'] );
	}
}
