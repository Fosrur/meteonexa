#!/usr/bin/env python3
from __future__ import annotations
import json, re, subprocess, sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def fail(msg):
    print('Build reproducibility: FAIL - '+msg); raise SystemExit(1)


# Offline fallback is valid only while ESM entrypoints avoid static imports.
for module in (ROOT/'modules/esm').rglob('*.mjs'):
    text=module.read_text(encoding='utf-8')
    if re.search(r'^\s*(?:import\s+(?!\()|export\s+.*\sfrom\s+)', text, re.MULTILINE):
        fail(f'offline source-preserving fallback unsafe: static import in {module.relative_to(ROOT)}')
package=json.loads((ROOT/'package.json').read_text(encoding='utf-8'))
if package.get('scripts',{}).get('build:production') != 'npm run build:esm && python3 tools/fingerprint_assets.py --require-esbuild':
    fail('production path must still require pinned esbuild')

subprocess.run(['python3','tools/reproducible-build.py','--check-twice'],cwd=ROOT,check=True)
att=ROOT/'dist/build-attestation.json'
if not att.is_file(): fail('missing build attestation')
data=json.loads(att.read_text(encoding='utf-8'))
if data.get('mode') not in {'esbuild-0.28.2','source-preserving-offline'}: fail('unexpected build mode')
if data.get('esbuildPinnedVersion')!='0.28.2': fail('pinned esbuild version drift')
if int(data.get('assetCount') or 0)<60: fail('asset count unexpectedly low')
if len(str(data.get('sourceSha256') or ''))!=64 or len(str(data.get('distSha256') or ''))!=64: fail('invalid attestation digests')
subprocess.run(['python3','qa/asset_contract.py'],cwd=ROOT,check=True,stdout=subprocess.DEVNULL)
print(f"Build reproducibility: PASS ({data['mode']}; deterministic two-pass build; asset contract green)")
