from __future__ import annotations
import hashlib
from pathlib import Path
import sys

root = Path(__file__).resolve().parents[1]
manifest = root / 'SHA256SUMS.txt'
if not manifest.is_file():
    raise SystemExit('SHA256SUMS.txt non trovato')
failures = []
checked = 0
for raw in manifest.read_text(encoding='utf-8').splitlines():
    line = raw.strip()
    if not line:
        continue
    try:
        expected, relative = line.split(None, 1)
    except ValueError:
        failures.append(f'Riga checksum non valida: {line}')
        continue
    relative = relative.strip()
    if relative.startswith('*'):
        relative = relative[1:]
    if relative.startswith('./'):
        relative = relative[2:]
    path = root / relative
    if not path.is_file():
        failures.append(f'File mancante: {relative}')
        continue
    actual = hashlib.sha256(path.read_bytes()).hexdigest()
    checked += 1
    if actual.lower() != expected.lower():
        failures.append(f'Checksum non valido: {relative}')
if failures:
    print('Checksum verification: FAIL')
    for failure in failures[:100]:
        print(' - ' + failure)
    raise SystemExit(1)
print(f'Checksum verification: PASS ({checked} file)')
