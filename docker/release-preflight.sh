#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
ENV_FILE="${1:-.env}"
[ -f "$ENV_FILE" ] || { echo "Env file mancante: $ENV_FILE" >&2; exit 1; }
set -a; . "$ENV_FILE"; set +a
fail=0
need(){ eval "v=\${$1:-}"; if [ -z "$v" ]; then echo "[FAIL] $1 mancante" >&2; fail=1; else echo "[OK] $1"; fi; }
optional(){ eval "v=\${$1:-}"; if [ -z "$v" ]; then echo "[INFO] $1 non configurato (opzionale)"; else echo "[OK] $1"; fi; }
need MYSQL_DATABASE; need MYSQL_USER; need MYSQL_PASSWORD; need MYSQL_ROOT_PASSWORD
need METEONEXA_BASE_URL
need METEONEXA_SMTP_USERNAME; need METEONEXA_SMTP_PASSWORD; need METEONEXA_SMTP_FROM_EMAIL
optional METEONEXA_PIPELINE_CRON_SECRET; optional METEONEXA_PUSH_CRON_SECRET; optional METEONEXA_VAPID_SUBJECT
optional METEONEXA_LEGAL_CONTROLLER_NAME; optional METEONEXA_LEGAL_CONTROLLER_ADDRESS; optional METEONEXA_PRIVACY_CONTACT_EMAIL
status="${METEONEXA_DPO_STATUS:-not-appointed}"
case "$status" in appointed) need METEONEXA_DPO_EMAIL ;; not-appointed) echo '[OK] METEONEXA_DPO_STATUS=not-appointed (default consentito)' ;; *) echo '[FAIL] METEONEXA_DPO_STATUS deve essere appointed oppure not-appointed' >&2; fail=1 ;; esac
RUNTIME_DIR="${METEONEXA_RUNTIME_DIR:-./runtime}"
WEB_CONTAINER="${METEONEXA_WEB_CONTAINER_NAME:-meteonexa-web}"
if [ -s "$RUNTIME_DIR/.app-secret" ]; then
  echo '[OK] runtime/.app-secret'
elif docker exec "$WEB_CONTAINER" sh -lc 'test -s /var/lib/meteonexa/.app-secret' >/dev/null 2>&1; then
  echo "[OK] runtime/.app-secret (verified through $WEB_CONTAINER)"
else
  echo '[FAIL] runtime/.app-secret mancante o non verificabile' >&2
  fail=1
fi
[ -f api/install/meteonexa-baseline.sqlite ] && echo '[OK] baseline SQLite' || { echo '[FAIL] baseline SQLite mancante' >&2; fail=1; }
for lock in package-lock.json qa/package-lock.json composer.lock; do [ -s "$lock" ] && echo "[OK] $lock" || { echo "[FAIL] $lock mancante: eseguire tools/generate-lockfiles.sh" >&2; fail=1; }; done
for marker in OpenRouter OpenFreeMap BigDataCloud LibreWXR; do grep -q "$marker" api/install/translations.json && echo "[OK] disclosure $marker" || { echo "[FAIL] disclosure $marker mancante" >&2; fail=1; }; done
[ "$fail" -eq 0 ] || exit 1
echo 'Production release preflight PASS'
