# Architecture

Index of Open Export 2.0.0. Detail lives in the code and [README.md](README.md). Code is cited by
method: search by symbol.

## Stack

- PHP 7.4+, WordPress 7.0+ (`open-export.php` header). WooCommerce is optional.
- `open-export.php` loads each class with one `require_once` line, in load order. No autoloader.
- `lib/vendor`: phpseclib 3 and two paragonie packages (MIT), for SFTP, loaded on first use.
  Rebuild: `composer install --no-dev --optimize-autoloader --working-dir=lib`.
- Root `composer.json`, `tests/php74/` and `tests/lint/` are test tooling, never shipped.
- The screen is `assets/app/`: 40 classic scripts and 2 stylesheets on `window.opexApp`.
- Secrets use libsodium. XLSX and zip compression need `ZipArchive`. FTP and FTPS need `ftp`.

## Build and test

- Setup: `composer install` (PHP 8.2+), and again with `--working-dir=tests/php74` and `tests/lint`.
- Test site: `tests/bin/setup-site.sh [--mariadb | --multisite] <dir>`. Throwaway, never live.
- Four lanes, all required before a commit. `test.sh` fails on zero tests.
  1. SQLite: `OPEX_TEST_SITE=<dir> tests/bin/test.sh`
  2. MariaDB: `tests/bin/test.sh --site <mariadb dir>` (runs its own server)
  3. Classic orders: `tests/bin/test.sh --site <dir> --legacy-orders`
  4. PHP 7.4: `tests/bin/test.sh --site <dir> --php <php 7.4 bin folder>`
- Multisite: `--filter 'NetworkExportTest|NetworkAccessTest|SmokeTest'` on a `--multisite` site.
  ACF: `--filter AcfFieldsTest` on a site with Secure Custom Fields (set up by hand). Both skip on a single site.
- Lint: `tests/bin/lint.sh` (`phpcs.xml.dist`).
- `OPEX_TEST_TOOLS`: a folder whose `venv/` has `pyftpdlib`, `asyncssh`, `pyopenssl`, or FTP tests skip.
- Release zip: `git archive` of a commit. `.gitattributes` keeps dev files and `.wordpress-org/` out.

## Compartments

Files are `includes/class-opex-<name>.php`. Other code uses only the boundary named last.

- Sources: `sources` (registry), `source-provider`, `source-posts`, `source-table`, `source-mapped`,
  `source-woo-*`, `source-labels`, `woo`, `woo-format`, `money`, `decimal`. Boundary: `OPEX_Sources`.
- ACF: `acf`, `acf-location`, `acf-spread` (20 rows max). Boundary: `OPEX_Acf`, `OPEX_Acf_Spread`.
- Catalog, schema, recipes: `catalog`, `catalog-meta`, `core-columns`, `field-types`, `dates`, `schema`,
  `schema-sources`, `schema-resolver`, `schema-document`, `recipe`, `recipe-rules`, `presets`,
  `preset-product-csv`. Boundary: `OPEX_Catalog`, `OPEX_Schema`, `OPEX_Recipe`.
- Query: `query`, `query-fetch`, `query-filters`, `filter-tree`, `query-dates`, `query-rows`. Boundary: `OPEX_Query`.
- Engine and jobs: `config`, `config-output` (CSV quoting, zip keys), `exporter`, `export-run`, `jobs`,
  `compress` (zip after finish), `lock`, `filename`, `download`. Boundary: `OPEX_Exporter`, `OPEX_Jobs`.
