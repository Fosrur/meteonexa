#!/usr/bin/env python3
from pathlib import Path
import json,sys
ROOT=Path(__file__).resolve().parents[1]
app=(ROOT/'app.js').read_text(encoding='utf-8')
account=(ROOT/'modules/esm/domains/account.mjs').read_text(encoding='utf-8')
device_sessions=(ROOT/'modules/esm/domains/device-sessions.mjs').read_text(encoding='utf-8')
account_frontend=app+'\n'+account
html=(ROOT/'index.html').read_text(encoding='utf-8')
styles=(ROOT/'styles.css').read_text(encoding='utf-8')
privacy=(ROOT/'privacy.html').read_text(encoding='utf-8')
cookie=(ROOT/'cookie-policy.html').read_text(encoding='utf-8')
trans=json.loads((ROOT/'api/install/translations.json').read_text(encoding='utf-8'))
checks={
 'impact chips filter cards': all(x in app for x in ['selectedActivities','visibleActivities','grid.dataset.filtered','IMPACT_ACTIVITY_IDS.filter','renderPersonalImpact();']),
 'guest alerts public surface': all(x in html for x in ['id="guest-public-alert-note"','id="public-official-alert-card"','data-mobile-audience="all" data-page="alerts"']) and 'setNotificationCenterAudience' in app and 'renderNotificationOfficialAlert' in app,
 'guest push controls hidden': '.notification-dialog[data-guest-public="true"]' in styles and 'notification-preferences' in styles and 'smart-alert-preferences' in styles,
 'guest touch consumes pointer': all(x in app for x in ["guestLoginButton.addEventListener('pointerdown'","guestLoginButton.addEventListener('pointerup'",'guestTouchActivationArmed','locationView.inert = true','input.readOnly = true','input.disabled = true']),
 'mobile search no autozoom': '#onboarding-city,#global-city-search,#command-city-search' in styles and 'font-size:16px!important' in styles and 'maximum-scale=5' in html and 'user-scalable=no' not in html,
 'private browser post-OTP hydration': all(x in account_frontend for x in ['hydrateAuthenticatedAccountAfterLogin','pullAccountSync({ force: true })','api/locations/manage.php?deviceId=','adoptSavedLocation']),
 'global device clear removed': 'devices-history-clear' not in app and 'devices-history-clear' not in device_sessions and 'devices-history-clear' not in styles and 'device-history-delete' in device_sessions,
 'privacy/cookie disclosure': all(x in privacy for x in ['privacy.guest.official_alerts','privacy.private_browsing']) and all(x in cookie for x in ['cookie.private_browsing.copy','cookie.guest.official_alerts.copy']),
 'translation seed': trans.get('version')=='20.1-semantic-i18n-v2',
 'guest Home official alert fail-soft parity': all(x in app for x in ['renderHomeOfficialAlert({ loading: true })','loadHomeOfficialAlerts({force}).catch','home.official.unavailable.title','state.officialAlertsRequest']) and 'if (isGuestSession()) await loadHomeOfficialAlerts' not in app and 'id="home-official-alert"' in html,
}
rows=trans.get('rows',[])
for key in ['guest.alerts.public.title','guest.alerts.official.kicker','privacy.guest.official_alerts','privacy.private_browsing','cookie.private_browsing.copy','home.official.loading.title','home.official.unavailable.title']:
 locales={r.get('locale') for r in rows if r.get('text_key')==key and str(r.get('translation','')).strip()}
 checks['i18n '+key]=locales=={'it','en','fr','es','de'}
failed=[]
for label,ok in checks.items():
 print(('[ OK ] ' if ok else '[FAIL] ')+label)
 if not ok:failed.append(label)
if failed:
 print('FAILED: '+', '.join(failed),file=sys.stderr);sys.exit(1)
print('Mobile / Guest Alerts / Private Browser smoke PASS')
