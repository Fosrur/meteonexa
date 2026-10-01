#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MODE="${1:-fast}"
run(){ echo "==> $*"; "$@"; }
fast(){
  run php "$ROOT/qa/ai_meteorologist_v2_smoke.php"
  run php "$ROOT/qa/personal_weather_twin_v2_smoke.php"
  run php "$ROOT/qa/weather_observability_release_smoke.php"
  run python3 "$ROOT/qa/roadmap_complete_schema_smoke.py"
  run php "$ROOT/qa/official_warning_hub_smoke.php"
  run php "$ROOT/qa/radar4_operational_readiness_smoke.php"
  run python3 "$ROOT/qa/roadmap_contract_smoke.py"
  run node "$ROOT/tools/static-analysis.mjs" --syntax-only
}
release(){
  fast
  run python3 "$ROOT/qa/release_audit.py" "$ROOT"
  run python3 "$ROOT/qa/production_readiness_smoke.py" "$ROOT"
  run python3 "$ROOT/qa/automatic_deploy_contract_smoke.py"
  run python3 "$ROOT/qa/p2_release_quality_smoke.py" "$ROOT"
  run python3 "$ROOT/qa/release_final_smoke.py" "$ROOT"
  run bash -lc "cd '$ROOT' && sha256sum -c SHA256SUMS.txt"
}
case "$MODE" in
 fast) fast;;
 release) release;;
 full) exec bash "$ROOT/qa/run-all.sh";;
 *) echo "Usage: $0 [fast|release|full]" >&2; exit 2;;
esac
echo "Continuous quality $MODE PASS"
