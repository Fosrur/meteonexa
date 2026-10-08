#!/usr/bin/env python3
from pathlib import Path
import re
import sqlite3
import sys

ROOT = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parents[1]
errors = []
locales = ['de', 'en', 'es', 'fr', 'it']
path = ROOT / 'api/install/meteonexa-baseline.sqlite'
with sqlite3.connect(path) as connection:
    rows = connection.execute('SELECT locale,text_key,translation FROM translations ORDER BY locale,text_key').fetchall()
by_locale = {locale: {} for locale in locales}
for locale, key, translation in rows:
    if locale in by_locale:
        by_locale[locale][key] = translation
base_keys = set(by_locale['it'])
for locale in locales:
    keys = set(by_locale[locale])
    missing = base_keys - keys
    extra = keys - base_keys
    blank = [key for key, value in by_locale[locale].items() if not str(value).strip()]
    if missing or extra or blank:
        errors.append(f'{locale}: missing={len(missing)} extra={len(extra)} blank={len(blank)}')

runtime = (ROOT / 'api/i18n.php').read_text(encoding='utf-8')
if 'FROM translations' not in runtime:
    errors.append('api/i18n.php must read runtime translations from DB')
preferences = (ROOT / 'api/preferences.php').read_text(encoding='utf-8')
if 'translations' not in preferences:
    errors.append('preferences endpoint must expose DB-backed translation catalog')

semantic = re.compile(r'^[a-z][a-z0-9_-]*(?:\.[a-z0-9_-]+)+$')
for relative in [
    'modules/esm/core/i18n-preferences.mjs',
    'modules/esm/core/i18n-catalog.mjs',
    'modules/esm/core/i18n-dom.mjs',
    'modules/esm/domains/radar-layers.mjs',
    'modules/esm/domains/suite-route.mjs',
    'modules/esm/domains/suite-history.mjs',
    'modules/esm/domains/suite-weather-intelligence.mjs',
]:
    source = (ROOT / relative).read_text(encoding='utf-8')
    for match in re.finditer(r'\b(?:t|meteonexaText|ui)\(\s*(["\'])(.*?)\1', source):
        key = match.group(2)
        if key and not semantic.fullmatch(key):
            errors.append(f'{relative}: non-semantic i18n literal {key[:80]}')

print('I18N DB source + parity: ' + ('PASS' if not errors else 'FAIL'))
if not errors:
    print(f' locales=5 keys={len(base_keys)} rows={len(rows)}')
for error in errors[:100]:
    print(' - ' + error)
sys.exit(bool(errors))
