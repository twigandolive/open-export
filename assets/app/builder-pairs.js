/**
 * Open Export admin screen: the value pairs editor of the "Map values" step.
 *
 * Pairs are edited as rows, so an empty or repeated "from" value can be
 * shown and fixed, then written to the step as the { from: to } object the
 * server expects. Rows live in a WeakMap keyed by the step object, so a
 * loaded config starts fresh.
 *
 * Exposes opexApp.builder.pairs:
 *   rows( step, name )                     The editable rows of a map argument.
 *   editor( step, name, n, validate )      Returns { root, input }.
 *   maxLength()                            Longest value on either side.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, sprintf } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;
	const B = app.builder;
	const pairRows = new WeakMap();

	// /options limits.map_pair_length (OPEX_Transforms::MAX_PAIR_LEN).
	const maxLength = () => app.store.limit( 'map_pair_length', 200 );

	function rows( step, name ) {
		if ( ! pairRows.has( step ) ) {
			// PHP sends pairs whose "from" values are 0, 1, 2 and so on as a JSON list. Read the list's positions as those values.
			const raw = step[ name ] && 'object' === typeof step[ name ] ? step[ name ] : {};
			const pairs = Array.isArray( raw ) ? Object.assign( {}, raw ) : raw;
			pairRows.set( step, Object.keys( pairs ).map( ( from ) => [ from, String( pairs[ from ] ) ] ) );
		}
		return pairRows.get( step );
	}

	function editor( step, name, n, validate ) {
		const rows = B.pairs.rows( step, name );
		const tbody = el( 'tbody', {} );
		const write = () => {
			const pairs = {};
			rows.forEach( ( row ) => {
				if ( ( row[ 0 ] || row[ 1 ] ) && ! Object.prototype.hasOwnProperty.call( pairs, row[ 0 ] ) ) {
					pairs[ row[ 0 ] ] = row[ 1 ];
				}
			} );
			step[ name ] = pairs;
			validate();
		};
		const draw = ( focusRow ) => {
			app.clear( tbody );
			rows.forEach( ( row, i ) => {
				/* translators: 1: pair number, 2: step number. */
				const from = el( 'input', { type: 'text', value: row[ 0 ], 'aria-label': sprintf( __( 'When the value is, pair %1$d of step %2$d', 'open-export' ), i + 1, n ), onInput: ( e ) => { row[ 0 ] = e.target.value; write(); } } );
				/* translators: 1: pair number, 2: step number. */
				const to = el( 'input', { type: 'text', value: row[ 1 ], 'aria-label': sprintf( __( 'Use this instead, pair %1$d of step %2$d', 'open-export' ), i + 1, n ), onInput: ( e ) => { row[ 1 ] = e.target.value; write(); } } );
				/* translators: %d: pair number. */
				const remove = el( 'button', { type: 'button', class: 'opex-iconbtn', 'aria-label': sprintf( __( 'Remove pair %d', 'open-export' ), i + 1 ), onClick: () => {
					rows.splice( i, 1 );
					write();
					draw( Math.min( i, rows.length - 1 ) );
				} }, app.icon( 'close', { sm: true } ) );
				tbody.appendChild( el( 'tr', {}, [ el( 'td', {}, from ), el( 'td', {}, to ), el( 'td', {}, remove ) ] ) );
			} );
			if ( undefined !== focusRow ) {
				const target = focusRow >= 0 ? tbody.children[ focusRow ].querySelector( 'input' ) : addPair;
				target.focus();
			}
		};
		const addPair = ui.button( { label: __( 'Add pair', 'open-export' ), small: true, icon: 'plus', onClick: () => {
			rows.push( [ '', '' ] );
			draw( rows.length - 1 );
		} } );
		addPair.setAttribute( 'data-step', name );
		draw();
		const table = el( 'table', { class: 'opex-pairs' }, [
			el( 'thead', {}, el( 'tr', {}, [
				el( 'th', { scope: 'col' }, __( 'When the value is', 'open-export' ) ),
				el( 'th', { scope: 'col' }, __( 'Use this instead', 'open-export' ) ),
				el( 'th', { scope: 'col' }, el( 'span', { class: 'opex-sr' }, __( 'Remove pair', 'open-export' ) ) ),
			] ) ),
			tbody,
		] );
		return { root: el( 'div', { class: 'opex-w-full' }, [ table, addPair ] ), input: addPair };
	}

	B.pairs = { rows, editor, maxLength };
}() );
