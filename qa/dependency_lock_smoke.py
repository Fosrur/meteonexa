#!/usr/bin/env python3
from pathlib import Path
import hashlib, json, sys
ROOT=Path(__file__).resolve().parents[1]
def load(p): return json.loads((ROOT/p).read_text(encoding='utf-8'))
root=load('package-lock.json'); qa=load('qa/package-lock.json'); comp=load('composer.lock'); composer=load('composer.json')
# Composer's Locker::getContentHash contract.
relevant=['name','version','require','require-dev','conflict','replace','provide','minimum-stability','prefer-stable','repositories','extra']
data={k:composer[k] for k in relevant if k in composer}
if isinstance(composer.get('config'),dict) and 'platform' in composer['config']:
    data['config']={'platform':composer['config']['platform']}
encoded=json.dumps(dict(sorted(data.items())),ensure_ascii=False,separators=(',',':')).replace('/', r'\/')
content_hash=hashlib.md5(encoded.encode()).hexdigest()
root_pkgs=root.get('packages',{}); qa_pkgs=qa.get('packages',{}); dev={p.get('name'):p.get('version') for p in comp.get('packages-dev',[])}
checks={
 'root npm lock v3 is non-trivial and exact':root.get('lockfileVersion')==3 and len(root_pkgs)>=100 and root_pkgs.get('node_modules/esbuild',{}).get('version')=='0.28.2' and root_pkgs.get('node_modules/eslint',{}).get('version')=='10.10.0',
 'root npm lock contains registry integrity metadata':all(root_pkgs.get(k,{}).get('integrity') and root_pkgs.get(k,{}).get('resolved') for k in ('node_modules/esbuild','node_modules/eslint')),
 'qa Playwright lock is exact':qa_pkgs.get('node_modules/@playwright/test',{}).get('version')=='1.55.0' and qa_pkgs.get('node_modules/playwright',{}).get('version')=='1.55.0',
 'composer direct tools are exact':dev.get('friendsofphp/php-cs-fixer')=='v3.95.25' and dev.get('phpstan/phpstan')=='2.2.13',
 'composer lock content hash matches composer.json':comp.get('content-hash')==content_hash,
}
failed=[]
for name,ok in checks.items(): print(f"[{'OK' if ok else 'FAIL'}] {name}"); failed += ([] if ok else [name])
if failed: print('Dependency lock smoke FAILED: '+', '.join(failed),file=sys.stderr);sys.exit(1)
print('Dependency lock smoke PASS')
