/**
 * Open Export admin screen: the export builder (shell).
 *
 * One reusable component that reads and writes ONE export config in the
 * shape OPEX_Config::sanitize_config accepts. The Export tab mounts it now,
 * the Schedules tab will mount the same thing, so nothing here knows which
 * tab it lives in.
 *
 * Each card (source, columns, filters, format, preview) is a section file
 * that calls opexApp.builder.register( def ). A section builds its DOM once
 * and rebuilds only the part a structural change touched, so typing never
 * loses focus or the caret.
 *
 * Exposes opexApp.builder:
 *   mount( container, { config, onChange, mode, level } )  mode: 'export' or 'schedule'.
 *       level is the card heading tag (default h2), so the cards fit the page's heading order.
 *       Returns { getConfig(), setConfig( config ), validate(), summary(),
 *       sourceLabel(), previewTotal(), ready }. onChange( config ) fires
 *       after every change with a copy of the config.
 *   Config helpers, the card shell and register() live in builder-parts.js.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __ } = window.wp.i18n;
	const api = app.api;
	const store = app.store;
	const B = app.builder;

	const fieldCache = new Map();

	/**
	 * Loads a source's fields once and keeps the answer, so switching back to a source does not ask the server again. A failed load is forgotten so it can be tried again.
	 *
	 * @return {Promise} Resolves with the /fields answer.
	 */
	function loadFields( source ) {
		if ( ! fieldCache.has( source ) ) {
			fieldCache.set( source, api.get( '/fields?source=' + encodeURIComponent( source ) ).catch( ( err ) => {
				fieldCache.delete( source );
				throw err;
			} ) );
		}
		return fieldCache.get( source );
	}

	/**
	 * Loads the source list once and keeps it in the store.
	 *
	 * @return {Promise} Resolves with the source groups.
	 */
	function loadSources() {
		if ( store.get( 'sources' ) ) {
			return Promise.resolve( store.get( 'sources' ) );
		}
		return api.get( '/sources' ).then( ( list ) => store.set( 'sources', Array.isArray( list ) ? list : [] ) );
	}

	/**
	 * Mounts the builder in a container and returns the controls the tab uses to read and change the config.
	 *
	 * @param {Element} container Where the cards go.
	 * @param {Object}  opts      Config, mode and callbacks.
	 */
	B.mount = function ( container, opts ) {
		const o = opts || {};
		const options = store.get( 'options' ) || {};
		const views = [];
		let fieldsTicket = 0;

		const ctx = {
			mode: 'schedule' === o.mode ? 'schedule' : 'export',
			options,
			limits: options.limits || {},
			config: B.normalize( o.config ),
			sources: null,
			sourcesError: null,
			fields: null,
			fieldsError: null,
			fieldIndex: {},
			preview: null,
			/** Item and group of a source id, or null. */
			source( id ) {
				for ( const group of ctx.sources || [] ) {
					const item = ( group.items || [] ).find( ( it ) => it.id === id );
					if ( item ) {
						return { item, group };
					}
				}
				return null;
			},
			/** Label for any column id: a field, a custom column, or the id itself. */
			label( id ) {
				const custom = ctx.config.custom_columns.find( ( col ) => col.id === id );
				if ( custom ) {
					return custom.label || __( 'Custom column', 'open-export' );
				}
				return ctx.fieldIndex[ id ] ? ctx.fieldIndex[ id ].label : id;
			},
			isCustom: ( id ) => ctx.config.custom_columns.some( ( col ) => col.id === id ),
			setSource,
			changed,
			retryFields: () => fetchFields( false ),
		};
		// Hoisted: sections such as Preview check the whole config first.
		ctx.validate = validate;

		function indexFields() {
			ctx.fieldIndex = {};
			( ( ctx.fields && ctx.fields.groups ) || [] ).forEach( ( group, i ) => {
				const key = group.id ? String( group.id ) : 'group-' + i;
				( group.fields || [] ).forEach( ( field ) => {
					// value_type is the screen vocabulary (text, number, date, bool, json).
					// The older `type` key means something else and is never read.
					ctx.fieldIndex[ field.id ] = { id: field.id, label: field.label || field.id, type: field.value_type || '', group: key };
				} );
			} );
		}

		// Fields of a source arrive after a request. A later pick wins.
		function fetchFields( preselect ) {
			const ticket = ++fieldsTicket;
			ctx.fields = null;
			ctx.fieldsError = null;
			indexFields();
			if ( ! ctx.config.source ) {
				return Promise.resolve();
			}
			return loadFields( ctx.config.source ).then( ( data ) => {
				if ( ticket !== fieldsTicket ) {
					return;
				}
				ctx.fields = data || {};
				indexFields();
				if ( preselect && ! ctx.config.fields.length ) {
					// Start with the first group, found by position, never by its translated label.
					const first = ( ctx.fields.groups || [] )[ 0 ];
					ctx.config.fields = first ? ( first.fields || [] ).map( ( f ) => f.id ) : [];
				}
				if ( preselect && ( ctx.fields.date_fields || [] ).length && ! ctx.config.date_range.field ) {
					ctx.config.date_range.field = ctx.fields.date_fields[ 0 ].id;
				}
				changed( 'fields-loaded' );
			} ).catch( ( err ) => {
				if ( ticket === fieldsTicket ) {
					ctx.fieldsError = err;
					changed( 'fields-error' );
				}
			} );
		}

		/**
		 * A new source makes every field id stale, so everything that points
		 * at fields starts over. Format choices stay.
		 *
		 * The old source's field list is cleared before the cards hear of the
		 * change (fetchFields does that first, synchronously). Otherwise the
		 * date range row would fill the empty date field from the old source,
		 * and the new source's default would never replace it.
		 *
		 * @param {string} id Source id.
		 */
		function setSource( id ) {
			const keep = ctx.config;
			ctx.config = Object.assign( B.defaults(), {
				source: id,
				format: keep.format,
				batch_size: keep.batch_size,
				csv_delimiter: keep.csv_delimiter,
				csv_encoding: keep.csv_encoding,
				csv_eol: keep.csv_eol,
				csv_enclosure: keep.csv_enclosure,
				csv_quote_all: keep.csv_quote_all,
				compress: keep.compress,
				xml_template: keep.xml_template,
				network: keep.network,
			} );
			ctx.preview = null;
			fetchFields( true );
			changed( 'source' );
		}

		function getConfig() {
			return JSON.parse( JSON.stringify( ctx.config ) );
		}

		function changed( reason ) {
			views.forEach( ( view ) => {
				if ( view.refresh ) {
					view.refresh( reason );
				}
			} );
			syncCards();
			if ( o.onChange ) {
				o.onChange( getConfig(), reason );
			}
		}

		function syncCards() {
			const hasSource = !! ctx.config.source;
			views.forEach( ( view ) => {
				view.card.setLocked( view.def.needsSource && ! hasSource );
				view.card.setDone( !! ( view.done && view.done() ) );
				view.card.setSummary( view.summary ? view.summary() : '' );
			} );
		}

		B.sections.forEach( ( def, i ) => {
			const card = B.card( { number: i + 1, title: def.title, level: o.level } );
			const view = def.build( ctx ) || {};
			view.def = def;
			view.card = card;
			card.body.appendChild( view.root );
			container.appendChild( card.root );
			views.push( view );
		} );

		const ready = loadSources().then( ( list ) => {
			ctx.sources = list;
			changed( 'sources' );
		} ).catch( ( err ) => {
			ctx.sourcesError = err;
			changed( 'sources' );
		} );
		changed( 'load' );
		if ( ctx.config.source ) {
			fetchFields( false );
		}

		function setConfig( config ) {
			ctx.config = B.normalize( config );
			ctx.preview = null;
			changed( 'load' );
			return fetchFields( false );
		}

		/**
		 * Collects what the server would drop or refuse, opens the card of the
		 * first problem and focuses it.
		 *
		 * @return {Array} Problems as { message, focus }.
		 */
		function validate() {
			const problems = [];
			views.forEach( ( view ) => {
				( view.errors ? view.errors() : [] ).forEach( ( problem ) => problems.push( Object.assign( { card: view.card }, problem ) ) );
			} );
			if ( problems.length ) {
				problems[ 0 ].card.setOpen( true );
				if ( problems[ 0 ].focus ) {
					problems[ 0 ].focus();
				}
			}
			return problems;
		}

		function sourceLabel() {
			const found = ctx.source( ctx.config.source );
			return found ? found.item.label : ctx.config.source;
		}

		function summary() {
			if ( ! ctx.config.source ) {
				return __( 'No source chosen yet', 'open-export' );
			}
			const parts = [ sourceLabel() ];
			views.forEach( ( view ) => {
				if ( view.def.inSummary && ( view.short || view.summary ) ) {
					parts.push( view.short ? view.short() : view.summary() );
				}
			} );
			return app.list( parts );
		}

		function previewTotal() {
			return ctx.preview && ctx.preview.key === JSON.stringify( ctx.config ) ? ctx.preview.total : null;
		}

		return { getConfig, setConfig, validate, summary, sourceLabel, previewTotal, ready, ctx };
	};
}() );
