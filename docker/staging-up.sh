#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
ENV_FILE="${1:-.env.staging}"
[ -f "$ENV_FILE" ] || { echo "File staging non trovato: $ENV_FILE" >&2; exit 2; }

# Same production compose, isolated project/state. No second compose file is allowed.
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-meteonexa-staging}"
export METEONEXA_DB_CONTAINER_NAME="${METEONEXA_DB_CONTAINER_NAME:-meteonexa-staging-db}"
export METEONEXA_WEB_CONTAINER_NAME="${METEONEXA_WEB_CONTAINER_NAME:-meteonexa-staging-web}"
export METEONEXA_WORKER_CONTAINER_NAME="${METEONEXA_WORKER_CONTAINER_NAME:-meteonexa-staging-worker}"
export METEONEXA_RUNTIME_DIR="${METEONEXA_RUNTIME_DIR:-./runtime-staging}"

./docker/prepare-runtime.sh "$METEONEXA_RUNTIME_DIR"
docker compose --env-file "$ENV_FILE" up -d --build
printf 'Staging avviato con compose di produzione: project=%s runtime=%s\n' "$COMPOSE_PROJECT_NAME" "$METEONEXA_RUNTIME_DIR"
