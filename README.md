# Open Export

Export anything from your WordPress database: every feature included, no add-ons, no upsells, ever.

Open Export is a free WordPress plugin. Pick a source, choose fields, filter, and export to CSV, Excel, JSON or XML. Save the setup, run it on a schedule, and have the file sent by email, webhook, FTP, FTPS or SFTP.

> **Status:** version 2.0.0 is in development on this branch and this page describes it. The latest release is 1.3.1.

- **Requires:** WordPress 7.0+, PHP 7.4+
- **License:** [GPLv2 or later](LICENSE)
- **Plugin page:** [twigandolive.com/shop](https://twigandolive.com/shop/)

## Features

### Sources

- Any post type, users, comments, and terms from any taxonomy.
- Any database table (Advanced). Raw tables offer only their own columns.
- WooCommerce: orders, order items (one row per item, with shipping, fee, coupon and tax lines showing their own amounts), customers (order count, total spent, average order, guests included), products and variations, and coupons.
- Custom fields grouped by the plugin that owns them (WooCommerce, ACF, Yoast SEO, Rank Math, Elementor and more). Serialized values become JSON.
- ACF repeater and flexible content fields on posts, terms and users, with ACF or Secure Custom Fields. Each is one JSON column, or "Spread into columns" for numbered columns such as `team 1 name` (up to 20 rows, fixed when the export starts). They cannot be filtered.
- Network exports on multisite: a super admin turns on "All sites in the network" to run one export across every public site, with Site ID and Site URL columns first. Not offered for users or raw tables. The preview reads the current site only.
- On multisite, a site administrator who is not a super admin reaches only that site's members, its own tables, and user meta that belongs to no other site.

### Filters and columns

- Rules with 14 operators, in nested AND and OR groups up to 3 levels deep and 50 rules in total.
- Date ranges from presets or your own dates, as whole days in the site's timezone. Relative presets stay relative in templates and schedules.
- Preview of the first 5 rows and the match count.
- Column order, custom headers, up to 8 transform steps per column, and up to 50 custom columns built from `{field id}` placeholders.

### Formats

- CSV: comma, semicolon or tab, four encodings (UTF-8 with BOM by default), LF or CRLF. Cells that start like a formula get a leading apostrophe.
- Excel (XLSX), from the plugin's own streaming writer. Needs the PHP zip extension. Numbers with up to 15 digits before the decimal point become real number cells, so Excel shows 82.50 as 82.5 unless the column is formatted. Leading-zero ids, longer numbers and text that looks like a formula stay text. More than 1,048,576 rows (one sheet) stops the export with an error.
- JSON.
- XML, in the default layout or a template with `{field id}` and `{field id|cdata}` placeholders.
- Feed presets for Google Merchant Center, Meta catalogs and Pinterest catalogs, built on WooCommerce products.

### Schedules and delivery

- Schedules: manual, hourly, daily, weekly or monthly, at a time in the site's timezone, with a file name pattern (`{label} {source} {date} {time} {datetime} {id} {site}`).
- "Changes only" schedules export only rows whose chosen date is after the start of the last successful run. "Start over" clears that watermark, so the next run exports every row.
- A trigger URL per schedule for a real server cron job. Rate limited, and the key can be replaced.
- Background exports driven by WP-Cron, so the browser can close. Resume restarts a paused or failed background export at once.
- Delivery by email (link or attachment up to 10 MB), signed webhook, or FTP, FTPS and SFTP destinations. Up to 3 attempts, 5 and then 30 minutes apart. "Send again now" in History uses the next attempt at once.
- A webhook must use https unless it points at this site or a loopback address. The rule is checked on save and again on send.
- Download links in emails and webhooks expire after 1 to 30 days (7 by default).

### Work with an AI assistant

The capability document (`GET /schema`, or `wp opex schema`) describes every source, field id, operator, date column and legal value on this site, with instructions and the recipe format. An assistant writes a recipe, and recipe import checks every id and reports what it matched, swapped or dropped. The plugin sends nothing to an assistant itself.

### Built in

- Batched, resumable exports for shared hosting.
- Retention: finished exports are removed 1 to 365 days (30 by default) after they finish, by an hourly cleanup.
- Site Health section and a cron test, suggested privacy policy text, and two Abilities API abilities (`open-export/get-schema`, `open-export/list-sources`).

## Requirements

- WordPress 7.0 or newer and PHP 7.4 or newer.
- PHP zip extension for XLSX. PHP ftp extension for FTP and FTPS. Libsodium (part of PHP) to save destination passwords and webhook secrets.
- WooCommerce for the WooCommerce sources and feed presets. Everything else works without it.
- A real cron job is recommended for schedules and background exports. WP-Cron runs only when someone visits the site.

## Install

1. Install from the Plugins screen, or copy this folder to `wp-content/plugins/open-export`.
2. Activate it.
3. Open **Tools, Open Export**.

## A short tour of the screen

The screen has five tabs. Names in quotes are the words on the screen.

- **Export:** "Start from" a saved template or a "Feed preset", or open "Use an AI recipe". Then five steps: Source, Columns (with Transform and "Add a custom column"), Filters and a date range, Format, and Preview. The Run step has "Send the result when it is finished", "Run in the background", "Save as template" and "Start export".
- **History:** every export with its source, format, status, progress, size and who started it. Actions: Download, Pause, Resume, "Run again", "Open in builder" and Delete. The details show the notes and each delivery, with "Send again now" when one is unsent.
- **Schedules:** one card per schedule with "Run now", Edit, Duplicate, Delete and an On or Off switch. The form has the same builder ("Edit export setup"), Frequency, "Changes only" with "Start over", "File name pattern", delivery, and the trigger URL with Copy and "Make a new URL".
- **Destinations:** saved FTP, FTPS and SFTP servers. The form saves with "Save and test". For SFTP it shows the "Server fingerprint" and "Reset saved fingerprint".
- **Settings:** "Keep finished exports", "Download links work for", "Run exports in the background by default", and a "This server" list (Excel export, FTP and FTPS, encrypted secrets, WP-Cron, WooCommerce, multisite).

## WP-CLI

Every command runs as `wp opex <command>`. List commands take `--format=<table|json|csv|ids|count>` (default `table`).

| Command | What it does |
| --- | --- |
| `sources` | Lists sources with row counts. |
| `templates` | Lists saved templates. |
| `schedules` | Lists schedules with next run (site time) and last run status. |
| `jobs [--status=<status>]` | Lists exports, newest first. Status is `running`, `paused`, `done` or `error`. |
| `run <name-or-id> [--format=<csv\|json\|xml\|xlsx>] [--output=<file>] [--force] [--since=<datetime>] [--network] [--porcelain]` | Runs a template or schedule to the end in this process. Matches the id first, then the exact name. `--format`, `--since` and `--network` work with templates only. `--since` takes `YYYY-MM-DD` or `YYYY-MM-DD HH:MM:SS` in site time. `--network` exports from every public site of a multisite network into one file. Exits 1 on any failure. |
| `export --config=<file\|-> [--output=<file>] [--force] [--porcelain]` | Runs a one-off export from a JSON config or recipe file, or from STDIN with `-`. |
| `schema [--source=<id>] [--all]` | Prints the capability document as JSON. |
| `clean` | Runs the maintenance sweep: removes expired exports, restarts stalled background jobs, re-arms schedules and sends pending deliveries. |

`--output` copies the finished file to a path in an existing, writable folder outside the plugin folder. `--force` overwrites it. `--porcelain` prints only the file path.

```sh
wp opex run "Weekly orders" --format=xlsx --output=/tmp/orders.xlsx --force
cat recipe.json | wp opex export --config=- --porcelain
```

## REST API

Namespace `open-export/v1`. Every route except the two public ones needs the capability from `opex_capability` (default `manage_options`). From a logged-in browser, WordPress also checks the `wp_rest` nonce.

| Group | Routes | Who can call |
| --- | --- | --- |
| Sources and fields | `GET /sources`, `GET /fields?source=`, `GET /schema?source=&scope=source\|all`, `POST /recipe`, `POST /preview` | Capability |
| Jobs | `GET /jobs`, `POST /jobs`, `GET /jobs/<id>`, `DELETE /jobs/<id>`, `POST /jobs/<id>/run`, `POST /jobs/<id>/pause`, `POST /jobs/<id>/rerun`, `POST /jobs/<id>/deliver`, `GET /jobs/<id>/download` | Capability |
| Templates | `GET /templates`, `POST /templates`, `DELETE /templates/<id>` | Capability |
| Schedules | `GET /schedules`, `POST /schedules`, `GET /schedules/<id>`, `POST /schedules/<id>`, `DELETE /schedules/<id>`, `POST /schedules/<id>/run`, `POST /schedules/<id>/rotate-key`, `POST /schedules/<id>/reset-watermark` | Capability |
| Destinations | `GET /destinations`, `POST /destinations`, `POST /destinations/<id>`, `DELETE /destinations/<id>`, `POST /destinations/<id>/test` | Capability |
| Settings and screen data | `GET /settings`, `POST /settings`, `GET /options`, `GET /presets` | Capability |
| Schedule trigger | `GET` or `POST /schedules/<id>/trigger?key=` | Anyone with the schedule's key |
| File download | `GET /file/<id>?token=` | Anyone with a valid, unexpired token |

Routes added for the 2.0.0 screen:

- `POST /jobs/<id>/deliver` sends a finished job's pending or failed deliveries now, skipping the wait. It never resends one that worked. It answers 409 when the job is not done or its deliveries are being sent.
- `POST /schedules/<id>/reset-watermark` clears the watermark, so the next "Changes only" run exports every row.
- `GET /presets` lists the feed presets, each with a ready config. It returns an empty list without WooCommerce.

On a network, a config with `network: true` is refused with `opex_bad_network` unless the caller is a super admin (or WP-CLI), and for users or raw tables.

## Hooks

| Hook | Type | Arguments | Use |
| --- | --- | --- | --- |
| `opex_capability` | filter | `string $capability` | The capability every screen, route and ability checks. Default `manage_options`. |
| `opex_job_done` | action | `array $job` | Fires once when an export finishes, after its deliveries are planned. Saved secrets inside the record stay encrypted. |
| `opex_delivery_retry_wait` | filter | `int $seconds`, `int $attempts` | Wait before a failed delivery is tried again. Default 300 after the first attempt, 1800 after the second. |
| `opex_cron_disabled` | filter | `bool $disabled` | Whether Site Health treats WP-Cron as switched off. Default: the `DISABLE_WP_CRON` constant. |

### Webhook message

A webhook gets a POST with this JSON body, and `X-Opex-Signature: sha256=<hex>` when a secret is set:

```json
{ "event": "export.completed",
  "job": { "id": "j…", "label": "…", "format": "csv", "rows": 120, "filesize": 5120, "finished": 1763251200 },
  "download_url": "https://example.com/wp-json/open-export/v1/file/j…?token=…",
  "site": "https://example.com" }
```

To check it, compute `hash_hmac( 'sha256', $raw_body, $secret )` over the raw body before you parse it, and compare it to the part after `sha256=` with `hash_equals`.

## Development

There is no build step. The PHP and the scripts and styles in `assets/app/` ship as written. The scripts are classic scripts on one namespace, `window.opexApp`, and `includes/class-opex-admin.php` loads them in order.

One-time setup:

```sh
composer install                              # PHPUnit for the main lanes (PHP 8.2+)
composer install --working-dir=tests/php74    # PHPUnit 9.6 for the PHP 7.4 lane
composer install --working-dir=tests/lint     # PHP_CodeSniffer
```

Make a throwaway test site (SQLite by default). Usage: `setup-site.sh [--mariadb | --multisite] <target-dir>`.

```sh
tests/bin/setup-site.sh <dir>                  # single site on SQLite
tests/bin/setup-site.sh --mariadb <dir>        # single site on a private MariaDB
tests/bin/setup-site.sh --multisite <dir>      # network: main site plus /second/ and /third/
```

`--multisite` works with SQLite only. It builds a subdirectory network with Open Export network-activated, WooCommerce on the main site only, and a super admin.

Run the tests. Usage: `tests/bin/test.sh [--site <dir>] [--legacy-orders] [--php <bin dir>] [phpunit args...]`. The four lanes every change must pass:

```sh
OPEX_TEST_SITE=<dir> tests/bin/test.sh                   # SQLite
tests/bin/test.sh --site <mariadb-dir>                   # MariaDB (starts and stops its own server)
tests/bin/test.sh --site <dir> --legacy-orders           # classic WooCommerce order storage
tests/bin/test.sh --site <dir> --php /opt/homebrew/opt/php@7.4/bin   # PHP 7.4 floor
```

Two more lanes cover multisite and ACF. On a single site their tests skip.

```sh
OPEX_TEST_SITE=<multisite-dir> tests/bin/test.sh --filter 'NetworkExportTest|NetworkAccessTest|SmokeTest'
OPEX_TEST_SITE=<acf-dir> tests/bin/test.sh --filter AcfFieldsTest
```

The multisite lane needs a site from `setup-site.sh --multisite`. Run only those tests there: 13 older tests assume a single site (user counts, permalinks, the table list) and fail on a network. The ACF lane needs a test site with the Secure Custom Fields plugin installed and active. No script sets that up.

The script fails when zero tests run. FTP, FTPS and SFTP tests need `OPEX_TEST_TOOLS` set to a folder with a `venv/` holding `pyftpdlib`, `asyncssh` and `pyopenssl`, and skip without it.

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
- [ROADMAP.md](ROADMAP.md): ideas and what is left out on purpose.
- [readme.txt](readme.txt): the WordPress.org listing.

Support: [twigandolive.com/contact-twig-and-olive](https://twigandolive.com/contact-twig-and-olive/). Privacy: [twigandolive.com/privacy-policy](https://twigandolive.com/privacy-policy/).

## License

Open Export is [GPLv2 or later](LICENSE). You may use, study, change and share it. Forks are welcome, and so is taking a piece of it for your own project. The bundled phpseclib library in `lib/vendor` is MIT licensed.

Issues and pull requests are welcome at [github.com/twigandolive/open-export](https://github.com/twigandolive/open-export).

Made by [Twig & Olive](https://twigandolive.com).
