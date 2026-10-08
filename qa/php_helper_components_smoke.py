#!/usr/bin/env python3
from pathlib import Path
import sys

ROOT = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parents[1]
errors = []
checked = []
for path in sorted((ROOT / 'api').rglob('*.php')):
    relative = path.relative_to(ROOT).as_posix()
    count = len(path.read_text(encoding='utf-8', errors='replace').splitlines())
    checked.append((relative, count))
    if count > 500:
        errors.append(f'{relative}: {count} lines exceeds 500')
print('PHP component size gate: ' + ('PASS' if not errors else 'FAIL') + f' ({len(checked)} files checked)')
for error in errors:
    print(' - ' + error)
sys.exit(bool(errors))
