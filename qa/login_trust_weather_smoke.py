#!/usr/bin/env python3
from pathlib import Path
import json, sys
ROOT=Path(__file__).resolve().parents[1]
app=(ROOT/'app.js').read_text(encoding='utf-8')
authflow=(ROOT/'modules/esm/domains/auth-flow.mjs').read_text(encoding='utf-8')
auth_frontend=app+'\n'+authflow
styles=(ROOT/'styles.css').read_text(encoding='utf-8')
suite=(ROOT/'suite.js').read_text(encoding='utf-8')
smart=(ROOT/'weather-intelligence.js').read_text(encoding='utf-8')
trusted=(ROOT/'api/auth/trusted-check.php').read_text(encoding='utf-8')
auth=(ROOT/'api/auth_session.php').read_text(encoding='utf-8')
privacy=(ROOT/'privacy.html').read_text(encoding='utf-8')
cookie=(ROOT/'cookie-policy.html').read_text(encoding='utf-8')
translations=json.loads((ROOT/'api/install/translations.json').read_text(encoding='utf-8'))
start=app.find('function resolveLoginWeatherScene'); end=app.find('async function startLocalSession',start); block=app[start:end]
checks={
 'trusted endpoint POST/same-origin': "require_method('POST')" in trusted and 'assert_same_origin()' in trusted,
 'trusted endpoint rate limits': 'require_ip_rate_limit' in trusted and 'require_device_rate_limit' in trusted,
 'trusted proof includes cookie/device id/key': 'meteonexa_current_trusted_device_identity' in trusted and 'HTTP_X_METEONEXA_DEVICE_ID' in auth and 'HTTP_X_METEONEXA_DEVICE_KEY' in auth and 'device_credentials' in auth,
 'trusted response minimizes email': "'email'=>$email" not in trusted,
 'email button prechecks trust': 'beginEmailAccessFlow' in auth_frontend and "api/auth/trusted-check.php" in auth_frontend and "{ action: 'trusted-reentry' }" in auth_frontend,
 'OTP modal remains fallback': "openAuthDialog('email')" in auth_frontend and 'requestEmailCode' in auth_frontend,
 'login weather local-only': '6 * 3600000' in block and 'navigator.geolocation' not in block and 'fetch(' not in block and 'fetchJson' not in block and 'fetchJSON' not in block,
 'weather scenes complete': all(f'data-login-weather="{x}"' in styles for x in ['rain','storm','snow','fog','night','cloud']) and "return isDay ? 'sun' : 'night'" in block,
 'neutral not permanently sunny': '#welcome[data-login-weather="neutral"] .stage-sun{opacity:0' in styles,
 'official warning localized': 'officialWarningDisplay' in suite and 'advanced.official.summary.area' in suite and 'officialWarningText' in smart and 'officialWarningText(event)' in smart,
 'privacy disclosed': 'privacy.login.dynamic_weather' in privacy and 'privacy.auth.trusted_reentry' in privacy,
 'cookie disclosed': 'cookie.login.dynamic_weather' in cookie and 'cookie.trusted_precheck.copy' in cookie,
 'translation seed': translations.get('version')=='20.1-semantic-i18n-v2',
}
rows=translations.get('rows',[])
for key in ['auth.trusted.checking.title','auth.trusted.reentry.title','advanced.official.summary.area','privacy.login.dynamic_weather','cookie.trusted_precheck.copy']:
    locales={r.get('locale') for r in rows if r.get('text_key')==key and str(r.get('translation','')).strip()}
    checks['i18n '+key]=locales=={'it','en','fr','es','de'}
failed=[]
for label,ok in checks.items():
    print(('[ OK ] ' if ok else '[FAIL] ')+label)
    if not ok:failed.append(label)
if failed:
    print('FAILED: '+', '.join(failed),file=sys.stderr);sys.exit(1)
print('Login / Trusted Re-entry / Weather backdrop smoke PASS')
