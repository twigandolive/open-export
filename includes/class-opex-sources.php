<?php
/**
 * Registry of export source providers.
 *
 * Maps a source id to the provider that serves it. The query, catalog and
 * schema code ask here instead of branching on a source type.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Finds the provider for a source id or a parsed source.
 */
class OPEX_Sources {

	/**
	 * Source id => resolver. A resolver returns the provider to use now, or
	 * null when the source is not available on this site.
	 *
	 * @var array<string, callable>|null
	 */
	private static $resolvers = null;

	/**
	 * Provider objects by class name. They hold no state, so one each is enough.
	 *
	 * @var array<string, OPEX_Source_Provider>
	 */
	private static $instances = array();

	/**
	 * The registered resolvers, in picker order.
	 *
	 * @return array<string, callable>
	 */
	private static function resolvers(): array {
		if ( null === self::$resolvers ) {
			self::$resolvers = array(
				OPEX_Woo::SOURCE                    => array( 'OPEX_Source_Woo_Orders', 'resolve' ),
				OPEX_Source_Woo_Order_Items::SOURCE => array( 'OPEX_Source_Woo_Order_Items', 'resolve' ),
				OPEX_Source_Woo_Customers::SOURCE   => array( 'OPEX_Source_Woo_Customers', 'resolve' ),
				OPEX_Source_Woo_Products::SOURCE    => array( 'OPEX_Source_Woo_Products', 'resolve' ),
				OPEX_Source_Woo_Coupons::SOURCE     => array( 'OPEX_Source_Woo_Coupons', 'resolve' ),
			);
		}
		return self::$resolvers;
	}

	/**
	 * One shared object per provider class.
	 *
	 * @param string $class_name Provider class name.
	 */
	public static function instance( string $class_name ): OPEX_Source_Provider {
		if ( ! isset( self::$instances[ $class_name ] ) ) {
			self::$instances[ $class_name ] = new $class_name();
		}
		return self::$instances[ $class_name ];
	}

	/**
	 * The provider for a source id, or null when the id is not a registered
	 * source or the source is not available on this site.
	 *
	 * @param string $source_id Source id.
	 */
	public static function get( string $source_id ): ?OPEX_Source_Provider {
		$resolvers = self::resolvers();
		if ( ! isset( $resolvers[ $source_id ] ) ) {
			return null;
		}
		$provider = call_user_func( $resolvers[ $source_id ] );
		return ( $provider instanceof OPEX_Source_Provider && $provider->is_available() ) ? $provider : null;
	}

	/**
	 * Every available provider, in picker order.
	 *
	 * @return OPEX_Source_Provider[]
	 */
	public static function all(): array {
		$out = array();
		foreach ( array_keys( self::resolvers() ) as $source_id ) {
			$provider = self::get( $source_id );
			if ( $provider ) {
				$out[] = $provider;
			}
		}
		return $out;
	}

	/**
	 * The parsed source for a registered source id, or null.
	 *
	 * @param string $source_id Source id.
	 */
	public static function parse( string $source_id ): ?array {
		$provider = self::get( $source_id );
		return $provider ? $provider->src() : null;
	}

	/**
	 * The provider that built a parsed source, or null for sources without one.
	 *
	 * @param array $src Parsed source.
	 */
	public static function for_src( array $src ): ?OPEX_Source_Provider {
		if ( empty( $src['provider_class'] ) ) {
			return null;
		}
		$class = (string) $src['provider_class'];
		return is_subclass_of( $class, 'OPEX_Source_Provider' ) ? self::instance( $class ) : null;
	}

	/**
	 * The provider for a parsed source, or for the raw post type a provider
	 * replaces (so the raw shop_order source shares the order status values).
	 *
	 * @param array $src Parsed source.
	 */
	public static function for_values( array $src ): ?OPEX_Source_Provider {
		$provider = self::for_src( $src );
		if ( $provider ) {
			return $provider;
		}
		if ( 'posts' !== ( $src['type'] ?? '' ) ) {
			return null;
		}
		foreach ( self::all() as $candidate ) {
			if ( '' !== $candidate->raw_post_type() && $candidate->raw_post_type() === ( $src['post_type'] ?? '' ) ) {
				return $candidate;
			}
		}
		return null;
	}
}
