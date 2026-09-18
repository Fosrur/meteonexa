#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
[ -f .env ] || { echo '.env mancante.' >&2; exit 1; }
# shellcheck disable=SC1091
set -a; . ./.env; set +a
printf '%s\n' '--- database.json runtime ---'
docker compose exec -T web php -r '$d=json_decode((string)file_get_contents("/var/lib/meteonexa/database.json"),true); if(!is_array($d)){fwrite(STDERR,"database.json non valido\n"); exit(1);} printf("driver=%s host=%s database=%s user=%s\n",$d["driver"]??"",$d["host"]??"",$d["database"]??"",$d["username"]??"");'
printf '%s\n' '--- metadata MySQL ---'
docker compose exec -T db mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "SELECT meta_key,meta_value FROM app_metadata WHERE meta_key IN ('app_version','schema_version','database_driver') ORDER BY meta_key;"
