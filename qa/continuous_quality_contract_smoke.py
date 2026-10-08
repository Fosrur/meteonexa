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
launcher_path = ROOT / 'tools' / 'run-quality.mjs'
python_launcher_path = ROOT / 'tools' / 'run-python.mjs'
checksum_path = ROOT / 'tools' / 'verify-checksums.py'
workflow_path = ROOT / '.github' / 'workflows' / 'meteonexa-tests.yml'
package = json.loads((ROOT / 'package.json').read_text(encoding='utf-8'))
catalog = json.loads(catalog_path.read_text(encoding='utf-8'))

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
    require('bash ' not in scripts.get(f'qa:{mode}', ''), f'package script qa:{mode} must be cross-platform')
    require('run-quality.mjs' in scripts.get(f'qa:{mode}', ''), f'package script qa:{mode} must use the cross-platform launcher')
require(launcher_path.is_file(), 'cross-platform quality launcher missing')
require(python_launcher_path.is_file(), 'cross-platform Python launcher missing')
require(checksum_path.is_file(), 'cross-platform checksum verifier missing')
require('lint:eslint' in scripts.get('qa:full', ''), 'qa:full must execute ESLint before the exhaustive suite')
require('check:ci-runtime' in scripts.get('qa:full', ''), 'qa:full must execute the local HTTP runtime parity gate')
require('check:ci-runtime' in scripts, 'check:ci-runtime package script missing')
for mode in ('fast', 'release', 'full'):
    for gate in catalog.get('modes', {}).get(mode, []):
        command = gate.get('command') or []
        require(not command or command[0] not in {'bash', 'sh', 'sha256sum'}, f"{mode}:{gate.get('id')}: non-portable command {command[0] if command else ''}")

runner = runner_path.read_text(encoding='utf-8')
for token in ('quality-report.json', 'quality-report.md', 'quality-junit.xml', 'quality-history.jsonl', '--baseline-report', 'gateStatuses', 'quality-trends.py', 'GITHUB_STEP_SUMMARY'):
    require(token in runner, f'quality runner missing {token}')

workflow = workflow_path.read_text(encoding='utf-8')
for token in ('QUALITY_REPORT_DIR', 'quality-reports', 'quality-fetch-history.py', 'upload-artifact'):
    require(token in workflow, f'CI workflow missing quality evidence token {token}')

if failures:
    print('Continuous quality contract FAIL')
    for failure in failures:
        print(' -', failure)
    sys.exit(1)
print(f'Continuous quality contract PASS ({len(ids)} catalogued gates)')
