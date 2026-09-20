#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
MODE="${1:-status}"
ENV_FILE="${METEONEXA_ENV_FILE:-.env}"
[ -f "$ENV_FILE" ] && { set -a; . "$ENV_FILE"; set +a; }
RUNTIME_DIR="${METEONEXA_RUNTIME_DIR:-./runtime}"
FLAG="$RUNTIME_DIR/maintenance.flag"
container_write() {
  local action="$1"
  local web_id
  web_id="$(docker compose ps -q web 2>/dev/null || true)"
  [ -n "$web_id" ] || return 1
  if [ "$action" = on ]; then docker compose exec -T web sh -c 'umask 007; : > /var/lib/meteonexa/maintenance.flag';
  else docker compose exec -T web rm -f /var/lib/meteonexa/maintenance.flag; fi
}
host_write() {
  mkdir -p "$RUNTIME_DIR" 2>/dev/null || true
  if [ "$MODE" = on ]; then
    if : > "$FLAG" 2>/dev/null; then chmod 0660 "$FLAG" 2>/dev/null || true; return 0; fi
    command -v sudo >/dev/null && sudo -n sh -c "umask 007; : > '$FLAG'; chown 33:33 '$FLAG'; chmod 0660 '$FLAG'" && return 0
  else
    if rm -f "$FLAG" 2>/dev/null; then return 0; fi
    command -v sudo >/dev/null && sudo -n rm -f "$FLAG" && return 0
  fi
  return 1
}
case "$MODE" in
  on) container_write on || host_write; echo "MAINTENANCE_ON flag=$FLAG" ;;
  off) container_write off || host_write; echo "MAINTENANCE_OFF flag=$FLAG" ;;
  status) if [ -f "$FLAG" ]; then echo "MAINTENANCE_ON flag=$FLAG"; exit 0; else echo "MAINTENANCE_OFF flag=$FLAG"; exit 0; fi ;;
  *) echo "Uso: $0 {on|off|status}" >&2; exit 2 ;;
esac
