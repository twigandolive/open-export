/**
 * Open Export admin screen: History cell and detail builders.
 *
 * Pure builders for one job: labels, the progress cell and the expandable
 * detail (deliveries, notes, error). tab-history.js decides when to call
 * them, so a running job only touches the parts that changed.
 *
 * Exposes opexApp.historyView:
 *   statusKind( job )       running, paused, done or failed.
 *   statusBadge( job )      Icon plus label.
 *   progress( job )         Returns { root, update( job ) } for the progress cell.
 *   sizeText( job )         File size, or why there is none yet.
 *   startedBy( job )        Who or what started the job, with the schedule name.
 *   formatLabel( job )      Format label from /options.
 *   detail( job, ctx )      The detail panel. Pass ctx.onDeliver( job ) only
 *                           when the server has the deliver route.
 *   detailKey( job )        A string that changes when the detail would change.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, sprintf } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;

	// The server tries a delivery at most three times (class-opex-delivery.php).
	const maxAttempts = () => app.store.limit( 'delivery_attempts', 3 );
	// A background job with no batch for this long is restarted by the hourly maintenance task.
	const stallSeconds = () => app.store.limit( 'stall_seconds', 600 );

	function statusKind( job ) {
		return 'error' === job.status ? 'failed' : job.status;
	}

	function statusLabel( status ) {
		switch ( status ) {
			case 'running':
				return __( 'Running', 'open-export' );
			case 'paused':
				return __( 'Paused', 'open-export' );
			case 'done':
				return __( 'Done', 'open-export' );
			case 'error':
				return __( 'Failed', 'open-export' );
		}
		return String( status || '' );
	}

	function statusBadge( job ) {
		return ui.status( statusKind( job ), statusLabel( job.status ) );
	}

	function percent( job ) {
		if ( 'done' === job.status ) {
			return 100;
		}
		return job.total > 0 ? ( job.processed / job.total ) * 100 : 0;
	}

	function barKind( job ) {
		return { paused: 'paused', done: 'done', error: 'failed' }[ job.status ] || '';
	}

	function progressText( job ) {
		/* translators: 1: rows processed so far, 2: total rows. */
		return sprintf( __( '%1$s of %2$s', 'open-export' ), app.formatNumber( job.processed ), app.formatNumber( job.total ) );
	}

	/**
	 * The progress cell, updated in place so a running job never rebuilds
	 * the row.
	 *
	 * @param {Object} job Exposed job.
	 * @return {{root: HTMLElement, update: Function}} Cell content.
	 */
	function progress( job ) {
		/* translators: %s: export name. */
		const bar = ui.bar( percent( job ), barKind( job ), sprintf( __( '%s progress', 'open-export' ), job.label ) );
		const text = el( 'span', {}, progressText( job ) );
		const root = el( 'div', {}, [ bar, text ] );
		function update( next ) {
			const value = Math.round( percent( next ) );
			bar.className = 'opex-bar' + ( barKind( next ) ? ' opex-bar-' + barKind( next ) : '' );
			bar.firstChild.style.width = value + '%';
			bar.setAttribute( 'aria-valuenow', String( value ) );
			text.textContent = progressText( next );
		}
		return { root, update };
	}

	function sizeText( job ) {
		if ( 'done' === job.status ) {
			return app.formatSize( job.filesize );
		}
		if ( 'error' === job.status ) {
			return __( 'No file', 'open-export' );
		}
		return __( 'Pending', 'open-export' );
	}

	/**
	 * The schedule name comes from the job's schedule_name when the server
	 * sends it, otherwise from the schedules list.
	 *
	 * @param {Object} job Exposed job.
	 * @return {string} Name or "".
	 */
	function scheduleName( job ) {
		if ( job.schedule_name ) {
			return job.schedule_name;
		}
		const found = ( app.store.get( 'schedules' ) || [] ).find( ( item ) => item.id === job.schedule_id );
		return found ? found.name : '';
	}

	function startedBy( job ) {
		if ( job.schedule_id ) {
			const name = scheduleName( job );
			/* translators: %s: schedule name. */
			return name ? sprintf( __( 'Schedule: %s', 'open-export' ), name ) : __( 'Schedule', 'open-export' );
		}
		switch ( job.origin ) {
			case 'cron':
				return __( 'Schedule', 'open-export' );
			case 'trigger':
				return __( 'Trigger URL', 'open-export' );
			case 'cli':
				return __( 'Command line', 'open-export' );
		}
		return __( 'Manual', 'open-export' );
	}

	function formatLabel( job ) {
		const formats = ( app.store.get( 'options' ) || {} ).formats || {};
		const def = formats[ job.format ];
		return def && def.label ? def.label : String( job.format || '' ).toUpperCase();
	}

	const CHANNEL_ICONS = { email: 'mail', webhook: 'link', destination: 'server' };

	function channelLabel( channel ) {
		if ( 'email' === channel ) {
			return __( 'Email', 'open-export' );
		}
		if ( 'webhook' === channel ) {
			return __( 'Webhook', 'open-export' );
		}
		if ( 'destination' === channel ) {
			return __( 'Destination', 'open-export' );
		}
		return channel;
	}

	function deliveryStatus( item ) {
		if ( 'sent' === item.status ) {
			return ui.status( 'sent', __( 'Sent', 'open-export' ) );
		}
		if ( 'failed' === item.status ) {
			return ui.status( 'failed', __( 'Failed', 'open-export' ) );
		}
		return ui.status( 'waiting', __( 'Waiting', 'open-export' ) );
	}

	/**
	 * One delivery line from the server's status, attempts and message only.
	 * Channels are independent, so no line refers to another.
	 *
	 * @param {Object} item Delivery item.
	 * @return {string} Text.
	 */
	function deliveryText( item ) {
		const parts = [];
		if ( item.attempts > 0 ) {
			/* translators: 1: attempt number, 2: most attempts. */
			parts.push( sprintf( __( 'Attempt %1$d of %2$d.', 'open-export' ), item.attempts, maxAttempts() ) );
		}
		if ( item.message ) {
			parts.push( item.message );
		}
		if ( 'failed' === item.status ) {
			parts.push( item.attempts < maxAttempts() ? __( 'Open Export will try again.', 'open-export' ) : __( 'No more automatic tries.', 'open-export' ) );
		}
		if ( item.time > 0 ) {
			/* translators: %s: date and time. */
			parts.push( sprintf( __( 'Updated %s.', 'open-export' ), app.formatDate( item.time ) ) );
		}
		return parts.join( ' ' );
	}

	function isStalled( job ) {
		const beat = Number( job.heartbeat ) || 0;
		return 'background' === job.mode && 'running' === job.status && beat > 0 && Date.now() / 1000 - beat > stallSeconds();
	}

	// Offered for a finished job with a delivery still to send. The caller
	// passes onDeliver only when the server has POST /jobs/{id}/deliver.
	function canDeliverNow( job ) {
		return 'done' === job.status && ( job.deliveries || [] ).some( ( item ) => 'sent' !== item.status );
	}

	function detail( job, ctx ) {
		const deliveries = job.deliveries || [];
		const notes = job.notes || [];
		const top = [];
		if ( 'error' === job.status && job.error ) {
			top.push( ui.notice( 'error', job.error, { tight: true, role: 'note' } ) );
		}
		if ( isStalled( job ) ) {
			top.push( ui.notice(
				'warning',
				sprintf(
					/* translators: %d: minutes. */
					__( 'This background export has made no progress for %d minutes. The maintenance task restarts it within the hour.', 'open-export' ),
					Math.round( stallSeconds() / 60 )
				),
				{ tight: true, role: 'note' }
			) );
		}
		if ( job.finished > 0 ) {
			/* translators: %s: date and time. */
			top.push( el( 'p', { class: 'opex-help' }, sprintf( __( 'Finished %s.', 'open-export' ), app.formatDate( job.finished ) ) ) );
		}

		const deliveryBlock = el( 'div', {}, [
			el( 'p', { class: 'opex-h3' }, __( 'Delivery', 'open-export' ) ),
			deliveries.length
				? el( 'ul', { class: 'opex-lines' }, deliveries.map( ( item ) => el( 'li', {}, [
					el( 'span', { class: 'opex-line-name' }, [ app.icon( CHANNEL_ICONS[ item.channel ] || 'info', { sm: true } ), channelLabel( item.channel ) ] ),
					deliveryStatus( item ),
					el( 'span', {}, deliveryText( item ) ),
				] ) ) )
				: el( 'p', { class: 'opex-help' }, __( 'No delivery was set up for this export.', 'open-export' ) ),
			ctx && ctx.onDeliver && canDeliverNow( job )
				? ui.button( { label: __( 'Send again now', 'open-export' ), small: true, action: 'deliver', cls: 'opex-mt', onClick: () => ctx.onDeliver( job ) } )
				: null,
		] );

		const notesBlock = el( 'div', {}, [
			el( 'p', { class: 'opex-h3' }, __( 'Notes', 'open-export' ) ),
			notes.length
				? el( 'ul', { class: 'opex-notes' }, notes.map( ( note ) => el( 'li', {}, [
					app.icon( 'info', { sm: true, cls: 'opex-c-info' } ),
					el( 'span', {}, note ),
				] ) ) )
				: el( 'p', { class: 'opex-help' }, __( 'No notes for this export.', 'open-export' ) ),
		] );

		return el( 'div', {}, [ top, el( 'div', { class: 'opex-detail-grid' }, [ deliveryBlock, notesBlock ] ) ] );
	}

	function detailKey( job, extra ) {
		return JSON.stringify( [ job.status, job.error, job.deliveries, job.notes, job.finished || 0, isStalled( job ), extra || '' ] );
	}

	app.historyView = { statusKind, statusBadge, statusLabel, progress, sizeText, startedBy, formatLabel, detail, detailKey };
}() );
