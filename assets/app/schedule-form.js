/**
 * Open Export admin screen: the add and edit form of the Schedules tab.
 *
 * Name, the export setup (the shared builder in schedule mode, shown as a
 * one-line summary until "Edit export setup" opens it in place), the
 * timetable and changes only rows (schedule-timing.js), the file name
 * pattern with token chips and a live sample, the delivery block
 * (delivery.js) and the Trigger URL box (schedule-trigger.js).
 *
 * Saves with POST /schedules (create) or POST /schedules/{id} (update).
 * The answer carries notes about anything the server changed, and the
 * caller shows them.
 *
 * Exposes opexApp.scheduleForm( { record, onSaved, onCancel, onDelete, onRecord } ):
 *   onSaved( schedule, notes ), onRecord( schedule ) after Start over or a new URL.
 *   Returns { root, focus() }.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, sprintf } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;
	const parts = app.scheduleParts;
	const MAX_NAME = 100;
	const MAX_PATTERN = 200;

	/**
	 * Builds the add and edit form of a schedule.
	 *
	 * @param {Object} o Record and callbacks.
	 */
	app.scheduleForm = function ( o ) {
		const record = o.record || null;
		const options = app.store.get( 'options' ) || {};
		const messages = el( 'div', { class: 'opex-messages', 'aria-live': 'polite' } );
		const heading = el( 'h2', { class: 'opex-h2 opex-m0', tabindex: '-1' }, record ? __( 'Edit schedule', 'open-export' ) : __( 'Add schedule', 'open-export' ) );
		const back = ui.linkButton( { label: __( 'Back to schedules', 'open-export' ), action: 'back', onClick: () => o.onCancel() } );

		// Name.
		const name = el( 'input', { type: 'text', class: 'opex-w-md', maxlength: MAX_NAME, value: record ? record.name : '' } );
		const nameRow = ui.field( { label: __( 'Name', 'open-export' ), control: name } );
		name.addEventListener( 'input', () => {
			nameRow.setError( '' );
			syncSample();
		} );

		// Export setup: summary, button, and the builder in place.
		const summaryText = el( 'span', { class: 'opex-summarytext' } );
		const builderBox = el( 'div', { class: 'opex-sched-builder', id: app.uid( 'opex-setup' ), hidden: true } );
		const editSetup = ui.button( { label: __( 'Edit export setup', 'open-export' ), small: true, action: 'setup', onClick: () => openSetup( builderBox.hidden ) } );
		editSetup.setAttribute( 'aria-expanded', 'false' );
		editSetup.setAttribute( 'aria-controls', builderBox.id );
		const setupRow = ui.field( {
			label: __( 'Export setup', 'open-export' ),
			rowLabel: true,
			control: el( 'div', { class: 'opex-summarybox' }, [ summaryText, editSetup ] ),
		} );
		const doneSetup = ui.button( { label: __( 'Done with the export setup', 'open-export' ), small: true, onClick: () => openSetup( false, true ) } );
		const cards = el( 'div', { class: 'opex-builder' } );
		app.append( builderBox, [
			el( 'p', { class: 'opex-help' }, __( 'The same setup as the Export tab. Each run uses it as saved here.', 'open-export' ) ),
			cards,
			el( 'div', { class: 'opex-inline' }, doneSetup ),
		] );
		let timing = null;
		let builder = null;
		builder = app.builder.mount( cards, {
			mode: 'schedule',
			level: 'h4',
			config: record ? record.config : null,
			onChange: ( config, reason ) => {
				// The builder reports changes while it mounts, before the rest of the form exists.
				if ( ! builder || ! timing ) {
					return;
				}
				syncSummary();
				syncSample();
				if ( [ 'load', 'source', 'fields-loaded', 'fields-error' ].indexOf( reason ) > -1 ) {
					timing.refresh();
				}
			},
		} );
		timing = app.scheduleTiming( { record, builder, onRecord: ( next ) => o.onRecord && o.onRecord( next ) } );

		// Opens or closes the export setup.
		function openSetup( open, focusButton ) {
			builderBox.hidden = ! open;
			editSetup.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			if ( open ) {
				const first = cards.querySelector( '.opex-card-head:not(:disabled)' );
				if ( first ) {
					first.focus();
				}
			} else if ( focusButton ) {
				editSetup.focus();
			}
		}

		// Shows the export setup in one line.
		function syncSummary() {
			const text = builder.summary();
			if ( summaryText.textContent !== text ) {
				summaryText.textContent = text;
			}
		}

		// File name pattern.
		const pattern = el( 'input', { type: 'text', class: 'opex-mono opex-w-md', maxlength: MAX_PATTERN, value: record ? record.filename : '{label}-{date}' } );
		const sample = el( 'code', {} );
		const sampleLine = el( 'p', { class: 'opex-help', 'aria-live': 'polite' } );
		/* translators: %s: a sample file name. */
		const sampleParts = __( 'Sample: %s', 'open-export' ).split( '%s' );
		app.append( sampleLine, [ sampleParts[ 0 ] || '', sample, sampleParts.slice( 1 ).join( '' ) ] );
		const chips = el( 'div', { class: 'opex-chips', role: 'group', 'aria-label': __( 'Insert a token', 'open-export' ) }, parts.TOKENS.map( ( token ) => el( 'button', {
			type: 'button',
			class: 'opex-chip',
			/* translators: %s: a file name token, such as {date}. */
			'aria-label': sprintf( __( 'Insert the %s token', 'open-export' ), '{' + token + '}' ),
			onClick: () => app.builder.insertAtCaret( pattern, '{' + token + '}' ),
		}, '{' + token + '}' ) ) );
		const patternRow = ui.field( {
			label: __( 'File name pattern', 'open-export' ),
			input: pattern,
			control: el( 'div', {}, [ pattern, chips, sampleLine ] ),
			help: __( 'Open Export drops unknown tokens and adds the file extension for you. Dates and times use the site time zone.', 'open-export' ),
		} );
		pattern.addEventListener( 'input', () => {
			patternRow.setError( '' );
			syncSample();
		} );

		// Shows a sample file name for the pattern.
		function syncSample() {
			const config = builder.getConfig();
			const format = ( options.formats || {} )[ config.format ] || {};
			sample.textContent = parts.sampleName( pattern.value.trim() || '{label}-{date}', {
				label: name.value.trim(),
				source: config.source,
				id: record ? record.id : 's0a1b2c3d4e5f',
				extension: format.extension || config.format,
			} );
		}

		// Delivery and trigger URL.
		const delivery = app.delivery( { value: record ? record.delivery : null } );
		const deliveryCard = ui.card( { title: __( 'Send the result when it is finished', 'open-export' ), level: 'h3' } );
		deliveryCard.body.appendChild( delivery.root );
		const trigger = app.scheduleTrigger( { record, onRecord: ( next ) => o.onRecord && o.onRecord( next ) } );

		const mainCard = ui.card( { title: __( 'Schedule', 'open-export' ), level: 'h3' } );
		app.append( mainCard.body, [ nameRow.root, setupRow.root, builderBox, timing.rows, patternRow.root ] );

		const save = ui.button( { label: __( 'Save schedule', 'open-export' ), primary: true, action: 'save', onClick: submit } );
		const cancel = ui.linkButton( { label: __( 'Cancel', 'open-export' ), action: 'cancel', onClick: () => o.onCancel() } );
		const remove = record ? ui.linkButton( { label: __( 'Delete schedule', 'open-export' ), danger: true, action: 'delete', onClick: () => o.onDelete( record ) } ) : null;
		const status = ui.result();

		const root = el( 'div', { class: 'opex-sched-form' }, [
			el( 'div', { class: 'opex-pagehead' }, [ heading, back ] ),
			messages,
			mainCard.root,
			deliveryCard.root,
			trigger.root,
			el( 'div', { class: 'opex-formactions' }, [ save, cancel, status.root, remove ] ),
		] );

		// Shows a message above the form, with an optional list.
		function say( type, text, list ) {
			app.clear( messages );
			if ( ! text ) {
				return;
			}
			const notice = ui.notice( type, text, { role: 'error' === type ? 'alert' : 'status' } );
			if ( list && list.length ) {
				notice.querySelector( '.opex-notice-body' ).appendChild( el( 'ul', { class: 'opex-problems' }, list.map( ( item ) => el( 'li', {}, item ) ) ) );
			}
			messages.appendChild( notice );
		}

		// Checks the whole form and lists the problems.
		function validate() {
			const problems = [];
			const n = name.value.trim();
			if ( ! n || n.length > MAX_NAME ) {
				/* translators: %d: most characters. */
				const message = sprintf( __( 'Give the schedule a name of up to %d characters.', 'open-export' ), MAX_NAME );
				nameRow.setError( message );
				problems.push( { message, focus: () => name.focus() } );
			}
			// The builder focuses its first problem itself, so it must be open first.
			let setup = builder.validate();
			if ( setup.length ) {
				if ( builderBox.hidden ) {
					builderBox.hidden = false;
					editSetup.setAttribute( 'aria-expanded', 'true' );
				}
				setup = setup.map( ( p ) => ( { message: p.message, focus: () => builder.validate() } ) );
			}
			if ( pattern.value.length > MAX_PATTERN ) {
				/* translators: %d: most characters. */
				const message = sprintf( __( 'Use at most %d characters.', 'open-export' ), MAX_PATTERN );
				patternRow.setError( message );
				problems.push( { message, focus: () => pattern.focus() } );
			}
			return problems.concat( setup, timing.validate(), delivery.validate() );
		}

		// Collects the form into the record the server saves.
		function body() {
			return Object.assign( {
				name: name.value.trim(),
				config: builder.getConfig(),
				filename: pattern.value.trim() || '{label}-{date}',
				delivery: delivery.get(),
			}, timing.get() );
		}

		let saving = false;
		// Checks the form, then saves the schedule.
		async function submit() {
			if ( saving ) {
				return;
			}
			const problems = validate();
			if ( problems.length ) {
				say( 'error', __( 'Fix these before saving:', 'open-export' ), problems.map( ( p ) => p.message ) );
				problems[ 0 ].focus();
				return;
			}
			saving = true;
			save.setAttribute( 'aria-disabled', 'true' );
			status.set( 'busy', __( 'Saving', 'open-export' ) );
			say( '', '' );
			try {
				const path = record ? '/schedules/' + encodeURIComponent( record.id ) : '/schedules';
				const saved = await app.api.post( path, body() );
				const notes = Array.isArray( saved.notes ) ? saved.notes : [];
				delete saved.notes;
				status.clear();
				o.onSaved( saved, notes );
			} catch ( err ) {
				status.clear();
				if ( ! err.session ) {
					say( 'error', err.message );
					save.focus();
				}
			} finally {
				saving = false;
				save.removeAttribute( 'aria-disabled' );
			}
		}

		syncSummary();
		syncSample();

		return {
			root,
			focus() {
				name.focus();
			},
			setRecord( next ) {
				timing.setRecord( next );
				trigger.setRecord( next );
			},
		};
	};
}() );
