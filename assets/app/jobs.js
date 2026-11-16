/**
 * Open Export admin screen: shared job actions.
 *
 * The job list lives in the store under "jobs", newest first. History reads
 * it now, and the Export tab will start and drive jobs through the same
 * functions, so a job looks the same in both places.
 *
 * A browser-mode job only moves while someone calls run. This screen calls
 * run in a loop only for jobs it started or resumed itself (drive). Jobs
 * from other windows, cron or the command line are watched by polling.
 *
 * Exposes opexApp.jobs:
 *   load()               GET /jobs into the store. Resolves with the list.
 *   upsert( job )        Puts one exposed job into the list.
 *   forget( id )         Drops a job from the list without a request.
 *   isActive( job )      True for running and paused jobs.
 *   isDriving( id )      True while this screen runs the job's batches.
 *   drive( id, resume )  Runs batches until the job stops. resume=true only from a Resume button.
 *   pause( id )          Pauses and stops driving.
 *   resume( job )        Resume button: drives browser jobs, nudges background jobs once.
 *   rerun( id )          Starts a new job from an old one and drives it if it runs in the browser.
 *   remove( id )         Deletes a job and its file.
 *   startPolling()       Polls every 5 seconds while any job is running or paused, or has
 *                        deliveries left to try. Returns stop().
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const api = app.api;
	const store = app.store;
	const POLL_MS = 5000;
	// Job id to the token of the drive call that owns it. Pause and Resume can
	// start a new drive while the old one still waits on a request, so each
	// call checks that it still owns the job before it acts.
	const driving = new Map();

	/**
	 * Gives the job list from the store.
	 */
	function list() {
		return store.get( 'jobs' ) || [];
	}

	/**
	 * Gives a copy of the jobs, newest first.
	 */
	function sorted( jobs ) {
		return jobs.slice().sort( ( a, b ) => ( b.created || 0 ) - ( a.created || 0 ) );
	}

	/**
	 * Tells whether a job is running or paused.
	 */
	function isActive( job ) {
		return !! job && ( 'running' === job.status || 'paused' === job.status );
	}

	/**
	 * Puts one job into the store, replacing its old copy and keeping the newest first.
	 *
	 * @return {Object} The same job.
	 */
	function upsert( job ) {
		if ( ! job || ! job.id ) {
			return job;
		}
		const jobs = list().filter( ( item ) => item.id !== job.id );
		jobs.push( job );
		store.set( 'jobs', sorted( jobs ) );
		return job;
	}

	/**
	 * Removes a job from the store and stops driving it.
	 */
	function forget( id ) {
		driving.delete( id );
		store.set( 'jobs', list().filter( ( item ) => item.id !== id ) );
	}

	/**
	 * Loads the list. Jobs this screen is driving keep their local copy,
	 * because a poll answer can be older than the last run answer.
	 *
	 * @return {Promise<Array>} Jobs.
	 */
	function load() {
		return api.get( '/jobs' ).then( ( fresh ) => {
			const local = {};
			list().forEach( ( job ) => {
				local[ job.id ] = job;
			} );
			const merged = ( Array.isArray( fresh ) ? fresh : [] ).map( ( job ) => ( driving.has( job.id ) && local[ job.id ] ? local[ job.id ] : job ) );
			return store.set( 'jobs', sorted( merged ) );
		} );
	}

	/**
	 * Builds the route of a job, or of one of its actions.
	 */
	function path( id, action ) {
		return '/jobs/' + encodeURIComponent( id ) + ( action ? '/' + action : '' );
	}

	/**
	 * Tells the store which jobs this screen is driving.
	 */
	function announce() {
		store.set( 'driving', Array.from( driving.keys() ) );
	}

	/**
	 * Calls run until the job is no longer running. The id is held locally,
	 * so deleting the job mid-run cannot break the loop.
	 *
	 * Each call gets its own token. After Pause and Resume a newer call owns
	 * the job, and an older call that is still waiting on a request then
	 * stops quietly: it does not apply its answer, run another batch, report
	 * its error or clear the newer call's place. After a plain Pause (no
	 * newer call) it behaves as before: no further batch.
	 *
	 * @param {string}  id     Job id.
	 * @param {boolean} resume True only for the Resume button.
	 * @return {Promise<Object|null>} The last job state, or null when another call drives the job.
	 */
	async function drive( id, resume ) {
		if ( driving.has( id ) ) {
			return null;
		}
		const token = {};
		const mine = () => driving.get( id ) === token;
		const superseded = () => driving.has( id ) && ! mine();
		driving.set( id, token );
		announce();
		let job = null;
		try {
			job = await api.post( path( id, 'run' ), resume ? { resume: true } : {} );
			if ( superseded() ) {
				return null;
			}
			upsert( job );
			while ( job && 'running' === job.status && mine() ) {
				job = await api.post( path( id, 'run' ) );
				if ( ! mine() ) {
					break;
				}
				upsert( job );
			}
			return job;
		} catch ( err ) {
			if ( superseded() ) {
				return null;
			}
			if ( 'opex_missing' === err.code ) {
				forget( id );
			} else if ( ! err.session ) {
				// The server records batch errors on the job itself. Fetch it so
				// the row shows the real state, then report the error.
				api.get( path( id ) ).then( upsert ).catch( () => {} );
			}
			throw err;
		} finally {
			// Pause, forget and remove drop the id without telling the store,
			// so a call that ends with no owner left still tells it. A newer
			// call's place is left alone.
			if ( mine() ) {
				driving.delete( id );
			}
			if ( ! driving.has( id ) ) {
				announce();
			}
		}
	}

	/**
	 * Pauses a job and stops driving it from this window.
	 *
	 * @return {Promise} Resolves with the paused job.
	 */
	function pause( id ) {
		driving.delete( id );
		return api.post( path( id, 'pause' ) ).then( upsert );
	}

	/**
	 * The Resume button. A browser job is driven from here. A background job
	 * gets one run call with resume, and the poll shows how it goes.
	 *
	 * @param {Object} job Exposed job.
	 * @return {Promise<Object|null>} Last job state.
	 */
	function resume( job ) {
		if ( 'background' === job.mode ) {
			return api.post( path( job.id, 'run' ), { resume: true } ).then( upsert );
		}
		return drive( job.id, true );
	}

	/**
	 * Starts a new job with the same config as an old one.
	 *
	 * @return {Promise} Resolves with the new job.
	 */
	function rerun( id ) {
		return api.post( path( id, 'rerun' ) ).then( ( job ) => {
			upsert( job );
			return job;
		} );
	}

	/**
	 * Deletes a job and its file, then removes it from the store.
	 */
	function remove( id ) {
		driving.delete( id );
		return api.del( path( id ) ).then( () => forget( id ) );
	}

	/**
	 * Tells whether a finished job still has a delivery the server will try:
	 * waiting, or failed with tries left. This matches the server's own test
	 * (OPEX_Runner::has_pending), so polling ends when every delivery is sent
	 * or out of tries.
	 *
	 * @param {Object} job Exposed job.
	 * @return {boolean} True while a delivery result can still change.
	 */
	function deliveryOpen( job ) {
		const most = store.limit( 'delivery_attempts', 3 );
		return !! job && 'done' === job.status && ( job.deliveries || [] ).some( ( item ) => ( 'pending' === item.status || 'failed' === item.status ) && ( Number( item.attempts ) || 0 ) < most );
	}

	/**
	 * Polls the list while anything is running or paused that this screen is
	 * not already driving, or while a finished job still has deliveries to
	 * try. Stops by itself when none is left.
	 *
	 * @return {Function} stop().
	 */
	function startPolling() {
		let timer = null;
		let stopped = false;
		const needed = () => list().some( ( job ) => ( isActive( job ) && ! driving.has( job.id ) ) || deliveryOpen( job ) );
		const tick = () => {
			timer = null;
			if ( stopped || 'expired' === store.get( 'session' ) ) {
				return;
			}
			if ( ! needed() ) {
				return;
			}
			load().catch( () => {} ).then( schedule );
		};
		const schedule = () => {
			if ( ! stopped && ! timer && needed() ) {
				timer = window.setTimeout( tick, POLL_MS );
			}
		};
		// Start again whenever the list gains an active job.
		const unsubscribe = store.subscribe( 'jobs', schedule );
		schedule();
		return function stop() {
			stopped = true;
			unsubscribe();
			if ( timer ) {
				window.clearTimeout( timer );
			}
		};
	}

	app.jobs = {
		load,
		upsert,
		forget,
		isActive,
		isDriving: ( id ) => driving.has( id ),
		drive,
		pause,
		resume,
		rerun,
		remove,
		startPolling,
	};
}() );
