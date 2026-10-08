#!/usr/bin/env python3
from __future__ import annotations
import json, re, subprocess, sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def fail(msg):
    print('Build reproducibility: FAIL - '+msg); raise SystemExit(1)


static_imports=[]
for module in (ROOT/'modules/esm').rglob('*.mjs'):
    source=module.read_text(encoding='utf-8')
    for match in re.finditer(r'^\s*import\s+(?:[^\n]*?\s+from\s+)?["\'](\.[^"\']+)["\']', source, re.MULTILINE):
        target=(module.parent/match.group(1)).resolve()
        if not target.is_file():
            fail(f'static import target missing: {module.relative_to(ROOT)} -> {match.group(1)}')
        static_imports.append((module,target))

package=json.loads((ROOT/'package.json').read_text(encoding='utf-8'))
if package.get('scripts',{}).get('build:production') != 'npm run build:esm && python3 tools/fingerprint_assets.py --require-esbuild':
    fail('production path must still require pinned esbuild')

subprocess.run(['python3','tools/reproducible-build.py','--check-twice'],cwd=ROOT,check=True)
att=ROOT/'dist/build-attestation.json'
if not att.is_file(): fail('missing build attestation')
data=json.loads(att.read_text(encoding='utf-8'))
if data.get('mode') not in {'esbuild-0.28.2','source-preserving-offline'}: fail('unexpected build mode')
if data.get('esbuildPinnedVersion')!='0.28.2': fail('pinned esbuild version drift')
if data.get('mode')=='source-preserving-offline':
    for _,target in static_imports:
        sidecar=ROOT/'dist'/target.relative_to(ROOT)
        if not sidecar.is_file(): fail(f'offline static-import sidecar missing: {target.relative_to(ROOT)}')
if int(data.get('assetCount') or 0)<60: fail('asset count unexpectedly low')
if len(str(data.get('sourceSha256') or ''))!=64 or len(str(data.get('distSha256') or ''))!=64: fail('invalid attestation digests')
subprocess.run(['python3','qa/asset_contract.py'],cwd=ROOT,check=True,stdout=subprocess.DEVNULL)
print(f"Build reproducibility: PASS ({data['mode']}; deterministic two-pass build; asset contract green)")
