/**
 * Open Export admin screen: builder card 5, preview.
 *
 * POST /preview with the whole config returns { headers, rows, total,
 * notes }: at most 5 rows with transforms and custom columns applied, the
 * match count, and the notes a job would carry (ignored fields, dropped
 * rules). Notes show only when the server sends them.
 *
 * The screen checks the config first, so the server never has to drop
 * anything the person typed. After any change the old preview is marked
 * out of date.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, sprintf } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;
	const B = app.builder;

	function build( ctx ) {
		const count = el( 'span', { class: 'opex-preview-count', role: 'status', 'aria-live': 'polite' } );
		const button = ui.button( { label: __( 'Preview', 'open-export' ), action: 'preview', onClick: run } );
		const messages = el( 'div', {} );
		const tableBox = el( 'div', {} );
		const notesBox = el( 'div', {} );
		const stale = el( 'p', { class: 'opex-help', hidden: true }, __( 'The setup changed after this preview. Preview again to see the new rows.', 'open-export' ) );
		let busy = false;

		function run() {
			if ( busy ) {
				return;
			}
			app.clear( messages );
			const problems = ctx.validate();
			if ( problems.length ) {
				const box = ui.notice( 'error', __( 'Fix these first:', 'open-export' ), { tight: true, role: 'alert' } );
				box.querySelector( '.opex-notice-body' ).appendChild( el( 'ul', { class: 'opex-problems' }, problems.map( ( p ) => el( 'li', {}, p.message ) ) ) );
				messages.appendChild( box );
				return;
			}
			busy = true;
			button.disabled = true;
			count.textContent = __( 'Loading the preview.', 'open-export' );
			const key = JSON.stringify( ctx.config );
			app.api.post( '/preview', ctx.config ).then( ( answer ) => {
				const data = answer || {};
				ctx.preview = { key, total: Number( data.total ) || 0 };
				show( data );
				ctx.changed( 'preview' );
			} ).catch( ( err ) => {
				count.textContent = '';
				if ( ! err.session ) {
					messages.appendChild( ui.notice( 'error', err.message, { tight: true, role: 'alert' } ) );
				}
			} ).then( () => {
				busy = false;
				button.disabled = false;
			} );
		}

		function show( data ) {
			const headers = Array.isArray( data.headers ) ? data.headers : [];
			const rows = Array.isArray( data.rows ) ? data.rows : [];
			/* translators: %s: "1,240 rows". */
			count.textContent = sprintf( __( '%s match', 'open-export' ), app.rows( data.total ) );
			app.clear( tableBox );
			app.clear( notesBox );
			stale.hidden = true;
			if ( rows.length ) {
				const table = ui.table( {
					/* translators: %d: number of rows shown. */
					caption: sprintf( __( 'First %d matching rows', 'open-export' ), rows.length ),
					cls: 'opex-plain opex-preview-table',
					columns: headers.map( ( label ) => ( { label: String( label ) } ) ),
				} );
				rows.forEach( ( row ) => table.tbody.appendChild( el( 'tr', {}, ( Array.isArray( row ) ? row : [] ).map( ( cell ) => el( 'td', {}, String( null === cell ? '' : cell ) ) ) ) ) );
				app.append( tableBox, [
					table.root,
					/* translators: %d: number of rows shown. */
					el( 'p', { class: 'opex-help' }, sprintf( __( 'Showing the first %d rows. Long values are cut short here, not in the file.', 'open-export' ), rows.length ) ),
				] );
			} else {
				tableBox.appendChild( el( 'p', { class: 'opex-help' }, __( 'No rows match. The export would make a file with only the header row.', 'open-export' ) ) );
			}
			const notes = Array.isArray( data.notes ) ? data.notes : [];
			if ( notes.length ) {
				notesBox.appendChild( el( 'ul', { class: 'opex-notes opex-mt', 'aria-label': __( 'Preview notes', 'open-export' ) }, notes.map( ( note ) => el( 'li', {}, [
					app.icon( 'info', { sm: true, cls: 'opex-c-info' } ),
					el( 'span', {}, String( note ) ),
				] ) ) ) );
			}
		}

		function clearAll() {
			app.clear( tableBox );
			app.clear( notesBox );
			app.clear( messages );
			count.textContent = '';
			stale.hidden = true;
		}

		return {
			root: el( 'div', {}, [ el( 'div', { class: 'opex-previewbar' }, [ button, count ] ), messages, stale, tableBox, notesBox ] ),
			refresh( reason ) {
				if ( 'preview' === reason ) {
					return;
				}
				if ( ! ctx.preview ) {
					clearAll();
					return;
				}
				stale.hidden = ctx.preview.key === JSON.stringify( ctx.config );
			},
			done: () => !! ctx.preview && ctx.preview.key === JSON.stringify( ctx.config ),
			summary() {
				if ( ! ctx.preview ) {
					return __( 'Not previewed yet', 'open-export' );
				}
				/* translators: %s: "1,240 rows". */
				const text = sprintf( __( '%s match', 'open-export' ), app.rows( ctx.preview.total ) );
				return ctx.preview.key === JSON.stringify( ctx.config ) ? text : text + ' ' + __( '(out of date)', 'open-export' );
			},
		};
	}

	B.register( { id: 'preview', order: 50, title: __( 'Preview', 'open-export' ), needsSource: true, build } );
}() );
