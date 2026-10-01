#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Compatibility entry point. The authoritative full suite is catalogued in
# qa/quality-suite.json and executed gate-by-gate so CI reports every failure.
exec python3 "$ROOT/tools/quality-runner.py" full "$@"
