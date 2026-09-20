#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

SHA="${1:-$(git rev-parse HEAD 2>/dev/null || true)}"
REPOSITORY="${METEONEXA_GITHUB_REPOSITORY:-${GITHUB_REPOSITORY:-}}"
TOKEN="${METEONEXA_GITHUB_TOKEN:-${GITHUB_TOKEN:-}}"
WORKFLOW_FILE="${METEONEXA_GITHUB_WORKFLOW_FILE:-meteonexa-tests.yml}"
API="${METEONEXA_GITHUB_API_URL:-https://api.github.com}"

[ -n "$SHA" ] || { echo 'ERRORE: SHA commit non disponibile per il controllo CI.' >&2; exit 2; }
[ -n "$REPOSITORY" ] || { echo 'ERRORE: METEONEXA_GITHUB_REPOSITORY non configurato (owner/repo).' >&2; exit 2; }
command -v curl >/dev/null || { echo 'ERRORE: curl non disponibile.' >&2; exit 2; }
command -v python3 >/dev/null || { echo 'ERRORE: python3 non disponibile.' >&2; exit 2; }

AUTH=()
if [ -n "$TOKEN" ]; then AUTH=(-H "Authorization: Bearer $TOKEN"); fi
URL="$API/repos/$REPOSITORY/actions/workflows/$WORKFLOW_FILE/runs?head_sha=$SHA&per_page=20"
JSON="$(curl -fsSL -H 'Accept: application/vnd.github+json' -H 'X-GitHub-Api-Version: 2022-11-28' "${AUTH[@]}" "$URL")" || {
  echo 'ERRORE: impossibile interrogare GitHub Actions per verificare la CI.' >&2
  exit 3
}

JSON_PAYLOAD="$JSON" python3 - "$SHA" "$WORKFLOW_FILE" <<'PY'
import json,os,sys
sha,workflow=sys.argv[1:3]
try: data=json.loads(os.environ.get('JSON_PAYLOAD',''))
except Exception as exc:
    raise SystemExit(f'ERRORE: risposta GitHub Actions non valida: {exc}')
runs=[r for r in data.get('workflow_runs',[]) if r.get('head_sha')==sha]
if not runs:
    raise SystemExit(f'ERRORE: nessuna esecuzione CI trovata per {sha} ({workflow}).')
runs.sort(key=lambda r:r.get('run_number',0), reverse=True)
run=runs[0]
status=run.get('status'); conclusion=run.get('conclusion')
if status!='completed' or conclusion!='success':
    raise SystemExit(f'ERRORE: CI non verde per {sha}: status={status}, conclusion={conclusion}, run={run.get("html_url","")}.')
print(f'CI_GREEN_PASS sha={sha} run={run.get("run_number")} workflow={workflow}')
PY
