=== Open Export ===
Contributors: twigandolive
Tags: export, csv export, woocommerce export, json export, database export
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export anything from your WordPress database: every feature included, no add-ons, no upsells, ever.

== Description ==

Open Export gets your data out of WordPress with a clean four-step flow: pick a source, filter it, choose your fields, export.

* **Every source**: any post type (including WooCommerce products and orders), users, comments, terms from any taxonomy, and any raw database table.
* **Smart field discovery**: meta keys are automatically grouped by the plugin that owns them: WooCommerce, ACF, Yoast SEO, Rank Math, Elementor, and more. Serialized values are unpacked into readable JSON.
* **Real filters**: stack rules (equals, contains, greater than, is one of, is empty, …) with AND/OR matching, on core fields, custom fields, and taxonomies. Preview the first rows and the match count before you commit.
* **Three formats**: CSV (Excel-safe: UTF-8 BOM, formula-injection guarded), JSON, and XML.
* **Host-friendly pacing**: exports run in small batches with a configurable pause between them, so a 500,000-row export won't melt shared hosting. Interrupted exports resume where they left off.
* **Templates and re-run**: save export setups, load them later, or re-run a previous export in one click.
* **Column control**: drag chips to reorder columns; rename headers for spreadsheet-friendly output.
* **CSV delimiters**: comma, semicolon, or tab for Excel-friendly regional formats.
* **Works with AI assistants**: export a JSON description of everything this site can export, hand it to an assistant such as Claude or ChatGPT, and paste back the short recipe it replies with. The screen configures itself and tells you about anything in the recipe it could not honour. Nothing exports until you press the button.
* **WooCommerce orders**: a dedicated Orders source that reads whichever storage your store uses, classic post storage or High-Performance Order Storage, and presents the same friendly fields either way: order number, status, customer, billing and shipping address, totals, and payment details.
* **Date ranges**: pick a preset (today, last 30 days, last month, this quarter…) or set a From and To date. Whole days in your site's timezone, end date included.
* **Relative date filters**: use tokens like `last 30 days` or `this month` in filter rules.
* **Secure by default**: administrator capability plus REST nonces on every endpoint, prepared statements throughout, export files stored in a protected directory with unguessable names, and password hashes are never exportable.

No Pro version. No add-ons to buy. This is all of it.

WooCommerce, ACF (Advanced Custom Fields), Yoast SEO, Rank Math, Elementor, and other plugin names are trademarks of their respective owners and are used here only to describe compatibility. Open Export is an independent project and is not affiliated with or endorsed by any of them.

== Installation ==

1. Upload the `open-export` folder to `/wp-content/plugins/`, or install it from the Plugins screen in your WordPress admin.
2. Activate the plugin through the **Plugins** screen.
3. Go to **Tools → Open Export**, or use the **Open Export** link on the Plugins screen.

No configuration is required. Exports are written to a protected folder inside your uploads directory and streamed to you through an authenticated download.

== Third-Party Services ==

Open Export does not connect to any third-party services. It makes no external network requests of any kind: no API calls, no tracking, no telemetry, no update pings beyond WordPress.org's own plugin system. All export processing happens locally on your server, and export files never leave it unless you download them yourself.

== Roadmap ==

* Scheduled exports, webhooks, WP-CLI support, and WooCommerce line-item exports (version 1.5)
* Custom XML templates, value transforms, FTP delivery, and multisite export (version 2.0)

== Frequently Asked Questions ==

= Can it export WooCommerce orders? =

Yes. Orders are a post type, and their meta is grouped under a WooCommerce heading. Sites using HPOS (custom order tables) can export those tables directly via Raw Database Tables.

= Where do export files go? =

`wp-content/uploads/open-export/`, protected with an .htaccess deny rule and random filenames. Downloads are streamed through an authenticated endpoint and files are cleaned up after 30 days.

= Does my data leave my server? =

No. Open Export makes no external connections and sends nothing anywhere. Exports are generated on your server and stay there until you download or delete them.

= What happens when I delete the plugin? =

Uninstalling removes everything: saved templates, job history, cached data, and every generated export file, on every site of a multisite network.

== Screenshots ==

1. Pick a data source: post types, users, comments, taxonomies, or any raw database table.
2. Filter, preview, and choose fields grouped by plugin (WooCommerce, ACF, Yoast, and more).
3. Export with pacing controls; download CSV, JSON, or XML when finished.

== Upgrade Notice ==

