#!/usr/bin/env python3
import json
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
failures = []

def require(cond, msg):
    if not cond:
        failures.append(msg)

catalog_path = ROOT / 'qa' / 'quality-suite.json'
runner_path = ROOT / 'tools' / 'quality-runner.py'
workflow_path = ROOT / '.github' / 'workflows' / 'meteonexa-tests.yml'
package = json.loads((ROOT / 'package.json').read_text())
catalog = json.loads(catalog_path.read_text())

require(catalog.get('version') == 1, 'quality catalog version must be 1')
ids = []
for mode in ('fast', 'release', 'full'):
    mode_ids = []
    require(mode in catalog.get('modes', {}), f'missing quality mode {mode}')
    for gate in catalog.get('modes', {}).get(mode, []):
        gid = gate.get('id')
        ids.append(gid)
        mode_ids.append(gid)
        require(bool(gid), f'{mode}: gate without id')
        require(isinstance(gate.get('command'), list) and gate['command'], f'{gid}: missing command')
        require(int(gate.get('timeout', 0)) > 0, f'{gid}: invalid timeout')
    require(len(mode_ids) == len(set(mode_ids)), f'{mode}: quality gate ids must be unique within the mode')
require(len(catalog.get('modes', {}).get('full', [])) >= 60, 'full quality catalog unexpectedly small')

scripts = package.get('scripts', {})
for mode in ('fast', 'release', 'full'):
    require(f'qa:{mode}' in scripts, f'package script qa:{mode} missing')

runner = runner_path.read_text()
for token in ('quality-report.json', 'quality-report.md', 'quality-junit.xml', 'quality-history.jsonl', '--baseline-report', 'gateStatuses', 'quality-trends.py', 'GITHUB_STEP_SUMMARY'):
    require(token in runner, f'quality runner missing {token}')

workflow = workflow_path.read_text()
for token in ('QUALITY_REPORT_DIR', 'quality-reports', 'quality-fetch-history.py', 'upload-artifact'):
    require(token in workflow, f'CI workflow missing quality evidence token {token}')

if failures:
    print('Continuous quality contract FAIL')
    for failure in failures:
        print(' -', failure)
    sys.exit(1)
print(f'Continuous quality contract PASS ({len(ids)} catalogued gates)')
