#!/usr/bin/env python3
from pathlib import Path
import json,re,sys
root=Path(__file__).resolve().parents[1]
rows=json.loads((root/'api/install/translations.json').read_text(encoding='utf-8'))['rows']
by={(r['locale'],r['text_key']):r['translation'] for r in rows}
locales={'it','en','es','fr','de'}
errors=[]
for key in ['cookie.updated','cookie.intel.features','cookie.verified_precision.copy','smart.panel.kicker','privacy.forecast_fusion']:
    for loc in locales:
        if not str(by.get((loc,key),'')).strip(): errors.append(f'missing {key} [{loc}]')
expected_six={'it':'sei modelli','en':'six Open-Meteo models','es':'seis modelos','fr':'six modèles','de':'sechs Open-Meteo-Modellen'}
for loc,needle in expected_six.items():
    text=by.get((loc,'privacy.forecast_fusion'),'')
    if needle.lower() not in text.lower(): errors.append(f'privacy.forecast_fusion not aligned to six models [{loc}]')
for fn in ['privacy.html','cookie-policy.html']:
    text=(root/fn).read_text(encoding='utf-8')
    if 'MeteoNexa 20.1' not in text: errors.append(f'{fn} current release marker missing')
    if re.search(r'(?<!\d)(?:18|19)\.\d+(?:\.\d+)?',text): errors.append(f'{fn} contains old app release reference')
print('Policy alignment: '+('PASS' if not errors else 'FAIL'))
for e in errors: print(' - '+e)
sys.exit(bool(errors))
