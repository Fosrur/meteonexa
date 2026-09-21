#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

BACKUP_DIR="${1:-}"
[ -n "$BACKUP_DIR" ] || { echo 'Uso: docker/verify-backup-restore.sh <backup-dir>' >&2; exit 2; }
[ -d "$BACKUP_DIR" ] || { echo "Backup non trovato: $BACKUP_DIR" >&2; exit 2; }
for f in mysql.sql.gz runtime.tar.gz metadata.txt SHA256SUMS; do
  [ -f "$BACKUP_DIR/$f" ] || { echo "File backup mancante: $f" >&2; exit 3; }
done

(
  cd "$BACKUP_DIR"
  sha256sum -c SHA256SUMS
)

gzip -t "$BACKUP_DIR/mysql.sql.gz"
tar -tzf "$BACKUP_DIR/runtime.tar.gz" >/tmp/meteonexa-runtime-list.$$
if grep -Eq '(^|/)\.\./|^/' /tmp/meteonexa-runtime-list.$$; then
  rm -f /tmp/meteonexa-runtime-list.$$
  echo 'ERRORE: archivio runtime contiene path non sicuri.' >&2
  exit 4
fi
rm -f /tmp/meteonexa-runtime-list.$$

TMP="$(mktemp -d)"
CID=""
cleanup() {
  [ -n "$CID" ] && docker rm -f "$CID" >/dev/null 2>&1 || true
  rm -rf "$TMP"
}
trap cleanup EXIT INT TERM

tar -xzf "$BACKUP_DIR/runtime.tar.gz" -C "$TMP"
RUNTIME_EXTRACTED="$(find "$TMP" -mindepth 1 -maxdepth 1 -type d -print -quit)"
[ -n "$RUNTIME_EXTRACTED" ] || { echo 'ERRORE: runtime non estraibile.' >&2; exit 5; }
[ -f "$RUNTIME_EXTRACTED/.app-secret" ] || { echo 'ERRORE: .app-secret assente dal backup runtime.' >&2; exit 5; }
[ -s "$RUNTIME_EXTRACTED/.app-secret" ] || { echo 'ERRORE: .app-secret vuota.' >&2; exit 5; }

TEST_ROOT="$(openssl rand -hex 18 2>/dev/null || python3 -c 'import secrets;print(secrets.token_hex(18))')"
CID="$(docker run -d --rm -e MYSQL_ROOT_PASSWORD="$TEST_ROOT" mysql:8.4)"
for _ in $(seq 1 60); do
  if docker exec "$CID" mysqladmin ping -h127.0.0.1 -uroot -p"$TEST_ROOT" --silent >/dev/null 2>&1; then break; fi
  sleep 2
docker inspect "$CID" >/dev/null 2>&1 || { echo 'ERRORE: MySQL restore container terminato.' >&2; exit 6; }
done

docker exec "$CID" mysqladmin ping -h127.0.0.1 -uroot -p"$TEST_ROOT" --silent >/dev/null 2>&1 || { echo 'ERRORE: MySQL 8.4 non pronto.' >&2; exit 6; }
gzip -dc "$BACKUP_DIR/mysql.sql.gz" | docker exec -i "$CID" sh -lc 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql -uroot'

DB_NAME="$(sed -n 's/^mysql_database=//p' "$BACKUP_DIR/metadata.txt" | head -1)"
[ -n "$DB_NAME" ] || DB_NAME=meteonexa
SCHEMA_SOURCE="$(sed -n 's/^schema_source=//p' "$BACKUP_DIR/metadata.txt" | head -1)"
[[ "$SCHEMA_SOURCE" =~ ^[0-9]+$ ]] || { echo "ERRORE: metadata backup senza schema_source valido: ${SCHEMA_SOURCE:-<vuoto>}" >&2; exit 7; }
SCHEMA="$(docker exec -e DB_NAME="$DB_NAME" "$CID" sh -lc 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; mysql -N -B -uroot "$DB_NAME" -e "SELECT meta_value FROM app_metadata WHERE meta_key=\"schema_version\" LIMIT 1"' 2>/dev/null || true)"
[ "$SCHEMA" = "$SCHEMA_SOURCE" ] || { echo "ERRORE: restore MySQL riuscito ma schema_version=$SCHEMA (sorgente backup $SCHEMA_SOURCE)." >&2; exit 7; }

printf '%s\n' 'RESTORE DRILL PASS: checksum, runtime extract e import MySQL 8.4 verificati su ambiente temporaneo.'
