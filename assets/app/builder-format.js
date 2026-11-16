/**
 * Open Export admin screen: builder card 4, format.
 *
 * Formats come from GET /options `formats` ({ id: { label, available } }).
 * An unavailable one shows disabled with its reason. Only the chosen
 * format's options show: CSV delimiter, encoding (/options csv_encodings)
 * and line endings (/options csv_eols), or the XML template editor. The
 * template is checked through POST /preview, the only route that runs the
 * server's template validation without starting a job.
 *
 * Speed writes batch_size. The three choices are the old screen's batch
 * sizes. A loaded config with another size keeps it as a fourth choice.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, sprintf } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;
	const B = app.builder;

	const PARTS = [ 'header', 'row', 'footer' ];

	function speeds() {
		return [
			{ batch: 100, name: __( 'Gentle', 'open-export' ), desc: __( 'Slower. Best for shared hosting.', 'open-export' ) },
			{ batch: 400, name: __( 'Balanced', 'open-export' ), desc: __( 'A good fit for most sites.', 'open-export' ) },
			{ batch: 2000, name: __( 'Fast', 'open-export' ), desc: __( 'Uses more of the server.', 'open-export' ) },
		];
	}

	function describe( id ) {
		return {
			csv: __( 'Plain text rows. Opens in any spreadsheet.', 'open-export' ),
			json: __( 'Structured data for apps and scripts.', 'open-export' ),
			xml: __( 'Tagged data, with an optional template.', 'open-export' ),
			xlsx: __( 'A spreadsheet file for Excel and Numbers.', 'open-export' ),
		}[ id ] || '';
	}

	function unavailable( id ) {
		return 'xlsx' === id ? __( 'This server is missing the PHP zip extension.', 'open-export' ) : __( 'This server cannot write this format.', 'open-export' );
	}

	function choice( name, value, title, desc, opts ) {
		const o = opts || {};
		const reasonId = o.reason ? app.uid( 'opex-reason' ) : null;
		const input = el( 'input', { type: 'radio', name, value, disabled: !! o.reason, 'aria-describedby': reasonId } );
		const label = el( 'label', { class: 'opex-choice' }, [ input, el( 'span', {}, [
			el( 'span', { class: 'opex-choice-name' }, title ),
			desc ? el( 'span', { class: 'opex-choice-desc' }, desc ) : null,
			o.reason ? el( 'span', { class: 'opex-choice-reason', id: reasonId }, [ app.icon( 'warning', { sm: true, cls: 'opex-c-warning' } ), o.reason ] ) : null,
		] ) ] );
		return { label, input };
	}

	function build( ctx ) {
		const formats = ctx.options.formats || {};
		const formatName = app.uid( 'opex-format' );
		const speedName = app.uid( 'opex-speed' );
		const formatInputs = Object.keys( formats ).map( ( id ) => choice( formatName, id, formats[ id ].label, describe( id ), { reason: formats[ id ].available ? '' : unavailable( id ) } ) );
		formatInputs.forEach( ( c ) => c.input.addEventListener( 'change', () => {
			ctx.config.format = c.input.value;
			syncVisible();
			ctx.changed( 'format' );
		} ) );

		const select = ( items, key ) => {
			const node = el( 'select', {}, items.map( ( item ) => el( 'option', { value: item.id }, item.label ) ) );
			node.addEventListener( 'change', () => {
				ctx.config[ key ] = node.value;
				ctx.changed( 'format' );
			} );
			return node;
		};
		const delimiter = select( [
			{ id: 'comma', label: __( 'Comma', 'open-export' ) },
			{ id: 'semicolon', label: __( 'Semicolon', 'open-export' ) },
			{ id: 'tab', label: __( 'Tab', 'open-export' ) },
		], 'csv_delimiter' );
		const encoding = select( ctx.options.csv_encodings || [], 'csv_encoding' );
		const eol = select( ctx.options.csv_eols || [], 'csv_eol' );
		const csvBox = el( 'fieldset', { class: 'opex-optgroup' }, [
			el( 'legend', {}, __( 'CSV options', 'open-export' ) ),
			ui.field( { label: __( 'Delimiter', 'open-export' ), control: delimiter } ).root,
			ui.field( { label: __( 'Encoding', 'open-export' ), control: encoding } ).root,
			ui.field( { label: __( 'Line endings', 'open-export' ), control: eol } ).root,
		] );

		// XML template editor.
		const maxPart = Number( ctx.limits.xml_part ) || 20000;
		let stash = null;
		let lastArea = null;
		const useTemplate = el( 'input', { type: 'checkbox' } );
		const areas = {};
		const areaErrors = {};
		const areaBox = el( 'div', { class: 'opex-xml-areas', id: app.uid( 'opex-xml' ) } );
		useTemplate.setAttribute( 'aria-controls', areaBox.id );
		const partLabels = { header: __( 'Header', 'open-export' ), row: __( 'One row', 'open-export' ), footer: __( 'Footer', 'open-export' ) };
		PARTS.forEach( ( part ) => {
			const area = el( 'textarea', { class: 'opex-mono', rows: 'row' === part ? 5 : 2, id: app.uid( 'opex-xml-' + part ), spellcheck: 'false' } );
			const error = el( 'p', { class: 'opex-field-error', id: app.uid( 'opex-err' ), hidden: true } );
			area.addEventListener( 'focus', () => {
				lastArea = area;
			} );
			area.addEventListener( 'input', () => {
				ctx.config.xml_template[ part ] = area.value;
				checkPart( part );
				result.clear();
				ctx.changed( 'xml' );
			} );
			areas[ part ] = area;
			areaErrors[ part ] = error;
			app.append( areaBox, [ el( 'label', { class: 'opex-arealabel', for: area.id }, partLabels[ part ] ), area, error ] );
		} );
		const insert = B.insertMenu( {
			label: __( 'Insert field into the template', 'open-export' ),
			target: () => lastArea || areas.row,
			items: () => ctx.config.fields.map( ( id ) => ( { id, label: ctx.label( id ) } ) ),
		} );
		const result = ui.result();
		const errorBox = el( 'div', {} );
		const checkButton = ui.button( { label: __( 'Check template', 'open-export' ), onClick: checkTemplate } );
		app.append( areaBox, [
			el( 'p', { class: 'opex-help' }, __( 'Write the header once, the row once (it repeats for every record) and the footer once. Use {field id} for a value, or {field id|cdata} to wrap it in CDATA. Only chosen columns can be used.', 'open-export' ) ),
			el( 'div', { class: 'opex-inline opex-mt' }, [ insert.root, checkButton ] ),
			result.root,
			errorBox,
		] );
		const xmlBox = el( 'fieldset', { class: 'opex-optgroup' }, [
			el( 'legend', {}, __( 'XML options', 'open-export' ) ),
			el( 'label', { class: 'opex-checkline' }, [ useTemplate, __( 'Use a custom XML template', 'open-export' ) ] ),
			el( 'p', { class: 'opex-help' }, __( 'Without a template, each record is written in the default XML layout.', 'open-export' ) ),
			areaBox,
		] );

		// Turning the template off keeps the text in this screen, so turning it on again brings it back.
		useTemplate.addEventListener( 'change', () => {
			if ( useTemplate.checked ) {
				ctx.config.xml_template = stash || { header: '', row: '', footer: '' };
				stash = null;
			} else {
				stash = Object.assign( {}, ctx.config.xml_template );
				ctx.config.xml_template = { header: '', row: '', footer: '' };
			}
			syncXml();
			ctx.changed( 'xml' );
			if ( useTemplate.checked ) {
				areas.row.focus();
			}
		} );

		function checkPart( part ) {
			const value = ctx.config.xml_template[ part ] || '';
			/* translators: %s: most characters. */
			const text = value.length > maxPart ? sprintf( __( 'Use at most %s characters here.', 'open-export' ), app.formatNumber( maxPart ) ) : '';
			areaErrors[ part ].textContent = text;
			areaErrors[ part ].hidden = ! text;
			if ( text ) {
				areas[ part ].setAttribute( 'aria-invalid', 'true' );
				areas[ part ].setAttribute( 'aria-describedby', areaErrors[ part ].id );
			} else {
				areas[ part ].removeAttribute( 'aria-invalid' );
				areas[ part ].removeAttribute( 'aria-describedby' );
			}
			return text;
		}

		function checkTemplate() {
			app.clear( errorBox );
			if ( ! ctx.config.xml_template.row.trim() ) {
				result.set( 'error', __( 'Write the row template first.', 'open-export' ) );
				areas.row.focus();
				return;
			}
			checkButton.disabled = true;
			result.set( 'busy', __( 'Checking the template.', 'open-export' ) );
			app.api.post( '/preview', ctx.config ).then( ( answer ) => {
				/* translators: %s: "1,240 rows". */
				result.set( 'success', sprintf( __( 'The template is well-formed. %s match.', 'open-export' ), app.rows( answer && answer.total ) ) );
			} ).catch( ( err ) => {
				result.clear();
				if ( ! err.session ) {
					errorBox.appendChild( ui.notice( 'error', err.message, { tight: true, role: 'alert' } ) );
				}
			} ).then( () => {
				checkButton.disabled = false;
			} );
		}

		function syncXml() {
			const t = ctx.config.xml_template;
			const on = !! ( t.header || t.row || t.footer ) || ( useTemplate.checked && ! stash );
			useTemplate.checked = on;
			areaBox.hidden = ! on;
			PARTS.forEach( ( part ) => {
				if ( areas[ part ].value !== ( t[ part ] || '' ) ) {
					areas[ part ].value = t[ part ] || '';
				}
				checkPart( part );
			} );
			insert.refresh();
		}

		const speedList = speeds();
		const speedBox = el( 'div', { class: 'opex-speed' } );
		function renderSpeed() {
			app.clear( speedBox );
			const list = speedList.slice();
			if ( ! list.some( ( s ) => s.batch === ctx.config.batch_size ) ) {
				/* translators: %s: number of rows. */
				list.push( { batch: ctx.config.batch_size, name: __( 'As saved', 'open-export' ), desc: sprintf( __( '%s rows per batch.', 'open-export' ), app.formatNumber( ctx.config.batch_size ) ) } );
			}
			list.forEach( ( s ) => {
				const c = choice( speedName, String( s.batch ), s.name, s.desc );
				c.input.checked = s.batch === ctx.config.batch_size;
				c.input.addEventListener( 'change', () => {
					ctx.config.batch_size = s.batch;
					ctx.changed( 'speed' );
				} );
				speedBox.appendChild( c.label );
			} );
		}
		const speedFieldset = el( 'fieldset', { class: 'opex-optgroup' }, [
			el( 'legend', {}, __( 'Speed', 'open-export' ) ),
			speedBox,
			el( 'p', { class: 'opex-help' }, __( 'How many rows each step reads. Smaller steps are gentler on the server.', 'open-export' ) ),
		] );

		function syncVisible() {
			csvBox.hidden = 'csv' !== ctx.config.format;
			xmlBox.hidden = 'xml' !== ctx.config.format;
		}

		function syncAll() {
			formatInputs.forEach( ( c ) => {
				c.input.checked = c.input.value === ctx.config.format;
			} );
			delimiter.value = ctx.config.csv_delimiter;
			encoding.value = ctx.config.csv_encoding;
			eol.value = ctx.config.csv_eol;
			stash = null;
			useTemplate.checked = false;
			syncXml();
			syncVisible();
			renderSpeed();
		}

		return {
			root: el( 'div', {}, [
				el( 'fieldset', {}, [ el( 'legend', { class: 'opex-h3' }, __( 'File format', 'open-export' ) ), el( 'div', { class: 'opex-formats' }, formatInputs.map( ( c ) => c.label ) ) ] ),
				csvBox,
				xmlBox,
				speedFieldset,
			] ),
			refresh( reason ) {
				if ( 'load' === reason || 'source' === reason ) {
					syncAll();
				} else if ( 'fields' === reason || 'chosen' === reason || 'custom' === reason || 'fields-loaded' === reason ) {
					insert.refresh();
				}
			},
			done: () => !! ctx.config.source,
			short: () => ( formats[ ctx.config.format ] ? formats[ ctx.config.format ].label : ctx.config.format ),
			summary() {
				const c = ctx.config;
				const def = formats[ c.format ];
				const parts = [ def ? def.label : c.format ];
				if ( 'csv' === c.format ) {
					parts.push( delimiter.options[ delimiter.selectedIndex ] ? delimiter.options[ delimiter.selectedIndex ].text : c.csv_delimiter );
					const enc = ( ctx.options.csv_encodings || [] ).find( ( e ) => e.id === c.csv_encoding );
					parts.push( enc ? enc.label : c.csv_encoding );
				}
				if ( 'xml' === c.format && c.xml_template.row ) {
					parts.push( __( 'custom template', 'open-export' ) );
				}
				const speed = speedList.find( ( s ) => s.batch === c.batch_size );
				/* translators: %s: number of rows. */
				parts.push( speed ? speed.name : sprintf( __( '%s rows per batch', 'open-export' ), app.formatNumber( c.batch_size ) ) );
				return app.list( parts );
			},
			errors() {
				const list = [];
				const def = formats[ ctx.config.format ];
				if ( def && ! def.available ) {
					list.push( { message: unavailable( ctx.config.format ), focus: () => formatInputs[ 0 ].input.focus() } );
				}
				if ( 'xml' === ctx.config.format && useTemplate.checked ) {
					PARTS.forEach( ( part ) => {
						const text = checkPart( part );
						if ( text ) {
							list.push( { message: app.labeled( partLabels[ part ], text ), focus: () => areas[ part ].focus() } );
						}
					} );
					if ( ! ctx.config.xml_template.row.trim() ) {
						list.push( { message: __( 'Write the row template, or turn off the custom template.', 'open-export' ), focus: () => areas.row.focus() } );
					}
				}
				return list;
			},
		};
	}

	B.register( { id: 'format', order: 40, title: __( 'Format', 'open-export' ), needsSource: true, inSummary: true, build } );
}() );
