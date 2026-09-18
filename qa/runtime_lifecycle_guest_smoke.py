#!/usr/bin/env python3
from pathlib import Path
import re, sys
ROOT = Path(__file__).resolve().parents[1]
advanced = (ROOT/'advanced.js').read_text(encoding='utf-8')
suite = (ROOT/'suite.js').read_text(encoding='utf-8')
integrations = (ROOT/'modules/esm/domains/suite-integrations.mjs').read_text(encoding='utf-8')
app = (ROOT/'app.js').read_text(encoding='utf-8')
styles = (ROOT/'styles.css').read_text(encoding='utf-8')
html = (ROOT/'index.html').read_text(encoding='utf-8')
checks = {
    'advanced service remains immutable': 'const advancedService = Object.freeze({' in advanced,
    'advanced exposes lifecycle registration': 'registerLifecycleHook' in advanced and "lifecycleHooks = new Set()" in advanced,
    'advanced dispatches lifecycle hooks': all(x in advanced for x in ["runLifecycleHooks('renderAll')", "runLifecycleHooks('locationChanged')", "runLifecycleHooksAsync('afterRefresh')", "runLifecycleHooksAsync('onPage', page)"]),
    'legacy suite uses lifecycle hook API': "advanced.registerLifecycleHook(Object.freeze({" in suite,
    'ESM suite integrations use lifecycle hook API': "advanced.registerLifecycleHook(Object.freeze({" in integrations,
    'legacy suite does not monkey patch advanced service': not re.search(r'\b(?:advanced|original)\.(?:renderAll|onPage|afterRefresh|locationChanged|build)\s*=', suite),
    'ESM integrations do not monkey patch advanced service': not re.search(r'\b(?:advanced|original|deps\.advanced)\.(?:renderAll|onPage|afterRefresh|locationChanged|build)\s*=', integrations),
    'guest CTA is a real button': 'id="guest-login"' in html and '<button' in html[html.rfind('<button',0,html.find('id="guest-login"')):html.find('id="guest-login"')+40],
    'guest CTA has full pointer hit target': all(x in styles for x in ['#guest-login{','pointer-events:auto!important','touch-action:manipulation!important','#guest-login>*{pointer-events:none!important}']),
    'guest CTA is required at runtime': "METEONEXA_GUEST_LOGIN_BUTTON_MISSING" in app,
    'guest CTA activation covers pointer and click': all(x in app for x in ["guestLoginButton.addEventListener('pointerdown'", "guestLoginButton.addEventListener('pointerup'", "guestLoginButton.addEventListener('click'"]),
}
failed=[]
for label, ok in checks.items():
    print(('[ OK ] ' if ok else '[FAIL] ')+label)
    if not ok: failed.append(label)
if failed:
    print('FAILED: '+', '.join(failed), file=sys.stderr)
    sys.exit(1)
print('Runtime lifecycle / guest CTA regression smoke PASS')
