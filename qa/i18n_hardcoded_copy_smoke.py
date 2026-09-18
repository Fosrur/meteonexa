#!/usr/bin/env python3
from __future__ import annotations
from html.parser import HTMLParser
from pathlib import Path
import re, sys

ROOT = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parents[1]
errors=[]
ALLOW_TEXT={'MeteoNexa','MeteoNexa 20.1','Meteo','Nexa','v20.1','meteonexa.com','km/h','°C','km','--','—','×','•','·'}

class VisibleTextAudit(HTMLParser):
    def __init__(self, name: str):
        super().__init__(convert_charrefs=True)
        self.name=name; self.stack=[]; self.skip=0; self.i18n_skip=0
    def handle_starttag(self, tag, attrs):
        attrs=dict(attrs); localized=bool(attrs.get('data-i18n-key') or attrs.get('data-i18n-attr')); explicit_skip='data-i18n-skip' in attrs
        parent_localized=self.stack[-1][1] if self.stack else False
        effective=localized or parent_localized
        self.stack.append((tag,effective,explicit_skip))
        if explicit_skip: self.i18n_skip += 1
        if tag in {'script','style','svg','symbol'}: self.skip += 1
    def handle_startendtag(self, tag, attrs):
        pass
    def handle_endtag(self, tag):
        if tag in {'script','style','svg','symbol'} and self.skip: self.skip -= 1
        if self.stack:
            # tolerate imperfect HTML by dropping back to the matching tag when possible
            for i in range(len(self.stack)-1,-1,-1):
                if self.stack[i][0] == tag:
                    removed=self.stack[i:]
                    self.i18n_skip=max(0,self.i18n_skip-sum(1 for item in removed if item[2]))
                    del self.stack[i:]; break
    def handle_data(self, data):
        if self.skip: return
        txt=' '.join(data.split())
        if not txt or len(txt)<2: return
        if self.i18n_skip: return
        if self.stack and self.stack[-1][1]: return
        if txt in ALLOW_TEXT: return
        if re.fullmatch(r'[\d\s%°+\-–—/.:,()]+', txt): return
        if txt.lower() == 'html': return
        errors.append(f'{self.name}: visible literal without i18n key: {txt[:120]}')

for rel in ['index.html','privacy.html','cookie-policy.html','offline.html']:
    parser=VisibleTextAudit(rel); parser.feed((ROOT/rel).read_text(encoding='utf-8'))

ui_files=[ROOT/'app.js',ROOT/'advanced.js',ROOT/'suite.js',*sorted((ROOT/'modules/esm').rglob('*.mjs'))]
patterns=[
    re.compile(r'\.textContent\s*=\s*(["\'])([^"\'\n]*[A-Za-zÀ-ÿ][^"\'\n]*)\1'),
    re.compile(r'\.innerHTML\s*=\s*(["\'])([^"\'\n]*[A-Za-zÀ-ÿ][^"\'\n]*)\1'),
    re.compile(r'\b(?:showToast|alert|confirm)\s*\(\s*(["\'])([^"\'\n]*[A-Za-zÀ-ÿ][^"\'\n]*)\1'),
]
for path in ui_files:
    src=path.read_text(encoding='utf-8')
    for pattern in patterns:
        for match in pattern.finditer(src):
            literal=match.group(2).strip()
            if not literal or literal.startswith(('<','http','METEONEXA_')): continue
            if re.fullmatch(r'[A-Z0-9_:\-./ ]+',literal): continue
            errors.append(f'{path.relative_to(ROOT)}: direct UI literal: {literal[:120]}')

# No active hash-like i18n references may survive in runtime sources.
hash_key=re.compile(r'\b(?:ui|code|html|attr|meta)\.[a-f0-9]{10,}\b',re.I)
for path in [*ui_files, ROOT/'privacy.html',ROOT/'cookie-policy.html',ROOT/'offline.html',ROOT/'index.html']:
    src=path.read_text(encoding='utf-8')
    for match in hash_key.finditer(src):
        errors.append(f'{path.relative_to(ROOT)}: hash-like i18n key: {match.group(0)}')

print('I18N hardcoded copy: '+('PASS' if not errors else 'FAIL'))
for error in errors[:100]: print(' - '+error)
sys.exit(bool(errors))
