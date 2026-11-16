=== Open Export ===
Contributors: twigandolive
Tags: export, csv export, xlsx, woocommerce export, scheduled export
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export posts, users, orders, products or any table to CSV, Excel, JSON, XML or HTML. Schedule and send the file. Every feature included, no add-ons.

== Description ==

Open Export gets your data out of WordPress. Choose a source, choose fields, filter, and export. Then save the setup, put it on a schedule, and have the file sent where you need it.

No Pro version. No add-ons to buy. This is all of it.

= Sources =

* Any post type, users, comments and terms from any taxonomy.
* Any database table, under Advanced.
* WooCommerce orders, order items, customers (with order count, total spent and average order), products and variations, and coupons.
* Custom fields, grouped by the plugin that owns them, such as WooCommerce, ACF, Yoast SEO, Rank Math and Elementor. Serialized (packed) values become readable JSON.
* ACF repeater and flexible content fields on posts, terms and users.
* On multisite, one export across every public site of the network.

= Shape the file =

* Filters with rules such as equals, contains, greater than, is one of and is empty, in nested groups such as (A and B) or C.
* Date ranges from a preset, such as last month, or your own dates, in whole days in your site's timezone.
* Preview the first rows and the match count before you export.
* Drag columns into order and rename headers.
* Transforms without code: trim, change case, add text, find and replace, format a date or number, map values, fill in when empty, remove HTML, shorten.
* Custom columns combine fields and text, such as a full name.

= Formats =

* CSV, with your choice of delimiter, encoding, line endings and quote character, and the option to quote every cell. The default encoding, UTF-8 with BOM, opens cleanly in Excel.
* Excel (XLSX), written by the plugin itself. Numbers are real numbers, so Excel shows 82.50 as 82.5 unless you format the column.
* JSON, XML (a default layout or your own template), and an HTML table to open in a browser or print.
* Cells that look like formulas stay plain text in CSV and Excel.
* "Compress the finished file as a zip" for every format except Excel, which is already compressed.
* Presets for Google, Meta and Pinterest product feeds and for WooCommerce's product importer.

= Schedules and delivery =

* Run a saved export hourly, every 6 hours, twice a day, daily, weekly, monthly or only when asked.
* Name the file with tokens: `{label}`, `{source}`, `{date}`, `{time}`, `{datetime}`, `{id}` and `{site}`.
* "Changes only" schedules export only rows that are new or changed since the last run.
* A trigger URL lets a real server cron job start a schedule.
* Background exports keep going after you close the tab.
* When an export finishes, send it by email (a link, or the file attached), to a webhook, or to an FTP, FTPS or SFTP server. "Save and test" checks a server first. History shows each delivery attempt, and "Send again now" retries a failed one.
* Exports run in small batches for shared hosting. An interrupted export resumes where it stopped.

= Work with an AI assistant =

On the Export tab, open "Use an AI recipe" and click "Copy site schema" to copy a JSON description of everything your site can export. Give it to an assistant, paste back the recipe it writes, and click "Apply recipe". Open Export names any unknown fields and leaves them out.

The plugin sends nothing to the assistant, and nothing exports until you click "Start export". If your WordPress has the Abilities API, the abilities `open-export/get-schema` and `open-export/list-sources` give AI tools the same description.

= Limits =

* History keeps the latest 200 exports.
* Up to 50 schedules, 20 destinations and 100 templates.
* An email delivery goes to up to 10 addresses. A failed delivery gets up to 3 tries in all.
* Excel and zip compression need the PHP zip extension.
* You cannot filter on customer total spent or average order.

WooCommerce, ACF (Advanced Custom Fields), Yoast SEO, Rank Math, Elementor, Excel, Google Merchant Center, Meta, Facebook, Instagram, Pinterest and other names are trademarks of their owners. This listing uses them only to describe compatibility. Open Export is an independent project and is not affiliated with or endorsed by any of them.

== Installation ==

1. In your WordPress admin, open Plugins, add a new plugin, and search for Open Export. Or upload the `open-export` folder to `/wp-content/plugins/`.
2. Activate the plugin on the Plugins screen.
3. Go to **Tools > Open Export**.

On nginx, block the export folder first. See "Where do export files go?" below.

== Frequently Asked Questions ==

= Who can use Open Export? =

Administrators: users with the `manage_options` capability. A developer can change that with the `opex_capability` filter, for example for shop managers. Whoever has that capability can export every source, raw tables included.

= Can it export WooCommerce orders? =

Yes. The WooCommerce Orders source reads High-Performance Order Storage or the classic posts table, and gives the same file either way: order number, dates, status, customer, addresses, totals and payment details. It leaves out refunds and draft orders. WooCommerce Order Items gives one row per item, and shipping, fee, coupon and tax lines show their own amounts.

= Did order exports change in 2.0.0? =

Yes. Money uses your store's price decimals (82.50, not 82.50000000), flags read yes or no, dates are in site time, and drafts are left out. If another program reads your order files, check it against a new export.

= How do scheduled exports run? =

Through WP-Cron, which WordPress runs when someone visits your site. On a quiet site, a schedule can start late. For on-time starts, add a real cron job that loads `wp-cron.php` every few minutes, or call the schedule's trigger URL from your server's cron.

Site Health warns you when a schedule is on and WP-Cron is off, and when a background export has not moved for an hour. A scheduled or background export that fails stops and shows as Failed on the History tab. The plugin retries only deliveries, not the export.

= Is the trigger URL safe to share? =

No. Treat it like a password: anyone who has it can start that export. If it leaks, edit the schedule on the Schedules tab and click "Make a new URL". The old URL stops working at once.

