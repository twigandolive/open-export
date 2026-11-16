# Changelog

All notable changes to Open Export are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - Unreleased

Open Export now runs exports on a schedule, sends the file where you need it, and covers more of a WooCommerce store. It also closes a multisite data gap: see "Security". Read "Changed" before you upgrade. WooCommerce order files look different, and a few loose inputs that 1.3.1 ignored, such as a filter on a field the source does not have, are now errors.

### Added

- **A new admin screen with five tabs:** Export, History, Schedules, Destinations and Settings. You build an export in one place and set up repeat exports in another, with the same export setup in both.
- **Excel (XLSX) files.** The plugin writes them itself, with no extra library, and needs the PHP zip extension for them. Ids with leading zeros and numbers with more than 15 significant digits stay text, so Excel does not change them. Other numbers are real numbers, so Excel shows 82.50 as 82.5 unless you format the column. Text that looks like a formula stays text. An export with more rows than one Excel sheet holds (1,048,576, header included) stops with a message that says so and suggests CSV or narrower filters.
- **CSV options:** choose the encoding (UTF-8 with BOM, UTF-8, Windows-1252 or UTF-16 LE) and the line endings (LF or CRLF), so the file opens cleanly in the program that reads it. Open Export converts each cell to the encoding before it quotes the cell, so a character that becomes a comma or a quote in Windows-1252 cannot split a cell. "Quote character" picks a double or a single quote. "Quote every cell" quotes every cell instead of only the cells that hold the delimiter, a quote, a space, a tab or a line break.
- **HTML table format:** one page to open in a browser or print, titled with the export name. Open Export escapes every cell, and the page has no scripts and loads nothing from the web. When you print it, the header row repeats on each page. The whole export is one page, so a very large one is slow to open.
- **Zip compression:** "Compress the finished file as a zip" under Format. The download, email attachment, webhook link and destination upload all get one .zip file that holds the export, named like the download (for example "Orders May.csv" inside "Orders May.csv.zip"). Zip compression needs the PHP zip extension. It is not offered for Excel, which is already compressed.
- **Custom XML templates.** Write a header, a row and a footer with `{field id}` placeholders. Open Export escapes the values for you, and `{field id|cdata}` wraps a value in CDATA. Before it runs, the plugin checks that the result is well-formed XML.
- **Column transforms.** Up to 8 steps per column: trim, change case, add text before or after, find and replace, format a date, format a number, map values, fill in when empty, remove HTML and shorten. Steps are plain settings, never code. "Format a number" rounds with exact decimal math, never floating point.
- **Custom columns** that combine fields and text, such as `{woo:billing_first_name} {woo:billing_last_name}`.
- **Nested filter groups**, such as (A and B) or C, up to 3 levels deep and 50 rules in total.
- **New WooCommerce sources:** products and variations, coupons, order line items and customers.
  - Order line items give one row per item, with the order's number, date, status and billing email beside it. Shipping, fee, coupon and tax lines show their own amounts.
  - Customers come with order count, total spent and average order, guests included.
  - Money uses exact decimal math, never floating point. You cannot filter on total spent or average order, because the database can only add them up as floating point, which is not exact. Their exported values stay exact.
- **Feed presets** for Google Merchant Center, Meta catalogs and Pinterest catalogs. On the Export tab, choose one under "Feed preset" and click "Load". The preset fills in a complete product export that you can change before you run it. Each feed carries the GTIN and, for a variation, its parent product as the item group (`g:gtin` and `g:item_group_id` for Google and Pinterest, `gtin` and `item_group_id` for Meta).
- **A "WooCommerce product CSV (importer columns)" preset:** every product and variation not in the trash, with the column names that WooCommerce's own product importer (Products > Import) reads.
  - WooCommerce may not match the GTIN column by itself. On its mapping screen, choose "GTIN, UPC, EAN, or ISBN" for that column once.
  - The preset leaves out attributes, the virtual and downloadable flags, downloads, upsells, cross-sells, grouped products and download limits.
  - The preset writes categories by name, so a subcategory comes back as a top-level category of the same name.
- **A "GTIN, UPC, EAN, or ISBN" column** for WooCommerce products. WooCommerce 9.2 and later store it. Older stores give an empty column.
- **Schedules.** Run an export hourly, every 6 hours, twice a day, daily, weekly, monthly or only when asked, at a time in your site's timezone.
  - Every 6 hours from 03:00 runs at 03:00, 09:00, 15:00 and 21:00, and twice a day at 03:00 runs at 03:00 and 15:00. A time that happens twice when clocks go back runs at its first occurrence.
  - Name the file with the tokens `{label}`, `{source}`, `{date}`, `{time}`, `{datetime}`, `{id}` and `{site}`.
  - Each schedule has "Run now", Duplicate and an On or Off switch. A run that fails shows on its schedule card with its error.
