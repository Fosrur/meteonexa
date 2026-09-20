#!/usr/bin/env node
import { readdir, readFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(fileURLToPath(new URL('..', import.meta.url)));
const syntaxOnly = process.argv.includes('--syntax-only');
const ignored = new Set(['dist', 'node_modules']);

async function walk(dir) {
    const out = [];
    for (const entry of await readdir(dir, { withFileTypes: true })) {
        if (ignored.has(entry.name)) continue;
        const path = resolve(dir, entry.name);
        if (entry.isDirectory()) out.push(...await walk(path));
        else if (entry.isFile() && (entry.name.endsWith('.js') || entry.name.endsWith('.mjs'))) out.push(path);
    }
    return out;
}

const files = await walk(ROOT);
const projectRelative = file => relative(ROOT, file).replaceAll('\\', '/');
let failed = false;
const fail = message => { failed = true; console.error(`FAIL ${message}`); };
const pass = message => console.log(`PASS ${message}`);

for (const file of files) {
    const check = spawnSync(process.execPath, ['--check', file], { stdio: 'ignore' });
    if (check.status !== 0) fail(`syntax ${projectRelative(file)}`);
}
if (!failed) pass(`JavaScript syntax (${files.length} files)`);
if (syntaxOnly) process.exit(failed ? 1 : 0);

const sources = new Map();
for (const file of files) sources.set(projectRelative(file), await readFile(file, 'utf8'));
const runtimeSources = new Map([...sources].filter(([name]) => name !== 'tools/static-analysis.mjs'));

const dangerous = [
    [/\beval\s*\(/, 'eval()'],
    [/\bnew\s+Function\s*\(/, 'new Function()'],
    [/\bdocument\.write\s*\(/, 'document.write()']
];
for (const [pattern, label] of dangerous) {
    const offenders = [...runtimeSources].filter(([, source]) => pattern.test(source)).map(([name]) => name);
    if (offenders.length) fail(`${label} forbidden: ${offenders.join(', ')}`);
    else pass(`${label} absent`);
}

const app = sources.get('js/app.js') || '';
const directAppGlobals = [...app.matchAll(/window\.MeteoNexa[A-Za-z0-9_]*/g)].map(match => match[0]);
const allowedAppGlobals = new Set(['window.MeteoNexaServices']);
const unexpectedAppGlobals = [...new Set(directAppGlobals.filter(name => !allowedAppGlobals.has(name)))];
if (unexpectedAppGlobals.length) fail(`js/app.js bypasses service registry: ${unexpectedAppGlobals.join(', ')}`);
else pass('js/app.js service dependencies go through MeteoNexaServices');

for (const name of ['js/advanced.js', 'js/suite.js']) {
    const source = sources.get(name) || '';
    if (!/SERVICES\.require\('runtimeApi'\)\.get\(\)/.test(source)) fail(`${name} missing Runtime API registry contract`);
    else pass(`${name} uses Runtime API through service registry`);
}

for (const name of ['js/advanced.js','js/suite.js','js/weather-intelligence.js','js/custom-controls.js']) {
    const source = sources.get(name) || '';
    const named = [...source.matchAll(/window\.(MeteoNexa[A-Za-z0-9_]*)/g)].map(match => match[1]).filter(value => value !== 'MeteoNexaServices');
    if (named.length) fail(`${name} bypasses registry with compatibility globals: ${[...new Set(named)].join(', ')}`);
    else pass(`${name} uses the single MeteoNexaServices global boundary`);
}

const registry = sources.get('modules/esm/core/service-registry.mjs') || '';
if (!/export function createServiceRegistry/.test(registry) || !/export function installServiceRegistry/.test(registry) || !/METEONEXA_SERVICE_NOT_READY/.test(registry)) fail('ESM service registry contract incomplete');
else pass('ESM service registry contract present');
if (!/const published = new Map\(\)/.test(registry) || !/publish\(name, service, \{ legacy = false \}/.test(registry) || !/exposeLegacy/.test(registry)) fail('service registry is not an internal registry with explicit legacy exposure');
else pass('service registry stores services internally and gates legacy exposure');

const runtimeApi = sources.get('modules/esm/core/runtime-api.mjs') || '';
if (!/export function createRuntimeApiHost/.test(runtimeApi) || !/export function installRuntimeApi/.test(runtimeApi) || !/METEONEXA_RUNTIME_API_MISSING/.test(runtimeApi)) fail('ESM runtime API contract incomplete');
else pass('ESM runtime API contract present');

const bootstrap = sources.get('modules/esm/bootstrap.mjs') || '';
const requiredCoreEsm = [
    'modules/esm/core/service-registry.mjs', 'modules/esm/core/runtime-api.mjs',
    'modules/esm/core/store.mjs', 'modules/esm/core/runtime-state.mjs', 'modules/esm/core/tooltips.mjs',
    'modules/esm/core/weather-utils.mjs', 'modules/esm/core/i18n-preferences.mjs'
];
const requiredDomainEsm = ['modules/esm/domains/visualization.mjs','modules/esm/domains/forecast-history.mjs','modules/esm/domains/model-intelligence.mjs','modules/esm/domains/device-sessions.mjs','modules/esm/domains/app-lifecycle.mjs','modules/esm/domains/suite-support.mjs','modules/esm/domains/app-utilities.mjs','modules/esm/domains/suite-assistant.mjs','modules/esm/domains/suite-integrations.mjs','modules/esm/domains/locations.mjs','modules/esm/domains/navigation.mjs','modules/esm/domains/notifications.mjs','modules/esm/domains/weather.mjs','modules/esm/domains/radar.mjs','modules/esm/domains/alerts.mjs','modules/esm/domains/auth.mjs','modules/esm/domains/auth-flow.mjs','modules/esm/domains/account.mjs','modules/esm/domains/radar-motion.mjs','modules/esm/domains/radar-controller.mjs','modules/esm/domains/privacy.mjs','modules/esm/domains/feedback.mjs','modules/esm/domains/ai.mjs'];
const requiredFeatureEsm = ['modules/esm/features/route-weather.mjs','modules/esm/features/copilot.mjs','modules/esm/features/intelligence.mjs','modules/esm/features/decision-timeline.mjs','modules/esm/features/watch-plan.mjs'];
if (!/CORE_ESM\.map\(logical => import\(assetUrl\(logical\)\)\)/.test(bootstrap) || !requiredCoreEsm.every(name => bootstrap.includes(name)) || !requiredDomainEsm.every(name => bootstrap.includes(name)) || !requiredFeatureEsm.every(name => bootstrap.includes(name)) || !/importInstall/.test(bootstrap) || !/PRE_APP_ESM/.test(bootstrap) || !/POST_APP_ESM/.test(bootstrap) || !/METEONEXA_ESM_BOOTSTRAP_FAILED/.test(bootstrap)) fail('ESM bootstrap contract incomplete');
else pass('ESM bootstrap contract present');

const coreContracts = [
    ['modules/esm/core/store.mjs', /export function installCore/, /export const coreService/],
    ['modules/esm/core/runtime-state.mjs', /export function installRuntimeState/, /export function createRuntimeState/],
    ['modules/esm/core/tooltips.mjs', /export function installTooltips/, /export const tooltipsService/],
    ['modules/esm/core/weather-utils.mjs', /export function installWeatherUtils/, /export function create/],
    ['modules/esm/core/i18n-preferences.mjs', /export function installI18nPreferences/, /export function create/]
];
for (const [name, first, second] of coreContracts) {
    const source = sources.get(name) || '';
    if (!first.test(source) || !second.test(source)) fail(`ESM core contract incomplete: ${name}`);
    else pass(`ESM core contract present: ${name}`);
}

const implicitAdvanced = ['typeof state', 'loadJSON(', 'saveJSON(', 'loadWeather(', 'updateThreshold(', 'withLoader('];
const advanced = sources.get('js/advanced.js') || '';
if (!/const APP_RUNTIME =/.test(advanced)) fail('js/advanced.js has no explicit runtime binding');
if (!/const APP_RUNTIME =/.test(sources.get('js/suite.js') || '')) fail('js/suite.js has no explicit runtime binding');

const packageJson = JSON.parse(await readFile(resolve(ROOT, 'package.json'), 'utf8'));
for (const script of ['check', 'lint:eslint', 'bundle:verify', 'check:full', 'build:assets', 'build', 'qa']) {
    if (!packageJson.scripts?.[script]) fail(`package.json missing script ${script}`);
}

if (packageJson.devDependencies?.esbuild !== '0.28.2') fail('package.json must pin esbuild 0.28.2');
else pass('esbuild version pinned');
if (packageJson.devDependencies?.eslint !== '10.10.0') fail('package.json must pin ESLint 10.10.0');
else pass('ESLint version pinned');
const legacyBoundaryFiles = [
    'modules/core/service-registry.js', 'modules/core/runtime-api.js',
    'modules/core/store.js', 'modules/core/runtime-state.js', 'modules/core/tooltips.js',
    'modules/core/weather-utils.js', 'modules/core/i18n-preferences.js',
    'modules/domains/locations.js','modules/domains/navigation.js','modules/domains/notifications.js','modules/domains/weather.js','modules/domains/radar.js','modules/domains/alerts.js','modules/domains/auth.js','modules/domains/auth-flow.js','modules/domains/account.js','modules/domains/radar-motion.js','modules/domains/radar-controller.js','modules/domains/privacy.js','modules/domains/feedback.js','modules/domains/ai.js',
    'modules/features/route-weather.js','modules/features/copilot.js','modules/features/intelligence.js','modules/features/decision-timeline.js','modules/features/watch-plan.js'
];
for (const name of legacyBoundaryFiles) {
    try { await readFile(resolve(ROOT, name), 'utf8'); fail(`legacy P2 boundary still present: ${name}`); }
    catch (error) { if (error?.code !== 'ENOENT') throw error; }
}
const indexHtml = await readFile(resolve(ROOT, 'index.html'), 'utf8');
const assetManifest = JSON.parse(await readFile(resolve(ROOT, 'asset-manifest.json'), 'utf8'));
const bootstrapPublic = String(assetManifest['modules/esm/bootstrap.mjs'] || '').replace(/^\.\//, '');
if (!/src=["']js\/asset-manifest\.js["']/.test(indexHtml) || !bootstrapPublic || !indexHtml.includes(`type="module" src="${bootstrapPublic}"`)) fail('index.html missing native ESM bootstrap');
else pass('index.html native ESM bootstrap present');

if (!failed) pass('P2 static-analysis contract');
process.exit(failed ? 1 : 0);
