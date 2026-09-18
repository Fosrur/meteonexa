#!/usr/bin/env python3
from pathlib import Path
import re,sys
root=Path(__file__).resolve().parents[1]
# README is intentionally the only release-history surface.
patterns=[
 re.compile(r'(?i)\bMeteoNexa\s*20\.0\b'),
 re.compile(r'(?i)(?:APP_BUILD|\bBUILD|\bVERSION|POLICY_VERSION|app_version|translation_seed_version)[^\n]{0,70}["\']20\.0'),
 re.compile(r'(?i)[?&]v=20\.0\b'),
 re.compile(r'(?i)\b(?:MeteoNexa|version|versione|versión|release|build|policy|seed)\s*[:=—-]?\s*["\']?\s*(?:17|18|19)\.\d+(?:\.\d+)?'),
 re.compile(r'(?i)(?:APP_BUILD|\bBUILD|\bVERSION|POLICY_VERSION|app_version|translation_seed_version)[^\n]{0,50}["\'](?:17|18|19)\.\d+'),
 re.compile(r'(?i)(?:release[_-]?|_v|\bv)(?:17|18|19)\d+'),
 re.compile(r'(?i)MeteoNexa[A-Za-z_]*(?:17|18|19)\d+'),
 re.compile(r'(?i)[?&]v=(?:17|18|19)\.\d+'),
 # Old release comments are also forbidden outside README.
 re.compile(r'(?i)(?:/\*|//|#)\s*(?:MeteoNexa\s*)?(?:17|18|19)\.\d+(?:\.\d+)?'),
]
ignore_ext={'.png','.jpg','.jpeg','.ico','.webp','.woff','.woff2','.sqlite','.zip'}
errors=[]
for p in root.rglob('*'):
 if not p.is_file() or p.name=='METEONEXA-20.1-RC2.md' or p.suffix.lower() in ignore_ext or 'dist' in p.parts: continue
 try:s=p.read_text(encoding='utf-8')
 except Exception:continue
 for n,line in enumerate(s.splitlines(),1):
  if any(rx.search(line) for rx in patterns): errors.append(f'{p.relative_to(root)}:{n}: {line[:180]}')
for p in root.rglob('*'):
 if p.is_file() and p.name!='METEONEXA-20.1-RC2.md' and re.search(r'(?i)(?:release[_-]?(?:17|18|19)\d|_(?:17|18|19)\d{3,}|v(?:17|18|19)\d{2,})',p.name): errors.append('filename: '+str(p.relative_to(root)))
print('Legacy release reference scan: '+('PASS' if not errors else 'FAIL'))
for e in errors[:120]: print(' - '+e)
if len(errors)>120: print(f' - ... {len(errors)-120} more')
sys.exit(bool(errors))
