/**
 * Open Export admin screen: the transform step editor for one column.
 *
 * Every step type, argument form and limit comes from GET /options
 * `transforms` (type => { label, args: { name: { type, label, max, min,
 * options, optional } } }) and `limits.transform_steps`. Arguments are
 * checked here, so the server never has to drop a step with a note.
 *
 * Two rules are not in /options and mirror OPEX_Transforms::sanitize_step:
 * "Find" may not be empty, and a date format may use only date letters and
 * a few separators. New number and shorten steps start with the server's
 * own defaults.
 *
 * Exposes opexApp.builder.transforms:
 *   panel( ctx, fieldId, onChange )  Returns { root, focusError( index, arg ) }.
 *   errors( ctx )        [ { fieldId, index, arg, message } ] for the whole config.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, sprintf } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;
	const B = app.builder;

		const DATE_CHARS = /^[dDjlNSwzWFmMntLoYyaABgGhHisuveIOPpTZcrU\\ \-/.,:;()#@_[\]]+$/;
	const REQUIRED = { 'find_replace.find': true, 'date_format.format': true };
	const START = { number: { decimals: 2, dec_point: '.', thousands_sep: ',' }, truncate: { length: 100 }, date_format: { format: 'Y-m-d' } };

	const types = ( ctx ) => ctx.options.transforms || {};
	const maxSteps = ( ctx ) => Number( ctx.limits.transform_steps ) || 8;

	/**
	 * Makes a new step of a type, with each argument at the server's starting value.
	 */
	function newStep( ctx, type ) {
		const def = types( ctx )[ type ] || { args: {} };
		const step = { type };
		Object.keys( def.args || {} ).forEach( ( name ) => {
			const arg = def.args[ name ];
			const start = ( START[ type ] || {} )[ name ];
			if ( 'select' === arg.type ) {
				step[ name ] = Object.keys( arg.options || {} )[ 0 ] || '';
			} else if ( 'map' === arg.type ) {
				step[ name ] = {};
			} else if ( ! arg.optional ) {
				step[ name ] = undefined === start ? '' : start;
			}
		} );
		return step;
	}

	/**
	 * Checks one step against its type's rules.
	 *
	 * @return {Object} Problems keyed by argument name. Empty when the step is fine.
	 */
	function check( step, ctx ) {
		const def = types( ctx )[ step.type ];
		const out = {};
		if ( ! def ) {
			out.type = __( 'This step type is not available on this site. Remove the step.', 'open-export' );
			return out;
		}
		const limits = ctx.limits;
		Object.keys( def.args || {} ).forEach( ( name ) => {
			const arg = def.args[ name ];
			const value = step[ name ];
			if ( 'text' === arg.type ) {
				const text = null === value || undefined === value ? '' : String( value );
				const max = Number( arg.max ) || Number( limits.transform_text ) || 500;
				if ( text.length > max ) {
					/* translators: 1: field label, 2: most characters. */
					out[ name ] = sprintf( __( '%1$s: use at most %2$s characters.', 'open-export' ), arg.label, app.formatNumber( max ) );
				} else if ( ! text && REQUIRED[ step.type + '.' + name ] ) {
					/* translators: %s: field label. */
					out[ name ] = sprintf( __( '%s: enter a value.', 'open-export' ), arg.label );
				} else if ( 'date_format' === step.type && text && ! DATE_CHARS.test( text ) ) {
					out[ name ] = __( 'PHP date format: use date letters such as Y-m-d H:i, with spaces and - / . , : as separators.', 'open-export' );
				}
			} else if ( 'int' === arg.type ) {
				const n = Number( value );
				const min = undefined === arg.min ? -Infinity : Number( arg.min );
				const max = undefined === arg.max ? Infinity : Number( arg.max );
				if ( '' === value || null === value || undefined === value || ! /^-?\d+$/.test( String( value ) ) || n < min || n > max ) {
					/* translators: 1: field label, 2: lowest number, 3: highest number. */
					out[ name ] = sprintf( __( '%1$s: enter a whole number from %2$s to %3$s.', 'open-export' ), arg.label, String( arg.min ), String( arg.max ) );
				}
			} else if ( 'select' === arg.type ) {
				if ( ! Object.prototype.hasOwnProperty.call( arg.options || {}, value ) ) {
					/* translators: %s: field label. */
					out[ name ] = sprintf( __( '%s: choose an option.', 'open-export' ), arg.label );
				}
			} else if ( 'map' === arg.type ) {
				const rows = B.pairs.rows( step, name ).filter( ( row ) => row[ 0 ] || row[ 1 ] );
				const max = Number( arg.max ) || Number( limits.map_pairs ) || 200;
				const seen = new Set();
				if ( rows.length > max ) {
					/* translators: %s: most pairs. */
					out[ name ] = sprintf( __( 'Use at most %s value pairs.', 'open-export' ), app.formatNumber( max ) );
				} else if ( rows.some( ( row ) => row[ 0 ].length > B.pairs.maxLength() || row[ 1 ].length > B.pairs.maxLength() ) ) {
					/* translators: %s: most characters. */
					out[ name ] = sprintf( __( 'Each value can have at most %s characters.', 'open-export' ), app.formatNumber( B.pairs.maxLength() ) );
				} else if ( rows.some( ( row ) => ( seen.has( row[ 0 ] ) ? true : ( seen.add( row[ 0 ] ), false ) ) ) ) {
					out[ name ] = __( 'Each value to replace can be listed only once.', 'open-export' );
				}
			}
		} );
		return out;
	}

	/**
	 * Collects the problems in every column's steps, so the screen can show them before the export starts.
	 */
	function errors( ctx ) {
		const list = [];
		const max = maxSteps( ctx );
		Object.keys( ctx.config.transforms ).forEach( ( fieldId ) => {
			if ( ctx.config.fields.indexOf( fieldId ) < 0 ) {
				return;
			}
			const steps = ctx.config.transforms[ fieldId ];
			if ( steps.length > max ) {
				/* translators: 1: column name, 2: most steps. */
				list.push( { fieldId, index: max, arg: 'type', message: sprintf( __( '%1$s has more than %2$d steps. Remove some.', 'open-export' ), ctx.label( fieldId ), max ) } );
			}
			steps.forEach( ( step, index ) => {
				const found = check( step, ctx );
				Object.keys( found ).forEach( ( arg ) => {
					/* translators: 1: column name, 2: step number, 3: the problem. */
					list.push( { fieldId, index, arg, message: sprintf( __( '%1$s, step %2$d: %3$s', 'open-export' ), ctx.label( fieldId ), index + 1, found[ arg ] ) } );
				} );
			} );
		} );
		return list;
	}

	/**
	 * Builds the select that lists the step types, keeping an unknown value visible.
	 */
	function typeSelect( ctx, value, label ) {
		const select = el( 'select', { 'aria-label': label } );
		const defs = types( ctx );
		Object.keys( defs ).forEach( ( id ) => select.appendChild( el( 'option', { value: id }, defs[ id ].label ) ) );
		if ( value && ! defs[ value ] ) {
			select.appendChild( el( 'option', { value }, value ) );
		}
		select.value = value;
		return select;
	}

	/**
	 * Builds the step editor of one column: its steps, their arguments, and add, move and remove buttons.
	 *
	 * @param {Object}   ctx      Builder context.
	 * @param {string}   fieldId  Column the steps belong to.
	 * @param {Function} onChange Called after each change.
	 */
	function panel( ctx, fieldId, onChange ) {
		const root = el( 'div', { class: 'opex-transform' } );
		const count = el( 'p', { class: 'opex-help opex-steps-count' } );
		const list = el( 'ol', { class: 'opex-steps' } );
		const reasonId = app.uid( 'opex-reason' );
		const addType = typeSelect( ctx, Object.keys( types( ctx ) )[ 0 ] || '', __( 'Step type to add', 'open-export' ) );
		const reason = el( 'span', { class: 'opex-reason', id: reasonId, hidden: true } );
		const add = ui.button( { label: __( 'Add step', 'open-export' ), small: true, icon: 'plus', action: 'add-step', describedBy: reasonId, onClick: () => {
			const steps = stepsList( true );
			if ( steps.length >= maxSteps( ctx ) || ! addType.value ) {
				return;
			}
			steps.push( newStep( ctx, addType.value ) );
			redraw( steps.length - 1, 'type' );
		} } );
		/* translators: %s: column name. */
		app.append( root, [ el( 'p', { class: 'opex-h3' }, sprintf( __( 'Transform steps for %s', 'open-export' ), ctx.label( fieldId ) ) ), count, list, el( 'div', { class: 'opex-inline' }, [ addType, add, reason ] ) ] );

		// Gets the steps of the column, making the list when asked to.
		function stepsList( create ) {
			if ( ! ctx.config.transforms[ fieldId ] && create ) {
				ctx.config.transforms[ fieldId ] = [];
			}
			return ctx.config.transforms[ fieldId ] || [];
		}

		// Removes an empty steps list from the config.
		function tidy() {
			if ( ctx.config.transforms[ fieldId ] && ! ctx.config.transforms[ fieldId ].length ) {
				delete ctx.config.transforms[ fieldId ];
			}
		}

		// Structural change: rebuild the list, then focus a control in it.
		function redraw( index, what ) {
			tidy();
			render();
			onChange( 'transforms' );
			const items = list.children;
			const item = items[ Math.min( index, items.length - 1 ) ];
			const target = item ? item.querySelector( '[data-step="' + what + '"]' ) || item.querySelector( 'select' ) : add;
			( target || add ).focus();
		}

		// Draws the steps of the column and the add controls.
		function render() {
			app.clear( list );
			const steps = stepsList( false );
			steps.forEach( ( step, index ) => list.appendChild( stepItem( step, index, steps ) ) );
			const max = maxSteps( ctx );
			/* translators: %s: "3 of 8 used." */
			count.textContent = sprintf( __( 'Steps run from top to bottom. Steps: %s', 'open-export' ), B.usedOf( steps.length, max ) );
			add.disabled = steps.length >= max;
			reason.hidden = ! add.disabled;
			/* translators: %d: most steps. */
			reason.textContent = sprintf( __( 'A column can have at most %d steps.', 'open-export' ), max );
		}

		// Builds one step with its arguments and its move and remove buttons.
		function stepItem( step, index, steps ) {
			const n = index + 1;
			const errorLine = el( 'p', { class: 'opex-field-error', id: app.uid( 'opex-err' ), hidden: true } );
			const body = el( 'div', { class: 'opex-step-body' } );
			const controls = {};
			/* translators: %d: step number. */
			const type = typeSelect( ctx, step.type, sprintf( __( 'Step %d type', 'open-export' ), n ) );
			type.setAttribute( 'data-step', 'type' );
			controls.type = type;
			type.addEventListener( 'change', () => {
				steps[ index ] = newStep( ctx, type.value );
				redraw( index, 'type' );
			} );
			body.appendChild( type );
			const def = types( ctx )[ step.type ];
			Object.keys( ( def && def.args ) || {} ).forEach( ( name ) => {
				const arg = def.args[ name ];
				const control = 'map' === arg.type ? B.pairs.editor( step, name, n, validate ) : argInput( step, name, arg, validate );
				controls[ name ] = control.input;
				body.appendChild( control.root );
			} );
			body.appendChild( errorLine );

			// Marks the arguments of the step that have a problem.
			function validate() {
				const found = check( step, ctx );
				Object.keys( controls ).forEach( ( name ) => {
					const input = controls[ name ];
					if ( found[ name ] ) {
						input.setAttribute( 'aria-invalid', 'true' );
						input.setAttribute( 'aria-describedby', errorLine.id );
					} else {
						input.removeAttribute( 'aria-invalid' );
						input.removeAttribute( 'aria-describedby' );
					}
				} );
				const text = Object.keys( found ).map( ( k ) => found[ k ] ).join( ' ' );
				errorLine.textContent = text;
				errorLine.hidden = ! text;
				onChange( 'transform-value' );
			}

			// Moves the step to a new position and draws the list again.
			const move = ( to ) => {
				const moved = steps.splice( index, 1 )[ 0 ];
				steps.splice( to, 0, moved );
				redraw( to, to < index ? 'up' : 'down' );
			};
			const icon = ( name, label, disabled, fn ) => el( 'button', { type: 'button', class: 'opex-iconbtn', 'aria-label': label, 'data-step': name, disabled, onClick: fn }, app.icon( name === 'remove' ? 'close' : name, { sm: true } ) );
			const item = el( 'li', { class: 'opex-step' }, [
				el( 'span', { class: 'opex-step-num', 'aria-hidden': 'true' }, String( n ) ),
				body,
				el( 'div', { class: 'opex-step-actions' }, [
					/* translators: %d: step number. */
					icon( 'up', sprintf( __( 'Move step %d up', 'open-export' ), n ), 0 === index, () => move( index - 1 ) ),
					/* translators: %d: step number. */
					icon( 'down', sprintf( __( 'Move step %d down', 'open-export' ), n ), index === steps.length - 1, () => move( index + 1 ) ),
					/* translators: %d: step number. */
					icon( 'remove', sprintf( __( 'Remove step %d', 'open-export' ), n ), false, () => {
						steps.splice( index, 1 );
						redraw( index, 'remove' );
					} ),
				] ),
			] );
			// Show problems at once for steps that arrived from a template or recipe.
			if ( Object.keys( check( step, ctx ) ).length ) {
				window.setTimeout( validate, 0 );
			}
			return item;
		}

		// Builds the input for one argument of a step.
		function argInput( step, name, arg, validate ) {
			let input;
			if ( 'select' === arg.type ) {
				input = el( 'select', {} );
				Object.keys( arg.options || {} ).forEach( ( key ) => input.appendChild( el( 'option', { value: key }, arg.options[ key ] ) ) );
				input.value = step[ name ];
			} else if ( 'int' === arg.type ) {
				input = el( 'input', { type: 'number', class: 'opex-w-num', min: arg.min, max: arg.max, step: 1, value: undefined === step[ name ] ? '' : String( step[ name ] ) } );
			} else {
				input = el( 'input', { type: 'text', class: 'date_format' === step.type ? 'opex-w-sm opex-mono' : 'opex-w-sm', value: undefined === step[ name ] ? '' : String( step[ name ] ) } );
			}
			input.setAttribute( 'data-step', name );
			input.addEventListener( 'select' === arg.type ? 'change' : 'input', () => {
				const raw = input.value;
				if ( 'int' === arg.type ) {
					step[ name ] = /^-?\d+$/.test( raw ) ? Number( raw ) : raw;
				} else if ( arg.optional && '' === raw ) {
					delete step[ name ];
				} else {
					step[ name ] = raw;
				}
				validate();
			} );
			/* translators: %s: field label. */
			const text = arg.optional ? sprintf( __( '%s (optional)', 'open-export' ), arg.label ) : arg.label;
			return { root: el( 'label', { class: 'opex-mini' }, [ text, ' ', input ] ), input };
		}

		render();
		return {
			root,
			focusError( index, arg ) {
				const item = list.children[ index ];
				const target = item && ( item.querySelector( '[data-step="' + arg + '"]' ) || item.querySelector( 'select' ) );
				( target || add ).focus();
			},
		};
	}

	B.transforms = { panel, errors };
}() );
