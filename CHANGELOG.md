# Changelog

All notable changes to Open Export are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] - 2026-09-02

### Added

- **Schema export for AI assistants.** A new *Work with an AI assistant* panel produces a JSON document describing everything this specific site can export: every source and its row count, every field with its id and group, the filter operators, the date columns and whether each is stored in UTC or site time, and the enumerated legal values for the fields where a wrong guess silently returns nothing — order statuses, post statuses, user roles, and taxonomy terms. Copy it to the clipboard or download it as `open-export-schema.json`.
- The schema carries its own instructions: a `how_to_use` narrative and a `recipe_format` block stating the exact JSON contract for the reply, so the assistant does not have to infer the return shape.
- **Recipe import.** Paste the JSON an assistant sends back and the export screen configures itself — source, fields and their order, filter rules, date range, format, and custom column headers. Nothing runs until the user presses Start export.
- Recipes are validated field by field against what the source actually has. Anything unrecognised is dropped and reported in plain language ("Field \"woo:customer_lifetime_value\" was ignored: this source does not have it") rather than silently producing a blank column or a wrong result.
- A markdown code fence around the pasted JSON is tolerated, as is a recipe wrapped in an outer `recipe` key.
- Relative date presets survive the round trip unresolved, so a recipe asking for "last month" still means last month whenever it is re-run.
- `GET /open-export/v1/schema` and `POST /open-export/v1/recipe`, both behind the same administrator capability and REST nonce as every other route.

### Fixed

- A filter rule comparing a date column with `is on or before` truncated a bare date to midnight, excluding that whole day. Date columns now use calendar-day semantics: `<= 2026-03-31` covers through the end of the 31st, and `equals` a date matches that day rather than only its first instant.
- A date typed in the compact form, such as `20260301`, was treated as a number, casting the column to `DECIMAL(24,6)` — which reduces a datetime to its year — so the filter matched nothing. Compact dates are now understood, and a date column is never cast to a number whatever the value looks like.
- Choosing a custom field, taxonomy, or computed field on a raw database table source produced silently empty columns; those fields are now rejected with an explanation, since a raw table has only its own columns.
- Fields that a source does not actually have are rejected instead of exporting as blank columns. Meta keys remain exempt, because the picker samples them under a `LIMIT` and absence from that sample does not mean a key is invalid.
- `post_modified_gmt` is now offered in the field picker, matching the columns the query layer already accepted.

## [1.2.0] - 2026-09-02

### Added

- **WooCommerce Orders source.** A dedicated `woo:orders` source that detects whether the store uses High-Performance Order Storage or classic post storage and reads the right one. Under HPOS it queries `wc_orders` joined to `wc_order_addresses` (billing and shipping) and `wc_order_operational_data`; under classic storage it resolves the same friendly field names to the existing `core:`/`meta:` ids, so a saved template survives a store migrating between engines. Refunds are excluded so order totals are not double counted. If HPOS is switched on but the tables have not been migrated yet, the source falls back to post storage rather than failing mid-export.
- **Date-range filter on every source.** Presets (today, yesterday, last 7/30/90 days, this and last week/month/quarter/year) plus a custom From/To range, targeting a source-appropriate date column: `post_date` and its GMT twin for posts, `user_registered` for users, `comment_date` for comments, `date_created_gmt`/`date_paid_gmt`/`date_completed_gmt` for HPOS orders, and any datetime column on a raw table.
- Date ranges are interpreted as whole calendar days in the site's timezone, converting to UTC only for columns that are stored in UTC, and are applied as a half-open interval so an inclusive end date really includes that whole day.
- The date range is always combined with `AND`, independently of the rules' `ALL`/`ANY` setting, so selecting "match ANY rule" cannot widen it back out to every row.
- Relative presets stay unresolved in saved templates, so "last month" still means last month the next time the template runs, and are pinned to concrete dates when a job is created so a long export that crosses midnight stays consistent.

### Fixed

- Relative date tokens (`today`, `yesterday`, `last 30 days`, `this month`, …) were resolved with `gmdate()` against a UTC process clock but compared against `post_date` and `comment_date`, which WordPress stores in site-local time. On a UTC-8 site the `today` boundary rolled over at 4pm local, putting it in the future so the filter matched nothing; other tokens were skewed by the full UTC offset. They now resolve in the site's timezone.
- `this month`, `last month`, `this year`, and `last year` resolved to the first day of the period at the *current time of day* rather than midnight, silently excluding everything earlier on that first day.

