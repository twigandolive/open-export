/**
 * Open Export admin screen: the shared core.
 *
 * Creates the window.opexApp namespace that every other file adds to, and
 * gives them a small DOM builder and the formatters the screen needs.
 *
 * Exposes on opexApp:
 *   boot                  OPEX_BOOT from PHP (restBase, restNonce, restRoot, adminUrl).
 *   el( tag, attrs, kids ) Builds an element. Text always goes in as text, never as HTML.
 *   clear( node )         Removes every child of a node.
 *   uid( prefix )         A unique id for label and aria wiring.
 *   formatDate( ts )      A Unix timestamp in the site time zone and date format.
 *   formatNumber( n )     A number with the page language's digit grouping.
 *   formatSize( bytes )   A file size such as "212 KB".
 *   rows( n )             "1 row" or "1,240 rows".
 *   list( items )         Items joined with the translated list separator, empty ones skipped.
 *   paren( main, note )   "Main (note)", in the order the translation sets.
 *   labeled( name, text ) "Name: text", in the order the translation sets.
 *   registerTab( id, def ) Adds a tab module. def.mount( panel ) builds the tab.
 *   tabModules            The registered tab modules by id.
 *
 * Strings are not wrapped in helpers here on purpose: `wp i18n make-pot` only
 * finds literal __( 'text', 'open-export' ) calls, so each file calls wp.i18n
 * itself.
 */
