#!/usr/bin/env python3
from pathlib import Path
import json
import sys

ROOT = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parents[1]
config = json.loads((ROOT / 'config/quality/architecture-10-10.json').read_text(encoding='utf-8'))
errors = []

def lines(path):
    return len(path.read_text(encoding='utf-8', errors='replace').splitlines())

def check(path, maximum, label=None):
    if not path.is_file():
        errors.append(f'missing architecture file: {path.relative_to(ROOT).as_posix()}')
        return
    count = lines(path)
    if count > maximum:
        errors.append(f'{label or path.relative_to(ROOT).as_posix()}: {count} lines exceeds {maximum}')

check(ROOT / 'js/app.js', 1000)
check(ROOT / 'js/suite.js', 600)

app_components = sorted((ROOT / 'js/app-components').glob('*.js'))
if not app_components:
    errors.append('js/app-components: missing app shell components')
for path in app_components:
    check(path, 500)

modules = sorted((ROOT / 'modules/esm').rglob('*.mjs'))
if not modules:
    errors.append('modules/esm: missing modules')
for path in modules:
    check(path, 500)

php_candidates = sorted((ROOT / 'api').rglob('*.php'))
for path in php_candidates:
    check(path, 500)

legacy = config.get('legacy_ratchets')
if legacy:
    errors.append('legacy architecture ratchets are forbidden in final 10/10 gate')

print('Architecture 10/10 final: ' + ('PASS' if not errors else 'FAIL'))
print(f' app={lines(ROOT / "js/app.js")} suite={lines(ROOT / "js/suite.js")} modules={len(modules)} php_files={len(php_candidates)}')
for error in errors:
    print(' - ' + error)
sys.exit(bool(errors))
