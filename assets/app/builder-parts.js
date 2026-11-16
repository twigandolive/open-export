/**
 * Open Export admin screen: shared parts of the export builder.
 *
 * The config helpers, the numbered card shell and the "Insert field" menu.
 * builder.js mounts the component. Section files register their cards here.
 *
 * Exposes on opexApp.builder:
 *   register( def )     For section files: { id, order, title, needsSource, inSummary, build( ctx ) }.
 *       build returns { root, refresh( reason ), summary(), short(), done(), errors() }.
 *       short() is an optional shorter summary for the sticky bar.
 *   defaults()          A new empty config.
 *   normalize( raw )    Any stored or server config, with every key present.
 *   card( { number, title, locked } )  The numbered collapsible card shell.
 *   insertAtCaret( input, text )       Inserts text at the caret.
 *   insertMenu( { label, target, items } )  Field select plus Insert button.
 *   usedOf( used, limit )              "3 of 8 used."
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, sprintf } = window.wp.i18n;
	const el = app.el;
	const B = ( app.builder = app.builder || {} );
	B.sections = B.sections || [];
	B.register = function ( def ) {
		B.sections.push( def );
		B.sections.sort( ( a, b ) => a.order - b.order );
	};

	const isObj = ( v ) => !! v && 'object' === typeof v && ! Array.isArray( v );
	const str = ( v ) => ( null === v || undefined === v ? '' : String( v ) );

	B.defaults = function () {
		return {
			source: '',
			fields: [],
			filters: { relation: 'AND', rules: [] },
			date_range: { field: '', preset: 'all_time', from: '', to: '' },
			format: 'csv',
			// Balanced speed. The server default is 500, which the old screen never sent.
			batch_size: 400,
			csv_delimiter: 'comma',
			column_labels: {},
			csv_encoding: 'utf8_bom',
			csv_eol: 'lf',
			xml_template: { header: '', row: '', footer: '' },
			transforms: {},
			custom_columns: [],
			network: false,
			csv_enclosure: 'double',
			csv_quote_all: false,
			compress: 'none',
		};
	};

	function normalizeTree( node ) {
		const group = isObj( node ) ? node : {};
		return {
			relation: 'OR' === str( group.relation ).toUpperCase() ? 'OR' : 'AND',
			rules: ( Array.isArray( group.rules ) ? group.rules : [] ).filter( isObj ).map( ( rule ) => (
				Array.isArray( rule.rules ) && undefined === rule.field
					? normalizeTree( rule )
					: { field: str( rule.field ), op: str( rule.op ) || 'eq', value: str( rule.value ) }
			) ),
		};
	}

	/**
	 * Every key present with the right type. PHP sends an empty map as [],
	 * so maps are rebuilt as objects. Unknown keys are kept, so a config from
	 * a newer server is not trimmed by this screen.
	 *
	 * @param {Object} raw Config from a template, job, recipe or preset.
	 * @return {Object} Config.
	 */
	B.normalize = function ( raw ) {
		const src = isObj( raw ) ? JSON.parse( JSON.stringify( raw ) ) : {};
		const out = Object.assign( B.defaults(), src );
		out.source = str( out.source );
		out.fields = ( Array.isArray( out.fields ) ? out.fields : [] ).map( str ).filter( Boolean );
		out.filters = normalizeTree( out.filters );
		out.date_range = Object.assign( B.defaults().date_range, isObj( out.date_range ) ? out.date_range : {} );
		out.column_labels = isObj( out.column_labels ) ? out.column_labels : {};
		out.transforms = isObj( out.transforms ) ? out.transforms : {};
		Object.keys( out.transforms ).forEach( ( id ) => {
			out.transforms[ id ] = ( Array.isArray( out.transforms[ id ] ) ? out.transforms[ id ] : [] ).filter( isObj );
			if ( ! out.transforms[ id ].length ) {
				delete out.transforms[ id ];
			}
		} );
		out.xml_template = Object.assign( B.defaults().xml_template, isObj( out.xml_template ) ? out.xml_template : {} );
		out.custom_columns = ( Array.isArray( out.custom_columns ) ? out.custom_columns : [] ).filter( isObj ).map( ( col ) => ( {
			id: str( col.id ),
			label: str( col.label ),
			template: str( col.template ),
		} ) );
		// ACF spread choices: kept only when present, so the key is never sent by a site without ACF fields.
		if ( Object.prototype.hasOwnProperty.call( out, 'acf_flatten' ) ) {
			out.acf_flatten = ( Array.isArray( out.acf_flatten ) ? out.acf_flatten : [] ).map( str ).filter( ( id ) => 0 === id.indexOf( 'acf:' ) );
			if ( ! out.acf_flatten.length ) {
				delete out.acf_flatten;
			}
		}
		out.batch_size = Number( out.batch_size ) || 400;
		out.network = true === out.network;
		out.csv_enclosure = 'single' === out.csv_enclosure ? 'single' : 'double';
		out.csv_quote_all = true === out.csv_quote_all;
		out.compress = 'zip' === out.compress ? 'zip' : 'none';
		return out;
	};

	/**
	 * The numbered card shell shared by the builder and the Export tab's Run
	 * card. Collapsed cards show a one-line summary. Locked cards cannot open.
	 *
	 * @param {Object} o number, title, locked (text shown while locked), level (heading tag, default h2).
	 * @return {Object} { root, head, body, setOpen, isOpen, setLocked, setDone, setSummary }.
	 */
	B.card = function ( o ) {
		const bodyId = app.uid( 'opex-card' );
		const summary = el( 'span', { class: 'opex-card-summary opex-summary-done' } );
		const locked = el( 'span', { class: 'opex-card-summary opex-summary-locked' }, o.locked || __( 'Choose a source first', 'open-export' ) );
		const doneText = el( 'span', { class: 'opex-sr', hidden: true }, __( '(complete)', 'open-export' ) );
		const head = el( 'button', { type: 'button', class: 'opex-card-head', 'aria-expanded': 'true', 'aria-controls': bodyId }, [
			el( 'span', { class: 'opex-marker', 'aria-hidden': 'true' }, [
				el( 'span', { class: 'opex-marker-num' }, String( o.number ) ),
				app.icon( 'tick', { sm: true, cls: 'opex-marker-check' } ),
			] ),
			el( 'span', { class: 'opex-card-title' }, [ o.title, ' ', doneText ] ),
			summary,
			locked,
			app.icon( 'chevron', { cls: 'opex-card-chev' } ),
		] );
		const body = el( 'div', { class: 'opex-card-body', id: bodyId } );
		const root = el( 'section', { class: 'opex-card opex-bcard' }, [ el( o.level || 'h2', { class: 'opex-card-h' }, head ), body ] );
		let open = true;
		let isLocked = false;
		function sync() {
			root.classList.toggle( 'is-collapsed', ! open );
			head.setAttribute( 'aria-expanded', open && ! isLocked ? 'true' : 'false' );
		}
		head.addEventListener( 'click', () => {
			open = ! open;
			sync();
		} );
		return {
			root,
			head,
			body,
			setOpen( value ) {
				open = !! value;
				sync();
			},
			isOpen: () => open && ! isLocked,
			setLocked( value ) {
				isLocked = !! value;
				root.classList.toggle( 'is-locked', isLocked );
				head.disabled = isLocked;
				sync();
			},
			setDone( value ) {
				root.classList.toggle( 'is-done', !! value );
				doneText.hidden = ! value;
			},
			setSummary( text ) {
				if ( summary.textContent !== text ) {
					summary.textContent = text;
				}
			},
		};
	};

	/**
	 * Inserts "{id}" at the caret of a text box and puts the caret after it.
	 * Used by every "Insert field" menu.
	 *
	 * @param {HTMLInputElement|HTMLTextAreaElement} input Target.
	 * @param {string}                               text  Text to insert.
	 */
	B.insertAtCaret = function ( input, text ) {
		const start = 'number' === typeof input.selectionStart ? input.selectionStart : input.value.length;
		const end = 'number' === typeof input.selectionEnd ? input.selectionEnd : start;
		input.value = input.value.slice( 0, start ) + text + input.value.slice( end );
		input.focus();
		input.setSelectionRange( start + text.length, start + text.length );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	};

	/**
	 * An "Insert field" menu: a field select and an Insert button. A button,
	 * not the select's change event, does the insert, because arrow keys
	 * change a closed select on some systems.
	 *
	 * @param {Object} o label (for screen readers), target() returns the text box, items() returns [ { id, label } ].
	 * @return {Object} { root, refresh() }.
	 */
	B.insertMenu = function ( o ) {
		const select = el( 'select', { 'aria-label': o.label } );
		const button = app.ui.button( {
			label: __( 'Insert', 'open-export' ),
			small: true,
			onClick: () => {
				const target = o.target();
				if ( select.value && target ) {
					B.insertAtCaret( target, '{' + select.value + '}' );
				}
			},
		} );
		function refresh() {
			const keep = select.value;
			app.clear( select );
			select.appendChild( el( 'option', { value: '' }, __( 'Insert field', 'open-export' ) ) );
			o.items().forEach( ( item ) => select.appendChild( el( 'option', { value: item.id }, item.label === item.id ? item.id : app.paren( item.label, item.id ) ) ) );
			select.value = keep;
			if ( select.value !== keep ) {
				select.value = '';
			}
		}
		refresh();
		return { root: el( 'span', { class: 'opex-inline opex-insert' }, [ select, button ] ), refresh };
	};

	/* translators: 1: used, 2: limit. */
	B.usedOf = ( used, limit ) => sprintf( __( '%1$s of %2$s used.', 'open-export' ), app.formatNumber( used ), app.formatNumber( limit ) );
}() );
