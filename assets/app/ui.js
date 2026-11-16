/**
 * Open Export admin screen: small shared parts (part one of two).
 *
 * Every tab builds its screen from these, so cards, notices and status
 * badges look and behave the same everywhere. Status is always an icon plus
 * a text label, never color alone.
 *
 * Exposes on opexApp.ui:
 *   button( { label, primary, small, icon, onClick, disabled, describedBy } )
 *   linkButton( { label, danger, onClick, disabled, describedBy } )  A text button styled as a link.
 *   notice( type, message, { role, action, tight } )  type: success, warning, error, info.
 *   status( kind, label )   kind: running, paused, done, sent, ok, failed, waiting.
 *   bar( percent, kind, label )  A progress bar. kind: '', paused, done, failed.
 *   card( { title, level } )     Returns { root, body }.
 *   field( { label, control, help, rowLabel } )  A labeled form row. Returns { root, setError( msg ) }.
 *   switchControl( { label, checked, onChange } ) Returns { root, input }.
 *   result()                A live result line. Returns { root, set( kind, text ), clear() }.
 *   skeleton( lines )       A loading placeholder.
 *   empty( { title, text, action } )  An empty state.
 *   loadError( error, onRetry )      An error notice with a Try again button.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __ } = window.wp.i18n;
	const el = app.el;
	const ui = ( app.ui = app.ui || {} );

	/**
	 * Builds a button from options: label, icon, style, action and click handler.
	 */
	ui.button = function ( o ) {
		const cls = [ 'opex-btn', o.primary ? 'opex-btn-primary' : '', o.small ? 'opex-btn-sm' : '', o.cls || '' ].join( ' ' ).trim();
		return el( 'button', {
			type: o.type || 'button',
			class: cls,
			disabled: !! o.disabled,
			'aria-describedby': o.describedBy,
			'data-action': o.action,
			onClick: o.onClick,
		}, [ o.icon ? app.icon( o.icon, { sm: true } ) : null, o.label ] );
	};

	/**
	 * Builds a button that looks like a link.
	 */
	ui.linkButton = function ( o ) {
		return el( 'button', {
			type: 'button',
			class: 'opex-link' + ( o.danger ? ' opex-link-danger' : '' ),
			disabled: !! o.disabled,
			'aria-disabled': o.ariaDisabled ? 'true' : null,
			'aria-describedby': o.describedBy,
			'aria-label': o.ariaLabel,
			'data-action': o.action,
			onClick: o.onClick,
		}, o.label );
	};

	const NOTICE_ICONS = { success: 'check', warning: 'warning', error: 'error', info: 'info' };

	/**
	 * Gives the spoken name of a notice's icon, which tells screen reader users what kind of notice it is.
	 */
	function noticeLabel( type ) {
		if ( 'success' === type ) {
			return __( 'Success', 'open-export' );
		}
		if ( 'warning' === type ) {
			return __( 'Warning', 'open-export' );
		}
		if ( 'error' === type ) {
			return __( 'Error', 'open-export' );
		}
		return __( 'Information', 'open-export' );
	}

	/**
	 * Builds a notice with an icon, a message and an optional action button.
	 *
	 * @param {string} type    success, warning, error or info.
	 * @param {string} message Text to show.
	 * @param {Object} opts    tight, role and action.
	 */
	ui.notice = function ( type, message, opts ) {
		const o = opts || {};
		const kind = NOTICE_ICONS[ type ] ? type : 'info';
		const body = el( 'div', { class: 'opex-notice-body' }, [
			el( 'p', {}, message ),
			o.action ? el( 'p', {}, ui.button( { label: o.action.label, small: true, onClick: o.action.onClick } ) ) : null,
		] );
		return el( 'div', {
			class: 'opex-notice opex-notice-' + kind + ( o.tight ? ' opex-notice-tight' : '' ),
			role: o.role || ( 'error' === kind ? 'alert' : null ),
		}, [ app.icon( NOTICE_ICONS[ kind ], { label: noticeLabel( kind ) } ), body ] );
	};

	const STATUS_ICONS = { running: 'sync', paused: 'pause', done: 'check', sent: 'check', ok: 'check', failed: 'error', waiting: 'clock' };

	/**
	 * Builds a status badge with an icon and a label.
	 */
	ui.status = function ( kind, label ) {
		return el( 'span', { class: 'opex-status opex-status-' + kind }, [
			app.icon( STATUS_ICONS[ kind ] || 'info', { sm: true } ),
			label,
		] );
	};

	/**
	 * Builds a progress bar that screen readers announce.
	 *
	 * @param {number} percent From 0 to 100.
	 * @param {string} kind    Bar style, such as paused, done or failed.
	 * @param {string} label   Spoken name of the bar.
	 */
	ui.bar = function ( percent, kind, label ) {
		const value = Math.max( 0, Math.min( 100, Math.round( Number( percent ) || 0 ) ) );
		const fill = el( 'span', {} );
		fill.style.width = value + '%';
		return el( 'div', {
			class: 'opex-bar' + ( kind ? ' opex-bar-' + kind : '' ),
			role: 'progressbar',
			'aria-label': label,
			'aria-valuemin': 0,
			'aria-valuemax': 100,
			'aria-valuenow': value,
		}, fill );
	};

	/**
	 * Builds a card with an optional heading.
	 *
	 * @return {Object} { root, body }.
	 */
	ui.card = function ( o ) {
		const body = el( 'div', { class: 'opex-pad' } );
		const head = o.title ? el( o.level || 'h2', { class: 'opex-statichead' }, o.title ) : null;
		const root = el( 'section', { class: 'opex-card' + ( o.cls ? ' ' + o.cls : '' ) }, [ head, body ] );
		return { root, body };
	};

	/**
	 * A labeled form row. The control gets an id when it has none, and the
	 * help and error lines are tied to it with aria-describedby.
	 *
	 * @param {Object} o label, control, help, rowLabel (for a group with no single input).
	 * @return {{root: HTMLElement, setError: Function, help: HTMLElement}} Row.
	 */
	ui.field = function ( o ) {
		const control = o.control;
		const target = o.input || control;
		if ( target && ! target.id ) {
			target.id = app.uid( 'opex-f' );
		}
		const helpId = app.uid( 'opex-help' );
		const errorId = app.uid( 'opex-err' );
		const help = o.help ? el( 'p', { class: 'opex-help', id: helpId }, o.help ) : null;
		const error = el( 'p', { class: 'opex-field-error', id: errorId, hidden: true } );
		const label = o.rowLabel
			? el( 'span', { class: 'opex-rowlabel', id: o.labelId }, o.label )
			: el( 'label', { for: target.id }, o.label );
		const describe = () => {
			const ids = [ help ? helpId : '', error.hidden ? '' : errorId ].filter( Boolean ).join( ' ' );
			if ( target && ! o.rowLabel ) {
				if ( ids ) {
					target.setAttribute( 'aria-describedby', ids );
				} else {
					target.removeAttribute( 'aria-describedby' );
				}
			}
		};
		describe();
		const root = el( 'div', { class: 'opex-row' + ( o.cls ? ' ' + o.cls : '' ) }, [
			label,
			el( 'div', { class: 'opex-rowcontrol' }, [ control, help, error ] ),
		] );
		function setError( message ) {
			error.textContent = message || '';
			error.hidden = ! message;
			if ( target && target.setAttribute ) {
				if ( message ) {
					target.setAttribute( 'aria-invalid', 'true' );
				} else {
					target.removeAttribute( 'aria-invalid' );
				}
			}
			describe();
		}
		return { root, setError, help, input: target };
	};

	/**
	 * Builds an on and off switch with its label.
	 */
	ui.switchControl = function ( o ) {
		const input = el( 'input', {
			type: 'checkbox',
			role: 'switch',
			class: 'opex-switch',
			checked: !! o.checked,
			disabled: !! o.disabled,
			onChange: o.onChange,
		} );
		const root = el( 'label', { class: 'opex-switchlabel' }, [ input, el( 'span', {}, o.label ) ] );
		return { root, input };
	};

	/**
	 * Builds the line that shows the result of an action.
	 *
	 * @return {Object} { root, set( kind, text ), clear() }.
	 */
	ui.result = function () {
		const root = el( 'p', { class: 'opex-result', role: 'status', 'aria-live': 'polite' } );
		function set( kind, text ) {
			app.clear( root );
			root.className = 'opex-result opex-result-' + kind;
			const icon = { success: 'check', error: 'error', info: 'info', busy: 'sync' }[ kind ] || 'info';
			app.append( root, [ app.icon( icon, { sm: true } ), el( 'span', {}, text ) ] );
		}
		function clear() {
			app.clear( root );
			root.className = 'opex-result';
		}
		return { root, set, clear };
	};

	/**
	 * Builds a loading placeholder with the given number of lines.
	 */
	ui.skeleton = function ( lines ) {
		const bars = [];
		for ( let i = 0; i < ( lines || 3 ); i++ ) {
			bars.push( el( 'i', {} ) );
		}
		return el( 'div', { class: 'opex-skel', 'aria-busy': 'true' }, [
			el( 'span', { class: 'opex-sr', role: 'status' }, __( 'Loading', 'open-export' ) ),
			bars,
		] );
	};

	/**
	 * Builds the empty state of a list: a title, some text and an optional button.
	 */
	ui.empty = function ( o ) {
		return el( 'div', { class: 'opex-empty' }, [
			el( 'strong', {}, o.title ),
			o.text ? el( 'p', {}, o.text ) : null,
			o.action ? ui.button( { label: o.action.label, primary: true, onClick: o.action.onClick, action: 'empty' } ) : null,
		] );
	};

	/**
	 * Builds the error shown when a list cannot load, with a "Try again" button.
	 */
	ui.loadError = function ( error, onRetry ) {
		return ui.notice( 'error', error && error.message ? error.message : __( 'Something went wrong. Try again.', 'open-export' ), {
			role: 'alert',
			action: onRetry ? { label: __( 'Try again', 'open-export' ), onClick: onRetry } : null,
		} );
	};
}() );
