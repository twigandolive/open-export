# Architecture

Index of Open Export 2.0.0. Detail: the code, [README.md](README.md).

## Stack

- PHP 7.4+, WordPress 7.0+ (`open-export.php` header). WooCommerce is optional.
- `open-export.php` has one `require_once` per class file, in load order (`cli` loads `cli-runner`).
- `lib/vendor`: phpseclib 3 and two paragonie packages (MIT) for SFTP, loaded on first use.
  Rebuild: `composer install --no-dev --optimize-autoloader --working-dir=lib`.
- Root `composer.json`, `tests/php74/`, `tests/lint/`: test tooling, never shipped.
- Secrets use libsodium. XLSX and zip need `ZipArchive`. FTP and FTPS need `ftp`.

## Build and test

- Setup: `composer install` (PHP 8.2+), again with `--working-dir=tests/php74` and `tests/lint`.
- Test site: `tests/bin/setup-site.sh [--mariadb | --multisite] <dir>`. Throwaway, never live.
- Four test runs, all required before a commit. `test.sh` fails when zero tests run.
  1. SQLite: `OPEX_TEST_SITE=<dir> tests/bin/test.sh`
  2. MariaDB: `tests/bin/test.sh --site <mariadb dir>` (own server)
  3. Classic orders: `tests/bin/test.sh --site <dir> --legacy-orders`
  4. PHP 7.4: `tests/bin/test.sh --site <dir> --php <7.4 bin folder>`
- Multisite: `--filter 'NetworkExportTest|NetworkAccessTest|SmokeTest|UninstallTest'` on a `--multisite` site.
  ACF: `--filter AcfFieldsTest` with Secure Custom Fields (by hand). Both skip on a single site.
- Lint: `tests/bin/lint.sh` (`phpcs.xml.dist`).
- `OPEX_TEST_TOOLS`: a folder whose `venv/` has `pyftpdlib`, `asyncssh`, `pyopenssl`. Without it, FTP tests skip.
- Release zip: `git archive` of a commit. `.gitattributes` keeps dev files and `.wordpress-org/` out.

## Compartments

Files are `includes/class-opex-<name>.php`. Other code uses only the boundary named last.

- Sources: `sources` (registry), `source-provider`, `-posts`, `-table`, `-mapped`, `-woo-*`, `-labels`,
  `woo`, `-format`, `-order-picker`, `money`, `decimal`. Boundary: `OPEX_Sources`.
- ACF: `acf`, `-location`, `-spread` (20 rows max). Boundary: `OPEX_Acf`, `OPEX_Acf_Spread`.
- Catalog, schema, recipes: `catalog`, `-meta`, `-fields`, `core-columns`, `field-types`, `dates`, `schema`, `-sources`,
  `-resolver`, `-document`, `recipe`, `-rules`, `presets`, `preset-product-csv`. Boundary: `OPEX_Catalog`, `OPEX_Schema`, `OPEX_Recipe`.
- Query: `query`, `-fetch`, `-filters`, `-relations`, `-dates`, `-rows`, `filter-tree`. Boundary: `OPEX_Query`.
- Engine and jobs: `config`, `-output` (CSV, zip keys), `exporter`, `export-run`, `jobs`, `compress`, `lock`,
  `file-lock` (flock), `store` (compare under lock, 3 tries, then busy, never unlocked),
  `user-error` (text for people), `filename`, `download`. Boundary: `OPEX_Exporter`, `OPEX_Jobs`.
- Multisite: `network`, `access` (what a caller may reach). Boundary: `OPEX_Network`, `OPEX_Access`.
- Writers: `writer` (base, formats), `-csv`, `-json`, `-xml`, `-xlsx`, `-xml-template`, `-html`. Boundary: `OPEX_Writer`.
- Transforms: `transforms`, `custom-columns`. Boundary: `OPEX_Transforms`.
- Templates and settings: `templates`, `settings`. Boundary: `OPEX_Templates`, `OPEX_Settings`.
- Schedules and runner: `schedules`, `schedule-rules`, `-clock`, `-start`, `-owed`, `runner`,
  `cron-retry` (5 busy tries). Boundary: `OPEX_Schedules`, `OPEX_Runner`.
