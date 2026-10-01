#!/usr/bin/env python3
"""Reproducible frontend build wrapper.

Uses pinned esbuild when it is installed. In offline/source-only environments it
falls back to source-preserving ESM fingerprinting. The fallback is safe because
MeteoNexa ESM entrypoints have no static imports: bootstrap resolves every
fingerprinted module through the immutable asset manifest.
"""
from __future__ import annotations
import argparse, hashlib, json, os, shutil, subprocess, sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DIST = ROOT / 'dist'
BUILD = ROOT / '.build' / 'esbuild-production'
ATTESTATION = DIST / 'build-attestation.json'


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def tree_digest(paths: list[Path]) -> str:
    h = hashlib.sha256()
    for p in sorted(paths, key=lambda x: x.as_posix()):
        rel = p.relative_to(ROOT).as_posix().encode()
        h.update(len(rel).to_bytes(4, 'big')); h.update(rel)
        data = p.read_bytes(); h.update(len(data).to_bytes(8, 'big')); h.update(data)
    return h.hexdigest()


def source_files() -> list[Path]:
    files = []
    for base in ['modules/esm', 'js', 'css', 'styles', 'install', 'diagnostics', 'qa']:
        root = ROOT / base
        if not root.exists(): continue
        for p in root.rglob('*'):
            if p.is_file() and p.suffix.lower() in {'.mjs','.js','.css'}:
                files.append(p)
    for name in ['index.html','privacy.html','cookie-policy.html','offline.html','package.json','package-lock.json','tools/fingerprint_assets.py','tools/build_css.py','tools/esbuild-production.mjs']:
        p = ROOT / name
        if p.is_file(): files.append(p)
    return files


def esbuild_available() -> bool:
    proc = subprocess.run(['node','-e',"import('esbuild').then(x=>process.exit(x.version==='0.28.2'?0:2)).catch(()=>process.exit(1))"], cwd=ROOT)
    return proc.returncode == 0


def dist_digest() -> str:
    files = [p for p in DIST.rglob('*') if p.is_file() and p.name != 'build-attestation.json']
    files += [ROOT/'asset-manifest.json', ROOT/'js/asset-manifest.js']
    return tree_digest([p for p in files if p.is_file()])


def build_once(force_source: bool = False) -> dict:
    use_esbuild = (not force_source) and esbuild_available()
    if use_esbuild:
        subprocess.run(['node','tools/esbuild-production.mjs'], cwd=ROOT, check=True)
        mode = 'esbuild-0.28.2'
        args = ['python3','tools/fingerprint_assets.py','--require-esbuild']
    else:
        shutil.rmtree(BUILD, ignore_errors=True)
        mode = 'source-preserving-offline'
        args = ['python3','tools/fingerprint_assets.py']
    subprocess.run(args, cwd=ROOT, check=True)
    manifest = json.loads((ROOT/'asset-manifest.json').read_text(encoding='utf-8'))
    attestation = {
        'contractVersion': 1,
        'mode': mode,
        'esbuildPinnedVersion': '0.28.2',
        'sourceSha256': tree_digest(source_files()),
        'manifestSha256': sha256_bytes(json.dumps(manifest, sort_keys=True, separators=(',',':')).encode()),
        'distSha256': dist_digest(),
        'assetCount': len(manifest),
        'offlineFallbackPolicy': 'source-preserving-esm-no-static-imports',
    }
    ATTESTATION.write_text(json.dumps(attestation, indent=2, sort_keys=True) + '\n', encoding='utf-8')
    return attestation


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('--source-only', action='store_true', help='Force deterministic offline/source-preserving mode')
    ap.add_argument('--check-twice', action='store_true', help='Build twice and require byte-identical artifacts')
    ns = ap.parse_args()
    first = build_once(ns.source_only)
    if ns.check_twice:
        first_dist = first['distSha256']; first_manifest = first['manifestSha256']
        second = build_once(ns.source_only)
        if second['distSha256'] != first_dist or second['manifestSha256'] != first_manifest:
            print('Reproducible build: FAIL - consecutive builds differ', file=sys.stderr)
            return 1
        first = second
    print(f"Reproducible build: PASS ({first['mode']}, {first['assetCount']} assets, dist {first['distSha256'][:12]})")
    return 0

if __name__ == '__main__':
    raise SystemExit(main())
