=== Open Export ===
Contributors: twigandolive
Tags: export, csv export, xlsx, woocommerce export, scheduled export
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export posts, users, orders, products or any table to CSV, Excel, JSON or XML. Schedule it, send it on. Every feature included, no add-ons.

== Description ==

Open Export gets your data out of WordPress. Pick a source, choose fields, filter, and export. Then save the setup, put it on a schedule, and have the file sent where you need it.

No Pro version. No add-ons to buy. This is all of it.

= Sources =

* Any post type, users, comments, and terms from any taxonomy.
* Any database table, under Advanced.
* WooCommerce orders, order line items (one row per item), customers (with order count, total spent and average order), products and variations, and coupons.
* Custom fields are grouped by the plugin that owns them, such as WooCommerce, ACF, Yoast SEO, Rank Math and Elementor. Serialized values become readable JSON.
* ACF repeater and flexible content fields export as JSON, or as numbered columns.
* On multisite, a network super admin can run one export across every site.

= Shape the file =

* Filters with rules such as equals, contains, greater than, is one of and is empty, in nested groups such as (A and B) or C.
* Date ranges: a preset (today, last 30 days, last month, this quarter so far) or your own From and To dates, in whole days in your site's timezone.
* Preview the first rows and the match count before you export.
* Drag columns into order and rename headers.
* Transforms change a column's values without code: trim, change case, add text before or after, find and replace, format a date or number, map values, fill in when empty, remove HTML, shorten.
* Custom columns combine fields and text, such as a full name from first and last name.

= Formats =

* CSV, with your choice of delimiter, encoding and line endings. The default (UTF-8 with BOM) opens cleanly in Excel. Cells that look like formulas are made safe.
* Excel (XLSX), written by the plugin itself.
* JSON.
* XML, in a default layout or your own template.
* Feed presets for Google Merchant Center, Meta catalogs and Pinterest catalogs.

= Schedules and delivery =

* Run a saved export hourly, daily, weekly, monthly or only when asked.
* Incremental schedules export only rows that are new or changed since the last run.
* A trigger URL lets a real server cron job start a schedule.
* Background exports keep going after you close the tab.
* When an export finishes, send it by email (a link, or the file attached), to a webhook, or to an FTP, FTPS or SFTP server. Failed deliveries are tried again, and you can retry them from History.

= Built for shared hosting =

* Exports run in small batches, so a large export does not overload a small host.
* An interrupted export resumes where it stopped.
* Finished exports are deleted after the number of days you choose (30 by default).

= Work with an AI assistant =

Copy a JSON description of everything your site can export, give it to an assistant, and paste back the short recipe it writes. The screen fills itself in and tells you about anything it could not use. Nothing is sent to the assistant by the plugin, and nothing exports until you press Start.

= Good to know =

* Scheduled and background exports run on WP-Cron, which runs only when someone visits your site. On a quiet site, set up a real cron job.
* A schedule's trigger URL is a secret. Anyone who has it can start that export.
* FTP sends your password and file without encryption. FTPS encrypts, but PHP cannot verify the server's certificate. Use SFTP when you can.
* SFTP private keys with a passphrase are not supported.
* Email attachments over 10 MB are sent as a download link instead.
* Excel (XLSX) files need the PHP zip extension.

WooCommerce, ACF (Advanced Custom Fields), Yoast SEO, Rank Math, Elementor, Excel, Google Merchant Center, Meta, Facebook, Instagram, Pinterest and other names are trademarks of their owners. They are used here only to describe compatibility. Open Export is an independent project and is not affiliated with or endorsed by any of them.

== Installation ==

1. In your WordPress admin, open Plugins, add a new plugin, and search for Open Export. Or upload the `open-export` folder to `/wp-content/plugins/`.
2. Activate the plugin on the Plugins screen.
3. Go to Tools, Open Export.

You can export right away. Files go to a protected folder in your uploads directory, and you download them from the History tab.

For schedules on a site with few visitors, add a real cron job that loads `wp-cron.php` every few minutes, or point your server's cron at a schedule's trigger URL.

== Frequently Asked Questions ==

= Can it export WooCommerce orders? =

Yes. Choose Orders under WooCommerce. It reads whichever storage your store uses, High-Performance Order Storage or the classic posts table, and gives the same file either way: order number, dates, status, customer, billing and shipping addresses, totals and payment details. Refunds and draft orders are left out. For one row per product sold, choose Order line items.

= Did order exports change in 2.0.0? =

Yes. Money now uses your store's price decimals (82.50, not 82.50000000), flags read yes or no, dates are in your site's time, and draft orders are left out. If another program reads your order files, check it against a new export.

= How do scheduled exports run? =

