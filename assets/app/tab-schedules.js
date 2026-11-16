/**
 * Open Export admin screen: the Schedules tab.
 *
 * Lists every schedule as a card (schedule-card.js) and swaps the list for
 * the add and edit form (schedule-form.js). The list lives in the store
 * under "schedules", so History can name the schedule that started a job.
 *
 * The switch, Run now and Duplicate live in schedule-actions.js. Run now
 * starts a background job through POST /schedules/{id}/run. The job goes
 * into the shared job list and is polled the way History polls it, so its
 * card shows progress, and the card refreshes its last run when the job
 * ends. "View job" opens History on that job (store key "historyFocus").
 *
 * Registers the tab "schedules".
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, sprintf } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;
	const api = app.api;
	const store = app.store;
	const jobs = app.jobs;
	const parts = app.scheduleParts;

	/**
	 * Builds the Schedules tab: the cards and the add and edit form.
	 *
	 * @param {Element} panel Tab panel.
	 */
	function mount( panel ) {
		const messages = el( 'div', { class: 'opex-messages', 'aria-live': 'polite' } );
		const limitHelp = el( 'p', { class: 'opex-help', id: app.uid( 'opex-limit' ), hidden: true },
			/* translators: %d: most schedules allowed. */
			sprintf( __( 'You can have at most %d schedules. Delete one to add another.', 'open-export' ), parts.max() ) );
		const addButton = ui.button( { label: __( 'Add schedule', 'open-export' ), icon: 'plus', primary: true, action: 'add', describedBy: limitHelp.id, onClick: () => openForm( null, addButton ) } );
		const listBox = el( 'div', {} );
		const listView = el( 'div', {}, [
			el( 'div', { class: 'opex-pagehead' }, [
				el( 'h2', { class: 'opex-h2 opex-m0' }, __( 'Schedules', 'open-export' ) ),
				el( 'div', {}, [ addButton, limitHelp ] ),
			] ),
			messages,
			listBox,
		] );
		const formView = el( 'div', { hidden: true } );
		const cards = new Map();
		let form = null;
		let opener = null;
		let loadError = null;
		let stopPolling = null;

		app.append( panel, [ listView, formView ] );

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

		function fail( err ) {
			if ( ! err.session ) {
				say( 'error', err.message );
			}
		}

		const list = () => store.get( 'schedules' );

		function put( record ) {
			const items = ( list() || [] ).slice();
			const at = items.findIndex( ( item ) => item.id === record.id );
			if ( at > -1 ) {
				items[ at ] = record;
			} else {
				items.push( record );
			}
			store.set( 'schedules', items );
		}

		function load() {
			loadError = null;
			render();
			api.get( '/schedules' ).then( ( items ) => {
				store.set( 'schedules', Array.isArray( items ) ? items : [] );
			} ).catch( ( err ) => {
				loadError = err;
				if ( list() ) {
					fail( err );
				}
				render();
			} );
		}

		function render() {
			const items = list();
			if ( ! items ) {
				cards.clear();
				app.clear( listBox ).appendChild( loadError ? ui.loadError( loadError, load ) : ui.skeleton( 4 ) );
				addButton.disabled = true;
				return;
			}
			const full = items.length >= parts.max();
			addButton.disabled = full;
			limitHelp.hidden = ! full;
			if ( ! items.length ) {
				cards.clear();
				app.clear( listBox ).appendChild( ui.empty( {
					title: __( 'No schedules yet.', 'open-export' ),
					text: __( 'A schedule runs an export on a timetable, or when a trigger URL is called, and can send the file for you.', 'open-export' ),
					action: { label: __( 'Add schedule', 'open-export' ), onClick: () => openForm( null, addButton ) },
				} ) );
				return;
			}
			// Drop a skeleton, empty state or load error left from before.
			if ( Array.prototype.some.call( listBox.children, ( node ) => ! node.classList.contains( 'opex-sched' ) ) ) {
				app.clear( listBox );
			}
			const seen = new Set( items.map( ( s ) => s.id ) );
			cards.forEach( ( card, id ) => {
				if ( ! seen.has( id ) ) {
					card.root.remove();
					cards.delete( id );
				}
			} );
			let cursor = listBox.firstChild;
			items.forEach( ( s ) => {
				let card = cards.get( s.id );
				if ( card ) {
					card.update( s );
				} else {
					card = app.scheduleCard( s, handlers );
					cards.set( s.id, card );
				}
				card.setFull( full );
				// Move a card only when it is out of place. Moving a node blurs it.
				if ( cursor !== card.root ) {
					listBox.insertBefore( card.root, cursor );
				} else {
					cursor = card.root.nextSibling;
				}
			} );
		}

		const actions = app.scheduleActions( { say, fail, put, list, cards, focusCard } );
		const handlers = {
			onToggle: actions.toggle,
			onRun: actions.run,
			onEdit: ( s, button ) => openForm( s, button ),
			onDuplicate: actions.duplicate,
			onDelete: remove,
			onView: viewJob,
		};

		// Asks first, deletes the schedule, then drops it from the shared list.
		async function remove( s ) {
			const yes = await ui.confirm( {
				title: __( 'Delete this schedule?', 'open-export' ),
				/* translators: %s: schedule name. */
				message: sprintf( __( '%s will be deleted and its trigger URL stops working. Finished exports stay in History.', 'open-export' ), s.name ),
				confirmLabel: __( 'Delete', 'open-export' ),
				danger: true,
			} );
			if ( ! yes ) {
				return;
			}
			const items = list() || [];
			const at = items.findIndex( ( item ) => item.id === s.id );
			const neighbor = items[ at + 1 ] || items[ at - 1 ];
			try {
				await api.del( '/schedules/' + encodeURIComponent( s.id ) );
				if ( form ) {
					closeForm( false );
				}
				// Filter the list as it is now, not the copy read before the
				// request: a switch or save may have changed another schedule meanwhile.
				store.set( 'schedules', ( list() || [] ).filter( ( item ) => item.id !== s.id ) );
				/* translators: %s: schedule name. */
				say( 'success', sprintf( __( '%s was deleted.', 'open-export' ), s.name ) );
				focusCard( neighbor ? neighbor.id : null, 'run' );
			} catch ( err ) {
				fail( err );
			}
		}

		function viewJob( jobId ) {
			// Open the tab first: History can only focus a row that is visible.
			app.showTab( 'history', false );
			store.set( 'historyFocus', jobId );
		}

		function focusCard( id, action ) {
			const card = id ? cards.get( id ) : null;
			const target = card ? card.root.querySelector( '[data-action="' + action + '"]' ) : null;
			if ( target ) {
				target.focus();
			} else if ( ! addButton.disabled ) {
				addButton.focus();
			} else {
				const empty = listBox.querySelector( 'button' );
				if ( empty ) {
					empty.focus();
				}
			}
		}

		function openForm( record, from ) {
			opener = from || addButton;
			say( '', '' );
			form = app.scheduleForm( {
				record,
				onSaved: ( saved, notes ) => {
					put( saved );
					closeForm( false );
					const text = notes.length
						/* translators: %s: schedule name. */
						? sprintf( __( '%s was saved. The server changed a few things:', 'open-export' ), saved.name )
						/* translators: %s: schedule name. */
						: sprintf( __( '%s was saved.', 'open-export' ), saved.name );
					say( notes.length ? 'warning' : 'success', text, notes );
					focusCard( saved.id, 'edit' );
				},
				onCancel: () => closeForm( true ),
				onDelete: remove,
				onRecord: put,
			} );
			app.clear( formView ).appendChild( form.root );
			formView.hidden = false;
			listView.hidden = true;
			window.scrollTo( 0, 0 );
			form.focus();
		}

		function closeForm( restoreFocus ) {
			form = null;
			app.clear( formView );
			formView.hidden = true;
			listView.hidden = false;
			if ( restoreFocus ) {
				if ( opener && opener.isConnected ) {
					opener.focus();
				} else {
					addButton.focus();
				}
			}
		}

		store.subscribe( 'schedules', render );
		store.subscribe( 'jobs', actions.onJobs );

		return {
			show() {
				// Fresh numbers each time the tab opens (cron may have run), but
				// never under an open form.
				if ( ! form ) {
					load();
				}
				jobs.load().catch( () => {} );
				if ( ! stopPolling ) {
					stopPolling = jobs.startPolling();
				}
			},
			hide() {
				if ( stopPolling ) {
					stopPolling();
					stopPolling = null;
				}
			},
		};
	}

	app.registerTab( 'schedules', { mount } );
}() );
