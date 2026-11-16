/**
 * Open Export admin screen: the Trigger URL box of the schedule form.
 *
 * The URL holds the schedule's secret key. It is shown only inside its own
 * read-only box. It is never logged, never put into a message, and the
 * result line never repeats it. "Make a new URL" asks first, then calls
 * POST /schedules/{id}/rotate-key, after which the old URL stops working.
 *
 * Exposes opexApp.scheduleTrigger( { record, onRecord } ):
 *   Returns { root, setRecord( record ) }. A schedule that is not saved yet
 *   has no URL, so the box says it appears after saving.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __ } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;

	/**
	 * Builds the Trigger URL box, with copy and "Make a new URL".
	 *
	 * @param {Object} o Record and onRecord().
	 * @return {Object} { root, setRecord( record ) }.
	 */
	app.scheduleTrigger = function ( o ) {
		let record = o.record || null;
		const card = ui.card( { title: __( 'Trigger URL', 'open-export' ), level: 'h3' } );
		const inputId = app.uid( 'opex-trigger' );
		const input = el( 'input', { type: 'text', id: inputId, class: 'opex-mono opex-trigger', readOnly: true, autocomplete: 'off', spellcheck: 'false' } );
		const label = el( 'label', { class: 'opex-sr', for: inputId }, __( 'Trigger URL', 'open-export' ) );
		const result = ui.result();
		const copy = ui.button( { label: __( 'Copy', 'open-export' ), icon: 'copy', action: 'copy', onClick: copyUrl } );
		const renew = ui.button( { label: __( 'Make a new URL', 'open-export' ), action: 'renew', onClick: renewUrl } );
		const warning = el( 'p', { class: 'opex-caution' }, [
			app.icon( 'warning', { sm: true, cls: 'opex-c-warning' } ),
			__( 'Anyone with this URL can start this export. Share it only with services you trust.', 'open-export' ),
		] );
		const help = el( 'p', { class: 'opex-help' }, __( 'A GET or POST request to this URL starts a run, or continues one that is going. It accepts one start every 30 seconds.', 'open-export' ) );
		const box = el( 'div', {}, [ label, el( 'div', { class: 'opex-inline' }, [ input, copy, renew ] ), warning, help, result.root ] );
		const unsaved = el( 'p', { class: 'opex-help' }, __( 'The trigger URL appears here after you save the schedule.', 'open-export' ) );
		app.append( card.body, [ box, unsaved ] );

		// Shows the trigger URL, or a note that it appears after saving.
		function sync() {
			const url = record && record.trigger_url ? String( record.trigger_url ) : '';
			input.value = url;
			box.hidden = ! url;
			unsaved.hidden = !! url;
		}

		// Copies the trigger URL.
		function copyUrl() {
			const done = () => result.set( 'success', __( 'Copied.', 'open-export' ) );
			// Copies through a selection when the clipboard API is not allowed.
			const fallback = () => {
				input.focus();
				input.select();
				let ok = false;
				try {
					ok = document.execCommand( 'copy' );
				} catch ( e ) {
					ok = false;
				}
				if ( ok ) {
					done();
					copy.focus();
				} else {
					result.set( 'error', __( 'The browser did not allow copying. The URL is selected, so press Ctrl+C or Command+C.', 'open-export' ) );
				}
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( input.value ).then( done, fallback );
			} else {
				fallback();
			}
		}

		// Asks first, then makes a new trigger URL.
		async function renewUrl() {
			if ( ! record ) {
				return;
			}
			const yes = await ui.confirm( {
				title: __( 'Make a new trigger URL?', 'open-export' ),
				message: __( 'The current URL stops working at once. Give the new one to anything that calls it.', 'open-export' ),
				confirmLabel: __( 'Make a new URL', 'open-export' ),
				danger: true,
			} );
			if ( ! yes ) {
				return;
			}
			result.set( 'busy', __( 'Making a new URL', 'open-export' ) );
			try {
				record = await app.api.post( '/schedules/' + encodeURIComponent( record.id ) + '/rotate-key' );
				sync();
				result.set( 'success', __( 'A new URL is ready. The old one no longer works.', 'open-export' ) );
				renew.focus();
				if ( o.onRecord ) {
					o.onRecord( record );
				}
			} catch ( err ) {
				if ( err.session ) {
					result.clear();
				} else {
					// The server's message, never the URL.
					result.set( 'error', err.message );
				}
			}
		}

		sync();
		return {
			root: card.root,
			setRecord( next ) {
				record = next;
				sync();
			},
		};
	};
}() );
