#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MODE="${1:-fast}"
shift || true
case "$MODE" in
  fast|release|full) ;;
  *) echo "Usage: $0 [fast|release|full] [quality-runner options]" >&2; exit 2;;
esac
exec python3 "$ROOT/tools/quality-runner.py" "$MODE" "$@"