= Which delivery method should I use for private data? =

SFTP. FTP sends your password and the file without encryption. FTPS encrypts the connection, but PHP cannot check the server's certificate.

SFTP remembers the server's fingerprint after the first test or delivery that works, and refuses to send if it changes. SFTP private keys with a passphrase do not work. If a new upload cannot replace a file already on the server, the old file stays.

= How large can an export be? =

CSV, JSON, XML and HTML have no fixed row limit. One Excel sheet holds 1,048,576 rows, header included. A bigger one stops and says so: use CSV. An HTML export is one page, so a very large one is slow to open. Email attaches files up to 10 MB and sends a download link for larger ones.

= How long are finished exports kept? =

For the days set in "Keep finished exports" on the Settings tab (30 by default), counted from when each export finished. When History holds 200 exports, the plugin removes the oldest finished or failed one sooner than that, but never a running or paused one. A download link stops working when its file is removed.

= Where do export files go? =

To `wp-content/uploads/open-export/`, or `wp-content/uploads/sites/<id>/open-export/` on a multisite sub-site. The folder's `.htaccess` file blocks direct access on Apache and LiteSpeed, and its `web.config` file blocks it on IIS. nginx ignores both, so add a rule like this above your other regex `location` blocks:

    # Open Export: block the export folder
    location ~ ^/wp-content/uploads/(sites/[0-9]+/)?open-export/ { deny all; }

Either way, each file name carries 12 random characters.

= Can I make a product feed? =

Yes. On the Export tab, choose Google Merchant Center, Meta catalog or Pinterest catalog under "Feed preset", then click "Load". Each feed carries the GTIN, which WooCommerce 9.2 and later store, and, for a variation, its parent product as the item group. The feeds leave out product condition, because no WooCommerce product field holds it.

= Can I export products for WooCommerce's importer? =

Yes. Load the "WooCommerce product CSV (importer columns)" preset. It writes every product and variation not in the trash, with the column names WooCommerce's importer reads. On WooCommerce's mapping screen, choose "GTIN, UPC, EAN, or ISBN" for the GTIN column once. The preset leaves out attributes, downloads, upsells, cross-sells and grouped products.

= Can it export ACF repeater fields? =

Yes, with ACF or Secure Custom Fields, on posts, terms and users. Each repeater or flexible content field exports as one JSON column. For numbered columns (up to 20 rows), choose "Spread into columns" under "Export as" in the Chosen columns list. You cannot filter on these fields. A field over 1000 rows exports its first 1000, with a note.

= Does it work on multisite? =

Yes. Each site has its own exports. A site administrator who is not a super admin can export only that site's users and tables. A super admin can turn on "All sites in the network" under Source to run one export across every public site, with Site ID and Site URL columns first. Network exports are not offered for users or raw tables, and the preview shows the current site only.

= What happens when I delete the plugin? =

Open Export removes its templates, schedules, destinations, settings, export history, cached data, scheduled events and export files, on every site of a network. To keep your setup for a later reinstall, first turn on "Keep my templates, schedules, export history and files" on the Settings tab. Then uninstalling removes only the scheduled events. On a network, each site's own setting decides for that site.

= Can I run exports from the command line? =

Yes, with WP-CLI. For example, `wp opex run "Weekly orders"` runs a saved template or schedule, and `wp opex jobs` lists recent exports.

== Screenshots ==

1. The Export tab: choose a source, choose the columns you want, and put them in order.
2. The History tab: every export with its progress, delivery and download.
3. The Schedules tab: each schedule with its timetable, next run, last run and delivery.
4. A destination form for an SFTP server, with its saved fingerprint.
5. The Settings tab: how long to keep exports and links, and what this server supports.

== Third-party services ==

Open Export contacts no outside service by itself. It has no tracking, no telemetry and no license checks. It sends data only to places you set up yourself, and only when an export you set up finishes or when you test a destination:

* Email: a download link, or the file, to the addresses you enter, through your site's own mail setup.
* Webhook: a JSON message to the URL you enter. It holds the export's id, name, format, row count, file size and finish time, a download link, and your site's address.
* FTP, FTPS or SFTP: the export file, sent to the server you enter with the login you saved. A test uploads a small file and deletes it.

A background export also asks your own site to run WP-Cron.

SFTP uses the bundled phpseclib library (MIT license).

== Changelog ==

= 2.0.0 =
* Added: a five-tab screen, schedules, "Changes only" runs, trigger URLs, background exports, and delivery by email, webhook, FTP, FTPS and SFTP.
* Added: Excel, an HTML table, zip compression, CSV options, XML templates, transforms, custom columns and nested filter groups.
* Added: WooCommerce order items, customers, products and coupons, feed presets with GTIN, and a product importer preset.
* Added: multisite network exports, ACF repeaters, WP-CLI, Site Health, privacy text and a setting to keep data on uninstall.
* Changed: order files are the same on both storage types and leave out drafts. A filter on an unknown field is an error. Saving under an existing template name updates it.
* Fixed: errors, repeated batches and parallel requests no longer break a file or lose a change. CSV cells with a backslash before a quote, and number filters, are now exact.
* Security: on multisite, a site admin who is not a super admin reaches only their own site's users and tables. Open Export never rebuilds stored PHP objects during an export. The export folder is also blocked on IIS.

Full history: [CHANGELOG.md on GitHub](https://github.com/twigandolive/open-export).

== Upgrade Notice ==

= 2.0.0 =
Schedules, delivery, Excel and new WooCommerce sources. Security: on multisite, site admins now reach only their own site's users and tables. Order files change: store price decimals, yes or no flags, site-time dates, no drafts. A filter on an unknown field is now an error.
