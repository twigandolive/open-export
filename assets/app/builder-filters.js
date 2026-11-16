/**
 * Open Export admin screen: builder card 3, filters.
 *
 * The date range row (builder-dates.js) and the nested filter tree:
 * config.filters = { relation: 'AND'|'OR', rules: [ rule | group ] }, rule
 * = { field, op, value }. Depth and rule limits come from /options
 * `limits` (filter_depth counts the top group as 1, filter_rules counts
 * rules, not groups). Operators and their labels come from /options
 * `operators`. A field's `value_type` from /fields narrows the operator
 * list. Without it, every operator is offered.
 *
 * Typing in a value never redraws. Adding or removing a rule or group
 * redraws the tree and moves focus to the new or next control.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, _n, sprintf } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;
	const B = app.builder;

	// Which operators make sense for each value type. A loaded rule keeps its
	// operator even when it is not in the list, so nothing is lost silently.
	const BY_TYPE = {
		number: [ 'eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'empty', 'not_empty' ],
		date: [ 'eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'contains', 'starts_with', 'empty', 'not_empty' ],
		bool: [ 'eq', 'neq', 'empty', 'not_empty' ],
		json: [ 'contains', 'not_contains', 'empty', 'not_empty' ],
	};

	/**
	 * Lists the operators to offer when the server sent none.
	 */
	function fallbackOps() {
		return [
			[ 'eq', __( 'equals', 'open-export' ) ], [ 'neq', __( 'does not equal', 'open-export' ) ],
			[ 'contains', __( 'contains', 'open-export' ) ], [ 'not_contains', __( 'does not contain', 'open-export' ) ],
			[ 'starts_with', __( 'starts with', 'open-export' ) ], [ 'ends_with', __( 'ends with', 'open-export' ) ],
			[ 'gt', '>' ], [ 'gte', '>=' ], [ 'lt', '<' ], [ 'lte', '<=' ],
			[ 'in', __( 'is one of (comma list)', 'open-export' ) ], [ 'not_in', __( 'is not one of (comma list)', 'open-export' ) ],
			[ 'empty', __( 'is empty', 'open-export' ) ], [ 'not_empty', __( 'is not empty', 'open-export' ) ],
		].map( ( pair ) => ( { id: pair[ 0 ], label: pair[ 1 ], needs_value: 'empty' !== pair[ 0 ] && 'not_empty' !== pair[ 0 ] } ) );
	}

	const isGroup = ( node ) => Array.isArray( node.rules ) && undefined === node.field;

	/**
	 * Counts the rules in a group and every group inside it, for the rule limit.
	 */
	function countRules( group ) {
		return group.rules.reduce( ( n, node ) => n + ( isGroup( node ) ? countRules( node ) : 1 ), 0 );
	}

	/**
	 * Builds the Filters card: the date range and the nested rule tree.
	 *
	 * @param {Object} ctx Builder context.
	 */
	function build( ctx ) {
		const dates = B.dates( ctx, ( reason ) => ctx.changed( reason ) );
		const tree = el( 'div', {} );
		const limitsLine = el( 'p', { class: 'opex-limits' } );
		const rulesHeading = el( 'p', { class: 'opex-h3 opex-rules-head' }, __( 'Rules', 'open-export' ) );
		const nodeEls = new WeakMap();
		const maxDepth = Number( ctx.limits.filter_depth ) || 3;
		const maxRules = Number( ctx.limits.filter_rules ) || 50;

		// /options sends the operators. An older server does not, and then the screen uses its own list.
		function operators() {
			const list = ( ctx.options.operators || [] ).filter( ( op ) => op && op.id );
			return list.length ? list : fallbackOps();
		}

		// The server marks each field `filterable`. An older server does not, and
		// there computed fields are the ones a rule cannot use, except roles.
		function canFilter( f ) {
			if ( 'boolean' === typeof f.filterable ) {
				return f.filterable;
			}
			return 0 !== f.id.indexOf( 'computed:' ) || 'computed:roles' === f.id;
		}

		function filterable() {
			const groups = [];
			( ( ctx.fields && ctx.fields.groups ) || [] ).forEach( ( group ) => {
				const fields = ( group.fields || [] ).filter( canFilter );
				if ( fields.length ) {
					groups.push( { label: group.label, fields } );
				}
			} );
			return groups;
		}

		function isFilterable( id ) {
			return filterable().some( ( group ) => group.fields.some( ( f ) => f.id === id ) );
		}

		function opsFor( rule ) {
			const info = ctx.fieldIndex[ rule.field ];
			const allowed = info && BY_TYPE[ info.type ];
			const list = operators().filter( ( op ) => ! allowed || allowed.indexOf( op.id ) > -1 || op.id === rule.op );
			if ( rule.op && ! list.some( ( op ) => op.id === rule.op ) ) {
				list.push( { id: rule.op, label: rule.op, needs_value: true } );
			}
			return list;
		}

		function needsValue( op ) {
			const found = operators().find( ( item ) => item.id === op );
			return found ? false !== found.needs_value : true;
		}

		function newRule() {
			const first = filterable()[ 0 ];
			const field = first ? first.fields[ 0 ].id : '';
			const rule = { field, op: 'eq', value: '' };
			const ops = opsFor( { field, op: '' } );
			rule.op = ops.some( ( op ) => 'eq' === op.id ) ? 'eq' : ( ops[ 0 ] ? ops[ 0 ].id : 'eq' );
			return rule;
		}

		// Redraw, report, then focus the first select of a node or a group button.
		function redraw( node, button ) {
			render();
			ctx.changed( 'filters' );
			const box = node ? nodeEls.get( node ) : null;
			let target = null;
			if ( box && button ) {
				target = box.querySelector( '[data-filter="' + button + '"]' );
			} else if ( box ) {
				target = box.querySelector( 'select' );
			}
			( target || tree.querySelector( '[data-filter="add-rule"]' ) ).focus();
		}

		function ruleRow( rule, group, index, label ) {
			/* translators: %s: rule number. */
			const field = el( 'select', { 'aria-label': sprintf( __( 'Rule %s field', 'open-export' ), label ) } );
			filterable().forEach( ( set ) => field.appendChild( el( 'optgroup', { label: set.label }, set.fields.map( ( f ) => el( 'option', { value: f.id }, f.label ) ) ) ) );
			if ( rule.field && ! isFilterable( rule.field ) ) {
				/* translators: %s: field id. */
				field.appendChild( el( 'option', { value: rule.field }, sprintf( __( '%s (cannot filter)', 'open-export' ), rule.field ) ) );
			}
			field.value = rule.field;
			/* translators: %s: rule number. */
			const op = el( 'select', { 'aria-label': sprintf( __( 'Rule %s operator', 'open-export' ), label ) } );
			/* translators: %s: rule number. */
			const value = el( 'input', { type: 'text', value: rule.value, 'aria-label': sprintf( __( 'Rule %s value', 'open-export' ), label ) } );
			const fillOps = () => {
				app.clear( op );
				const list = opsFor( rule );
				list.forEach( ( item ) => op.appendChild( el( 'option', { value: item.id }, item.label ) ) );
				if ( ! list.some( ( item ) => item.id === rule.op ) && list[ 0 ] ) {
					rule.op = list[ 0 ].id;
				}
				op.value = rule.op;
				syncValue();
			};
			const syncValue = () => {
				const needed = needsValue( rule.op );
				value.hidden = ! needed;
				value.placeholder = 'in' === rule.op || 'not_in' === rule.op ? __( 'a, b, c', 'open-export' ) : '';
				if ( ! needed ) {
					rule.value = '';
					value.value = '';
				}
			};
			field.addEventListener( 'change', () => {
				rule.field = field.value;
				fillOps();
				ctx.changed( 'filter-value' );
			} );
			op.addEventListener( 'change', () => {
				rule.op = op.value;
				syncValue();
				ctx.changed( 'filter-value' );
			} );
			value.addEventListener( 'input', () => {
				rule.value = value.value;
				ctx.changed( 'filter-value' );
			} );
			fillOps();
			/* translators: %s: rule number. */
			const remove = el( 'button', { type: 'button', class: 'opex-iconbtn', 'aria-label': sprintf( __( 'Remove rule %s', 'open-export' ), label ), onClick: () => {
				group.rules.splice( index, 1 );
				const next = group.rules[ index ] || group.rules[ index - 1 ];
				redraw( next || group, next ? null : 'add-rule' );
			} }, app.icon( 'close', { sm: true } ) );
			const row = el( 'div', { class: 'opex-rule' }, [ field, op, el( 'div', {}, value ), remove ] );
			nodeEls.set( rule, row );
			return row;
		}

		function groupBox( group, depth, parent, index, prefix ) {
			const name = app.uid( 'opex-rel' );
			/* translators: %d: nesting level. */
			const seg = el( 'div', { class: 'opex-seg', role: 'radiogroup', 'aria-label': sprintf( __( 'Match mode for level %d group', 'open-export' ), depth ) } );
			[ [ 'AND', __( 'All of (AND)', 'open-export' ) ], [ 'OR', __( 'Any of (OR)', 'open-export' ) ] ].forEach( ( pair ) => {
				const input = el( 'input', { class: 'opex-sr', type: 'radio', name, value: pair[ 0 ], checked: group.relation === pair[ 0 ] } );
				input.addEventListener( 'change', () => {
					group.relation = pair[ 0 ];
					ctx.changed( 'filter-value' );
				} );
				seg.appendChild( el( 'label', {}, [ input, el( 'span', {}, pair[ 1 ] ) ] ) );
			} );
			const head = el( 'div', { class: 'opex-fgroup-head' }, [ el( 'span', { class: 'opex-fgroup-title' }, __( 'Match', 'open-export' ) ), seg ] );
			if ( parent ) {
				/* translators: %d: nesting level. */
				head.appendChild( el( 'button', { type: 'button', class: 'opex-iconbtn opex-push', 'aria-label': sprintf( __( 'Remove group at level %d', 'open-export' ), depth ), onClick: () => {
					parent.rules.splice( index, 1 );
					redraw( parent, 'add-group' );
				} }, app.icon( 'trash', { sm: true } ) ) );
			}
			/* translators: %d: nesting level. */
			const box = el( 'div', { class: 'opex-fgroup', role: 'group', 'aria-label': sprintf( __( 'Filter group, level %d', 'open-export' ), depth ) }, head );
			let n = 0;
			group.rules.forEach( ( node, i ) => {
				if ( isGroup( node ) ) {
					box.appendChild( groupBox( node, depth + 1, group, i, prefix ) );
				} else {
					n += 1;
					box.appendChild( ruleRow( node, group, i, prefix + n ) );
				}
			} );
			const full = countRules( ctx.config.filters ) >= maxRules;
			const deep = depth >= maxDepth;
			const noFields = ! filterable().length;
			const ruleReason = el( 'span', { class: 'opex-reason', id: app.uid( 'opex-reason' ) } );
			const groupReason = el( 'span', { class: 'opex-reason', id: app.uid( 'opex-reason' ) } );
			/* translators: %d: most rules. */
			ruleReason.textContent = full ? sprintf( __( 'You have used all %d rules.', 'open-export' ), maxRules ) : ( noFields ? __( 'This source has no fields to filter on.', 'open-export' ) : '' );
			/* translators: %d: most levels. */
			groupReason.textContent = deep ? sprintf( __( 'Groups can nest %d levels deep.', 'open-export' ), maxDepth ) : '';
			const addRule = ui.button( { label: __( 'Add rule', 'open-export' ), small: true, disabled: full || noFields, describedBy: ruleReason.id, onClick: () => {
				const rule = newRule();
				group.rules.push( rule );
				redraw( rule );
			} } );
			addRule.setAttribute( 'data-filter', 'add-rule' );
			const addGroup = ui.button( { label: __( 'Add group', 'open-export' ), small: true, disabled: full || deep || noFields, describedBy: groupReason.id, onClick: () => {
				const rule = newRule();
				group.rules.push( { relation: 'AND' === group.relation ? 'OR' : 'AND', rules: [ rule ] } );
				redraw( rule );
			} } );
			addGroup.setAttribute( 'data-filter', 'add-group' );
			box.appendChild( el( 'div', { class: 'opex-fgroup-foot' }, [ addRule, addGroup, ruleReason, groupReason ] ) );
			nodeEls.set( group, box );
			return box;
		}

		function render() {
			app.clear( tree );
			if ( ! ctx.fields ) {
				return;
			}
			tree.appendChild( groupBox( ctx.config.filters, 1, null, 0, '' ) );
			const used = countRules( ctx.config.filters );
			/* translators: 1: "2 of 50 used.", 2: most levels. */
			limitsLine.textContent = sprintf( __( 'Rules: %1$s Groups can nest %2$d levels deep.', 'open-export' ), B.usedOf( used, maxRules ), maxDepth );
		}

		function badRules( group, out ) {
			group.rules.forEach( ( node ) => {
				if ( isGroup( node ) ) {
					badRules( node, out );
				} else if ( ! isFilterable( node.field ) ) {
					out.push( node );
				}
			} );
			return out;
		}

		// Date range and rule count. Empty when nothing filters, so the sticky bar skips it.
		function short() {
			const n = countRules( ctx.config.filters );
			/* translators: %s: number of filter rules. */
			const rules = n ? sprintf( _n( '%s filter', '%s filters', n, 'open-export' ), app.formatNumber( n ) ) : '';
			return app.list( [ dates.summary(), rules ] );
		}

		return {
			root: el( 'div', {}, [ dates.root, rulesHeading, tree, limitsLine ] ),
			refresh( reason ) {
				if ( [ 'load', 'source', 'fields-loaded', 'fields-error' ].indexOf( reason ) > -1 ) {
					dates.refresh();
					render();
				}
			},
			done: () => !! ctx.config.source && !! ctx.fields,
			short,
			summary: () => short() || __( 'No filters', 'open-export' ),
			errors() {
				const list = dates.errors();
				if ( ctx.fields ) {
					badRules( ctx.config.filters, [] ).forEach( ( rule ) => list.push( {
						/* translators: %s: field id. */
						message: rule.field ? sprintf( __( 'This source cannot filter on %s. Pick another field or remove the rule.', 'open-export' ), rule.field ) : __( 'A filter rule has no field.', 'open-export' ),
						focus: () => {
							const row = nodeEls.get( rule );
							if ( row ) {
								row.querySelector( 'select' ).focus();
							}
						},
					} ) );
				}
				return list;
			},
		};
	}

	B.register( { id: 'filters', order: 30, title: __( 'Filters', 'open-export' ), needsSource: true, inSummary: true, build } );
}() );
