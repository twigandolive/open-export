/**
 * Open Export admin screen: the History tab.
 *
 * Lists every job with its status, progress and actions. Rows are kept and
 * updated in place when the job list changes, so a running job moves its
 * progress bar without rebuilding the table or taking focus away.
 *
 * Another tab opens one job by setting the store key "historyFocus" to its
 * id: the row's details open and its toggle takes focus.
 *
 * Registers the tab "history". Uses opexApp.jobs for data and polling,
 * opexApp.historyView for the cells and the detail panel, and
 * opexApp.historyActions for the row actions.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, sprintf } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;
	const view = app.historyView;
	const jobs = app.jobs;
	const store = app.store;
	const COLS = 8;
	// Where focus goes when the action someone used turns into another one.
	const COUNTERPART = { pause: 'resume', resume: 'pause', rerun: 'download' };

	/**
	 * Builds the History tab and keeps its rows in step with the job list.
	 *
	 * @param {Element} panel Tab panel.
	 */
	function mount( panel ) {
		const messages = el( 'div', { class: 'opex-messages', 'aria-live': 'polite' } );
		const body = el( 'div', {} );
		const hintId = app.uid( 'opex-hint' );
		const rows = new Map();
		let table = null;
		let stopPolling = null;
		let loadState = 'idle';
		let loadError = null;
		let schedulesAsked = false;
		// The send again button needs POST /jobs/{id}/deliver, which an older server does not have.
		let canDeliver = false;

		app.append( panel, [ messages, body ] );

		// Shows a message above the table.
		function say( type, text ) {
			app.clear( messages );
			if ( text ) {
				messages.appendChild( ui.notice( type, text, { role: 'error' === type ? 'alert' : 'status' } ) );
			}
		}

		// Shows an error, unless the session has expired.
		function fail( err ) {
			if ( ! err.session ) {
				say( 'error', err.message );
			}
		}

		const rowActions = app.historyActions( { say, fail, rows, body, hintId } );

		// Reads the exports from the server.
		function load() {
			loadState = 'loading';
			render();
			jobs.load().then( () => {
				loadState = 'ready';
				render();
			} ).catch( ( err ) => {
				loadState = 'error';
				loadError = err;
				if ( store.get( 'jobs' ) ) {
					fail( err );
				}
				render();
			} );
		}

		// Names for jobs that a schedule started, when the server does not
		// send schedule_name yet. One request, only when needed.
		function askSchedules( list ) {
			if ( schedulesAsked || ! list.some( ( job ) => job.schedule_id && ! job.schedule_name ) ) {
				return;
			}
			schedulesAsked = true;
			app.api.get( '/schedules' ).then( ( items ) => store.set( 'schedules', Array.isArray( items ) ? items : [] ) ).catch( () => {} );
		}

		// Draws the table, a loading state or the empty state.
		function render() {
			const list = store.get( 'jobs' );
			if ( 'loading' === loadState && ! list ) {
				showOnly( ui.skeleton( 4 ) );
				return;
			}
			if ( 'error' === loadState && ! list ) {
				showOnly( ui.loadError( loadError, load ) );
				return;
			}
			if ( ! list ) {
				return;
			}
			if ( ! list.length ) {
				dropTable();
				showOnly( ui.empty( {
					title: __( 'No exports yet.', 'open-export' ),
					text: __( 'Finished and running exports are listed here.', 'open-export' ),
					action: { label: __( 'Start an export', 'open-export' ), onClick: () => app.showTab && app.showTab( 'export', true ) },
				} ) );
				return;
			}
			askSchedules( list );
			sync( list );
			takeFocus();
		}

		// Another tab asked to show one job (store key "historyFocus", a job id).
		// A job missing from the list gets one fresh load before giving up.
		let focusReloaded = false;
		// Opens and puts focus on the row another tab asked for.
		function takeFocus() {
			const id = store.get( 'historyFocus' );
			if ( ! id || 'ready' !== loadState ) {
				return;
			}
			const row = rows.get( id );
			if ( ! row && ! focusReloaded ) {
				focusReloaded = true;
				jobs.load().catch( () => {} );
				return;
			}
			focusReloaded = false;
			store.set( 'historyFocus', null );
			if ( ! row ) {
				say( 'warning', __( 'That export is no longer in History. Open Export removes finished exports after the number of days set in Settings.', 'open-export' ) );
				return;
			}
			if ( row.detail.hidden ) {
				row.toggle.click();
			}
			row.toggle.focus();
			row.tr.scrollIntoView( { block: 'nearest' } );
		}

		// Shows one node in place of the table.
		function showOnly( node ) {
			table = null;
			rows.clear();
			app.clear( body ).appendChild( node );
		}

		// Forgets the table and its rows.
		function dropTable() {
			table = null;
			rows.clear();
		}

		// Builds the table the first time it is needed.
		function ensureTable() {
			if ( table ) {
				return;
			}
			table = ui.table( {
				caption: __( 'Export history', 'open-export' ),
				cls: 'opex-stack-sm',
				columns: [
					{ label: __( 'Name', 'open-export' ) },
					{ label: __( 'Source and format', 'open-export' ) },
					{ label: __( 'Status', 'open-export' ) },
					{ label: __( 'Progress', 'open-export' ) },
					{ label: __( 'Size', 'open-export' ) },
					{ label: __( 'Started', 'open-export' ) },
					{ label: __( 'Started by', 'open-export' ) },
					{ label: __( 'Actions', 'open-export' ) },
				],
			} );
			app.clear( body );
			app.append( body, [
				table.root,
				el( 'p', { class: 'opex-help', id: hintId }, __( 'Edit and run again loads the setup of an export into the Export tab, so you can change it and run it again.', 'open-export' ) ),
			] );
		}

		// Updates the rows to match the export list.
		function sync( list ) {
			ensureTable();
			// Drop gone rows first, so the rows after them stay in place.
			const seen = new Set( list.map( ( job ) => job.id ) );
			rows.forEach( ( row, id ) => {
				if ( ! seen.has( id ) ) {
					row.tr.remove();
					row.detail.remove();
					rows.delete( id );
				}
			} );
			let cursor = table.tbody.firstChild;
			list.forEach( ( job ) => {
				let row = rows.get( job.id );
				if ( ! row ) {
					row = createRow( job );
					rows.set( job.id, row );
				}
				updateRow( row, job );
				// Move a row only when it is out of place. Moving a node blurs it.
				if ( cursor !== row.tr ) {
					table.tbody.insertBefore( row.tr, cursor );
					table.tbody.insertBefore( row.detail, cursor );
				} else {
					cursor = row.detail.nextSibling;
				}
			} );
		}

		// Builds the row of one export.
		function createRow( job ) {
			const detailId = app.uid( 'opex-job' );
			const toggle = el( 'button', {
				type: 'button',
				class: 'opex-iconbtn',
				'aria-expanded': 'false',
				'aria-controls': detailId,
				'data-action': 'toggle',
			}, app.icon( 'chevron', { sm: true, cls: 'opex-chev' } ) );
			const name = el( 'strong', {} );
			const file = el( 'span', { class: 'opex-subline' } );
			const progress = view.progress( job );
			// data-label names each cell when narrow screens stack the table.
			const cells = {
				format: el( 'td', { 'data-label': __( 'Source and format', 'open-export' ) } ),
				status: el( 'td', { 'data-label': __( 'Status', 'open-export' ) } ),
				progress: el( 'td', { class: 'opex-progress-cell', 'data-label': __( 'Progress', 'open-export' ) }, progress.root ),
				size: el( 'td', { class: 'opex-nowrap', 'data-label': __( 'Size', 'open-export' ) } ),
				started: el( 'td', { class: 'opex-nowrap', 'data-label': __( 'Started', 'open-export' ) } ),
				by: el( 'td', { 'data-label': __( 'Started by', 'open-export' ) } ),
				actions: el( 'td', {} ),
			};
			const tr = el( 'tr', {}, [
				el( 'td', {}, [ el( 'span', { class: 'opex-nameline' }, [ toggle, el( 'span', {}, [ name, file ] ) ] ) ] ),
				cells.format, cells.status, cells.progress, cells.size, cells.started, cells.by, cells.actions,
			] );
			const detailCell = el( 'td', { colspan: COLS } );
			const detail = el( 'tr', { class: 'opex-detail', id: detailId, hidden: true }, detailCell );
			const row = { id: job.id, job, tr, detail, detailCell, toggle, name, file, progress, cells, statusKey: '', actionsKey: '', detailKey: '', busy: false };
			toggle.addEventListener( 'click', () => {
				detail.hidden = ! detail.hidden;
				toggle.setAttribute( 'aria-expanded', detail.hidden ? 'false' : 'true' );
				row.detailKey = '';
				updateRow( row, row.job );
			} );
			return row;
		}

		// Changes the text of a node only when it differs.
		function setText( node, text ) {
			if ( node.textContent !== text ) {
				node.textContent = text;
			}
		}

		// Rebuilds a part, then puts focus back on the same action, or its
		// counterpart, if the person was using it. Returns true when focus
		// was inside and has nowhere to go.
		function rebuild( container, build ) {
			const active = document.activeElement;
			const action = active && container.contains( active ) ? active.getAttribute( 'data-action' ) : null;
			app.clear( container );
			app.append( container, build() );
			if ( ! action ) {
				return false;
			}
			const find = ( name ) => container.querySelector( '[data-action="' + name + '"]' );
			const again = find( action ) || find( COUNTERPART[ action ] || '' );
			if ( again ) {
				again.focus();
			}
			return ! again;
		}

		// Updates a row for the export's latest state.
		function updateRow( row, job ) {
			row.job = job;
			setText( row.name, job.label );
			setText( row.file, job.delivery_name || '' );
			/* translators: %s: export name. */
			row.toggle.setAttribute( 'aria-label', sprintf( __( 'Show details for %s', 'open-export' ), job.label ) );
			// source_label arrives with the server's add-only job fields. Older jobs show the format only.
			setText( row.cells.format, app.list( [ job.source_label || '', view.formatLabel( job ) ] ) );
			if ( row.statusKey !== job.status ) {
				row.statusKey = job.status;
				app.clear( row.cells.status ).appendChild( view.statusBadge( job ) );
			}
			row.progress.update( job );
			setText( row.cells.size, view.sizeText( job ) );
			setText( row.cells.started, app.formatDate( job.created ) );
			updateBy( row, job );
			const actionsKey = job.status + ( jobs.isDriving( job.id ) ? ':driving' : '' );
			if ( row.actionsKey !== actionsKey ) {
				row.actionsKey = actionsKey;
				if ( rebuild( row.cells.actions, () => rowActions.build( row ) ) ) {
					row.toggle.focus();
				}
			}
			if ( ! row.detail.hidden ) {
				const key = view.detailKey( job, canDeliver );
				if ( row.detailKey !== key ) {
					row.detailKey = key;
					rebuild( row.detailCell, () => view.detail( job, { onDeliver: canDeliver ? rowActions.deliver : null } ) );
				}
			}
		}

		// Updates the Started by cell.
		function updateBy( row, job ) {
			const text = view.startedBy( job );
			const key = text + ( 'background' === job.mode ? ':bg' : '' );
			if ( row.byKey === key ) {
				return;
			}
			row.byKey = key;
			app.clear( row.cells.by );
			app.append( row.cells.by, [
				el( 'span', {}, text ),
				'background' === job.mode ? el( 'span', { class: 'opex-badge opex-ml' }, __( 'Background', 'open-export' ) ) : null,
			] );
		}

		store.subscribe( 'jobs', () => {
			if ( store.get( 'jobs' ) ) {
				loadState = 'ready';
			}
			render();
		} );
		store.subscribe( 'driving', render );
		app.api.hasRoute( '/deliver' ).then( ( yes ) => {
			canDeliver = yes;
			rows.forEach( ( row ) => updateRow( row, row.job ) );
		} );
		store.subscribe( 'schedules', () => rows.forEach( ( row ) => updateBy( row, row.job ) ) );
		store.subscribe( 'historyFocus', takeFocus );

		load();

		return {
			show() {
				if ( 'ready' === loadState ) {
					jobs.load().catch( () => {} );
				}
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

	app.registerTab( 'history', { mount } );
}() );
