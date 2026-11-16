/**
 * Open Export admin screen: the Settings tab.
 *
 * Four saved settings (GET and POST /settings) and a read-only list of what
 * this server can do, from the /options features flags.
 *
 * Registers the tab "settings".
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, sprintf } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;
	const api = app.api;
	const store = app.store;

	// Ranges match the REST arguments in class-opex-rest-delivery.php.
	const RANGES = { retention_days: [ 1, 365 ], link_days: [ 1, 30 ] };

	/**
	 * Builds the Settings tab: the saved settings and what this server can do.
	 *
	 * @param {Element} panel Tab panel.
	 */
	function mount( panel ) {
		const settingsCard = ui.card( { title: __( 'Exports', 'open-export' ) } );
		const serverCard = ui.card( { title: __( 'This server', 'open-export' ) } );
		app.append( panel, [ settingsCard.root, serverCard.root ] );
		serverCard.body.appendChild( serverList( ( store.get( 'options' ) || {} ).features || {} ) );

		function load() {
			app.clear( settingsCard.body ).appendChild( ui.skeleton( 3 ) );
			api.get( '/settings' ).then( ( saved ) => {
				store.set( 'settings', saved );
				app.clear( settingsCard.body ).appendChild( form( saved ) );
			} ).catch( ( err ) => {
				app.clear( settingsCard.body ).appendChild( ui.loadError( err, load ) );
			} );
		}

		load();
	}

	/**
	 * Builds a number input limited to the range the server allows for a setting.
	 */
	function numberInput( value, key ) {
		return el( 'input', { type: 'number', class: 'opex-w-num', min: RANGES[ key ][ 0 ], max: RANGES[ key ][ 1 ], step: 1, value: String( value ) } );
	}

	/**
	 * Builds the settings form from the saved values and saves it.
	 *
	 * @param {Object} saved Settings as the server sent them.
	 */
	function form( saved ) {
		const keep = numberInput( saved.retention_days, 'retention_days' );
		const links = numberInput( saved.link_days, 'link_days' );
		const background = ui.switchControl( { label: __( 'Run exports in the background by default', 'open-export' ), checked: !! saved.background } );
		const keepData = el( 'input', { type: 'checkbox', checked: !! saved.keep_data_on_uninstall } );
		const result = ui.result();
		const save = ui.button( { label: __( 'Save changes', 'open-export' ), primary: true, type: 'submit' } );

		const rows = {
			retention_days: ui.field( {
				label: __( 'Keep finished exports', 'open-export' ),
				control: el( 'div', { class: 'opex-inline' }, [ keep, el( 'span', {}, __( 'days', 'open-export' ) ) ] ),
				input: keep,
				help: __( 'From 1 to 365 days. Older exports and their files are removed by the hourly cleanup.', 'open-export' ),
			} ),
			link_days: ui.field( {
				label: __( 'Download links work for', 'open-export' ),
				control: el( 'div', { class: 'opex-inline' }, [ links, el( 'span', {}, __( 'days', 'open-export' ) ) ] ),
				input: links,
				help: __( 'From 1 to 30 days. Applies to links sent by email.', 'open-export' ),
			} ),
			background: ui.field( {
				label: __( 'Background', 'open-export' ),
				control: background.root,
				input: background.input,
				rowLabel: true,
				help: __( 'Used when an export starts without choosing. Background exports keep running after you leave this page.', 'open-export' ),
			} ),
			keep_data_on_uninstall: ui.field( {
				label: __( 'When the plugin is deleted', 'open-export' ),
				control: el( 'label', { class: 'opex-checkline' }, [ keepData, __( 'Keep my templates, schedules, export history and files', 'open-export' ) ] ),
				input: keepData,
				rowLabel: true,
				help: __( 'Leave this off to remove everything Open Export saved when you delete the plugin. Turn it on if you plan to install it again and want to keep your setup. Scheduled runs stop either way.', 'open-export' ),
			} ),
		};

		// The row label names the group, so the help is tied to the box itself.
		keepData.setAttribute( 'aria-describedby', rows.keep_data_on_uninstall.help.id );

		function readNumber( input, key ) {
			const raw = input.value.trim();
			const n = Number( raw );
			const [ min, max ] = RANGES[ key ];
			if ( ! /^\d+$/.test( raw ) || n < min || n > max ) {
				/* translators: 1: smallest allowed number, 2: largest allowed number. */
				rows[ key ].setError( sprintf( __( 'Enter a whole number from %1$d to %2$d.', 'open-export' ), min, max ) );
				return null;
			}
			rows[ key ].setError( '' );
			return n;
		}

		async function submit( event ) {
			event.preventDefault();
			const data = {
				retention_days: readNumber( keep, 'retention_days' ),
				link_days: readNumber( links, 'link_days' ),
				background: background.input.checked,
				keep_data_on_uninstall: keepData.checked,
			};
			if ( null === data.retention_days || null === data.link_days ) {
				( null === data.retention_days ? keep : links ).focus();
				result.set( 'error', __( 'Check the highlighted fields.', 'open-export' ) );
				return;
			}
			save.disabled = true;
			result.set( 'busy', __( 'Saving', 'open-export' ) );
			try {
				const next = await api.post( '/settings', data );
				store.set( 'settings', next );
				keep.value = String( next.retention_days );
				links.value = String( next.link_days );
				background.input.checked = !! next.background;
				keepData.checked = !! next.keep_data_on_uninstall;
				// The Export tab reads its background default from /options.
				store.update( 'options', ( options ) => {
					const copy = Object.assign( {}, options );
					copy.features = Object.assign( {}, copy.features, { background: !! next.background } );
					return copy;
				} );
				result.set( 'success', __( 'Settings saved.', 'open-export' ) );
			} catch ( err ) {
				if ( err.session ) {
					result.clear();
				} else {
					Object.keys( err.params || {} ).forEach( ( key ) => rows[ key ] && rows[ key ].setError( String( err.params[ key ] ) ) );
					result.set( 'error', err.message );
				}
			} finally {
				save.disabled = false;
			}
		}

		return el( 'form', { novalidate: true, onSubmit: submit }, [
			rows.retention_days.root,
			rows.link_days.root,
			rows.background.root,
			rows.keep_data_on_uninstall.root,
			el( 'div', { class: 'opex-run-foot' }, [ save, result.root ] ),
		] );
	}

	/**
	 * One line per feature flag. Each says what the flag means for exports,
	 * with an icon and a text label so it never relies on color.
	 *
	 * @param {Object} f /options features.
	 * @return {HTMLElement} List.
	 */
	function serverList( f ) {
		const ok = __( 'Available', 'open-export' );
		const lines = [
			[ f.zip, __( 'Excel export (zip)', 'open-export' ), f.zip ? __( 'Available.', 'open-export' ) : __( 'Not available. The PHP zip extension is missing, so Excel files cannot be made.', 'open-export' ) ],
			[ f.ftp, __( 'FTP and FTPS', 'open-export' ), f.ftp ? __( 'Available.', 'open-export' ) : __( 'Not available. The PHP ftp extension is missing. SFTP still works.', 'open-export' ) ],
			[ f.sodium, __( 'Encrypted secrets', 'open-export' ), f.sodium ? __( 'Available. Passwords, keys and webhook secrets are stored encrypted.', 'open-export' ) : __( 'Not available. The PHP sodium extension is missing, so passwords and secrets cannot be saved.', 'open-export' ) ],
			[ ! f.cron_disabled, __( 'Scheduled runs (WP-Cron)', 'open-export' ), f.cron_disabled ? __( 'WP-Cron is turned off on this site. Scheduled and background exports need a real cron job.', 'open-export' ) : __( 'On. Scheduled and background exports run on their own.', 'open-export' ) ],
			[ null, __( 'WooCommerce', 'open-export' ), f.woocommerce ? __( 'Active.', 'open-export' ) : __( 'Not active. WooCommerce exports are not offered.', 'open-export' ) ],
			[ null, __( 'Multisite', 'open-export' ), f.multisite ? __( 'On. This site is part of a network.', 'open-export' ) : __( 'Off. This is a single site.', 'open-export' ) ],
		];
		return el( 'ul', { class: 'opex-status-list', 'aria-label': __( 'This server', 'open-export' ) }, lines.map( ( line ) => {
			const good = line[ 0 ];
			let icon = app.icon( 'info', { sm: true, cls: 'opex-c-info', label: __( 'Information', 'open-export' ) } );
			if ( true === good ) {
				icon = app.icon( 'check', { sm: true, cls: 'opex-c-success', label: ok } );
			} else if ( false === good ) {
				icon = app.icon( 'warning', { sm: true, cls: 'opex-c-warning', label: __( 'Warning', 'open-export' ) } );
			}
			return el( 'li', { class: false === good ? 'is-warning' : null }, [ icon, el( 'strong', {}, line[ 1 ] ), el( 'span', {}, line[ 2 ] ) ] );
		} ) );
	}

	app.registerTab( 'settings', { mount } );
}() );
