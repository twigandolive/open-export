#!/usr/bin/env bash
# Run PHP_CodeSniffer with the repo's phpcs.xml.dist (WordPress standard plus PHP 7.4 compatibility).
# Usage: tests/bin/lint.sh [extra phpcs arguments]
# Set up once with: composer install --working-dir=tests/lint
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
PHPCS="$REPO/tests/lint/vendor/bin/phpcs"

[ -x "$PHPCS" ] || { echo "phpcs is not installed. Run: composer install --working-dir=tests/lint" >&2; exit 2; }

cd "$REPO"
exec php -d error_reporting=22527 "$PHPCS" --standard=phpcs.xml.dist "$@"
