#!/usr/bin/env python3
from pathlib import Path
import re
import sys

ROOT = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parents[1]
workflow_dir = ROOT / '.github' / 'workflows'
errors = []
uses_re = re.compile(r'^\s*-?\s*uses:\s*([^\s#]+)', re.MULTILINE)
sha_re = re.compile(r'^[0-9a-f]{40}$')

for path in sorted(workflow_dir.glob('*.yml')):
    text = path.read_text(encoding='utf-8')
    for spec in uses_re.findall(text):
        if '@' not in spec:
            errors.append(f'{path.name}: invalid uses spec {spec}')
            continue
        action, ref = spec.rsplit('@', 1)
        if not sha_re.fullmatch(ref):
            errors.append(f'{path.name}: mutable action ref {action}@{ref}')

if errors:
    print('Workflow supply-chain smoke: FAIL')
    for error in errors:
        print(' - ' + error)
    raise SystemExit(1)

print('Workflow supply-chain smoke: PASS (all uses refs are immutable 40-char SHAs)')
