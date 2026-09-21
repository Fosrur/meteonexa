#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
[ -f .env ] || { echo '.env mancante.' >&2; exit 1; }
# shellcheck disable=SC1091
set -a; . ./.env; set +a

printf '%s\n' '--- database.json runtime ---'
docker compose exec -T web php -r '$d=json_decode((string)file_get_contents("/var/lib/meteonexa/database.json"),true); if(!is_array($d)){fwrite(STDERR,"database.json non valido\n"); exit(1);} printf("driver=%s host=%s database=%s user=%s\n",$d["driver"]??"",$d["host"]??"",$d["database"]??"",$d["username"]??"");'

EXPECTED_SCHEMA="$(docker compose exec -T web php -r 'require_once "/var/www/html/api/database/migrations.php"; echo meteonexa_current_schema_version();' | tr -d '\r\n')"
ACTUAL_SCHEMA="$(docker compose exec -T db mysql -N -B -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "SELECT meta_value FROM app_metadata WHERE meta_key='schema_version' LIMIT 1" | tr -d '\r\n')"

case "$EXPECTED_SCHEMA" in ''|*[!0-9]*) echo "ERRORE: schema atteso non valido: ${EXPECTED_SCHEMA:-<vuoto>}" >&2; exit 2;; esac
case "$ACTUAL_SCHEMA" in ''|*[!0-9]*) echo "ERRORE: schema MySQL non valido: ${ACTUAL_SCHEMA:-<vuoto>}" >&2; exit 2;; esac

printf '%s\n' '--- metadata MySQL ---'
docker compose exec -T db mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "SELECT meta_key,meta_value FROM app_metadata WHERE meta_key IN ('app_version','schema_version','database_driver') ORDER BY meta_key;"

[ "$ACTUAL_SCHEMA" = "$EXPECTED_SCHEMA" ] || {
  echo "ERRORE: schema MySQL=$ACTUAL_SCHEMA, atteso dall'app=$EXPECTED_SCHEMA." >&2
  exit 3
}
printf 'MYSQL_SCHEMA_PASS actual=%s expected=%s\n' "$ACTUAL_SCHEMA" "$EXPECTED_SCHEMA"
