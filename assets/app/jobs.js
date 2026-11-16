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
	// Job id to the token of the drive call that is running its batches.
	const driving = new Map();
	// Job id to the token of the last action that claimed the job: a drive
	// call, Pause, or a background Resume. Pause and Resume can start a new
	// call while an old one still waits on a request, so an answer is put in
	// the list only by the call whose token is still here. A finished call
	// keeps its token until something else claims the job, so its own error
	// read still lands, but an older call can never claim it back.
	const owner = new Map();
	// Job id to the clock value of the list's copy. A poll answer never
	// replaces a copy written after the poll was sent.
	const written = new Map();
	// Job id to the clock value of its deletion. A poll sent before that
	// would bring the deleted job back, so its rows for the id are dropped.
	const gone = new Map();
	// Clock values of the polls still waiting for an answer.
	const asking = new Set();
	let clock = 0;

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
		// Marks the copy as newer than any poll already sent. A job written
		// again is no longer treated as deleted.
		written.set( job.id, ++clock );
		gone.delete( job.id );
		const jobs = list().filter( ( item ) => item.id !== job.id );
		jobs.push( job );
		store.set( 'jobs', sorted( jobs ) );
		return job;
	}

	/**
	 * Removes a job from the store and stops driving it. The deletion is
	 * remembered while an older poll is still out, so its answer cannot
	 * put the job back.
	 *
	 * @param {string} id Job id.
	 * @return {void}
	 */
	function forget( id ) {
		driving.delete( id );
		owner.delete( id );
		written.delete( id );
		gone.set( id, ++clock );
		prune();
		store.set( 'jobs', list().filter( ( item ) => item.id !== id ) );
	}

	/**
	 * Drops the deletions no poll still out was sent before. Only a poll
	 * sent before a deletion can bring that job back.
	 *
	 * @return {void}
	 */
	function prune() {
		const oldest = asking.size ? Math.min( ...asking ) : Infinity;
		gone.forEach( ( time, id ) => {
			if ( time < oldest ) {
				gone.delete( id );
			}
		} );
	}

	/**
	 * Gives a job a new owner token, so answers to anything sent for it
	 * before are no longer put in the list.
	 *
	 * @param {string} id Job id.
	 * @return {Object} The new token.
	 */
	function claim( id ) {
		const token = {};
		owner.set( id, token );
		return token;
	}

	/**
	 * Tells whether a token is still the job's owner.
	 *
	 * @param {string} id    Job id.
	 * @param {Object} token Token from claim().
	 * @return {boolean} True while no later action has claimed the job.
	 */
	function owns( id, token ) {
		return owner.get( id ) === token;
	}

	/**
	 * Loads the list. A job keeps its local copy while this screen drives
	 * it, or when that copy was written after this request was sent, because
	 * the answer can then be older than what the screen already shows. A job
	 * written after the request and missing from the answer is kept too, so
	 * a new job does not vanish until the next poll. A job deleted after
	 * this request was sent is left out of the answer.
	 *
	 * @return {Promise<Array>} Jobs.
	 */
	function load() {
		const asked = ++clock;
		asking.add( asked );
		const done = () => {
			asking.delete( asked );
			prune();
		};
		return api.get( '/jobs' ).then( ( fresh ) => {
			const local = {};
			list().forEach( ( job ) => {
				local[ job.id ] = job;
			} );
			const newer = ( id ) => ( written.get( id ) || 0 ) > asked;
			const deleted = ( id ) => ( gone.get( id ) || 0 ) > asked;
			const rows = ( Array.isArray( fresh ) ? fresh : [] ).filter( ( job ) => ! deleted( job.id ) );
			const seen = {};
			const merged = rows.map( ( job ) => {
				seen[ job.id ] = true;
				if ( local[ job.id ] && ( driving.has( job.id ) || newer( job.id ) ) ) {
					return local[ job.id ];
				}
				// The copy now shows the server as of this request.
				written.set( job.id, asked );
				return job;
			} );
			Object.keys( local ).forEach( ( id ) => {
				if ( ! seen[ id ] && newer( id ) ) {
					merged.push( local[ id ] );
				}
			} );
			done();
			return store.set( 'jobs', sorted( merged ) );
		}, ( err ) => {
			done();
			throw err;
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
	 * Each call claims the job with its own token. Pause, a newer call or a
	 * delete takes the token away, and from then on this call stops quietly:
	 * it does not apply its answer, run another batch, report its error or
	 * read the job again. That holds even after the newer call has finished.
	 *
	 * @param {string}  id     Job id.
	 * @param {boolean} resume True only for the Resume button.
	 * @return {Promise<Object|null>} The last job state, or null when the call lost the job.
	 */
	async function drive( id, resume ) {
		if ( driving.has( id ) ) {
			return null;
		}
		const token = claim( id );
		const mine = () => owns( id, token );
		driving.set( id, token );
		announce();
		let job = null;
		try {
			job = await api.post( path( id, 'run' ), resume ? { resume: true } : {} );
			if ( ! mine() ) {
				return null;
			}
			upsert( job );
			while ( job && 'running' === job.status ) {
				job = await api.post( path( id, 'run' ) );
				if ( ! mine() ) {
					return null;
				}
				upsert( job );
			}
			return job;
		} catch ( err ) {
			if ( ! mine() ) {
				return null;
			}
			if ( 'opex_missing' === err.code ) {
				forget( id );
			} else if ( ! err.session ) {
				// The server records batch errors on the job itself. Fetch it so
				// the row shows the real state, unless something newer claimed
				// the job meanwhile. Then report the error.
				api.get( path( id ) ).then( ( fresh ) => mine() && upsert( fresh ) ).catch( () => {} );
			}
			throw err;
		} finally {
			// Pause, forget and remove drop the id without telling the store,
			// so a call that ends with no driver left still tells it. A newer
			// call's place is left alone.
			if ( driving.get( id ) === token ) {
				driving.delete( id );
			}
			if ( ! driving.has( id ) ) {
				announce();
			}
		}
	}

	/**
	 * Sends one request that claims the job, and puts its answer in the list
	 * only while no later action has claimed the job.
	 *
	 * @param {string} id     Job id.
	 * @param {string} action Route action.
	 * @param {Object} data   Request body.
	 * @return {Promise<Object>} Resolves with the answer, applied or not.
	 */
	function claimAndPost( id, action, data ) {
		const token = claim( id );
		return api.post( path( id, action ), data ).then( ( job ) => {
			if ( owns( id, token ) ) {
				upsert( job );
			}
			return job;
		} );
	}

	/**
	 * Pauses a job and stops driving it from this window. Pause claims the
	 * job, so a run answer still on its way is not put in the list.
	 *
	 * @param {string} id Job id.
	 * @return {Promise} Resolves with the paused job.
	 */
	function pause( id ) {
		driving.delete( id );
		return claimAndPost( id, 'pause' );
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
			return claimAndPost( job.id, 'run', { resume: true } );
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
	 * Deletes a job and its file, then removes it from the store. The job
	 * loses its owner at once, so a run answer still on its way cannot put
	 * it back.
	 *
	 * @param {string} id Job id.
	 * @return {Promise} Resolves once the job is gone.
	 */
	function remove( id ) {
		driving.delete( id );
		owner.delete( id );
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
