#!/bin/sh
set -u

INTERVAL="${METEONEXA_WORKER_INTERVAL_SECONDS:-180}"
case "$INTERVAL" in ''|*[!0-9]*) INTERVAL=180 ;; esac
[ "$INTERVAL" -lt 60 ] && INTERVAL=60

# Do not mutate the source SQLite while the user is preparing the migration.
while true; do
  if php -r '$p="/var/lib/meteonexa/database.json"; $d=is_file($p)?json_decode((string)file_get_contents($p),true):null; exit(is_array($d)&&strtolower((string)($d["driver"]??""))==="mysql"?0:1);'; then
    break
  fi
  printf '[%s] MeteoNexa worker: attendo migrazione MySQL via /install/\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  sleep 30
done

while true; do
  printf '[%s] MeteoNexa pipeline\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  php /var/www/html/api/pipeline/worker.php || true
  printf '\n[%s] MeteoNexa push dispatch\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  php /var/www/html/api/push/dispatch.php || true
  printf '\n'
  sleep "$INTERVAL"
done
