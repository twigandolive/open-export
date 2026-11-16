/**
 * Open Export admin screen: the row actions of the History tab.
 *
 * Builds the action links for one job and runs them through opexApp.jobs.
 * tab-history.js owns the rows and passes a small context in, so this file
 * never reaches into the table.
 *
 * Exposes opexApp.historyActions( ctx ) with ctx:
 *   say( type, text )  Shows a result or error above the table.
 *   fail( error )      Shows a normalized error.
 *   rows               The Map of row views by job id.
 *   body               The tab body, for focusing the empty state.
 *   hintId             Id of the hint line under the table.
 * Returns { build( row ), deliver( job ) }.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, sprintf } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;
	const jobs = app.jobs;

	/**
	 * Builds the row actions of the History tab and runs them through the shared job list.
	 *
	 * @param {Object} ctx say, fail, rows, body and hintId from the History tab.
	 */
	app.historyActions = function ( ctx ) {
		const { say, fail, rows, body, hintId } = ctx;

		function build( row ) {
			const job = row.job;
			const items = [];
			const act = ( action, label, fn, extra ) => ui.linkButton( Object.assign( { label, action, onClick: () => guard( row, fn ) }, extra || {} ) );
			if ( 'done' === job.status ) {
				items.push( el( 'a', { href: app.api.downloadUrl( job.id ), 'data-action': 'download' }, __( 'Download', 'open-export' ) ) );
			}
			if ( 'running' === job.status ) {
				items.push( act( 'pause', __( 'Pause', 'open-export' ), () => pause( job ) ) );
			}
			if ( 'paused' === job.status || 'error' === job.status ) {
				items.push( act( 'resume', __( 'Resume', 'open-export' ), () => resume( job ) ) );
			}
			if ( 'done' === job.status || 'error' === job.status ) {
				items.push( act( 'rerun', __( 'Run again', 'open-export' ), () => rerun( job ) ) );
			}
			items.push( act( 'builder', __( 'Open in builder', 'open-export' ), () => openInBuilder( job ), { describedBy: hintId } ) );
			items.push( act( 'delete', __( 'Delete', 'open-export' ), () => remove( row ), { danger: true } ) );
			return el( 'span', { class: 'opex-actions' }, items );
		}

		// One action at a time per row, so a double click sends one request.
		function guard( row, fn ) {
			if ( row.busy ) {
				return;
			}
			row.busy = true;
			Promise.resolve().then( fn ).catch( fail ).then( () => {
				row.busy = false;
			} );
		}

		function pause( job ) {
			return jobs.pause( job.id ).then( () => say( 'success', __( 'Export paused.', 'open-export' ) ) );
		}

		function resume( job ) {
			say( '', '' );
			// Driving takes as long as the export. Free the row at once and
			// report the end when it comes.
			jobs.resume( job ).then( ( last ) => {
				if ( last && 'done' === last.status ) {
					/* translators: %s: export name. */
					say( 'success', sprintf( __( '%s finished.', 'open-export' ), last.label ) );
				}
			} ).catch( fail );
		}

		function rerun( job ) {
			return jobs.rerun( job.id ).then( ( fresh ) => {
				/* translators: %s: export name. */
				say( 'success', sprintf( __( 'Started %s.', 'open-export' ), fresh.label ) );
				if ( 'browser' === fresh.mode && 'running' === fresh.status ) {
					jobs.drive( fresh.id, false ).catch( fail );
				}
			} );
		}

		// GET /jobs/{id} carries the config. The Export tab reads the store
		// key builderLoad, even if it has not been opened yet.
		function openInBuilder( job ) {
			return app.api.get( '/jobs/' + encodeURIComponent( job.id ) ).then( ( detail ) => {
				if ( ! detail || ! detail.config ) {
					say( 'error', __( 'This export has no saved setup to open.', 'open-export' ) );
					return;
				}
				app.store.set( 'builderLoad', { config: detail.config, name: detail.label || job.label } );
				if ( app.showTab ) {
					app.showTab( 'export', true );
				}
			} );
		}

		// An older server has no POST /jobs/{id}/deliver. history-view.js shows
		// the button only when the server sends the job fields that go with it.
		function deliver( job ) {
			return app.api.post( '/jobs/' + encodeURIComponent( job.id ) + '/deliver' ).then( ( result ) => {
				if ( result && result.id ) {
					jobs.upsert( result );
				} else {
					jobs.load().catch( () => {} );
				}
				say( 'success', __( 'Sending again. The delivery lines update when it is done.', 'open-export' ) );
			} ).catch( fail );
		}

		function remove( row ) {
			const job = row.job;
			return ui.confirm( {
				title: __( 'Delete this export?', 'open-export' ),
				/* translators: %s: export name. */
				message: sprintf( __( '%s and its file will be deleted. This cannot be undone.', 'open-export' ), job.label ),
				confirmLabel: __( 'Delete', 'open-export' ),
				danger: true,
			} ).then( ( yes ) => {
				if ( ! yes ) {
					return null;
				}
				// Pick where focus goes before the row disappears.
				const ids = Array.from( rows.keys() );
				const at = ids.indexOf( job.id );
				const nextId = ids[ at + 1 ] || ids[ at - 1 ];
				return jobs.remove( job.id ).then( () => {
					say( 'success', __( 'Export deleted.', 'open-export' ) );
					const next = nextId && rows.get( nextId );
					const target = next ? next.toggle : body.querySelector( '[data-action="empty"]' );
					if ( target ) {
						target.focus();
					}
				} );
			} );
		}

		return { build, deliver };
	};
}() );