### Security

- The admin app's `esc()` helper round-tripped values through `textContent`/`innerHTML`, which escapes `&`, `<`, and `>` but not quote characters. Every call site interpolates into a double-quoted HTML attribute, and several carry database-derived values — meta keys, post type and taxonomy labels, `menu_icon`, table names — so a value containing a double quote could break out of the attribute and inject markup into the export screen. Quotes are now escaped.

## [1.1.2] - 2026-07-10

### Added

- Full internationalization of the JavaScript admin app via `wp.i18n` and `wp_set_script_translations()`; every user-facing string is now translatable.
- Multisite-aware `uninstall.php`: options, transients, guard files, and the export directory are removed on every site of a network.
- `Third-Party Services` section, trademark note, and privacy/uninstall FAQs in `readme.txt` for WordPress.org guideline compliance.

### Fixed

- Filter values containing literal backslashes were corrupted by an unnecessary `wp_unslash()` on REST JSON input (REST bodies are never slashed).
- Uninstall left the export directory behind because `glob()` never matched the `.htaccess` guard file; guard files are now deleted explicitly.

### Changed

- Minimum supported WordPress version raised to 7.0 (`Requires at least` and `Tested up to`).
- Removed the bundled `.pot` template and `load_plugin_textdomain()` call; translations load automatically from WordPress.org (GlotPress).
- Replaced `@filesize()` error suppression with an existence-checked helper.

## [1.1.1] - 2026-07-08

### Changed

- Move admin screen to **Tools → Open Export** instead of a top-level menu item.

### Added

- Quick-start instructions below the banner (select, filter, export).
- Collapsible source groups; only Content expanded by default.
- **Common sources** / **Advanced** tabs; raw database tables live under Advanced.
- Table search when browsing Advanced sources.

## [1.1.0] - 2026-07-08

### Added

- Saved export templates: name, save, load, and delete via `GET/POST/DELETE /templates`.
- One-click re-run from job history via `POST /jobs/{id}/rerun`.
- Load previous export setup from job history via `GET /jobs/{id}`.
- Drag-to-reorder column chips in the field picker.
- Custom column headers (rename fields on export chips).
- CSV delimiter choice: comma, semicolon, or tab.
- Relative date filter tokens (`last 30 days`, `this month`, `-7 days`, `today`, `yesterday`, and more).

## [1.0.1] - 2026-07-08

### Security

- Block credential columns (`user_pass`, `user_activation_key`) on raw table exports: picker, filters, field validation, and output paths.
- Register explicit REST nonce middleware in the admin app for CSRF protection on mutating API calls.
- Sanitize server-side exception messages returned to the client; log full detail only when `WP_DEBUG` and `WP_DEBUG_LOG` are enabled.
- Add per-job run locks to prevent concurrent batches from corrupting or duplicating export output.

### Added

- `POST /jobs/{id}/pause` REST endpoint so paused exports persist across page refreshes.
- `load_plugin_textdomain()` support with a `/languages` domain path.
- `register_deactivation_hook()` to release stale job locks on deactivate.
- `index.php` silence stubs in `includes/` and `assets/` directories.
- Plugin action links and documentation row meta on the Plugins screen.
- `languages/open-export.pot` translation template for translators.
- WordPress.org `readme.txt` Screenshots and Upgrade Notice sections.

### Fixed

- Empty meta keys no longer trigger PHP notices during field classification.
- Pause/resume state is now tracked server-side (`paused` status) instead of client-only.
- Race where a batch completing after pause could reset job status back to `running`.

### Changed

- Plugin header now includes `Author URI` and `Domain Path` per WordPress.org conventions.

## [1.0.0] - 2026-07-08

### Added

- Initial release: multi-source exports (posts, users, comments, terms, raw tables).
- Smart field discovery with plugin-aware meta grouping.
- Filter builder with AND/OR rules and live preview.
- CSV, JSON, and XML output with host-friendly batched pacing.
- Secure export file storage and authenticated downloads.

[1.1.2]: https://github.com/twigandolive/open-export/compare/v1.1.1...v1.1.2
[1.1.1]: https://github.com/twigandolive/open-export/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/twigandolive/open-export/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/twigandolive/open-export/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/twigandolive/open-export/releases/tag/v1.0.0
