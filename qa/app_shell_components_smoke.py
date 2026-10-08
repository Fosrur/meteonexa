#!/usr/bin/env python3
from pathlib import Path
import re
import sys

ROOT = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parents[1]
errors = []
app = ROOT / 'js/app.js'
parts = sorted((ROOT / 'js/app-components').glob('app-functions-*.js'))
bootstrap = (ROOT / 'modules/esm/bootstrap.mjs').read_text(encoding='utf-8')
if not app.is_file():
    errors.append('js/app.js missing')
else:
    count = len(app.read_text(encoding='utf-8').splitlines())
    if count > 1000:
        errors.append(f'js/app.js has {count} lines')
if len(parts) < 2:
    errors.append('app shell was not decomposed')
for path in parts:
    count = len(path.read_text(encoding='utf-8').splitlines())
    if count > 500:
        errors.append(f'{path.relative_to(ROOT).as_posix()} has {count} lines')
    if re.search(r'^\s*(?:const|let|var)\s+(?:CONFIG|SERVICES|state|STORAGE)\b', path.read_text(encoding='utf-8'), re.M):
        errors.append(f'{path.relative_to(ROOT).as_posix()} owns app state instead of functions')
for path in parts:
    logical = path.relative_to(ROOT).as_posix()
    if f"'{logical}'" not in bootstrap:
        errors.append(f'bootstrap missing {logical}')
load_parts = "for (const logical of APP_FUNCTION_SCRIPTS) await loadClassic(logical);"
load_app = "await loadClassic('js/app.js');"
if load_parts not in bootstrap or load_app not in bootstrap or bootstrap.index(load_parts) > bootstrap.index(load_app):
    errors.append('app function components must load before js/app.js')
print('App shell components: ' + ('PASS' if not errors else 'FAIL'))
if not errors:
    print(f' app={len(app.read_text(encoding="utf-8").splitlines())} parts={len(parts)} max_part={max(len(p.read_text(encoding="utf-8").splitlines()) for p in parts)}')
for error in errors:
    print(' - ' + error)
sys.exit(bool(errors))
