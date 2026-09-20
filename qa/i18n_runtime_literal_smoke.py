#!/usr/bin/env python3
from __future__ import annotations
from pathlib import Path
import re, sys
ROOT=Path(sys.argv[1]).resolve() if len(sys.argv)>1 else Path(__file__).resolve().parents[1]
FILES=[ROOT/'js/app.js',ROOT/'js/advanced.js',ROOT/'js/suite.js',ROOT/'js/maintenance.js',*sorted((ROOT/'modules/esm').rglob('*.mjs'))]
ALLOW={'MeteoNexa','METEONEXA','PDF','CSV','OK','AQI','UV','Radar','Netatmo','Open-Meteo','ECMWF','ICON','GFS','AIFS','UKMO'}
errors=[]
word=re.compile(r'[A-Za-zÀ-ÿ]{3,}')
# UI sinks where a direct literal would bypass the translation runtime.
patterns=[
 ('textContent', re.compile(r'\.textContent\s*=\s*(["\'])(.*?)\1',re.S)),
 ('innerText', re.compile(r'\.innerText\s*=\s*(["\'])(.*?)\1',re.S)),
 ('aria/title/placeholder', re.compile(r'\.setAttribute\(\s*(["\'])(?:aria-label|title|placeholder)\1\s*,\s*(["\'])(.*?)\2\s*\)',re.S)),
 ('property label', re.compile(r'\.(?:title|placeholder|ariaLabel)\s*=\s*(["\'])(.*?)\1',re.S)),
 ('toast/dialog', re.compile(r'\b(?:showToast|toast|alert|confirm)\s*\(\s*(["\'])(.*?)\1',re.S)),
]
def suspicious(text:str)->bool:
    clean=text.strip()
    if not clean or clean in ALLOW: return False
    if clean.startswith(('http://','https://','METEONEXA_','#','.', '/', '<')): return False
    if '${' in clean or 'meteonexaText' in clean or 'ui(' in clean: return False
    if re.fullmatch(r'[A-Z0-9_:\-./ +%°]+',clean): return False
    return bool(word.search(clean))
for path in FILES:
    src=path.read_text(encoding='utf-8')
    rel=path.relative_to(ROOT)
    for label,rx in patterns:
        for m in rx.finditer(src):
            literal=m.group(m.lastindex or 1)
            if suspicious(literal): errors.append(f'{rel}: {label} direct UI literal: {literal[:100]}')
    # Static visible text in HTML template literals assigned directly to UI sinks.
    html_templates=[]
    html_templates += [m.group(1) for m in re.finditer(r'\.innerHTML\s*=\s*`([\s\S]*?)`',src)]
    html_templates += [m.group(1) for m in re.finditer(r'insertAdjacentHTML\s*\(\s*["\'][^"\']+["\']\s*,\s*`([\s\S]*?)`\s*\)',src)]
    for tpl in html_templates:
        if 'meteonexaText(' in tpl or 'data-i18n-' in tpl or '${ui(' in tpl or '${t(' in tpl:
            continue
        visible=re.sub(r'<svg\b.*?</svg>',' ',tpl,flags=re.S|re.I)
        visible=re.sub(r'<[^>]+>',' ',visible)
        visible=re.sub(r'\$\{[^{}]*\}',' ',visible)
        visible=re.sub(r'&[a-zA-Z#0-9]+;',' ',visible)
        visible=' '.join(visible.split())
        if suspicious(visible): errors.append(f'{rel}: template visible literal: {visible[:100]}')
print('I18N runtime literal audit: '+('PASS' if not errors else 'FAIL'))
for e in errors[:120]: print(' - '+e)
sys.exit(bool(errors))
