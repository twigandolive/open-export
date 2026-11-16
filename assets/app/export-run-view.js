/**
 * Open Export admin screen: the progress view of a job the Export tab started.
 *
 * Built once per job. update( job ) changes only the parts that moved: the
 * bar and the row count on every batch, and the status, actions, error,
 * notes and delivery lines only when they change. Focus stays on the
 * action the person used, or moves to its counterpart (Pause to Resume).
 *
 * Exposes opexApp.exportRunView( job, { pause(), resume() } ):
 *   Returns { root, update( job, driving ), say( message ) }.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, sprintf } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;
	const view = app.historyView;
	const COUNTERPART = { pause: 'resume', resume: 'pause' };

	/**
	 * Gives the name of a delivery channel.
	 */
	function channelLabel( channel ) {
		return { email: __( 'Email', 'open-export' ), webhook: __( 'Webhook', 'open-export' ), destination: __( 'Destination', 'open-export' ) }[ channel ] || channel;
	}

	/**
	 * Builds the status badge of one delivery.
	 */
	function deliveryBadge( item ) {
		const name = channelLabel( item.channel );
		if ( 'sent' === item.status ) {
			/* translators: %s: channel, for example Email. */
			return ui.status( 'sent', sprintf( __( '%s sent', 'open-export' ), name ) );
		}
		if ( 'failed' === item.status ) {
			/* translators: %s: channel, for example Email. */
			return ui.status( 'failed', sprintf( __( '%s failed', 'open-export' ), name ) );
		}
		/* translators: %s: channel, for example Email. */
		return ui.status( 'waiting', sprintf( __( '%s waiting', 'open-export' ), name ) );
	}

	/**
	 * Builds the progress view of one job and updates it in place as the job moves.
	 *
	 * @param {Object} job     Exposed job.
	 * @param {Object} actions pause() and resume().
	 * @return {Object} { root, update( job, driving ), say( message ) }.
	 */
	app.exportRunView = function ( job, actions ) {
		const name = el( 'span', { class: 'opex-run-name' }, job.label );
		const status = el( 'span', {} );
		const progress = view.progress( job );
		const count = el( 'span', { 'aria-live': 'polite' } );
		const buttons = el( 'span', { class: 'opex-run-actions' } );
		const problem = el( 'div', {} );
		const message = el( 'div', { 'aria-live': 'polite' } );
		const deliveries = el( 'p', { class: 'opex-inline opex-m0' } );
		const notes = el( 'div', {} );
		const help = el( 'p', { class: 'opex-help opex-m0' } );
		const root = el( 'div', { class: 'opex-run-view' }, [
			el( 'div', { class: 'opex-run-top' }, [ name, status ] ),
			progress.root.firstChild,
			el( 'div', { class: 'opex-run-actions' }, [ count, buttons ] ),
			problem,
			message,
			deliveries,
			notes,
			help,
		] );
		const keys = {};

		// Tells whether a part of the view changed since it was last drawn.
		function changedKey( part, value ) {
			const key = JSON.stringify( value );
			if ( keys[ part ] === key ) {
				return false;
			}
			keys[ part ] = key;
			return true;
		}

		// Builds the buttons that fit the export's status.
		function buildButtons( next, driving ) {
			const list = [];
			if ( 'done' === next.status ) {
				list.push( el( 'a', { class: 'opex-btn opex-btn-primary', href: app.api.downloadUrl( next.id ), 'data-action': 'download' }, [ app.icon( 'download', { sm: true } ), __( 'Download', 'open-export' ) ] ) );
			}
			// Pause stays while the job runs, even between "stop driving" and the
			// paused answer, so focus never falls off the button.
			if ( 'running' === next.status ) {
				list.push( ui.button( { label: __( 'Pause', 'open-export' ), icon: 'pause', action: 'pause', onClick: actions.pause } ) );
			}
			if ( 'paused' === next.status || 'error' === next.status ) {
				list.push( ui.button( { label: __( 'Resume', 'open-export' ), icon: 'play', primary: true, action: 'resume', onClick: actions.resume } ) );
			}
			if ( 'running' === next.status && ! driving && 'browser' === next.mode ) {
				list.push( el( 'span', { class: 'opex-help' }, __( 'Another window is running this export.', 'open-export' ) ) );
			}
			return list;
		}

		// Rebuild a part, keeping focus on the same action or its counterpart.
		function rebuild( box, nodes ) {
			const active = document.activeElement;
			const action = active && box.contains( active ) ? active.getAttribute( 'data-action' ) : null;
			app.clear( box );
			app.append( box, nodes );
			if ( action ) {
				const again = box.querySelector( '[data-action="' + action + '"]' ) || box.querySelector( '[data-action="' + ( COUNTERPART[ action ] || '' ) + '"]' ) || box.querySelector( '[data-action]' );
				if ( again ) {
					again.focus();
				}
			}
		}

		// Updates the view for the export's latest state.
		function update( next, driving ) {
			progress.update( next );
			let text = sprintf(
				/* translators: 1: rows done, 2: total rows. */
				__( '%1$s of %2$s rows', 'open-export' ),
				app.formatNumber( next.processed ),
				app.formatNumber( next.total )
			);
			if ( 'done' === next.status ) {
				text += ', ' + app.formatSize( next.filesize );
			}
			if ( count.textContent !== text ) {
				count.textContent = text;
			}
			if ( changedKey( 'status', next.status ) ) {
				app.clear( status ).appendChild( view.statusBadge( next ) );
			}
			if ( changedKey( 'buttons', [ next.status, !! driving, next.mode ] ) ) {
				rebuild( buttons, buildButtons( next, driving ) );
			}
			if ( changedKey( 'problem', [ next.status, next.error ] ) ) {
				app.clear( problem );
				if ( 'error' === next.status ) {
					problem.appendChild( ui.notice( 'error', next.error || __( 'The export stopped. Your progress is saved, so you can resume it.', 'open-export' ), { tight: true, role: 'alert' } ) );
				}
			}
			if ( changedKey( 'deliveries', next.deliveries || [] ) ) {
				app.clear( deliveries );
				app.append( deliveries, ( next.deliveries || [] ).map( deliveryBadge ) );
				deliveries.hidden = ! ( next.deliveries || [] ).length;
			}
			if ( changedKey( 'notes', next.notes || [] ) ) {
				app.clear( notes );
				if ( ( next.notes || [] ).length ) {
					notes.appendChild( el( 'ul', { class: 'opex-notes', 'aria-label': __( 'Export notes', 'open-export' ) }, next.notes.map( ( note ) => el( 'li', {}, [
						app.icon( 'info', { sm: true, cls: 'opex-c-info' } ),
						el( 'span', {}, String( note ) ),
					] ) ) ) );
				}
			}
			const hint = 'background' === next.mode && app.historyView && 'running' === next.status
				? __( 'This export runs in the background. You can leave this page. History shows it too.', 'open-export' )
				: ( 'running' === next.status && driving ? __( 'Keep this page open until the export is done. You can keep editing the setup meanwhile.', 'open-export' ) : '' );
			if ( help.textContent !== hint ) {
				help.textContent = hint;
			}
			if ( 'done' === next.status && changedKey( 'done', true ) ) {
				/* translators: %s: export name. */
				say( sprintf( __( '%s is done.', 'open-export' ), next.label ), 'success' );
			}
		}

		// Shows a message in the progress view.
		function say( text, type ) {
			app.clear( message );
			if ( text ) {
				message.appendChild( ui.notice( type || 'error', text, { tight: true, role: 'error' === ( type || 'error' ) ? 'alert' : 'status' } ) );
			}
		}

		update( job, false );
		return { root, update, say };
	};
}() );
