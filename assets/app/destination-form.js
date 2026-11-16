/**
 * Open Export admin screen: the add and edit form for one destination.
 *
 * The secret is write-only: the server never sends it back, only has_secret.
 * A blank secret on edit keeps the saved one. "Save and test" saves first,
 * because the test route tests the stored record.
 *
 * Exposes opexApp.destinationForm( o ) with o:
 *   record      The exposed destination, or null for a new one.
 *   notes       Known security notes by type, from saved records.
 *   onSaved( record )  Called after each save or refresh with the new record.
 *   onCancel()  Called when the person closes the form.
 * Returns { root, focus() }.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, sprintf } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;
	const api = app.api;
	const parts = app.destinationParts;

	// Server error codes mapped to the field they belong to.
	const ERROR_FIELDS = {
		opex_destination_type: 'type',
		opex_destination_host: 'host',
		opex_destination_port: 'port',
		opex_destination_username: 'username',
		opex_destination_auth: 'auth',
		opex_destination_secret: 'secret',
		opex_destination_key: 'secret',
		opex_destination_path: 'path',
	};

	/**
	 * Builds the add and edit form for one destination, with save, test and fingerprint reset.
	 *
	 * @param {Object} o Record, notes and callbacks.
	 * @return {Object} { root, focus() }.
	 */
	app.destinationForm = function ( o ) {
		let record = o.record || null;
		const types = ( app.store.get( 'options' ) || {} ).destination_types || [];
		const f = parts.fields( record, types );
		const rows = f.rows;
		const result = ui.result();
		const title = el( 'h2', { class: 'opex-statichead' } );
		const saveButton = ui.button( { label: __( 'Save and test', 'open-export' ), primary: true, type: 'submit', action: 'save' } );
		const fingerprint = parts.fingerprint( { onReset: resetFingerprint } );
		const lastTest = el( 'div', {} );
		const lastTestRow = ui.field( { label: __( 'Last test', 'open-export' ), control: lastTest, rowLabel: true } );

		const form = el( 'form', { class: 'opex-card opex-dest-form', novalidate: true, onSubmit: ( event ) => {
			event.preventDefault();
			save();
		} }, [
			title,
			el( 'div', { class: 'opex-pad' }, [
				rows.name.root, rows.type.root, rows.host.root, rows.port.root, rows.username.root,
				rows.auth.root, rows.secret.root, rows.path.root, rows.passive.root,
				fingerprint.row.root, lastTestRow.root,
				el( 'div', { class: 'opex-run-foot' }, [
					saveButton,
					ui.linkButton( { label: __( 'Cancel', 'open-export' ), action: 'cancel', onClick: () => o.onCancel() } ),
					result.root,
				] ),
			] ),
		] );

		f.type.addEventListener( 'change', refresh );
		f.authInputs.forEach( ( input ) => input.addEventListener( 'change', refresh ) );

		function current() {
			return { type: f.type.value, auth: f.authKey.checked && 'sftp' === f.type.value ? 'key' : 'password' };
		}

		// Shows only the parts that apply to the chosen type and login method.
		function refresh() {
			const now = current();
			title.textContent = record ? __( 'Edit destination', 'open-export' ) : __( 'Add destination', 'open-export' );
			parts.showNote( f.note, record, o.notes, now.type );
			f.port.placeholder = 'sftp' === now.type ? '22' : '21';
			f.keyChoice.hidden = 'sftp' !== now.type;
			if ( 'sftp' !== now.type ) {
				f.authPassword.checked = true;
			}
			const isKey = 'key' === now.auth;
			f.password.hidden = isKey;
			f.key.hidden = ! isKey;
			rows.secret.root.querySelector( 'label' ).setAttribute( 'for', isKey ? f.key.id : f.password.id );
			rows.secret.root.querySelector( 'label' ).textContent = isKey ? __( 'Private key', 'open-export' ) : __( 'Password', 'open-export' );
			const saved = !! ( record && record.has_secret && record.auth === now.auth );
			f.secretSaved.hidden = ! saved;
			f.secretSavedText.textContent = isKey ? __( 'A private key is saved', 'open-export' ) : __( 'A password is saved', 'open-export' );
			f.password.placeholder = saved ? __( 'Leave blank to keep the saved password', 'open-export' ) : '';
			f.key.placeholder = saved ? __( 'Paste a new private key to replace the saved one', 'open-export' ) : '';
			rows.passive.root.hidden = 'sftp' === now.type;
			// A pin belongs to the saved host. Changing the type clears it on save.
			fingerprint.row.root.hidden = ! record || 'sftp' !== now.type || 'sftp' !== record.type;
			fingerprint.update( record );
			lastTestRow.root.hidden = ! parts.wasTested( record );
			if ( parts.wasTested( record ) ) {
				app.clear( lastTest ).appendChild( parts.lastTest( record.last_test ) );
			}
		}

		function payload() {
			const now = current();
			const data = {
				name: f.name.value.trim(),
				type: now.type,
				host: f.host.value.trim(),
				port: f.port.value.trim(),
				username: f.username.value,
				auth: now.auth,
				path: f.path.value.trim() || '/',
				passive: f.passive.checked,
			};
			const secret = 'key' === now.auth ? f.key.value : f.password.value;
			if ( secret ) {
				data.secret = secret;
			}
			return data;
		}

		function clearErrors() {
			Object.keys( rows ).forEach( ( key ) => rows[ key ].setError( '' ) );
		}

		// Light checks that save a round trip. The server still decides.
		function check( data ) {
			const errors = {};
			if ( ! data.host ) {
				errors.host = __( 'Enter the server address.', 'open-export' );
			}
			if ( '' !== data.port && ! ( /^\d+$/.test( data.port ) && +data.port >= 1 && +data.port <= 65535 ) ) {
				errors.port = __( 'Use a port from 1 to 65535, or leave it blank.', 'open-export' );
			}
			if ( ! data.username ) {
				errors.username = __( 'Enter the user name.', 'open-export' );
			}
			const saved = record && record.has_secret && record.auth === data.auth;
			if ( ! data.secret && ! saved ) {
				errors.secret = 'key' === data.auth ? __( 'Paste the private key.', 'open-export' ) : __( 'Enter the password.', 'open-export' );
			}
			return errors;
		}

		function showErrors( errors ) {
			let first = null;
			Object.keys( errors ).forEach( ( key ) => {
				if ( rows[ key ] ) {
					rows[ key ].setError( errors[ key ] );
					first = first || key;
				}
			} );
			if ( first ) {
				const input = 'secret' === first ? ( f.key.hidden ? f.password : f.key ) : rows[ first ].input;
				if ( input && input.focus ) {
					input.focus();
				}
			}
			return !! first;
		}

		function serverErrors( err ) {
			const errors = {};
			if ( ERROR_FIELDS[ err.code ] ) {
				errors[ ERROR_FIELDS[ err.code ] ] = err.message;
			}
			Object.keys( err.params || {} ).forEach( ( key ) => {
				errors[ key ] = String( err.params[ key ] );
			} );
			return errors;
		}

		function setRecord( next ) {
			record = next;
			f.password.value = '';
			f.key.value = '';
			o.onSaved( next );
			refresh();
		}

		async function save() {
			clearErrors();
			const data = payload();
			if ( showErrors( check( data ) ) ) {
				result.set( 'error', __( 'Check the highlighted fields.', 'open-export' ) );
				return;
			}
			saveButton.disabled = true;
			result.set( 'busy', __( 'Saving', 'open-export' ) );
			try {
				const saved = await api.post( record ? '/destinations/' + encodeURIComponent( record.id ) : '/destinations', data );
				setRecord( saved );
				result.set( 'busy', __( 'Saved. Testing the connection.', 'open-export' ) );
				const test = await api.post( '/destinations/' + encodeURIComponent( saved.id ) + '/test' );
				if ( test && test.ok ) {
					result.set( 'success', __( 'Saved. The connection works.', 'open-export' ) );
				} else {
					/* translators: %s: the reason the connection test failed. */
					result.set( 'error', sprintf( __( 'Saved. The test failed: %s', 'open-export' ), test && test.message ? test.message : '' ) );
				}
				// A good SFTP test pins the fingerprint, so read the record again.
				const list = await api.get( '/destinations' );
				const fresh = ( Array.isArray( list ) ? list : [] ).find( ( item ) => item.id === saved.id );
				if ( fresh ) {
					record = fresh;
					o.onSaved( fresh );
					refresh();
				}
			} catch ( err ) {
				if ( err.session ) {
					result.clear();
				} else {
					showErrors( serverErrors( err ) );
					result.set( 'error', err.message );
				}
			} finally {
				saveButton.disabled = false;
			}
		}

		async function resetFingerprint() {
			const yes = await ui.confirm( {
				title: __( 'Reset the saved fingerprint?', 'open-export' ),
				message: __( 'Do this only if the server was rebuilt on purpose. The next test or delivery saves the new fingerprint.', 'open-export' ),
				confirmLabel: __( 'Reset fingerprint', 'open-export' ),
				danger: true,
			} );
			if ( ! yes ) {
				return;
			}
			try {
				const saved = await api.post( '/destinations/' + encodeURIComponent( record.id ), { reset_host_key: true } );
				setRecord( saved );
				// An older server ignores reset_host_key and keeps the pin.
				if ( saved.host_key ) {
					result.set( 'error', __( 'The fingerprint was not reset. This version of Open Export cannot reset it yet.', 'open-export' ) );
				} else {
					result.set( 'success', __( 'The saved fingerprint was reset. Save and test to save the new one.', 'open-export' ) );
				}
				saveButton.focus();
			} catch ( err ) {
				if ( err.session ) {
					result.clear();
				} else {
					result.set( 'error', err.message );
				}
			}
		}

		refresh();

		return {
			root: form,
			focus: () => f.name.focus(),
		};
	};
}() );
