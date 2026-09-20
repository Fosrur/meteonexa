#!/usr/bin/env python3
from pathlib import Path
import json,re,sys
root=Path(__file__).resolve().parents[1]
errors=[]
def ok(cond,msg):
    if not cond: errors.append(msg)

def text(rel): return (root/rel).read_text(encoding='utf-8')

seed=json.loads(text('api/install/translations.json'))
rows=seed.get('rows',[]) if isinstance(seed,dict) else seed
seed_keys={str(r.get('text_key','')).strip() for r in rows if isinstance(r,dict) and str(r.get('text_key','')).strip()}
for lang in ('it','en','fr','es','de'):
    path=root/f'assets/i18n/{lang}.json'
    ok(path.is_file(),f'missing static catalog {lang}')
    if path.is_file():
        payload=json.loads(path.read_text(encoding='utf-8'))
        data=payload.get('translations',{}) if isinstance(payload,dict) else {}
        ok(payload.get('language')==lang,f'static catalog {lang} language marker mismatch')
        ok(set(data)==seed_keys,f'static catalog {lang} key set differs from canonical translations')
        ok(all(isinstance(v,str) and v.strip() for v in data.values()),f'static catalog {lang} has empty translations')

i18n=text('js/i18n-runtime.js')
ok('requestStaticCatalog' in i18n,'i18n runtime has no static first-paint catalog')
ok("assignCatalog(local, 'static-release')" in i18n,'static catalog is not assigned before server sync')
ok('if (!markReady())' in i18n,'i18n bootstrap does not explicitly release the pending UI state')
ok('credentials: \'omit\'' in i18n or 'credentials:"omit"' in i18n,'static i18n fetch should omit credentials')

app=text('js/app.js')
ok('clearNavigationLoadingArtifacts' in app,'navigation loading artifact cleanup missing')
ok('loadUiVisibilityConfig().catch' in app,'UI config should be background/fail-soft at boot')
ok('loadHomeOfficialAlerts({force}).catch' in app.replace(' ','' ) or 'loadHomeOfficialAlerts({force}).catch' in app,'official alerts should be background/fail-soft')
ok('if (isGuestSession()) await loadHomeOfficialAlerts' not in app,'guest weather boot still blocks on official alerts')
ok("if (!candidate || candidate.disabled || candidate.matches('[data-no-preloader], [data-page], .nav-link, .mobile-nav-link')) return;" in app,'loader action guard is missing for pointer/touch paths without a click candidate')
ok('spinner.\\n        if (!candidate' not in app,'loader action guard was accidentally swallowed by a literal \\n inside a comment')
ok('function forceReleaseGlobalLoader()' in app,'global loader has no fail-safe release helper')
ok("try {\n        setLoader(true, title, copy);" in app,'withLoader can still throw before entering its try/finally release path')
ok("if (type === 'guest') {" in app and 'await enterSession();' in app,'guest access is not on a local-only fast path')
ok("return waitForWeather ? state.weather : null;" in app,'background weather hydration still leaks its promise to callers and can block guest entry')
ok("const shouldWaitForWeather = !isGuestSession()" in app,'restored guest sessions can still block on a fresh weather request')
ok("if (isGuestSession()) {\n            await showApp({ refresh: true, waitForWeather: false, forceWeather: true });" in app,'continue-to-app still wraps guest entry in the global weather loader')

watch=text('modules/esm/features/watch-plan.mjs')
ok('function authenticated()' in watch or 'const authenticated=' in watch,'watch plan has no auth guard')
ok('if(!authenticated())' in watch.replace(' ',''),'watch plan load does not short-circuit guests')
ok("if(authenticated())setTimeout(()=>load({quiet:true})" in watch.replace(' ',''),'watch plan ready event can still call API for guests')

advanced=text('js/advanced.js')
ok('loadAdvanced(false, true).then' in advanced,'advanced page does not background its initial load')
ok('modelState.rows.length === 3' not in advanced,'advanced still hardcodes a legacy three-model completeness test')
ok('legacy 3-model' not in advanced and '5-model suite' not in advanced,'advanced still carries legacy model-count semantics')

sw=text('js/sw.js')
for lang in ('it','en','fr','es','de'):
    ok(f"appUrl('assets/i18n/{lang}.json')" in sw,f'service worker does not precache static {lang} catalog')
ok("SHELL_REVISION = '20.1-final-04-assistant-runtime-radar'" in sw,'service worker shell revision is not the current 20.1 P2 final ESM-services revision')

css=text('css/suite.css')
ok('.nav-link.button-loading' in css and 'pointer-events:auto!important' in css,'navigation fail-safe CSS missing')

legacy=text('qa/no_legacy_release_refs.py')
ok('(?:17|18|19)' in legacy,'legacy release scan does not protect older app release markers')

print('Mobile/bootstrap regression gate: '+('PASS' if not errors else 'FAIL'))
for e in errors: print(' - '+e)
sys.exit(bool(errors))
