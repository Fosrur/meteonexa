#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

TMP="$(mktemp "${TMPDIR:-/tmp}/meteonexa-sha256.XXXXXX")"
trap 'rm -f "$TMP"' EXIT

# Prefer the repository index when this is a real checkout. This guarantees
# that every tracked release file is covered. A source bundle without .git
# uses the deterministic find fallback below.
if GIT_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" && [ "$(cd "$GIT_ROOT" && pwd)" = "$ROOT" ]; then
  git ls-files -z \
    | while IFS= read -r -d '' file; do
        [ "$file" = "SHA256SUMS.txt" ] && continue
        [ -f "$file" ] || continue
        printf './%s\0' "$file"
      done \
    | sort -z \
    | xargs -0 -r sha256sum > "$TMP"
else
  find . -type f \
    ! -path './SHA256SUMS.txt' \
    ! -path './.git/*' \
    ! -path './node_modules/*' \
    ! -path './qa/node_modules/*' \
    ! -path './vendor/*' \
    ! -path './runtime/*' \
    ! -path './runtime-staging/*' \
    ! -path './backups/*' \
    ! -path './api/storage/*' \
    ! -path './qa/playwright-report/*' \
    ! -path './qa/test-results/*' \
    ! -name '.env' \
    ! -name '.env.staging' \
    ! -name '.installer-key' \
    ! -name '.eslintcache' \
    ! -name '.php-cs-fixer.cache' \
    ! -name '.phpstan.cache' \
    -print0 \
    | sort -z \
    | xargs -0 -r sha256sum > "$TMP"
fi

mv "$TMP" SHA256SUMS.txt
trap - EXIT
printf 'SHA256SUMS.txt aggiornato: %s file.\n' "$(wc -l < SHA256SUMS.txt)"