= 1.3.1 =
Fixes the AI round trip: the capability document now describes every Common source, WooCommerce ids work on both storage engines, and a recipe that does not resolve is reported rather than silently applied.

= 1.3.0 =
Hand a JSON description of your site to an AI assistant and paste back the export recipe it writes. Also fixes date filters that excluded the last day of a range.

= 1.2.0 =
WooCommerce order exports (HPOS and classic) and a proper date-range filter. Fixes relative date tokens that resolved in UTC, and an attribute-escaping issue in the admin screen.

= 1.1.2 =
Compliance and translation release: the whole admin app is now translatable, uninstall wipes every trace (multisite included), and filter values with backslashes export correctly. Now requires WordPress 7.0.

= 1.1.1 =
UI cleanup: Open Export now lives under Tools. Source picker uses collapsible groups and an Advanced tab for database tables.

= 1.1.0 =
Workflow release: saved templates, one-click re-run, column reorder and rename, CSV delimiters, and relative date filters.

= 1.0.1 =
Security and stability update: blocks credential columns on raw table exports, adds REST nonce protection, per-job run locks, and server-side export pause. Recommended for all users.

= 1.0.0 =
Initial release.

== Changelog ==

= 1.3.1 =
* Fixed: the capability document listed no fields unless a source was already selected, leaving an AI nothing to compose a recipe from. Every Common source is now described in full.
* Fixed: WooCommerce field ids only worked on stores using High-Performance Order Storage. The same woo: ids now work on both storage engines, so one recipe fits any store.
* Fixed: a recipe whose ids did not resolve was applied as an empty selection. Import now shows a report naming every id as matched, substituted or dropped, with a reason and a suggested correction, and refuses a recipe where nothing resolves.
* Fixed: Orders appeared twice in Common sources; the raw shop_order post type moved to Advanced.
* Fixed: date paid and date completed are now usable date ranges on stores that keep orders as posts.
* Added: shipping email and guest flag order fields; a type on every field and a tier on every source in the capability document.


= 1.3.0 =
* Added: an AI assistant panel that exports a JSON description of this site's sources, fields, operators, date columns, and legal field values, complete with the instructions and return format an assistant needs.
* Added: paste the JSON recipe an assistant replies with and the export screen configures itself; nothing runs until you press Start export.
* Added: recipes are validated field by field, and anything unrecognised is reported in plain language instead of silently exporting a blank column.
* Added: REST endpoints /schema and /recipe, behind the same administrator capability and nonce as every other route.
* Fixed: a date rule using "is on or before" excluded the whole of the end date; date columns now compare by calendar day.
* Fixed: a compact date such as 20260301 was treated as a number and matched nothing.
* Fixed: custom fields, taxonomies, and computed fields chosen on a raw database table exported as silently empty columns.
* Fixed: post_modified_gmt is now offered in the field picker.


= 1.2.0 =
* Added: WooCommerce Orders source that works with both High-Performance Order Storage and classic post storage, exposing order, customer, billing, shipping, totals, and payment fields under the same names on either engine.
* Added: date-range filter on every source, with presets (today, last 7/30/90 days, this or last week/month/quarter/year) and a custom From/To range.
* Added: date ranges are whole days in your site's timezone and the end date is fully included; the range is always combined with AND, so choosing "match ANY rule" cannot widen it.
* Fixed: relative date tokens (`today`, `this month`, `last 30 days`) resolved against UTC instead of the site timezone, so on a UTC-8 site `today` returned no rows at all from late afternoon onward.
* Fixed: `this month`, `last month`, `this year`, and `last year` started at the current time of day instead of midnight.
* Security: the admin app escaped `&`, `<`, and `>` but not quotes when building HTML attributes, so a meta key, post type label, or table name containing a double quote could inject markup into the export screen.

= 1.1.2 =
* Added: full internationalization of the admin app; every UI string now runs through WordPress translation functions.
* Added: multisite-aware uninstall that removes options, transients, guard files, and export directories on every site.
* Added: Third-Party Services statement and privacy FAQ (the plugin makes no external connections).
* Fixed: filter values containing literal backslashes are no longer altered before querying.
* Changed: minimum supported WordPress version raised to 7.0.
* Changed: translations now load automatically from WordPress.org; the bundled .pot template was removed.

= 1.1.1 =
* Changed: admin screen moved to Tools → Open Export.
* Added: quick-start steps, collapsible source groups, Advanced tab for database tables, table search.

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
