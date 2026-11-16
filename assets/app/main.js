/**
 * Open Export admin screen: boot.
 *
 * Loads GET /options once, builds the shell (title, five tabs, footer line)
 * and mounts each tab the first time it opens. The open tab is kept in the
 * URL hash, so a reload or a shared link opens the same tab.
 *
 * Exposes opexApp.showTab( id, focus ) for parts that send people to
 * another tab, for example an empty state's button.
 *
 * Tabs without a registered module (Export and Schedules in this stage)
 * show a short placeholder. A later stage only has to load a file that
 * calls opexApp.registerTab( 'export', { mount } ).
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __ } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;
	const store = app.store;

	const TABS = [
		{ id: 'export', label: __( 'Export', 'open-export' ) },
		{ id: 'history', label: __( 'History', 'open-export' ) },
		{ id: 'schedules', label: __( 'Schedules', 'open-export' ) },
		{ id: 'destinations', label: __( 'Destinations', 'open-export' ) },
		{ id: 'settings', label: __( 'Settings', 'open-export' ) },
	];
	const ids = TABS.map( ( tab ) => tab.id );

	function fromHash() {
		const id = String( window.location.hash || '' ).replace( /^#/, '' );
		return ids.indexOf( id ) > -1 ? id : null;
	}

	function placeholder() {
		const url = app.boot.adminUrl;
		return el( 'div', { class: 'opex-empty' }, [
			el( 'strong', {}, __( 'This tab arrives in the next stage.', 'open-export' ) ),
			el( 'p', {}, __( 'Until then, use the current screen for this task.', 'open-export' ) ),
			url ? el( 'a', { class: 'opex-btn', href: url }, __( 'Open the current screen', 'open-export' ) ) : null,
		] );
	}

	function start() {
		const root = document.getElementById( 'opex-app' );
		if ( ! root ) {
			return;
		}
		const wrap = root.parentNode;
		const mounted = {};
		let active = null;

		const session = el( 'div', {} );
		const top = el( 'div', {} );
		const stage = el( 'div', { class: 'opex-stage' } );
		const tabs = ui.tabs( { items: TABS, label: __( 'Open Export sections', 'open-export' ), onSelect: open } );

		wrap.insertBefore( el( 'h1', {}, __( 'Open Export', 'open-export' ) ), root );
		wrap.insertBefore( session, root );
		app.clear( root );
		app.append( root, [ tabs.list, top, stage ] );
		wrap.appendChild( el( 'p', { class: 'opex-footer' }, __( 'Open Export by Twig & Olive', 'open-export' ) ) );

		function open( id ) {
			if ( active && mounted[ active ] && mounted[ active ].hide ) {
				mounted[ active ].hide();
			}
			active = id;
			if ( ! mounted[ id ] ) {
				const module = app.tabModules[ id ];
				const panel = tabs.panels[ id ];
				if ( module && module.mount ) {
					mounted[ id ] = module.mount( panel ) || {};
				} else {
					panel.appendChild( placeholder() );
					mounted[ id ] = {};
				}
			}
			if ( mounted[ id ].show ) {
				mounted[ id ].show();
			}
			if ( fromHash() !== id && window.history && window.history.replaceState ) {
				window.history.replaceState( null, '', '#' + id );
			}
		}

		app.showTab = function ( id, focus ) {
			tabs.select( id, !! focus );
		};

		store.subscribe( 'session', ( value ) => {
			if ( 'expired' !== value || session.firstChild ) {
				return;
			}
			session.appendChild( ui.notice( 'error', __( 'Your session has expired. Reload the page.', 'open-export' ), {
				role: 'alert',
				action: { label: __( 'Reload page', 'open-export' ), onClick: () => window.location.reload() },
			} ) );
			root.inert = true;
			root.setAttribute( 'aria-hidden', 'true' );
		} );

		function boot() {
			app.clear( stage ).appendChild( ui.skeleton( 5 ) );
			tabs.list.hidden = true;
			app.api.get( '/options' ).then( ( options ) => {
				store.set( 'options', options || {} );
				const features = ( options && options.features ) || {};
				if ( features.cron_disabled ) {
					top.appendChild( ui.notice( 'warning', __( 'WP-Cron is turned off on this site. Scheduled and background exports need a real cron job.', 'open-export' ), { role: 'note' } ) );
				}
				app.clear( stage );
				TABS.forEach( ( tab ) => stage.appendChild( tabs.panels[ tab.id ] ) );
				tabs.list.hidden = false;
				tabs.select( fromHash() || 'export', false );
				window.addEventListener( 'hashchange', () => {
					const id = fromHash();
					if ( id ) {
						tabs.select( id, false );
					}
				} );
			} ).catch( ( err ) => {
				if ( ! err.session ) {
					app.clear( stage ).appendChild( ui.loadError( err, boot ) );
				} else {
					app.clear( stage );
				}
			} );
		}

		boot();
	}

	// Footer scripts run after the mount point exists. Fall back to
	// DOMContentLoaded in case the scripts move to the head.
	if ( document.getElementById( 'opex-app' ) ) {
		start();
	} else {
		document.addEventListener( 'DOMContentLoaded', start );
	}
}() );
