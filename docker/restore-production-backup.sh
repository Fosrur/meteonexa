#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

BACKUP_DIR="${1:-}"
[ -n "$BACKUP_DIR" ] || { echo 'Uso: METEONEXA_RESTORE_CONFIRM=RESTORE docker/restore-production-backup.sh <backup-dir>' >&2; exit 2; }
[ "${METEONEXA_RESTORE_CONFIRM:-}" = 'RESTORE' ] || { echo 'Restore distruttivo non autorizzato. Imposta METEONEXA_RESTORE_CONFIRM=RESTORE.' >&2; exit 2; }
[ -f .env ] || { echo '.env mancante.' >&2; exit 2; }
./docker/verify-backup-restore.sh "$BACKUP_DIR"

# shellcheck disable=SC1091
set -a; . ./.env; set +a
: "${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD mancante}"
RUNTIME_DIR="${METEONEXA_RUNTIME_DIR:-./runtime}"
PREVIOUS="${RUNTIME_DIR}.pre-restore-$(date -u +%Y%m%dT%H%M%SZ)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT INT TERM

tar -xzf "$BACKUP_DIR/runtime.tar.gz" -C "$TMP"
EXTRACTED="$(find "$TMP" -mindepth 1 -maxdepth 1 -type d -print -quit)"
[ -n "$EXTRACTED" ] || { echo 'Runtime backup non valido.' >&2; exit 3; }

printf '%s\n' 'Arresto web/worker...'
docker compose stop web worker
if [ -e "$RUNTIME_DIR" ]; then mv "$RUNTIME_DIR" "$PREVIOUS"; fi
mkdir -p "$(dirname "$RUNTIME_DIR")"
mv "$EXTRACTED" "$RUNTIME_DIR"
chmod -R u+rwX,go-rwx "$RUNTIME_DIR"

printf '%s\n' 'Ripristino database MySQL...'
gzip -dc "$BACKUP_DIR/mysql.sql.gz" | docker compose exec -T db sh -lc 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql -uroot'

docker compose up -d web worker
printf 'Restore completato. Copia runtime precedente conservata in: %s\n' "$PREVIOUS"
printf '%s\n' 'Eseguire ora docker/verify-mysql.sh e gli smoke test applicativi.'
