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
 *
 * Keys in use: options, jobs, driving, schedules, destinations, settings,
 * session, sources, builderLoad (Export tab), historyFocus (History tab).
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const values = Object.create( null );
	const listeners = Object.create( null );

	function get( key ) {
		return values[ key ];
	}

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

	function update( key, fn ) {
		return set( key, fn( values[ key ] ) );
	}

	function subscribe( key, fn ) {
		( listeners[ key ] = listeners[ key ] || [] ).push( fn );
		return function unsubscribe() {
			listeners[ key ] = ( listeners[ key ] || [] ).filter( ( item ) => item !== fn );
		};
	}

	app.store = { get, set, update, subscribe };
}() );
