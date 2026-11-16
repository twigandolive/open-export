# Security policy

## Report a vulnerability privately

Do not open a public issue or post in a public forum. Use one of these:

- The contact page: <https://twigandolive.com/contact-twig-and-olive/>
- GitHub private vulnerability reporting on `twigandolive/open-export` (the Security tab, then "Report a vulnerability").

Include the plugin, WordPress and PHP versions, what you found and why it matters, the shortest steps to reproduce it, and whether it needs a logged-in user (and which role). Leave out passwords, keys and real customer data.

We read every report. We do not promise a response time or a fix date.

## Supported versions

Only the latest release gets security fixes. Update before you report.

## How the plugin protects data

- Every screen, REST route and ability that touches site data needs the `manage_options` capability. A site can change it with the `opex_capability` filter. WordPress checks the REST nonce on requests from a logged-in browser.
- Two routes work without a login: the schedule trigger URL and the file download link. Each answers only to a secret, and a wrong secret gets a short reply that does not say why.
- A trigger key is 40 random characters, compared in constant time. After 10 wrong keys in 10 minutes the URL stops answering for the rest of that window. A schedule starts at most once every 30 seconds from its URL.
- A download link carries a random token that expires. The site stores only a keyed hash of the token.
- Export files live in `wp-content/uploads/open-export/`, which holds an `index.php` and an `.htaccess` file that denies access. Each file name includes 12 random characters.
- Destination passwords, private keys and webhook secrets are encrypted with libsodium, with a key derived from your site's salts. The screen and the REST API never send a saved secret back.
- SFTP checks the server's host key before it sends any password or key, and refuses a server whose key has changed.
- A webhook must use https unless it points at your own site or a loopback address. The rule is checked when you save the webhook and again before each send, so a stored plain http address to another host is refused. It is signed with HMAC-SHA256 in the `X-Opex-Signature` header when you set a secret. It goes through WordPress's safe request function, which refuses local network addresses, and it does not follow redirects.
- `user_pass`, `user_activation_key`, `session_tokens` and `_application_passwords` are never exported, on any source.
- CSV cells that start with `=`, `+`, `-`, `@`, a tab or a carriage return get a leading apostrophe, so a spreadsheet does not run them as formulas. Plain numbers such as -5 stay numbers. XLSX cells are written as text or plain numbers, never formulas.
- XML templates cannot declare a DOCTYPE or entities.
- On a multisite network, a site administrator who is not a super admin reaches only the current site's data: its member users, its own tables, and user meta that belongs to no other site. Fixed in 2.0.0. In 1.3.1 and earlier, such an administrator could export every user of the network and any table.
- Only a network super admin, or WP-CLI, can run an export across every site of a multisite network.
- Uninstalling removes the plugin's options, lock rows, cached data, scheduled events and export files on every site.

## Limits you should know

- FTP sends your password and the file without encryption.
- FTPS encrypts the connection, but PHP cannot verify the server's certificate. Use SFTP when the server offers it.
- SFTP trusts the host key it sees the first time a test or delivery works. Test a new destination on a network you trust.
- SFTP private keys with a passphrase are not supported.
- A schedule's trigger URL is a secret. Anyone who has it can start that export. Replace the key if it leaks.
- Trigger keys are stored as plain text in the database, because the screen shows the URL again. Anyone who can read your database can read them.
- On servers that ignore `.htaccess` files, such as nginx, the export folder relies on its random file names. Block the folder in your server settings.
- Anyone with shell access to WP-CLI can run exports, including network exports. WP-CLI adds no extra check.
- On a network, a schedule a super admin saves on a site keeps network-wide reach when cron or its trigger URL runs it. That site's administrators can see its trigger URL and download its files, as they can for any export on their site.
