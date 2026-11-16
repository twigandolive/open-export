/**
 * Open Export admin screen: shared pieces of the Schedules tab.
 *
 * Pure builders and text functions for one schedule, used by the list cards
 * (schedule-card.js) and the add and edit form (schedule-form.js).
 *
 * Exposes opexApp.scheduleParts:
 *   max()                  The most schedules the server keeps (/options limits.schedules).
 *   TOKENS                 File name tokens the server knows.
 *   timetable( s )         "Daily at 03:00", "Weekly on Monday at 07:00".
 *   nextRun( s )           Next run in the site time zone, or why there is none.
 *   watermarkTime( mark )  The stored UTC "Y-m-d H:i:s" mark as a timestamp, or 0.
 *   lastRun( s, onView )   The last run cell: status, time, rows and a View in History link.
 *   deliveryList( d )      Icon plus label for every channel that is on.
 *   sampleName( pattern, ctx )  A live sample of the file name the server would make.
 *   activeJob( id )        The running or paused job of a schedule, or null.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, _n, sprintf } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;
	const store = app.store;

	const TOKENS = [ 'label', 'source', 'date', 'time', 'datetime', 'id', 'site' ];

	/**
	 * Gives the /options answer from the store.
	 */
	function options() {
		return store.get( 'options' ) || {};
	}

	/**
	 * Gives the label of a frequency, or its id when it is unknown.
	 */
	function frequencyLabel( id ) {
		const found = ( options().frequencies || [] ).find( ( item ) => item.id === id );
		return found ? found.label : String( id || '' );
	}

	/**
	 * Gives the name of a weekday number, or the number when it is unknown.
	 */
	function weekdayLabel( value ) {
		const found = ( options().weekdays || [] ).find( ( item ) => Number( item.value ) === Number( value ) );
		return found ? found.label : String( value );
	}

	/**
	 * A wall-clock time moved by whole hours, wrapping past midnight.
	 *
	 * @param {string} time  'HH:MM'.
	 * @param {number} hours Hours to add.
	 * @return {string} 'HH:MM'.
	 */
	function shiftHours( time, hours ) {
		const parts = time.split( ':' );
		const hour = ( ( parseInt( parts[ 0 ], 10 ) || 0 ) + hours ) % 24;
		return ( hour < 10 ? '0' : '' ) + hour + ':' + ( parts[ 1 ] || '00' );
	}

	/**
	 * The timetable in words. Times are the stored wall-clock time in the
	 * site time zone, the same as the form shows them.
	 *
	 * @param {Object} s Exposed schedule.
	 * @return {string} Words.
	 */
	function timetable( s ) {
		const time = String( s.time || '03:00' );
		switch ( s.frequency ) {
			case 'hourly': {
				const minute = parseInt( time.split( ':' )[ 1 ], 10 ) || 0;
				if ( ! minute ) {
					return __( 'Hourly, on the hour', 'open-export' );
				}
				/* translators: %d: minutes past the hour. */
				return sprintf( _n( 'Hourly, %d minute past the hour', 'Hourly, %d minutes past the hour', minute, 'open-export' ), minute );
			}
			case 'every_6_hours':
				/* translators: %s: time of day of the first run, such as 03:00. */
				return sprintf( __( 'Every 6 hours from %s', 'open-export' ), time );
			case 'twicedaily': {
				const times = [ time, shiftHours( time, 12 ) ].sort();
				/* translators: 1: earlier time of day, such as 03:00, 2: later time of day, such as 15:00. */
				return sprintf( __( 'Twice a day at %1$s and %2$s', 'open-export' ), times[ 0 ], times[ 1 ] );
			}
			case 'daily':
				/* translators: %s: time of day, such as 03:00. */
				return sprintf( __( 'Daily at %s', 'open-export' ), time );
			case 'weekly':
				/* translators: 1: day of the week, 2: time of day. */
				return sprintf( __( 'Weekly on %1$s at %2$s', 'open-export' ), weekdayLabel( s.weekday ), time );
			case 'monthly':
				if ( 'last' === s.monthday ) {
					/* translators: %s: time of day. */
					return sprintf( __( 'Monthly on the last day at %s', 'open-export' ), time );
				}
				/* translators: 1: day of the month, 1 to 28, 2: time of day. */
				return sprintf( __( 'Monthly on day %1$s at %2$s', 'open-export' ), app.formatNumber( s.monthday ), time );
		}
		return frequencyLabel( s.frequency );
	}

	/**
	 * Says when a schedule runs next, or why it does not run.
	 */
	function nextRun( s ) {
		if ( 'manual' === s.frequency ) {
			return __( 'Not scheduled, runs only when started', 'open-export' );
		}
		if ( ! s.enabled ) {
			return __( 'Not scheduled while off', 'open-export' );
		}
		return Number( s.next_run ) > 0 ? app.formatDate( s.next_run ) : __( 'Not scheduled', 'open-export' );
	}

	/**
	 * Turns a stored watermark, written in UTC, into a timestamp.
	 *
	 * @return {number} Seconds since the epoch, or 0 when the text is not a date.
	 */
	function watermarkTime( mark ) {
		const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/.exec( String( mark || '' ) );
		if ( ! m ) {
			return 0;
		}
		return Math.floor( Date.UTC( +m[ 1 ], +m[ 2 ] - 1, +m[ 3 ], +m[ 4 ], +m[ 5 ], +m[ 6 ] ) / 1000 );
	}

	/**
	 * The last run: status badge, then time and rows, then a link that opens
	 * the job in History.
	 *
	 * @param {Object}   s      Exposed schedule.
	 * @param {Function} onView Called with the job id.
	 * @return {Array} Nodes.
	 */
	function lastRun( s, onView ) {
		const run = s.last_run || {};
		if ( ! Number( run.time ) ) {
			return [ __( 'Not run yet', 'open-export' ) ];
		}
		return [
			app.historyView.statusBadge( { status: run.status } ),
			el( 'br', {} ),
			/* translators: 1: date and time, 2: "1,240 rows". */
			sprintf( __( '%1$s, %2$s', 'open-export' ), app.formatDate( run.time ), app.rows( run.rows ) ),
			run.job_id && onView ? el( 'br', {} ) : null,
			run.job_id && onView ? ui.linkButton( {
				label: __( 'View in History', 'open-export' ),
				action: 'view-job',
				/* translators: %s: schedule name. */
				ariaLabel: sprintf( __( 'View the last run of %s in History', 'open-export' ), s.name ),
				onClick: () => onView( run.job_id ),
			} ) : null,
		];
	}

	/**
	 * Builds the list of delivery channels a schedule has on.
	 */
	function deliveryList( delivery ) {
		const d = delivery || {};
		const on = [
			[ 'email', 'mail', __( 'Email', 'open-export' ) ],
			[ 'webhook', 'link', __( 'Webhook', 'open-export' ) ],
			[ 'destination', 'server', __( 'Destination', 'open-export' ) ],
		].filter( ( item ) => d[ item[ 0 ] ] && true === d[ item[ 0 ] ].enabled );
		if ( ! on.length ) {
			return [ __( 'None, the file stays in History', 'open-export' ) ];
		}
		return on.map( ( item ) => el( 'span', {}, [ app.icon( item[ 1 ], { sm: true } ), item[ 2 ] ] ) );
	}

	/**
	 * Adds a leading zero to a number below 10.
	 */
	function pad( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}

	// Date parts in the site time zone, through wp.date when it is there.
	function siteParts() {
		const date = window.wp.date;
		if ( date && date.date ) {
			return { date: date.date( 'Y-m-d' ), time: date.date( 'His' ) };
		}
		const now = new Date();
		return {
			date: now.getFullYear() + '-' + pad( now.getMonth() + 1 ) + '-' + pad( now.getDate() ),
			time: pad( now.getHours() ) + pad( now.getMinutes() ) + pad( now.getSeconds() ),
		};
	}

	/**
	 * What OPEX_Filename::render would make of a pattern now, plus the
	 * format's extension. Close enough for a sample: the server has the
	 * final say, and the sample says so.
	 *
	 * @param {string} pattern Pattern with tokens.
	 * @param {Object} ctx     label, source, id, extension.
	 * @return {string} File name.
	 */
	function sampleName( pattern, ctx ) {
		const parts = siteParts();
		const map = {
			label: ctx.label || '',
			// The server turns the colon in posts:post into a hyphen, so the sample does too.
			source: String( ctx.source || '' ).replace( /:/g, '-' ),
			id: ctx.id || '',
			site: window.location.hostname,
			date: parts.date,
			time: parts.time,
			datetime: parts.date + '-' + parts.time,
		};
		let name = String( pattern || '' ).replace( /\{([A-Za-z_]+)\}/g, ( all, key ) => map[ key.toLowerCase() ] || '' );
		name = name.replace( /[/\\.\0]/g, '-' );
		// The parts of sanitize_file_name that change a typical name.
		name = name.replace( /[?[\]=<>:;,'"&$#*()|~`!{}%+’«»”“]/g, '' );
		name = name.replace( /[\r\n\t -]+/g, '-' );
		name = name.replace( /^[\s\-_]+|[\s\-_]+$/g, '' );
		if ( name.length > 120 ) {
			name = name.slice( 0, 120 ).replace( /[\s\-_]+$/g, '' );
		}
		return ( name || 'export' ) + ( ctx.extension ? '.' + ctx.extension : '' );
	}

	/**
	 * Finds the running or paused job of a schedule.
	 *
	 * @return {Object|null} The job, or null when there is none.
	 */
	function activeJob( id ) {
		return ( store.get( 'jobs' ) || [] ).find( ( job ) => job.schedule_id === id && app.jobs.isActive( job ) ) || null;
	}

	app.scheduleParts = {
		max: () => store.limit( 'schedules', 50 ),
		TOKENS,
		timetable,
		nextRun,
		watermarkTime,
		lastRun,
		deliveryList,
		sampleName,
		activeJob,
	};
}() );
