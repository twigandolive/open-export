# Architecture

Index of Open Export 2.0.0. Detail lives in the code and in [README.md](README.md). Line numbers
drift as code changes: search by symbol.

## Stack

- PHP 7.4 or newer, WordPress 7.0 or newer (`open-export.php:7-8`). WooCommerce is optional.
- `open-export.php` loads every class with one `require_once` line each, in load order. No autoloader.
- One bundled library set in `lib/vendor`: phpseclib 3 with its two paragonie helpers (all MIT),
  for SFTP only, loaded on first use by `class-opex-delivery-sftp.php`. Rebuild with
  `composer install --no-dev --optimize-autoloader --working-dir=lib`.
- Root `composer.json`, `tests/php74/` and `tests/lint/` are test tooling only. They never ship.
- No build step: `assets/app/*.js` are plain scripts on one namespace, `window.opexApp`.
- Secrets use libsodium, which PHP bundles. XLSX needs `ZipArchive`. FTP and FTPS need the `ftp` extension.

## Build and test

- One-time setup: `composer install` (PHPUnit, needs PHP 8.2+), `composer install --working-dir=tests/php74`,
  `composer install --working-dir=tests/lint`.
- Test site: `tests/bin/setup-site.sh [--mariadb | --multisite] <dir>`. Seeded, throwaway, never live.
- Four lanes, all required before a commit. `test.sh` fails when zero tests run.
  1. SQLite: `OPEX_TEST_SITE=<dir> tests/bin/test.sh`
  2. MariaDB: `tests/bin/test.sh --site <mariadb dir>` (starts and stops its own private server)
  3. Classic order storage: `tests/bin/test.sh --site <dir> --legacy-orders` (turns HPOS off, runs the
     Orders, Woo and Hpos tests, turns it back on)
  4. PHP 7.4: `tests/bin/test.sh --site <dir> --php <folder holding php 7.4>`
- Lint: `tests/bin/lint.sh` (WordPress standard plus PHP 7.4 compatibility, `phpcs.xml.dist`).
- `OPEX_TEST_TOOLS`: folder with a `venv/` holding `pyftpdlib`, `asyncssh` and `pyopenssl`. FTP and
  SFTP tests skip without it.
- Release zip: `git archive` of a commit. `.gitattributes` keeps dev files out. No script yet.

## Compartments

Other code uses only the boundary named last on each line. Files are `includes/class-opex-<name>.php`.

- Sources: `sources` (registry), `source-provider`, `source-posts`, `source-table`, `source-mapped`,
  `source-woo-*`, `woo`, `woo-format`, `money`, `decimal`. Boundary: `OPEX_Sources`.
- Catalog, schema and recipes: `catalog`, `core-columns`, `field-types`, `dates`, `schema`,
  `schema-document`, `recipe`, `recipe-rules`, `presets`. Boundary: `OPEX_Catalog`, `OPEX_Schema`, `OPEX_Recipe`.
- Query: `query`, `query-filters`, `filter-tree`, `query-dates`, `query-rows`. Boundary: `OPEX_Query`.
- Engine and jobs: `config`, `exporter`, `jobs`, `lock`, `filename`, `download`. Boundary: `OPEX_Exporter`, `OPEX_Jobs`.
- Writers: `writers` (CSV, JSON, XML), `writer-xlsx`, `writer-xml-template`. Boundary: `OPEX_Writer`.
- Transforms: `transforms`, `custom-columns`. Boundary: `OPEX_Transforms`.
- Templates and settings: `templates`, `settings`. Boundary: `OPEX_Templates`, `OPEX_Settings`.
- Schedules and runner: `schedules`, `schedule-clock`, `runner`. Boundary: `OPEX_Schedules`, `OPEX_Runner`.
- Delivery: `delivery`, `delivery-email`, `-webhook`, `-ftp`, `-sftp`, `destinations`. Boundary: `OPEX_Delivery`, `OPEX_Destinations`.
- Secrets and links: `secrets`, `download-tokens`. Boundary: `OPEX_Secrets`, `OPEX_Download_Tokens`.
- REST: `rest`, `rest-screen`, `rest-schedules`, `rest-delivery`, `rest-public`. Boundary: each `register_routes()`.
- CLI: `cli`, `cli-runner` (`wp opex`). Boundary: `OPEX_CLI::register()`.
- Admin screen: `admin` plus `assets/app/`. Boundary: `OPEX_Admin::register_menu()`, `enqueue()`.
- Integrations: `privacy`, `site-health`, `abilities`. Boundary: each `register()`.

Provider seam: each `woo:*` source is a provider class. Posts-backed providers (`OPEX_Source_Posts`)
resolve fields to post columns or meta. SQL-mapped providers (`OPEX_Source_Table` plus the
`OPEX_Source_Mapped` trait) give a FROM clause and a map of field id to SQL. `OPEX_Sources` lists them,
and Query, Catalog and Schema ask the provider. The core sources (posts, users, comments, terms,
tables) still use `OPEX_Query`'s own branches. A new source is a provider plus one registry line.

Hooks: `opex_job_done` fires once per finished job, after deliveries are planned in the same save.
`OPEX_Schedules::on_job_done` and `OPEX_Runner::on_job_done` (priority 20) listen.

