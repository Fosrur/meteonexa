#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

[ -f .env ] || { echo 'ERRORE: .env mancante.' >&2; exit 1; }
# shellcheck disable=SC1091
set -a; . ./.env; set +a
: "${MYSQL_DATABASE:?MYSQL_DATABASE mancante}" "${MYSQL_USER:?MYSQL_USER mancante}" "${MYSQL_PASSWORD:?MYSQL_PASSWORD mancante}"

RUNTIME_DIR="${METEONEXA_RUNTIME_DIR:-./runtime}"
[ -d "$RUNTIME_DIR" ] || { echo "ERRORE: runtime non trovato: $RUNTIME_DIR" >&2; exit 1; }

docker compose ps --status running db | grep -q . || { echo 'ERRORE: container db non attivo.' >&2; exit 1; }

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP_ROOT="${METEONEXA_BACKUP_DIR:-./backups}"
DEST="${1:-$BACKUP_ROOT/$STAMP}"
umask 077
mkdir -p "$DEST"

printf '%s\n' 'Backup MySQL consistente...'
docker compose exec -T db sh -lc '
  export MYSQL_PWD="$MYSQL_PASSWORD"
  exec mysqldump --user="$MYSQL_USER" --single-transaction --quick --routines --triggers --events --hex-blob --no-tablespaces --set-gtid-purged=OFF --databases "$MYSQL_DATABASE"
' | gzip -9 > "$DEST/mysql.sql.gz"

test -s "$DEST/mysql.sql.gz" || { echo 'ERRORE: dump MySQL vuoto.' >&2; exit 1; }

printf '%s\n' 'Backup runtime applicativo...'
RUNTIME_PARENT="$(cd "$(dirname "$RUNTIME_DIR")" && pwd -P)"
RUNTIME_BASE="$(basename "$RUNTIME_DIR")"
tar -C "$RUNTIME_PARENT" \
  --exclude="$RUNTIME_BASE/*.lock" \
  --exclude="$RUNTIME_BASE/*.pid" \
  -czf "$DEST/runtime.tar.gz" "$RUNTIME_BASE"

test -s "$DEST/runtime.tar.gz" || { echo 'ERRORE: archivio runtime vuoto.' >&2; exit 1; }

{
  printf 'created_utc=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  printf 'app_version=20.1\n'
  printf 'schema_expected=28\n'
  printf 'runtime_source=%s\n' "$RUNTIME_DIR"
  printf 'mysql_database=%s\n' "$MYSQL_DATABASE"
} > "$DEST/metadata.txt"

(
  cd "$DEST"
  sha256sum mysql.sql.gz runtime.tar.gz metadata.txt > SHA256SUMS
)
chmod -R go-rwx "$DEST"
printf 'Backup creato: %s\n' "$DEST"
printf '%s\n' 'Esegui docker/verify-backup-restore.sh <backup-dir> prima di considerarlo valido.'
