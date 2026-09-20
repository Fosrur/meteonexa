#!/usr/bin/env python3
"""P0 security refactor regression contract."""
from pathlib import Path
import re, sys
root=Path(sys.argv[1]).resolve() if len(sys.argv)>1 else Path(__file__).resolve().parents[1]
read=lambda p:(root/p).read_text(encoding='utf-8',errors='replace')
config=read('api/config.php')
diag=read('api/diagnostics_access.php')
db='\n'.join(path.read_text(encoding='utf-8',errors='replace') for path in sorted((root/'api/database').glob('*.php')))
vendor=read('api/vendor-asset.php')
env=read('.env.example')
installer=read('install/index.php')
checks={
    'QA admin allowlist is deployment-owned': "getenv('METEONEXA_QA_ADMIN_EMAILS')" in config and "'admin_emails'" in config,
    'no package bootstrap administrator source': 'bootstrap_admin_emails' not in config+diag+db,
    'SMTP cannot authorize diagnostics': "config['smtp']" not in diag and "['username','from_email']" not in diag,
    'QA env is documented': 'METEONEXA_QA_ADMIN_EMAILS=' in env,
    'MapLibre npm package is version pinned': 'maplibre-gl-5.24.0.tgz' in vendor,
    'MapLibre package has fixed SHA-512 integrity': bool(re.search(r"'integrity'\s*=>\s*'sha512-[A-Za-z0-9+/=]+'", vendor)),
    'MapLibre executable CDN URL removed': 'cdnjs.cloudflare.com/ajax/libs/maplibre-gl' not in vendor,
    'MapLibre JS comes from exact package entry': "package/dist/maplibre-gl.js" in vendor,
    'MapLibre CSS comes from exact package entry': "package/dist/maplibre-gl.css" in vendor,
    'pre-hardening cache names are not reused': 'npm-pinned.js' in vendor and 'npm-pinned.css' in vendor,
    'package integrity is checked before cache write': '$integrityMatches($packageBody' in vendor and 'VENDOR_PACKAGE_INTEGRITY_FAILED' in vendor,
    'installer checks Phar/zlib runtime support': "class_exists('PharData')" in installer and "extension_loaded('zlib')" in installer,
}
failed=[]
for name,ok in checks.items():
    print(('PASS' if ok else 'FAIL'),name)
    if not ok: failed.append(name)
if failed: raise SystemExit('P0 security refactor smoke failed: '+', '.join(failed))
print('P0 security refactor PASS')
