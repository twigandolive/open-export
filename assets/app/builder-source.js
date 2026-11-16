/**
 * Open Export admin screen: builder card 1, the source picker.
 *
 * Reads GET /sources (groups of { id, label, icon, count }). Standard groups
 * show as tiles. The advanced tier (raw database tables) sits behind a
 * disclosure with a caution line. Writes config.source through
 * ctx.setSource, which also resets everything that points at fields.
 *
 * The "All sites in the network" switch writes config.network. It shows
 * only on a multisite network for a super admin, and is off for users and
 * raw tables, which the server refuses to run across sites.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, sprintf } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;
	const B = app.builder;

	// Dashicon names from the server become a class, so only plain names pass.
	const ICON = /^dashicons-[a-z0-9-]+$/;

	/**
	 * Tells whether a source can never run across a network: users and raw tables.
	 */
	function noNetwork( id ) {
		return 'users' === id || 0 === String( id ).indexOf( 'table:' );
	}

	/**
	 * Builds the Source card: the source tiles, the raw tables behind a disclosure, and the network switch.
	 *
	 * @param {Object} ctx Builder context.
	 */
	function build( ctx ) {
		const name = app.uid( 'opex-source' );
		const features = ctx.options.features || {};
		const search = el( 'input', { type: 'search', class: 'opex-search', placeholder: __( 'Search sources', 'open-export' ), 'aria-label': __( 'Search sources', 'open-export' ) } );
		const standard = el( 'div', {} );
		const rawId = app.uid( 'opex-raw' );
		const raw = el( 'div', { id: rawId, class: 'opex-mt', hidden: true } );
		const disclosure = el( 'button', { type: 'button', class: 'opex-link opex-disclosure', 'aria-expanded': 'false', 'aria-controls': rawId }, [ '', app.icon( 'chevron', { sm: true, cls: 'opex-chev' } ) ] );
		const caution = el( 'p', { class: 'opex-caution' }, [
			app.icon( 'warning', { sm: true, cls: 'opex-c-warning' } ),
			__( 'Caution: Open Export copies raw tables as they are stored, and they can include private data. You can export only their own columns.', 'open-export' ),
		] );
		const advanced = el( 'div', { hidden: true }, [ disclosure, caution, raw ] );
		const noMatch = el( 'p', { class: 'opex-help', hidden: true }, __( 'No sources match your search.', 'open-export' ) );
		const status = el( 'div', {} );
		const unknown = el( 'div', {} );
		const radios = [];
		let network = null;

		disclosure.addEventListener( 'click', () => setRaw( raw.hidden ) );
		// Shows or hides the raw database tables.
		function setRaw( open ) {
			raw.hidden = ! open;
			disclosure.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}

		// Builds the radio tile of one source.
		function tile( item ) {
			const input = el( 'input', { class: 'opex-sr', type: 'radio', name, value: item.id } );
			input.addEventListener( 'change', () => {
				if ( input.checked && item.id !== ctx.config.source ) {
					ctx.setSource( item.id );
				}
			} );
			radios.push( input );
			const icon = ICON.test( String( item.icon || '' ) ) ? item.icon : 'dashicons-admin-generic';
			const label = el( 'label', { class: 'opex-source', 'data-search': ( item.label + ' ' + item.id ).toLowerCase() }, [
				input,
				el( 'span', { class: 'dashicons ' + icon, 'aria-hidden': 'true' } ),
				el( 'span', { class: 'opex-source-name' + ( 0 === item.id.indexOf( 'table:' ) ? ' opex-mono' : '' ) }, item.label ),
				el( 'span', { class: 'opex-source-count' }, [
					el( 'span', { 'aria-hidden': 'true' }, app.formatNumber( item.count ) ),
					el( 'span', { class: 'opex-sr' }, ', ' + app.rows( item.count ) ),
				] ),
				app.icon( 'tick', { sm: true, cls: 'opex-source-check' } ),
			] );
			return label;
		}

		// Draws the source groups and the raw tables.
		function renderGroups() {
			app.clear( standard );
			app.clear( raw );
			app.clear( status );
			radios.length = 0;
			if ( ctx.sourcesError ) {
				status.appendChild( ui.loadError( ctx.sourcesError, () => window.location.reload() ) );
				return;
			}
			if ( ! ctx.sources ) {
				status.appendChild( ui.skeleton( 3 ) );
				return;
			}
			let rawCount = 0;
			ctx.sources.forEach( ( group ) => {
				const items = group.items || [];
				if ( ! items.length ) {
					return;
				}
				const grid = el( 'div', { class: 'opex-sourcegrid' }, items.map( tile ) );
				if ( 'advanced' === group.tier ) {
					rawCount += items.length;
					/* translators: %s: group name, for example "Raw Database Tables". */
					disclosure.firstChild.textContent = sprintf( __( 'Advanced: %s', 'open-export' ), group.label );
					raw.appendChild( grid );
					return;
				}
				standard.appendChild( el( 'fieldset', { class: 'opex-sourcegroup' }, [ el( 'legend', { class: 'opex-groupname' }, group.label ), grid ] ) );
			} );
			advanced.hidden = ! rawCount;
			if ( false === features.woocommerce ) {
				status.appendChild( el( 'p', { class: 'opex-help' }, __( 'WooCommerce is not active, so Open Export does not list its sources.', 'open-export' ) ) );
			}
			applySearch();
		}

		// Hides the sources and groups that do not match the search text.
		function applySearch() {
			const q = search.value.trim().toLowerCase();
			let any = false;
			standard.querySelectorAll( '.opex-sourcegroup' ).forEach( ( set ) => {
				let shown = 0;
				set.querySelectorAll( '.opex-source' ).forEach( ( label ) => {
					const hit = ! q || label.getAttribute( 'data-search' ).indexOf( q ) > -1;
					label.hidden = ! hit;
					shown += hit ? 1 : 0;
				} );
				set.hidden = ! shown;
				any = any || shown > 0;
			} );
			raw.querySelectorAll( '.opex-source' ).forEach( ( label ) => {
				label.hidden = !! q && label.getAttribute( 'data-search' ).indexOf( q ) < 0;
			} );
			noMatch.hidden = any || ! ctx.sources || ! ctx.sources.length;
		}
		search.addEventListener( 'input', applySearch );

		// Selects the config's source and shows a note when the site does not have it.
		function sync() {
			const id = ctx.config.source;
			radios.forEach( ( input ) => {
				input.checked = input.value === id;
			} );
			const found = ctx.sources ? ctx.source( id ) : null;
			if ( found && 'advanced' === found.group.tier && raw.hidden ) {
				setRaw( true );
			}
			app.clear( unknown );
			if ( id && ctx.sources && ! found ) {
				/* translators: %s: source id. */
				unknown.appendChild( ui.notice( 'warning', sprintf( __( 'This setup uses a source this site does not have: %s. Choose another source.', 'open-export' ), id ), { role: 'note', tight: true } ) );
			}
			if ( network ) {
				const blocked = noNetwork( id );
				network.input.disabled = blocked || ! id;
				if ( blocked && ctx.config.network ) {
					ctx.config.network = false;
				}
				network.input.checked = !! ctx.config.network;
				network.help.textContent = blocked
					? __( 'You cannot export users or raw tables across the network.', 'open-export' )
					: __( 'Runs this export on every site in turn and adds site_id and site_url columns.', 'open-export' );
			}
		}

		const parts = [ search, status, standard, noMatch, advanced, unknown ];
		if ( features.multisite && true === ctx.options.is_super_admin ) {
			network = ui.switchControl( {
				label: __( 'All sites in the network', 'open-export' ),
				checked: !! ctx.config.network,
				onChange: ( event ) => {
					ctx.config.network = !! event.target.checked;
					ctx.changed( 'network' );
				},
			} );
			network.help = el( 'p', { class: 'opex-help', id: app.uid( 'opex-help' ) } );
			network.input.setAttribute( 'aria-describedby', network.help.id );
			parts.push( el( 'div', { class: 'opex-mt-lg' }, [ network.root, network.help ] ) );
		}

		return {
			root: el( 'div', {}, parts ),
			refresh( reason ) {
				if ( 'sources' === reason ) {
					renderGroups();
				}
				sync();
			},
			done: () => !! ctx.config.source,
			summary() {
				const found = ctx.source( ctx.config.source );
				if ( ! found ) {
					return ctx.config.source;
				}
				return app.list( [ found.item.label, app.rows( found.item.count ), ctx.config.network ? __( 'all sites', 'open-export' ) : '' ] );
			},
			errors() {
				if ( ctx.config.source ) {
					return [];
				}
				return [ { message: __( 'Choose a source.', 'open-export' ), focus: () => ( radios[ 0 ] || search ).focus() } ];
			},
		};
	}

	B.register( { id: 'source', order: 10, title: __( 'Source', 'open-export' ), needsSource: false, build } );
}() );
