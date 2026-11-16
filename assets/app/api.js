/**
 * Open Export admin screen: the one door to the REST API.
 *
 * Every request goes through wp.apiFetch under open-export/v1, so the nonce,
 * nonce refresh and root URL come from WordPress itself. Errors always come
 * back in one shape, and an expired session is handled here once instead of
 * in every tab.
 *
 * Exposes opexApp.api:
 *   get( path )                 GET  /open-export/v1<path>.
 *   post( path, data )          POST with a JSON body.
 *   del( path )                 DELETE.
 *   request( options )          Any apiFetch options, path relative to the namespace.
 *   downloadUrl( jobId )        A plain link that streams a finished file.
 *   hasRoute( fragment )        Resolves true when the server registers a matching route.
 *
 * When the server answers 401 or 403, the store key "session" is set to
 * "expired" and main.js shows the reload message.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __ } = window.wp.i18n;
	const apiFetch = window.wp.apiFetch;
	const boot = app.boot;
	const base = '/' + String( boot.restBase || 'open-export/v1' ).replace( /^\/+|\/+$/g, '' );

	// WordPress registers its own nonce middleware on every admin page. Add
	// one only if it is missing, so the built-in nonce refresh keeps working.
	if ( apiFetch && ! apiFetch.nonceMiddleware && boot.restNonce ) {
		apiFetch.nonceMiddleware = apiFetch.createNonceMiddleware( boot.restNonce );
		apiFetch.use( apiFetch.nonceMiddleware );
	}

	/**
	 * Turns whatever apiFetch rejected with into { code, message, status }.
	 *
	 * @param {*} error Rejection value.
	 * @return {{code: string, message: string, status: number}} Normalized error.
	 */
	function normalize( error ) {
		const raw = error && 'object' === typeof error ? error : {};
		const data = raw.data && 'object' === typeof raw.data ? raw.data : {};
		let status = Number( data.status || raw.status || 0 );
		let code = String( raw.code || '' );
		let message = 'string' === typeof raw.message ? raw.message : '';
		if ( 'fetch_error' === code || ( ! code && ! status ) ) {
			code = code || 'fetch_error';
			message = __( 'Could not reach the server. Check your connection and try again.', 'open-export' );
		}
		if ( 'invalid_json' === code ) {
			message = __( 'The server sent an answer the screen could not read. Try again.', 'open-export' );
		}
		// Some answers carry HTML, such as the WordPress critical error page.
		// Show them as plain text.
		message = message.replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).trim();
		if ( ! message ) {
			message = __( 'Something went wrong. Try again.', 'open-export' );
		}
		if ( 'rest_cookie_invalid_nonce' === code ) {
			status = status || 403;
		}
		// Per-field messages from WordPress argument validation.
		const params = data.params && 'object' === typeof data.params ? data.params : null;
		return { code, message, status, params };
	}

	/**
	 * Tells whether a failed request means the login or the nonce has expired, so the screen can ask the person to log in again.
	 *
	 * @param {Object} err Normalized error with a status and a code.
	 * @return {boolean} True when the session is gone.
	 */
	function isSessionError( err ) {
		if ( 401 === err.status ) {
			return true;
		}
		// A 403 from the permission callback or nonce check means the login or
		// nonce is gone. Every route here uses the same capability.
		return 403 === err.status && /^rest_(forbidden|cookie_invalid_nonce|not_logged_in)/.test( err.code );
	}

	/**
	 * Sends a request. Resolves with the parsed body, rejects with a
	 * normalized error.
	 *
	 * @param {Object} options apiFetch options. path is relative to the namespace.
	 * @return {Promise<*>} Body.
	 */
	function request( options ) {
		const opts = Object.assign( {}, options, { path: base + ( options.path || '' ) } );
		return apiFetch( opts ).catch( ( error ) => {
			const err = normalize( error );
			if ( isSessionError( err ) ) {
				err.session = true;
				app.store.set( 'session', 'expired' );
			}
			throw err;
		} );
	}

	/**
	 * Sends a GET request to a plugin route.
	 */
	function get( path ) {
		return request( { path, method: 'GET' } );
	}

	/**
	 * Sends a POST request to a plugin route, with an empty body when there is no data.
	 */
	function post( path, data ) {
		return request( { path, method: 'POST', data: data || {} } );
	}

	/**
	 * Sends a DELETE request to a plugin route.
	 */
	function del( path ) {
		return request( { path, method: 'DELETE' } );
	}

	/**
	 * A download link. It is a plain GET, so the nonce rides in the query.
	 * The current nonce is used, which may be newer than the one in OPEX_BOOT.
	 *
	 * @param {string} jobId Job id.
	 * @return {string} URL.
	 */
	function downloadUrl( jobId ) {
		const root = String( boot.restRoot || '' ).replace( /\/$/, '' );
		const url = root + '/jobs/' + encodeURIComponent( jobId ) + '/download';
		const nonce = ( apiFetch && apiFetch.nonceMiddleware && apiFetch.nonceMiddleware.nonce ) || boot.restNonce || '';
		return url + ( url.indexOf( '?' ) > -1 ? '&' : '?' ) + '_wpnonce=' + encodeURIComponent( nonce );
	}

	let routes = null;

	/**
	 * Whether the server registers a route whose pattern contains the given
	 * text. Reads the namespace index once. Used to show controls that need
	 * newer routes only when the server has them.
	 *
	 * @param {string} fragment Part of a route pattern, such as "/deliver".
	 * @return {Promise<boolean>} True when a route matches.
	 */
	function hasRoute( fragment ) {
		if ( ! routes ) {
			routes = get( '' ).then( ( index ) => Object.keys( ( index && index.routes ) || {} ) ).catch( () => [] );
		}
		return routes.then( ( list ) => list.some( ( route ) => route.indexOf( fragment ) > -1 ) );
	}

	app.api = { request, get, post, del, downloadUrl, hasRoute };
}() );
