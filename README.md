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
- WooCommerce: orders, order line items (one row per item), customers (order count, total spent, average order, guests included), products and variations, and coupons.
- Custom fields grouped by the plugin that owns them (WooCommerce, ACF, Yoast SEO, Rank Math, Elementor and more). Serialized values become JSON.
- ACF repeater and flexible content fields, as JSON or as numbered columns (up to 20 rows).
- Network exports on multisite: a super admin can run one export across every site, with `site_id` and `site_url` columns. Not offered for raw tables or users.

### Filters and columns

- Rules with 14 operators, in nested AND and OR groups up to 3 levels deep and 50 rules in total.
- Date ranges from presets or your own dates, as whole days in the site's timezone. Relative presets stay relative in templates and schedules.
- Preview of the first 5 rows and the match count.
- Column order, custom headers, up to 8 transform steps per column, and up to 50 custom columns built from `{field id}` placeholders.

### Formats

- CSV: comma, semicolon or tab, four encodings (UTF-8 with BOM by default), LF or CRLF. Cells that start like a formula get a leading apostrophe.
- Excel (XLSX), from the plugin's own streaming writer. Needs the PHP zip extension.
- JSON.
- XML, in the default layout or a template with `{field id}` and `{field id|cdata}` placeholders.
- Feed presets for Google Merchant Center, Meta catalogs and Pinterest catalogs, built on WooCommerce products.

### Schedules and delivery

- Schedules: manual, hourly, daily, weekly or monthly, at a time in the site's timezone, with a file name pattern (`{label} {source} {date} {time} {datetime} {id} {site}`).
- Incremental schedules export only rows newer than the start of the last successful run. You can reset that watermark.
- A trigger URL per schedule for a real server cron job. Rate limited, and the key can be replaced.
- Background exports driven by WP-Cron, so the browser can close.
- Delivery by email (link or attachment up to 10 MB), signed webhook, or FTP, FTPS and SFTP destinations. Up to 3 attempts, 5 and then 30 minutes apart, plus a manual retry.
- Download links in emails and webhooks expire after 1 to 30 days (7 by default).

### Work with an AI assistant

The capability document (`GET /schema`, or `wp opex schema`) describes every source, field id, operator, date column and legal value on this site, with instructions and the recipe format. An assistant writes a recipe, and recipe import checks every id and reports what it matched, swapped or dropped. The plugin sends nothing to an assistant itself.

### Built in

- Batched, resumable exports for shared hosting.
- Retention: finished exports are removed after 1 to 365 days (30 by default) by an hourly cleanup.
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

- **Export:** pick a source, choose and order fields, add filters and a date range, pick a format and its options, add transforms and custom columns, preview, save as a template, and start. A delivery can be attached to a one-off export. The assistant panel and feed presets live here.
- **History:** every export with its progress, notes, deliveries and download. Pause, resume, rerun, retry delivery, load into the builder, or delete.
- **Schedules:** the same builder, plus frequency, incremental runs, file name pattern, delivery, run now, trigger URL, new key and reset watermark.
- **Destinations:** saved FTP, FTPS and SFTP servers with a Test button. Shows the SFTP host key fingerprint and lets you reset it.
- **Settings:** retention, link lifetime, background by default, and a status list (zip, ftp, sodium, cron, WooCommerce, multisite).

## WP-CLI

Every command runs as `wp opex <command>`. List commands take `--format=<table|json|csv|ids|count>` (default `table`).

| Command | What it does |
| --- | --- |
| `sources` | Lists sources with row counts. |
| `templates` | Lists saved templates. |
| `schedules` | Lists schedules with next run (site time) and last run status. |
| `jobs [--status=<status>]` | Lists exports, newest first. Status is `running`, `paused`, `done` or `error`. |
| `run <name-or-id> [--format=<csv\|json\|xml\|xlsx>] [--output=<file>] [--force] [--since=<datetime>] [--porcelain]` | Runs a template or schedule to the end in this process. Matches the id first, then the exact name. `--format` and `--since` work with templates only. `--since` takes `YYYY-MM-DD` or `YYYY-MM-DD HH:MM:SS` in site time. Exits 1 on any failure. |
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

There is no build step. The PHP and the scripts in `assets/app/` ship as written.

One-time setup:

```sh
composer install                              # PHPUnit for the main lanes (PHP 8.2+)
composer install --working-dir=tests/php74    # PHPUnit 9.6 for the PHP 7.4 lane
composer install --working-dir=tests/lint     # PHP_CodeSniffer
```

Make a throwaway test site (SQLite by default):

```sh
tests/bin/setup-site.sh [--mariadb | --multisite] <target-dir>
```

Run the tests. Usage: `tests/bin/test.sh [--site <dir>] [--legacy-orders] [--php <bin dir>] [phpunit args...]`. The four lanes:

```sh
OPEX_TEST_SITE=<dir> tests/bin/test.sh                   # SQLite
tests/bin/test.sh --site <mariadb-dir>                   # MariaDB (starts and stops its own server)
tests/bin/test.sh --site <dir> --legacy-orders           # classic WooCommerce order storage
tests/bin/test.sh --site <dir> --php /opt/homebrew/opt/php@7.4/bin   # PHP 7.4 floor
```

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
