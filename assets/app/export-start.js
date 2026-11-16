/**
 * Open Export admin screen: the "Start from" row of the Export tab.
 *
 * Saved templates (GET /templates, newest first) with Load and Delete, feed
 * presets (GET /presets) only when the server has the route and returns
 * items, and the toggle for the AI recipe panel. A select never acts on
 * its own: Load and Delete are buttons, so arrow keys only browse.
 *
 * The template list lives in the store under "templates", so "Save as
 * template" in the Run card refreshes this menu.
 *
 * Exposes opexApp.exportStart( { onLoad( config, name ), say( type, text ), extra } ):
 *   Returns { root, loadTemplates() }. extra is appended to the row (the recipe toggle).
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, sprintf } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;
	const store = app.store;

	app.exportStart = function ( o ) {
		const labelId = app.uid( 'opex-start' );
		const tplSelect = el( 'select', { 'aria-label': __( 'Saved templates', 'open-export' ) } );
		const tplLoad = ui.button( { label: __( 'Load', 'open-export' ), small: true, onClick: loadTemplate } );
		const tplDelete = ui.linkButton( { label: __( 'Delete', 'open-export' ), danger: true, onClick: deleteTemplate } );
		const presetSelect = el( 'select', { 'aria-label': __( 'Feed presets for stores', 'open-export' ) } );
		const presetLoad = ui.button( { label: __( 'Load', 'open-export' ), small: true, onClick: loadPreset } );
		const presetHelp = el( 'p', { class: 'opex-help opex-preset-help', 'aria-live': 'polite' } );
		const presetBox = el( 'span', { class: 'opex-inline', hidden: true }, [ presetSelect, presetLoad ] );
		let presets = [];

		function templates() {
			return store.get( 'templates' ) || [];
		}

		function fillTemplates() {
			const list = templates();
			const keep = tplSelect.value;
			app.clear( tplSelect );
			tplSelect.appendChild( el( 'option', { value: '' }, list.length ? __( 'Saved templates', 'open-export' ) : __( 'No saved templates', 'open-export' ) ) );
			// The source name helps tell apart templates with similar names. Older servers send none.
			list.forEach( ( t ) => tplSelect.appendChild( el( 'option', { value: t.id }, t.source_label ? app.paren( t.name, t.source_label ) : t.name ) ) );
			tplSelect.value = list.some( ( t ) => t.id === keep ) ? keep : '';
			tplSelect.disabled = ! list.length;
			tplLoad.disabled = ! list.length;
			tplDelete.disabled = ! list.length;
		}
		store.subscribe( 'templates', fillTemplates );

		function loadTemplates() {
			return app.api.get( '/templates' ).then( ( list ) => store.set( 'templates', Array.isArray( list ) ? list : [] ) ).catch( ( err ) => {
				if ( ! err.session ) {
					o.say( 'error', err.message );
				}
			} );
		}

		function picked() {
			return templates().find( ( t ) => t.id === tplSelect.value ) || null;
		}

		function loadTemplate() {
			const t = picked();
			if ( ! t ) {
				o.say( 'error', __( 'Choose a saved template first.', 'open-export' ) );
				tplSelect.focus();
				return;
			}
			o.onLoad( t.config, t.name );
			/* translators: %s: template name. */
			o.say( 'success', sprintf( __( 'Loaded the template %s.', 'open-export' ), t.name ) );
		}

		function deleteTemplate() {
			const t = picked();
			if ( ! t ) {
				o.say( 'error', __( 'Choose a saved template first.', 'open-export' ) );
				tplSelect.focus();
				return;
			}
			ui.confirm( {
				title: __( 'Delete this template?', 'open-export' ),
				/* translators: %s: template name. */
				message: sprintf( __( 'The template %s will be deleted. Exports made from it are not affected.', 'open-export' ), t.name ),
				confirmLabel: __( 'Delete', 'open-export' ),
				danger: true,
			} ).then( ( yes ) => {
				if ( ! yes ) {
					return null;
				}
				return app.api.del( '/templates/' + encodeURIComponent( t.id ) ).then( () => {
					store.set( 'templates', templates().filter( ( item ) => item.id !== t.id ) );
					/* translators: %s: template name. */
					o.say( 'success', sprintf( __( 'Deleted the template %s.', 'open-export' ), t.name ) );
					tplSelect.focus();
				} );
			} ).catch( ( err ) => {
				if ( ! err.session ) {
					o.say( 'error', err.message );
				}
			} );
		}

		function loadPreset() {
			const p = presets.find( ( item ) => item.id === presetSelect.value );
			if ( ! p ) {
				o.say( 'error', __( 'Choose a feed preset first.', 'open-export' ) );
				presetSelect.focus();
				return;
			}
			o.onLoad( p.config, p.label );
			/* translators: %s: preset name. */
			o.say( 'success', sprintf( __( 'Loaded the %s preset. Check the setup, then start the export.', 'open-export' ), p.label ) );
		}

		presetSelect.addEventListener( 'change', () => {
			const p = presets.find( ( item ) => item.id === presetSelect.value );
			presetHelp.textContent = p ? String( p.description || '' ) : '';
		} );

		// Feed presets need GET /presets and an active WooCommerce.
		app.api.hasRoute( '/presets' ).then( ( yes ) => ( yes ? app.api.get( '/presets' ) : [] ) ).then( ( list ) => {
			presets = ( Array.isArray( list ) ? list : [] ).filter( ( p ) => p && p.id && p.config );
			if ( ! presets.length ) {
				return;
			}
			presetSelect.appendChild( el( 'option', { value: '' }, __( 'Feed preset', 'open-export' ) ) );
			presets.forEach( ( p ) => presetSelect.appendChild( el( 'option', { value: p.id }, p.label ) ) );
			presetBox.hidden = false;
		} ).catch( () => {} );

		fillTemplates();
		const root = el( 'div', {}, [
			el( 'div', { class: 'opex-startbar', role: 'group', 'aria-labelledby': labelId }, [
				el( 'span', { class: 'opex-startbar-label', id: labelId }, __( 'Start from', 'open-export' ) ),
				el( 'span', { class: 'opex-inline' }, [ tplSelect, tplLoad, tplDelete ] ),
				presetBox,
				o.extra || null,
			] ),
			presetHelp,
		] );
		return { root, loadTemplates };
	};
}() );
