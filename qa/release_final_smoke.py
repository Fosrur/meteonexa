#!/usr/bin/env python3
from __future__ import annotations
import json, re, sys
from pathlib import Path

ROOT = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parents[1]

def fail(msg: str) -> None:
    raise SystemExit(f'Final release smoke FAIL: {msg}')

def text(rel: str) -> str:
    return (ROOT / rel).read_text(encoding='utf-8')

def js_array(source: str, name: str) -> list[str]:
    match = re.search(rf"const\s+{re.escape(name)}\s*=\s*Object\.freeze\(\[(.*?)\]\);", source, re.S)
    if not match:
        fail(f'{name} array missing')
    return re.findall(r"'([^']+)'", match.group(1))

bootstrap = text('modules/esm/bootstrap.mjs')
pre_app = js_array(bootstrap, 'PRE_APP_ESM')
suite = js_array(bootstrap, 'SUITE_ESM')
deferred_styles = js_array(bootstrap, 'DEFERRED_STYLES')

expected_suite = {
    'modules/esm/domains/suite-support.mjs',
    'modules/esm/domains/suite-assistant.mjs',
    'modules/esm/features/route-weather.mjs',
}
if not expected_suite.issubset(set(suite)):
    fail('suite-only ESM modules are not isolated in SUITE_ESM')
if expected_suite.intersection(pre_app):
    fail('suite-only ESM modules still block js/app.js')

pre_app_bytes = sum((ROOT / rel).stat().st_size for rel in pre_app)
if pre_app_bytes > 450_000:
    fail(f'pre-app ESM payload budget exceeded: {pre_app_bytes} bytes')
if "preloadClassic('js/app.js')" not in bootstrap:
    fail('js/app.js preload missing')

expected_deferred = {'css/advanced.css','css/suite.css','css/intelligence.css','css/decision-timeline.css','css/watch-plan.css','css/copilot.css'}
if set(deferred_styles) != expected_deferred:
    fail(f'deferred stylesheet contract drifted: {deferred_styles}')
if 'scheduleDeferredStyles();' not in bootstrap or 'requestAnimationFrame(() => requestAnimationFrame(run))' not in bootstrap:
    fail('feature styles are not scheduled after first paint')

manifest = json.loads(text('asset-manifest.json'))
index = text('index.html')
styles = text('css/styles.css')
welcome_styles = text('styles/main/10-welcome-auth.css')
blocking_css = ['css/styles.css','css/light-theme.css']
blocking_bytes = sum((ROOT / rel).stat().st_size for rel in blocking_css)
if blocking_bytes > 450_000:
    fail(f'blocking CSS budget exceeded: {blocking_bytes} bytes')
for logical in expected_deferred:
    target = str(manifest.get(logical, '')).removeprefix('./')
    if target and re.search(rf'<link[^>]+href=["\']{re.escape(target)}["\'][^>]+rel=["\']stylesheet["\']', index):
        fail(f'{logical} is render-blocking in index.html')
if 'api/vendor-asset.php?asset=maplibre-css' in index:
    fail('MapLibre CSS is still render-blocking in index.html')
if not re.search(r'<script[^>]+defer[^>]+src="api/vendor-asset\.php\?asset=maplibre-js"', index):
    fail('MapLibre JS is not parser-deferred')

visualization = text('modules/esm/domains/visualization.mjs')
if "Object.freeze(['radarMotion', 'radarController'])" not in visualization:
    fail('visualization radar dependencies are not explicit')
if re.search(r'\bSERVICES\.', visualization):
    fail('visualization still references an undeclared SERVICES global')
for name in ('ensureRadar','setRadarFrame','renderRadarMap','toggleRadarAnimation','zoomRadar'):
    if not re.search(rf'\b{name}\b', visualization):
        fail(f'visualization does not expose {name}')
app = text('js/app.js')
if 'ensureRadar, setRadarFrame, stopRadarAnimation' not in app:
    fail('js/app.js does not bind radar runtime functions from visualization')

sw = text('js/sw.js')
for marker in ('CRITICAL_SHELL', 'OPTIONAL_SHELL', 'WARM_OPTIONAL_SHELL', "cache: immutableAssetRequest(url) ? 'force-cache' : 'reload'", "SHELL_REVISION = '20.1-final-05-release'"):
    if marker not in sw:
        fail(f'service worker performance contract missing: {marker}')
install_match = re.search(r"addEventListener\('install'.{0,500}", sw, re.S)
if not install_match or 'CRITICAL_SHELL' not in install_match.group(0) or 'APP_SHELL' in install_match.group(0):
    fail('service worker install must precache critical shell only')
notifications = text('modules/esm/domains/notifications.mjs')
if 'scheduleOptionalShellWarmup' not in notifications or 'WARM_OPTIONAL_SHELL' not in notifications:
    fail('optional shell warmup is not scheduled from the app')


# RC1 bootstrap regression guard: navigation declares uiVisibility/guestAccess and
# must publish stable facades synchronously before js/app.js creates the controller.
navigation = text('modules/esm/domains/navigation.mjs')
for marker in (
    'provided.uiVisibility = uiVisibilityService;',
    'provided.guestAccess = guestAccessService;',
    'Object.assign(uiVisibilityService, {',
    'Object.assign(guestAccessService, {'
):
    if marker not in navigation:
        fail(f'navigation synchronous service publication missing: {marker}')
if "const APP_RELEASE_LABEL = '20.1 Final';" not in app:
    fail('Final release label is missing from js/app.js')
if 'id="login-version-badge">v20.1 Final</span>' not in index:
    fail('login version badge does not expose Final channel')

if '#guest-login{position:relative' not in welcome_styles or '#guest-login .i18n-text{display:block;width:100%;text-align:center' not in welcome_styles or '#guest-login>svg{position:absolute;right:19px' not in welcome_styles or '#guest-login{position:relative' not in styles or '#guest-login .i18n-text{display:block;width:100%;text-align:center' not in styles or '#guest-login>svg{position:absolute;right:19px' not in styles:
    fail('guest-login stable first-paint alignment missing')
if 'data-i18n-key="home.guest_login.continue_as_guest">Continua come ospite</span>' not in index:
    fail('guest-login first-paint fallback copy missing')
readme = text('readme.md')
if '# MeteoNexa 20.1 — Final' not in readme:
    fail('README does not document the 20.1 Final release')

print(f'Final release smoke: PASS (pre-app ESM {pre_app_bytes} bytes; blocking CSS {blocking_bytes} bytes; suite ESM deferred {len(suite)} modules)')