- **Changes only** on a schedule exports only rows that are new or changed since the last successful run. The schedule saves when that run started. The code calls that time the watermark.
  - "Start over" makes the next run export every row again.
  - A failed run leaves that saved time alone, so the next run tries the same rows.
  - A run can finish while the site is too busy saving to update its schedule. The hourly maintenance run, or the next start of the schedule, whichever comes first, then catches the schedule up, so the next run does not export the same rows again.
  - The "Changes only" field must be one of the source's date fields. Changing the schedule's source or that field makes Open Export forget that time, so the next run exports every row, and the save says so.
- **A trigger URL for each schedule**, so a real server cron job can start it. The URL holds a secret key. "Make a new URL" replaces the key at any time. After 10 wrong keys for one schedule, or 30 wrong keys from one IP address, the URL answers 429 with a Retry-After header until 10 minutes after the first wrong key.
- **Background exports** that keep going after you close the browser tab. They run on WP-Cron. Resume restarts a paused or failed background export at once.
  - If a WP-Cron task finds the site busy saving, Open Export tries it again a minute later, up to 5 times in a row. After that, a scheduled start shows as failed on its schedule card and runs again at its next regular time. The hourly maintenance run restarts stalled exports and sends waiting deliveries.
- **Delivery when an export finishes:** email (a download link, or the file attached up to 10 MB), a signed webhook, or upload to a saved FTP, FTPS or SFTP destination. A failed delivery is tried up to 3 times in all, 5 and then 30 minutes apart.
  - A webhook must use https unless it points at your own site or a loopback address (the server itself). Open Export checks this when you save the webhook and again when it sends.
  - On servers that send with cURL, the send goes to the exact address that Open Export checked, so a DNS answer that changes in between cannot redirect it. Other transports cannot pin the address.
  - If Open Export cannot store a webhook's secret encrypted, it turns the webhook off with a note. It never sends the webhook unsigned.
- **Send again now** in an export's details on the History tab tries its unsent deliveries at once instead of waiting. It uses one of the 3 tries, and it never sends a delivery that already worked a second time.
- **Destinations** with "Save and test".
  - SFTP remembers the server's fingerprint and refuses to send if it changes. After a planned server rebuild, "Reset saved fingerprint" clears it.
  - FTP and FTPS ignore the passive address a server sends, and open the file connection to the server they logged in to.
  - Every upload goes to a temporary name, and Open Export then renames it into place. Open Export never deletes a file already on the server when a rename fails. A remote folder of `/` is used as given.
- **Download links that expire**, for email and webhook deliveries. You set how many days they last.
- **Settings:** how long to keep finished exports (1 to 365 days, default 30, counted from when each export finished), how long download links last (1 to 30 days, default 7) and whether exports run in the background by default.
- **"When the plugin is deleted":** to keep your setup for a later reinstall, turn on "Keep my templates, schedules, export history and files". Uninstall then leaves the options and files in place, and still clears the scheduled events. On a network, each site's own setting decides for that site.
- **The saved templates menu** shows each template's source, as "Template name (Source name)".
- **Network exports on multisite.** A super admin can turn on "All sites in the network" to run one export across every public site, with Site ID and Site URL columns first. Network exports are not offered for users or raw tables. The preview shows rows from the current site only.
- **ACF repeater and flexible content fields** on posts, terms and users, with ACF or Secure Custom Fields. Each one exports as one JSON column. To get numbered columns such as "team 1 name" instead, up to 20 rows, choose "Spread into columns". You cannot filter on these fields. When a field has more than 1000 rows in one record, the export gets its first 1000 and a note.
- **WP-CLI:** `wp opex` lists sources, templates, schedules and exports, runs a template or schedule, runs a one-off export from a JSON file, prints the site schema and runs the cleanup.
  - `wp opex run <template> --network` runs a template across every site.
  - `run` and `export` exit with code 1 when a delivery fails.
  - `run` and `export` write `--output` through a unique temporary file. Without `--force`, they never replace a file, even one that appeared during the run.
  - In a folder that cannot hold hard links, `run` and `export` first claim the file name with an empty file. Only one run can claim a name, so without `--force` they still never replace another run's file.
