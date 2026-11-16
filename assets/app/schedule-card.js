/**
 * Open Export admin screen: one schedule card in the Schedules list.
 *
 * Shows the name, the on and off switch, the timetable in words, the next
 * run, the last run (with a link to its job in History), the delivery
 * channels and, while a run is going, its progress. A failed last run gets
 * an error notice. The switch and the action buttons are built once and
 * kept, so updating a card never takes focus away.
 *
 * Exposes opexApp.scheduleCard( schedule, handlers ):
 *   handlers: { onToggle( s, on, input ), onRun( s, button ), onEdit( s, button ),
 *               onDuplicate( s, button ), onDelete( s ), onView( jobId ) }
 *   Returns { root, update( schedule ), sync(), setRunBusy( busy ), setFull( full ) }.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, sprintf } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;
	const parts = app.scheduleParts;
	const view = app.historyView;

	app.scheduleCard = function ( schedule, h ) {
		let s = schedule;
		const titleId = app.uid( 'opex-sched' );
		const title = el( 'h3', { class: 'opex-sched-name', id: titleId } );
		const stateText = el( 'span', { 'aria-hidden': 'true' } );
		const toggle = el( 'input', { type: 'checkbox', role: 'switch', class: 'opex-switch', 'data-action': 'toggle' } );
		toggle.addEventListener( 'change', () => h.onToggle( s, toggle.checked, toggle ) );
		const notice = el( 'div', {} );
		const meta = {
			timetable: el( 'dd', {} ),
			next: el( 'dd', {} ),
			last: el( 'dd', {} ),
			delivery: el( 'dd', { class: 'opex-meta-icons' } ),
		};
		const runBox = el( 'div', { class: 'opex-sched-run', hidden: true } );
		const runButton = ui.button( { label: __( 'Run now', 'open-export' ), icon: 'play', small: true, action: 'run', onClick: () => {
			if ( 'true' !== runButton.getAttribute( 'aria-disabled' ) ) {
				h.onRun( s, runButton );
			}
		} } );
		const editButton = ui.linkButton( { label: __( 'Edit', 'open-export' ), action: 'edit', onClick: () => h.onEdit( s, editButton ) } );
		const copyButton = ui.linkButton( { label: __( 'Duplicate', 'open-export' ), action: 'duplicate', onClick: () => h.onDuplicate( s, copyButton ) } );
		const deleteButton = ui.linkButton( { label: __( 'Delete', 'open-export' ), action: 'delete', danger: true, onClick: () => h.onDelete( s ) } );
		let lastKey = '';
		let runJob = null;
		let progress = null;

		const pair = ( label, dd ) => el( 'div', {}, [ el( 'dt', {}, label ), dd ] );
		const root = el( 'article', { class: 'opex-card opex-sched', 'aria-labelledby': titleId, 'data-id': s.id }, [
			el( 'div', { class: 'opex-sched-head' }, [
				title,
				el( 'label', { class: 'opex-switchlabel' }, [ toggle, stateText ] ),
			] ),
			notice,
			el( 'dl', { class: 'opex-meta' }, [
				pair( __( 'Schedule', 'open-export' ), meta.timetable ),
				pair( __( 'Next run', 'open-export' ), meta.next ),
				pair( __( 'Last run', 'open-export' ), meta.last ),
				pair( __( 'Delivery', 'open-export' ), meta.delivery ),
			] ),
			runBox,
			el( 'div', { class: 'opex-sched-actions' }, [ runButton, editButton, copyButton, deleteButton ] ),
		] );

		function setText( node, text ) {
			if ( node.textContent !== text ) {
				node.textContent = text;
			}
		}

		// The error of the last run, when its job is still in the list.
		function failText() {
			const run = s.last_run || {};
			const job = ( app.store.get( 'jobs' ) || [] ).find( ( item ) => item.id === run.job_id );
			if ( job && job.error ) {
				/* translators: %s: the error the server recorded on the job. */
				return sprintf( __( 'The last run failed: %s Check the setup, then run it again.', 'open-export' ), job.error );
			}
			return __( 'The last run failed. Open the job in History to see why, then run it again.', 'open-export' );
		}

		function render() {
			setText( title, s.name );
			setText( stateText, s.enabled ? __( 'On', 'open-export' ) : __( 'Off', 'open-export' ) );
			toggle.checked = !! s.enabled;
			/* translators: %s: schedule name. */
			toggle.setAttribute( 'aria-label', sprintf( __( 'Run %s on its timetable', 'open-export' ), s.name ) );
			/* translators: %s: schedule name. */
			runButton.setAttribute( 'aria-label', sprintf( __( 'Run %s now', 'open-export' ), s.name ) );
			/* translators: %s: schedule name. */
			editButton.setAttribute( 'aria-label', sprintf( __( 'Edit %s', 'open-export' ), s.name ) );
			/* translators: %s: schedule name. */
			copyButton.setAttribute( 'aria-label', sprintf( __( 'Duplicate %s', 'open-export' ), s.name ) );
			/* translators: %s: schedule name. */
			deleteButton.setAttribute( 'aria-label', sprintf( __( 'Delete %s', 'open-export' ), s.name ) );
			setText( meta.timetable, parts.timetable( s ) );
			setText( meta.next, parts.nextRun( s ) );
			const run = s.last_run || {};
			const key = [ run.time, run.status, run.rows, run.job_id, s.name ].join( '|' );
			const failed = 'error' === run.status;
			const text = failed ? failText() : '';
			if ( key !== lastKey || ( notice.textContent || '' ).indexOf( text ) < 0 ) {
				lastKey = key;
				const focusView = meta.last.contains( document.activeElement );
				app.clear( meta.last );
				app.append( meta.last, parts.lastRun( s, h.onView ) );
				if ( focusView && meta.last.querySelector( 'button' ) ) {
					meta.last.querySelector( 'button' ).focus();
				}
				app.clear( notice );
				if ( failed ) {
					// role note, not alert: a page full of old failures must not interrupt.
					notice.appendChild( ui.notice( 'error', text, { role: 'note' } ) );
				}
			}
			app.clear( meta.delivery );
			app.append( meta.delivery, parts.deliveryList( s.delivery ) );
			sync();
		}

		/**
		 * Shows the progress of a running or paused job of this schedule,
		 * the same bar History shows.
		 */
		function sync() {
			const job = parts.activeJob( s.id );
			if ( ! job ) {
				if ( runJob ) {
					runJob = null;
					progress = null;
					app.clear( runBox );
					runBox.hidden = true;
				}
				return;
			}
			if ( ! runJob || runJob.id !== job.id ) {
				progress = view.progress( job );
				app.clear( runBox );
				app.append( runBox, [
					el( 'span', { class: 'opex-sched-runhead' }, [
						view.statusBadge( job ),
						ui.linkButton( {
							label: __( 'View in History', 'open-export' ),
							action: 'view-run',
							/* translators: %s: schedule name. */
							ariaLabel: sprintf( __( 'View the current run of %s in History', 'open-export' ), s.name ),
							onClick: () => h.onView( job.id ),
						} ),
					] ),
					progress.root,
				] );
				runBox.hidden = false;
			} else if ( runJob.status !== job.status ) {
				runBox.firstChild.replaceChild( view.statusBadge( job ), runBox.firstChild.firstChild );
			}
			progress.update( job );
			runJob = job;
		}

		render();

		return {
			root,
			update( next ) {
				s = next;
				render();
			},
			sync,
			// aria-disabled, not disabled: a disabled button drops focus.
			setRunBusy( busy ) {
				if ( busy ) {
					runButton.setAttribute( 'aria-disabled', 'true' );
				} else {
					runButton.removeAttribute( 'aria-disabled' );
				}
			},
			setFull( full ) {
				copyButton.disabled = !! full;
			},
		};
	};
}() );
