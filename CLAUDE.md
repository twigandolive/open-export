# Open Export

A WordPress plugin. Read `README.md` first. The shared rules in
`../../Standards/engineering/CORE.md` apply; skill `wordpress-plugin`.

## Project notes

- This GitHub repo is public on purpose: the plugin ships on WordPress.org (Doug, 2026-10-31).
  Do not make it private, and never commit anything private to it.
- When you split files, update the explicit `require_once` chain in `open-export.php` (eleven
  requires) and the script handles passed to `wp_enqueue_script` in `includes/` (for example
  `includes/class-opex-admin.php`).
- Option `opex_jobs` is shipped. Never rename it without a migration.
