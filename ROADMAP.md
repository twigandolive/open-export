# Roadmap

Upcoming work for Open Export, grouped by target release. Plans may change as feedback comes in.

## Version 1.5

Scheduled exports and richer WooCommerce order data:

- WP-Cron scheduled exports
- Incremental export (new or changed records since last run)
- Email on completion (download link or attachment)
- Webhook on completion
- External cron trigger URL (secret key for server cron)
- Background processing (exports continue with the browser tab closed)
- WP-CLI command (`wp opex run …`)
- WooCommerce HPOS orders source (joined, friendly field view)
- WooCommerce order line items (one row per line item)

## Version 2.0

Advanced shaping, delivery, and multi-site support:

- Custom XML templates (safe placeholder syntax, no user PHP)
- Safe declarative value transforms (date format, find/replace, prefix/suffix, concat)
- Nested filter groups (`(A AND B) OR C`)
- FTP / SFTP delivery on completion
- Multisite network-wide export
- Repeater and flexible content field flattening (optional; JSON blobs remain the default)
- Feed presets for common product-feed formats

## Excluded

Deliberately out of scope:

- **Custom PHP / `eval()` snippets**: unacceptable security risk with user-supplied code
- **Excel native (XLS / XLSX)**: heavy dependency and memory use; CSV with UTF-8 BOM opens correctly in Excel
- **Direct cloud spreadsheet sync**: OAuth complexity; webhooks cover most automation needs
- **Full re-import companion**: a separate product; exports will always include ID and stable keys for standard import workflows
- **WordPress WXR export**: already provided by WordPress core
