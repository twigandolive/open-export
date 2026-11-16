/**
 * Open Export admin screen: the "Use an AI recipe" panel of the Export tab.
 *
 * Copy or download the capability document (GET /schema?source=&scope=),
 * paste the recipe an assistant wrote, and POST /recipe. The answer is
 * { ok, report, config, name, notes }. When ok, the config goes into the
 * builder and the name into the export name. The route never starts an
 * export. When not ok, only the report shows.
 *
 * Exposes opexApp.exportRecipe( { source(), onApply( config, name ) } ):
 *   Returns { toggle, panel, open( focus ) }.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, _n, sprintf } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;

	/**
	 * Removes the code fences an assistant often wraps around a recipe.
	 */
	function stripFences( text ) {
		return String( text ).trim().replace( /^```[a-zA-Z]*\s*/, '' ).replace( /\s*```$/, '' ).trim();
	}

	/**
	 * Adds every rule of a report, nested groups included, to one list.
	 *
	 * @param {Array} rows Report rows, some of them groups.
	 * @param {Array} out  Receives the rules.
	 */
	function flattenRules( rows, out ) {
		( Array.isArray( rows ) ? rows : [] ).forEach( ( row ) => {
			if ( row && row.group ) {
				flattenRules( row.rules, out );
			} else if ( row ) {
				out.push( row );
			}
		} );
		return out;
	}

	/**
	 * Describes one field of the recipe report: its id, its label and what was used instead.
	 */
	function fieldText( row ) {
		let text = String( row.id || '' );
		if ( row.label && row.label !== row.id ) {
			text = app.paren( text, row.label );
		}
		if ( row.used_instead ) {
			/* translators: 1: field, 2: field id used instead. */
			text = sprintf( __( '%1$s, used %2$s instead', 'open-export' ), text, row.used_instead );
		}
		if ( row.reason ) {
			text = app.labeled( text, row.reason );
		}
		if ( row.suggestion ) {
			/* translators: 1: field and why it was dropped, 2: suggested field id. */
			text = sprintf( __( '%1$s Did you mean %2$s?', 'open-export' ), text, row.suggestion );
		}
		return text;
	}

	/**
	 * Builds the list that says which fields, filters and dates of a recipe were used, changed or dropped.
	 */
	function reportList( answer ) {
		const report = ( answer && answer.report ) || {};
		const lines = [];
		// Adds one line of the result for a list of fields.
		const add = ( icon, cls, name, items ) => {
			if ( items.length ) {
				lines.push( el( 'li', {}, [
					el( 'span', { class: 'opex-report-name' }, [ app.icon( icon, { sm: true, cls } ), name ] ),
					el( 'span', {}, items.join( '; ' ) ),
				] ) );
			}
		};
		const fields = Array.isArray( report.fields ) ? report.fields : [];
		const by = ( status ) => fields.filter( ( r ) => r && r.status === status ).map( fieldText );
		add( 'check', 'opex-c-success', __( 'Matched', 'open-export' ), by( 'matched' ) );
		add( 'info', 'opex-c-info', __( 'Substituted', 'open-export' ), by( 'substituted' ) );
		add( 'warning', 'opex-c-warning', __( 'Dropped', 'open-export' ), by( 'dropped' ) );
		const rules = flattenRules( report.filters, [] );
		const kept = rules.filter( ( r ) => 'matched' === r.status ).length;
		if ( kept ) {
			/* translators: %d: number of filter rules. */
			add( 'check', 'opex-c-success', __( 'Filters', 'open-export' ), [ sprintf( _n( '%d rule kept', '%d rules kept', kept, 'open-export' ), kept ) ] );
		}
		add( 'warning', 'opex-c-warning', __( 'Filters dropped', 'open-export' ), rules.filter( ( r ) => 'dropped' === r.status ).map( ( r ) => {
			const rule = [ r.field, r.op ].filter( Boolean ).join( ' ' );
			return r.reason ? app.labeled( rule, r.reason ) : rule;
		} ) );
		const range = report.date_range;
		if ( range && range.status && 'matched' !== range.status ) {
			add( 'info', 'opex-c-info', __( 'Date range', 'open-export' ), [ fieldText( Object.assign( { id: range.field }, range ) ) ] );
		}
		const notes = Array.isArray( answer && answer.notes ) ? answer.notes : [];
		add( 'info', 'opex-c-info', __( 'Notes', 'open-export' ), notes.map( String ) );
		if ( ! answer.ok && report.reason ) {
			add( 'error', 'opex-c-error', __( 'Not applied', 'open-export' ), [ String( report.reason ) ] );
		}
		return lines.length ? el( 'ul', {}, lines ) : null;
	}

	/**
	 * Builds the AI recipe panel: copy or download the capability document, paste a recipe and apply it.
	 *
	 * @param {Object} o Callbacks: source() and onApply( config, name ).
	 * @return {Object} { toggle, panel, open( focus ) }.
	 */
	app.exportRecipe = function ( o ) {
		const panelId = app.uid( 'opex-recipe' );
		const toggle = el( 'button', { type: 'button', class: 'opex-link', 'aria-expanded': 'false', 'aria-controls': panelId }, __( 'Use an AI recipe', 'open-export' ) );
		const scopeName = app.uid( 'opex-scope' );
		const scopes = [ [ 'source', __( 'The chosen source', 'open-export' ) ], [ 'all', __( 'Every source (large on a busy site)', 'open-export' ) ] ].map( ( pair, i ) => {
			const input = el( 'input', { type: 'radio', name: scopeName, value: pair[ 0 ], checked: 0 === i } );
			return { input, root: el( 'label', { class: 'opex-checkline' }, [ input, pair[ 1 ] ] ) };
		} );
		const schemaResult = ui.result();
		const text = el( 'textarea', { class: 'opex-mono', rows: 6, id: app.uid( 'opex-f' ), placeholder: __( 'Paste the recipe here', 'open-export' ), spellcheck: 'false' } );
		const applyResult = ui.result();
		const report = el( 'div', { class: 'opex-report', role: 'status', 'aria-label': __( 'Recipe result', 'open-export' ), hidden: true } );
		const close = el( 'button', { type: 'button', class: 'opex-iconbtn', 'aria-label': __( 'Close AI recipe panel', 'open-export' ) }, app.icon( 'close', { sm: true } ) );
		const copy = ui.button( { label: __( 'Copy site schema', 'open-export' ), icon: 'copy', onClick: () => schema( 'copy' ) } );
		const download = ui.button( { label: __( 'Download schema', 'open-export' ), icon: 'download', onClick: () => schema( 'download' ) } );
		const apply = ui.button( { label: __( 'Apply recipe', 'open-export' ), primary: true, onClick: applyRecipe } );
		const scopeLabel = app.uid( 'opex-l' );

		const panel = el( 'div', { class: 'opex-recipe', id: panelId, hidden: true }, [
			el( 'div', { class: 'opex-recipe-head' }, [ el( 'h2', { class: 'opex-h2 opex-m0' }, __( 'Use an AI recipe', 'open-export' ) ), close ] ),
			el( 'p', {}, __( 'Copy your site schema into an AI assistant, ask it for an export recipe, then paste the recipe here. Applying a recipe fills in the setup below. It does not start an export.', 'open-export' ) ),
			el( 'div', { class: 'opex-mt', role: 'radiogroup', 'aria-labelledby': scopeLabel }, [ el( 'span', { class: 'opex-rowlabel', id: scopeLabel }, __( 'Describe', 'open-export' ) ), scopes.map( ( s ) => s.root ) ] ),
			el( 'div', { class: 'opex-inline opex-mt' }, [ copy, download ] ),
			schemaResult.root,
			el( 'label', { class: 'opex-rowlabel opex-mt-lg', for: text.id }, __( 'Recipe', 'open-export' ) ),
			text,
			el( 'div', { class: 'opex-inline opex-mt' }, [ apply ] ),
			applyResult.root,
			report,
		] );

		// Opens or closes the AI recipe panel.
		function setOpen( open, focus ) {
			panel.hidden = ! open;
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			if ( focus ) {
				( open ? text : toggle ).focus();
			}
		}
		// Opening moves focus into the paste box. Closing leaves it on the toggle.
		toggle.addEventListener( 'click', () => setOpen( panel.hidden, panel.hidden ) );
		close.addEventListener( 'click', () => setOpen( false, true ) );

		// Returns which sources the schema describes.
		function scope() {
			const picked = scopes.find( ( s ) => s.input.checked );
			return picked ? picked.input.value : 'source';
		}

		// Gets the site schema, then copies or downloads it.
		function schema( action ) {
			const source = o.source();
			const all = 'all' === scope();
			if ( ! all && ! source ) {
				schemaResult.set( 'error', __( 'Choose a source first, or describe every source.', 'open-export' ) );
				return;
			}
			schemaResult.set( 'busy', __( 'Getting the schema.', 'open-export' ) );
			app.api.get( '/schema?scope=' + ( all ? 'all' : 'source' ) + '&source=' + encodeURIComponent( all ? '' : source ) ).then( ( doc ) => {
				const json = JSON.stringify( doc, null, 2 );
				if ( 'download' === action ) {
					const url = URL.createObjectURL( new Blob( [ json ], { type: 'application/json' } ) );
					const link = el( 'a', { href: url, download: 'open-export-schema.json', hidden: true } );
					document.body.appendChild( link );
					link.click();
					link.remove();
					window.setTimeout( () => URL.revokeObjectURL( url ), 1000 );
					schemaResult.set( 'success', __( 'Schema downloaded as open-export-schema.json.', 'open-export' ) );
					return null;
				}
				return navigator.clipboard.writeText( json ).then( () => {
					schemaResult.set( 'success', __( 'Schema copied. Paste it into your AI assistant.', 'open-export' ) );
				} );
			} ).catch( ( err ) => {
				if ( err && err.session ) {
					return;
				}
				schemaResult.set( 'error', err && err.message && err.code ? err.message : __( 'The browser did not allow copying. Use Download schema instead.', 'open-export' ) );
			} );
		}

		// Reads the pasted recipe, sends it to the server and shows the result.
		function applyRecipe() {
			app.clear( report );
			report.hidden = true;
			let recipe = null;
			try {
				recipe = JSON.parse( stripFences( text.value ) );
			} catch ( e ) {
				recipe = null;
			}
			if ( ! recipe || 'object' !== typeof recipe || Array.isArray( recipe ) ) {
				applyResult.set( 'error', __( 'That is not valid JSON. Paste the whole object, including its outer braces.', 'open-export' ) );
				text.focus();
				return;
			}
			apply.disabled = true;
			applyResult.set( 'busy', __( 'Reading the recipe.', 'open-export' ) );
			app.api.post( '/recipe', recipe ).then( ( answer ) => {
				const list = reportList( answer || {} );
				if ( list ) {
					report.appendChild( list );
					report.hidden = false;
				}
				if ( answer && answer.ok && answer.config ) {
					const config = Object.assign( {}, answer.config );
					// A recipe that names no speed gets the screen's default speed,
					// not the server's own fallback size, which no speed choice uses.
					if ( ! Object.prototype.hasOwnProperty.call( recipe, 'batch_size' ) ) {
						config.batch_size = app.builder.defaults().batch_size;
					}
					o.onApply( config, answer.name || '' );
					applyResult.set( 'success', __( 'Recipe applied. Check the setup below, then start the export.', 'open-export' ) );
				} else {
					applyResult.set( 'error', __( 'The recipe was not applied. See the result below.', 'open-export' ) );
				}
			} ).catch( ( err ) => {
				if ( ! err.session ) {
					applyResult.set( 'error', err.message );
				}
			} ).then( () => {
				apply.disabled = false;
			} );
		}

		return { toggle, panel, open: ( focus ) => setOpen( true, focus ) };
	};
}() );
