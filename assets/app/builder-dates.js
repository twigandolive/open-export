/**
 * Open Export admin screen: the date range row of the Filters card.
 *
 * Date fields, preset labels and the site time zone come from GET /fields
 * (date_fields, presets, timezone). Writes config.date_range =
 * { field, preset, from, to }. A source with no date field gets no control.
 * Relative presets stay relative in templates and schedules: the server
 * works out the dates when a job starts.
 *
 * Exposes opexApp.builder.dates( ctx, onChange ):
 *   Returns { root, refresh(), summary(), errors() }.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, sprintf } = window.wp.i18n;
	const el = app.el;
	const B = app.builder;
	const DAY = /^\d{4}-\d{2}-\d{2}$/;

	/**
	 * Builds the date range row of the Filters card: the date field, the preset and the custom dates.
	 *
	 * @param {Object}   ctx      Builder context.
	 * @param {Function} onChange Called with a reason after each change.
	 * @return {Object} { root, refresh(), summary(), errors() }.
	 */
	B.dates = function ( ctx, onChange ) {
		const fieldSelect = el( 'select', {} );
		const presetSelect = el( 'select', {} );
		const from = el( 'input', { type: 'date' } );
		const to = el( 'input', { type: 'date' } );
		const custom = el( 'span', { class: 'opex-inline' }, [
			el( 'label', { class: 'opex-mini' }, [ __( 'From', 'open-export' ), ' ', from ] ),
			el( 'label', { class: 'opex-mini' }, [ __( 'To', 'open-export' ), ' ', to ] ),
		] );
		const help = el( 'p', { class: 'opex-help' } );
		const none = el( 'p', { class: 'opex-help' }, __( 'This source has no date field, so it has no date range.', 'open-export' ) );
		const row = el( 'div', {}, [
			el( 'div', { class: 'opex-inline' }, [
				el( 'label', { class: 'opex-mini' }, [ __( 'Date field', 'open-export' ), ' ', fieldSelect ] ),
				el( 'label', { class: 'opex-mini' }, [ __( 'Range', 'open-export' ), ' ', presetSelect ] ),
				custom,
			] ),
			help,
		] );
		const root = el( 'div', {}, [ el( 'p', { class: 'opex-h3' }, __( 'Date range', 'open-export' ) ), row, none ] );

		const range = () => ctx.config.date_range;
		const dateFields = () => ( ctx.fields && ctx.fields.date_fields ) || [];
		const presets = () => ( ctx.fields && ctx.fields.presets ) || [];

		fieldSelect.addEventListener( 'change', () => {
			range().field = fieldSelect.value;
			onChange( 'date' );
		} );
		presetSelect.addEventListener( 'change', () => {
			range().preset = presetSelect.value;
			if ( 'custom' !== presetSelect.value ) {
				range().from = '';
				range().to = '';
			}
			syncCustom();
			onChange( 'date' );
		} );
		[ [ from, 'from' ], [ to, 'to' ] ].forEach( ( pair ) => pair[ 0 ].addEventListener( 'input', () => {
			range()[ pair[ 1 ] ] = pair[ 0 ].value;
			onChange( 'date' );
		} ) );

		// Shows the From and To dates only for a custom range.
		function syncCustom() {
			const isCustom = 'custom' === range().preset;
			custom.hidden = ! isCustom;
			from.value = range().from || '';
			to.value = range().to || '';
		}

		// Fills a select, keeping a saved value that is not in the list.
		function options( select, items, value ) {
			app.clear( select );
			items.forEach( ( item ) => select.appendChild( el( 'option', { value: item.id }, item.label ) ) );
			if ( value && ! items.some( ( item ) => item.id === value ) ) {
				select.appendChild( el( 'option', { value }, value ) );
			}
			select.value = value;
		}

		// Fills the date field and range selects for the chosen source.
		function refresh() {
			const fields = dateFields();
			row.hidden = ! fields.length;
			none.hidden = !! fields.length || ! ctx.fields;
			if ( ! fields.length ) {
				return;
			}
			if ( ! range().field ) {
				range().field = fields[ 0 ].id;
			}
			options( fieldSelect, fields, range().field );
			options( presetSelect, presets(), range().preset || 'all_time' );
			syncCustom();
			const zone = ( ctx.fields && ctx.fields.timezone ) || ctx.options.timezone || '';
			help.textContent = 'schedule' === ctx.mode
				/* translators: %s: site time zone, for example Europe/Berlin or +02:00. */
				? sprintf( __( 'Dates use the site time zone, %s. Open Export works out ranges such as Last 7 days again on every run.', 'open-export' ), zone )
				/* translators: %s: site time zone, for example Europe/Berlin or +02:00. */
				: sprintf( __( 'Dates use the site time zone, %s. Open Export works out ranges such as Last 7 days when the export starts. You can leave either custom date empty.', 'open-export' ), zone );
		}

		// Describes the date range for the card summary.
		function summary() {
			const r = range();
			if ( ! dateFields().length || ! r.preset || 'all_time' === r.preset ) {
				return '';
			}
			if ( 'custom' === r.preset ) {
				/* translators: 1: start date, 2: end date. */
				return sprintf( __( '%1$s to %2$s', 'open-export' ), r.from || __( 'any start', 'open-export' ), r.to || __( 'any end', 'open-export' ) );
			}
			const found = presets().find( ( p ) => p.id === r.preset );
			return found ? found.label : r.preset;
		}

		// Lists custom dates that are not written as YYYY-MM-DD.
		function errors() {
			const r = range();
			if ( 'custom' !== r.preset ) {
				return [];
			}
			const bad = [ [ from, r.from ], [ to, r.to ] ].find( ( pair ) => pair[ 1 ] && ! DAY.test( pair[ 1 ] ) );
			return bad ? [ { message: __( 'Enter dates as YYYY-MM-DD.', 'open-export' ), focus: () => bad[ 0 ].focus() } ] : [];
		}

		return { root, refresh, summary, errors };
	};
}() );
