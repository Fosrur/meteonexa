#!/usr/bin/env python3
from pathlib import Path
import re,sys
root=Path(__file__).resolve().parents[1]
errors=[]
browser_files=['js/app.js','js/advanced.js','js/suite.js','js/config.js','modules/esm/features/intelligence.mjs','modules/esm/core/i18n-preferences.mjs','modules/esm/domains/visualization.mjs','modules/esm/domains/forecast-history.mjs','modules/esm/domains/model-intelligence.mjs','modules/esm/domains/device-sessions.mjs','modules/esm/domains/app-lifecycle.mjs','modules/esm/domains/suite-support.mjs','modules/esm/domains/app-utilities.mjs','modules/esm/domains/suite-assistant.mjs','modules/esm/domains/suite-integrations.mjs','modules/esm/domains/account.mjs','modules/esm/domains/auth-flow.mjs','modules/esm/domains/locations.mjs','modules/esm/domains/navigation.mjs','modules/esm/domains/notifications.mjs','modules/esm/domains/radar-motion.mjs','modules/esm/domains/radar-controller.mjs']
provider_tokens=['CONFIG.ECMWF_API','CONFIG.AIFS_API','CONFIG.ICON_API','CONFIG.DWD_ICON_API','CONFIG.GFS_API','CONFIG.METEOFRANCE_API','CONFIG.UKMO_API']
provider_urls=['api.open-meteo.com/v1/ecmwf','api.open-meteo.com/v1/dwd-icon','api.open-meteo.com/v1/gfs','api.open-meteo.com/v1/meteofrance','ukmo_seamless']
for name in browser_files:
    text=(root/name).read_text(encoding='utf-8')
    for token in provider_tokens:
        if token in text: errors.append(f'direct provider config reference in {name}: {token}')
    for token in provider_urls:
        if token in text: errors.append(f'direct provider URL in browser source {name}: {token}')
# Expected model totals in runtime must be supplied by provider definitions/server evidence.
for path in [root/'js/app.js',root/'js/advanced.js',root/'js/suite.js',root/'modules/esm/features/intelligence.mjs',root/'api/weather/severe.php',root/'api/intelligence/severe_outlook_helpers.php']:
    text=path.read_text(encoding='utf-8')
    for rx in [r'modelsExpected\s*[:=]>?\s*6\b',r'EXPECTED_FORECAST_MODELS\s*=\s*6\b',r'freshCount\s*/\s*6\b',r'\(\s*[56]\s*-\s*(?:rows|models)\.length\s*\)']:
        if re.search(rx,text): errors.append(f'hardcoded model-count logic in {path.relative_to(root)}: {rx}')
# No non-empty literal credentials in runtime source.
secret_rx=re.compile(r'(?i)\b(?:api[_-]?key|access[_-]?token|bearer[_-]?token|password|secret)\b\s*[:=]\s*[\'\"]([^\'\"]{8,})[\'\"]')
for path in root.rglob('*'):
    if not path.is_file() or 'dist' in path.parts or 'vendor' in path.parts or 'qa' in path.parts or path.name in {'readme.md','.env.example'}: continue
    if path.suffix.lower() not in {'.js','.mjs','.php'}: continue
    try:text=path.read_text(encoding='utf-8')
    except UnicodeDecodeError: continue
    if secret_rx.search(text): errors.append(f'possible hardcoded credential in {path.relative_to(root)}')
print('Hardcode contract: '+('PASS' if not errors else 'FAIL'))
for e in errors: print(' - '+e)
sys.exit(bool(errors))
