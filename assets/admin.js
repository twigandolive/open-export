/**
 * Open Export admin app: one state object, render functions, delegated
 * events, and wp.apiFetch (attaches the REST nonce). All strings flow
 * through wp.i18n and are escaped on output.
 */
(function () {
	'use strict';

	const apiFetch = window.wp.apiFetch;
	const { __, _n, sprintf } = window.wp.i18n;
	const BOOT = window.OPEX_BOOT || {};

	if ( apiFetch && BOOT.restNonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( BOOT.restNonce ) );
	}

	const OPS = [
		['eq', __( 'equals', 'open-export' )],
		['neq', __( 'does not equal', 'open-export' )],
		['contains', __( 'contains', 'open-export' )],
		['not_contains', __( 'does not contain', 'open-export' )],
		['starts_with', __( 'starts with', 'open-export' )],
		['ends_with', __( 'ends with', 'open-export' )],
		['gt', '>'], ['gte', '≥'], ['lt', '<'], ['lte', '≤'],
		['in', __( 'is one of (comma list)', 'open-export' )],
		['not_in', __( 'is not one of (comma list)', 'open-export' )],
		['empty', __( 'is empty', 'open-export' )],
		['not_empty', __( 'is not empty', 'open-export' )],
	];

	const PACING = {
		gentle:   { batch: 100,  delay: 1500, name: __( 'Gentle', 'open-export' ),   label: __( 'Gentle: shared / budget hosting', 'open-export' ) },
		balanced: { batch: 400,  delay: 400,  name: __( 'Balanced', 'open-export' ), label: __( 'Balanced: most sites', 'open-export' ) },
		fast:     { batch: 2000, delay: 50,   name: __( 'Fast', 'open-export' ),     label: __( 'Fast: VPS / dedicated', 'open-export' ) },
	};

	const DELIMITERS = {
		comma:     { label: __( 'Comma', 'open-export' ) },
		semicolon: { label: __( 'Semicolon', 'open-export' ) },
		tab:       { label: __( 'Tab', 'open-export' ) },
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
		dateRange: { field: '', preset: 'all_time', from: '', to: '' },
		dateFields: [],
		presets: [],
		timezone: '',
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
		sourceTab: 'standard',
		sourceSearch: '',
		collapsed: {},
		collapsedReady: false,
		error: '',
		aiOpen: false,
		aiScopeAll: false,
		aiBusy: false,
		aiRecipe: '',
		aiNotes: [],
		aiStatus: '',
		aiStatusKind: '',
		aiReport: null,
	};

	const app = document.getElementById('opex-app');

	/**
	 * Escape for both text and quoted-attribute contexts.
	 *
	 * The previous textContent -> innerHTML round-trip escaped & < > only, and
	 * every call site here interpolates into a double-quoted attribute. Values
	 * such as meta keys and post-type labels come from the database, where any
	 * plugin can put a quote character, so quotes have to go too.
	 */
	function esc(value) {
		return String(value == null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function fmtNum(n) {
		return Number(n || 0).toLocaleString();
	}

	function fmtSize(bytes) {
		if (!bytes) return '-';
		const units = ['B', 'KB', 'MB', 'GB'];
		let i = 0, v = bytes;
		while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
		return v.toFixed(v >= 10 || i === 0 ? 0 : 1) + ' ' + units[i];
	}

	function fieldLabel(id) {
		return id.replace(/^(core|meta|tax|computed|col|woo):/, '');
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
			introSteps() +
			aiSection() +
			stepSource() +
			(state.sourceId ? stepFilters() + stepFields() + stepExport() : '') +
			savedTemplates() +
			recentJobs();
		bindDynamic();
	}

	function header() {
		return '<div class="opex-hero">' +
			'<h1><span class="dashicons dashicons-database-export"></span> ' + esc( __( 'Open Export', 'open-export' ) ) + '</h1>' +
			'<p>' + esc( __( 'Pick a source, filter it down, choose your fields, export. Everything included; no add-ons, ever.', 'open-export' ) ) + '</p>' +
			(state.error ? '<div class="opex-error">' + esc(state.error) + ' <button type="button" class="opex-link" data-act="dismiss-error">' + esc( __( 'dismiss', 'open-export' ) ) + '</button></div>' : '') +
			'</div>';
	}

	/** Escape a translated step, then swap the %1$s/%2$s markers for <strong> tags. */
	function introStep(text) {
		return '<li>' + esc(text).replace('%1$s', '<strong>').replace('%2$s', '</strong>') + '</li>';
	}

	function introSteps() {
		return '<div class="opex-intro-steps-wrap">' +
			'<ol class="opex-intro-steps">' +
			/* translators: %1$s / %2$s wrap the leading verb in <strong> tags. */
			introStep( __( '%1$sSelect%2$s what you want to export.', 'open-export' ) ) +
			/* translators: %1$s / %2$s wrap the leading verb in <strong> tags. */
			introStep( __( '%1$sFilter%2$s your data when the next sections appear below (optional).', 'open-export' ) ) +
			/* translators: %1$s / %2$s wrap the leading verb in <strong> tags. */
			introStep( __( '%1$sExport%2$s: choose fields, set your parameters, and download.', 'open-export' ) ) +
			'</ol></div>';
	}

	function ensureCollapsedDefaults() {
		if (state.collapsedReady) return;
		state.sources.forEach(function (g) {
			if (state.collapsed[g.id] === undefined) {
				state.collapsed[g.id] = 'content' !== g.id;
			}
		});
		state.collapsedReady = true;
	}

	function visibleSourceGroups() {
		ensureCollapsedDefaults();
		return state.sources.filter(function (g) {
			if (!g.items.length) return false;
			const tier = g.tier || 'standard';
			if (state.sourceTab === 'advanced') return tier === 'advanced';
			return tier !== 'advanced';
		});
	}

	function filteredSourceItems(items) {
		const q = (state.sourceSearch || '').toLowerCase().trim();
		if (!q) return items;
		return items.filter(function (item) {
			return item.label.toLowerCase().indexOf(q) !== -1;
		});
	}

	function aiSection() {
		if (!state.aiOpen) {
			return '<section class="opex-section opex-ai-closed">' +
				'<button type="button" class="opex-ai-open" data-act="ai-toggle">' +
				'<span class="dashicons dashicons-editor-code"></span> ' +
				esc( __( 'Work with an AI assistant', 'open-export' ) ) +
				' <span class="opex-muted">' + esc( __( 'describe this site to it, then paste back what it asks for', 'open-export' ) ) + '</span>' +
				'</button></section>';
		}

		const notes = state.aiNotes.length
			? '<ul class="opex-ai-notes">' + state.aiNotes.map(function (n) {
				return '<li>' + esc(n) + '</li>';
			}).join('') + '</ul>'
			: '';

		const status = state.aiStatus
			? '<p class="opex-ai-status' + (state.aiStatusKind ? ' is-' + esc(state.aiStatusKind) : '') + '">' + esc(state.aiStatus) + '</p>'
			: '';

		/* translators: %s: the selected source name, e.g. "WooCommerce Orders". */
		const scopeNote = state.sourceId
			? sprintf( esc( __( 'Describes %s in full.', 'open-export' ) ), '<strong>' + esc(state.sourceLabel) + '</strong>' )
			: esc( __( 'Pick a source first for a detailed description, or send the site overview as it is.', 'open-export' ) );

		const body =
			'<div class="opex-ai-grid">' +
			'<div class="opex-ai-half">' +
			'<h3>' + esc( __( '1. Describe this site', 'open-export' ) ) + '</h3>' +
			'<p class="opex-muted">' + scopeNote + '</p>' +
			'<p class="opex-hint">' + esc( __( 'Copy this JSON into your assistant and tell it what report you want. It lists every source, field, operator, and date option this site has.', 'open-export' ) ) + '</p>' +
			'<label class="opex-ai-check"><input type="checkbox" data-act="ai-scope"' + (state.aiScopeAll ? ' checked' : '') + '> ' +
			esc( __( 'Include every source, not just the selected one (much larger)', 'open-export' ) ) + '</label>' +
			'<div class="opex-row-actions">' +
			'<button type="button" class="button button-secondary" data-act="ai-copy"' + (state.aiBusy ? ' disabled' : '') + '>' + esc( __( 'Copy schema', 'open-export' ) ) + '</button> ' +
			'<button type="button" class="button" data-act="ai-download"' + (state.aiBusy ? ' disabled' : '') + '>' + esc( __( 'Download .json', 'open-export' ) ) + '</button>' +
			'</div></div>' +

			'<div class="opex-ai-half">' +
			'<h3>' + esc( __( '2. Paste what it sends back', 'open-export' ) ) + '</h3>' +
			'<p class="opex-hint">' + esc( __( 'The assistant replies with a short JSON recipe. Paste it here and the screen configures itself; nothing exports until you press Start export.', 'open-export' ) ) + '</p>' +
			'<textarea id="opex-ai-recipe" class="opex-ai-textarea" rows="7" spellcheck="false" placeholder="' + esc( '{ "source": "woo:orders", "fields": [ ... ] }' ) + '">' + esc(state.aiRecipe) + '</textarea>' +
			'<div class="opex-row-actions">' +
			'<button type="button" class="button button-primary" data-act="ai-apply"' + (state.aiBusy ? ' disabled' : '') + '>' + esc( __( 'Apply recipe', 'open-export' ) ) + '</button>' +
			'</div>' + status + resolutionReport() + notes +
			'</div></div>';

		return '<section class="opex-section opex-ai">' +
			'<h2><span class="opex-step is-plain"><span class="dashicons dashicons-editor-code"></span></span> ' +
			esc( __( 'Work with an AI assistant', 'open-export' ) ) +
			' <button type="button" class="opex-link opex-ai-close" data-act="ai-toggle">' + esc( __( 'hide', 'open-export' ) ) + '</button></h2>' +
			'<div class="opex-section-body">' + body + '</div></section>';
	}

	/**
	 * Every id the recipe asked for, and what became of it. An assistant is
	 * guessing from a document, so a wrong guess has to be visible rather than
	 * silently absent from the export.
	 */
	function resolutionReport() {
		const r = state.aiReport;
		if (!r) return '';

		const row = function (id, status, reason, suggestion, extra) {
			const cls = status === 'matched' ? 'is-matched' : (status === 'substituted' ? 'is-sub' : 'is-dropped');
			const mark = status === 'matched' ? '✓' : (status === 'substituted' ? '~' : '✕');
			let detail = reason ? esc(reason) : '';
			if (suggestion) {
				/* translators: %s: a field id being offered as a closer match. */
				detail += (detail ? ' ' : '') + sprintf( esc( __( 'Did you mean %s?', 'open-export' ) ), '<code>' + esc(suggestion) + '</code>' );
			}
			if (extra) {
				/* translators: %s: the field id that was used instead. */
				detail += (detail ? ' ' : '') + sprintf( esc( __( 'Used %s instead.', 'open-export' ) ), '<code>' + esc(extra) + '</code>' );
			}
			return '<tr class="' + cls + '"><td class="opex-rr-mark">' + mark + '</td>' +
				'<td><code>' + esc(id) + '</code></td>' +
				'<td>' + (detail || '<span class="opex-muted">' + esc( __( 'matched', 'open-export' ) ) + '</span>') + '</td></tr>';
		};

		let rows = (r.fields || []).map(function (f) {
			return row(f.id, f.status, f.reason, f.suggestion, null);
		}).join('');

		rows += (r.filters || []).map(function (f) {
			return row(
				/* translators: 1: a field id, 2: a filter operator. */
				sprintf( __( 'filter: %1$s %2$s', 'open-export' ), f.field, f.op ),
				f.status, f.reason, f.suggestion, null
			);
		}).join('');

		if (r.date_range) {
			rows += row(
				/* translators: %s: a date field id. */
				sprintf( __( 'date range: %s', 'open-export' ), r.date_range.field || '(none)' ),
				r.date_range.status, r.date_range.reason, r.date_range.suggestion, r.date_range.used_instead
			);
		}

		if (!rows) return '';

		const s = r.summary || {};
		/* translators: 1: number of fields matched, 2: number requested. */
		const head = sprintf( esc( __( '%1$d of %2$d fields resolved', 'open-export' ) ), s.fields_matched || 0, s.fields_requested || 0 );

		return '<div class="opex-rr">' +
			'<p class="opex-rr-head">' + head + '</p>' +
			'<table class="opex-rr-table"><tbody>' + rows + '</tbody></table>' +
			'</div>';
	}

	function stepSource() {
		let body;
		if (!state.sources.length) {
			body = '<p class="opex-muted">' + esc( __( 'Loading sources…', 'open-export' ) ) + '</p>';
		} else {
			const tabs =
				'<div class="opex-source-tabs">' +
				'<button type="button" class="opex-tab' + (state.sourceTab === 'standard' ? ' is-on' : '') + '" data-act="source-tab" data-v="standard">' + esc( __( 'Common sources', 'open-export' ) ) + '</button>' +
				'<button type="button" class="opex-tab' + (state.sourceTab === 'advanced' ? ' is-on' : '') + '" data-act="source-tab" data-v="advanced">' + esc( __( 'Advanced: database tables', 'open-export' ) ) + '</button>' +
				'</div>';

			const search = state.sourceTab === 'advanced'
				? '<input type="search" id="opex-source-search" class="opex-search" placeholder="' + esc( __( 'Search tables…', 'open-export' ) ) + '" value="' + esc(state.sourceSearch) + '">'
				: '<p class="opex-muted opex-tab-hint">' + esc( __( 'Post types, users, comments, and taxonomies. Plugin tables live under Advanced.', 'open-export' ) ) + '</p>';

			body = tabs + search + visibleSourceGroups().map(function (group) {
				const items = filteredSourceItems(group.items);
				const collapsed = !!state.collapsed[group.id];
				const arrow = collapsed ? 'dashicons-arrow-right-alt2' : 'dashicons-arrow-down-alt2';
				return '<div class="opex-source-group' + (collapsed ? ' is-collapsed' : '') + '">' +
					'<button type="button" class="opex-group-toggle" data-act="toggle-src-group" data-id="' + esc(group.id) + '">' +
					'<span class="dashicons ' + arrow + '"></span>' +
					'<span class="opex-group-toggle-label">' + esc(group.label) + '</span>' +
					'<span class="opex-muted">(' + fmtNum(group.items.length) + ')</span>' +
					'</button>' +
					'<div class="opex-group-body">' +
					(items.length
						? '<div class="opex-cards">' + items.map(function (item) {
							const on = state.sourceId === item.id;
							/* translators: %s: formatted row count. */
							const count = sprintf( _n( '%s row', '%s rows', item.count || 0, 'open-export' ), fmtNum(item.count) );
							return '<button type="button" class="opex-card' + (on ? ' is-on' : '') + '" data-act="pick-source" data-id="' + esc(item.id) + '" data-label="' + esc(item.label) + '">' +
								'<span class="dashicons ' + esc(item.icon) + '"></span>' +
								'<span class="opex-card-label">' + esc(item.label) + '</span>' +
								'<span class="opex-card-count">' + esc(count) + '</span>' +
								'</button>';
						}).join('') + '</div>'
						: '<p class="opex-muted">' + esc( __( 'No matches.', 'open-export' ) ) + '</p>') +
					'</div></div>';
			}).join('');
		}
		return section(1, esc( __( 'What do you want to export?', 'open-export' ) ), body);
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

	function dateRangeBlock() {
		if (!state.dateFields.length) return '';

		const dr = state.dateRange;
		const custom = dr.preset === 'custom';

		const presetOpts = state.presets.map(function (p) {
			return '<option value="' + esc(p.id) + '"' + (dr.preset === p.id ? ' selected' : '') + '>' + esc(p.label) + '</option>';
		}).join('');

		const fieldOpts = state.dateFields.map(function (f) {
			return '<option value="' + esc(f.id) + '"' + (dr.field === f.id ? ' selected' : '') + '>' + esc(f.label) + '</option>';
		}).join('');

		const customRow = custom
			? '<div class="opex-date-custom">' +
				'<label>' + esc( __( 'From', 'open-export' ) ) + ' <input type="date" id="opex-date-from" value="' + esc(dr.from) + '"></label>' +
				'<label>' + esc( __( 'To', 'open-export' ) ) + ' <input type="date" id="opex-date-to" value="' + esc(dr.to) + '"></label>' +
				'</div>'
			: '';

		const showField = dr.preset !== 'all_time' && state.dateFields.length > 1;

		/* translators: %s: the site's timezone, e.g. America/Los_Angeles. */
		const tzNote = state.timezone
			? '<p class="opex-hint">' + esc( sprintf( __( 'Dates are whole days in your site timezone (%s). The end date is included in full.', 'open-export' ), state.timezone ) ) + '</p>'
			: '';

		return '<div class="opex-daterange">' +
			'<div class="opex-date-row">' +
			'<label>' + esc( __( 'Date range', 'open-export' ) ) + ' <select id="opex-date-preset">' + presetOpts + '</select></label>' +
			(showField ? '<label>' + esc( __( 'based on', 'open-export' ) ) + ' <select id="opex-date-field">' + fieldOpts + '</select></label>' : '') +
			'</div>' + customRow + (dr.preset !== 'all_time' ? tzNote : '') +
			'</div>';
	}

	function stepFilters() {
		const rows = state.rules.map(function (rule, i) {
			const noValue = rule.op === 'empty' || rule.op === 'not_empty';
			return '<div class="opex-rule" data-i="' + i + '">' +
				'<select class="opex-rule-field">' + filterFieldOptions() + '</select>' +
				'<select class="opex-rule-op">' + OPS.map(function (op) {
					return '<option value="' + op[0] + '"' + (rule.op === op[0] ? ' selected' : '') + '>' + esc(op[1]) + '</option>';
				}).join('') + '</select>' +
				'<input type="text" class="opex-rule-value" placeholder="' + esc( __( 'value or last 30 days, this month…', 'open-export' ) ) + '"' + (noValue ? ' disabled' : '') + ' value="' + esc(rule.value) + '">' +
				'<button type="button" class="opex-icon-btn" data-act="del-rule" data-i="' + i + '" title="' + esc( __( 'Remove rule', 'open-export' ) ) + '">&times;</button>' +
				'</div>';
		}).join('');

		const relation = state.rules.length > 1
			? '<div class="opex-relation">' + esc( __( 'Match', 'open-export' ) ) + ' <select id="opex-relation">' +
				'<option value="AND"' + (state.relation === 'AND' ? ' selected' : '') + '>' + esc( __( 'ALL rules (AND)', 'open-export' ) ) + '</option>' +
				'<option value="OR"' + (state.relation === 'OR' ? ' selected' : '') + '>' + esc( __( 'ANY rule (OR)', 'open-export' ) ) + '</option>' +
				'</select></div>'
			: '';

		const preview = state.preview
			? previewTable()
			: '<p class="opex-muted">' + esc(state.previewBusy ? __( 'Loading preview…', 'open-export' ) : __( 'Use Preview to sanity-check before exporting.', 'open-export' )) + '</p>';

		/* translators: %1$s, %2$s, %3$s: literal date-filter tokens; they must stay in English because the server parses them. */
		const hint = sprintf( esc( __( 'Date filters accept tokens like %1$s, %2$s, %3$s (use with ≥ or ≤).', 'open-export' ) ),
			'<code>last 30 days</code>', '<code>this month</code>', '<code>-7 days</code>' );

		const body =
			dateRangeBlock() +
			'<div class="opex-rules">' + rows + '</div>' + relation +
			'<p class="opex-hint">' + hint + '</p>' +
			'<div class="opex-row-actions">' +
			'<button type="button" class="button" data-act="add-rule">' + esc( __( '+ Add filter', 'open-export' ) ) + '</button> ' +
			'<button type="button" class="button button-secondary" data-act="preview"' + (state.previewBusy ? ' disabled' : '') + '>' + esc( __( 'Preview first 5 rows', 'open-export' ) ) + '</button>' +
			'</div>' + preview;

		return section(2, esc( __( 'Filter it down', 'open-export' ) ) + ' <span class="opex-optional">' + esc( __( '(optional)', 'open-export' ) ) + '</span>', body);
	}

	function previewTable() {
		const p = state.preview;
		const head = p.headers.map(function (h) { return '<th>' + esc(h) + '</th>'; }).join('');
		const rows = p.rows.length
			? p.rows.map(function (row) {
				return '<tr>' + row.map(function (cell) { return '<td>' + esc(cell) + '</td>'; }).join('') + '</tr>';
			}).join('')
			: '<tr><td colspan="' + p.headers.length + '" class="opex-muted">' + esc( __( 'No rows match your filters.', 'open-export' ) ) + '</td></tr>';
		/* translators: %s: formatted number of matching rows. */
		const total = sprintf( esc( _n( '%s row matches.', '%s rows match.', p.total || 0, 'open-export' ) ), '<strong>' + fmtNum(p.total) + '</strong>' );
		return '<p class="opex-total">' + total + '</p>' +
			'<div class="opex-table-scroll"><table class="opex-table"><thead><tr>' + head + '</tr></thead><tbody>' + rows + '</tbody></table></div>';
	}

	function stepFields() {
		if (!state.fieldGroups.length) {
			return section(3, esc( __( 'Pick your fields', 'open-export' ) ), '<p class="opex-muted">' + esc( __( 'Loading fields…', 'open-export' ) ) + '</p>');
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
				'<button type="button" class="opex-link" data-act="toggle-group" data-group="' + esc(group.label) + '">' + esc(allOn ? __( 'none', 'open-export' ) : __( 'all', 'open-export' )) + '</button>' +
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
				return '<span class="opex-chip" draggable="true" data-id="' + esc(id) + '" title="' + esc( __( 'Drag to reorder', 'open-export' ) ) + '">' +
					'<input type="text" class="opex-chip-label" data-id="' + esc(id) + '" value="' + esc(chipDisplayLabel(id)) + '">' +
					'<button type="button" data-act="chip-remove" data-id="' + esc(id) + '" title="' + esc( __( 'Remove', 'open-export' ) ) + '">&times;</button></span>';
			}).join('') + '</div>'
			: '<p class="opex-muted">' + esc( __( 'Nothing selected yet; tick fields below. Drag chips to reorder; edit labels for custom column headers.', 'open-export' ) ) + '</p>';

		/* translators: %d: number of selected fields. */
		const selectedCount = sprintf( _n( '%d field selected', '%d fields selected', state.selected.length, 'open-export' ), state.selected.length );

		const body =
			'<div class="opex-selected"><strong>' + esc(selectedCount) + '</strong>' + chips + '</div>' +
			'<input type="search" id="opex-search" class="opex-search" placeholder="' + esc( __( 'Search fields…', 'open-export' ) ) + '" value="' + esc(state.search) + '">' +
			groups;

		return section(3, esc( __( 'Pick your fields', 'open-export' ) ), body);
	}

	function stepExport() {
		const running = state.running;
		let progress = '';

		if (running) {
			const pct = running.total > 0 ? Math.min(100, Math.round(running.processed / running.total * 100)) : (running.status === 'done' ? 100 : 0);
			/* translators: %1$s: rows processed, %2$s: total rows, %3$s: percent complete. */
			const progressText = sprintf( __( '%1$s / %2$s rows (%3$s%%)', 'open-export' ), fmtNum(running.processed), fmtNum(running.total), pct );
			progress =
				'<div class="opex-progress"><div class="opex-progress-bar' + (running.status === 'done' ? ' is-done' : '') + '" style="width:' + pct + '%"></div></div>' +
				'<p class="opex-progress-text">' + esc(progressText) +
				(running.status === 'running' ? ' <button type="button" class="opex-link" data-act="stop">' + esc( __( 'stop', 'open-export' ) ) + '</button>' : '') + '</p>';
			if (running.status === 'done') {
				/* translators: %s: export file name. */
				const downloadText = sprintf( __( 'Download %s', 'open-export' ), running.filename || __( 'file', 'open-export' ) );
				progress += '<p><a class="button button-primary" href="' + downloadUrl(running.id) + '">' + esc(downloadText) + '</a></p>';
			}
			if (running.status === 'error') {
				progress += '<p class="opex-error">' + esc(running.error || __( 'Export failed.', 'open-export' )) + ' <button type="button" class="opex-link" data-act="resume">' + esc( __( 'retry / resume', 'open-export' ) ) + '</button></p>';
			}
			if (running.status === 'paused') {
				progress += '<p class="opex-muted">' + esc( __( 'Paused.', 'open-export' ) ) + ' <button type="button" class="opex-link" data-act="resume">' + esc( __( 'resume', 'open-export' ) ) + '</button></p>';
			}
		}

		const delimBlock = state.format === 'csv'
			? '<div><label class="opex-label">' + esc( __( 'CSV delimiter', 'open-export' ) ) + '</label><div class="opex-pills">' +
				Object.keys(DELIMITERS).map(function (k) {
					return '<button type="button" class="opex-pill' + (state.csvDelimiter === k ? ' is-on' : '') + '" data-act="delimiter" data-v="' + k + '">' + esc(DELIMITERS[k].label) + '</button>';
				}).join('') + '</div></div>'
			: '';

		/* translators: %s: name of the selected data source. */
		const exportText = sprintf( __( 'Export %s', 'open-export' ), state.sourceLabel );

		const body =
			'<div class="opex-export-grid">' +
			'<div><label class="opex-label">' + esc( __( 'Format', 'open-export' ) ) + '</label><div class="opex-pills">' +
			['csv', 'json', 'xml'].map(function (f) {
				return '<button type="button" class="opex-pill' + (state.format === f ? ' is-on' : '') + '" data-act="format" data-v="' + f + '">' + f.toUpperCase() + '</button>';
			}).join('') + '</div></div>' +
			delimBlock +
			'<div><label class="opex-label">' + esc( __( 'Pacing', 'open-export' ) ) + ' <span class="opex-muted">' + esc( __( '(be kind to your host)', 'open-export' ) ) + '</span></label><div class="opex-pills">' +
			Object.keys(PACING).map(function (k) {
				return '<button type="button" class="opex-pill' + (state.pacing === k ? ' is-on' : '') + '" data-act="pacing" data-v="' + k + '" title="' + esc(PACING[k].label) + '">' +
					esc(PACING[k].name) + '</button>';
			}).join('') + '</div></div>' +
			'<div><label class="opex-label" for="opex-label-input">' + esc( __( 'Export name', 'open-export' ) ) + '</label>' +
			'<input type="text" id="opex-label-input" placeholder="' + esc(state.sourceLabel || __( 'my-export', 'open-export' )) + '" value="' + esc(state.label) + '"></div>' +
			'</div>' +
			'<p class="opex-run">' +
			'<button type="button" class="button button-primary button-hero" data-act="export"' +
			((state.running && state.running.status === 'running') || !state.selected.length ? ' disabled' : '') + '>' + esc(exportText) + '</button> ' +
			'<button type="button" class="button" data-act="save-template"' + (!state.selected.length ? ' disabled' : '') + '>' + esc( __( 'Save as template', 'open-export' ) ) + '</button>' +
			'</p>' +
			progress;

		return section(4, esc( __( 'Export', 'open-export' ) ), body);
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
				'<button type="button" class="button button-small" data-act="load-template" data-id="' + esc(tpl.id) + '">' + esc( __( 'Load', 'open-export' ) ) + '</button> ' +
				'<button type="button" class="button button-small" data-act="del-template" data-id="' + esc(tpl.id) + '">' + esc( __( 'Delete', 'open-export' ) ) + '</button>' +
				'</td></tr>';
		}).join('');

		const body = '<div class="opex-table-scroll"><table class="opex-table">' +
			'<thead><tr><th>' + esc( __( 'Name', 'open-export' ) ) + '</th><th>' + esc( __( 'Source', 'open-export' ) ) + '</th><th>' + esc( __( 'Format', 'open-export' ) ) + '</th><th>' + esc( __( 'Saved', 'open-export' ) ) + '</th><th></th></tr></thead>' +
			'<tbody>' + rows + '</tbody></table></div>';

		return section('T', esc( __( 'Saved templates', 'open-export' ) ), body, true);
	}

	function recentJobs() {
		if (!state.jobs.length) return '';
		const rows = state.jobs.map(function (job) {
			const date = new Date(job.created * 1000).toLocaleString();
			const status = job.status === 'done' ? '<span class="opex-badge is-done">' + esc( __( 'done', 'open-export' ) ) + '</span>'
				: job.status === 'error' ? '<span class="opex-badge is-error">' + esc( __( 'error', 'open-export' ) ) + '</span>'
				: '<span class="opex-badge">' + esc(job.status) + '</span>';
			return '<tr>' +
				'<td>' + esc(job.label) + '</td>' +
				'<td>' + esc(job.format.toUpperCase()) + '</td>' +
				'<td>' + fmtNum(job.processed) + '</td>' +
				'<td>' + fmtSize(job.filesize) + '</td>' +
				'<td>' + esc(date) + '</td>' +
				'<td>' + status + '</td>' +
				'<td class="opex-job-actions">' +
				'<button type="button" class="button button-small" data-act="load-job" data-id="' + esc(job.id) + '">' + esc( __( 'Load', 'open-export' ) ) + '</button> ' +
				'<button type="button" class="button button-small" data-act="rerun-job" data-id="' + esc(job.id) + '">' + esc( __( 'Re-run', 'open-export' ) ) + '</button> ' +
				(job.status === 'done' ? '<a class="button button-small" href="' + downloadUrl(job.id) + '">' + esc( __( 'Download', 'open-export' ) ) + '</a> ' : '') +
				'<button type="button" class="button button-small" data-act="del-job" data-id="' + esc(job.id) + '">' + esc( __( 'Delete', 'open-export' ) ) + '</button>' +
				'</td></tr>';
		}).join('');

		const body = '<div class="opex-table-scroll"><table class="opex-table">' +
			'<thead><tr><th>' + esc( __( 'Name', 'open-export' ) ) + '</th><th>' + esc( __( 'Format', 'open-export' ) ) + '</th><th>' + esc( __( 'Rows', 'open-export' ) ) + '</th><th>' + esc( __( 'Size', 'open-export' ) ) + '</th><th>' + esc( __( 'Created', 'open-export' ) ) + '</th><th>' + esc( __( 'Status', 'open-export' ) ) + '</th><th></th></tr></thead>' +
			'<tbody>' + rows + '</tbody></table></div>';

		return section('H', esc( __( 'Recent exports', 'open-export' ) ), body, true);
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

		const recipeBox = document.getElementById('opex-ai-recipe');
		if (recipeBox) {
			recipeBox.addEventListener('input', function () { state.aiRecipe = this.value; });
		}

		const relation = document.getElementById('opex-relation');
		if (relation) {
			relation.addEventListener('change', function () { state.relation = this.value; });
		}

		const preset = document.getElementById('opex-date-preset');
		if (preset) {
			preset.addEventListener('change', function () {
				state.dateRange.preset = this.value;
				if (this.value !== 'custom') { state.dateRange.from = ''; state.dateRange.to = ''; }
				if (!state.dateRange.field && state.dateFields.length) state.dateRange.field = state.dateFields[0].id;
				state.preview = null;
				render();
			});
		}

		const dateField = document.getElementById('opex-date-field');
		if (dateField) {
			dateField.addEventListener('change', function () {
				state.dateRange.field = this.value;
				state.preview = null;
			});
		}

		['from', 'to'].forEach(function (end) {
			const input = document.getElementById('opex-date-' + end);
			if (input) {
				input.addEventListener('change', function () {
					state.dateRange[end] = this.value;
					state.preview = null;
				});
			}
		});

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

		const sourceSearch = document.getElementById('opex-source-search');
		if (sourceSearch) {
			sourceSearch.addEventListener('input', debounce(function () {
				state.sourceSearch = sourceSearch.value;
				const pos = sourceSearch.selectionStart;
				render();
				const again = document.getElementById('opex-source-search');
				if (again) { again.focus(); again.setSelectionRange(pos, pos); }
			}, 200));
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
		if (act === 'source-tab') {
			state.sourceTab = btn.dataset.v;
			state.sourceSearch = '';
			if (state.sourceTab === 'advanced') {
				state.collapsed.tables = false;
			}
			return render();
		}
		if (act === 'toggle-src-group') {
			const id = btn.dataset.id;
			state.collapsed[id] = !state.collapsed[id];
			return render();
		}
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
		if (act === 'ai-toggle') { state.aiOpen = !state.aiOpen; state.aiStatus = ''; return render(); }
		if (act === 'ai-scope') { state.aiScopeAll = btn.checked; return render(); }
		if (act === 'ai-copy') return copySchema();
		if (act === 'ai-download') return downloadSchema();
		if (act === 'ai-apply') return applyRecipe();
	});

	function pickSource(id, label) {
		state.sourceId = id;
		state.sourceLabel = label;
		state.fieldGroups = [];
		state.selected = [];
		state.columnLabels = {};
		state.rules = [];
		state.dateRange = { field: '', preset: 'all_time', from: '', to: '' };
		state.dateFields = [];
		state.preview = null;
		state.running = null;
		render();

		apiFetch({ path: '/' + BOOT.restBase + '/fields?source=' + encodeURIComponent(id) })
			.then(function (payload) {
				applyFieldsPayload(payload);
				const groups = state.fieldGroups;
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
		state.dateRange = {
			field: (cfg.date_range && cfg.date_range.field) || '',
			preset: (cfg.date_range && cfg.date_range.preset) || 'all_time',
			from: (cfg.date_range && cfg.date_range.from) || '',
			to: (cfg.date_range && cfg.date_range.to) || ''
		};
		state.format = cfg.format || 'csv';
		state.csvDelimiter = cfg.csv_delimiter || 'comma';
		state.pacing = inferPacing(cfg.batch_size);
		state.label = label || '';
		state.preview = null;
		state.running = null;
		state.error = '';

		if (cfg.source && cfg.source.indexOf('table:') === 0) {
			state.sourceTab = 'advanced';
			state.collapsed.tables = false;
		}

		return apiFetch({ path: '/' + BOOT.restBase + '/fields?source=' + encodeURIComponent(cfg.source) })
			.then(function (payload) {
				applyFieldsPayload(payload);
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
		const name = window.prompt( __( 'Template name', 'open-export' ) );
		if (!name || !name.trim()) return;
		apiFetch({
			path: '/' + BOOT.restBase + '/templates',
			method: 'POST',
			data: { name: name.trim(), config: config() },
		})
			.then(function () { refreshTemplates(); })
			.catch(fail);
	}

	/**
	 * The /fields endpoint used to return a bare array of groups and now returns
	 * an object carrying the date metadata too. Accept both so a cached script
	 * from a previous version does not break the screen mid-upgrade.
	 */
	function applyFieldsPayload(payload) {
		if (Array.isArray(payload)) {
			state.fieldGroups = payload;
			state.dateFields = [];
			state.presets = [];
			return;
		}
		state.fieldGroups = payload.groups || [];
		state.dateFields = payload.date_fields || [];
		state.presets = payload.presets || [];
		state.timezone = payload.timezone || '';

		const known = state.dateFields.some(function (f) { return f.id === state.dateRange.field; });
		if (!known) {
			state.dateRange.field = state.dateFields.length ? state.dateFields[0].id : '';
		}
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
			date_range: state.dateRange,
			format: state.format,
			csv_delimiter: state.csvDelimiter,
			column_labels: labels,
			batch_size: PACING[state.pacing].batch,
			label: state.label || state.sourceLabel,
		};
	}

	function doPreview() {
		if (!state.selected.length) { state.error = __( 'Pick at least one field first.', 'open-export' ); return render(); }
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
				state.running.error = (err && err.message) || __( 'Request failed. Resume to continue where the export left off.', 'open-export' );
				render();
			});
	}

	function schemaPath() {
		return '/' + BOOT.restBase + '/schema?scope=' + (state.aiScopeAll ? 'all' : 'source') +
			'&source=' + encodeURIComponent(state.sourceId || '');
	}

	function fetchSchema() {
		state.aiBusy = true;
		state.aiStatus = '';
		render();
		return apiFetch({ path: schemaPath() })
			.then(function (doc) {
				state.aiBusy = false;
				return JSON.stringify(doc, null, 2);
			})
			.catch(function (err) { state.aiBusy = false; fail(err); throw err; });
	}

	function copySchema() {
		fetchSchema().then(function (text) {
			const done = function () {
				/* translators: %s: size of the copied JSON, e.g. "48 KB". */
				state.aiStatus = sprintf( __( 'Schema copied (%s). Paste it into your assistant.', 'open-export' ), fmtSize(text.length) );
				render();
			};
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text, done); });
			} else {
				fallbackCopy(text, done);
			}
		}).catch(function () {});
	}

	function fallbackCopy(text, done) {
		const ta = document.createElement('textarea');
		ta.value = text;
		ta.setAttribute('readonly', '');
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild(ta);
		ta.select();
		try { document.execCommand('copy'); done(); }
		catch (e) { state.error = __( 'Could not copy. Use Download .json instead.', 'open-export' ); render(); }
		document.body.removeChild(ta);
	}

	function downloadSchema() {
		fetchSchema().then(function (text) {
			const blob = new Blob([text], { type: 'application/json' });
			const url = URL.createObjectURL(blob);
			const a = document.createElement('a');
			a.href = url;
			a.download = 'open-export-schema.json';
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
			setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
			state.aiStatus = __( 'Schema downloaded.', 'open-export' );
			render();
		}).catch(function () {});
	}

	function applyRecipe() {
		const box = document.getElementById('opex-ai-recipe');
		const text = (box ? box.value : state.aiRecipe).trim();
		state.aiRecipe = text;
		state.aiNotes = [];
		state.aiReport = null;
		state.aiStatus = '';
		state.aiStatusKind = '';

		if (!text) {
			state.aiStatus = __( 'Paste the recipe JSON first.', 'open-export' );
			return render();
		}

		let parsed;
		try {
			parsed = JSON.parse(stripFences(text));
		} catch (e) {
			state.aiStatus = __( 'That is not valid JSON. Copy the whole object, including its outer braces.', 'open-export' );
			return render();
		}

		state.aiBusy = true;
		render();

		apiFetch({ path: '/' + BOOT.restBase + '/recipe', method: 'POST', data: parsed })
			.then(function (result) {
				state.aiBusy = false;
				state.aiNotes = result.notes || [];
				state.aiReport = result.report || null;

				// Nothing resolved: refuse rather than hand back an empty
				// selection that looks like a successful import.
				if (!result.ok || !result.config) {
					state.aiStatusKind = 'error';
					state.aiStatus = (result.report && result.report.reason) ||
						__( 'That recipe could not be applied.', 'open-export' );
					return render();
				}

				const s = (result.report && result.report.summary) || {};
				return applyConfig(result.config, result.name || '').then(function () {
					state.aiStatusKind = s.fields_dropped ? 'warn' : 'ok';
					state.aiStatus = s.fields_dropped
						/* translators: %d: number of field ids that could not be resolved. */
						? sprintf( __( 'Recipe applied, but %d id could not be resolved and was left out. Review below, then press Start export.', 'open-export' ), s.fields_dropped )
						: __( 'Recipe applied in full. Review the fields, then press Start export.', 'open-export' );
					render();
				});
			})
			.catch(function (err) {
				state.aiBusy = false;
				state.aiReport = null;
				state.aiStatusKind = 'error';
				state.aiStatus = (err && err.message) || __( 'That recipe could not be applied.', 'open-export' );
				render();
			});
	}

	/** Assistants often wrap JSON in a markdown code fence; drop it. */
	function stripFences(text) {
		return text.replace(/^\s*```(?:json)?\s*/i, '').replace(/\s*```\s*$/, '').trim();
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
		state.error = (err && err.message) || __( 'Something went wrong.', 'open-export' );
		render();
	}

	render();
	apiFetch({ path: '/' + BOOT.restBase + '/sources' })
		.then(function (sources) { state.sources = sources; render(); })
		.catch(fail);
	refreshJobs();
	refreshTemplates();
})();
