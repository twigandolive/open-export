/**
 * Open Export admin screen: shared parts with behavior (part two of two).
 *
 * Exposes on opexApp.ui:
 *   tabs( { items, label, onSelect } )  An ARIA tab bar with panels. Arrow keys,
 *                       Home and End move between tabs (roving tabindex).
 *                       Returns { list, panels, select( id, focus ), current() }.
 *   confirm( { title, message, confirmLabel, danger } )  A native modal <dialog>.
 *                       Resolves true or false. Focus goes back to the control
 *                       that opened it unless the caller moves it.
 *   table( { caption, columns, cls } )  A list table. Returns { root, table, tbody }.
 *                       columns: [ { label, cls, sr } ], sr hides the header text visually.
 *                       cls "opex-plain" stripes every other row. Without it the
 *                       stripes assume a detail row after each row, as in History.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __ } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;

	/**
	 * Builds the tab bar and one panel per tab.
	 *
	 * @param {Object} o items [ { id, label } ], label (for the tablist), onSelect( id ).
	 * @return {Object} Tab bar API.
	 */
	ui.tabs = function ( o ) {
		const buttons = {};
		const panels = {};
		let current = null;
		const ids = o.items.map( ( item ) => item.id );

		const list = el( 'div', { class: 'opex-tabs', role: 'tablist', 'aria-label': o.label } );
		o.items.forEach( ( item ) => {
			const tabId = 'opex-tab-' + item.id;
			const panelId = 'opex-panel-' + item.id;
			buttons[ item.id ] = el( 'button', {
				type: 'button',
				class: 'opex-tab',
				role: 'tab',
				id: tabId,
				'aria-controls': panelId,
				'aria-selected': 'false',
				tabindex: '-1',
				onClick: () => select( item.id, false ),
				onKeydown: ( event ) => onKey( event, item.id ),
			}, item.label );
			panels[ item.id ] = el( 'section', {
				class: 'opex-panel',
				id: panelId,
				role: 'tabpanel',
				'aria-labelledby': tabId,
				hidden: true,
			} );
			list.appendChild( buttons[ item.id ] );
		} );

		function onKey( event, id ) {
			const i = ids.indexOf( id );
			let next = null;
			if ( 'ArrowRight' === event.key ) {
				next = ids[ ( i + 1 ) % ids.length ];
			} else if ( 'ArrowLeft' === event.key ) {
				next = ids[ ( i - 1 + ids.length ) % ids.length ];
			} else if ( 'Home' === event.key ) {
				next = ids[ 0 ];
			} else if ( 'End' === event.key ) {
				next = ids[ ids.length - 1 ];
			}
			if ( next ) {
				event.preventDefault();
				select( next, true );
			}
		}

		function select( id, focus ) {
			if ( ! buttons[ id ] ) {
				return;
			}
			const changed = id !== current;
			current = id;
			ids.forEach( ( key ) => {
				const on = key === id;
				buttons[ key ].setAttribute( 'aria-selected', on ? 'true' : 'false' );
				buttons[ key ].tabIndex = on ? 0 : -1;
				panels[ key ].hidden = ! on;
			} );
			if ( focus ) {
				buttons[ id ].focus();
			}
			if ( changed && o.onSelect ) {
				o.onSelect( id );
			}
		}

		return { list, panels, buttons, select, current: () => current };
	};

	/**
	 * Asks a yes or no question in a modal dialog.
	 *
	 * @param {Object} o title, message, confirmLabel, danger.
	 * @return {Promise<boolean>} True when the person confirmed.
	 */
	ui.confirm = function ( o ) {
		const opener = document.activeElement;
		const host = document.querySelector( '.opex-wrap' ) || document.body;
		const titleId = app.uid( 'opex-dlg-title' );
		const textId = app.uid( 'opex-dlg-text' );
		return new Promise( ( resolve ) => {
			let answer = false;
			const yes = ui.button( {
				label: o.confirmLabel || __( 'OK', 'open-export' ),
				primary: ! o.danger,
				cls: o.danger ? 'opex-btn-danger' : '',
				onClick: () => {
					answer = true;
					dialog.close();
				},
			} );
			const no = ui.button( { label: __( 'Cancel', 'open-export' ), onClick: () => dialog.close() } );
			const dialog = el( 'dialog', {
				class: 'opex-dialog',
				'aria-labelledby': titleId,
				'aria-describedby': textId,
			}, [
				el( 'h2', { class: 'opex-dialog-title', id: titleId }, o.title ),
				el( 'p', { id: textId }, o.message ),
				el( 'div', { class: 'opex-dialog-actions' }, [ no, yes ] ),
			] );
			dialog.addEventListener( 'close', () => {
				dialog.remove();
				resolve( answer );
				// The caller may move focus after resolving (for example after a
				// delete). Restore it only if nothing else took it.
				window.setTimeout( () => {
					const active = document.activeElement;
					if ( ( ! active || active === document.body ) && opener && opener.isConnected ) {
						opener.focus();
					}
				}, 0 );
			} );
			host.appendChild( dialog );
			dialog.showModal();
			// The safe choice gets focus first for destructive questions.
			( o.danger ? no : yes ).focus();
		} );
	};

	/**
	 * Builds a table with a hidden caption and column headers.
	 *
	 * @param {Object} o Caption, columns and an optional class.
	 */
	ui.table = function ( o ) {
		const head = el( 'tr', {}, o.columns.map( ( col ) => el( 'th', {
			scope: 'col',
			class: col.cls,
		}, col.sr ? el( 'span', { class: 'opex-sr' }, col.label ) : col.label ) ) );
		const tbody = el( 'tbody', {} );
		const table = el( 'table', { class: 'opex-table' + ( o.cls ? ' ' + o.cls : '' ) }, [
			el( 'caption', { class: 'opex-sr' }, o.caption ),
			el( 'thead', {}, head ),
			tbody,
		] );
		return { root: el( 'div', { class: 'opex-tablewrap' }, table ), tbody };
	};
}() );
