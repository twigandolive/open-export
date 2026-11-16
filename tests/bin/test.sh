#!/usr/bin/env bash
# Run the PHPUnit suite against a test site.
# Usage: tests/bin/test.sh [--site <dir>] [--legacy-orders] [--php <bin dir>] [phpunit args...]
# --php runs everything with the php in that folder, for example the plugin's floor:
#   tests/bin/test.sh --php /opt/homebrew/opt/php@7.4/bin
# PHP older than 8.2 cannot run the main PHPUnit, so that test run uses PHPUnit 9.6 from
# tests/php74 (install it once with: composer install --working-dir=tests/php74).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
SITE="${OPEX_TEST_SITE:-}"
LEGACY=0
PHP_DIR=""
ARGS=()
while [ $# -gt 0 ]; do
	case "$1" in
		--site) SITE="${2:-}"; shift 2 ;;
		--legacy-orders) LEGACY=1; shift ;;
		--php) PHP_DIR="${2:-}"; shift 2 ;;
		*) ARGS+=("$1"); shift ;;
	esac
done
if [ -n "$PHP_DIR" ]; then
	[ -x "$PHP_DIR/php" ] || { echo "No php in '$PHP_DIR'." >&2; exit 2; }
	PATH="$PHP_DIR:$PATH"
	export PATH
fi
PHPUNIT=(vendor/bin/phpunit)
if [ "$(php -r 'echo PHP_VERSION_ID < 80200 ? 1 : 0;')" = 1 ]; then
	[ -f "$REPO/tests/php74/vendor/bin/phpunit" ] || { echo "Run: composer install --working-dir=tests/php74" >&2; exit 2; }
	PHPUNIT=(tests/php74/vendor/bin/phpunit -c tests/php74/phpunit.xml)
fi
[ -n "$SITE" ] || { echo "Set OPEX_TEST_SITE or pass --site <dir>." >&2; exit 2; }
[ -f "$SITE/wordpress/wp-load.php" ] || { echo "No WordPress in '$SITE/wordpress'." >&2; exit 2; }
SITE="$(cd "$SITE" && pwd)"
export OPEX_TEST_SITE="$SITE"
cd "$REPO"

WP_BIN="$(command -v wp || true)"
wpcli() { php -d memory_limit=1G -d error_reporting=22527 -d display_errors=0 "$WP_BIN" --path="$SITE/wordpress" "$@"; }

# Private MariaDB test run: start the server this site owns, stop it on exit.
if [ -f "$SITE/mariadb.env" ]; then
	# shellcheck disable=SC1091
	. "$HERE/mariadb-lib.sh"
	opex_maria_start "$SITE"
	trap 'opex_maria_stop "$SITE"' EXIT
fi

SWITCHED=0
if [ "$LEGACY" = 1 ]; then
	wpcli wc hpos sync >/dev/null
	wpcli wc hpos disable >/dev/null
	SWITCHED=1
	export OPEX_ORDER_STORAGE=legacy
	restore() { wpcli wc hpos sync >/dev/null 2>&1 || true; wpcli wc hpos enable >/dev/null 2>&1 || true; [ -f "$SITE/mariadb.env" ] && opex_maria_stop "$SITE" || true; }
	trap restore EXIT
	[ -z "${ARGS[*]:-}" ] && ARGS=(--filter 'Orders|Woo|Hpos')
fi

OUT="$(mktemp)"
set +e
php -d memory_limit=1G -d error_reporting=22527 "${PHPUNIT[@]}" ${ARGS[@]+"${ARGS[@]}"} 2>&1 | tee "$OUT"
RC=${PIPESTATUS[0]}
set -e
if [ "$RC" -eq 0 ] && ! grep -Eq '^OK \(|^OK, but' "$OUT"; then
	echo "No passing run reported (zero tests?)." >&2
	RC=1
fi
if grep -Eq 'No tests executed|OK \(0 tests' "$OUT"; then
	echo "Zero tests ran." >&2
	RC=1
fi
rm -f "$OUT"
exit "$RC"