( function () {
	'use strict';

	const app = ( window.opexApp = window.opexApp || {} );
	const { __, _n, _x, sprintf } = window.wp.i18n;

	app.boot = window.OPEX_BOOT || {};

	/**
	 * Property names that must be set as DOM properties, not attributes, so
	 * that toggling them later behaves the same as the first render.
	 */
	const PROPS = [ 'value', 'checked', 'disabled', 'hidden', 'selected', 'readOnly', 'required', 'indeterminate' ];

	/**
	 * Builds an element.
	 *
	 * attrs keys: class, text, html is not supported on purpose. Keys that
	 * start with "on" and hold a function become event listeners. null,
	 * undefined and false skip the attribute, true sets it empty.
	 *
	 * @param {string}        tag      Tag name.
	 * @param {Object}        attrs    Attributes, properties and listeners.
	 * @param {Array|Node|string} children Children. Strings become text nodes.
	 * @return {HTMLElement} The element.
	 */
	app.el = function ( tag, attrs, children ) {
		const node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( ( key ) => {
			const value = attrs[ key ];
			if ( null === value || undefined === value || false === value ) {
				return;
			}
			if ( 'class' === key ) {
				node.className = value;
			} else if ( 'text' === key ) {
				node.textContent = String( value );
			} else if ( 0 === key.indexOf( 'on' ) && 'function' === typeof value ) {
				node.addEventListener( key.slice( 2 ).toLowerCase(), value );
			} else if ( PROPS.indexOf( key ) > -1 ) {
				node[ key ] = value;
			} else {
				node.setAttribute( key, true === value ? '' : String( value ) );
			}
		} );
		app.append( node, children );
		return node;
	};

	/**
	 * Appends children, skipping empty ones and turning strings into text.
	 *
	 * @param {Node}              node     Parent.
	 * @param {Array|Node|string} children Children.
	 * @return {Node} The parent.
	 */
	app.append = function ( node, children ) {
		( Array.isArray( children ) ? children : [ children ] ).forEach( ( child ) => {
			if ( null === child || undefined === child || false === child ) {
				return;
			}
			if ( Array.isArray( child ) ) {
				app.append( node, child );
				return;
			}
			node.appendChild( 'object' === typeof child ? child : document.createTextNode( String( child ) ) );
		} );
		return node;
	};

	/**
	 * Removes every child of a node.
	 *
	 * @return {Element} The same node, now empty.
	 */
	app.clear = function ( node ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
		return node;
	};

	let counter = 0;
	/**
	 * Makes an id that is unique on the page, for labels and aria links.
	 */
	app.uid = function ( prefix ) {
		counter += 1;
		return ( prefix || 'opex' ) + '-' + counter;
	};

	const lang = document.documentElement.lang || undefined;
	let numberFormat = null;
	try {
		numberFormat = new Intl.NumberFormat( lang );
	} catch ( e ) {
		numberFormat = new Intl.NumberFormat();
	}

	/**
	 * Formats a number for the site's language.
	 */
	app.formatNumber = function ( n ) {
		return numberFormat.format( Number( n ) || 0 );
	};

	/**
	 * Formats a Unix timestamp in the site time zone. wp.date knows the site
	 * zone and date format from WordPress itself, so the screen matches the
	 * rest of wp-admin.
	 *
	 * @param {number} ts Seconds since the epoch.
	 * @return {string} Formatted date, or "" for 0.
	 */
	app.formatDate = function ( ts ) {
		const seconds = Number( ts ) || 0;
		if ( seconds <= 0 ) {
			return '';
		}
		const date = window.wp.date;
		if ( date && date.dateI18n ) {
			const formats = ( date.getSettings && date.getSettings().formats ) || {};
			return date.dateI18n( formats.datetimeAbbreviated || formats.datetime || 'M j, Y g:i a', seconds * 1000 );
		}
		return new Date( seconds * 1000 ).toLocaleString( lang );
	};

	/**
	 * Formats a byte count as bytes, KB, MB or GB.
	 */
	app.formatSize = function ( bytes ) {
		const n = Number( bytes ) || 0;
		if ( n < 1024 ) {
			/* translators: %s: a number of bytes. */
			return sprintf( __( '%s bytes', 'open-export' ), app.formatNumber( n ) );
		}
		if ( n < 1024 * 1024 ) {
			/* translators: %s: a file size in kilobytes. */
			return sprintf( __( '%s KB', 'open-export' ), app.formatNumber( Math.round( n / 1024 ) ) );
		}
		const mb = Math.round( ( n / ( 1024 * 1024 ) ) * 10 ) / 10;
		/* translators: %s: a file size in megabytes. */
		return sprintf( __( '%s MB', 'open-export' ), app.formatNumber( mb ) );
	};

	/**
	 * Tab registry. A tab file calls registerTab( id, { mount } ) when it
	 * loads, and main.js mounts it the first time the tab opens. mount gets
	 * the empty panel and may return { show(), hide() } hooks.
	 */
	app.tabModules = app.tabModules || {};
	/**
	 * Registers a tab so the shell can mount it the first time it opens.
	 *
	 * @param {string} id  Tab id.
	 * @param {Object} def Tab definition with mount( panel ).
	 */
	app.registerTab = function ( id, def ) {
		app.tabModules[ id ] = def;
	};

	/**
	 * Joins items with a separator translators can change, so no list is
	 * glued with a hard-coded comma.
	 *
	 * @param {Array} items Strings. Empty ones are skipped.
	 * @return {string} List.
	 */
	app.list = function ( items ) {
		/* translators: separator between items of a short list, with its spaces. */
		return items.filter( Boolean ).join( _x( ', ', 'list separator', 'open-export' ) );
	};

	/**
	 * Puts a detail in parentheses after a name, in a way translators can reorder.
	 */
	app.paren = function ( main, note ) {
		/* translators: 1: a name, 2: a detail about it, such as an id or a type. */
		return sprintf( _x( '%1$s (%2$s)', 'name and detail', 'open-export' ), main, note );
	};

	/**
	 * Puts a message after what it is about, in a way translators can reorder.
	 */
	app.labeled = function ( name, text ) {
		/* translators: 1: what a message is about, 2: the message. */
		return sprintf( _x( '%1$s: %2$s', 'subject and message', 'open-export' ), name, text );
	};

	/**
	 * Gives a row count as text, such as "1 row" or "25 rows".
	 */
	app.rows = function ( n ) {
		/* translators: %s: a number of rows. */
		return sprintf( _n( '%s row', '%s rows', Number( n ) || 0, 'open-export' ), app.formatNumber( n ) );
	};
}() );
