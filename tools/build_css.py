#!/usr/bin/env python3
"""Build MeteoNexa aggregate CSS from ordered maintainable partials.

The order is a public rendering contract: partials are concatenated byte-for-byte
without reordering or minification so CSS cascade/specificity remain unchanged.
"""
from __future__ import annotations
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BUNDLES = {
    ROOT / 'css/styles.css': ROOT / 'styles' / 'main',
    ROOT / 'css/suite.css': ROOT / 'styles' / 'suite',
}


def ordered_parts(folder: Path) -> list[Path]:
    parts = sorted(folder.glob('*.css'))
    if not parts:
        raise SystemExit(f'no CSS partials found in {folder.relative_to(ROOT)}')
    return parts


def build(output: Path, folder: Path) -> tuple[int, int]:
    parts = ordered_parts(folder)
    payload = ''.join(p.read_text(encoding='utf-8') for p in parts)
    output.write_text(payload, encoding='utf-8')
    return len(parts), payload.count('\n')


def main() -> None:
    for output, folder in BUNDLES.items():
        count, lines = build(output, folder)
        print(f'{output.name}: {count} partials, {lines} lines')


if __name__ == '__main__':
    main()
