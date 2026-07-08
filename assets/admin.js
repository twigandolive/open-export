/**
 * Open Export admin app. No framework, no build step — one state object,
 * render functions, delegated events, and wp.apiFetch (which attaches the
 * REST nonce automatically).
 */
(function () {
	'use strict';

	const apiFetch = window.wp.apiFetch;
	const BOOT = window.OPEX_BOOT || {};

	if ( apiFetch && BOOT.restNonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( BOOT.restNonce ) );
	}

	const OPS = [
		['eq', 'equals'], ['neq', 'does not equal'],
		['contains', 'contains'], ['not_contains', 'does not contain'],
		['starts_with', 'starts with'], ['ends_with', 'ends with'],
		['gt', '>'], ['gte', '≥'], ['lt', '<'], ['lte', '≤'],
		['in', 'is one of (comma list)'], ['not_in', 'is not one of (comma list)'],
		['empty', 'is empty'], ['not_empty', 'is not empty'],
	];

	const PACING = {
		gentle:   { batch: 100,  delay: 1500, label: 'Gentle — shared / budget hosting' },
		balanced: { batch: 400,  delay: 400,  label: 'Balanced — most sites' },
		fast:     { batch: 2000, delay: 50,   label: 'Fast — VPS / dedicated' },
	};

	const DELIMITERS = {
		comma:     { label: 'Comma', char: ',' },
		semicolon: { label: 'Semicolon', char: ';' },
		tab:       { label: 'Tab', char: 'Tab' },
	};

	const state = {
		sources: [],
		sourceId: null,
		sourceLabel: '',
		fieldGroups: [],
		selected: [],
		columnLabels: {},
		relation: 'AND',
		rules: [],
		format: 'csv',
		csvDelimiter: 'comma',
		pacing: 'balanced',
		label: '',
		search: '',
		preview: null,
		previewBusy: false,
		jobs: [],
		templates: [],
		running: null,
		stopRequested: false,
		dragChipId: null,
		error: '',
	};

	const app = document.getElementById('opex-app');

	function esc(value) {
		const div = document.createElement('div');
		div.textContent = value == null ? '' : String(value);
		return div.innerHTML;
	}

	function fmtNum(n) {
		return Number(n || 0).toLocaleString();
	}

	function fmtSize(bytes) {
		if (!bytes) return '—';
		const units = ['B', 'KB', 'MB', 'GB'];
		let i = 0, v = bytes;
		while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
		return v.toFixed(v >= 10 || i === 0 ? 0 : 1) + ' ' + units[i];
	}

	function fieldLabel(id) {
		return id.replace(/^(core|meta|tax|computed|col):/, '');
	}

	function chipDisplayLabel(id) {
		return state.columnLabels[id] || fieldLabel(id);
	}

	function inferPacing(batchSize) {
		const batch = batchSize || 400;
		let match = 'balanced';
		Object.keys(PACING).forEach(function (k) {
			if (PACING[k].batch === batch) match = k;
		});
		return match;
	}

	function findSourceLabel(id) {
		let label = id;
		state.sources.forEach(function (group) {
			group.items.forEach(function (item) {
				if (item.id === id) label = item.label;
			});
		});
		return label;
	}

	/* ------------------------------------------------------------------ */
	/* Render                                                              */
	/* ------------------------------------------------------------------ */

	function render() {
		app.innerHTML =
			header() +
			stepSource() +
			(state.sourceId ? stepFilters() + stepFields() + stepExport() : '') +
			savedTemplates() +
			recentJobs();
		bindDynamic();
	}

	function header() {
		return '<div class="opex-hero">' +
			'<h1><span class="dashicons dashicons-database-export"></span> Open Export</h1>' +
			'<p>Pick a source, filter it down, choose your fields, export. Everything included — no add-ons, ever.</p>' +
			(state.error ? '<div class="opex-error">' + esc(state.error) + ' <button type="button" class="opex-link" data-act="dismiss-error">dismiss</button></div>' : '') +
			'</div>';
	}

	function stepSource() {
		let body;
		if (!state.sources.length) {
			body = '<p class="opex-muted">Loading sources…</p>';
		} else {
			body = state.sources.map(function (group) {
				if (!group.items.length) return '';
				return '<h3 class="opex-group-title">' + esc(group.label) + '</h3>' +
					'<div class="opex-cards">' + group.items.map(function (item) {
						const on = state.sourceId === item.id;
						return '<button type="button" class="opex-card' + (on ? ' is-on' : '') + '" data-act="pick-source" data-id="' + esc(item.id) + '" data-label="' + esc(item.label) + '">' +
							'<span class="dashicons ' + esc(item.icon) + '"></span>' +
							'<span class="opex-card-label">' + esc(item.label) + '</span>' +
							'<span class="opex-card-count">' + fmtNum(item.count) + ' rows</span>' +
							'</button>';
					}).join('') + '</div>';
			}).join('');
		}
		return section(1, 'What do you want to export?', body);
	}

	function filterFieldOptions() {
		let opts = '';
		state.fieldGroups.forEach(function (group) {
			opts += '<optgroup label="' + esc(group.label) + '">';
			group.fields.forEach(function (f) {
				if (f.id.indexOf('computed:') === 0 && f.id !== 'computed:roles') return;
				opts += '<option value="' + esc(f.id) + '">' + esc(f.label) + '</option>';
			});
			opts += '</optgroup>';
		});
		return opts;
	}

	function stepFilters() {
		const rows = state.rules.map(function (rule, i) {
			const noValue = rule.op === 'empty' || rule.op === 'not_empty';
			return '<div class="opex-rule" data-i="' + i + '">' +
				'<select class="opex-rule-field">' + filterFieldOptions() + '</select>' +
				'<select class="opex-rule-op">' + OPS.map(function (op) {
					return '<option value="' + op[0] + '"' + (rule.op === op[0] ? ' selected' : '') + '>' + esc(op[1]) + '</option>';
				}).join('') + '</select>' +
				'<input type="text" class="opex-rule-value" placeholder="value or last 30 days, this month…"' + (noValue ? ' disabled' : '') + ' value="' + esc(rule.value) + '">' +
				'<button type="button" class="opex-icon-btn" data-act="del-rule" data-i="' + i + '" title="Remove rule">&times;</button>' +
				'</div>';
		}).join('');

		const relation = state.rules.length > 1
			? '<div class="opex-relation">Match <select id="opex-relation">' +
				'<option value="AND"' + (state.relation === 'AND' ? ' selected' : '') + '>ALL rules (AND)</option>' +
				'<option value="OR"' + (state.relation === 'OR' ? ' selected' : '') + '>ANY rule (OR)</option>' +
				'</select></div>'
			: '';

		const preview = state.preview
			? previewTable()
			: '<p class="opex-muted">' + (state.previewBusy ? 'Loading preview…' : 'Use Preview to sanity-check before exporting.') + '</p>';

		const body =
			'<div class="opex-rules">' + rows + '</div>' + relation +
			'<p class="opex-hint">Date filters accept tokens like <code>last 30 days</code>, <code>this month</code>, <code>-7 days</code> (use with ≥ or ≤).</p>' +
			'<div class="opex-row-actions">' +
			'<button type="button" class="button" data-act="add-rule">+ Add filter</button> ' +
			'<button type="button" class="button button-secondary" data-act="preview"' + (state.previewBusy ? ' disabled' : '') + '>Preview first 5 rows</button>' +
			'</div>' + preview;

		return section(2, 'Filter it down <span class="opex-optional">(optional)</span>', body);
	}

	function previewTable() {
		const p = state.preview;
		const head = p.headers.map(function (h) { return '<th>' + esc(h) + '</th>'; }).join('');
		const rows = p.rows.length
			? p.rows.map(function (row) {
				return '<tr>' + row.map(function (cell) { return '<td>' + esc(cell) + '</td>'; }).join('') + '</tr>';
			}).join('')
			: '<tr><td colspan="' + p.headers.length + '" class="opex-muted">No rows match your filters.</td></tr>';
		return '<p class="opex-total"><strong>' + fmtNum(p.total) + '</strong> rows match.</p>' +
			'<div class="opex-table-scroll"><table class="opex-table"><thead><tr>' + head + '</tr></thead><tbody>' + rows + '</tbody></table></div>';
	}

	function stepFields() {
		if (!state.fieldGroups.length) {
			return section(3, 'Pick your fields', '<p class="opex-muted">Loading fields…</p>');
		}

		const q = state.search.toLowerCase();
		const groups = state.fieldGroups.map(function (group) {
			const fields = group.fields.filter(function (f) {
				return !q || f.label.toLowerCase().indexOf(q) !== -1;
			});
			if (!fields.length) return '';
			const allOn = fields.every(function (f) { return state.selected.indexOf(f.id) !== -1; });
			return '<div class="opex-fgroup">' +
				'<div class="opex-fgroup-head">' +
				'<strong>' + esc(group.label) + '</strong> <span class="opex-muted">(' + fields.length + ')</span>' +
				'<button type="button" class="opex-link" data-act="toggle-group" data-group="' + esc(group.label) + '">' + (allOn ? 'none' : 'all') + '</button>' +
				'</div>' +
				'<div class="opex-checks">' + fields.map(function (f) {
					const on = state.selected.indexOf(f.id) !== -1;
					return '<label class="opex-check' + (on ? ' is-on' : '') + '">' +
						'<input type="checkbox" data-act="toggle-field" data-id="' + esc(f.id) + '"' + (on ? ' checked' : '') + '> ' +
						esc(f.label) + '</label>';
				}).join('') + '</div></div>';
		}).join('');

		const chips = state.selected.length
			? '<div class="opex-chips" id="opex-chips">' + state.selected.map(function (id) {
				return '<span class="opex-chip" draggable="true" data-id="' + esc(id) + '" title="Drag to reorder">' +
					'<input type="text" class="opex-chip-label" data-id="' + esc(id) + '" value="' + esc(chipDisplayLabel(id)) + '">' +
					'<button type="button" data-act="chip-remove" data-id="' + esc(id) + '" title="Remove">&times;</button></span>';
			}).join('') + '</div>'
			: '<p class="opex-muted">Nothing selected yet — tick fields below. Drag chips to reorder; edit labels for custom column headers.</p>';

		const body =
			'<div class="opex-selected"><strong>' + state.selected.length + ' selected</strong>' + chips + '</div>' +
			'<input type="search" id="opex-search" class="opex-search" placeholder="Search fields…" value="' + esc(state.search) + '">' +
			groups;

		return section(3, 'Pick your fields', body);
	}

	function stepExport() {
		const running = state.running;
		let progress = '';

		if (running) {
			const pct = running.total > 0 ? Math.min(100, Math.round(running.processed / running.total * 100)) : (running.status === 'done' ? 100 : 0);
			progress =
				'<div class="opex-progress"><div class="opex-progress-bar' + (running.status === 'done' ? ' is-done' : '') + '" style="width:' + pct + '%"></div></div>' +
				'<p class="opex-progress-text">' + fmtNum(running.processed) + ' / ' + fmtNum(running.total) + ' rows (' + pct + '%)' +
				(running.status === 'running' ? ' <button type="button" class="opex-link" data-act="stop">stop</button>' : '') + '</p>';
			if (running.status === 'done') {
				progress += '<p><a class="button button-primary" href="' + downloadUrl(running.id) + '">Download ' + esc(running.filename || 'file') + '</a></p>';
			}
			if (running.status === 'error') {
				progress += '<p class="opex-error">' + esc(running.error || 'Export failed.') + ' <button type="button" class="opex-link" data-act="resume">retry / resume</button></p>';
			}
			if (running.status === 'paused') {
				progress += '<p class="opex-muted">Paused. <button type="button" class="opex-link" data-act="resume">resume</button></p>';
			}
		}

		const delimBlock = state.format === 'csv'
			? '<div><label class="opex-label">CSV delimiter</label><div class="opex-pills">' +
				Object.keys(DELIMITERS).map(function (k) {
					return '<button type="button" class="opex-pill' + (state.csvDelimiter === k ? ' is-on' : '') + '" data-act="delimiter" data-v="' + k + '">' + esc(DELIMITERS[k].label) + '</button>';
				}).join('') + '</div></div>'
			: '';

		const body =
			'<div class="opex-export-grid">' +
			'<div><label class="opex-label">Format</label><div class="opex-pills">' +
			['csv', 'json', 'xml'].map(function (f) {
				return '<button type="button" class="opex-pill' + (state.format === f ? ' is-on' : '') + '" data-act="format" data-v="' + f + '">' + f.toUpperCase() + '</button>';
			}).join('') + '</div></div>' +
			delimBlock +
			'<div><label class="opex-label">Pacing <span class="opex-muted">(be kind to your host)</span></label><div class="opex-pills">' +
			Object.keys(PACING).map(function (k) {
				return '<button type="button" class="opex-pill' + (state.pacing === k ? ' is-on' : '') + '" data-act="pacing" data-v="' + k + '" title="' + esc(PACING[k].label) + '">' +
					k.charAt(0).toUpperCase() + k.slice(1) + '</button>';
			}).join('') + '</div></div>' +
			'<div><label class="opex-label" for="opex-label-input">Export name</label>' +
			'<input type="text" id="opex-label-input" placeholder="' + esc(state.sourceLabel || 'my-export') + '" value="' + esc(state.label) + '"></div>' +
			'</div>' +
			'<p class="opex-run">' +
			'<button type="button" class="button button-primary button-hero" data-act="export"' +
			((state.running && state.running.status === 'running') || !state.selected.length ? ' disabled' : '') + '>Export ' + esc(state.sourceLabel) + '</button> ' +
			'<button type="button" class="button" data-act="save-template"' + (!state.selected.length ? ' disabled' : '') + '>Save as template</button>' +
			'</p>' +
			progress;

		return section(4, 'Export', body);
	}

	function savedTemplates() {
		if (!state.templates.length) return '';
		const rows = state.templates.map(function (tpl) {
			const date = new Date(tpl.updated * 1000).toLocaleString();
			return '<tr>' +
				'<td>' + esc(tpl.name) + '</td>' +
				'<td>' + esc((tpl.config.source || '').replace(/^(posts:|terms:|table:)/, '')) + '</td>' +
				'<td>' + esc(tpl.config.format.toUpperCase()) + '</td>' +
				'<td>' + esc(date) + '</td>' +
				'<td class="opex-job-actions">' +
				'<button type="button" class="button button-small" data-act="load-template" data-id="' + esc(tpl.id) + '">Load</button> ' +
				'<button type="button" class="button button-small" data-act="del-template" data-id="' + esc(tpl.id) + '">Delete</button>' +
				'</td></tr>';
		}).join('');

		const body = '<div class="opex-table-scroll"><table class="opex-table">' +
			'<thead><tr><th>Name</th><th>Source</th><th>Format</th><th>Saved</th><th></th></tr></thead>' +
			'<tbody>' + rows + '</tbody></table></div>';

		return section('T', 'Saved templates', body, true);
	}

	function recentJobs() {
		if (!state.jobs.length) return '';
		const rows = state.jobs.map(function (job) {
			const date = new Date(job.created * 1000).toLocaleString();
			const status = job.status === 'done' ? '<span class="opex-badge is-done">done</span>'
				: job.status === 'error' ? '<span class="opex-badge is-error">error</span>'
				: '<span class="opex-badge">' + esc(job.status) + '</span>';
			return '<tr>' +
				'<td>' + esc(job.label) + '</td>' +
				'<td>' + esc(job.format.toUpperCase()) + '</td>' +
				'<td>' + fmtNum(job.processed) + '</td>' +
				'<td>' + fmtSize(job.filesize) + '</td>' +
				'<td>' + esc(date) + '</td>' +
				'<td>' + status + '</td>' +
				'<td class="opex-job-actions">' +
				'<button type="button" class="button button-small" data-act="load-job" data-id="' + esc(job.id) + '">Load</button> ' +
				'<button type="button" class="button button-small" data-act="rerun-job" data-id="' + esc(job.id) + '">Re-run</button> ' +
				(job.status === 'done' ? '<a class="button button-small" href="' + downloadUrl(job.id) + '">Download</a> ' : '') +
				'<button type="button" class="button button-small" data-act="del-job" data-id="' + esc(job.id) + '">Delete</button>' +
				'</td></tr>';
		}).join('');

		const body = '<div class="opex-table-scroll"><table class="opex-table">' +
			'<thead><tr><th>Name</th><th>Format</th><th>Rows</th><th>Size</th><th>Created</th><th>Status</th><th></th></tr></thead>' +
			'<tbody>' + rows + '</tbody></table></div>';

		return section('H', 'Recent exports', body, true);
	}

	function section(num, title, body, plain) {
		return '<section class="opex-section">' +
			'<h2><span class="opex-step' + (plain ? ' is-plain' : '') + '">' + num + '</span> ' + title + '</h2>' +
			'<div class="opex-section-body">' + body + '</div></section>';
	}

	function downloadUrl(id) {
		const base = BOOT.restRoot.replace(/\/$/, '') + '/jobs/' + id + '/download';
		const sep = base.indexOf('?') === -1 ? '?' : '&';
		return base + sep + '_wpnonce=' + encodeURIComponent(BOOT.restNonce);
	}

	/* ------------------------------------------------------------------ */
	/* Behavior                                                            */
	/* ------------------------------------------------------------------ */

	function bindDynamic() {
		document.querySelectorAll('.opex-rule').forEach(function (el) {
			const i = parseInt(el.dataset.i, 10);
			const rule = state.rules[i];
			if (!rule) return;
			const fieldSel = el.querySelector('.opex-rule-field');
			if (rule.field) fieldSel.value = rule.field;
			else rule.field = fieldSel.value;

			fieldSel.addEventListener('change', function () { rule.field = this.value; });
			el.querySelector('.opex-rule-op').addEventListener('change', function () {
				rule.op = this.value;
				render();
			});
			el.querySelector('.opex-rule-value').addEventListener('input', function () { rule.value = this.value; });
		});

		const relation = document.getElementById('opex-relation');
		if (relation) {
			relation.addEventListener('change', function () { state.relation = this.value; });
		}

		const search = document.getElementById('opex-search');
		if (search) {
			search.addEventListener('input', debounce(function () {
				state.search = search.value;
				const pos = search.selectionStart;
				render();
				const again = document.getElementById('opex-search');
				if (again) { again.focus(); again.setSelectionRange(pos, pos); }
			}, 200));
		}

		const label = document.getElementById('opex-label-input');
		if (label) {
			label.addEventListener('input', function () { state.label = this.value; });
		}

		document.querySelectorAll('.opex-chip-label').forEach(function (input) {
			input.addEventListener('input', function () {
				const id = input.dataset.id;
				const val = input.value.trim();
				if (val && val !== fieldLabel(id)) {
					state.columnLabels[id] = val;
				} else {
					delete state.columnLabels[id];
				}
			});
			input.addEventListener('click', function (e) { e.stopPropagation(); });
			input.addEventListener('dragstart', function (e) { e.stopPropagation(); });
		});

		document.querySelectorAll('.opex-chip').forEach(function (chip) {
			chip.addEventListener('dragstart', function (e) {
				state.dragChipId = chip.dataset.id;
				chip.classList.add('is-dragging');
				e.dataTransfer.effectAllowed = 'move';
			});
			chip.addEventListener('dragend', function () {
				chip.classList.remove('is-dragging');
				state.dragChipId = null;
			});
			chip.addEventListener('dragover', function (e) {
				e.preventDefault();
				chip.classList.add('is-drag-over');
			});
			chip.addEventListener('dragleave', function () {
				chip.classList.remove('is-drag-over');
			});
			chip.addEventListener('drop', function (e) {
				e.preventDefault();
				chip.classList.remove('is-drag-over');
				const from = state.dragChipId;
				const to = chip.dataset.id;
				if (from && to && from !== to) {
					reorderSelected(from, to);
					render();
				}
			});
		});
	}

	function reorderSelected(fromId, toId) {
		const from = state.selected.indexOf(fromId);
		const to = state.selected.indexOf(toId);
		if (from === -1 || to === -1) return;
		state.selected.splice(from, 1);
		state.selected.splice(to, 0, fromId);
	}

	function debounce(fn, ms) {
		let t;
		return function () { clearTimeout(t); t = setTimeout(fn, ms); };
	}

	app.addEventListener('click', function (event) {
		const btn = event.target.closest('[data-act]');
		if (!btn) return;
		const act = btn.dataset.act;

		if (act === 'pick-source') return pickSource(btn.dataset.id, btn.dataset.label);
		if (act === 'add-rule') { state.rules.push({ field: '', op: 'eq', value: '' }); state.preview = null; return render(); }
		if (act === 'del-rule') { state.rules.splice(parseInt(btn.dataset.i, 10), 1); state.preview = null; return render(); }
		if (act === 'preview') return doPreview();
		if (act === 'toggle-field') return toggleField(btn.dataset.id, btn.checked);
		if (act === 'chip-remove') return toggleField(btn.dataset.id, false);
		if (act === 'toggle-group') return toggleGroup(btn.dataset.group);
		if (act === 'format') { state.format = btn.dataset.v; return render(); }
		if (act === 'delimiter') { state.csvDelimiter = btn.dataset.v; return render(); }
		if (act === 'pacing') { state.pacing = btn.dataset.v; return render(); }
		if (act === 'export') return startExport();
		if (act === 'save-template') return saveTemplate();
		if (act === 'stop') return pauseExport();
		if (act === 'resume') return resumeExport();
		if (act === 'load-job') return loadJob(btn.dataset.id);
		if (act === 'rerun-job') return rerunJob(btn.dataset.id);
		if (act === 'load-template') return loadTemplate(btn.dataset.id);
		if (act === 'del-template') return deleteTemplate(btn.dataset.id);
		if (act === 'del-job') return deleteJob(btn.dataset.id);
		if (act === 'dismiss-error') { state.error = ''; return render(); }
	});

	function pickSource(id, label) {
		state.sourceId = id;
		state.sourceLabel = label;
		state.fieldGroups = [];
		state.selected = [];
		state.columnLabels = {};
		state.rules = [];
		state.preview = null;
		state.running = null;
		render();

		apiFetch({ path: '/' + BOOT.restBase + '/fields?source=' + encodeURIComponent(id) })
			.then(function (groups) {
				state.fieldGroups = groups;
				const core = groups.find(function (g) { return g.label.indexOf('Core') === 0 || g.label === 'Columns'; });
				state.selected = core ? core.fields.map(function (f) { return f.id; }) : [];
				render();
			})
			.catch(fail);
	}

	function applyConfig(cfg, label) {
		state.sourceId = cfg.source;
		state.sourceLabel = findSourceLabel(cfg.source);
		state.selected = (cfg.fields || []).slice();
		state.columnLabels = Object.assign({}, cfg.column_labels || {});
		state.relation = (cfg.filters && cfg.filters.relation) || 'AND';
		state.rules = (cfg.filters && cfg.filters.rules) ? cfg.filters.rules.map(function (r) {
			return { field: r.field || '', op: r.op || 'eq', value: r.value || '' };
		}) : [];
		state.format = cfg.format || 'csv';
		state.csvDelimiter = cfg.csv_delimiter || 'comma';
		state.pacing = inferPacing(cfg.batch_size);
		state.label = label || '';
		state.preview = null;
		state.running = null;
		state.error = '';

		return apiFetch({ path: '/' + BOOT.restBase + '/fields?source=' + encodeURIComponent(cfg.source) })
			.then(function (groups) {
				state.fieldGroups = groups;
				render();
			});
	}

	function loadJob(id) {
		apiFetch({ path: '/' + BOOT.restBase + '/jobs/' + id })
			.then(function (job) {
				return applyConfig(job.config, job.label);
			})
			.catch(fail);
	}

	function loadTemplate(id) {
		const tpl = state.templates.find(function (t) { return t.id === id; });
		if (!tpl) return;
		applyConfig(tpl.config, tpl.name).catch(fail);
	}

	function rerunJob(id) {
		state.error = '';
		state.stopRequested = false;
		apiFetch({ path: '/' + BOOT.restBase + '/jobs/' + id + '/rerun', method: 'POST' })
			.then(function (job) {
				state.running = job;
				render();
				if (job.status !== 'done') pump(job.id);
				else refreshJobs();
			})
			.catch(fail);
	}

	function saveTemplate() {
		if (!state.selected.length) return;
		const name = window.prompt('Template name');
		if (!name || !name.trim()) return;
		apiFetch({
			path: '/' + BOOT.restBase + '/templates',
			method: 'POST',
			data: { name: name.trim(), config: config() },
		})
			.then(function () { refreshTemplates(); })
			.catch(fail);
	}

	function toggleField(id, on) {
		const at = state.selected.indexOf(id);
		if (on && at === -1) state.selected.push(id);
		if (!on && at !== -1) {
			state.selected.splice(at, 1);
			delete state.columnLabels[id];
		}
		render();
	}

	function toggleGroup(labelText) {
		const group = state.fieldGroups.find(function (g) { return g.label === labelText; });
		if (!group) return;
		const q = state.search.toLowerCase();
		const visible = group.fields.filter(function (f) { return !q || f.label.toLowerCase().indexOf(q) !== -1; });
		const allOn = visible.every(function (f) { return state.selected.indexOf(f.id) !== -1; });
		visible.forEach(function (f) {
			const at = state.selected.indexOf(f.id);
			if (allOn && at !== -1) {
				state.selected.splice(at, 1);
				delete state.columnLabels[f.id];
			}
			if (!allOn && at === -1) state.selected.push(f.id);
		});
		render();
	}

	function config() {
		const labels = {};
		state.selected.forEach(function (id) {
			if (state.columnLabels[id]) labels[id] = state.columnLabels[id];
		});
		return {
			source: state.sourceId,
			fields: state.selected,
			filters: { relation: state.relation, rules: state.rules.filter(function (r) { return r.field; }) },
			format: state.format,
			csv_delimiter: state.csvDelimiter,
			column_labels: labels,
			batch_size: PACING[state.pacing].batch,
			label: state.label || state.sourceLabel,
		};
	}

	function doPreview() {
		if (!state.selected.length) { state.error = 'Pick at least one field first.'; return render(); }
		state.previewBusy = true;
		state.preview = null;
		render();
		apiFetch({ path: '/' + BOOT.restBase + '/preview', method: 'POST', data: config() })
			.then(function (p) { state.preview = p; state.previewBusy = false; render(); })
			.catch(function (err) { state.previewBusy = false; fail(err); });
	}

	function startExport() {
		state.error = '';
		state.stopRequested = false;
		apiFetch({ path: '/' + BOOT.restBase + '/jobs', method: 'POST', data: config() })
			.then(function (job) {
				state.running = job;
				render();
				if (job.status !== 'done') pump(job.id);
				else refreshJobs();
			})
			.catch(fail);
	}

	function resumeExport() {
		if (!state.running) return;
		state.stopRequested = false;
		state.running.status = 'running';
		render();
		pump(state.running.id);
	}

	function pauseExport() {
		if (!state.running || state.running.status !== 'running') return;
		state.stopRequested = true;
		apiFetch({ path: '/' + BOOT.restBase + '/jobs/' + state.running.id + '/pause', method: 'POST' })
			.then(function (job) {
				state.running = job;
				state.stopRequested = false;
				render();
			})
			.catch(fail);
	}

	function pump(id) {
		if (state.stopRequested) return;
		apiFetch({ path: '/' + BOOT.restBase + '/jobs/' + id + '/run', method: 'POST' })
			.then(function (job) {
				state.running = job;
				render();
				if (job.status === 'running') {
					setTimeout(function () { pump(id); }, PACING[state.pacing].delay);
				} else {
					refreshJobs();
				}
			})
			.catch(function (err) {
				state.running.status = 'error';
				state.running.error = (err && err.message) || 'Request failed — your host may have hiccupped. Resume to continue where it left off.';
				render();
			});
	}

	function refreshJobs() {
		apiFetch({ path: '/' + BOOT.restBase + '/jobs' })
			.then(function (jobs) { state.jobs = jobs; render(); })
			.catch(function () {});
	}

	function refreshTemplates() {
		apiFetch({ path: '/' + BOOT.restBase + '/templates' })
			.then(function (templates) { state.templates = templates; render(); })
			.catch(function () {});
	}

	function deleteJob(id) {
		apiFetch({ path: '/' + BOOT.restBase + '/jobs/' + id, method: 'DELETE' })
			.then(function () {
				if (state.running && state.running.id === id) state.running = null;
				refreshJobs();
			})
			.catch(fail);
	}

	function deleteTemplate(id) {
		apiFetch({ path: '/' + BOOT.restBase + '/templates/' + id, method: 'DELETE' })
			.then(refreshTemplates)
			.catch(fail);
	}

	function fail(err) {
		state.error = (err && err.message) || 'Something went wrong.';
		render();
	}

	render();
	apiFetch({ path: '/' + BOOT.restBase + '/sources' })
		.then(function (sources) { state.sources = sources; render(); })
		.catch(fail);
	refreshJobs();
	refreshTemplates();
})();
