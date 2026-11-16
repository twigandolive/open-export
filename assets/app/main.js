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
 * Each tab is a file that calls opexApp.registerTab( id, { mount } ).
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

	/**
	 * Reads the tab id from the URL hash.
	 *
	 * @return {string|null} The tab id, or null when the hash names no tab.
	 */
	function fromHash() {
		const id = String( window.location.hash || '' ).replace( /^#/, '' );
		return ids.indexOf( id ) > -1 ? id : null;
	}

	/**
	 * "Open Export by Twig & Olive", with the maker's name as a link. The
	 * sentence is translated whole and split at the placeholder, so no HTML
	 * comes from the translation.
	 *
	 * @return {HTMLElement} Footer line.
	 */
	function footer() {
		/* translators: %s: the maker's name, Twig & Olive, shown as a link. */
		const parts = __( 'Open Export by %s', 'open-export' ).split( '%s' );
		const link = el( 'a', { href: 'https://twigandolive.com', target: '_blank', rel: 'noopener noreferrer' }, [
			'Twig & Olive',
			el( 'span', { class: 'opex-sr' }, ' ' + __( '(opens in a new tab)', 'open-export' ) ),
		] );
		return el( 'p', { class: 'opex-footer' }, [ parts[ 0 ] || '', link, parts.slice( 1 ).join( '' ) ] );
	}

	/**
	 * Builds the shell, loads /options, then opens the tab the URL names, or Export.
	 */
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
		wrap.appendChild( footer() );

		// Shows a tab, building it the first time it opens.
		function open( id ) {
			if ( active && mounted[ active ] && mounted[ active ].hide ) {
				mounted[ active ].hide();
			}
			active = id;
			if ( ! mounted[ id ] ) {
				const module = app.tabModules[ id ];
				const panel = tabs.panels[ id ];
				mounted[ id ] = module && module.mount ? module.mount( panel ) || {} : {};
			}
			if ( mounted[ id ].show ) {
				mounted[ id ].show();
			}
			if ( fromHash() !== id && window.history && window.history.replaceState ) {
				window.history.replaceState( null, '', '#' + id );
			}
		}

		/**
		 * Opens a tab, for parts that send people to another tab.
		 *
		 * @param {string}  id    Tab id.
		 * @param {boolean} focus True to move focus to the tab.
		 */
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

		// Reads the options from the server, then builds the tabs.
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
