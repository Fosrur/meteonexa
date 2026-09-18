#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

RUNTIME_DIR="${1:-${METEONEXA_RUNTIME_DIR:-./runtime}}"
mkdir -p "$RUNTIME_DIR"

owner="$(stat -c '%u:%g' "$RUNTIME_DIR")"
if [ "$owner" != '33:33' ]; then
  if [ "$(id -u)" -eq 0 ]; then
    chown -R 33:33 "$RUNTIME_DIR"
  elif command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1; then
    sudo chown -R 33:33 "$RUNTIME_DIR"
  else
    echo "ERRORE: $RUNTIME_DIR deve appartenere a uid/gid 33:33 per i container non-root." >&2
    echo "Esegui: sudo chown -R 33:33 '$RUNTIME_DIR'" >&2
    exit 3
  fi
fi
chmod 0770 "$RUNTIME_DIR"
[ "$(stat -c '%u:%g' "$RUNTIME_DIR")" = '33:33' ] || { echo 'ERRORE: ownership runtime non applicata.' >&2; exit 3; }
printf 'Runtime pronto: %s owner=33:33 mode=0770\n' "$RUNTIME_DIR"
