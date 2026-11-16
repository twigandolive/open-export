/**
 * Open Export admin screen: the timetable and "changes only" rows of the
 * schedule form.
 *
 * Frequency comes from /options frequencies. Only the controls that apply
 * show: a time for hourly (its minutes), every 6 hours and twice a day
 * (the time the runs repeat from), daily, weekly and monthly, a
 * weekday for weekly, a day of the month for monthly. Changes only writes
 * incremental { enabled, field }, with date fields from the chosen source,
 * and shows the watermark with a "Start over" action wired to
 * POST /schedules/{id}/reset-watermark when the server has that route.
 *
 * Exposes opexApp.scheduleTiming( { record, builder, onRecord } ):
 *   Returns { rows: [ frequency, changes ], get(), validate(), refresh(), setRecord( record ) }.
 *   get() returns { frequency, time, weekday, monthday, enabled, incremental }.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, sprintf } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;
	const parts = app.scheduleParts;
	const TIME = /^([01]\d|2[0-3]):[0-5]\d$/;

	app.scheduleTiming = function ( o ) {
		const options = app.store.get( 'options' ) || {};
		let record = o.record || null;
		const base = record || {};

		const freq = el( 'select', {} );
		( options.frequencies || [] ).forEach( ( item ) => freq.appendChild( el( 'option', { value: item.id }, item.label ) ) );
		freq.value = base.frequency || 'manual';
		if ( ! freq.value && freq.options.length ) {
			freq.value = freq.options[ 0 ].value;
		}
		const time = el( 'input', { type: 'time', class: 'opex-w-sm', value: base.time || '03:00', step: 60 } );
		const weekday = el( 'select', {} );
		( options.weekdays || [] ).forEach( ( item ) => weekday.appendChild( el( 'option', { value: String( item.value ) }, item.label ) ) );
		weekday.value = String( undefined === base.weekday ? 1 : base.weekday );
		const monthday = el( 'select', {} );
		for ( let d = 1; d <= 28; d++ ) {
			monthday.appendChild( el( 'option', { value: String( d ) }, app.formatNumber( d ) ) );
		}
		monthday.appendChild( el( 'option', { value: 'last' }, __( 'Last day', 'open-export' ) ) );
		monthday.value = String( base.monthday || 1 );
		const mini = ( label, control ) => el( 'label', { class: 'opex-mini' }, [ label, control ] );
		const timeBox = mini( __( 'Time', 'open-export' ), time );
		const weekdayBox = mini( __( 'Day of the week', 'open-export' ), weekday );
		const monthdayBox = mini( __( 'Day of the month', 'open-export' ), monthday );
		const enabled = el( 'input', { type: 'checkbox', checked: record ? !! record.enabled : true } );
		const enabledLine = el( 'label', { class: 'opex-checkline' }, [ enabled, __( 'Run on this timetable', 'open-export' ) ] );
		const freqHelp = el( 'p', { class: 'opex-help' } );
		const timeError = el( 'p', { class: 'opex-field-error', hidden: true, id: app.uid( 'opex-err' ) } );
		const frequency = ui.field( {
			label: __( 'Frequency', 'open-export' ),
			input: freq,
			control: el( 'div', {}, [ el( 'div', { class: 'opex-inline' }, [ freq, timeBox, weekdayBox, monthdayBox ] ), timeError, enabledLine, freqHelp ] ),
		} );

		function syncFrequency() {
			const f = freq.value;
			timeBox.hidden = 'manual' === f;
			weekdayBox.hidden = 'weekly' !== f;
			monthdayBox.hidden = 'monthly' !== f;
			enabledLine.hidden = 'manual' === f;
			let help = '';
			if ( 'manual' === f ) {
				help = __( 'Runs only when you press Run now, or when something calls the trigger URL.', 'open-export' );
			} else {
				/* translators: %s: site time zone, for example Europe/Berlin or +02:00. */
				help = sprintf( __( 'Times use the site time zone, %s.', 'open-export' ), options.timezone || '' );
				if ( 'hourly' === f ) {
					help = sprintf(
						/* translators: %s: site time zone. */
						__( 'Hourly runs use the minutes of this time. Times use the site time zone, %s.', 'open-export' ),
						options.timezone || ''
					);
				} else if ( 'every_6_hours' === f || 'twicedaily' === f ) {
					help = sprintf(
						/* translators: %s: site time zone. */
						__( 'Runs at this time and repeats through the day, for example 03:00, 09:00, 15:00 and 21:00 every 6 hours. Times use the site time zone, %s.', 'open-export' ),
						options.timezone || ''
					);
				}
			}
			freqHelp.textContent = help;
		}
		freq.addEventListener( 'change', syncFrequency );
		time.addEventListener( 'input', () => {
			timeError.hidden = true;
			time.removeAttribute( 'aria-invalid' );
		} );
		syncFrequency();

		// Changes only.
		const inc = Object.assign( { enabled: false, field: '' }, base.incremental || {} );
		const incBox = el( 'input', { type: 'checkbox', checked: !! inc.enabled } );
		const incLine = el( 'label', { class: 'opex-checkline' }, [ incBox, __( 'Only export rows that are new or changed since the last run', 'open-export' ) ] );
		const dateField = el( 'select', {} );
		const dateBox = mini( __( 'Date field', 'open-export' ), dateField );
		const markText = el( 'span', {} );
		const startOver = ui.linkButton( { label: __( 'Start over', 'open-export' ), action: 'start-over', onClick: () => resetMark() } );
		const markLine = el( 'p', { class: 'opex-help' }, [ markText, ' ', startOver ] );
		const incHelp = el( 'p', { class: 'opex-help' } );
		const incError = el( 'p', { class: 'opex-field-error', hidden: true } );
		const result = ui.result();
		const changes = ui.field( {
			label: __( 'Changes only', 'open-export' ),
			rowLabel: true,
			control: el( 'div', {}, [ incLine, el( 'div', { class: 'opex-inline opex-mt' }, [ dateBox ] ), incError, markLine, incHelp, result.root ] ),
		} );
		let canReset = false;
		app.api.hasRoute( '/reset-watermark' ).then( ( yes ) => {
			canReset = yes;
			syncMark();
		} );

		function dateFields() {
			const ctx = o.builder.ctx;
			return ( ctx.fields && ctx.fields.date_fields ) || [];
		}

		function refresh() {
			const ctx = o.builder.ctx;
			const list = dateFields();
			const keep = dateField.value || inc.field;
			app.clear( dateField );
			list.forEach( ( f ) => dateField.appendChild( el( 'option', { value: f.id }, f.label ) ) );
			if ( keep && ! list.some( ( f ) => f.id === keep ) && ctx.fields ) {
				/* translators: %s: field id. */
				dateField.appendChild( el( 'option', { value: keep }, sprintf( __( '%s (not on this source)', 'open-export' ), keep ) ) );
			}
			if ( keep ) {
				dateField.value = keep;
			}
			const ready = !! ctx.fields && list.length > 0;
			incBox.disabled = ! ready && ! incBox.checked;
			dateField.disabled = ! incBox.checked || ! ready;
			dateBox.hidden = ! incBox.checked;
			if ( ! ctx.config.source ) {
				incHelp.textContent = __( 'Choose a source in the export setup first.', 'open-export' );
			} else if ( ! ctx.fields ) {
				incHelp.textContent = '';
			} else if ( ! list.length ) {
				incHelp.textContent = __( 'This source has no date field, so every run exports all rows.', 'open-export' );
			} else {
				incHelp.textContent = __( 'Rows count as changed when this date is after the start of the last run that finished.', 'open-export' );
			}
			syncMark();
		}

		function syncMark() {
			const ts = parts.watermarkTime( record && record.watermark );
			markLine.hidden = ! incBox.checked || ! record;
			if ( ts ) {
				/* translators: %s: date and time. */
				markText.textContent = sprintf( __( 'The last finished run covered rows up to %s.', 'open-export' ), app.formatDate( ts ) );
			} else {
				markText.textContent = __( 'The next run exports every row. Runs after it export only the changes.', 'open-export' );
			}
			startOver.hidden = ! ts || ! canReset;
		}

		incBox.addEventListener( 'change', () => {
			incError.hidden = true;
			refresh();
			if ( incBox.checked && ! dateField.disabled ) {
				dateField.focus();
			}
		} );

		async function resetMark() {
			const yes = await ui.confirm( {
				title: __( 'Start over?', 'open-export' ),
				message: __( 'The next run will export every row again, not only the changes. Later runs go back to changes only.', 'open-export' ),
				confirmLabel: __( 'Start over', 'open-export' ),
			} );
			if ( ! yes || ! record ) {
				return;
			}
			result.set( 'busy', __( 'Starting over', 'open-export' ) );
			try {
				record = await app.api.post( '/schedules/' + encodeURIComponent( record.id ) + '/reset-watermark' );
				syncMark();
				result.set( 'success', __( 'Done. The next run exports every row.', 'open-export' ) );
				incBox.focus();
				if ( o.onRecord ) {
					o.onRecord( record );
				}
			} catch ( err ) {
				if ( err.session ) {
					result.clear();
				} else {
					result.set( 'error', err.message );
				}
			}
		}

		function get() {
			const f = freq.value;
			return {
				frequency: f,
				time: time.value || '03:00',
				weekday: parseInt( weekday.value, 10 ) || 0,
				monthday: 'last' === monthday.value ? 'last' : parseInt( monthday.value, 10 ) || 1,
				enabled: 'manual' === f ? ( record ? !! record.enabled : false ) : enabled.checked,
				incremental: { enabled: incBox.checked, field: incBox.checked ? dateField.value : ( dateField.value || inc.field || '' ) },
			};
		}

		function validate() {
			const problems = [];
			if ( 'manual' !== freq.value && ! TIME.test( time.value ) ) {
				timeError.textContent = __( 'Enter a time such as 14:30.', 'open-export' );
				timeError.hidden = false;
				time.setAttribute( 'aria-invalid', 'true' );
				time.setAttribute( 'aria-describedby', timeError.id );
				problems.push( { message: timeError.textContent, focus: () => time.focus() } );
			}
			if ( incBox.checked && ! dateField.value ) {
				incError.textContent = __( 'Choose the date field that marks a row as changed.', 'open-export' );
				incError.hidden = false;
				problems.push( { message: incError.textContent, focus: () => ( dateField.disabled ? incBox : dateField ).focus() } );
			}
			return problems;
		}

		refresh();

		return {
			rows: [ frequency.root, changes.root ],
			get,
			validate,
			refresh,
			setRecord( next ) {
				record = next;
				syncMark();
			},
		};
	};
}() );