- Delivery: `delivery`, `-email`, `-webhook`, `-ftp`, `-sftp`, `destinations`. Boundary: `OPEX_Delivery`, `OPEX_Destinations`.
- Secrets and links: `secrets`, `download-tokens`. Boundary: `OPEX_Secrets`, `OPEX_Download_Tokens`.
- REST: `rest`, `-jobs`, `-screen`, `-schedules`, `-delivery`, `-public`, `-guard` (every route: busy 503,
  else 400 or 500). Boundary: each `register_routes()`.
- CLI: `cli`, `-runner` (`wp opex`). Boundary: `OPEX_CLI::register()`.
- Admin screen: `admin`, `assets/app/` (40 classic scripts, 2 stylesheets, `window.opexApp`,
  handles in `OPEX_Admin::SCRIPTS`). Boundary: `OPEX_Admin::register_menu()`, `enqueue()`.
- Integrations: `privacy`, `site-health`, `abilities`. Boundary: each `register()`.

Provider seam: each `woo:*` source is an `OPEX_Source_Posts`, or an `OPEX_Source_Table` with the
`OPEX_Source_Mapped` trait. New source: a provider plus one registry line.

Network runs: `OPEX_Network` batches in `switch_to_blog`, always restored. Job, lock and file
stay on the starting site.

Hooks: `opex_job_done` (after delivery planning): `OPEX_Schedules::on_job_done`,
`OPEX_Runner::on_job_done` (priority 20). `opex_job_failed` (a batch error): `OPEX_Schedules::on_job_failed`.

## Saved state

"Shipped" means in 1.3.1. Never rename a shipped name without a migration. Options autoload off.
New config and job keys are add-only and saved only when not default. Reads fill defaults.

| Name | Kind | Shipped |
|---|---|---|
| `opex_jobs` | option, latest 200 (trimming skips running, paused), token hashes | yes |
| `opex_templates` | option, at most 100 | yes |
| `opex_schedules` | option, at most 50 (trigger keys in plain text) | no |
| `opex_destinations` | option, at most 20 (encrypted secrets, pinned fingerprints, last test) | no |
| `opex_settings` | option: `retention_days`, `link_days`, `background`, `keep_data_on_uninstall` | no |
| `opex_lock_<name>` | option row `<expiry>:<token>`, INSERT IGNORE. Names: job id, `sched_<id>`, `deliver_<job id>`, `store_<option>`, `trig_*` | name yes (was a transient) |
| `opex_table_status`, `opex_acf_keys`, `opex_keys_*` | transients, 5 minute caches | yes |
| `opex_trigger_<id>`, `opex_trig_fail_<id>`, `opex_trig_ip_<hash>`, `opex_retry_<hash>` | transients: start gap, wrong keys (per schedule, per IP), cron retries | no |
| `opex_run_schedule`, `opex_run_job`, `opex_deliver` | single cron events, one per id | no |
| `opex_maintenance` | hourly cron event | no |
| `uploads/open-export/` | export files, sidecars `*.rows`, `*.rows.n`, `*.zip.tmp`, `*.zip.part`, `*.lock` (while written), `index.php`, `.htaccess`, `web.config` | yes |
| system temp `opex-<hex>/` | email attachment copy, removed once sent | no |

New in 2.0.0, add-only: config `network`, `acf_flatten` (`acf:` ids), `csv_enclosure`,
`csv_quote_all`, `compress` (`none` or `zip`, never with `xlsx`), format `html`.
Job `sites`, `site_index`, `schedule_owed`. Job and schedule `wide`, `generation`.
Job `cursor` may be digit text. Destination `last_test`. `wide` is never read from
input or exposed. Spread ACF columns are job `headers` keys `acf:<name>|<n>|<sub>`.

