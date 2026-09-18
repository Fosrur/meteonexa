#!/usr/bin/env python3
from pathlib import Path
import json,re,sys
root=Path(__file__).resolve().parents[1]
rows=json.loads((root/'api/install/translations.json').read_text(encoding='utf-8'))['rows']
keys={r['text_key'] for r in rows}
refs={}
patterns=[
    re.compile(r'data-i18n-key=["\']([^"\']+)["\']'),
    re.compile(r'\b(?:meteonexaText|meteonexa_backend_text|ui|text|t)\(\s*["\']([^"\']+)["\']')
]
for path in root.rglob('*'):
    if not path.is_file() or 'dist' in path.parts or 'vendor' in path.parts or path.name=='translations.json':
        continue
    if path.suffix.lower() not in {'.html','.js','.mjs','.php'}:
        continue
    try: source=path.read_text(encoding='utf-8')
    except UnicodeDecodeError: continue
    for pattern in patterns:
        for match in pattern.finditer(source):
            key=match.group(1)
            if key.endswith('.'): # dynamic prefix, e.g. 'language.' + locale
                continue
            refs.setdefault(key,set()).add(str(path.relative_to(root)))
missing={k:sorted(v) for k,v in refs.items() if k not in keys}
locales={r['locale'] for r in rows}
by_key={}
for row in rows: by_key.setdefault(row['text_key'],set()).add(row['locale'])
incomplete={k:sorted(locales-v) for k,v in by_key.items() if v!=locales}
errors=[]
if missing: errors.extend([f'missing referenced key {k}: {", ".join(v)}' for k,v in sorted(missing.items())])
if incomplete: errors.extend([f'incomplete locales {k}: {", ".join(v)}' for k,v in sorted(incomplete.items())])
print(f'i18n references: {"PASS" if not errors else "FAIL"} — {len(refs)} referenced keys, {len(keys)} catalog keys, {len(locales)} locales')
for e in errors[:100]: print(' - '+e)
sys.exit(bool(errors))
