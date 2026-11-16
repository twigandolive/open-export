/**
 * Open Export admin screen: the list actions of the Schedules tab.
 *
 * The on and off switch (saved at once), Run now, Duplicate (client side:
 * a new schedule from the same fields, " copy" added to the name, switched
 * off), and following runs: every running job of a schedule is watched
 * through the shared job list, and when it ends the schedule is read again
 * so its card shows the new last run.
 *
 * Exposes opexApp.scheduleActions( { say, fail, put, list, cards, focusCard } ):
 *   Returns { toggle( s, on, input ), run( s ), duplicate( s ), onJobs() }.
 *   Call onJobs() whenever the store key "jobs" changes.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, sprintf } = window.wp.i18n;
	const api = app.api;
	const store = app.store;
	const jobs = app.jobs;
	const parts = app.scheduleParts;

	/**
	 * Builds the list actions of the Schedules tab: the on and off switch, Run now, Duplicate, and following runs until they end.
	 *
	 * @param {Object} o say, fail, put, list, cards and focusCard from the Schedules tab.
	 * @return {Object} { toggle( s, on, input ), run( s ), duplicate( s ), onJobs() }.
	 */
	app.scheduleActions = function ( o ) {
		const { say, fail, put, list, cards, focusCard } = o;
		// Job id to schedule id, for jobs seen running.
		const following = new Map();

		async function toggle( s, on, input ) {
			try {
				const saved = await api.post( '/schedules/' + encodeURIComponent( s.id ), { enabled: on } );
				delete saved.notes;
				put( saved );
				say( 'success', on
					/* translators: %s: schedule name. */
					? sprintf( __( '%s is on.', 'open-export' ), saved.name )
					/* translators: %s: schedule name. */
					: sprintf( __( '%s is off. It runs only when started by hand or by its trigger URL.', 'open-export' ), saved.name ) );
			} catch ( err ) {
				input.checked = ! on;
				fail( err );
			}
		}

		async function run( s ) {
			const card = cards.get( s.id );
			card.setRunBusy( true );
			/* translators: %s: schedule name. */
			say( 'info', sprintf( __( 'Starting %s', 'open-export' ), s.name ) );
			try {
				const job = await api.post( '/schedules/' + encodeURIComponent( s.id ) + '/run' );
				following.set( job.id, s.id );
				jobs.upsert( job );
				if ( jobs.isActive( job ) ) {
					/* translators: %s: schedule name. */
					say( 'success', sprintf( __( '%s started. It runs in the background, and this card shows its progress.', 'open-export' ), s.name ) );
				}
				refreshOne( s.id );
			} catch ( err ) {
				if ( 'opex_busy' === err.code ) {
					const active = parts.activeJob( s.id );
					say( 'warning', err.message );
					if ( active ) {
						following.set( active.id, s.id );
					} else {
						jobs.load().catch( () => {} );
					}
				} else {
					fail( err );
				}
			} finally {
				card.setRunBusy( false );
			}
		}

		function refreshOne( id ) {
			return api.get( '/schedules/' + encodeURIComponent( id ) ).then( ( saved ) => {
				put( saved );
				// The schedule can learn that a run ended before the next job poll
				// does. Fetch that job, so its card and message agree at once.
				const run = saved.last_run || {};
				if ( run.job_id && id === following.get( run.job_id ) && 'running' !== run.status ) {
					fetchJob( run.job_id, id );
				}
			} ).catch( ( err ) => {
				if ( 'opex_missing' === err.code ) {
					store.set( 'schedules', ( list() || [] ).filter( ( s ) => s.id !== id ) );
				}
			} );
		}

		// Called on every job list change: move progress bars, and refresh a
		// schedule when a run it started has ended.
		function onJobs() {
			const all = store.get( 'jobs' ) || [];
			all.forEach( ( job ) => {
				if ( job.schedule_id && jobs.isActive( job ) && ! following.has( job.id ) ) {
					following.set( job.id, job.schedule_id );
				}
			} );
			following.forEach( ( sid, jobId ) => {
				const job = all.find( ( item ) => item.id === jobId );
				if ( job && jobs.isActive( job ) ) {
					return;
				}
				if ( ! job ) {
					// A list answer that was on its way before the run started can
					// lack the job. Ask for the job itself instead of guessing.
					fetchJob( jobId, sid );
					return;
				}
				following.delete( jobId );
				const s = ( list() || [] ).find( ( item ) => item.id === sid );
				refreshOne( sid );
				if ( s && 'done' === job.status ) {
					/* translators: 1: schedule name, 2: "1,240 rows". */
					say( 'success', sprintf( __( 'The run of %1$s finished with %2$s.', 'open-export' ), s.name, app.rows( job.processed ) ) );
				} else if ( s && 'error' === job.status ) {
					/* translators: 1: schedule name, 2: the error the server recorded. */
					say( 'error', sprintf( __( 'The run of %1$s failed: %2$s', 'open-export' ), s.name, job.error || '' ) );
				}
			} );
			cards.forEach( ( card ) => card.sync() );
		}

		const checking = new Set();
		function fetchJob( jobId, sid ) {
			if ( checking.has( jobId ) ) {
				return;
			}
			checking.add( jobId );
			api.get( '/jobs/' + encodeURIComponent( jobId ) ).then( ( job ) => {
				checking.delete( jobId );
				jobs.upsert( job );
			} ).catch( ( err ) => {
				checking.delete( jobId );
				if ( 'opex_missing' === err.code ) {
					// Deleted from History while it ran.
					following.delete( jobId );
					refreshOne( sid );
				}
			} );
		}

		async function duplicate( s ) {
			const copyName = sprintf(
				/* translators: %s: name of the schedule that was copied. */
				__( '%s copy', 'open-export' ),
				s.name
			).slice( 0, 100 );
			const d = s.delivery || {};
			const delivery = JSON.parse( JSON.stringify( d ) );
			const lostSecret = !! ( d.webhook && d.webhook.has_secret );
			if ( delivery.webhook ) {
				delete delivery.webhook.has_secret;
			}
			try {
				const saved = await api.post( '/schedules', {
					name: copyName,
					config: s.config,
					frequency: s.frequency,
					time: s.time,
					weekday: s.weekday,
					monthday: s.monthday,
					// A copy starts off, so two identical runs never fire at once.
					enabled: false,
					incremental: s.incremental,
					filename: s.filename,
					delivery,
				} );
				const notes = Array.isArray( saved.notes ) ? saved.notes : [];
				delete saved.notes;
				put( saved );
				if ( lostSecret ) {
					notes.push( __( 'The webhook secret is never shown, so it was not copied. Enter it on the copy.', 'open-export' ) );
				}
				/* translators: %s: name of the new schedule. */
				say( notes.length ? 'warning' : 'success', sprintf( __( 'Made %s. It starts switched off.', 'open-export' ), saved.name ), notes );
				focusCard( saved.id, 'edit' );
			} catch ( err ) {
				fail( err );
			}
		}

		return { toggle, run, duplicate, onJobs };
	};
}() );
