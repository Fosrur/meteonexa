#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
[ -f .env ] || { echo '.env mancante. Esegui ./docker/init-env.sh' >&2; exit 1; }
docker compose ps --status running web | grep -q . || { echo 'servizio web non attivo. Esegui docker compose up -d --build' >&2; exit 1; }
docker compose exec -T web test -f /var/lib/meteonexa/.app-secret || { echo 'Runtime .app-secret mancante nel container. Esegui ./docker/restore-runtime.sh prima di avviare lo stack.' >&2; exit 1; }
docker compose exec -T web test -f /var/lib/meteonexa/meteonexa.sqlite || { echo 'Runtime SQLite mancante nel container.' >&2; exit 1; }
# shellcheck disable=SC1091
set -a; . ./.env; set +a
: "${MYSQL_DATABASE:?}" "${MYSQL_USER:?}" "${MYSQL_PASSWORD:?}"
# Preflight MySQL: SQLite allows long, case-sensitive translation keys.  The
# source runtime contains keys longer than the legacy VARCHAR(191), and keys
# that differ only by case.  Keep enough room and binary key semantics before
# the native installer starts copying rows.  This is safe on a fresh DB and
# also repairs a target left by an older failed migration attempt.
docker compose exec -T db sh -lc '''
  mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" <<"SQL"
CREATE TABLE IF NOT EXISTS translations (
  locale VARCHAR(8) NOT NULL,
  text_key VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  translation MEDIUMTEXT NOT NULL,
  updated_at VARCHAR(40) NOT NULL,
  PRIMARY KEY(locale,text_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE translations
  MODIFY text_key VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
SQL
'''
KEY="$(openssl rand -hex 32)"
docker compose exec -T -e INSTALL_KEY="$KEY" web sh -c 'umask 077; printf "%s\n" "$INSTALL_KEY" > /var/www/html/install/ENABLE_INSTALL; chown www-data:www-data /var/www/html/install/ENABLE_INSTALL; chmod 600 /var/www/html/install/ENABLE_INSTALL'
docker compose exec -T \
  -e INSTALL_KEY="$KEY" -e DB_NAME="$MYSQL_DATABASE" -e DB_USER="$MYSQL_USER" -e DB_PASSWORD="$MYSQL_PASSWORD" \
  web sh -c '
    curl -fsS -o /tmp/meteonexa-install-result.html \
      -X POST http://127.0.0.1/install/ \
      --data-urlencode "install_key=$INSTALL_KEY" \
      --data-urlencode "host=db" \
      --data-urlencode "port=3306" \
      --data-urlencode "database=$DB_NAME" \
      --data-urlencode "username=$DB_USER" \
      --data-urlencode "password=$DB_PASSWORD"
  '
docker compose exec -T web rm -f /var/www/html/install/ENABLE_INSTALL 2>/dev/null || true
if ! docker compose exec -T web test -f /var/lib/meteonexa/database.json || ! docker compose exec -T web test -f /var/lib/meteonexa/install.lock; then
  echo 'Migrazione non completata. Controlla: docker logs meteonexa-web --tail 200' >&2
  exit 1
fi
DRIVER="$(docker compose exec -T web php -r '$d=json_decode((string)file_get_contents("/var/lib/meteonexa/database.json"),true); echo is_array($d)?strtolower((string)($d["driver"]??"")):"";' 2>/dev/null || true)"
[ "$DRIVER" = mysql ] || { echo 'database.json non indica driver mysql.' >&2; exit 1; }
rm -f .installer-key 2>/dev/null || true
printf 'Migrazione SQLite -> MySQL completata tramite installer nativo.\n'
