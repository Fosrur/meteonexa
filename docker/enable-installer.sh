#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
[ -f .env ] || { echo '.env mancante. Esegui ./docker/init-env.sh' >&2; exit 1; }
docker compose ps --status running web | grep -q . || { echo 'servizio web non attivo. Esegui docker compose up -d --build' >&2; exit 1; }
umask 077
KEY="$(openssl rand -hex 32)"
printf '%s\n' "$KEY" > .installer-key
docker compose exec -T web sh -c 'umask 077; cat > /var/www/html/install/ENABLE_INSTALL; chown www-data:www-data /var/www/html/install/ENABLE_INSTALL; chmod 600 /var/www/html/install/ENABLE_INSTALL' < .installer-key
printf '\nInstaller MeteoNexa abilitato.\n'
printf 'Percorso: /install/\n'
printf 'Chiave installer: %s\n' "$KEY"
printf 'MySQL host nel form: db\n'
printf 'MySQL porta: 3306\n'
printf 'Database/utente/password: vedi .env del deployment (non condividerlo).\n\n'
printf 'Dopo la migrazione l installer rimuove ENABLE_INSTALL da solo.\n'
