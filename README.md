# Open Export

Export anything from your WordPress database: every feature included, no add-ons, no upsells, ever.

**Version:** 1.3.0  
**Requires WordPress:** 7.0+  
**Requires PHP:** 7.4+  
**License:** [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html)

---

## Overview

Open Export gets your data out of WordPress with a clean four-step flow: pick a source, filter it, choose your fields, export.

- **Every source**: any post type (including WooCommerce products and orders), users, comments, terms from any taxonomy, and any raw database table.
- **Smart field discovery**: meta keys are automatically grouped by the plugin that owns them: WooCommerce, ACF, Yoast SEO, Rank Math, Elementor, and more. Serialized values are unpacked into readable JSON.
- **Works with AI assistants**: export a JSON description of everything this site can export, hand it to an assistant, and paste back the recipe it writes. The screen configures itself and reports anything it could not honour.
- **WooCommerce orders**: a dedicated Orders source that reads whichever storage the store uses — High-Performance Order Storage or classic posts — and offers the same friendly fields either way.
- **Date ranges**: a preset (today, last 30 days, last month, this quarter…) or a custom From/To range, on any source. Whole days in your site's timezone, end date included.
- **Real filters**: stack rules (equals, contains, greater than, is one of, is empty, …) with AND/OR matching, on core fields, custom fields, and taxonomies. Preview the first rows and the match count before you commit.
- **Three formats**: CSV (Excel-safe: UTF-8 BOM, formula-injection guarded), JSON, and XML.
- **Host-friendly pacing**: exports run in small batches with a configurable pause between them, so a large export won't overwhelm shared hosting. Interrupted exports resume where they left off.
- **Secure by default**: administrator capability plus REST nonces on every endpoint, prepared statements throughout, export files stored in a protected directory with unguessable names, and password hashes are never exportable.

No Pro version. No add-ons to buy. This is all of it.

## Installation

1. Upload the `open-export` folder to `/wp-content/plugins/`, or install from the WordPress plugin directory when available.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Open **Tools → Open Export** in the WordPress admin (or use the **Open Export** link on the Plugins screen).

## Usage

1. **Pick a source**: post type, users, comments, taxonomy, or a raw database table.
2. **Filter** (optional): add rules, preview the first five rows, and check the match count.
3. **Choose fields**: search and select columns; drag chips to reorder; edit chip labels for custom headers.
4. **Export**: pick CSV, JSON, or XML (with delimiter choice for CSV), set pacing, and download when finished.

Save setups as templates, load them later, or re-run exports from history.

## What's coming

See [ROADMAP.md](ROADMAP.md) for the full plan. Highlights:

- **1.5:** interface refactor, scheduled exports, webhooks, WP-CLI, WooCommerce line-item exports (one row per item)
- **2.0:** custom XML templates, value transforms, FTP delivery, multisite export

## FAQ

### Can it export WooCommerce orders?

Yes. Pick **WooCommerce Orders** from the source list. It detects whether the store keeps orders in the classic `wp_posts` table or in High-Performance Order Storage and reads the right one, presenting the same field names either way — order number, status, customer, billing and shipping address, totals, and payment details. Refunds are excluded so totals are not double counted.

### How do I use this with Claude or another assistant?

Open **Work with an AI assistant** at the top of the export screen and press **Copy schema**. That JSON describes this specific site: every source, every field id, the filter operators, the date columns and whether each is UTC or site time, and the legal values for fields like order status. It also carries its own instructions and the exact return format, so the assistant does not have to guess.

Describe the report you want. The assistant replies with a short recipe:

```json
{
  "opex_recipe": 1,
  "name": "Completed March orders",
  "source": "woo:orders",
  "fields": ["woo:order_number", "woo:date_created", "woo:status",
             "woo:billing_first_name", "woo:billing_last_name", "woo:total"],
  "filters": { "relation": "AND",
               "rules": [{ "field": "woo:status", "op": "eq", "value": "wc-completed" }] },
  "date_range": { "field": "woo:date_created", "preset": "last_month" },
  "format": "csv"
}
```

Paste it back and press **Apply recipe**. Every field is checked against what the source really has; anything unrecognised is dropped and named, so a hallucinated field never becomes a silently blank column. Nothing exports until you press **Start export**.

### How do I export just one month of orders?

Pick **WooCommerce Orders**, then in *Filter it down* set the date range to **Last month**, or choose **Custom range…** and enter a From and To date. Dates are whole days in your site's timezone and the end date is included in full. The range always applies on top of your other rules, so switching the rules to "match ANY" will not widen it.

### Where do export files go?

`wp-content/uploads/open-export/`, protected with an `.htaccess` deny rule and random filenames. Downloads are streamed through an authenticated endpoint and files are cleaned up after 30 days.

### Does my data leave my server?

No. Open Export makes no external connections and sends nothing anywhere. Exports are generated on your server and stay there until you download or delete them.

## Development

| File | Purpose |
| ---- | ------- |
| [`CHANGELOG.md`](CHANGELOG.md) | Version history |
| [`ROADMAP.md`](ROADMAP.md) | Upcoming features by release |
| [`readme.txt`](readme.txt) | WordPress.org plugin directory readme |

### Translations

The `languages/` directory ships empty on purpose: strings are extracted and served by WordPress.org (GlotPress). Every user-facing string, in both PHP and JavaScript, runs through the `open-export` text domain.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for full release notes.

### 1.3.0

Schema export and recipe import for working with AI assistants. Fixes date filter rules that excluded the last day of a range, compact dates being treated as numbers, and fields that silently exported as blank columns.

### 1.2.0

WooCommerce Orders source covering both HPOS and classic storage, and a date-range filter on every source. Fixes relative date tokens that resolved in UTC rather than site time, and an attribute-escaping gap in the admin screen.

### 1.1.2

Full admin-app internationalization, multisite-aware uninstall cleanup, third-party services and privacy disclosures, and a backslash-handling fix in filter values. Minimum WordPress version is now 7.0.

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
