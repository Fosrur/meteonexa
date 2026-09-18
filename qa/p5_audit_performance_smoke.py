#!/usr/bin/env python3
from __future__ import annotations
import json, re, sys
from pathlib import Path
ROOT=Path(sys.argv[1]).resolve() if len(sys.argv)>1 else Path(__file__).resolve().parents[1]

def fail(msg): raise SystemExit('P5 audit/performance: FAIL - '+msg)
bootstrap=(ROOT/'modules/esm/bootstrap.mjs').read_text(encoding='utf-8')
if 'Promise.all(PRE_APP_ESM.map(logical => import(assetUrl(logical))))' not in bootstrap: fail('pre-app ESM imports are not parallelized')
if 'for (const logical of PRE_APP_ESM) await importInstall' in bootstrap: fail('sequential pre-app import waterfall reintroduced')
if 'for (let index = 0; index < modules.length; index += 1)' not in bootstrap: fail('deterministic install order missing')

privacy=(ROOT/'privacy.html').read_text(encoding='utf-8')
context=(ROOT/'privacy-context.js').read_text(encoding='utf-8')
ui=(ROOT/'api/ui-config.php').read_text(encoding='utf-8')
config=(ROOT/'api/config.php').read_text(encoding='utf-8')
env=(ROOT/'.env.example').read_text(encoding='utf-8')
for token in ['privacy-controller-name','privacy-controller-address','privacy-controller-warning','privacy-dpo-email','privacy-groq-link']:
    if token not in privacy: fail('privacy deployment context missing '+token)
for token in ['controllerName','controllerAddress','legalConfigured','dpoEmail','aiProvider']:
    if token not in ui: fail('public legal context missing '+token)
for token in ['METEONEXA_LEGAL_CONTROLLER_NAME','METEONEXA_LEGAL_CONTROLLER_ADDRESS','METEONEXA_PRIVACY_CONTACT_EMAIL','METEONEXA_DPO_EMAIL']:
    if token not in config or token not in env: fail('deployment legal config missing '+token)
if "setKey('privacy-dpo-copy', 'privacy.art13.dpo.configured')" not in context: fail('DPO policy copy is not runtime-aware')

cookie=json.loads((ROOT/'assets/i18n/en.json').read_text(encoding='utf-8'))['translations']
for key,needle in {
    'cookie.session.copy':'meteonexa_auth_session',
    'cookie.trusted.copy':'meteonexa_trusted_device',
    'cookie.client.copy':'meteonexa_client',
    'cookie.analytics.copy':'Plausible',
}.items():
    if needle not in cookie.get(key,''): fail('cookie policy mismatch '+key)

ht=(ROOT/'.htaccess').read_text(encoding='utf-8')
for needle in ["default-src 'self'", "script-src 'self'", 'Strict-Transport-Security', 'X-Content-Type-Options', 'Referrer-Policy']:
    if needle not in ht: fail('security header missing '+needle)

sw=(ROOT/'sw.js').read_text(encoding='utf-8')
if "SHELL_REVISION = 'rc2-stabilization-01-lifecycle-guest'" not in sw: fail('service-worker revision not bumped')

seed=json.loads((ROOT/'api/install/translations.json').read_text(encoding='utf-8'))
if seed.get('version')!='20.1-semantic-i18n-v2': fail('translation seed version mismatch')
if len({row['text_key'] for row in seed.get('rows',[])})!=4656: fail('translation key count mismatch')
if re.search(r'^(?:[a-z0-9_]+\.)*[0-9a-f]{12,}$', '\n'.join(sorted({row['text_key'] for row in seed.get('rows',[])})), re.M): fail('hash-like i18n key remains')

print('P5 audit/performance: PASS (privacy/legal context, cookie/security contract, parallel ESM bootstrap)')
