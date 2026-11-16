/**
 * Open Export admin screen: a tiny shared state holder.
 *
 * Tabs keep their own view state. Data that more than one tab reads (the
 * /options payload, jobs, destinations, schedules) lives here, so a tab can
 * react when another tab changes it. Listeners fire per key, which keeps
 * updates local: nothing re-renders the whole screen.
 *
 * Exposes opexApp.store:
 *   get( key )              Current value, or undefined.
 *   set( key, value )       Stores a value and calls that key's listeners.
 *   update( key, fn )       set( key, fn( get( key ) ) ).
 *   subscribe( key, fn )    Calls fn( value, key ) on each set. Returns an unsubscribe function.
 *   limit( key, fallback )  A number from /options `limits`, or the fallback before /options arrives.
 *
 * Keys in use: options, jobs, driving, schedules, destinations, settings,
 * session, sources, builderLoad (Export tab), historyFocus (History tab).
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const values = Object.create( null );
	const listeners = Object.create( null );

	/**
	 * Reads a value from the store.
	 */
	function get( key ) {
		return values[ key ];
	}

	/**
	 * Writes a value and tells every listener of its key. A listener that throws does not stop the others.
	 *
	 * @return {*} The value.
	 */
	function set( key, value ) {
		values[ key ] = value;
		( listeners[ key ] || [] ).slice().forEach( ( fn ) => {
			try {
				fn( value, key );
			} catch ( error ) {
				// One broken listener must not stop the others.
				window.console.error( error );
			}
		} );
		return value;
	}

	/**
	 * Changes a value with a function of the old one.
	 */
	function update( key, fn ) {
		return set( key, fn( values[ key ] ) );
	}

	/**
	 * Calls a function whenever a key changes.
	 *
	 * @return {Function} Call it to stop listening.
	 */
	function subscribe( key, fn ) {
		( listeners[ key ] = listeners[ key ] || [] ).push( fn );
		return function unsubscribe() {
			listeners[ key ] = ( listeners[ key ] || [] ).filter( ( item ) => item !== fn );
		};
	}

	// The server sends the limits it enforces. The fallbacks match it, for the
	// moment before /options arrives and for an older server.
	function limit( key, fallback ) {
		const value = Number( ( ( get( 'options' ) || {} ).limits || {} )[ key ] );
		return value > 0 ? value : fallback;
	}

	app.store = { get, set, update, subscribe, limit };
}() );