- **Site Health:** an Open Export section under Info. Its "Export folder protection" line names the deny files in place and says nginx needs its own rule. Two status tests tell you when scheduled exports need a real cron job and when a background export has not moved for over an hour.
- **Suggested privacy policy text** under Settings > Privacy.
- **Two abilities** for the WordPress Abilities API, `open-export/get-schema` and `open-export/list-sources`, when your WordPress has that API.
- **Plain-language notes on each export** that say what was dropped and why, for example a field the source does not have.
- Hooks for developers: the `opex_job_done` and `opex_job_failed` actions, and the `opex_delivery_retry_wait`, `opex_cron_disabled` and `opex_zip_available` filters.
- The site schema (the capability document in the code) and recipe import now cover the new sources, formats, CSV options, transforms, custom columns, nested filter groups and XML templates. `opex_schema` stays at version 1, so a 1.3.1 recipe still works.

### Changed

- **WooCommerce order exports give the same file on both order storage types.** Money uses your store's price decimals (82.50, not 82.50000000), flags read yes or no, and dates are in your site's time. If a spreadsheet or import reads your order files, check it against a new export.
- **Draft orders no longer export.** Orders, order line items and the row counts leave out auto-drafts and checkout drafts.
- **A filter on a field the source does not have is now an error** that names the field, on preview, export, template save and schedule save. Before, Open Export ignored the rule and you got more rows than you asked for. Recipes still report and drop unknown fields.
- **Saving a template under a name that already exists updates that template** instead of adding a second one with the same name.
- A paused export stays paused until you click Resume.
- Relative date words such as "today" in a filter apply only to date fields. In a text field they are plain text.
- An empty "is one of" list matches nothing. Before, the rule disappeared and every row matched.
- The spreadsheet formula guard leaves plain numbers such as -5 alone, and now guards the header row too.
- Taxonomy filters with starts with, ends with, greater than and less than now compare the term name. Before, Open Export ignored them.
- A user role filter with "is one of" takes a list of roles.
- "This week", "this month", "this quarter" and "this year" now carry the label "so far", because they end today.
- A batch size that is not a number falls back to 500.
- **REST errors have fixed codes.**
  - When the site is busy saving, because two requests change the same saved list at once and one still cannot save after its retries, the route answers 503 with the code `opex_busy`. Try again.
  - When the same export, schedule start or delivery is already running, for example a second start of a schedule that is still running, the route answers 409 with the code `opex_busy`.
  - Another error written for people answers 400 with its message.
  - Any other unexpected error answers a plain 500 with the code `opex_failed`. The detail goes to the debug log only when `WP_DEBUG` and `WP_DEBUG_LOG` are on.
- A raw table with no whole-number primary key is ordered by its primary key columns. A table with no key at all gets a note that row order is not guaranteed.
- The field picker lists post type and MIME type for every post type, and the comment GMT date and karma for comments.
- An hourly cleanup removes old exports. It follows the retention setting and counts from when each export finished. Before, Open Export removed an export 30 days after it started, when you opened the export list.
- **History keeps the latest 200 exports, up from 50.** When the list is full, Open Export removes the oldest finished or failed export and its file, sooner than the retention setting. It never removes running or paused exports to make room. A download link stops working when its file is removed.
- Deactivating the plugin clears its scheduled events, on every site when you deactivate it for the whole network. Uninstalling also removes schedules, destinations, settings, lock rows and every scheduled event, on every site of a network, unless that site keeps its data (see "When the plugin is deleted" above).

### Fixed

- A database error in the middle of an export now stops the export with an error you can resume from. Before, the export could finish with rows missing.
- A batch that runs twice, for example after a timeout, no longer writes its rows twice.
- Two requests can no longer run the same export batch at the same time.
- Deleting an export while it runs now removes it for good, file included.
- Open Export never exports the `session_tokens` and `_application_passwords` user meta on any path, including the raw user meta table, and you cannot filter on them.
- A blank text column now counts as empty for equals, does not equal and is empty.
- The `to_see_all_fields` link in the site schema works on sites without pretty permalinks.
- Open Export reports a recipe with values of the wrong shape instead of failing with an error.
- Any PHP error during an export now marks the export as failed instead of stopping the request.
- CSV export no longer raises a deprecation notice on newer PHP.
- The `/schema` route refuses an unknown `scope`.
- The Open Export link on the Plugins screen opens Tools > Open Export.
- Two requests that change the export list at the same time no longer undo each other. Before, a running batch could save an older copy of the list, and a pause, a delete or another export's progress could be lost.
- CSV cells that hold a backslash before a quote are written as standard CSV, and other programs read them back unchanged.
- Greater than and less than filters on numbers compare the exact number you typed. Before, the comparison went through floating point, so very long numbers could compare wrong.
- With `WP_DEBUG` on, a failed export no longer shows PHP's own error text, which can hold server paths. You see a plain message, and the detail goes to the debug log when `WP_DEBUG_LOG` is on.
- Term filters on description, parent and count work. Before, they caused a database error.
- A user role filter with "is empty" or "is not empty" finds users with no role or with any role. Before, both matched no one.
- A raw table exports every row, including rows whose key is zero, negative or very large. Before, Open Export left out rows with a key of zero or less.
- Date ranges and date rules on a raw table's DATE column use whole calendar days. Before, a range on such a column could lose its first day.
- A full disk stops an export with a plain message. Before, a write that failed went unnoticed and the file could end short. Zip compression checks its file the same way.

