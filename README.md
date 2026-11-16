# Open Export

Export anything from your WordPress database: every feature included, no add-ons, no upsells, ever.

Open Export is a free WordPress plugin. Choose a source, choose fields, filter, and export to CSV, Excel, JSON, XML or an HTML table. Save the setup, run it on a schedule, and have the file sent by email, webhook, FTP, FTPS or SFTP.

- **Version:** 2.0.1
- **Requires:** WordPress 7.0+, PHP 7.4+
- **License:** [GPLv2 or later](LICENSE)
- **Plugin page:** [Open Export in the Twig & Olive shop](https://twigandolive.com/shop/)

## Features

### What can you export?

- Any post type, users, comments and terms from any taxonomy.
- Any database table, under Advanced. A raw table offers only its own columns. It exports every row, including rows whose key is zero, negative or very large. A filter on a DATE column works in whole days.
- WooCommerce orders, order items, customers, products and variations, and coupons.
  - Order items give one row per item. Shipping, fee, coupon and tax lines show their own amounts.
  - Customers include guests, with order count, total spent and average order. You cannot filter on total spent or average order, because the database can only add them up as floating point, which is not exact. Their exported values stay exact.
  - Products have a "GTIN, UPC, EAN, or ISBN" column. WooCommerce 9.2 and later fill it in. Older stores give an empty column.
- Custom fields, grouped by the plugin that owns them (WooCommerce, ACF, Yoast SEO, Rank Math, Elementor and more). Serialized values (PHP's way of packing several values into one field) become JSON. Open Export reads them with PHP classes turned off, so a value that holds an object of a class exports as its stored text.
- ACF repeater and flexible content fields on posts, terms and users, with ACF or Secure Custom Fields. Each field exports as one JSON column. To get numbered columns such as `team 1 name` instead, choose "Spread into columns" (up to 20 rows, fixed when the export starts). You cannot filter on these fields. When a field has more than 1000 rows in one record, the export gets its first 1000 and a note.
- Network exports on multisite. A super admin turns on "All sites in the network" to run one export across every public site, with Site ID and Site URL columns first. A site without that source, or with no content of that type, adds no rows and gets a note. A site that cannot be read stops the export with an error.
  - Network exports are not offered for users or raw tables. The preview reads the current site only.
- On multisite, a site administrator who is not a super admin reaches only that site's members, its own tables, and user meta that belongs to no other site.

### Filters and columns

- Rules with 14 operators, in nested AND and OR groups up to 3 levels deep and 50 rules in total. A role rule with "is empty" finds users with no role, and "is not empty" finds users with any role. Term rules work on description, parent and count too.
- Date ranges from presets or your own dates, as whole days in the site's timezone. A relative preset such as "Last month" stays relative in templates and schedules.
- A preview of the first 5 rows, with the match count.
- Column order, custom headers, up to 8 transform steps per column, and up to 50 custom columns built from `{field id}` placeholders.

### Formats

- CSV:
  - Comma, semicolon or tab as the delimiter. Four encodings, with UTF-8 with BOM as the default (the BOM is a marker that tells Excel the file is UTF-8). LF or CRLF line endings.
  - Open Export converts each cell to the encoding before it quotes the cell.
  - "Quote character" is a double or a single quote. "Quote every cell" quotes every cell. Without it, Open Export quotes only cells that hold the delimiter, a quote, a space, a tab or a line break.
  - Cells that start like a formula get a leading apostrophe.
- Excel (XLSX), from the plugin's own streaming writer. It needs the PHP zip extension.
  - Numbers with up to 15 significant digits become real number cells, so Excel shows 82.50 as 82.5 unless you format the column. Ids with leading zeros, numbers with more digits, and text that looks like a formula stay text.
  - One sheet holds 1,048,576 rows, header included. A bigger export stops with a message that says so and suggests CSV or narrower filters.
- JSON.
- XML, in the default layout or a template with `{field id}` and `{field id|cdata}` placeholders.
- HTML table: one page to open in a browser or print, titled with the export name. Open Export escapes every cell, and the page has no scripts and loads nothing from the web. When you print it, the header row repeats on each page. The whole export is one page, so a very large one is slow to open.
- Zip compression: turn on "Compress the finished file as a zip" under Format. The download, email attachment, webhook link and destination upload then all get one `.zip` that holds the export, named like the download (`Orders May.csv` inside `Orders May.csv.zip`). Zip compression needs the PHP zip extension. It is not offered for Excel, which is already compressed.
- Feed presets for Google Merchant Center, Meta catalogs and Pinterest catalogs, built on WooCommerce products. Each one carries the GTIN and, for a variation, the parent product as the item group (`g:gtin` and `g:item_group_id`, or `gtin` and `item_group_id` for Meta).
- A "WooCommerce product CSV (importer columns)" preset. It writes every product and variation not in the trash, with the column names that WooCommerce's own product importer (**Products > Import**) reads.
  - WooCommerce may not match the GTIN column by itself. On the importer's mapping screen, choose "GTIN, UPC, EAN, or ISBN" for that column once.
  - The preset leaves out attributes, the virtual and downloadable flags, downloads, upsells, cross-sells, grouped products and download limits.
  - The preset writes categories by name, so a subcategory comes back as a top-level category of the same name.

### Schedules and delivery

- Schedules run manually, hourly, every 6 hours, twice a day, daily, weekly or monthly, at a time in the site's timezone. You can have up to 50.
  - Every 6 hours from 03:00 runs at 03:00, 09:00, 15:00 and 21:00. Twice a day at 03:00 runs at 03:00 and 15:00.
  - On a daylight saving day, a run in the skipped hour moves to the end of the gap. The repeated hour runs once, at its first occurrence.
- A file name pattern names a schedule's file. The tokens are `{label}` (the export name), `{source}` (the source id, with a hyphen for the colon, such as `posts-post`), `{date}`, `{time}`, `{datetime}` (site time), `{id}` and `{site}` (the site's host name). Open Export drops unknown tokens and adds the extension for you.
- "Changes only" schedules export only rows whose chosen date is after the start of the last successful run. Open Export saves that start time with the schedule. The code calls it the watermark, as in the `reset-watermark` route.
  - "Start over" makes Open Export forget that time, so the next run exports every row.
  - The chosen field must be one of the source's date fields. Changing the schedule's source or that field also makes Open Export forget the time, and the save message says so.
  - A run can finish while the site is too busy saving to update its schedule (the last run and the saved start time). If that happens, the hourly maintenance run or the schedule's next start, whichever comes first, catches the schedule up. The next run does not export the same rows again.
- Each schedule has a trigger URL for a real server cron job (a timed task that your host runs). The URL starts its schedule at most once every 30 seconds. You can replace its key.
  - After 10 wrong keys for one schedule, or 30 wrong keys from one IP address, the URL answers 429 with a `Retry-After` header until 10 minutes after the first wrong key. It gives that answer even to the right key.
- Background exports run on WP-Cron, so you can close the browser. Resume restarts a paused or failed background export at once.
  - If a WP-Cron task finds the site busy saving, Open Export tries it again a minute later, up to 5 times in a row.
  - If the site is still busy after those 5 tries, a scheduled start gives up. Its schedule card shows that the last run failed, and the schedule runs again at its next regular time.
  - The hourly maintenance run restarts a background export whose last batch is over 10 minutes old, and sends deliveries that are still waiting.
- Delivery goes by email (a link, or an attachment up to 10 MB, to up to 10 addresses), by signed webhook, or to FTP, FTPS and SFTP destinations (up to 20). Each delivery gets up to 3 attempts, 5 and then 30 minutes apart. "Send again now" in History uses the next attempt at once.
- A webhook must use https unless it points at this site or a loopback address (the server itself).
  - Open Export checks that rule when you save the webhook and again when it sends.
  - On the cURL transport, Open Export pins the send to the address it checked.
  - If Open Export cannot store a webhook's secret encrypted, it turns the webhook off with a note. It never sends the webhook unsigned.
- FTP, FTPS and SFTP upload to a temporary name, then rename the file into place.
  - Some servers refuse to rename onto an existing file. On those, Open Export first renames the old file to a backup name, moves the upload into place, and only then deletes the backup. If the upload cannot take the name, Open Export renames the backup back.
  - Open Export never deletes a file already on the server when a rename fails.
  - A remote folder of `/` is used as given.
- Download links in emails and webhooks expire after 1 to 30 days (7 by default). A link stops working sooner if its file is removed.
- A scheduled or background export that fails stops and shows as Failed in History. Open Export does not retry it by itself. Only deliveries get retried. Resume continues the export.
  - A failed scheduled run also shows its error on its schedule card. A "Changes only" schedule keeps its saved start time, so the next run tries the same rows.

### Work with an AI assistant

The site schema (`GET /schema`, or `wp opex schema`) is a JSON description of what this site can export. The screen offers it as "Copy site schema" and "Download schema", and the code calls it the capability document. It lists every source, field id, operator, date column and legal value, with instructions and the recipe format. An assistant writes a recipe (an export setup in JSON). Recipe import checks every id and reports what it matched, swapped or dropped. Open Export never sends anything to an assistant itself.

### Storage, history and site tools

- Exports run in batches and resume after an interruption, so they work on shared hosting. Open Export checks every write, so a full disk stops an export, or its zip compression, with a plain message.
- An hourly cleanup removes finished exports 1 to 365 days (30 by default) after they finish.
- History keeps the latest 200 exports. When History is full, Open Export removes the oldest finished or failed export and its file, sooner than the retention setting. It never removes running or paused exports to make room.
- Export files live in `wp-content/uploads/open-export/` (`uploads/sites/<id>/open-export/` on a multisite sub-site). Each file name carries 12 random characters.
  - Open Export saves every file in the folder with an `.opex` ending, such as `orders-20261116-093000-a1b2c3d4e5f6.csv.opex`. Downloads, attachments, uploads and `wp opex --output` still use the real name and extension. Files saved by 2.0.0 keep their names until the usual cleanup removes them.
  - The folder holds an `index.php`, an `.htaccess` file that blocks direct access on Apache (2.2 and 2.4 rules) and LiteSpeed, and a `web.config` file that blocks it on IIS.
  - Open Export gives a folder made by 1.3.1 the new `.htaccess`, unless someone edited the old one by hand.
  - Many hosts put nginx in front of Apache. There nginx serves known file types such as `.html` and `.zip` itself, but it passes `.opex` files to Apache, which blocks them.
  - nginx on its own reads neither file, so add a deny rule for the folder. The [readme.txt](readme.txt) answer "Where do export files go?" has an example.
  - In Site Health, "Export folder protection" names the deny files in place, explains the `.opex` ending, and says nginx without Apache needs its own rule.
- You can save up to 100 templates. The templates menu shows each one as "Template name (Source name)".
- Uninstalling removes everything the plugin saved, on every site of a network. A site that turned on "Keep my templates, schedules, export history and files" keeps its options and files. Uninstalling clears scheduled events either way.
- Site Health gets an Open Export section under **Site Health > Info**, and two tests. One warns when schedules are on and WP-Cron is off. The other warns when a background export has not moved for over an hour.
- Suggested privacy policy text.
- Two abilities for the WordPress Abilities API, which lets AI tools and other code find a plugin's features: `open-export/get-schema` and `open-export/list-sources`.

## Requirements

- WordPress 7.0 or newer and PHP 7.4 or newer.
- The PHP zip extension for XLSX and zip compression. The PHP ftp extension for FTP and FTPS. Libsodium (part of PHP) to save destination passwords and webhook secrets.
- WooCommerce for the WooCommerce sources and feed presets. Everything else works without it.
- Recommended: a real cron job for schedules and background exports. WP-Cron runs only when someone visits the site.

## Install Open Export

1. Install Open Export from the Plugins screen, or copy this folder to `wp-content/plugins/open-export`.
2. Activate it.
3. Open **Tools > Open Export**.

On nginx without Apache behind it, block the export folder before you export anything. The [readme.txt](readme.txt) answer "Where do export files go?" has an example rule.

## A short tour of the screen

The Open Export screen has five tabs. Names in quotes are the words on the screen.

- **Export:** "Start from" a saved template or a "Feed preset", or open "Use an AI recipe". Then come five steps: Source, Columns (with Transform and "Add a custom column"), Filters (with the date range), Format and Preview. The Run step after them has "Send the result when it is finished", "Run in the background", "Save as template" and "Start export".
- **History:** every export with its source, format, status, progress, size and who started it. The actions are Download, Pause, Resume, "Run again", "Edit and run again" and Delete. The details show the notes and each delivery, with "Send again now" when a delivery is unsent.
- **Schedules:** one card per schedule with "Run now", Edit, Duplicate, Delete and an On or Off switch. The form has the same export setup as the Export tab ("Edit export setup"), Frequency, "Changes only" with "Start over", "File name pattern", delivery, and the trigger URL with Copy and "Make a new URL".
- **Destinations:** saved FTP, FTPS and SFTP servers. The form saves with "Save and test". For SFTP, the form shows the "Server fingerprint" and "Reset saved fingerprint".
- **Settings:** "Keep finished exports", "Download links work for", "Run exports in the background by default", "When the plugin is deleted" (with "Keep my templates, schedules, export history and files"), and a "This server" list (Excel export, FTP and FTPS, encrypted secrets, WP-Cron, WooCommerce, multisite).

## WP-CLI

Every command runs as `wp opex <command>`. List commands take `--format=<table|json|csv|ids|count>` (default `table`).

| Command | What it does |
| --- | --- |
| `sources` | Lists sources with row counts. |
| `templates` | Lists saved templates. |
| `schedules` | Lists schedules with next run (site time) and last run status. |
| `jobs [--status=<status>]` | Lists exports, newest first. Status is `running`, `paused`, `done` or `error`. |
| `run <name-or-id> [--format=<csv\|json\|xml\|xlsx\|html>] [--output=<file>] [--force] [--since=<datetime>] [--network] [--porcelain]` | Runs a template or schedule to the end in this process. It matches the id first, then the exact name. `--format`, `--since` and `--network` work with templates only. `--since` takes `YYYY-MM-DD` or `YYYY-MM-DD HH:MM:SS` in site time. `--network` exports from every public site of a multisite network into one file. Exits 1 on any failure, a failed delivery included. |
| `export --config=<file\|-> [--output=<file>] [--force] [--porcelain]` | Runs a one-off export from a JSON config or recipe file, or from STDIN with `-`. Exits 1 on any failure, a failed delivery included. |
| `schema [--source=<id>] [--all]` | Prints the site schema as JSON. |
| `clean` | Runs the maintenance sweep: catches up schedules that a finished run could not update, removes expired exports, restarts stalled background exports, sets the next run of each schedule and sends pending deliveries. Exits 1 when a step could not save, after the other steps ran. |

`--output` copies the finished file to a path in an existing, writable folder outside the plugin folder. The copy goes to a unique temporary file in that folder first, then takes the final name. Without `--force`, the command never replaces an existing file, even one that appeared while the export ran. `--force` overwrites it.

Some folders cannot hold hard links (a second name for the same file), which the command uses to place the copy safely. In such a folder, the command first claims the file name with an empty file. Only one run can claim a name, so without `--force` it still never replaces another run's file. If the copy then cannot take the name, the command removes its empty file.

`--porcelain` prints only the file path. Warnings and errors, such as a failed delivery, still go to STDERR.

When a delivery fails, `run` and `export` still write the file. They print each delivery error as a warning, then the file path, then exit 1. Open Export tries the failed delivery again later, as for any export.

There is no compression flag. A template, schedule or config file with zip compression (`"compress": "zip"`) makes a `.zip`, and `--output` copies that file. `--format=xlsx` turns zip compression off and prints a warning, because an Excel file is already compressed.

```sh
wp opex run "Weekly orders" --format=xlsx --output=/tmp/orders.xlsx --force
cat recipe.json | wp opex export --config=- --porcelain
```

## REST API

The namespace is `open-export/v1`. Every route except the two public ones needs the capability from `opex_capability` (default `manage_options`). From a logged-in browser, WordPress also checks the `wp_rest` nonce (a short-lived security token).

| Group | Routes | Who can call |
| --- | --- | --- |
| Sources and fields | `GET /sources`, `GET /fields?source=`, `GET /schema?source=&scope=source\|all`, `POST /recipe`, `POST /preview` | Capability |
| Exports | `GET /jobs`, `POST /jobs`, `GET /jobs/<id>`, `DELETE /jobs/<id>`, `POST /jobs/<id>/run`, `POST /jobs/<id>/pause`, `POST /jobs/<id>/rerun`, `POST /jobs/<id>/deliver`, `GET /jobs/<id>/download` | Capability |
| Templates | `GET /templates`, `POST /templates`, `DELETE /templates/<id>` | Capability |
| Schedules | `GET /schedules`, `POST /schedules`, `GET /schedules/<id>`, `POST /schedules/<id>`, `DELETE /schedules/<id>`, `POST /schedules/<id>/run`, `POST /schedules/<id>/rotate-key`, `POST /schedules/<id>/reset-watermark` | Capability |
| Destinations | `GET /destinations`, `POST /destinations`, `POST /destinations/<id>`, `DELETE /destinations/<id>`, `POST /destinations/<id>/test` | Capability |
| Settings and screen data | `GET /settings`, `POST /settings`, `GET /options`, `GET /presets` | Capability |
| Schedule trigger | `GET` or `POST /schedules/<id>/trigger?key=` | Anyone with the schedule's key |
| File download | `GET /file/<id>?token=` | Anyone with a valid, unexpired token |

The 2.0.0 screen added three routes:

- `POST /jobs/<id>/deliver` sends a finished export's pending or failed deliveries now, without the wait. It never resends a delivery that worked. It answers 409 when the export is not done or its deliveries are already sending.
- `POST /schedules/<id>/reset-watermark` does what "Start over" does: the next "Changes only" run exports every row.
- `GET /presets` lists the feed presets, each with a ready config. Without WooCommerce, it returns an empty list.

On a network, the API refuses a config with `network: true`, with the code `opex_bad_network`, unless the caller is a super admin (or WP-CLI). It also refuses one for users or raw tables.

### Errors

An error comes back as a standard WordPress REST error with a `code`, a `message` and `data.status`.

| Status | Code | What it means |
| --- | --- | --- |
| 400 | `opex_invalid` | Open Export could not carry out the request, and `message` says why in words for people. Other refusals keep their own code and status, such as 404 for an unknown id. |
| 409 | `opex_busy` | The same export, schedule start or delivery is already running, for example a second start of a schedule that is still running. Wait for it to finish. |
| 500 | `opex_failed` | An unexpected error. The message is plain ("Open Export could not finish this request. Try again in a moment."). Open Export writes the detail to the debug log only when `WP_DEBUG` and `WP_DEBUG_LOG` are on. |
| 503 | `opex_busy` | The site is busy saving. Another request was changing the same saved list, and this one still could not save after its retries. The message is "The site is busy. Try again in a moment." Try the request again. |

## Hooks

| Hook | Type | Arguments | Use |
| --- | --- | --- | --- |
| `opex_capability` | filter | `string $capability` | The capability every screen, route and ability checks. Default `manage_options`. |
| `opex_job_done` | action | `array $job` | Fires once when an export finishes, after Open Export plans its deliveries. Saved secrets inside the record stay encrypted. |
| `opex_job_failed` | action | `array $job` | Fires when an error stops an export during a batch, after Open Export saves the export record with status `error` and the message a person sees. It does not fire when the export cannot be created, was deleted while running, or is already running in another request. Saved secrets inside the record stay encrypted. |
| `opex_delivery_retry_wait` | filter | `int $seconds`, `int $attempts` | The wait before Open Export tries a failed delivery again. Default 300 after the first attempt, 1800 after the second. |
| `opex_cron_disabled` | filter | `bool $disabled` | Whether Site Health treats WP-Cron as switched off. Default: the `DISABLE_WP_CRON` constant. |
| `opex_zip_available` | filter | `bool $available` | Return false to turn off Excel and zip compression on this site. It cannot turn them on without the PHP zip extension. Default true. |

### Webhook message

Open Export sends a webhook a POST with this JSON body. When you set a secret, it also sends the header `X-Opex-Signature: sha256=<hex>`.

```json
{ "event": "export.completed",
  "job": { "id": "j…", "label": "…", "format": "csv", "rows": 120, "filesize": 5120, "finished": 1763251200 },
  "download_url": "https://example.com/wp-json/open-export/v1/file/j…?token=…",
  "site": "https://example.com" }
```

To check the signature, compute `hash_hmac( 'sha256', $raw_body, $secret )` over the raw body before you parse it. Then compare the result to the part after `sha256=` with `hash_equals`.

## Development

There is no build step. The PHP and the scripts and styles in `assets/app/` ship as written. The scripts are classic scripts on one namespace, `window.opexApp`, and `includes/class-opex-admin.php` loads them in order.

One-time setup:

```sh
composer install                              # PHPUnit for the main test runs (PHP 8.2+)
composer install --working-dir=tests/php74    # PHPUnit 9.6 for the PHP 7.4 test run
composer install --working-dir=tests/lint     # PHP_CodeSniffer
```

Make a throwaway test site (SQLite by default). Usage: `setup-site.sh [--mariadb | --multisite] <target-dir>`.

```sh
tests/bin/setup-site.sh <dir>                  # single site on SQLite
tests/bin/setup-site.sh --mariadb <dir>        # single site on a private MariaDB
tests/bin/setup-site.sh --multisite <dir>      # network: main site plus /second/ and /third/
```

`--multisite` works with SQLite only. It builds a subdirectory network with Open Export network-activated, WooCommerce on the main site only, and a super admin.

Run the tests. Usage: `tests/bin/test.sh [--site <dir>] [--legacy-orders] [--php <bin dir>] [phpunit args...]`. Every change must pass these four test runs:

```sh
OPEX_TEST_SITE=<dir> tests/bin/test.sh                   # SQLite
tests/bin/test.sh --site <mariadb-dir>                   # MariaDB (starts and stops its own server)
tests/bin/test.sh --site <dir> --legacy-orders           # classic WooCommerce order storage
tests/bin/test.sh --site <dir> --php /opt/homebrew/opt/php@7.4/bin   # PHP 7.4 floor
```

Two more test runs cover multisite and ACF. On a single site, the multisite and ACF tests skip.

```sh
OPEX_TEST_SITE=<multisite-dir> tests/bin/test.sh --filter 'NetworkExportTest|NetworkAccessTest|SmokeTest|UninstallTest'
OPEX_TEST_SITE=<acf-dir> tests/bin/test.sh --filter AcfFieldsTest
```

The multisite run needs a site from `setup-site.sh --multisite`. The whole suite also passes there: 13 older tests that assume a single site (user counts, permalinks, the table list) skip on a network and say why. The ACF run needs a test site with the Secure Custom Fields plugin installed and active. No script sets that up.

The script fails when zero tests run. FTP, FTPS and SFTP tests need `OPEX_TEST_TOOLS` set to a folder with a `venv/` holding `pyftpdlib`, `asyncssh` and `pyopenssl`. Without it, they skip.

Lint. Usage: `tests/bin/lint.sh [extra phpcs arguments]`.

```sh
tests/bin/lint.sh
```

### Translations

Every string a person sees, in PHP and JavaScript, uses the `open-export` text domain. WordPress.org serves the translations, so the plugin ships no `.pot` file.

## More

- [ARCHITECTURE.md](ARCHITECTURE.md): compartments, saved state, trust boundaries.
- [SECURITY.md](SECURITY.md): how to report a vulnerability, and what the plugin protects.
- [CHANGELOG.md](CHANGELOG.md): release notes.
- [ROADMAP.md](ROADMAP.md): ideas, and what is left out on purpose.
- [readme.txt](readme.txt): the WordPress.org listing.

For support, use the [Twig & Olive contact page](https://twigandolive.com/contact-twig-and-olive/). For privacy, see the [Twig & Olive privacy policy](https://twigandolive.com/privacy-policy/).

## License

Open Export is [GPLv2 or later](LICENSE). You can use, study, change and share it. Forks are welcome, and so is taking a piece of it for your own project. The bundled phpseclib library in `lib/vendor` is MIT licensed.

Issues and pull requests are welcome in the [Open Export repository on GitHub](https://github.com/twigandolive/open-export).

Made by [Twig & Olive](https://twigandolive.com).
