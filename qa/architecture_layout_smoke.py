#!/usr/bin/env python3
from pathlib import Path
import sys
ROOT=Path(sys.argv[1]).resolve() if len(sys.argv)>1 else Path(__file__).resolve().parents[1]
errors=[]
root_files=[p for p in ROOT.iterdir() if p.is_file()]
for p in root_files:
    if p.suffix.lower() in {'.js','.css','.ico'} or (p.suffix.lower()=='.php' and p.name not in {'config/quality/php-cs-fixer.php'}):
        errors.append(f'root runtime/source file forbidden: {p.name}')
required=[
    ROOT/'js/app.js', ROOT/'js/sw.js', ROOT/'js/asset-manifest.js',
    ROOT/'css/styles.css', ROOT/'css/suite.css',
    ROOT/'modules/esm/bootstrap.mjs', ROOT/'styles/main', ROOT/'styles/suite',
    ROOT/'api/public-error.php', ROOT/'api/vendor-asset.php',
    ROOT/'config/quality/php-cs-fixer.php', ROOT/'config/quality/phpstan.neon',
    ROOT/'config/quality/phpstan-p6.neon', ROOT/'config/quality/semgrep.yml',
    ROOT/'docs/reports/ARCHITECTURE-SECURITY.md',
]
for p in required:
    if not p.exists(): errors.append(f'missing structured path: {p.relative_to(ROOT)}')
print('Architecture layout: '+('PASS' if not errors else 'FAIL'))
for e in errors: print(' - '+e)
sys.exit(bool(errors))
