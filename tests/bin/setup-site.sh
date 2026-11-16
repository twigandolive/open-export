#!/usr/bin/env bash
# Build a throwaway local WordPress site for testing the Open Export plugin.
# Usage: setup-site.sh [--mariadb | --multisite] <target-dir>
# Database: SQLite (SQLite Database Integration plugin), no server needed.
# With --mariadb it starts a private MariaDB 10.11 (its own data directory, pid file and
# 127.0.0.1 port inside the target folder), builds the same seeded site on it, then stops it.
# tests/bin/test.sh --site <target> starts and stops that server around a test run.
# With --multisite (SQLite only) it builds a subdirectory network: the main site plus /second/
# and /third/, Open Export network-activated, WooCommerce on the main site only, and a few
# posts and one extra user on each sub-site. The admin is a super admin.
# Safe to rerun: it only wipes a directory that holds the marker file it wrote.
set -euo pipefail

MARIA=0
MULTI=0
while [ "${1:-}" = "--mariadb" ] || [ "${1:-}" = "--multisite" ]; do
	if [ "$1" = "--mariadb" ]; then MARIA=1; else MULTI=1; fi
	shift
done
TARGET="${1:-}"
[ -n "$TARGET" ] || { echo "Usage: $0 [--mariadb | --multisite] <target-dir>" >&2; exit 2; }
if [ "$MARIA" = 1 ] && [ "$MULTI" = 1 ]; then
	echo "--multisite works with SQLite only. Do not combine it with --mariadb." >&2
	exit 2
fi

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
MARKER=".opex-test-site"
ADMIN_USER="admin"
ADMIN_PASS="opex-test-pass"   # throwaway, local only
SITE_URL="http://example.test"

# shellcheck disable=SC1091
. "$HERE/mariadb-lib.sh"

# Refuse to touch a directory we did not create.
if [ -e "$TARGET" ]; then
	if [ ! -f "$TARGET/$MARKER" ]; then
		if [ -z "$(ls -A "$TARGET" 2>/dev/null)" ]; then
			: # empty directory is fine
		else
			echo "Refusing: '$TARGET' exists and has no $MARKER marker." >&2
			exit 1
		fi
	else
		echo "Wiping previous test site in '$TARGET'"
		[ -f "$TARGET/mariadb.env" ] && opex_maria_stop "$(cd "$TARGET" && pwd)"
		find "$TARGET" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
	fi
fi
mkdir -p "$TARGET"
TARGET="$(cd "$TARGET" && pwd)"
echo "opex test site, built by tests/bin/setup-site.sh" > "$TARGET/$MARKER"

# Run wp-cli with more memory and without PHP 8.5 deprecation noise.
WP_BIN="$(command -v wp)"
wp() { php -d memory_limit=1G -d error_reporting=22527 -d display_errors=0 "$WP_BIN" "$@"; }
export WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1
WP=(wp --path="$TARGET/wordpress")

echo "1/6 Downloading WordPress"
wp core download --path="$TARGET/wordpress" --force

WPC="$TARGET/wordpress/wp-content"
if [ "$MARIA" = 1 ]; then
	echo "2/6 Starting a private MariaDB"
	opex_maria_init "$TARGET"
	opex_maria_start "$TARGET"
	trap 'opex_maria_stop "$TARGET"' EXIT
	opex_maria_create_db "$TARGET"
	"${WP[@]}" config create --dbname=opex_test --dbuser=opex --dbpass=opex-test-pass \
		--dbhost="127.0.0.1:$(opex_maria_env "$TARGET" PORT)" --dbcharset=utf8mb4 --dbcollate=utf8mb4_unicode_520_ci --skip-check --force
else
	echo "2/6 Setting up SQLite"
	"${WP[@]}" config create --dbname=opex_test --dbuser=none --dbpass=none --skip-check --force
	# WordPress cannot load yet (no database), so fetch the plugin zip directly.
	ZIP="$TARGET/sqlite-plugin.zip"
	curl -fsSL -o "$ZIP" "https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip"
	unzip -q -o "$ZIP" -d "$WPC/plugins"
	rm -f "$ZIP"
	# Install the db.php drop-in from the plugin's template.
	sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$WPC/plugins/sqlite-database-integration#g" \
		-e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" \
		"$WPC/plugins/sqlite-database-integration/db.copy" > "$WPC/db.php"
fi

echo "3/6 Installing WordPress"
if [ "$MULTI" = 1 ]; then
	"${WP[@]}" core multisite-install --url="$SITE_URL" --title="Open Export Test" \
		--admin_user="$ADMIN_USER" --admin_password="$ADMIN_PASS" \
		--admin_email="admin@example.test" --skip-email
	"${WP[@]}" site create --slug=second --title="Second Site" --email="admin@example.test"
	"${WP[@]}" site create --slug=third --title="Third Site" --email="admin@example.test"
else
	"${WP[@]}" core install --url="$SITE_URL" --title="Open Export Test" \
		--admin_user="$ADMIN_USER" --admin_password="$ADMIN_PASS" \
		--admin_email="admin@example.test" --skip-email
fi

echo "4/6 Linking and activating the plugin"
ln -s "$REPO" "$WPC/plugins/open-export"
if [ "$MULTI" = 1 ]; then
	"${WP[@]}" plugin activate open-export --network
else
	"${WP[@]}" plugin activate open-export
fi

echo "5/6 Installing WooCommerce"
"${WP[@]}" plugin install woocommerce --activate
"${WP[@]}" option update woocommerce_custom_orders_table_enabled yes >/dev/null 2>&1 || true

echo "6/6 Seeding data"
"${WP[@]}" eval-file "$HERE/seed.php"
if [ "$MULTI" = 1 ]; then
	for SLUG in second third; do
		"${WP[@]}" --url="$SITE_URL/$SLUG/" eval-file "$HERE/seed-subsite.php" "$SLUG"
	done
fi

echo "Done. Site: $TARGET/wordpress  (admin / $ADMIN_PASS)"
echo "Use: wp --path='$TARGET/wordpress' plugin list"
