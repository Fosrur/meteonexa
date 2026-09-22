#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

EXPECTED_SHA="${1:-}"
ENV_FILE="${METEONEXA_ENV_FILE:-.env}"

command -v git >/dev/null || { echo 'ERRORE: git non disponibile.' >&2; exit 2; }
command -v docker >/dev/null || { echo 'ERRORE: docker non disponibile.' >&2; exit 2; }
docker compose version >/dev/null 2>&1 || { echo 'ERRORE: docker compose non disponibile.' >&2; exit 2; }
[ -f "$ENV_FILE" ] || { echo "ERRORE: $ENV_FILE mancante." >&2; exit 2; }

if [ -n "$(git status --porcelain)" ]; then
  echo 'ERRORE: working tree non pulita (modifiche tracked o file untracked non ignorati). Abort deploy.' >&2
  git status --short
  exit 3
fi

git fetch --prune origin main
REMOTE_SHA="$(git rev-parse origin/main)"
if [ -n "$EXPECTED_SHA" ] && [ "$REMOTE_SHA" != "$EXPECTED_SHA" ]; then
  echo "ERRORE: origin/main=$REMOTE_SHA, atteso=$EXPECTED_SHA" >&2
  exit 4
fi

git checkout main
git pull --ff-only origin main
DEPLOY_SHA="$(git rev-parse HEAD)"
[ "$DEPLOY_SHA" = "$REMOTE_SHA" ] || { echo 'ERRORE: HEAD non coincide con origin/main.' >&2; exit 4; }

echo "Deploy candidate: $DEPLOY_SHA"

# Production deploy is allowed only for a commit whose MeteoNexa QA workflow completed successfully.
bash docker/verify-ci-green.sh "$DEPLOY_SHA"

set -a
# shellcheck disable=SC1090
. "$ENV_FILE"
set +a

bash docker/release-preflight.sh "$ENV_FILE"
bash docker/prepare-runtime.sh "${METEONEXA_RUNTIME_DIR:-./runtime}"

# Prepare the candidate completely while production is still serving traffic.
# A build/backup/restore-drill failure must not put the public site in maintenance.
METEONEXA_BUILD_SHA="$DEPLOY_SHA" docker compose build --pull web worker

BACKUP_DIR="${METEONEXA_BACKUP_DIR:-./backups}/predeploy-$(date -u +%Y%m%dT%H%M%SZ)"
bash docker/backup-production.sh "$BACKUP_DIR"
bash docker/verify-backup-restore.sh "$BACKUP_DIR"

# Enter maintenance only for the actual container replacement and post-deploy checks.
# On failure after this point maintenance intentionally remains active until rollback/fix.
bash docker/maintenance-mode.sh on
MAINTENANCE_ACTIVE=1
trap 'echo "ERRORE: deploy interrotto con maintenance mode ancora ATTIVA. Dopo rollback/verifica: bash docker/maintenance-mode.sh off" >&2' ERR

docker compose up -d --remove-orphans

echo 'Attesa servizi production e migrazioni database...'
STATUS_URL='http://127.0.0.1/api/system/status.php'
for _ in $(seq 1 60); do
  if docker compose exec -T web curl -fsS "$STATUS_URL" >/dev/null 2>&1; then
    break
  fi
  sleep 2
done
STATUS_JSON="$(docker compose exec -T web curl -fsS "$STATUS_URL")"
printf 'Production status: %s\n' "$STATUS_JSON"

WEB_ID="$(docker compose ps -q web)"
WORKER_ID="$(docker compose ps -q worker)"
[ -n "$WEB_ID" ] && [ -n "$WORKER_ID" ] || { echo 'ERRORE: web/worker non attivi.' >&2; exit 5; }
[ "$(docker inspect -f '{{.Config.User}}' "$WEB_ID")" = '33:33' ] || { echo 'ERRORE: web non gira come 33:33.' >&2; exit 5; }
[ "$(docker inspect -f '{{.HostConfig.ReadonlyRootfs}}' "$WEB_ID")" = 'true' ] || { echo 'ERRORE: web rootfs non read-only.' >&2; exit 5; }
[ "$(docker inspect -f '{{.Config.User}}' "$WORKER_ID")" = '33:33' ] || { echo 'ERRORE: worker non gira come 33:33.' >&2; exit 5; }
[ "$(docker inspect -f '{{.HostConfig.ReadonlyRootfs}}' "$WORKER_ID")" = 'true' ] || { echo 'ERRORE: worker rootfs non read-only.' >&2; exit 5; }

WEB_SHA="$(docker inspect -f '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$WEB_ID")"
WORKER_SHA="$(docker inspect -f '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$WORKER_ID")"
[ "$WEB_SHA" = "$DEPLOY_SHA" ] || { echo "ERRORE: provenance web=$WEB_SHA, atteso=$DEPLOY_SHA." >&2; exit 6; }
[ "$WORKER_SHA" = "$DEPLOY_SHA" ] || { echo "ERRORE: provenance worker=$WORKER_SHA, atteso=$DEPLOY_SHA." >&2; exit 6; }
printf 'CONTAINER_PROVENANCE_PASS web=%s worker=%s expected=%s\n' "$WEB_SHA" "$WORKER_SHA" "$DEPLOY_SHA"

bash docker/verify-mysql.sh
docker compose ps

if [ "${METEONEXA_KEEP_MAINTENANCE:-0}" = "1" ]; then
  echo 'DEPLOY_INTERNAL_PASS: maintenance mode resta ATTIVA fino allo smoke live esterno.'
else
  bash docker/maintenance-mode.sh off
  MAINTENANCE_ACTIVE=0
fi
trap - ERR

echo "DEPLOY_FINAL_PASS sha=$DEPLOY_SHA backup=$BACKUP_DIR maintenance=${MAINTENANCE_ACTIVE:-0}"
