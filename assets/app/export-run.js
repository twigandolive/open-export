/**
 * Open Export admin screen: the Run card of the Export tab.
 *
 * Name, "Run in the background" (defaulted from /options features), the
 * shared delivery block, "Save as template" with a real name box, and
 * "Start export". Starting POSTs /jobs with the builder's config plus
 * label, mode and delivery, then hands the job to stage 1's job runner
 * (opexApp.jobs): browser jobs are driven, background jobs are polled.
 *
 * The job view below the form is built once per job and updated in place,
 * so typing anywhere on the page keeps focus while a job runs.
 *
 * Exposes opexApp.exportRun( { builder, say } ):
 *   Returns { root, start(), setName( name ), syncName(), show(), hide(), isRunning() }.
 */
( function () {
	'use strict';

	const app = window.opexApp;
	const { __, sprintf } = window.wp.i18n;
	const el = app.el;
	const ui = app.ui;
	const store = app.store;
	const jobs = app.jobs;

	app.exportRun = function ( o ) {
		const builder = o.builder;
		const features = ( store.get( 'options' ) || {} ).features || {};
		const startResult = ui.result();
		let nameTouched = false;
		let currentId = null;
		let view = null;
		let starting = false;
		let visible = false;
		let stopPolling = null;
		let deliveryTimer = null;

		const nameInput = el( 'input', { type: 'text', class: 'opex-w-md', maxlength: 200 } );
		nameInput.addEventListener( 'input', () => {
			nameTouched = true;
		} );
		const nameField = ui.field( { label: __( 'Export name', 'open-export' ), control: nameInput } );

		const bg = el( 'input', { type: 'checkbox', checked: !! features.background } );
		const bgHelp = __( 'The server runs it batch by batch, so you can leave this page. Otherwise this page runs it and must stay open.', 'open-export' );
		const bgRow = ui.field( { label: __( 'Background', 'open-export' ), rowLabel: true, control: el( 'div', {}, [
			el( 'label', { class: 'opex-checkline' }, [ bg, __( 'Run in the background', 'open-export' ) ] ),
			el( 'p', { class: 'opex-help' }, bgHelp ),
			features.cron_disabled ? ui.notice( 'warning', __( 'WP-Cron is turned off, so a background export may not move until a real cron job calls the site.', 'open-export' ), { tight: true, role: 'note' } ) : null,
		] ) } );

		const delivery = app.delivery( {} );
		const deliveryId = app.uid( 'opex-delivery' );
		const deliveryBox = el( 'div', { id: deliveryId, hidden: true }, delivery.root );
		const collapser = el( 'button', { type: 'button', class: 'opex-link opex-collapser', 'aria-expanded': 'false', 'aria-controls': deliveryId }, [
			__( 'Send the result when it is finished', 'open-export' ),
			app.icon( 'chevron', { sm: true, cls: 'opex-chev' } ),
		] );
		const setDeliveryOpen = ( open ) => {
			deliveryBox.hidden = ! open;
			collapser.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		};
		collapser.addEventListener( 'click', () => setDeliveryOpen( deliveryBox.hidden ) );

		const tplName = el( 'input', { type: 'text', class: 'opex-w-md', maxlength: 200 } );
		const tplField = ui.field( { label: __( 'Template name', 'open-export' ), control: tplName, help: __( 'Saving under a name that exists updates that template. Delivery is not saved in templates.', 'open-export' ) } );
		const tplResult = ui.result();
		const tplButton = ui.button( { label: __( 'Save as template', 'open-export' ), onClick: saveTemplate } );
		const startButton = ui.button( { label: __( 'Start export', 'open-export' ), primary: true, action: 'start', onClick: start } );
		const viewBox = el( 'div', { class: 'opex-run-box' } );

		const root = el( 'div', {}, [
			viewBox,
			nameField.root,
			bgRow.root,
			collapser,
			deliveryBox,
			el( 'div', { class: 'opex-run-foot' }, [ startButton, startResult.root ] ),
			el( 'div', { class: 'opex-run-template' }, [ tplField.root, el( 'div', { class: 'opex-inline' }, [ tplButton, tplResult.root ] ) ] ),
		] );

		function syncName() {
			if ( ! nameTouched ) {
				nameInput.value = builder.sourceLabel() || '';
			}
		}

		function setName( name ) {
			if ( name ) {
				nameInput.value = name;
				nameTouched = true;
			} else {
				nameTouched = false;
				syncName();
			}
		}

		function problemsText( problems ) {
			// Each message ends as a sentence, so joined messages still read apart.
			return problems.map( ( p ) => String( p.message ).replace( /([^.!?])$/, '$1.' ) ).join( ' ' );
		}

		function saveTemplate() {
			tplField.setError( '' );
			const problems = builder.validate();
			if ( problems.length ) {
				tplResult.set( 'error', problemsText( problems ) );
				return;
			}
			const name = tplName.value.trim();
			if ( ! name ) {
				tplField.setError( __( 'Give the template a name.', 'open-export' ) );
				tplName.focus();
				return;
			}
			tplButton.disabled = true;
			tplResult.set( 'busy', __( 'Saving the template.', 'open-export' ) );
			app.api.post( '/templates', { name, config: builder.getConfig() } ).then( ( saved ) => {
				const list = ( store.get( 'templates' ) || [] ).filter( ( t ) => t.id !== saved.id );
				const existed = list.length !== ( store.get( 'templates' ) || [] ).length;
				store.set( 'templates', [ saved ].concat( list ) );
				/* translators: %s: template name. */
				tplResult.set( 'success', sprintf( existed ? __( 'Updated the template %s.', 'open-export' ) : __( 'Saved the template %s.', 'open-export' ), saved.name ) );
			} ).catch( ( err ) => {
				if ( ! err.session ) {
					tplResult.set( 'error', err.message );
				}
			} ).then( () => {
				tplButton.disabled = false;
			} );
		}

		function isRunning() {
			const job = current();
			return !! job && 'running' === job.status;
		}

		function current() {
			return currentId ? ( store.get( 'jobs' ) || [] ).find( ( j ) => j.id === currentId ) || null : null;
		}

		function start() {
			if ( starting ) {
				return;
			}
			startResult.clear();
			const problems = builder.validate();
			if ( problems.length ) {
				startResult.set( 'error', problemsText( problems ) );
				return;
			}
			const deliveryProblems = delivery.validate();
			if ( deliveryProblems.length ) {
				setDeliveryOpen( true );
				deliveryProblems[ 0 ].focus();
				startResult.set( 'error', problemsText( deliveryProblems ) );
				return;
			}
			if ( isRunning() ) {
				startResult.set( 'error', __( 'An export is still running. Pause it or wait until it is done.', 'open-export' ) );
				return;
			}
			const body = Object.assign( builder.getConfig(), {
				label: nameInput.value.trim() || builder.sourceLabel(),
				mode: bg.checked ? 'background' : 'browser',
			} );
			if ( delivery.anyEnabled() ) {
				body.delivery = delivery.get();
			}
			starting = true;
			startButton.disabled = true;
			startResult.set( 'busy', __( 'Starting the export.', 'open-export' ) );
			app.api.post( '/jobs', body ).then( ( job ) => {
				startResult.clear();
				jobs.upsert( job );
				follow( job );
				if ( 'browser' === job.mode && 'running' === job.status ) {
					drive( job.id, false );
				}
			} ).catch( ( err ) => {
				if ( ! err.session ) {
					startResult.set( 'error', err.message );
				}
			} ).then( () => {
				starting = false;
				startButton.disabled = false;
			} );
		}

		function drive( id, resume ) {
			jobs.drive( id, resume ).catch( ( err ) => {
				if ( ! err.session && view ) {
					view.say( err.message );
				}
			} );
		}

		// Shows a job in the view and keeps it current.
		function follow( job ) {
			currentId = job.id;
			view = app.exportRunView( job, {
				pause: () => jobs.pause( job.id ).catch( ( err ) => ! err.session && view.say( err.message ) ),
				resume: () => {
					const latest = current() || job;
					if ( 'background' === latest.mode ) {
						jobs.resume( latest ).catch( ( err ) => ! err.session && view.say( err.message ) );
					} else {
						drive( latest.id, true );
					}
				},
			} );
			app.clear( viewBox ).appendChild( view.root );
			watch();
		}

		function watch() {
			const job = current();
			if ( ! job ) {
				return;
			}
			if ( view ) {
				view.update( job, jobs.isDriving( job.id ) );
			}
			const needsPoll = jobs.isActive( job ) && ! jobs.isDriving( job.id );
			if ( needsPoll && visible && ! stopPolling ) {
				stopPolling = jobs.startPolling();
			}
			// Deliveries run after the job is done, so check the job again for a while.
			if ( 'done' === job.status && ( job.deliveries || [] ).some( ( d ) => 'pending' === d.status ) && ! deliveryTimer ) {
				let tries = 0;
				const tick = () => {
					tries += 1;
					app.api.get( '/jobs/' + encodeURIComponent( job.id ) ).then( jobs.upsert ).catch( () => {} ).then( () => {
						const latest = current();
						const pending = latest && ( latest.deliveries || [] ).some( ( d ) => 'pending' === d.status );
						deliveryTimer = pending && tries < 24 && visible ? window.setTimeout( tick, 5000 ) : null;
					} );
				};
				deliveryTimer = window.setTimeout( tick, 5000 );
			}
		}
		store.subscribe( 'jobs', watch );
		store.subscribe( 'driving', watch );

		return {
			root,
			start,
			setName,
			syncName,
			isRunning,
			show() {
				visible = true;
				watch();
			},
			hide() {
				visible = false;
				if ( stopPolling ) {
					stopPolling();
					stopPolling = null;
				}
				if ( deliveryTimer ) {
					window.clearTimeout( deliveryTimer );
					deliveryTimer = null;
				}
			},
		};
	};
}() );
