#!/usr/bin/env python3
from __future__ import annotations
import hashlib
import json
import sys
from pathlib import Path

ROOT=Path(sys.argv[1] if len(sys.argv)>1 else Path(__file__).resolve().parents[1]).resolve()

BUNDLES={
    'styles.css': ROOT/'styles/main',
    'suite.css': ROOT/'styles/suite',
}
expected_counts={'styles.css':11,'suite.css':7}

for bundle, folder in BUNDLES.items():
    assert folder.is_dir(), f'missing CSS source folder: {folder}'
    parts=sorted(folder.glob('*.css'))
    assert len(parts)==expected_counts[bundle], f'{bundle}: unexpected partial count {len(parts)}'
    names=[p.name for p in parts]
    assert names==sorted(names), f'{bundle}: partial ordering is not deterministic'
    rebuilt=''.join(p.read_text(encoding='utf-8') for p in parts)
    actual=(ROOT/bundle).read_text(encoding='utf-8')
    assert rebuilt==actual, f'{bundle}: generated aggregate differs from ordered partials'
    assert '@import' not in rebuilt, f'{bundle}: runtime @import would make cascade/network behavior less deterministic'

build=(ROOT/'tools/build_css.py').read_text(encoding='utf-8')
assert "'styles.css'" in build and "'suite.css'" in build, 'CSS builder must own both aggregate bundles'
fp=(ROOT/'tools/fingerprint_assets.py').read_text(encoding='utf-8')
assert 'build_css.py' in fp, 'asset fingerprint build must regenerate CSS first'

pkg=json.loads((ROOT/'package.json').read_text(encoding='utf-8'))
assert pkg['scripts'].get('build:css')=='python3 tools/build_css.py'
assert 'check:p4:css' in pkg['scripts'].get('check:full','')

readme=(ROOT/'METEONEXA-20.1-RC2.md').read_text(encoding='utf-8')
assert 'P4 fase 3' in readme and 'styles/main' in readme and 'styles/suite' in readme
arch=(ROOT/'METEONEXA-20.1-RC2.md').read_text(encoding='utf-8')
assert 'CSS source architecture' in arch

print('P4 CSS architecture: PASS')
