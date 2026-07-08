=== Open Export ===
Contributors: twigandolive
Tags: export, csv export, xml export, json export, database export
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export anything from your WordPress database — every feature included, no add-ons, no upsells, ever.

== Description ==

Open Export gets your data out of WordPress with a clean four-step flow: pick a source, filter it, choose your fields, export.

* **Every source** — any post type (including WooCommerce products and orders), users, comments, terms from any taxonomy, and any raw database table.
* **Smart field discovery** — meta keys are automatically grouped by the plugin that owns them: WooCommerce, ACF, Yoast SEO, Rank Math, Elementor, and more. Serialized values are unpacked into readable JSON.
* **Real filters** — stack rules (equals, contains, greater than, is one of, is empty, …) with AND/OR matching, on core fields, custom fields, and taxonomies. Preview the first rows and the match count before you commit.
* **Three formats** — CSV (Excel-safe: UTF-8 BOM, formula-injection guarded), JSON, and XML.
* **Host-friendly pacing** — exports run in small batches with a configurable pause between them, so a 500,000-row export won't melt shared hosting. Interrupted exports resume where they left off.
* **Templates and re-run** — save export setups, load them later, or re-run a previous export in one click.
* **Column control** — drag chips to reorder columns; rename headers for spreadsheet-friendly output.
* **CSV delimiters** — comma, semicolon, or tab for Excel-friendly regional formats.
* **Relative date filters** — use tokens like `last 30 days` or `this month` in filter rules.
* **Secure by default** — administrator capability plus REST nonces on every endpoint, prepared statements throughout, export files stored in a protected directory with unguessable names, and password hashes are never exportable.

No Pro version. No add-ons to buy. This is all of it.

== Roadmap ==

* Scheduled exports, webhooks, WP-CLI support, and WooCommerce line-item exports (version 1.5)
* Custom XML templates, value transforms, FTP delivery, and multisite export (version 2.0)

== Frequently Asked Questions ==

= Can it export WooCommerce orders? =

Yes — orders are a post type, and their meta is grouped under a WooCommerce heading. Sites using HPOS (custom order tables) can export those tables directly via Raw Database Tables.

= Where do export files go? =

`wp-content/uploads/open-export/`, protected with an .htaccess deny rule and random filenames. Downloads are streamed through an authenticated endpoint and files are cleaned up after 30 days.

== Screenshots ==

1. Pick a data source — post types, users, comments, taxonomies, or any raw database table.
2. Filter, preview, and choose fields grouped by plugin (WooCommerce, ACF, Yoast, and more).
3. Export with pacing controls; download CSV, JSON, or XML when finished.

== Upgrade Notice ==

= 1.1.0 =
Workflow release: saved templates, one-click re-run, column reorder and rename, CSV delimiters, and relative date filters.

= 1.0.1 =
Security and stability update: blocks credential columns on raw table exports, adds REST nonce protection, per-job run locks, and server-side export pause. Recommended for all users.

= 1.0.0 =
Initial release.

== Changelog ==

= 1.1.0 =
* Added: saved export templates (save, load, delete).
* Added: one-click re-run and load setup from job history.
* Added: drag-to-reorder columns and custom column headers.
* Added: CSV delimiter choice (comma, semicolon, tab).
* Added: relative date filter tokens (last 30 days, this month, etc.).

= 1.0.1 =
* Security: block password-hash columns on raw table exports.
* Security: explicit REST nonce middleware for CSRF protection on API mutations.
* Security: per-job run locks prevent duplicate/corrupt output from parallel requests.
* Security: generic export error messages to clients; details logged only when debugging.
* Added: server-side pause (`POST /jobs/{id}/pause`) so paused exports survive page refresh.
* Fixed: empty meta keys no longer trigger PHP notices.
* Added: translation loading, deactivation hook, and directory silence stubs.
* Added: plugin action links, documentation row meta, Screenshots section, and Upgrade Notice for WordPress.org.
* Added: `languages/open-export.pot` translation template.

= 1.0.0 =
* Initial release.
