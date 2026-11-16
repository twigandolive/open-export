/**
 * Open Export admin screen: custom columns (label plus a template).
 *
 * A custom column is { id, label, template }. The template is literal text
 * with {field id} placeholders. The id is made once, when the column is
 * added, and never changes on rename, because fields, transforms and
 * column_labels point at it. It matches custom:[a-z0-9-]+, which the server
 * keeps as long as it is unique.
 *
 * Limits come from /options: limits.custom_columns, limits.custom_label and
 * limits.custom_template.
 *
 * Exposes opexApp.builder.custom:
 *   addForm( ctx, onAdded )             The "Add a custom column" box. Returns { root, refresh() }.
 *   templateEditor( ctx, col, onChange ) Template box for an added column. Returns { root, input }.
 *   errors( ctx )                       [ { id, what, message } ] for every custom column.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, sprintf } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;
	const B = app.builder;

	const maxColumns = ( ctx ) => Number( ctx.limits.custom_columns ) || 50;
	const maxLabel = ( ctx ) => Number( ctx.limits.custom_label ) || 100;
	const maxTemplate = ( ctx ) => Number( ctx.limits.custom_template ) || 500;

	function newId( label, ctx ) {
		const base = 'custom:' + ( String( label ).toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-+|-+$/g, '' ).slice( 0, 40 ) || 'column' );
		const taken = new Set( ctx.config.custom_columns.map( ( col ) => col.id ).concat( ctx.config.fields ) );
		let id = base;
		for ( let n = 2; taken.has( id ); n++ ) {
			id = base + '-' + n;
		}
		return id;
	}

	function checkLabel( label, ctx ) {
		if ( ! label.trim() ) {
			return __( 'Give the column a label.', 'open-export' );
		}
		/* translators: %s: most characters. */
		return label.length > maxLabel( ctx ) ? sprintf( __( 'Use at most %s characters for the label.', 'open-export' ), app.formatNumber( maxLabel( ctx ) ) ) : '';
	}

	function checkTemplate( template, ctx ) {
		if ( ! template.trim() ) {
			return __( 'Write a template, for example {core:post_title}.', 'open-export' );
		}
		/* translators: %s: most characters. */
		return template.length > maxTemplate( ctx ) ? sprintf( __( 'Use at most %s characters for the template.', 'open-export' ), app.formatNumber( maxTemplate( ctx ) ) ) : '';
	}

	/**
	 * Placeholders the server will leave as plain text: unknown fields and
	 * other custom columns. A warning, not an error, as on the server.
	 *
	 * @param {string} template Template.
	 * @param {Object} ctx      Builder context.
	 * @return {string} Warning or "".
	 */
	function warning( template, ctx ) {
		if ( ! ctx.fields ) {
			return '';
		}
		const bad = [];
		( template.match( /\{([^{}]+)\}/g ) || [] ).forEach( ( token ) => {
			const id = token.slice( 1, -1 );
			if ( ! ctx.fieldIndex[ id ] && bad.indexOf( id ) < 0 ) {
				bad.push( id );
			}
		} );
		/* translators: %s: list of placeholders. */
		return bad.length ? sprintf( __( 'These stay as plain text because they are not fields of this source: %s. A custom column cannot use another custom column.', 'open-export' ), bad.join( ', ' ) ) : '';
	}

	function fieldItems( ctx ) {
		return Object.keys( ctx.fieldIndex ).map( ( id ) => ctx.fieldIndex[ id ] );
	}

	function errors( ctx ) {
		const list = [];
		ctx.config.custom_columns.forEach( ( col ) => {
			const name = col.label || col.id;
			const label = checkLabel( col.label, ctx );
			const template = checkTemplate( col.template, ctx );
			if ( label ) {
				list.push( { id: col.id, what: 'label', message: name + ': ' + label } );
			}
			if ( template ) {
				list.push( { id: col.id, what: 'template', message: name + ': ' + template } );
			}
		} );
		if ( ctx.config.custom_columns.length > maxColumns( ctx ) ) {
			/* translators: %d: most custom columns. */
			list.push( { id: '', what: 'count', message: sprintf( __( 'Use at most %d custom columns.', 'open-export' ), maxColumns( ctx ) ) } );
		}
		return list;
	}

	function templateBox( ctx, value, describedBy ) {
		const input = el( 'input', { type: 'text', class: 'opex-mono opex-w-full', value } );
		const warn = el( 'p', { class: 'opex-help opex-warnline', id: app.uid( 'opex-warn' ), hidden: true } );
		const insert = B.insertMenu( { label: __( 'Insert field into template', 'open-export' ), target: () => input, items: () => fieldItems( ctx ) } );
		input.setAttribute( 'aria-describedby', [ describedBy, warn.id ].filter( Boolean ).join( ' ' ) );
		function sync() {
			const text = warning( input.value, ctx );
			warn.textContent = text;
			warn.hidden = ! text;
		}
		input.addEventListener( 'input', sync );
		sync();
		return { input, warn, insert, sync };
	}

	function templateEditor( ctx, col, onChange ) {
		const error = el( 'p', { class: 'opex-field-error', id: app.uid( 'opex-err' ), hidden: true } );
		const box = templateBox( ctx, col.template, error.id );
		box.input.setAttribute( 'data-custom', 'template' );
		box.input.addEventListener( 'input', () => {
			col.template = box.input.value;
			const problem = checkTemplate( col.template, ctx );
			error.textContent = problem;
			error.hidden = ! problem;
			if ( problem ) {
				box.input.setAttribute( 'aria-invalid', 'true' );
			} else {
				box.input.removeAttribute( 'aria-invalid' );
			}
			onChange( 'custom-value' );
		} );
		const label = el( 'label', { class: 'opex-h3', for: box.input.id || ( box.input.id = app.uid( 'opex-f' ) ) }, __( 'Template', 'open-export' ) );
		return { root: el( 'div', { class: 'opex-custom-edit' }, [ label, box.input, error, box.warn, el( 'div', { class: 'opex-mt' }, box.insert.root ) ] ), input: box.input };
	}

	function addForm( ctx, onAdded ) {
		const result = ui.result();
		const labelInput = el( 'input', { type: 'text', class: 'opex-w-md' } );
		const labelField = ui.field( { label: __( 'Label', 'open-export' ), control: labelInput } );
		const helpId = app.uid( 'opex-help' );
		const help = el( 'p', { class: 'opex-help', id: helpId } );
		const templateError = el( 'p', { class: 'opex-field-error', id: app.uid( 'opex-err' ), hidden: true } );
		const box = templateBox( ctx, '', helpId + ' ' + templateError.id );
		box.input.id = app.uid( 'opex-f' );
		const reasonId = app.uid( 'opex-reason' );
		const reason = el( 'span', { class: 'opex-reason', id: reasonId, hidden: true } );
		const addButton = ui.button( { label: __( 'Add column', 'open-export' ), describedBy: reasonId, onClick: add } );

		function setTemplateError( text ) {
			templateError.textContent = text;
			templateError.hidden = ! text;
			if ( text ) {
				box.input.setAttribute( 'aria-invalid', 'true' );
			} else {
				box.input.removeAttribute( 'aria-invalid' );
			}
		}

		function add() {
			const label = labelInput.value;
			const template = box.input.value;
			const labelProblem = checkLabel( label, ctx );
			const templateProblem = checkTemplate( template, ctx );
			labelField.setError( labelProblem );
			setTemplateError( templateProblem );
			if ( labelProblem || templateProblem ) {
				( labelProblem ? labelInput : box.input ).focus();
				return;
			}
			const id = newId( label, ctx );
			ctx.config.custom_columns.push( { id, label: label.trim(), template } );
			ctx.config.fields.push( id );
			labelInput.value = '';
			box.input.value = '';
			box.sync();
			/* translators: %s: column label. */
			result.set( 'success', sprintf( __( 'Added the column %s.', 'open-export' ), label.trim() ) );
			onAdded( id );
			labelInput.focus();
		}

		function refresh() {
			const used = ctx.config.custom_columns.length;
			const max = maxColumns( ctx );
			addButton.disabled = used >= max;
			reason.hidden = ! addButton.disabled;
			/* translators: %d: most custom columns. */
			reason.textContent = sprintf( __( 'You have used all %d custom columns.', 'open-export' ), max );
			/* translators: 1: example template, 2: "1 of 50 used." */
			help.textContent = sprintf( __( 'Use {field id} placeholders, for example %1$s. Custom columns: %2$s', 'open-export' ), '{core:post_title} - {core:post_date}', B.usedOf( used, max ) );
			box.insert.refresh();
			box.sync();
		}

		const root = el( 'div', { class: 'opex-custom' }, [
			el( 'p', { class: 'opex-h3' }, __( 'Add a custom column', 'open-export' ) ),
			labelField.root,
			el( 'div', { class: 'opex-row' }, [
				el( 'label', { for: box.input.id }, __( 'Template', 'open-export' ) ),
				el( 'div', { class: 'opex-rowcontrol' }, [
					box.input,
					templateError,
					box.warn,
					el( 'div', { class: 'opex-inline opex-mt' }, [ box.insert.root, addButton, reason ] ),
					help,
					result.root,
				] ),
			] ),
		] );
		refresh();
		return { root, refresh };
	}

	B.custom = { addForm, templateEditor, errors, checkLabel };
}() );