WordPress runs scheduled tasks with WP-Cron when someone visits your site. On a quiet site a schedule can start late. Set up a real cron job, or call the schedule's trigger URL from your server's cron. Site Health tells you when WP-Cron is switched off.

= Is the trigger URL safe to share? =

No. Treat it like a password: anyone who has it can start that export. If it leaks, replace the key on the Schedules tab and the old URL stops working.

= Where can a finished export be sent? =

To email addresses, a webhook URL, or an FTP, FTPS or SFTP server that you save on the Destinations tab. Use the Test button to check a destination before a schedule relies on it. A failed delivery is tried up to 3 times in all, and History shows each attempt.

= Which delivery method should I use for private data? =

SFTP. FTP sends your password and the file without encryption. FTPS encrypts the connection, but PHP cannot check the server's certificate. SFTP remembers the server's identity the first time it connects and refuses to send if it changes.

= How large can an export be? =

There is no fixed row limit. Exports run in batches, can run in the background, and resume after an interruption. Email can attach files up to 10 MB. Larger files go out as a download link that expires after the number of days you set.

= Can I make a product feed? =

Yes. On the Export tab, apply the Google Merchant Center, Meta catalog or Pinterest preset, then change anything you like. Product condition is not included, because no WooCommerce product field holds it. To keep a feed fresh, put it on a schedule and send it to a server your feed service reads from.

= Can it export ACF repeater fields? =

Yes. A repeater or flexible content field exports as JSON, or as numbered columns with one column for each sub-field, up to 20 rows.

= Does it work on multisite? =

Yes. Each site has its own exports. A network super admin can also run one export across every site, with the site id and URL on each row. Network exports are not offered for raw tables or users.

= How do I use this with an AI assistant? =

Open "Work with an AI assistant" on the Export tab and copy the schema. It describes your site's sources, fields, filters and date columns. Describe the report you want to the assistant, paste its recipe back, and press Apply recipe. Every field is checked against what the source really has, and anything unknown is named and left out.

= Where do export files go? =

`wp-content/uploads/open-export/`. The folder has an `.htaccess` rule that denies access, and each file name includes random characters. Finished exports are deleted after 30 days, or the number of days you set on the Settings tab.

= Does my data leave my server? =

Only when you set up a delivery, or when you download a file. Open Export sends no usage data and contacts no outside service by itself.

= What happens when I delete the plugin? =

Uninstalling removes its templates, schedules, destinations, settings, job history, cached data, scheduled events and every export file, on every site of a network.

= Can I run exports from the command line? =

Yes, with WP-CLI. For example, `wp opex run "Weekly orders"` runs a saved template or schedule, and `wp opex jobs` lists recent exports.

== Third-party services ==

Open Export contacts no outside service by itself. It has no tracking, no telemetry and no license checks. It sends data only to places you set up yourself, and only when an export you set up finishes or when you press Test:

* Email: a download link, or the file, to the addresses you enter, through your site's own mail setup.
* Webhook: a JSON message to the URL you enter. It holds the export's id, name, format, row count, file size and finish time, a download link, and your site's address.
* FTP, FTPS or SFTP: the export file, sent to the server you enter with the login you saved. Test uploads a small file and deletes it.

A background export also asks your own site to run WP-Cron. That request goes to your own site, not to anyone else.

SFTP uses the bundled phpseclib library (MIT license).

== Changelog ==

= 2.0.0 =
* Added: a new five-tab screen (Export, History, Schedules, Destinations, Settings).
* Added: schedules, incremental runs, trigger URLs and background exports.
* Added: delivery by email, webhook, FTP, FTPS and SFTP, with retries.
* Added: Excel (XLSX), CSV encoding and line endings, XML templates, transforms, custom columns and nested filter groups.
* Added: WooCommerce order line items, customers, products and coupons.
* Added: feed presets for Google Merchant Center, Meta catalogs and Pinterest catalogs.
* Added: multisite network exports, WP-CLI, Site Health and suggested privacy text.
* Added: ACF repeater and flexible content fields.
* Changed: WooCommerce order files are the same on both storage types: store price decimals, yes or no flags, site-time dates. Draft orders are left out.
* Changed: a filter on a field the source does not have is now an error. Saving a template under an existing name updates it.
* Fixed: database errors, repeated batches and parallel requests can no longer leave a broken or doubled file.

Full history: CHANGELOG.md at https://github.com/twigandolive/open-export

== Upgrade Notice ==

= 2.0.0 =
Schedules, delivery, Excel and new WooCommerce sources. Order files change: store price decimals, yes or no flags, site-time dates, no drafts. A filter on an unknown field is now an error. Check any program that reads your order exports.
