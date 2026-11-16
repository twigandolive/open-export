/**
 * Open Export admin screen: the Export tab.
 *
 * Puts the pieces together: the "Start from" row (export-start.js) with the
 * AI recipe panel (export-recipe.js), the builder (builder.js and its
 * section files), the Run card (export-run.js) and the sticky summary bar.
 * "Start export" is the only label for starting, in the Run card and in
 * the bar.
 *
 * Other tabs load a config into the builder by setting the store key
 * "builderLoad" to { config, name } and opening this tab. History's "Open
 * in builder" does that. The key is read again when this tab mounts, so it
 * works before the tab was ever opened.
 *
 * Registers the tab "export".
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, sprintf } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;
	const store = app.store;

	function mount( panel ) {
		const messages = el( 'div', { class: 'opex-messages', 'aria-live': 'polite' } );
		const cards = el( 'div', { class: 'opex-builder' } );
		const barText = el( 'p', { class: 'opex-stickybar-text', 'aria-live': 'off' } );
		// Set after the builder mounts. The builder reports changes while it mounts.
		let run = null;
		let runCard = null;
		let builder = null;

		function say( type, text ) {
			app.clear( messages );
			if ( text ) {
				messages.appendChild( ui.notice( type, text, { role: 'error' === type ? 'alert' : 'status' } ) );
			}
		}

		builder = app.builder.mount( cards, {
			mode: 'export',
			onChange: ( config, reason ) => {
				if ( ! builder ) {
					return;
				}
				syncBar();
				if ( run && ( 'source' === reason || 'sources' === reason || 'load' === reason ) ) {
					run.syncName();
				}
				if ( runCard ) {
					runCard.setLocked( ! config.source );
				}
			},
		} );

		function load( config, name ) {
			builder.setConfig( config ).then( () => syncBar() );
			run.setName( name || '' );
			runCard.setLocked( ! builder.getConfig().source );
		}

		const recipe = app.exportRecipe( {
			source: () => builder.getConfig().source,
			onApply: ( config, name ) => load( config, name ),
		} );
		const start = app.exportStart( { onLoad: load, say, extra: recipe.toggle } );

		runCard = app.builder.card( { number: app.builder.sections.length + 1, title: __( 'Run', 'open-export' ) } );
		run = app.exportRun( { builder, say } );
		runCard.body.appendChild( run.root );
		runCard.setLocked( ! builder.getConfig().source );
		run.syncName();

		const barButton = ui.button( { label: __( 'Start export', 'open-export' ), primary: true, onClick: () => {
			// Bring the Run card into view, so the result line is where the person looks.
			runCard.setOpen( true );
			run.start();
			runCard.root.scrollIntoView( { block: 'nearest' } );
		} } );
		const bar = el( 'div', { class: 'opex-stickybar', role: 'region', 'aria-label': __( 'Export summary', 'open-export' ) }, [ barText, barButton ] );

		function syncBar() {
			const total = builder.previewTotal();
			let text = builder.summary();
			if ( null !== total ) {
				/* translators: 1: export summary, 2: "1,240 rows". */
				text = sprintf( __( '%1$s, %2$s', 'open-export' ), text, app.rows( total ) );
			}
			if ( barText.textContent !== text ) {
				barText.textContent = text;
			}
		}

		app.append( panel, [ messages, start.root, recipe.panel, cards, runCard.root, bar ] );
		start.loadTemplates();
		syncBar();

		function takeLoad() {
			const request = store.get( 'builderLoad' );
			if ( ! request || ! request.config ) {
				return;
			}
			store.set( 'builderLoad', null );
			load( request.config, request.name );
			/* translators: %s: export name. */
			say( 'success', request.name ? sprintf( __( 'Loaded the setup of %s into the builder.', 'open-export' ), request.name ) : __( 'Loaded the setup into the builder.', 'open-export' ) );
		}
		store.subscribe( 'builderLoad', takeLoad );
		takeLoad();

		return {
			show() {
				run.show();
			},
			hide() {
				run.hide();
			},
		};
	}

	app.registerTab( 'export', { mount } );
}() );
