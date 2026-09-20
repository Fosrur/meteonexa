#!/usr/bin/env python3
from __future__ import annotations
import json, sys
from pathlib import Path

ROOT = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parents[1]

def fail(message: str) -> None:
    raise SystemExit(f'P4 production build: FAIL - {message}')

package = json.loads((ROOT/'package.json').read_text(encoding='utf-8'))
scripts = package.get('scripts', {})
if scripts.get('build:esm') != 'node tools/esbuild-production.mjs':
    fail('build:esm does not use the production esbuild builder')
if '--require-esbuild' not in scripts.get('build:production',''):
    fail('build:production does not require esbuild output before fingerprinting')
if scripts.get('build') != 'npm run check && npm run build:production':
    fail('default build is not the production build path')
if package.get('devDependencies', {}).get('esbuild') != '0.28.2':
    fail('esbuild must remain exactly pinned')

builder = (ROOT/'tools/esbuild-production.mjs').read_text(encoding='utf-8')
for needle in ["bundle: true", "format: 'esm'", "target: ['es2022']", "minify: true", "outExtension: { '.js': '.mjs' }"]:
    if needle not in builder:
        fail(f'esbuild production contract missing: {needle}')

fingerprint = (ROOT/'tools/fingerprint_assets.py').read_text(encoding='utf-8')
for needle in ["ESBUILD_PROD=ROOT/'.build/esbuild-production'", "METEONEXA_REQUIRE_ESBUILD", "built=ESBUILD_PROD/logical"]:
    if needle not in fingerprint:
        fail(f'fingerprint pipeline does not consume esbuild output: {needle}')

docker = (ROOT/'Dockerfile').read_text(encoding='utf-8')
for needle in ['FROM node:20-bookworm-slim AS frontend-build','RUN npm run build:production','COPY --from=frontend-build /src/dist /var/www/html/dist']:
    if needle not in docker:
        fail(f'Docker production build contract missing: {needle}')

workflow = (ROOT/'.github/workflows/meteonexa-tests.yml').read_text(encoding='utf-8')
if 'npm run build:production' not in workflow:
    fail('CI does not execute the production frontend build')

print('P4 production build: PASS (esbuild is the release ESM builder; Docker/CI use build:production)')
