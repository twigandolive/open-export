# Roadmap

Ideas for after Open Export 2.0.0. None of these has a date, and plans may change as feedback comes in. Everything the old roadmap listed for 1.5 and 2.0 ships in 2.0.0: see [CHANGELOG.md](CHANGELOG.md).

## Ideas

- **SFTP keys with a passphrase.** Today a private key must have no passphrase. Support for an encrypted key would let you use the key you already have.
- **Reuse a saved webhook secret.** Today a one-off export that sends a signed webhook needs the secret typed again, and so does a schedule made with Duplicate.
- **"Last used" on saved templates**, so you can tell which templates are still in use.

## Excluded

Left out on purpose:

- **Custom PHP or `eval()` snippets:** running code typed into a form is too great a security risk. Transforms and custom columns cover the common needs without code.
- **Marking rows as exported:** it writes a flag into your own data for every row. Incremental schedules already export only what is new since the last run.
- **Export when an order changes status:** it would run an export inside the checkout request and slow it down. A schedule or the trigger URL does the same job outside checkout.
- **Google Sheets sync:** it needs a sign-in with Google and an outside service. A webhook or an SFTP upload covers most automation.
- **Splitting one export into several files:** one export makes one file, which keeps history and delivery simple. A filter or date range can cut a large export into parts.
- **A front-end shortcode:** it would put private data on public pages.
- **PDF export:** it needs a large library, and a data export belongs in a spreadsheet.
- **WordPress WXR export:** WordPress already has it under Tools, Export.
- **A re-import companion:** that is a separate product. Exports always include ids and stable keys for standard import tools.