### Removed

- The old one-page export screen and its script and style (`assets/admin.js`, `assets/admin.css`). The five-tab screen replaces them.
- The empty `languages/` folder. WordPress.org delivers translations as language packs.

### Security

- **On a multisite network, a site administrator who is not a super admin now reaches only their own site's data.** The Users source holds only the site's members, and the raw table list shows only the site's own tables. Open Export neither offers nor exports other sites' user meta. In 1.3.1 and earlier, such an administrator could export every user of the network and any table, including the tables the network shares. Single sites, super admins and WP-CLI are unchanged. A schedule a super admin saved keeps its reach when cron or its trigger URL runs it.
- **The export folder also blocks direct access on IIS,** with a `web.config` file next to the `.htaccess` file. The `.htaccess` file now holds rules for Apache 2.2 as well as 2.4. Open Export replaces the one-line file that 1.3.1 wrote, unless someone edited it. nginx reads neither file, so add your own rule there: readme.txt has an example.
- **Stored PHP objects are never rebuilt during an export.** Open Export reads serialized values in custom fields, columns and raw tables with PHP classes turned off, so no code of a class on your site runs. A value that holds an object of a class exports as its stored text. Arrays and plain objects still become JSON as before. In 1.3.1 and earlier, Open Export turned such a value back into an object.

## [1.3.1] - 2026-09-02

Corrective release for the assistant round trip: hand the capability document
to an AI, get a recipe back, paste it in, get the right CSV. Every change is
additive; `opex_schema` stays at version 1.

### Fixed

- **The capability document described no fields.** `sources` was populated only when a source was already selected, so the default export carried an empty array — and an assistant told to use only the ids listed there had nothing to work from. Every Common source is now inlined with its full field catalogue and date columns. Advanced raw tables are inlined only on request, and any source whose custom-field list was shortened says so and gives the URL for its complete list.
- **WooCommerce field ids changed with the storage engine.** `woo:` ids resolved only under High-Performance Order Storage; on a store keeping orders in `wp_posts` the catalogue emitted `core:`/`meta:` ids instead, so a recipe written against `woo:billing_state` matched nothing. The `woo:` ids are now canonical on both engines and the query layer translates them to the underlying post column or meta key. Pre-1.3.1 configs using the raw `core:`/`meta:` ids keep working.
- **A recipe that resolved to nothing was applied as an empty selection.** Import now returns a resolution report naming every field, filter and date-range id as matched, substituted or dropped, each with a reason and a nearest-match suggestion. A recipe where nothing resolves is refused and the report shown, so the empty-selection state is unreachable through import.
- **Orders appeared twice in Common sources** — once as WooCommerce Orders and once as the raw `shop_order` post type, with identical counts and one of them empty under HPOS. The raw post type moves to Advanced, labelled as such.
- Legacy `_date_paid` and `_date_completed` are now usable as date-range columns. They hold UNIX timestamps, so they compare against epochs computed in PHP from the site's timezone rather than through `UNIX_TIMESTAMP()`, whose result depends on the database session timezone.

### Added

- Order fields `shipping_email` and `is_guest`, completing the coverage a shop report needs: order number, dates, status and currency; full billing and shipping address sets; payment method and title; order, tax, shipping, shipping-tax and discount totals; customer id and guest flag.
- Every field in the capability document carries a `type` (`text`, `number`, `date`, `id`, `enum`, `email`, `url`, `boolean`), and every source carries its `group`, `tier` and row count, so the document mirrors the Common/Advanced split the picker draws and an assistant steers ordinary requests to Common sources.

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
