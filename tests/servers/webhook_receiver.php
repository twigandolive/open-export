<?php
/**
 * Router for `php -S`: records each request and answers 200, 500 on /fail, and a 302 to /hook on /redirect.
 * Env OPEX_RECV_DIR is the folder for request.json.
 */
$dir  = getenv( 'OPEX_RECV_DIR' );
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
file_put_contents(
	$dir . '/request.json',
	json_encode(
		array(
			'path'      => $path,
			'method'    => $_SERVER['REQUEST_METHOD'],
			'signature' => $_SERVER['HTTP_X_OPEX_SIGNATURE'] ?? null,
			'type'      => $_SERVER['CONTENT_TYPE'] ?? null,
			'body_b64'  => base64_encode( file_get_contents( 'php://input' ) ),
		)
	)
);
if ( '/fail' === $path ) {
	http_response_code( 500 );
	echo 'secret-looking body that must never be echoed';
	return;
}
if ( '/redirect' === $path ) {
	http_response_code( 302 );
	header( 'Location: /hook' );
	return;
}
http_response_code( 200 );
echo 'ok';
