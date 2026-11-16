/**
 * Open Export admin screen: the ordered list of chosen columns.
 *
 * One row per id in config.fields: an editable header label, the field id
 * (or a Custom tag), a Transform button that opens the step editor, and up,
 * down and remove buttons. Rows can also be dragged by their grip. Up and
 * down keep keyboard users able to reorder, and focus follows the moved row.
 *
 * Header labels write config.column_labels (only when they differ from the
 * field's own label). A custom column's header is its label, so renaming it
 * edits custom_columns[].label and never its id.
 *
 * Exposes opexApp.builder.chosen( ctx, { onChange, onEmpty } ):
 *   Returns { root, rebuild(), focusError( problem ) }.
 *   onChange( reason ) reports a change. onEmpty() returns where focus goes
 *   when the last row is removed.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, _n, sprintf } = window.wp.i18n;
	const el = app.el;
	const B = app.builder;

	/**
	 * Takes a column out of config.acf_flatten, and drops the key when the
	 * list is empty. Used when a column is removed.
	 *
	 * @param {Object} config Builder config.
	 * @param {string} id     Column id.
	 */
	B.dropAcf = function ( config, id ) {
		if ( ! Array.isArray( config.acf_flatten ) ) {
			return;
		}
		config.acf_flatten = config.acf_flatten.filter( ( item ) => item !== id );
		if ( ! config.acf_flatten.length ) {
			delete config.acf_flatten;
		}
	};

	B.chosen = function ( ctx, o ) {
		const open = new Set();
		const panels = new Map();
		const title = el( 'p', { class: 'opex-pane-title' } );
		const list = el( 'ol', { class: 'opex-chosen', 'aria-label': __( 'Chosen columns', 'open-export' ) } );
		const empty = el( 'p', { class: 'opex-help', hidden: true }, __( 'No columns yet. Tick fields in the list, or add a custom column below.', 'open-export' ) );
		const live = el( 'p', { class: 'opex-sr', 'aria-live': 'polite' } );
		let dragId = null;

		function dropColumn( id ) {
			const config = ctx.config;
			config.fields = config.fields.filter( ( f ) => f !== id );
			delete config.column_labels[ id ];
			delete config.transforms[ id ];
			config.custom_columns = config.custom_columns.filter( ( col ) => col.id !== id );
			B.dropAcf( config, id );
			open.delete( id );
		}

		function moveTo( id, to ) {
			const fields = ctx.config.fields;
			const from = fields.indexOf( id );
			if ( from < 0 || to < 0 || to >= fields.length || from === to ) {
				return;
			}
			fields.splice( to, 0, fields.splice( from, 1 )[ 0 ] );
			/* translators: 1: column name, 2: new position, 3: number of columns. */
			live.textContent = sprintf( __( '%1$s moved to position %2$d of %3$d.', 'open-export' ), ctx.label( id ), to + 1, fields.length );
		}

		// Structural change: rebuild, report, then focus an action on a row.
		function after( id, action ) {
			rebuild();
			o.onChange( 'chosen' );
			const row = id ? list.querySelector( '[data-col="' + CSS.escape( id ) + '"]' ) : null;
			let target = row ? row.querySelector( '[data-col-action="' + action + '"]' ) : null;
			if ( target && target.disabled ) {
				target = row.querySelector( '[data-col-action="' + ( 'up' === action ? 'down' : 'up' ) + '"]' );
			}
			if ( target && ! target.disabled ) {
				target.focus();
			} else if ( row ) {
				row.querySelector( 'input' ).focus();
			} else {
				const fallback = o.onEmpty && o.onEmpty();
				if ( fallback ) {
					fallback.focus();
				}
			}
		}

		function stepCount( id ) {
			return ( ctx.config.transforms[ id ] || [] ).length;
		}

		function badge( button, id ) {
			const n = stepCount( id );
			const old = button.querySelector( '.opex-count' );
			if ( old ) {
				old.remove();
			}
			if ( n ) {
				/* translators: %d: number of steps. */
				button.appendChild( el( 'span', { class: 'opex-count', 'aria-label': sprintf( _n( '%d step', '%d steps', n, 'open-export' ), n ) }, String( n ) ) );
			}
		}

		function headerInput( id, custom ) {
			const name = ctx.label( id );
			/* translators: %s: field id or custom column label. */
			const input = el( 'input', { type: 'text', 'aria-label': sprintf( __( 'Header label for %s', 'open-export' ), custom ? name : id ), 'data-col-action': 'label' } );
			if ( custom ) {
				input.value = custom.label;
				input.addEventListener( 'input', () => {
					custom.label = input.value;
					if ( B.custom.checkLabel( input.value, ctx ) ) {
						input.setAttribute( 'aria-invalid', 'true' );
					} else {
						input.removeAttribute( 'aria-invalid' );
					}
					o.onChange( 'custom-value' );
				} );
				return input;
			}
			const own = ctx.fieldIndex[ id ] ? ctx.fieldIndex[ id ].label : id;
			input.value = Object.prototype.hasOwnProperty.call( ctx.config.column_labels, id ) ? String( ctx.config.column_labels[ id ] ) : own;
			input.placeholder = own;
			input.addEventListener( 'input', () => {
				const value = input.value;
				if ( ! value.trim() || value === own ) {
					delete ctx.config.column_labels[ id ];
				} else {
					ctx.config.column_labels[ id ] = value;
				}
				o.onChange( 'labels' );
			} );
			return input;
		}

		/**
		 * ACF repeater and flexible content fields (ids starting with acf:)
		 * can be kept as one JSON column or spread into columns. The choice
		 * lives in config.acf_flatten, a list of spread ids. The key is only
		 * written once someone picks "Spread", so a site without ACF fields
		 * never sends it.
		 *
		 * @param {string} id Column id.
		 * @return {HTMLElement|null} The choice, or null when it does not apply.
		 */
		function acfChoice( id ) {
			if ( 0 !== id.indexOf( 'acf:' ) || ! Object.keys( ctx.fieldIndex ).some( ( key ) => 0 === key.indexOf( 'acf:' ) ) ) {
				return null;
			}
			const spread = () => ( Array.isArray( ctx.config.acf_flatten ) ? ctx.config.acf_flatten : [] );
			const select = el( 'select', { 'data-col-action': 'acf' }, [
				el( 'option', { value: 'json' }, __( 'Keep as one JSON column', 'open-export' ) ),
				el( 'option', { value: 'spread' }, __( 'Spread into columns', 'open-export' ) ),
			] );
			select.value = spread().indexOf( id ) > -1 ? 'spread' : 'json';
			const help = el( 'p', { class: 'opex-help', hidden: 'spread' !== select.value }, __( 'Makes one column per sub-field and row, for at most 20 rows.', 'open-export' ) );
			select.addEventListener( 'change', () => {
				const list = spread().filter( ( item ) => item !== id );
				if ( 'spread' === select.value ) {
					list.push( id );
				}
				if ( list.length ) {
					ctx.config.acf_flatten = list;
				} else {
					delete ctx.config.acf_flatten;
				}
				help.hidden = 'spread' !== select.value;
				o.onChange( 'acf' );
			} );
			return el( 'div', { class: 'opex-colrow-acf' }, [
				el( 'label', { class: 'opex-mini' }, [ __( 'Export as', 'open-export' ), select ] ),
				help,
			] );
		}

		function panelFor( id, custom, button ) {
			const box = el( 'div', { class: 'opex-colpanel', id: app.uid( 'opex-tf' ) } );
			const onChange = ( reason ) => {
				badge( button, id );
				o.onChange( reason );
			};
			const editor = custom ? B.custom.templateEditor( ctx, custom, onChange ) : null;
			const steps = B.transforms.panel( ctx, id, onChange );
			app.append( box, [ editor ? el( 'div', { class: 'opex-transform' }, editor.root ) : null, steps.root ] );
			return { box, editor, steps };
		}

		function row( id, index, count ) {
			const custom = ctx.config.custom_columns.find( ( col ) => col.id === id ) || null;
			const name = ctx.label( id );
			const known = custom || ! ctx.fields || ctx.fieldIndex[ id ];
			const item = el( 'li', { class: 'opex-colrow', 'data-col': id } );
			const grip = el( 'span', { class: 'opex-grip', 'aria-hidden': 'true', title: __( 'Drag to reorder', 'open-export' ) }, app.icon( 'grip', { sm: true } ) );
			const toggle = el( 'button', { type: 'button', class: 'opex-btn opex-btn-sm', 'aria-expanded': 'false', 'data-col-action': 'transform' }, custom ? __( 'Template and steps', 'open-export' ) : __( 'Transform', 'open-export' ) );
			badge( toggle, id );
			const icon = ( action, label, disabled, fn ) => el( 'button', { type: 'button', class: 'opex-iconbtn', 'aria-label': label, 'data-col-action': action, disabled, onClick: fn }, app.icon( 'remove' === action ? 'close' : action, { sm: true } ) );
			const main = el( 'div', { class: 'opex-colrow-main' }, [
				grip,
				headerInput( id, custom ),
				custom ? el( 'span', { class: 'opex-tag' }, __( 'Custom', 'open-export' ) ) : el( 'code', { class: 'opex-colfield' }, id ),
				known ? null : el( 'span', { class: 'opex-tag opex-tag-warn' }, __( 'Not on this source', 'open-export' ) ),
				toggle,
				/* translators: %s: column name. */
				icon( 'up', sprintf( __( 'Move %s up', 'open-export' ), name ), 0 === index, () => {
					moveTo( id, index - 1 );
					after( id, 'up' );
				} ),
				/* translators: %s: column name. */
				icon( 'down', sprintf( __( 'Move %s down', 'open-export' ), name ), index === count - 1, () => {
					moveTo( id, index + 1 );
					after( id, 'down' );
				} ),
				/* translators: %s: column name. */
				icon( 'remove', sprintf( __( 'Remove %s', 'open-export' ), name ), false, () => {
					const next = ctx.config.fields[ index + 1 ] || ctx.config.fields[ index - 1 ] || null;
					dropColumn( id );
					/* translators: %s: column name. */
					live.textContent = sprintf( __( '%s removed.', 'open-export' ), name );
					after( next, 'remove' );
				} ),
			] );
			app.append( item, [ main, custom ? null : acfChoice( id ) ] );

			const setOpen = ( value ) => {
				if ( value && ! panels.has( id ) ) {
					const made = panelFor( id, custom, toggle );
					panels.set( id, made );
					toggle.setAttribute( 'aria-controls', made.box.id );
					item.appendChild( made.box );
				}
				if ( panels.has( id ) ) {
					panels.get( id ).box.hidden = ! value;
				}
				toggle.setAttribute( 'aria-expanded', value ? 'true' : 'false' );
				if ( value ) {
					open.add( id );
				} else {
					open.delete( id );
				}
			};
			toggle.addEventListener( 'click', () => setOpen( 'true' !== toggle.getAttribute( 'aria-expanded' ) ) );
			item.opexOpen = setOpen;
			if ( open.has( id ) ) {
				setOpen( true );
			}

			// Drag starts only from the grip, so text in the header box stays selectable.
			grip.addEventListener( 'mousedown', () => {
				item.draggable = true;
			} );
			item.addEventListener( 'dragstart', ( event ) => {
				dragId = id;
				event.dataTransfer.effectAllowed = 'move';
				event.dataTransfer.setData( 'text/plain', id );
				item.classList.add( 'is-dragging' );
			} );
			item.addEventListener( 'dragend', () => {
				item.draggable = false;
				dragId = null;
				list.querySelectorAll( '.opex-colrow' ).forEach( ( node ) => node.classList.remove( 'is-dragging', 'opex-drop-before', 'opex-drop-after' ) );
			} );
			item.addEventListener( 'dragover', ( event ) => {
				if ( ! dragId || dragId === id ) {
					return;
				}
				event.preventDefault();
				const box = item.getBoundingClientRect();
				const before = event.clientY < box.top + box.height / 2;
				item.classList.toggle( 'opex-drop-before', before );
				item.classList.toggle( 'opex-drop-after', ! before );
			} );
			item.addEventListener( 'dragleave', () => item.classList.remove( 'opex-drop-before', 'opex-drop-after' ) );
			item.addEventListener( 'drop', ( event ) => {
				event.preventDefault();
				if ( ! dragId || dragId === id ) {
					return;
				}
				const moving = dragId;
				const before = item.classList.contains( 'opex-drop-before' );
				const fields = ctx.config.fields;
				const from = fields.indexOf( moving );
				let to = fields.indexOf( id ) + ( before ? 0 : 1 );
				if ( from < to ) {
					to -= 1;
				}
				moveTo( moving, to );
				after( moving, 'label' );
			} );
			return item;
		}

		function rebuild() {
			panels.clear();
			app.clear( list );
			const fields = ctx.config.fields;
			fields.forEach( ( id, i ) => list.appendChild( row( id, i, fields.length ) ) );
			empty.hidden = fields.length > 0;
			app.clear( title );
			app.append( title, [
				/* translators: %s: number of columns. */
				sprintf( __( 'Chosen columns, %s', 'open-export' ), app.formatNumber( fields.length ) ),
				el( 'span', {}, __( 'Drag to reorder, or use the arrows.', 'open-export' ) ),
			] );
		}

		function focusError( problem ) {
			const id = problem.fieldId || problem.id;
			const item = id ? list.querySelector( '[data-col="' + CSS.escape( id ) + '"]' ) : null;
			if ( ! item ) {
				return;
			}
			if ( 'label' === problem.what ) {
				item.querySelector( '[data-col-action="label"]' ).focus();
				return;
			}
			item.opexOpen( true );
			const made = panels.get( id );
			if ( 'template' === problem.what && made.editor ) {
				made.editor.input.focus();
			} else if ( undefined !== problem.index ) {
				made.steps.focusError( problem.index, problem.arg );
			}
		}

		return { root: el( 'div', {}, [ title, el( 'div', { class: 'opex-pane opex-pane-chosen' }, [ list, empty ] ), live ] ), rebuild, focusError };
	};
}() );