- Multisite: `network`, `access` (what a caller may reach). Boundary: `OPEX_Network`, `OPEX_Access`.
- Writers: `writers` (CSV, JSON, XML), `writer-xlsx`, `writer-xml-template`, `writer-html`. Boundary: `OPEX_Writer`.
- Transforms: `transforms`, `custom-columns`. Boundary: `OPEX_Transforms`.
- Templates and settings: `templates`, `settings`. Boundary: `OPEX_Templates`, `OPEX_Settings`.
- Schedules and runner: `schedules`, `schedule-clock`, `runner`. Boundary: `OPEX_Schedules`, `OPEX_Runner`.
- Delivery: `delivery`, `delivery-email`, `-webhook`, `-ftp`, `-sftp`, `destinations`. Boundary: `OPEX_Delivery`, `OPEX_Destinations`.
- Secrets and links: `secrets`, `download-tokens`. Boundary: `OPEX_Secrets`, `OPEX_Download_Tokens`.
- REST: `rest`, `rest-screen`, `rest-schedules`, `rest-delivery`, `rest-public`. Boundary: each `register_routes()`.
- CLI: `cli`, `cli-runner` (`wp opex`). Boundary: `OPEX_CLI::register()`.
- Admin screen: `admin`, `assets/app/` (handles in `OPEX_Admin::SCRIPTS`). Boundary: `OPEX_Admin::register_menu()`, `enqueue()`.
- Integrations: `privacy`, `site-health`, `abilities`. Boundary: each `register()`.

Provider seam: each `woo:*` source is a provider (`OPEX_Source_Posts`, or `OPEX_Source_Table` with the
`OPEX_Source_Mapped` trait). A new source is a provider and one registry line.

Network runs: batches go through `OPEX_Network` inside `switch_to_blog`, always restored. The job, lock
and file stay on the starting site.

Hooks: `opex_job_done` fires once per finished job, after delivery planning. Listeners:
`OPEX_Schedules::on_job_done`, `OPEX_Runner::on_job_done` (priority 20).

## Saved state

"Shipped" means in 1.3.1. Never rename a shipped name without a migration. Options autoload off.
New config and job keys are add-only and saved only when not default. Reads fill defaults.

| Name | Kind | Shipped |
| --- | --- | --- |
| `opex_jobs` | option, job registry, latest 200 (`OPEX_Jobs::trim_history`: never a running or paused job), token hashes | yes |
| `opex_templates` | option, saved templates, at most 100 | yes |
| `opex_schedules` | option, at most 50 (trigger keys in plain text) | no |
| `opex_destinations` | option, at most 20 (encrypted secrets, pinned host keys, last test) | no |
| `opex_settings` | option: `retention_days`, `link_days`, `background`, `keep_data_on_uninstall` | no |
| `opex_lock_<name>` | option row `<expiry>:<token>` by INSERT IGNORE. Names: job id, `sched_<id>`, `deliver_<job id>` | name yes (was a transient) |
| `opex_table_status`, `opex_acf_keys`, `opex_keys_*` | transients, 5 minute caches | yes |
| `opex_trigger_<id>`, `opex_trig_fail_<id>` | transients: trigger start gap, failed-key count | no |
| `opex_run_schedule`, `opex_run_job`, `opex_deliver` | single cron events, one per id | no |
| `opex_maintenance` | hourly cron event | no |
| `uploads/open-export/` | export files, sidecars `*.rows`, `*.rows.n`, `*.zip.tmp`, `*.zip.part`, `index.php`, `.htaccess`, `web.config` | yes |
| system temp `opex-<hex>/` | email attachment copy, removed after sending | no |

Add-only keys new in 2.0.0: config `network`, `acf_flatten` (list of `acf:` ids), `csv_enclosure`,
`csv_quote_all`, `compress` (`none` or `zip`, never with `xlsx`), format `html`.
Job `sites`, `site_index`, `wide`. Schedule `wide`. Destination `last_test`. `wide` is never read from
input or exposed. Spread ACF columns are job `headers` keys `acf:<name>|<n>|<sub>`.

Other shipped names: REST namespace `open-export/v1`, filter `opex_capability`, menu slug `open-export`,
field id prefixes `core: meta: tax: computed: col: woo:` (2.0.0 adds `acf:`), source ids. No user meta.
Deactivation clears every `opex_*` cron event. `uninstall.php` removes all of the above on every site.
A site with `keep_data_on_uninstall` keeps its options and files and loses only cron events.

