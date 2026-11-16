<?php
/**
 * GET /templates rows carry source and source_label, and a template keeps
 * the pass (e) output keys through a save.
 *
 * Templates made here are deleted in tearDown.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

/**
 * Template list rows and the new config keys.
 */
class TemplateSourceTest extends TestCase {

	/** @var string[] Template ids. */
	private array $ids = array();

	protected function setUp(): void {
		wp_set_current_user( 1 );
	}

	protected function tearDown(): void {
		foreach ( $this->ids as $id ) {
			OPEX_Templates::delete( $id );
		}
		$this->ids = array();
		OPEX_Source_Labels::reset();
	}

	/**
	 * The /templates row for an id.
	 *
	 * @param string $id Template id.
	 */
	private function row( string $id ): array {
		foreach ( OPEX_Rest::templates()->get_data() as $row ) {
			if ( $row['id'] === $id ) {
				return $row;
			}
		}
		$this->fail( 'The template is not listed.' );
	}

	/** Each row names its source, add-only after the existing keys. */
	public function test_rows_carry_the_source(): void {
		$saved = OPEX_Templates::save(
			'Source row test',
			array(
				'source' => 'posts:post',
				'fields' => array( 'core:ID' ),
			)
		);
		$this->assertIsArray( $saved );
		$this->ids[] = $saved['id'];
		$row         = $this->row( $saved['id'] );
		$this->assertSame( array( 'id', 'name', 'config', 'created', 'updated', 'source', 'source_label' ), array_keys( $row ) );
		$this->assertSame( 'posts:post', $row['source'] );
		$this->assertSame( get_post_type_object( 'post' )->labels->name, $row['source_label'] );
	}

	/** A template whose source is gone still lists, with an empty label. */
	public function test_missing_source_has_an_empty_label(): void {
		$saved = OPEX_Templates::save(
			'Gone source test',
			array(
				'source' => 'posts:post',
				'fields' => array( 'core:ID' ),
			)
		);
		$this->ids[] = $saved['id'];
		$all         = get_option( 'opex_templates' );
		$all[ $saved['id'] ]['config']['source'] = 'posts:opex_gone_type';
		update_option( 'opex_templates', $all, false );
		$row = $this->row( $saved['id'] );
		$this->assertSame( 'posts:opex_gone_type', $row['source'] );
		$this->assertSame( '', $row['source_label'] );
	}

	/** The quoting, compression and HTML choices survive a template save and load. */
	public function test_new_keys_round_trip(): void {
		$saved = OPEX_Templates::save(
			'Output keys test',
			array(
				'source'        => 'posts:post',
				'fields'        => array( 'core:ID' ),
				'csv_enclosure' => 'single',
				'csv_quote_all' => true,
				'compress'      => class_exists( 'ZipArchive' ) ? 'zip' : 'none',
			)
		);
		$this->assertIsArray( $saved );
		$this->ids[] = $saved['id'];
		$config      = OPEX_Templates::get( $saved['id'] )['config'];
		$this->assertSame( 'single', $config['csv_enclosure'] );
		$this->assertTrue( $config['csv_quote_all'] );
		$this->assertSame( $config, OPEX_Exporter::sanitize_config( $config ), 'saving again changes nothing' );

		$html = OPEX_Templates::save( 'Output keys test', array_merge( $config, array( 'format' => 'html' ) ) );
		$this->assertSame( $saved['id'], $html['id'] );
		$this->assertSame( 'html', OPEX_Templates::get( $saved['id'] )['config']['format'] );
	}
}
