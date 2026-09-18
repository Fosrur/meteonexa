#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"; cd "$ROOT"
npm install --package-lock-only --ignore-scripts --no-audit --no-fund
( cd qa && npm install --package-lock-only --ignore-scripts --no-audit --no-fund )
if command -v composer >/dev/null; then composer update --lock --no-interaction --no-progress --prefer-dist; elif [ -x ./composer.phar ]; then php composer.phar update --lock --no-interaction --no-progress --prefer-dist; else echo 'composer mancante' >&2; exit 1; fi
for f in package-lock.json qa/package-lock.json composer.lock; do test -s "$f" || { echo "lockfile non generato: $f" >&2; exit 1; }; done
echo 'Lockfile generation PASS'
