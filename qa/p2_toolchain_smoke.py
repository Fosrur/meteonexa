#!/usr/bin/env python3
"""P2 final native-ESM/toolchain contract."""
from pathlib import Path
import json,re,subprocess,sys
root=Path(sys.argv[1]).resolve() if len(sys.argv)>1 else Path(__file__).resolve().parents[1]
read=lambda p:(root/p).read_text(encoding='utf-8',errors='replace')
app=read('app.js'); advanced=read('advanced.js'); suite=read('suite.js'); intelligence_shell=read('weather-intelligence.js')
registry=read('modules/esm/core/service-registry.mjs'); runtime=read('modules/esm/core/runtime-api.mjs'); bootstrap=read('modules/esm/bootstrap.mjs')
store=read('modules/esm/core/store.mjs'); runtime_state=read('modules/esm/core/runtime-state.mjs'); tooltips=read('modules/esm/core/tooltips.mjs'); weather=read('modules/esm/core/weather-utils.mjs'); i18n=read('modules/esm/core/i18n-preferences.mjs')
readme=read('METEONEXA-20.1-RC2.md'); architecture=read('METEONEXA-20.1-RC2.md'); index=read('index.html'); sw=read('sw.js'); htaccess=read('.htaccess')
manifest=json.loads(read('asset-manifest.json')); package=json.loads(read('package.json'))
core_esm=['modules/esm/core/store.mjs','modules/esm/core/runtime-state.mjs','modules/esm/core/tooltips.mjs','modules/esm/core/weather-utils.mjs','modules/esm/core/i18n-preferences.mjs']
domain_esm=['modules/esm/domains/visualization.mjs','modules/esm/domains/forecast-history.mjs','modules/esm/domains/model-intelligence.mjs','modules/esm/domains/device-sessions.mjs','modules/esm/domains/app-lifecycle.mjs','modules/esm/domains/suite-support.mjs','modules/esm/domains/app-utilities.mjs','modules/esm/domains/suite-assistant.mjs','modules/esm/domains/suite-integrations.mjs','modules/esm/domains/locations.mjs','modules/esm/domains/navigation.mjs','modules/esm/domains/notifications.mjs','modules/esm/domains/weather.mjs','modules/esm/domains/radar.mjs','modules/esm/domains/alerts.mjs','modules/esm/domains/auth.mjs','modules/esm/domains/auth-flow.mjs','modules/esm/domains/account.mjs','modules/esm/domains/radar-motion.mjs','modules/esm/domains/radar-controller.mjs','modules/esm/domains/privacy.mjs','modules/esm/domains/feedback.mjs','modules/esm/domains/ai.mjs']
feature_esm=['modules/esm/features/route-weather.mjs','modules/esm/features/copilot.mjs','modules/esm/features/intelligence.mjs','modules/esm/features/decision-timeline.mjs','modules/esm/features/watch-plan.mjs']
legacy_sources=['modules/core/store.js','modules/core/runtime-state.js','modules/core/tooltips.js','modules/core/weather-utils.js','modules/core/i18n-preferences.js','modules/core/service-registry.js','modules/core/runtime-api.js']+[p.replace('modules/esm/','modules/').replace('.mjs','.js') for p in domain_esm+feature_esm]
classic_registry_consumers=['app.js','advanced.js','suite.js','weather-intelligence.js','product-metrics.js','custom-controls.js']
checks={
    'package zero-dependency release scripts': all(package.get('scripts',{}).get(name) for name in ['check','build:assets','build','qa']),
    'package full toolchain scripts': all(package.get('scripts',{}).get(name) for name in ['lint:eslint','bundle:verify','check:full']),
    'node engine supports eslint10': str(package.get('engines',{}).get('node','')).startswith('>=20.19'),
    'tool versions pinned': package.get('devDependencies',{}).get('esbuild')=='0.28.2' and package.get('devDependencies',{}).get('eslint')=='10.10.0',
    'legacy core/domain/feature sources removed': all(not (root/path).exists() for path in legacy_sources),
    'service registry stores internally': 'const published = new Map()' in registry and 'publish(name, service, { legacy = false } = {})' in registry and 'exposeLegacy' in registry,
    'runtime api is esm': 'export function createRuntimeApiHost' in runtime and 'export function installRuntimeApi' in runtime and 'METEONEXA_RUNTIME_API_MISSING' in runtime,
    'store is esm': 'export const coreService' in store and 'export function installCore' in store and 'attachRuntimeState' in store,
    'runtime state is esm': 'export function createRuntimeState' in runtime_state and 'export function installRuntimeState' in runtime_state,
    'tooltips are esm': 'export const tooltipsService' in tooltips and 'export function installTooltips' in tooltips,
    'weather utils are esm': 'export function create' in weather and 'export function installWeatherUtils' in weather,
    'i18n preferences are esm': 'export function create' in i18n and 'export function installI18nPreferences' in i18n,
    'bootstrap installs esm core': 'const CORE_ESM' in bootstrap and 'CORE_ESM.map(logical => import(assetUrl(logical)))' in bootstrap and all(path in bootstrap for path in core_esm),
    'bootstrap installs esm domains': 'const PRE_APP_ESM' in bootstrap and 'Promise.all(PRE_APP_ESM.map(logical => import(assetUrl(logical))))' in bootstrap and 'await importPreAppModules(services)' in bootstrap and all(path in bootstrap for path in domain_esm),
    'bootstrap installs post-app esm features': 'const POST_APP_ESM' in bootstrap and all(path in bootstrap for path in feature_esm) and "await importInstall(POST_APP_ESM.copilot, services)" in bootstrap and "await importInstall(POST_APP_ESM.watchPlan, services)" in bootstrap,
    'classic shells use only registry boundary': all((lambda src: 'window.MeteoNexaServices' in src and not re.search(r'window\.MeteoNexa(?!Services)',src))(read(path)) for path in classic_registry_consumers),
    'app late-load safe': "document.readyState === 'loading'" in app and 'queueMicrotask(startApplication)' in app,
    'advanced late-load safe': "document.readyState === 'loading'" in advanced and 'queueMicrotask(bind)' in advanced,
    'suite late-load safe': 'bindDatePickerDom' in suite and 'queueMicrotask(bindDatePickerDom)' in suite,
    'advanced runtime dependency explicit': "const APP_RUNTIME = SERVICES.require('runtimeApi').get();" in advanced and 'const appState = () => APP_RUNTIME.getState();' in advanced,
    'esm assets fingerprinted': all(k in manifest for k in ['modules/esm/bootstrap.mjs','modules/esm/core/service-registry.mjs','modules/esm/core/runtime-api.mjs',*core_esm,*domain_esm,*feature_esm]),
    'index uses native esm bootstrap only': index.find('asset-manifest.js') < index.find(manifest['modules/esm/bootstrap.mjs'].removeprefix('./')) and 'type="module"' in index and all(manifest[path].removeprefix('./') not in index for path in domain_esm+feature_esm),
    'service worker caches full esm graph': all(f"asset('{path}')" in sw for path in ['modules/esm/bootstrap.mjs','modules/esm/core/service-registry.mjs','modules/esm/core/runtime-api.mjs',*core_esm,*domain_esm,*feature_esm]),
    'service worker cache revision final': "const SHELL_REVISION = 'rc2-stabilization-01-lifecycle-guest';" in sw,
    'mjs mime configured': 'AddType text/javascript .mjs' in htaccess,
    'readme documents p2 final': '## 20.1 — P2 finale: domini/feature ESM e service registry interno' in readme and '**Stato P2: COMPLETATO.**' in readme,
    'architecture documents p2 final': '## P2 final — ESM domains/features and internal service registry' in architecture,
}
failed=[]
for name,ok in checks.items():
    print(('PASS' if ok else 'FAIL'),name)
    if not ok: failed.append(name)
for cmd,label in [
    (['node',str(root/'tools/static-analysis.mjs')],'static analysis'),
    (['node',str(root/'qa/p2_esm_runtime_smoke.mjs')],'native ESM boundary smoke'),
    (['node',str(root/'qa/p2_core_esm_smoke.mjs')],'native ESM core smoke'),
    (['node',str(root/'qa/p2_domain_esm_smoke.mjs')],'native ESM domain/feature smoke')
]:
    result=subprocess.run(cmd,cwd=root)
    if result.returncode!=0: failed.append(label)
    else: print('PASS',label)
if failed: raise SystemExit('P2 final smoke failed: '+', '.join(failed))
print('P2 final native ESM/toolchain PASS')
