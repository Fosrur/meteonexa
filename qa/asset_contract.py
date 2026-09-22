#!/usr/bin/env python3
from pathlib import Path
import hashlib,json,re,sys
root=Path(__file__).resolve().parents[1]; fail=[]
def ck(v,m): print(('PASS' if v else 'FAIL')+': '+m); fail.append(m) if not v else None
manifest=json.loads((root/'asset-manifest.json').read_text(encoding='utf-8'))
ck(len(manifest)>=46,'immutable asset manifest includes current frontend surfaces')
for logical,url in manifest.items():
 p=root/url.removeprefix('./'); ck(p.is_file(),f'asset exists: {logical}')
 if p.is_file():
  m=re.search(r'\.([0-9a-f]{12})\.(?:js|mjs|css)$',p.name); ck(bool(m) and hashlib.sha256(p.read_bytes()).hexdigest()[:12]==m.group(1),f'asset hash matches: {logical}')
for logical in ['js/app.js','js/suite.js','css/copilot.css','modules/esm/features/copilot.mjs','modules/esm/features/route-weather.mjs','modules/esm/features/decision-timeline.mjs','modules/esm/features/watch-plan.mjs','modules/esm/bootstrap.mjs','modules/esm/core/service-registry.mjs','modules/esm/core/runtime-api.mjs','modules/esm/core/store.mjs','modules/esm/core/runtime-state.mjs','modules/esm/core/tooltips.mjs','modules/esm/core/weather-utils.mjs','modules/esm/core/i18n-preferences.mjs']:
 ck(logical in manifest,f'manifest includes {logical}')
 if logical in manifest:
  published=root/manifest[logical].removeprefix('./')
  built=root/'.build/esbuild-production'/logical if logical.endswith('.mjs') else root/logical
  expected=built if built.is_file() else root/logical
  ck(expected.read_bytes()==published.read_bytes(),f'build/dist byte contract: {logical}')
idx=(root/'index.html').read_text(encoding='utf-8')
bootstrap=(root/'modules/esm/bootstrap.mjs').read_text(encoding='utf-8')
deferred_css=['css/suite.css','css/intelligence.css','css/decision-timeline.css','css/watch-plan.css','css/copilot.css']
for logical in deferred_css:
 ck(logical in bootstrap,f'bootstrap declares deferred stylesheet {logical}')
 ck(manifest.get(logical,'').removeprefix('./') not in idx,f'index does not render-block on {logical}')
ck(manifest.get('modules/esm/bootstrap.mjs','').removeprefix('./') in idx,'index references fingerprinted ESM bootstrap')
for logical in ['modules/esm/domains/navigation.mjs','modules/esm/domains/weather.mjs','modules/esm/features/route-weather.mjs','js/custom-controls.js','js/app.js','js/suite.js','modules/esm/features/copilot.mjs']:
 ck(logical in bootstrap,f'ESM bootstrap declares dynamic asset {logical}')
 ck(manifest.get(logical,'').removeprefix('./') not in idx,f'index does not duplicate dynamic {logical}')
ck("const APP_BUILD = '20.1'" in (root/'js/app.js').read_text(encoding='utf-8'),'app build is 20.1')
ck("const BUILD = '20.1'" in (root/'js/sw.js').read_text(encoding='utf-8'),'service worker build is 20.1')
ck("const VERSION = '20.1'" in (root/'js/i18n-runtime.js').read_text(encoding='utf-8'),'i18n runtime is 20.1')
print('\nAsset contract: '+('PASS' if not fail else 'FAIL'))
sys.exit(bool(fail))
