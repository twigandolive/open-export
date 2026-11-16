/**
 * Open Export admin screen: the controls inside the destination form.
 *
 * Kept apart from destination-form.js so the form file holds the behavior
 * (save, test, reset) and this file holds the markup.
 *
 * Exposes opexApp.destinationParts:
 *   fields( record, types )  Every input and its row. Returns the inputs by name plus rows.
 *   fingerprint( { onReset } ) The SFTP fingerprint row. Returns { row, update( record ) }.
 *   showNote( box, record, notes, type )  The server's security note for a type.
 *   lastTest( lastTest )     Status line for a stored test result (spec 3.5).
 *   wasTested( record )      True when the record holds a real test result.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __ } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;

	function fields( record, types ) {
		const r = record || {};
		const f = {};
		f.name = el( 'input', { type: 'text', class: 'opex-w-md', maxlength: 100, value: r.name || '' } );
		f.type = el( 'select', {}, types.map( ( item ) => el( 'option', { value: item.id, selected: item.id === ( r.type || 'sftp' ) }, item.label ) ) );
		f.note = el( 'div', {} );
		f.host = el( 'input', { type: 'text', class: 'opex-mono opex-w-md', maxlength: 253, value: r.host || '', autocomplete: 'off', spellcheck: 'false' } );
		f.port = el( 'input', { type: 'number', class: 'opex-w-num', min: 1, max: 65535, value: r.port ? String( r.port ) : '' } );
		f.username = el( 'input', { type: 'text', class: 'opex-w-md', maxlength: 128, value: r.username || '', autocomplete: 'off', spellcheck: 'false' } );

		const group = app.uid( 'opex-auth' );
		const labelId = app.uid( 'opex-auth-l' );
		f.authPassword = el( 'input', { type: 'radio', name: group, value: 'password', checked: 'key' !== r.auth } );
		f.authKey = el( 'input', { type: 'radio', name: group, value: 'key', checked: 'key' === r.auth } );
		f.authInputs = [ f.authPassword, f.authKey ];
		f.keyChoice = el( 'label', { class: 'opex-checkline' }, [ f.authKey, __( 'Private key', 'open-export' ) ] );
		const auth = el( 'div', { role: 'radiogroup', 'aria-labelledby': labelId, class: 'opex-stack' }, [
			el( 'label', { class: 'opex-checkline' }, [ f.authPassword, __( 'Password', 'open-export' ) ] ),
			f.keyChoice,
		] );

		f.password = el( 'input', { type: 'password', class: 'opex-w-md', maxlength: 1024, autocomplete: 'new-password' } );
		f.key = el( 'textarea', { class: 'opex-mono', rows: 6, spellcheck: 'false', autocomplete: 'off' } );
		f.key.id = app.uid( 'opex-key' );
		f.secretSavedText = el( 'span', {} );
		f.secretSaved = el( 'p', { class: 'opex-secret', hidden: true }, [ app.icon( 'lock', { sm: true, cls: 'opex-c-success' } ), f.secretSavedText ] );

		f.path = el( 'input', { type: 'text', class: 'opex-mono opex-w-md', maxlength: 500, value: r.path || '/', spellcheck: 'false' } );
		const passive = ui.switchControl( { label: __( 'Use passive mode', 'open-export' ), checked: undefined === r.passive ? true : !! r.passive } );
		f.passive = passive.input;

		f.rows = {
			name: ui.field( { label: __( 'Name', 'open-export' ), control: f.name, help: __( 'Leave blank to use the host name.', 'open-export' ) } ),
			type: ui.field( { label: __( 'Type', 'open-export' ), control: el( 'div', {}, [ f.type, f.note ] ), input: f.type } ),
			host: ui.field( { label: __( 'Host', 'open-export' ), control: f.host, help: __( 'The server name or IP address only, without ftp:// or a folder.', 'open-export' ) } ),
			port: ui.field( { label: __( 'Port', 'open-export' ), control: f.port, help: __( 'Leave blank for the usual port: 21 for FTP and FTPS, 22 for SFTP.', 'open-export' ) } ),
			username: ui.field( { label: __( 'User name', 'open-export' ), control: f.username } ),
			auth: ui.field( { label: __( 'Login method', 'open-export' ), control: auth, rowLabel: true, labelId, help: __( 'A private key works with SFTP only. Keys with a passphrase are not supported.', 'open-export' ) } ),
			secret: ui.field( { label: __( 'Password', 'open-export' ), control: el( 'div', {}, [ f.password, f.key, f.secretSaved ] ), input: f.password } ),
			path: ui.field( { label: __( 'Remote folder', 'open-export' ), control: f.path, help: __( 'The folder on the server where files are uploaded.', 'open-export' ) } ),
			passive: ui.field( { label: __( 'Mode', 'open-export' ), control: passive.root, rowLabel: true, input: f.passive } ),
		};
		return f;
	}

	function fingerprint( o ) {
		const code = el( 'code', { class: 'opex-fingerprint' } );
		const none = el( 'p', {}, __( 'None saved yet.', 'open-export' ) );
		const reset = ui.button( { label: __( 'Reset saved fingerprint', 'open-export' ), action: 'reset', cls: 'opex-mt', onClick: o.onReset } );
		const help = __( 'Open Export saves the server fingerprint the first time a test or delivery works, and refuses to connect if it changes. If the server was rebuilt on purpose, reset it and test again.', 'open-export' );
		const row = ui.field( {
			label: __( 'Server fingerprint', 'open-export' ),
			control: el( 'div', {}, [ code, none, el( 'p', { class: 'opex-help' }, help ), reset ] ),
			rowLabel: true,
			input: code,
		} );
		function update( record ) {
			const key = record && record.host_key ? record.host_key : '';
			code.textContent = key;
			code.hidden = ! key;
			none.hidden = !! key;
			reset.disabled = ! key;
		}
		return { row, update };
	}

	/**
	 * The server writes the security note on each saved record. A type the
	 * person just picked shows the note from any saved record of that type,
	 * or nothing until it is saved.
	 *
	 * @param {HTMLElement} box    Container under the type select.
	 * @param {Object|null} record Saved record.
	 * @param {Object}      notes  Notes by type from saved records.
	 * @param {string}      type   Chosen type.
	 */
	function showNote( box, record, notes, type ) {
		let text = null;
		if ( record && record.type === type ) {
			text = record.security_note || '';
		} else if ( notes && Object.prototype.hasOwnProperty.call( notes, type ) ) {
			text = notes[ type ];
		}
		app.clear( box );
		if ( text ) {
			box.appendChild( ui.notice( 'warning', text, { tight: true, role: 'note' } ) );
		}
	}

	function lastTest( test ) {
		const when = app.formatDate( test.time );
		return el( 'div', {}, [
			test.ok ? ui.status( 'ok', __( 'Passed', 'open-export' ) ) : ui.status( 'failed', __( 'Failed', 'open-export' ) ),
			when ? el( 'span', { class: 'opex-subline' }, when ) : null,
			! test.ok && test.message ? el( 'span', { class: 'opex-subline' }, test.message ) : null,
		] );
	}

	// The server sends last_test with time 0 for a destination never tested.
	function wasTested( record ) {
		return !! ( record && record.last_test && record.last_test.time > 0 );
	}

	app.destinationParts = { fields, fingerprint, showNote, lastTest, wasTested };
}() );