## Trust boundaries

"Capability" means `current_user_can( opex_capability() )` (in `open-export.php`), default
`manage_options`. WordPress checks the `wp_rest` nonce on cookie REST calls.

| Entry point | Who | Guard | At risk |
| --- | --- | --- | --- |
| REST core: sources, fields, schema, recipe, preview, jobs, templates | Admins | capability, `$can` in `OPEX_Rest::register_routes` | Any table, post, user data |
| REST screen: `jobs/<id>/deliver`, `schedules/<id>/reset-watermark`, `presets` | Admins | capability, `OPEX_Rest_Screen::can` | Delivery, watermark |
| REST schedules | Admins | capability, `OPEX_Rest_Schedules::can` | Schedules, trigger keys |
| REST destinations, settings, options | Admins | capability, `OPEX_Rest_Delivery::can` | Credentials (encrypted) |
| `schedules/<id>/trigger?key=` | Key holders | `OPEX_Rest_Public::can_trigger` (`hash_equals`, 10-failure lock), `trigger` (30 s gap) | Starts an export |
| `file/<id>?token=` | Token holders | `OPEX_Download_Tokens::verify` (HMAC, expiry), `OPEX_Rest_Public::can_download` | The export file |
| Admin screen | Admins | capability, `OPEX_Admin::render`, `register_menu` | Screen, REST nonce |
| Abilities | Admins | capability, `OPEX_Abilities::can_use` | Site schema |
| Network export (`network: true`) | Super admins, WP-CLI | `OPEX_Network::may_request` via `OPEX_Config::sanitize_config`, `check_create` via `OPEX_Exporter::create` | Every site's data |
| Site data on a network | Site admins, cron, trigger | `OPEX_Access`: `users_scope`, `table_allowed`, `meta_allowed`, `job_wide` | Other sites' users, shared tables |
| WP-CLI `wp opex` | Shell users | shell access only. `OPEX_CLI_Runner::check_output` (not in plugin) | Exports, output paths |
| Cron hooks | WP-Cron | ids from saved state, `OPEX_Runner::register` | Runs exports, delivery |
| Recipe import | Admins | capability, `OPEX_Recipe::to_config`, `sanitize_config` | Config to run |
| Webhook out | Remote server | `OPEX_Delivery_Webhook::transport_allowed` (https, on save and send), `send` (no redirects, IP pinned on cURL) | Signed payload, link |
| SFTP out | Remote server | host key before login, `OPEX_Delivery_Sftp::open` | Credentials, file |
| FTP and FTPS out | Remote server | passive address ignored. Warning `OPEX_Destinations::security_note` | Credentials, file |
| XML template text | Admins | no DOCTYPE `OPEX_Writer_XML_Template::validate`, `LIBXML_NONET` | XML parser |
| Stored ACF meta | ACF editors | no classes in `unserialize`, `OPEX_Acf::stored_value` | PHP objects |
| Database values in files | Anyone who wrote data | `OPEX_Writer::guard_cell`, `OPEX_Query::FORBIDDEN`, `CREDENTIAL_META_KEYS`, HTML cells escaped | Spreadsheet users |

## Overrides

- `declare(strict_types=1)` only in new files. 1.3.1 files and code split from them keep loose coercion.
- XLSX comes from the in-house `writer-xlsx`, not a library, for a small zip.
- phpseclib (about 3.2 MB, for SFTP) puts the zip far over the usual 200 KB target.
- phpseclib is bundled unprefixed: if another plugin has already loaded phpseclib 3, that copy is used.
- WP-Cron only, no Action Scheduler: no dependency on another plugin.
- No build step, so the shipped code is the source a reviewer reads.
- Trigger keys stay in plain text, by design, so the screen can show the trigger URL again.
