<?php
/**
 * Provider base for sources that are a joined query over tables.
 *
 * The provider supplies a FROM clause and a map of field id to SQL expression.
 * Every join must be many-to-one, so a row is one record and cursor paging on
 * the primary key stays sound.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * SQL-mapped tables: a FROM clause and a field map.
 */
abstract class OPEX_Source_Table extends OPEX_Source_Provider {

	/**
	 * The source type the engine passes around for this provider.
	 *
	 * @return string The source type.
	 */
	abstract public function src_type(): string;

	/**
	 * {@inheritDoc}
	 *
	 * @return array The parsed source.
	 */
	public function src(): array {
		return array(
			'type'           => $this->src_type(),
			'provider'       => $this->id(),
			'provider_class' => get_class( $this ),
		);
	}
}
