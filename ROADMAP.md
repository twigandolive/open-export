# Roadmap

These are ideas for after Open Export 2.0.0. None of them has a date, and plans may change as feedback comes in. Everything the old roadmap listed for 1.5 and 2.0 ships in 2.0.0, as the [changelog](CHANGELOG.md) shows.

## Ideas

None of these is built or promised. Each one waits on a decision.

- **WooCommerce Subscriptions as a source**, with one row per subscription.
- **Summary or grouped reports**, such as sales by product or by customer.
- **Cloud destinations:** Amazon S3, Dropbox and Google Drive.
- **WPML and Polylang language columns**, and a filter by language.
- **Cron expressions and 5-minute schedules**, for timetables the fixed choices do not cover. A cron expression is a one-line pattern that describes a repeating timetable.
- **A server folder destination** that copies the file to a folder on the same host.
- **The ODS format** for LibreOffice and other OpenDocument programs.
- **An order notes column** on WooCommerce orders.
- **A zip of product images** next to a product export.
- **A dedicated capability and per-template visibility**, so a shop manager can run chosen exports without reaching every source.
- **Posting the file itself to a webhook**, not only a signed download link.
- **Friendly names for SEO plugin fields** instead of their raw meta keys.
- **A run log** with each step of an export, for troubleshooting.
- **Several server destinations for one export.** Today an export goes to one FTP, FTPS or SFTP destination, plus email and a webhook.
- **A `{count}` file name token** for the number of rows.
- **SFTP keys with a passphrase.** Today a private key must have no passphrase.
- **Reuse a saved webhook secret.** Today a one-off export that sends a signed webhook needs the secret typed again, and so does a schedule made with Duplicate.
- **"Last used" on saved templates**, so you can tell which templates are still in use.

## Left out on purpose

Open Export leaves these out, for the reasons given.

- **Custom PHP or `eval()` snippets:** running code typed into a form is too great a security risk. Transforms and custom columns cover the common needs without code.
- **Marking rows as exported:** it would write a flag into your own data for every row. "Changes only" schedules already export only what is new since the last run.
- **Export when an order changes status:** it would run an export inside the checkout request and slow it down. A schedule or the trigger URL does the same job outside checkout.
- **Google Sheets sync:** it needs a sign-in with Google and an outside service. A webhook or an SFTP upload covers most automation.
- **Splitting one export into several files:** one export makes one file, which keeps history and delivery simple. A filter or date range can cut a large export into parts.
- **A front-end shortcode:** it would put private data on public pages.
- **PDF export:** it needs a large library. The HTML table format prints, and your browser can save it as a PDF.
- **WordPress WXR export** (WordPress's own XML export format): WordPress already has it under Tools > Export.
- **A re-import companion:** that is a separate product. Exports always include ids and stable keys for standard import tools.
- **Password hash export:** it would hand out every user's password hash, a credential leak by design.
- **Action Scheduler** (a job queue library that ships with WooCommerce): Open Export uses WP-Cron only, so it has no dependency on another plugin's queue.
- **A template that holds delivery:** delivery belongs to schedules, which already hold it.
- **Friendly labels for core fields such as `post_title`:** the labels are the default column headers of existing exports, so changing them would change files people already rely on.
