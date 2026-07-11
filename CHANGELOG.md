# Changelog

All notable changes to Open Export are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
