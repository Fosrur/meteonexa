#!/usr/bin/env python3
from pathlib import Path
import json
import sys

ROOT = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parents[1]
errors = []

config = json.loads((ROOT / 'config/quality/architecture-10-10.json').read_text(encoding='utf-8'))
if config.get('legacy_ratchets'):
    errors.append('legacy architecture ratchets still configured')
expected_targets = {
    'js/app.js': 1000,
    'js/suite.js': 600,
    'js/app-components/*.js': 500,
    'modules/esm/**/*.mjs': 500,
    'api/**/*.php': 500,
}
for key, maximum in expected_targets.items():
    if int(config.get('final_targets', {}).get(key, -1)) != maximum:
        errors.append(f'final architecture target missing: {key} <= {maximum}')

radar = (ROOT / 'modules/esm/domains/radar-layers.mjs').read_text(encoding='utf-8')
for token in [
    "cloud: { variable: 'cloud_cover'",
    "wind_speed_10m,wind_direction_10m",
    "type === 'snow' && max <= 0",
    "setLegend(type, 'loading')",
    "setLegend(type, 'empty')",
    "setLegend(type, 'error'",
    "presentationLayer = `forecast-${type}`",
    "renderer: 'cloud'",
    "renderer: 'wind'",
    "renderer: 'snow'",
]:
    if token not in radar:
        errors.append(f'radar v2 contract missing: {token}')

package = json.loads((ROOT / 'package.json').read_text(encoding='utf-8'))
full = package.get('scripts', {}).get('check:full', '')
for script in ['check:radar-v2', 'check:architecture10', 'check:zero-comments', 'check:i18n-db', 'check:php-components', 'check:10-10']:
    if script != 'check:10-10' and script not in full:
        errors.append(f'check:full missing {script}')

quality = json.loads((ROOT / 'qa/quality-suite.json').read_text(encoding='utf-8'))
required_gates = {'radar-layer-v2', 'architecture-10-10', 'app-shell-components', 'zero-comments', 'i18n-db-source', 'php-component-size', 'roadmap-10-10-final'}
fast_ids = {item.get('id') for item in quality.get('modes', {}).get('fast', [])}
release_ids = fast_ids | {item.get('id') for item in quality.get('modes', {}).get('release', [])}
full_ids = {item.get('id') for item in quality.get('modes', {}).get('full', [])}
for mode, ids in [('fast', fast_ids), ('release', release_ids), ('full', full_ids)]:
    missing = sorted(required_gates - ids)
    if missing:
        errors.append(f'{mode} quality mode missing: {", ".join(missing)}')

workflow = (ROOT / '.github/workflows/deploy-production.yml').read_text(encoding='utf-8')
for token in ['workflow_run:', 'workflows: ["MeteoNexa QA"]', "github.event.workflow_run.conclusion == 'success'", "github.event.workflow_run.head_branch == 'main'"]:
    if token not in workflow:
        errors.append(f'production deploy gate missing: {token}')

translations = ROOT / 'api/install/meteonexa-baseline.sqlite'
if not translations.is_file():
    errors.append('translation baseline missing')

print('Roadmap 10/10 final: ' + ('PASS' if not errors else 'FAIL'))
for error in errors:
    print(' - ' + error)
sys.exit(bool(errors))
