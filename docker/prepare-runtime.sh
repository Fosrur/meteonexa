#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

RUNTIME_DIR="${1:-${METEONEXA_RUNTIME_DIR:-./runtime}}"
mkdir -p "$RUNTIME_DIR"

owner="$(stat -c '%u:%g' "$RUNTIME_DIR")"
mode="$(stat -c '%a' "$RUNTIME_DIR")"

# The container runs as www-data (33:33). On CI/host systems, changing the
# owner first means the invoking user can no longer chmod the directory. Apply
# ownership and mode in the same privileged branch so staging is deterministic.
if [ "$(id -u)" -eq 0 ]; then
  [ "$owner" = '33:33' ] || chown -R 33:33 "$RUNTIME_DIR"
  [ "$mode" = '770' ] || chmod 0770 "$RUNTIME_DIR"
elif command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1; then
  [ "$owner" = '33:33' ] || sudo chown -R 33:33 "$RUNTIME_DIR"
  [ "$mode" = '770' ] || sudo chmod 0770 "$RUNTIME_DIR"
else
  if [ "$owner" != '33:33' ]; then
    echo "ERRORE: $RUNTIME_DIR deve appartenere a uid/gid 33:33 per i container non-root." >&2
    echo "Esegui: sudo chown -R 33:33 '$RUNTIME_DIR' && sudo chmod 0770 '$RUNTIME_DIR'" >&2
    exit 3
  fi
  if [ "$mode" != '770' ]; then
    echo "ERRORE: $RUNTIME_DIR deve avere mode 0770." >&2
    echo "Esegui: sudo chmod 0770 '$RUNTIME_DIR'" >&2
    exit 3
  fi
fi

[ "$(stat -c '%u:%g' "$RUNTIME_DIR")" = '33:33' ] || { echo 'ERRORE: ownership runtime non applicata.' >&2; exit 3; }
[ "$(stat -c '%a' "$RUNTIME_DIR")" = '770' ] || { echo 'ERRORE: mode runtime non applicato.' >&2; exit 3; }
printf 'Runtime pronto: %s owner=33:33 mode=0770\n' "$RUNTIME_DIR"
