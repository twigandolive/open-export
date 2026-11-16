/**
 * Open Export admin screen: builder card 2, columns.
 *
 * Left: the source's fields from GET /fields, grouped, with search and
 * "all" and "none" per group. Groups are keyed by the server's stable `id`,
 * or by position when there is none, never by their translated label.
 * Right: the ordered chosen list (builder-chosen.js). Below: the custom
 * column form (builder-custom.js).
 *
 * Writes config.fields, column_labels, transforms and custom_columns.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, sprintf, _n } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;
	const B = app.builder;

	/**
	 * Builds the Columns card: the field picker, the chosen list and the custom column form.
	 *
	 * @param {Object} ctx Builder context.
	 */
	function build( ctx ) {
		const search = el( 'input', { type: 'search', class: 'opex-w-full opex-fieldsearch', placeholder: __( 'Search fields', 'open-export' ), 'aria-label': __( 'Search fields', 'open-export' ) } );
		const pane = el( 'div', { class: 'opex-pane' } );
		const noMatch = el( 'p', { class: 'opex-help', hidden: true }, __( 'No fields match your search.', 'open-export' ) );
		const boxes = new Map();
		const chosen = B.chosen( ctx, { onChange: ( reason ) => ctx.changed( reason ), onEmpty: () => search } );
		const custom = B.custom.addForm( ctx, () => ctx.changed( 'custom' ) );

		// Adds a field to the chosen columns, once.
		function addField( id ) {
			if ( ctx.config.fields.indexOf( id ) < 0 ) {
				ctx.config.fields.push( id );
			}
		}

		// Takes a field out of the chosen columns, with its label, steps and ACF spread setting.
		function removeField( id ) {
			ctx.config.fields = ctx.config.fields.filter( ( f ) => f !== id );
			delete ctx.config.column_labels[ id ];
			delete ctx.config.transforms[ id ];
			B.dropAcf( ctx.config, id );
		}

		// Draws the field checkboxes of the chosen source, group by group.
		function renderPicker() {
			app.clear( pane );
			boxes.clear();
			if ( ! ctx.config.source ) {
				return;
			}
			if ( ctx.fieldsError ) {
				pane.appendChild( ui.loadError( ctx.fieldsError, () => ctx.retryFields() ) );
				return;
			}
			if ( ! ctx.fields ) {
				pane.appendChild( ui.skeleton( 4 ) );
				return;
			}
			( ctx.fields.groups || [] ).forEach( ( group, i ) => {
				const key = group.id ? String( group.id ) : 'group-' + i;
				const fields = group.fields || [];
				// Checks or clears every field of one group.
				const set = ( on ) => {
					fields.forEach( ( f ) => ( on ? addField( f.id ) : removeField( f.id ) ) );
					ctx.changed( 'fields' );
				};
				// The name is a span tied to the fieldset, because a legend must be the fieldset's first child.
				const nameId = app.uid( 'opex-fg' );
				const head = el( 'div', { class: 'opex-fieldgroup-head' }, [
					el( 'span', { class: 'opex-fieldgroup-name', id: nameId }, group.label ),
					el( 'span', { class: 'opex-fieldgroup-links' }, [
						/* translators: %s: field group name. */
						ui.linkButton( { label: __( 'all', 'open-export' ), ariaLabel: sprintf( __( 'Select all %s fields', 'open-export' ), group.label ), onClick: () => set( true ) } ),
						/* translators: %s: field group name. */
						ui.linkButton( { label: __( 'none', 'open-export' ), ariaLabel: sprintf( __( 'Clear all %s fields', 'open-export' ), group.label ), onClick: () => set( false ) } ),
					] ),
				] );
				const items = fields.map( ( field ) => {
					const box = el( 'input', { type: 'checkbox', value: field.id } );
					box.addEventListener( 'change', () => {
						if ( box.checked ) {
							addField( field.id );
						} else {
							removeField( field.id );
						}
						ctx.changed( 'fields' );
					} );
					boxes.set( field.id, box );
					return el( 'label', { class: 'opex-check', 'data-search': ( field.label + ' ' + field.id ).toLowerCase() }, [
						box,
						el( 'span', {}, field.label ),
						field.label !== field.id ? el( 'code', {}, field.id ) : null,
					] );
				} );
				pane.appendChild( el( 'fieldset', { class: 'opex-fieldgroup', 'data-group': key, 'aria-labelledby': nameId }, [ head, items ] ) );
			} );
			pane.appendChild( noMatch );
			syncBoxes();
			applySearch();
		}

		// Checks the boxes of the fields that are in the config.
		function syncBoxes() {
			const on = new Set( ctx.config.fields );
			boxes.forEach( ( box, id ) => {
				box.checked = on.has( id );
			} );
		}

		// Hides the fields and groups that do not match the search text.
		function applySearch() {
			const q = search.value.trim().toLowerCase();
			let any = false;
			pane.querySelectorAll( '.opex-fieldgroup' ).forEach( ( set ) => {
				let shown = 0;
				set.querySelectorAll( '.opex-check' ).forEach( ( label ) => {
					const hit = ! q || label.getAttribute( 'data-search' ).indexOf( q ) > -1;
					label.hidden = ! hit;
					shown += hit ? 1 : 0;
				} );
				set.hidden = ! shown;
				any = any || shown > 0;
			} );
			noMatch.hidden = any || ! boxes.size;
		}
		search.addEventListener( 'input', applySearch );

		const root = el( 'div', {}, [
			el( 'div', { class: 'opex-cols2' }, [
				el( 'div', {}, [ search, pane ] ),
				chosen.root,
			] ),
			custom.root,
		] );

		return {
			root,
			refresh( reason ) {
				if ( [ 'load', 'source', 'fields-loaded', 'fields-error' ].indexOf( reason ) > -1 ) {
					renderPicker();
					chosen.rebuild();
					custom.refresh();
				} else if ( 'fields' === reason || 'custom' === reason ) {
					syncBoxes();
					chosen.rebuild();
					custom.refresh();
				} else if ( 'chosen' === reason ) {
					syncBoxes();
					custom.refresh();
				}
			},
			done: () => ctx.config.fields.length > 0,
			summary() {
				const n = ctx.config.fields.length;
				const c = ctx.config.custom_columns.length;
				/* translators: %s: number of columns. */
				const text = sprintf( _n( '%s column', '%s columns', n, 'open-export' ), app.formatNumber( n ) );
				if ( ! c ) {
					return text;
				}
				/* translators: %s: number of custom columns. */
				const extra = sprintf( _n( '%s custom', '%s custom', c, 'open-export' ), app.formatNumber( c ) );
				/* translators: 1: "5 columns", 2: "2 custom". */
				return sprintf( __( '%1$s, %2$s', 'open-export' ), text, extra );
			},
			errors() {
				const list = [];
				if ( ctx.config.source && ! ctx.config.fields.length ) {
					list.push( { message: __( 'Choose at least one column.', 'open-export' ), focus: () => search.focus() } );
				}
				B.custom.errors( ctx ).concat( B.transforms.errors( ctx ) ).forEach( ( problem ) => {
					list.push( { message: problem.message, focus: () => chosen.focusError( problem ) } );
				} );
				return list;
			},
		};
	}

	B.register( { id: 'columns', order: 20, title: __( 'Columns', 'open-export' ), needsSource: true, inSummary: true, build } );
}() );
