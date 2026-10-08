#!/usr/bin/env python3
from __future__ import annotations
import json
import sys
from pathlib import Path

ROOT=Path(sys.argv[1] if len(sys.argv)>1 else Path(__file__).resolve().parents[1]).resolve()

BUNDLES={
    'css/styles.css': ROOT/'styles/main',
    'css/suite.css': ROOT/'styles/suite',
}

for bundle, folder in BUNDLES.items():
    assert folder.is_dir(), f'missing CSS source folder: {folder}'
    parts=sorted(folder.glob('*.css'))
    assert parts, f'{bundle}: no CSS partials found'
    names=[p.name for p in parts]
    assert names==sorted(names), f'{bundle}: partial ordering is not deterministic'
    assert len(names)==len(set(names)), f'{bundle}: duplicate partial name detected'
    rebuilt=''.join(p.read_text(encoding='utf-8') for p in parts)
    actual=(ROOT/bundle).read_text(encoding='utf-8')
    assert rebuilt==actual, f'{bundle}: generated aggregate differs from ordered partials'
    assert '@import' not in rebuilt, f'{bundle}: runtime @import would make cascade/network behavior less deterministic'

if (ROOT/'modules/esm/domains/radar-layers.mjs').is_file():
    assert (ROOT/'styles/suite/85-radar-layer-v2.css').is_file(), 'Radar Layer V2 CSS partial missing'

build=(ROOT/'tools/build_css.py').read_text(encoding='utf-8')
assert "'css/styles.css'" in build and "'css/suite.css'" in build, 'CSS builder must own both aggregate bundles'
fp=(ROOT/'tools/fingerprint_assets.py').read_text(encoding='utf-8')
assert 'build_css.py' in fp, 'asset fingerprint build must regenerate CSS first'

pkg=json.loads((ROOT/'package.json').read_text(encoding='utf-8'))
assert pkg['scripts'].get('build:css') in {'python3 tools/build_css.py','py tools/build_css.py','node tools/run-python.mjs tools/build_css.py'}
assert 'check:p4:css' in pkg['scripts'].get('check:full','')

readme=(ROOT/'readme.md').read_text(encoding='utf-8')
assert 'P4 fase 3' in readme and 'styles/main' in readme and 'styles/suite' in readme
assert 'CSS source architecture' in readme

print('P4 CSS architecture: PASS')
