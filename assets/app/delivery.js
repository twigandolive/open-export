/**
 * Open Export admin screen: the delivery block (shared component).
 *
 * Reads and writes the delivery object the server stores:
 *   email:       { enabled, to: [ up to 10 addresses ], mode: 'link'|'attach', subject }
 *   webhook:     { enabled, url, secret }   (the secret is write-only: has_secret on read, clear_secret to remove)
 *   destination: { enabled, id }
 * The Export tab sends it with a new job. The Schedules tab will reuse it.
 * The screen applies the server's rules before sending, because the server
 * turns a bad channel off with a note that a schedule save discards.
 *
 * Exposes opexApp.delivery( { value, onChange } ):
 *   Returns { root, get(), set( value ), validate(), anyEnabled() }.
 *   validate() returns [ { message, focus } ] and marks the bad fields.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, sprintf } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;
	const store = app.store;

	// Limits from /options (OPEX_Delivery), with the same numbers as fallbacks.
	const maxTo = () => store.limit( 'email_recipients', 10 );
	const maxSubject = () => store.limit( 'email_subject', 200 );
	const maxSecret = () => store.limit( 'webhook_secret', 256 );
	const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

	/**
	 * Turns delivery settings from the server or a template into the full shape the block edits.
	 */
	function normalize( raw ) {
		const v = raw && 'object' === typeof raw ? raw : {};
		const email = v.email || {};
		const webhook = v.webhook || {};
		const destination = v.destination || {};
		return {
			email: { enabled: true === email.enabled, to: Array.isArray( email.to ) ? email.to.map( String ) : [], mode: 'attach' === email.mode ? 'attach' : 'link', subject: String( email.subject || '' ) },
			webhook: { enabled: true === webhook.enabled, url: String( webhook.url || '' ), has_secret: true === webhook.has_secret },
			destination: { enabled: true === destination.enabled, id: String( destination.id || '' ) },
		};
	}

	const split = ( text ) => String( text ).split( /[\s,;]+/ ).map( ( s ) => s.trim() ).filter( Boolean );

	/**
	 * Builds the delivery block: email, webhook and destination, each behind its own switch.
	 *
	 * @param {Object} opts The starting value and callbacks.
	 */
	app.delivery = function ( opts ) {
		const o = opts || {};
		const features = ( store.get( 'options' ) || {} ).features || {};
		let value = normalize( o.value );
		const fields = {};

		function channel( key, icon, label, body ) {
			const sw = ui.switchControl( { label, checked: value[ key ].enabled, onChange: () => {
				value[ key ].enabled = sw.input.checked;
				bodyBox.hidden = ! sw.input.checked;
				changed();
			} } );
			sw.root.insertBefore( app.icon( icon ), sw.root.lastChild );
			const bodyBox = el( 'div', { class: 'opex-switchbody', hidden: ! value[ key ].enabled }, body );
			return { root: el( 'div', { class: 'opex-switchrow' }, [ sw.root, bodyBox ] ), input: sw.input, body: bodyBox };
		}

		function changed() {
			if ( o.onChange ) {
				o.onChange( get() );
			}
		}

		// Email.
		const to = el( 'input', { type: 'text', class: 'opex-w-full', autocomplete: 'off' } );
		fields.to = ui.field( {
			label: __( 'Addresses', 'open-export' ),
			control: to,
			/* translators: %d: most addresses. */
			help: sprintf( __( 'Up to %d addresses, separated by commas, spaces or semicolons.', 'open-export' ), maxTo() ),
		} );
		const modeName = app.uid( 'opex-mode' );
		const modeLabel = app.uid( 'opex-l' );
		const modes = [ [ 'attach', __( 'Attach the file', 'open-export' ) ], [ 'link', __( 'Send a download link', 'open-export' ) ] ].map( ( pair ) => {
			const input = el( 'input', { type: 'radio', name: modeName, value: pair[ 0 ] } );
			input.addEventListener( 'change', () => {
				value.email.mode = pair[ 0 ];
				changed();
			} );
			return { input, root: el( 'label', { class: 'opex-checkline' }, [ input, pair[ 1 ] ] ) };
		} );
		const modeRow = ui.field( {
			label: __( 'Send as', 'open-export' ),
			rowLabel: true,
			labelId: modeLabel,
			control: el( 'div', { class: 'opex-stack', role: 'radiogroup', 'aria-labelledby': modeLabel }, modes.map( ( m ) => m.root ) ),
			help: __( 'Files over 10 MB are sent as a link. Links work for the number of days set in Settings.', 'open-export' ),
		} );
		const subject = el( 'input', { type: 'text', class: 'opex-w-full' } );
		fields.subject = ui.field( { label: __( 'Subject', 'open-export' ), control: subject, help: __( 'Leave empty to use "Your export is ready" and the export name.', 'open-export' ) } );
		to.addEventListener( 'input', () => {
			value.email.to = split( to.value );
			fields.to.setError( '' );
			changed();
		} );
		subject.addEventListener( 'input', () => {
			value.email.subject = subject.value;
			fields.subject.setError( '' );
			changed();
		} );
		const email = channel( 'email', 'mail', __( 'Email', 'open-export' ), [ fields.to.root, modeRow.root, fields.subject.root ] );

		// Webhook.
		const url = el( 'input', { type: 'url', class: 'opex-mono opex-w-full', placeholder: 'https://' } );
		fields.url = ui.field( { label: __( 'URL', 'open-export' ), control: url, help: __( 'Must start with https, unless it points at this site.', 'open-export' ) } );
		const secret = el( 'input', { type: 'password', class: 'opex-w-md', autocomplete: 'new-password' } );
		const saved = el( 'p', { class: 'opex-secret', hidden: true }, [ app.icon( 'lock', { sm: true, cls: 'opex-c-success' } ), __( 'A secret is saved', 'open-export' ) ] );
		const clear = el( 'input', { type: 'checkbox' } );
		const clearLine = el( 'label', { class: 'opex-checkline', hidden: true }, [ clear, __( 'Remove the saved secret', 'open-export' ) ] );
		fields.secret = ui.field( {
			label: __( 'Secret', 'open-export' ),
			control: el( 'div', {}, [ secret, saved, clearLine ] ),
			input: secret,
			help: __( 'Optional. Each request then carries an X-Opex-Signature header (sha256 HMAC of the body), so your server can check it came from this site. The secret is stored encrypted and never shown again.', 'open-export' ),
		} );
		url.addEventListener( 'input', () => {
			value.webhook.url = url.value.trim();
			fields.url.setError( '' );
			changed();
		} );
		secret.addEventListener( 'input', () => {
			fields.secret.setError( '' );
			changed();
		} );
		clear.addEventListener( 'change', changed );
		const webhook = channel( 'webhook', 'link', __( 'Webhook', 'open-export' ), [ fields.url.root, fields.secret.root ] );

		// Destination.
		const dest = el( 'select', {} );
		const manage = ui.linkButton( { label: __( 'Manage destinations', 'open-export' ), onClick: () => app.showTab && app.showTab( 'destinations', true ) } );
		const destHelp = el( 'p', { class: 'opex-help' } );
		fields.dest = ui.field( { label: __( 'Destination', 'open-export' ), control: el( 'div', {}, [ el( 'div', { class: 'opex-inline' }, [ dest, manage ] ), destHelp ] ), input: dest } );
		dest.addEventListener( 'change', () => {
			value.destination.id = dest.value;
			fields.dest.setError( '' );
			changed();
		} );
		const destination = channel( 'destination', 'server', __( 'Destination', 'open-export' ), fields.dest.root );
		if ( false === features.sodium ) {
			destination.input.disabled = true;
			destination.root.appendChild( el( 'p', { class: 'opex-help' }, __( 'Destinations need the PHP sodium extension to store passwords, and it is missing on this server.', 'open-export' ) ) );
		}

		function fillDestinations() {
			const list = store.get( 'destinations' );
			app.clear( dest );
			dest.appendChild( el( 'option', { value: '' }, __( 'Choose a destination', 'open-export' ) ) );
			( list || [] ).forEach( ( item ) => {
				const blocked = false === features.ftp && ( 'ftp' === item.type || 'ftps' === item.type );
				dest.appendChild( el( 'option', { value: item.id, disabled: blocked }, app.paren( item.name, String( item.type || '' ).toUpperCase() ) ) );
			} );
			if ( value.destination.id && ! ( list || [] ).some( ( item ) => item.id === value.destination.id ) && list ) {
				/* translators: %s: destination id. */
				dest.appendChild( el( 'option', { value: value.destination.id }, sprintf( __( 'Missing destination (%s)', 'open-export' ), value.destination.id ) ) );
			}
			dest.value = value.destination.id;
			destHelp.textContent = list && ! list.length ? __( 'No destinations are saved yet. Add one on the Destinations tab.', 'open-export' ) : ( false === features.ftp ? __( 'FTP and FTPS destinations cannot be used because the PHP ftp extension is missing.', 'open-export' ) : '' );
		}
		store.subscribe( 'destinations', fillDestinations );
		if ( ! store.get( 'destinations' ) ) {
			app.api.get( '/destinations' ).then( ( list ) => store.set( 'destinations', Array.isArray( list ) ? list : [] ) ).catch( () => {} );
		}

		function set( next ) {
			value = normalize( next );
			email.input.checked = value.email.enabled;
			email.body.hidden = ! value.email.enabled;
			to.value = value.email.to.join( ', ' );
			modes.forEach( ( m ) => {
				m.input.checked = m.input.value === value.email.mode;
			} );
			subject.value = value.email.subject;
			webhook.input.checked = value.webhook.enabled;
			webhook.body.hidden = ! value.webhook.enabled;
			url.value = value.webhook.url;
			secret.value = '';
			clear.checked = false;
			saved.hidden = ! value.webhook.has_secret;
			clearLine.hidden = ! value.webhook.has_secret;
			secret.placeholder = value.webhook.has_secret ? __( 'Leave empty to keep the saved secret', 'open-export' ) : '';
			destination.input.checked = value.destination.enabled && ! destination.input.disabled;
			destination.body.hidden = ! destination.input.checked;
			fillDestinations();
			Object.keys( fields ).forEach( ( k ) => fields[ k ].setError( '' ) );
		}

		function get() {
			const out = {
				email: { enabled: value.email.enabled, to: value.email.to.slice(), mode: value.email.mode, subject: value.email.subject },
				webhook: { enabled: value.webhook.enabled, url: value.webhook.url },
				destination: { enabled: value.destination.enabled && ! destination.input.disabled, id: value.destination.id },
			};
			if ( secret.value ) {
				out.webhook.secret = secret.value;
			} else if ( clear.checked ) {
				out.webhook.clear_secret = true;
			}
			return out;
		}

		/**
		 * Whether plain http is allowed for a webhook address. It mirrors the
		 * server's rule (OPEX_Delivery_Webhook::transport_allowed): localhost,
		 * ::1, an IPv4 address in 127.0.0.0/8, or the host of the site's home
		 * URL, any port. The home host comes from the REST root, which WordPress
		 * builds from the home URL. The host is read from the typed text, not
		 * from URL(), because URL() rewrites forms such as 127.1 that the server
		 * would refuse.
		 *
		 * @param {string} address The webhook address as typed.
		 * @return {boolean} True when the server would accept it over http.
		 */
		function httpAllowed( address ) {
			const hostOf = ( text ) => {
				const match = /^[a-z][a-z0-9+.-]*:\/\/(?:[^@/?#]*@)?(\[[^\]/?#]*\]|[^:/?#]*)/i.exec( String( text || '' ).trim() );
				return match ? match[ 1 ].replace( /^\[|\]$/g, '' ).toLowerCase() : '';
			};
			const host = hostOf( address );
			if ( ! host ) {
				return false;
			}
			const octets = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/.exec( host );
			const ipv4 = !! octets && octets.slice( 1 ).every( ( part ) => ( '0' === part || '0' !== part[ 0 ] ) && Number( part ) <= 255 );
			if ( 'localhost' === host || '::1' === host || ( ipv4 && '127' === octets[ 1 ] ) ) {
				return true;
			}
			return host === hostOf( app.boot.restRoot );
		}

		function validate() {
			const problems = [];
			const add = ( field, input, message ) => {
				field.setError( message );
				problems.push( { message, focus: () => input.focus() } );
			};
			if ( value.email.enabled ) {
				const list = value.email.to;
				const bad = list.filter( ( a ) => ! EMAIL.test( a ) );
				if ( ! list.length ) {
					add( fields.to, to, __( 'Enter at least one email address.', 'open-export' ) );
				} else if ( bad.length ) {
					/* translators: %s: list of addresses. */
					add( fields.to, to, sprintf( __( 'These are not email addresses: %s', 'open-export' ), bad.join( ', ' ) ) );
				} else if ( list.length > maxTo() ) {
					/* translators: %d: most addresses. */
					add( fields.to, to, sprintf( __( 'Enter at most %d addresses.', 'open-export' ), maxTo() ) );
				}
				if ( value.email.subject.length > maxSubject() ) {
					/* translators: %d: most characters. */
					add( fields.subject, subject, sprintf( __( 'Use at most %d characters.', 'open-export' ), maxSubject() ) );
				}
			}
			if ( value.webhook.enabled ) {
				let parsed = null;
				try {
					parsed = new URL( value.webhook.url );
				} catch ( e ) {
					parsed = null;
				}
				if ( ! parsed || ! /^https?:$/.test( parsed.protocol ) ) {
					add( fields.url, url, __( 'Enter the full webhook address, starting with https://.', 'open-export' ) );
				} else if ( 'https:' !== parsed.protocol && ! httpAllowed( value.webhook.url ) ) {
					add( fields.url, url, __( 'The webhook address must start with https.', 'open-export' ) );
				}
				if ( secret.value.length > maxSecret() ) {
					/* translators: %d: most characters. */
					add( fields.secret, secret, sprintf( __( 'Use at most %d characters.', 'open-export' ), maxSecret() ) );
				}
			}
			if ( value.destination.enabled && ! destination.input.disabled && ! value.destination.id ) {
				add( fields.dest, dest, __( 'Choose a destination.', 'open-export' ) );
			}
			return problems;
		}

		const root = el( 'div', { class: 'opex-delivery' }, [ email.root, webhook.root, destination.root ] );
		set( value );
		return {
			root,
			get,
			set,
			validate,
			anyEnabled: () => value.email.enabled || value.webhook.enabled || ( value.destination.enabled && ! destination.input.disabled ),
		};
	};
}() );