## Saved state

"Shipped" means in 1.3.1. Never rename a shipped name without a migration. Options autoload off.
New config and job keys are add-only and saved only when not default. Reads fill defaults.

| Name | Kind | Shipped |
| --- | --- | --- |
| `opex_jobs` | option, job registry, at most 50 (download tokens stored as hashes) | yes |
| `opex_templates` | option, saved templates, at most 100 | yes |
| `opex_schedules` | option, at most 50 (trigger keys in plain text) | no |
| `opex_destinations` | option, at most 20 (encrypted secrets, pinned host keys, last test) | no |
| `opex_settings` | option: `retention_days`, `link_days`, `background` | no |
| `opex_lock_<name>` | option row `<expiry>:<token>`, taken by INSERT IGNORE. Names: job id, `sched_<id>`, `deliver_<job id>` | name yes (was a transient) |
| `opex_table_status`, `opex_acf_keys`, `opex_keys_*` | transients, 5 minute caches | yes |
| `opex_trigger_<id>`, `opex_trig_fail_<id>` | transients: trigger start gap, failed-key count | no |
| `opex_run_schedule`, `opex_run_job`, `opex_deliver` | single cron events, one per id | no |
| `opex_maintenance` | hourly cron event | no |
| `uploads/open-export/` | export files, sidecars `*.rows`, `*.rows.n`, `*.zip.tmp`, `index.php`, `.htaccess` | yes |
| system temp `opex-<hex>/` | email attachment copy, removed after sending | no |

Other shipped names: REST namespace `open-export/v1`, filter `opex_capability`, menu slug
`open-export`, field id prefixes `core: meta: tax: computed: col: woo:`, source ids. No user meta.
Deactivation clears every `opex_*` cron event. `uninstall.php` removes all of the above on every site.

## Trust boundaries

"Capability" means `current_user_can( opex_capability() )`, default `manage_options`
(`open-export.php:94-96`). WordPress core checks the `wp_rest` nonce on cookie-authenticated REST calls.

| Entry point | Who | Guard | At risk |
| --- | --- | --- | --- |
| REST core: sources, fields, schema, recipe, preview, jobs, templates | Admins | capability `rest.php:15-17` | Any table, post, user data |
| REST screen: `jobs/<id>/deliver`, `schedules/<id>/reset-watermark`, `presets` | Admins | capability `rest-screen.php:27,65-66` | Delivery, watermark |
| REST schedules: list, save, delete, run, rotate key | Admins | capability `rest-schedules.php:28,97-98` | Schedules, trigger keys |
| REST destinations, settings, options | Admins | capability `rest-delivery.php:27,124-125` | Credentials (encrypted) |
| `schedules/<id>/trigger?key=` (GET, POST) | Anyone with the key | `hash_equals` `rest-public.php:101-124`, 10 failures lock `:111-118`, 30 s gap `:169-177` | Starts an export |
| `file/<id>?token=` | Anyone with a token | HMAC hash, expiry `download-tokens.php:70-76`, one 404 `rest-public.php:231-239` | The export file |
| Admin screen | Admins | capability `admin.php:114` | Screen, REST nonce |
| Abilities `get-schema`, `list-sources` | Admins | capability `abilities.php:126-127` | Site schema |
| Network export (`network: true`) | Super admins | `config.php:32-35` | Every site's data |
| WP-CLI `wp opex` | Shell users | none beyond shell access. `--output` not in plugin folder `cli-runner.php:112-114` | Exports, output paths |
| Cron hooks | WP-Cron | ids come from saved state `runner.php:21-24` | Runs exports, delivery |
| Recipe import (`POST recipe`) | Admins | capability, then `OPEX_Recipe` and `sanitize_config` | Config to run |
| Webhook out | Remote server | https rule `delivery.php:68`, safe request, no redirects `delivery-webhook.php:73-79` | Signed payload, link |
| SFTP out | Remote server | host key check before login `delivery-sftp.php:171-178` | Credentials, file |
| FTP and FTPS out | Remote server | none possible. Warning `destinations.php:162-166` | Credentials, file |
| XML template text | Admins | no DOCTYPE `writer-xml-template.php:100`, `LIBXML_NONET` `:166` | XML parser |
| Database values in files | Anyone who wrote the data | formula guard `writers.php:162-170`, blocked columns `query.php:18,24` | Spreadsheet users |

## Overrides

- `declare(strict_types=1)` only in new files. The 1.3.1 files and the three files split from
  `class-opex-query.php` (`-query-filters`, `-query-dates`, `-query-rows`) hold moved code, and strict
  types would change how they coerce values.
- XLSX uses the in-house writer `class-opex-writer-xlsx.php`, not a library, to keep the zip small.
- The bundled phpseclib (`lib/vendor`, about 3.2 MB) makes the zip far larger than the usual 200 KB
  target. SFTP needs it, and no PHP extension replaces it.
- Assets are classic scripts with no build step, so the shipped code is the source a reviewer reads.
- Trigger keys stay in plain text: the screen must show the trigger URL again (lead's ruling).
- Over 500 lines, waiting for a structure-only split: `class-opex-schema.php`, `class-opex-catalog.php`,
  `class-opex-query.php`.
