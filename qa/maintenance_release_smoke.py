#!/usr/bin/env python3
from pathlib import Path
import json,sys
ROOT=Path(sys.argv[1]).resolve() if len(sys.argv)>1 else Path(__file__).resolve().parents[1]
errors=[]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8')
ht=text('.htaccess'); deploy=text('docker/deploy-production.sh'); mode=text('docker/maintenance-mode.sh'); html=text('maintenance.html'); js=text('js/maintenance.js')
for needle in ['/var/lib/meteonexa/maintenance.flag','api/maintenance.php']:
    if needle not in ht: errors.append(f'.htaccess missing {needle}')
if 'Service-Worker-Allowed' not in ht or '/js/sw.js' not in ht: errors.append('service worker root scope header missing')
on=deploy.find('bash docker/maintenance-mode.sh on'); up=deploy.find('docker compose up -d'); off=deploy.rfind('bash docker/maintenance-mode.sh off')
if not (0 <= on < up < off): errors.append('deploy maintenance sequence must be on -> container replacement -> off')
if "trap 'echo \"ERRORE: deploy interrotto con maintenance mode ancora ATTIVA" not in deploy: errors.append('deploy failure maintenance trap missing')
if 'maintenance.flag' not in mode: errors.append('maintenance helper missing shared flag contract')
if 'data-i18n="maintenance.title"' not in html or 'js/maintenance.js' not in html or 'css/maintenance.css' not in html: errors.append('maintenance page not folder/i18n based')
if 'assets/i18n/${language}.json' not in js: errors.append('maintenance runtime does not load i18n catalog')
seed=json.loads(text('api/install/translations.json'))['rows']
keys={r['text_key'] for r in seed}; locales={r['locale'] for r in seed if r['text_key'].startswith('maintenance.')}
required={'maintenance.kicker','maintenance.title','maintenance.message','maintenance.note','maintenance.retry','maintenance.page_title'}
if not required.issubset(keys): errors.append('maintenance translations incomplete')
if locales != {'it','en','fr','es','de'}: errors.append(f'maintenance locales incomplete: {sorted(locales)}')
print('Maintenance release contract: '+('PASS' if not errors else 'FAIL'))
for e in errors: print(' - '+e)
sys.exit(bool(errors))
