<?php
/**
 * PHPUnit bootstrap: loads a real WordPress site and every plugin class.
 *
 * @package Open_Export
 */

$opex_site = getenv( 'OPEX_TEST_SITE' );
if ( ! $opex_site || ! is_file( rtrim( $opex_site, '/' ) . '/wordpress/wp-load.php' ) ) {
	fwrite( STDERR, "OPEX_TEST_SITE must point to a folder that contains wordpress/wp-load.php (build one with tests/bin/setup-site.sh).\n" );
	exit( 1 );
}

@ini_set( 'memory_limit', '1G' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
error_reporting( E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED );

$_SERVER['HTTP_HOST']       = 'example.test';
$_SERVER['SERVER_NAME']     = 'example.test';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';

require_once rtrim( $opex_site, '/' ) . '/wordpress/wp-load.php';

$opex_root = dirname( __DIR__, 2 );
foreach ( (array) glob( $opex_root . '/includes/class-opex-*.php' ) as $opex_file ) {
	require_once $opex_file;
}

wp_set_current_user( 1 );
