# Security policy

## How do I report a vulnerability?

Report it privately. Do not open a public issue or post in a public forum. Use one of these:

- The [Twig & Olive contact page](https://twigandolive.com/contact-twig-and-olive/).
- GitHub private vulnerability reporting on `twigandolive/open-export`: open the Security tab, then click "Report a vulnerability".

Include the plugin, WordPress and PHP versions, what you found and why it matters, the shortest steps to reproduce it, and whether it needs a logged-in user (and which role). Leave out passwords, keys and real customer data.

We read every report. We do not promise a response time or a fix date.

## Supported versions

Only the latest release gets security fixes. Update before you report.

## How Open Export protects data

- Every screen, REST route and ability that touches site data needs the `manage_options` capability. A site can change that with the `opex_capability` filter. WordPress checks the REST nonce (a short-lived security token) on requests from a logged-in browser.
- Two routes work without a login: the schedule trigger URL and the file download link. Each answers only to a secret. A wrong secret gets a short reply that does not say why.
- A trigger key is 40 random characters, compared in constant time. Open Export counts wrong keys per schedule and per IP address. After 10 wrong keys for one schedule, or 30 from one IP address across all schedules, the URL answers 429 with a `Retry-After` header, even to the right key, until 10 minutes after the first wrong key. An unknown schedule id gets the same reply as a wrong key. A schedule starts at most once every 30 seconds from its URL.
- A download link carries a random token that expires. The site stores only a keyed hash of the token.
- Export files live in `wp-content/uploads/open-export/`, or `wp-content/uploads/sites/<id>/open-export/` on a multisite sub-site. The folder holds an `index.php`, an `.htaccess` file that denies access on Apache (2.2 and 2.4 rules) and LiteSpeed, and a `web.config` file that denies access on IIS. Site Health names the deny files in place and says nginx without Apache needs its own rule. Each file name includes 12 random characters.
- Open Export saves every file in the export folder with an `.opex` ending, such as `orders-20261116-093000-a1b2c3d4e5f6.csv.opex`. When nginx sits in front of Apache, nginx serves known file types such as `.html` and `.zip` straight from disk, but it passes `.opex` files to Apache, which denies them. Fixed in 2.0.1. In 2.0.0, such a server served HTML and zip exports to anyone who had the exact URL. Downloads and deliveries still use the real name and extension.
- Open Export encrypts destination passwords, private keys and webhook secrets with libsodium, using a key derived from your site's salts. The screen and the REST API never send a saved secret back.
- SFTP checks the server's fingerprint before it sends any password or key, and refuses a server whose fingerprint has changed. Open Export saves a fingerprint only for the server it came from.
- FTP, FTPS and SFTP upload to a temporary name, then rename the file into place. Open Export never deletes a file already on the server when a rename fails.
- A webhook must use https unless it points at your own site or a loopback address (the server itself). Open Export checks that rule when you save the webhook and again before each send, so it refuses a stored plain http address to another host. When you set a secret, Open Export signs the webhook with HMAC-SHA256 in the `X-Opex-Signature` header. If it cannot store the secret encrypted, it turns the webhook off with a note and never sends it unsigned. The webhook goes through WordPress's safe request function, which refuses local network addresses, and it does not follow redirects.
- A failed export shows a plain message, never PHP's own error text, which can hold server paths. The detail goes to the PHP error log only when `WP_DEBUG` and `WP_DEBUG_LOG` are on.
- Open Export never exports `user_pass`, `user_activation_key`, `session_tokens` or `_application_passwords`, on any source.
- CSV cells that start with `=`, `+`, `-`, `@`, a tab or a carriage return get a leading apostrophe, so a spreadsheet does not run them as formulas. Plain numbers such as -5 stay numbers. Open Export writes XLSX cells as text or plain numbers, never formulas.
- XML templates cannot declare a DOCTYPE or entities.
- Open Export reads serialized values in custom fields, columns and raw tables with PHP classes turned off. A stored object of a class is never rebuilt, and no class code runs during an export. Such a value exports as its stored text. Fixed in 2.0.0.
- On a multisite network, a site administrator who is not a super admin reaches only the current site's data: its member users, its own tables, and user meta that belongs to no other site. Fixed in 2.0.0. In 1.3.1 and earlier, such an administrator could export every user of the network and any table.
- Only a network super admin, or WP-CLI, can run an export across every site of a multisite network.
- Uninstalling removes the plugin's options, lock rows, cached data, scheduled events and export files on every site. A site that turned on "Keep my templates, schedules, export history and files" keeps its options and files, saved secrets included, and loses only its scheduled events.

## Limits you should know

- FTP sends your password and the file without encryption.
- FTPS encrypts the connection, but PHP cannot verify the server's certificate. Use SFTP when the server offers it.
- SFTP trusts the fingerprint it sees the first time a test or delivery works. Test a new destination on a network you trust.
- SFTP private keys with a passphrase do not work.
- A schedule's trigger URL is a secret. Anyone who has it can start that export. If it leaks, replace the key.
- The trigger limit counts the address the connection comes from. It ignores forwarding headers, which any caller can set. Behind a proxy or CDN, every caller shares one count, so 30 wrong keys from anyone pause every trigger URL for up to 10 minutes.
- Open Export stores trigger keys as plain text in the database, because the screen shows the URL again. Anyone who can read your database can read them.
- nginx on its own ignores `.htaccess` and `web.config` files and serves `.opex` files like any other file, so there the export folder relies on its random file names. Block the folder in your server settings. The readme's "Where do export files go?" answer has an example rule.
- Exports saved by 2.0.0 keep their old names until the usual cleanup removes them. Until then, nginx in front of Apache can still serve an HTML or zip export from 2.0.0 to anyone who has its exact URL. Delete those exports on the History tab to remove them sooner.
- Open Export checks webhook addresses when you save them and again before each send. On servers that send with cURL, it pins the checked address for the request. Other transports cannot pin it, so on those a hostile DNS answer could still send the request to another address.
- FTP, FTPS and SFTP destinations may point at hosts on your local network, because an administrator sets them up. The "Save and test" button reaches those hosts too.
- FTP and FTPS ignore the passive address a server sends, and open the file connection to the address they logged in to.
- Anyone with shell access to WP-CLI can run exports, including network exports. WP-CLI adds no extra check.
- On a network, a schedule that a super admin saves on a site keeps network-wide reach when cron or its trigger URL runs it. That site's administrators can see its trigger URL and download its files, as they can for any export on their site.
