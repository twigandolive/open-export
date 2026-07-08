# Open Export

Export anything from your WordPress database — every feature included, no add-ons, no upsells, ever.

**Version:** 1.1.1  
**Requires WordPress:** 6.0+  
**Requires PHP:** 7.4+  
**License:** [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html)

---

## Overview

Open Export gets your data out of WordPress with a clean four-step flow: pick a source, filter it, choose your fields, export.

- **Every source** — any post type (including WooCommerce products and orders), users, comments, terms from any taxonomy, and any raw database table.
- **Smart field discovery** — meta keys are automatically grouped by the plugin that owns them: WooCommerce, ACF, Yoast SEO, Rank Math, Elementor, and more. Serialized values are unpacked into readable JSON.
- **Real filters** — stack rules (equals, contains, greater than, is one of, is empty, …) with AND/OR matching, on core fields, custom fields, and taxonomies. Preview the first rows and the match count before you commit.
- **Three formats** — CSV (Excel-safe: UTF-8 BOM, formula-injection guarded), JSON, and XML.
- **Host-friendly pacing** — exports run in small batches with a configurable pause between them, so a large export won't overwhelm shared hosting. Interrupted exports resume where they left off.
- **Secure by default** — administrator capability plus REST nonces on every endpoint, prepared statements throughout, export files stored in a protected directory with unguessable names, and password hashes are never exportable.

No Pro version. No add-ons to buy. This is all of it.

## Installation

1. Upload the `open-export` folder to `/wp-content/plugins/`, or install from the WordPress plugin directory when available.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Open **Tools → Open Export** in the WordPress admin (or use the **Open Export** link on the Plugins screen).

## Usage

1. **Pick a source** — post type, users, comments, taxonomy, or a raw database table.
2. **Filter** (optional) — add rules, preview the first five rows, and check the match count.
3. **Choose fields** — search and select columns; drag chips to reorder; edit chip labels for custom headers.
4. **Export** — pick CSV, JSON, or XML (with delimiter choice for CSV), set pacing, and download when finished.

Save setups as templates, load them later, or re-run exports from history.

## What's coming

See [ROADMAP.md](ROADMAP.md) for the full plan. Highlights:

- **1.5:** scheduled exports, webhooks, WP-CLI, WooCommerce line-item exports
- **2.0:** custom XML templates, value transforms, FTP delivery, multisite export

## FAQ

### Can it export WooCommerce orders?

Yes — orders are a post type, and their meta is grouped under a WooCommerce heading. Sites using HPOS (custom order tables) can export those tables directly via **Raw Database Tables**.

### Where do export files go?

`wp-content/uploads/open-export/`, protected with an `.htaccess` deny rule and random filenames. Downloads are streamed through an authenticated endpoint and files are cleaned up after 30 days.

## Development

| File | Purpose |
|------|---------|
| [`CHANGELOG.md`](CHANGELOG.md) | Version history |
| [`ROADMAP.md`](ROADMAP.md) | Upcoming features by release |
| [`readme.txt`](readme.txt) | WordPress.org plugin directory readme |
| [`languages/open-export.pot`](languages/open-export.pot) | Translation template |

### Generating translations

```bash
wp i18n make-pot . languages/open-export.pot --domain=open-export
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for full release notes.

### 1.1.1

Cleaner source picker: collapsible groups, quick-start steps, Advanced tab for database tables, moved to Tools menu.

### 1.1.0

Saved templates, one-click re-run, drag-to-reorder columns, custom headers, CSV delimiters, and relative date filters.

### 1.0.1

Security and stability: blocks credential columns on raw table exports, REST nonce protection, per-job run locks, server-side export pause, translation template, and WordPress.org readme polish.

### 1.0.0

Initial release.

## Author

[Twig & Olive](https://twigandolive.com)
