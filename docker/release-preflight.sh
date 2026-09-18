#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
ENV_FILE="${1:-.env}"
[ -f "$ENV_FILE" ] || { echo "Env file mancante: $ENV_FILE" >&2; exit 1; }
set -a; . "$ENV_FILE"; set +a
fail=0
need(){ eval "v=\${$1:-}"; if [ -z "$v" ]; then echo "[FAIL] $1 mancante" >&2; fail=1; else echo "[OK] $1"; fi; }
need MYSQL_DATABASE; need MYSQL_USER; need MYSQL_PASSWORD; need MYSQL_ROOT_PASSWORD
need METEONEXA_BASE_URL
need METEONEXA_SMTP_USERNAME; need METEONEXA_SMTP_PASSWORD; need METEONEXA_SMTP_FROM_EMAIL
need METEONEXA_LEGAL_CONTROLLER_NAME; need METEONEXA_LEGAL_CONTROLLER_ADDRESS; need METEONEXA_PRIVACY_CONTACT_EMAIL
status="${METEONEXA_DPO_STATUS:-}"
case "$status" in appointed) need METEONEXA_DPO_EMAIL ;; not-appointed) echo '[OK] METEONEXA_DPO_STATUS=not-appointed' ;; *) echo '[FAIL] METEONEXA_DPO_STATUS deve essere appointed oppure not-appointed' >&2; fail=1 ;; esac
[ -f runtime/.app-secret ] && echo '[OK] runtime/.app-secret' || { echo '[FAIL] runtime/.app-secret mancante' >&2; fail=1; }
[ -f api/install/meteonexa-baseline.sqlite ] && echo '[OK] baseline SQLite' || { echo '[FAIL] baseline SQLite mancante' >&2; fail=1; }
for lock in package-lock.json qa/package-lock.json composer.lock; do [ -s "$lock" ] && echo "[OK] $lock" || { echo "[FAIL] $lock mancante: eseguire tools/generate-lockfiles.sh" >&2; fail=1; }; done
for marker in OpenRouter OpenFreeMap BigDataCloud LibreWXR; do grep -q "$marker" api/install/translations.json && echo "[OK] disclosure $marker" || { echo "[FAIL] disclosure $marker mancante" >&2; fail=1; }; done
[ "$fail" -eq 0 ] || exit 1
echo 'Production release preflight PASS'
