/**
 * Open Export admin screen: the inline SVG icon set.
 *
 * Shapes come from the approved blueprint. They are stroke icons on a 20 by
 * 20 grid that take the text color, so the CSS color tokens apply.
 *
 * Exposes:
 *   opexApp.icon( name, opts )  An <svg>. opts.sm for 16px, opts.label for a
 *                               meaningful icon (role img), opts.cls for extra classes.
 *                               Without a label the icon is hidden from screen readers.
 *   opexApp.icons               The shape table, so a later stage can add icons.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const NS = 'http://www.w3.org/2000/svg';

	// Each icon is a list of [ tag, attributes ]. A plain string is a path.
	const icons = {
		tick: [ 'M4.5 10.5l3.5 3.5 7.5-8' ],
		check: [ [ 'circle', { cx: 10, cy: 10, r: 7.5 } ], 'M6.6 10.2l2.4 2.4 4.5-4.9' ],
		warning: [ 'M10 3L18 16.5H2z', 'M10 8v4M10 14.2v.3' ],
		error: [ [ 'circle', { cx: 10, cy: 10, r: 7.5 } ], 'M7.5 7.5l5 5M12.5 7.5l-5 5' ],
		info: [ [ 'circle', { cx: 10, cy: 10, r: 7.5 } ], 'M10 9v5M10 6.2v.3' ],
		clock: [ [ 'circle', { cx: 10, cy: 10, r: 7.5 } ], 'M10 6v4.2l2.8 1.6' ],
		sync: [ 'M16 8A6.2 6.2 0 0 0 4.5 6.5M4 12a6.2 6.2 0 0 0 11.5 1.5', 'M4 3.5v3.3h3.3M16 16.5v-3.3h-3.3' ],
		play: [ 'M6.5 4.5l9 5.5-9 5.5z' ],
		pause: [ [ 'circle', { cx: 10, cy: 10, r: 7.5 } ], 'M8.2 7.5v5M11.8 7.5v5' ],
		download: [ 'M10 3.5v9M6.2 9l3.8 3.8L13.8 9M4 16.5h12' ],
		chevron: [ 'M5 7.5l5 5 5-5' ],
		up: [ 'M10 16V4M5.5 8.5L10 4l4.5 4.5' ],
		down: [ 'M10 4v12M5.5 11.5L10 16l4.5-4.5' ],
		close: [ 'M5 5l10 10M15 5L5 15' ],
		mail: [ [ 'rect', { x: 2.5, y: 4.5, width: 15, height: 11, rx: 1.5 } ], 'M3 5.5l7 5.5 7-5.5' ],
		link: [ 'M8.5 11.5l3-3M7.5 8l-1.8 1.8a2.8 2.8 0 0 0 4 4L11.5 12M12.5 12l1.8-1.8a2.8 2.8 0 0 0-4-4L8.5 8' ],
		server: [ [ 'rect', { x: 3, y: 3.5, width: 14, height: 5, rx: 1 } ], [ 'rect', { x: 3, y: 11.5, width: 14, height: 5, rx: 1 } ], 'M6 6h.5M6 14h.5' ],
		copy: [ [ 'rect', { x: 7, y: 7, width: 9.5, height: 9.5, rx: 1.5 } ], 'M13 7V4.5a1 1 0 0 0-1-1H4.5a1 1 0 0 0-1 1V12a1 1 0 0 0 1 1H7' ],
		trash: [ 'M4 5.5h12M8 5.5V3.5h4v2M5.5 5.5l.8 11h7.4l.8-11M8.5 8.5v5M11.5 8.5v5' ],
		plus: [ 'M10 4v12M4 10h12' ],
		lock: [ [ 'rect', { x: 4.5, y: 9, width: 11, height: 8, rx: 1.5 } ], 'M7 9V6.5a3 3 0 0 1 6 0V9' ],
		search: [ [ 'circle', { cx: 8.5, cy: 8.5, r: 5 } ], 'M12.3 12.3L17 17' ],
		doc: [ 'M5 2.5h6.5L15 6v11.5H5z', 'M11.5 2.5V6H15M7.5 10h5M7.5 13h5' ],
		grip: [ [ 7.5, 5 ], [ 12.5, 5 ], [ 7.5, 10 ], [ 12.5, 10 ], [ 7.5, 15 ], [ 12.5, 15 ] ].map( ( c ) => [ 'circle', { cx: c[ 0 ], cy: c[ 1 ], r: 1.2, fill: 'currentColor', stroke: 'none' } ] ),
	};

	app.icons = icons;

	/**
	 * Builds an inline SVG icon. Without a label it is hidden from screen readers.
	 *
	 * @param {string} name Icon name.
	 * @param {Object} opts sm for the small size, cls for extra classes, label for a spoken name.
	 * @return {Element} The SVG element.
	 */
	app.icon = function ( name, opts ) {
		const o = opts || {};
		const svg = document.createElementNS( NS, 'svg' );
		svg.setAttribute( 'viewBox', '0 0 20 20' );
		svg.setAttribute( 'class', [ 'opex-i', o.sm ? 'opex-i-sm' : '', o.cls || '' ].join( ' ' ).trim() );
		svg.setAttribute( 'focusable', 'false' );
		if ( o.label ) {
			svg.setAttribute( 'role', 'img' );
			svg.setAttribute( 'aria-label', o.label );
		} else {
			svg.setAttribute( 'aria-hidden', 'true' );
		}
		( icons[ name ] || [] ).forEach( ( shape ) => {
			const def = 'string' === typeof shape ? [ 'path', { d: shape } ] : shape;
			const node = document.createElementNS( NS, def[ 0 ] );
			Object.keys( def[ 1 ] ).forEach( ( key ) => node.setAttribute( key, String( def[ 1 ][ key ] ) ) );
			svg.appendChild( node );
		} );
		return svg;
	};
}() );
