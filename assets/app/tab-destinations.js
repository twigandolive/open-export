/**
 * Open Export admin screen: the Destinations tab.
 *
 * Lists saved FTP, FTPS and SFTP servers and opens the add and edit form
 * (destination-form.js) below the list. The list lives in the store under
 * "destinations", so the delivery block in later stages can read it.
 *
 * Registers the tab "destinations".
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, sprintf } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;
	const api = app.api;
	const store = app.store;
	// The most destinations the server keeps (/options limits.destinations).
	const max = () => app.store.limit( 'destinations', 20 );

	function mount( panel ) {
		const options = store.get( 'options' ) || {};
		const features = options.features || {};
		const types = options.destination_types || [];
		const messages = el( 'div', { class: 'opex-messages', 'aria-live': 'polite' } );
		const limitHelp = el( 'p', { class: 'opex-help', id: app.uid( 'opex-limit' ), hidden: true },
			/* translators: %d: most destinations allowed. */
			sprintf( __( 'You can save at most %d destinations.', 'open-export' ), max() ) );
		const addButton = ui.button( { label: __( 'Add destination', 'open-export' ), icon: 'plus', action: 'add', describedBy: limitHelp.id, onClick: () => openForm( null, addButton ) } );
		const listBox = el( 'div', {} );
		const formBox = el( 'div', { class: 'opex-mt-lg' } );
		let form = null;
		let editingId = null;
		let opener = null;
		let loadError = null;

		const warnings = [];
		if ( false === features.ftp ) {
			warnings.push( ui.notice( 'warning', __( 'The PHP ftp extension is not installed on this server, so FTP and FTPS destinations cannot send files. SFTP still works.', 'open-export' ), { role: 'note' } ) );
		}
		if ( false === features.sodium ) {
			warnings.push( ui.notice( 'warning', __( 'This server cannot store passwords safely because the PHP sodium extension is missing. Destinations cannot be saved until it is installed.', 'open-export' ), { role: 'note' } ) );
		}

		app.append( panel, [
			el( 'div', { class: 'opex-pagehead' }, [
				el( 'h2', { class: 'opex-h2 opex-m0' }, __( 'Saved destinations', 'open-export' ) ),
				el( 'div', {}, [ addButton, limitHelp ] ),
			] ),
			warnings,
			messages,
			listBox,
			formBox,
		] );

		function say( type, text ) {
			app.clear( messages );
			if ( text ) {
				messages.appendChild( ui.notice( type, text, { role: 'error' === type ? 'alert' : 'status' } ) );
			}
		}

		function list() {
			return store.get( 'destinations' );
		}

		function typeLabel( id ) {
			const found = types.find( ( item ) => item.id === id );
			return found ? found.label : String( id || '' ).toUpperCase();
		}

		// Security notes the server wrote on saved records, by type.
		function knownNotes() {
			const notes = {};
			( list() || [] ).forEach( ( item ) => {
				notes[ item.type ] = item.security_note || '';
			} );
			return notes;
		}

		function load() {
			loadError = null;
			render();
			api.get( '/destinations' ).then( ( items ) => {
				store.set( 'destinations', Array.isArray( items ) ? items : [] );
			} ).catch( ( err ) => {
				loadError = err;
				render();
			} );
		}

		function render() {
			const items = list();
			app.clear( listBox );
			if ( ! items ) {
				listBox.appendChild( loadError ? ui.loadError( loadError, load ) : ui.skeleton( 3 ) );
				addButton.disabled = true;
				return;
			}
			const full = items.length >= max();
			addButton.disabled = full;
			limitHelp.hidden = ! full;
			if ( ! items.length ) {
				if ( ! form ) {
					listBox.appendChild( ui.empty( {
						title: __( 'No destinations yet.', 'open-export' ),
						text: __( 'Save an FTP, FTPS or SFTP server here. Exports and schedules can then send their files to it.', 'open-export' ),
						action: { label: __( 'Add destination', 'open-export' ), onClick: () => openForm( null, addButton ) },
					} ) );
				}
				return;
			}
			listBox.appendChild( table( items ) );
		}

		function table( items ) {
			// The Last test column needs last_test, which an older server does not send. Without it the column is left out.
			const withTest = items.some( ( item ) => Object.prototype.hasOwnProperty.call( item, 'last_test' ) );
			const columns = [
				{ label: __( 'Name', 'open-export' ) },
				{ label: __( 'Type', 'open-export' ) },
				{ label: __( 'Host', 'open-export' ) },
				{ label: __( 'Folder', 'open-export' ) },
			];
			if ( withTest ) {
				columns.push( { label: __( 'Last test', 'open-export' ) } );
			}
			columns.push( { label: __( 'Actions', 'open-export' ) } );
			const t = ui.table( { caption: __( 'Saved destinations', 'open-export' ), columns, cls: 'opex-plain opex-stack-sm' } );
			items.forEach( ( item ) => {
				t.tbody.appendChild( el( 'tr', { 'data-id': item.id }, [
					el( 'td', {}, el( 'strong', {}, item.name || item.host ) ),
					el( 'td', { 'data-label': __( 'Type', 'open-export' ) }, el( 'span', { class: 'opex-badge' }, typeLabel( item.type ) ) ),
					el( 'td', { 'data-label': __( 'Host', 'open-export' ) }, el( 'span', { class: 'opex-mono' }, item.host ) ),
					el( 'td', { 'data-label': __( 'Folder', 'open-export' ) }, el( 'span', { class: 'opex-mono' }, item.path || '/' ) ),
					withTest ? el( 'td', { 'data-label': __( 'Last test', 'open-export' ) }, app.destinationParts.wasTested( item ) ? app.destinationParts.lastTest( item.last_test ) : ui.status( 'waiting', __( 'Not tested', 'open-export' ) ) ) : null,
					el( 'td', {}, el( 'span', { class: 'opex-actions' }, [
						ui.linkButton( {
							label: __( 'Edit', 'open-export' ),
							action: 'edit',
							/* translators: %s: destination name. */
							ariaLabel: sprintf( __( 'Edit %s', 'open-export' ), item.name || item.host ),
							onClick: ( event ) => openForm( item, event.currentTarget ),
						} ),
						ui.linkButton( {
							label: __( 'Delete', 'open-export' ),
							action: 'delete',
							danger: true,
							/* translators: %s: destination name. */
							ariaLabel: sprintf( __( 'Delete %s', 'open-export' ), item.name || item.host ),
							onClick: () => remove( item ),
						} ),
					] ) ),
				] ) );
			} );
			return t.root;
		}

		function openForm( record, from ) {
			opener = from || addButton;
			editingId = record ? record.id : null;
			say( '', '' );
			form = app.destinationForm( {
				record,
				notes: knownNotes(),
				onSaved: upsert,
				onCancel: closeForm,
			} );
			app.clear( formBox ).appendChild( form.root );
			render();
			form.focus();
		}

		function closeForm() {
			form = null;
			editingId = null;
			app.clear( formBox );
			render();
			focusAfter( opener );
		}

		// Focus the same control again if it survived the redraw, else a
		// matching one by row id, else the Add button.
		function focusAfter( node, rowId ) {
			if ( node && node.isConnected ) {
				node.focus();
				return;
			}
			const id = rowId || ( node && node.closest && node.closest( 'tr' ) ? node.closest( 'tr' ).getAttribute( 'data-id' ) : null );
			const again = id ? listBox.querySelector( 'tr[data-id="' + id + '"] [data-action="edit"]' ) : null;
			const target = again || ( addButton.disabled ? listBox.querySelector( 'button' ) : addButton );
			if ( target ) {
				target.focus();
			}
		}

		function upsert( record ) {
			editingId = record.id;
			// Replace in place so a saved row keeps its position in the list.
			const items = ( list() || [] ).slice();
			const at = items.findIndex( ( item ) => item.id === record.id );
			if ( at > -1 ) {
				items[ at ] = record;
			} else {
				items.push( record );
			}
			store.set( 'destinations', items );
		}

		async function remove( item ) {
			const name = item.name || item.host;
			const yes = await ui.confirm( {
				title: __( 'Delete this destination?', 'open-export' ),
				/* translators: %s: destination name. */
				message: sprintf( __( '%s will be deleted. Exports and schedules that send files to it will stop sending there.', 'open-export' ), name ),
				confirmLabel: __( 'Delete', 'open-export' ),
				danger: true,
			} );
			if ( ! yes ) {
				return;
			}
			const items = list() || [];
			const at = items.findIndex( ( d ) => d.id === item.id );
			const neighbor = items[ at + 1 ] || items[ at - 1 ];
			try {
				await api.del( '/destinations/' + encodeURIComponent( item.id ) );
				if ( editingId === item.id ) {
					form = null;
					editingId = null;
					app.clear( formBox );
				}
				store.set( 'destinations', items.filter( ( d ) => d.id !== item.id ) );
				/* translators: %s: destination name. */
				say( 'success', sprintf( __( '%s was deleted.', 'open-export' ), name ) );
				focusAfter( null, neighbor ? neighbor.id : null );
			} catch ( err ) {
				if ( ! err.session ) {
					say( 'error', err.message );
				}
			}
		}

		store.subscribe( 'destinations', render );

		if ( list() ) {
			render();
		} else {
			load();
		}
	}

	app.registerTab( 'destinations', { mount } );
}() );