Other shipped names: namespace `open-export/v1`, filter `opex_capability`, menu slug `open-export`,
field id prefixes `core: meta: tax: computed: col: woo:` (2.0.0 adds `acf:`), source ids. No user meta.
Deactivation clears every `opex_*` cron event (each site on a network deactivation). `uninstall.php`
removes all of the above on every site.
A site with `keep_data_on_uninstall` keeps its options and files and loses only cron events.

## Trust boundaries

"Capability": `current_user_can( opex_capability() )`, default `manage_options`, plus the `wp_rest` nonce on cookie REST calls.

| Entry point | Who | Guard | At risk |
|---|---|---|---|
| REST core: sources, fields, schema, recipe, preview, jobs, templates | Admins | capability, `$can` in `OPEX_Rest::register_routes` | Any site data |
| REST screen: `jobs/<id>/deliver`, `schedules/<id>/reset-watermark`, `presets` | Admins | capability, `OPEX_Rest_Screen::can` | Delivery, watermark |
| REST schedules | Admins | capability, `OPEX_Rest_Schedules::can` | Schedules, trigger keys |
| REST destinations, settings, options | Admins | capability, `OPEX_Rest_Delivery::can` | Credentials (encrypted) |
| `schedules/<id>/trigger?key=` | Key holders | `OPEX_Rest_Public::can_trigger` (`hash_equals`, wrong-key caps), `trigger` (30 s gap) | Starts an export |
| `file/<id>?token=` | Token holders | `OPEX_Download_Tokens::verify` (HMAC, expiry), `OPEX_Rest_Public::can_download` | The export file |
| Admin screen | Admins | capability, `OPEX_Admin::render`, `register_menu` | Screen, REST nonce |
| Abilities | Admins | capability, `OPEX_Abilities::can_use` | Site schema |
| Network export (`network: true`) | Super admins, WP-CLI | `OPEX_Network::may_request` (config), `check_create` (job) | Every site's data |
| Site data on a network | Site admins, cron, trigger | `OPEX_Access`: `users_scope`, `table_allowed`, `meta_allowed`, `job_wide` | Other sites' users, shared tables |
| WP-CLI `wp opex` | Shell users | shell access only. `OPEX_CLI_Runner::check_output` (not in plugin) | Exports, output paths |
| Cron hooks | WP-Cron | ids from saved state, `OPEX_Runner::register` | Runs exports, delivery |
| Recipe import | Admins | capability, `OPEX_Recipe::to_config`, `sanitize_config` | Config to run |
| Webhook out | Remote server | `OPEX_Delivery_Webhook::transport_allowed` (https), `send` (no redirects), `pin_for` (IP pin, cURL only) | Signed payload, link |
| SFTP out | Remote server | fingerprint before login, `OPEX_Delivery_Sftp::open` | Credentials, file |
| FTP and FTPS out | Remote server | passive address ignored, `OPEX_Delivery_Ftp::open`. Warning `OPEX_Destinations::security_note` | Credentials, file |
| XML template text | Admins | no DOCTYPE `OPEX_Writer_XML_Template::validate`, `LIBXML_NONET` | XML parser |
| Stored serialized values | Data writers | no classes in `unserialize`: `OPEX_Query_Rows::flatten`, `OPEX_Acf::stored_value` | PHP objects |
| Database values in files | Anyone who wrote data | `OPEX_Writer::guard_cell`, `OPEX_Query::FORBIDDEN`, `CREDENTIAL_META_KEYS`, HTML cells escaped | Spreadsheet users |

## Overrides

- `declare(strict_types=1)` only in new files. 1.3.1 files and their splits keep loose coercion.
- XLSX: in-house `writer-xlsx`, no library.
- phpseclib (3.2 MB, for SFTP) puts the zip far over the 200 KB target. Bundled unprefixed, so a phpseclib 3
  that another plugin loaded first wins.
- WP-Cron only, no Action Scheduler (no plugin dependency).
- No build step: reviewers read the shipped code.
- Trigger keys stay plain text so the screen can show the URL again.
