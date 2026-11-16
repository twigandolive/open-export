#!/usr/bin/env bash
# Run the PHPUnit suite against a test site.
# Usage: tests/bin/test.sh [--site <dir>] [--legacy-orders] [phpunit args...]
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
SITE="${OPEX_TEST_SITE:-}"
LEGACY=0
ARGS=()
while [ $# -gt 0 ]; do
	case "$1" in
		--site) SITE="${2:-}"; shift 2 ;;
		--legacy-orders) LEGACY=1; shift ;;
		*) ARGS+=("$1"); shift ;;
	esac
done
[ -n "$SITE" ] || { echo "Set OPEX_TEST_SITE or pass --site <dir>." >&2; exit 2; }
[ -f "$SITE/wordpress/wp-load.php" ] || { echo "No WordPress in '$SITE/wordpress'." >&2; exit 2; }
SITE="$(cd "$SITE" && pwd)"
export OPEX_TEST_SITE="$SITE"
cd "$REPO"

WP_BIN="$(command -v wp || true)"
wpcli() { php -d memory_limit=1G -d error_reporting=22527 -d display_errors=0 "$WP_BIN" --path="$SITE/wordpress" "$@"; }

# Private MariaDB lane: start the server this site owns, stop it on exit.
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
php -d memory_limit=1G -d error_reporting=22527 vendor/bin/phpunit ${ARGS[@]+"${ARGS[@]}"} 2>&1 | tee "$OUT"
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
