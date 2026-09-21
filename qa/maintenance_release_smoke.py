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
build=deploy.find('docker compose build --pull web worker'); drill=deploy.find('bash docker/verify-backup-restore.sh'); on=deploy.find('bash docker/maintenance-mode.sh on'); up=deploy.find('docker compose up -d'); off=deploy.rfind('bash docker/maintenance-mode.sh off')
if not (0 <= build < drill < on < up < off): errors.append('deploy sequence must be build -> restore drill -> maintenance on -> container replacement -> off')
if "trap 'echo \"ERRORE: deploy interrotto con maintenance mode ancora ATTIVA" not in deploy: errors.append('deploy failure maintenance trap missing')
if 'maintenance.flag' not in mode: errors.append('maintenance helper missing shared flag contract')
if 'data-i18n="maintenance.title"' not in html or 'js/maintenance.js' not in html or 'css/maintenance.css' not in html: errors.append('maintenance page assets/i18n wiring missing')
for asset in ('css/maintenance\\.css','js/maintenance\\.js','assets/logo-full\\.png','assets/icons/(?:favicon-32\\.png|favicon\\.ico|apple-touch-icon\\.png|icon-192\\.png)','assets/i18n/'):
    if asset not in ht: errors.append('maintenance rewrite does not exempt '+asset)

for asset in ('assets/logo-full.png','assets/icons/favicon-32.png','assets/icons/favicon.ico','assets/icons/apple-touch-icon.png'):
    if not (ROOT/asset).is_file(): errors.append('maintenance branding asset missing '+asset)
if 'id="maintenance-critical"' not in html: errors.append('maintenance embedded critical CSS missing')
if html.count('data:image/png;base64,') < 4: errors.append('maintenance embedded logo/favicon fallback missing')
if "style-src 'self' 'sha256-" not in ht or "style-src-elem 'self' 'sha256-" not in ht: errors.append('maintenance critical CSS CSP hash missing')
for fallback in ('MeteoNexa è in manutenzione','RILASCIO IN CORSO','Riprova ora','id="maintenance-critical"'):
    if fallback not in html: errors.append('maintenance resilient fallback missing '+fallback)
if 'bgcolor="#030914"' not in html or 'maintenance-ambient' not in html or 'maintenance-weather-loader' not in html: errors.append('maintenance branded dark fallback/visual shell missing')
if 'assets/i18n/${language}.json' not in js: errors.append('maintenance runtime does not load i18n catalog')
if 'maintenance_probe=' not in js or 'setInterval' not in js: errors.append('maintenance auto-recovery probe missing')
if '@media(min-width:621px)' not in html or 'max-height:calc(100dvh - 36px)' not in html or 'width:min(100%,600px)' not in html: errors.append('maintenance desktop compact no-scroll contract missing')
if '@media(max-width:620px),(max-height:560px)' not in html or 'body{overflow:auto}' not in html: errors.append('maintenance small-viewport scroll fallback missing')
if 'http-equiv="refresh"' not in html: errors.append('maintenance HTML refresh fallback missing')
if 'maintenance_release=' not in js or 'location.replace' not in js: errors.append('maintenance cache-busting release navigation missing')
if 'METEONEXA_KEEP_MAINTENANCE' not in deploy: errors.append('deploy cannot retain maintenance through internal replacement checks')
if 'api/system/status.php' not in deploy: errors.append('deploy must gate release on dynamic system status/schema migration endpoint')
seed=json.loads(text('api/install/translations.json'))['rows']
keys={r['text_key'] for r in seed}; locales={r['locale'] for r in seed if r['text_key'].startswith('maintenance.')}
required={'maintenance.kicker','maintenance.title','maintenance.message','maintenance.note','maintenance.retry','maintenance.page_title'}
if not required.issubset(keys): errors.append('maintenance translations incomplete')
if locales != {'it','en','fr','es','de'}: errors.append(f'maintenance locales incomplete: {sorted(locales)}')
print('Maintenance release contract: '+('PASS' if not errors else 'FAIL'))
for e in errors: print(' - '+e)
sys.exit(bool(errors))
