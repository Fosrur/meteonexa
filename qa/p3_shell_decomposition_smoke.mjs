#!/usr/bin/env node
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const assert = (value, message) => {
  if (!value) throw new Error(`P3_SHELL_DECOMPOSITION:${message}`);
  console.log(`PASS ${message}`);
};

const app = read('js/app.js');
const suite = read('js/suite.js');
const visualization = read('modules/esm/domains/visualization.mjs');
const suiteSupport = read('modules/esm/domains/suite-support.mjs');
const forecastHistory = read('modules/esm/domains/forecast-history.mjs');
const modelIntelligence = read('modules/esm/domains/model-intelligence.mjs');
const deviceSessions = read('modules/esm/domains/device-sessions.mjs');
const appLifecycle = read('modules/esm/domains/app-lifecycle.mjs');
const appUtilities = read('modules/esm/domains/app-utilities.mjs');
const suiteAssistant = read('modules/esm/domains/suite-assistant.mjs');
const suiteIntegrations = read('modules/esm/domains/suite-integrations.mjs');
const manifest = JSON.parse(read('asset-manifest.json'));

const appLines = app.split(/\r?\n/).length - 1;
const suiteLines = suite.split(/\r?\n/).length - 1;
assert(appLines <= 4700, `js/app.js shell remains <= 4700 lines (${appLines})`);
assert(suiteLines <= 1400, `js/suite.js shell remains <= 1400 lines (${suiteLines})`);
assert(app.includes("SERVICES.require('visualization').create"), 'js/app.js delegates visualization implementation to ESM service');
assert(app.includes("SERVICES.require('forecastHistory').create"), 'js/app.js delegates forecast/history implementation to ESM service');
assert(app.includes("SERVICES.require('modelIntelligence').create"), 'js/app.js delegates model intelligence implementation to ESM service');
assert(app.includes("SERVICES.require('deviceSessions').create"), 'js/app.js delegates device session implementation to ESM service');
assert(app.includes("SERVICES.require('appLifecycle').create"), 'js/app.js delegates lifecycle implementation to ESM service');
assert(app.includes("SERVICES.require('appUtilities').create"), 'js/app.js delegates share/bug-report utilities to ESM service');
for (const symbol of ['function canvasSetup(', 'function drawChart(', 'function weatherFXFrame(', 'function drawMotionChart(', 'function loadForecastFusion(', 'function loadHistory(', 'function loadIntelligence(', 'function reconcileRemoteDeviceRevocation(', 'function readCacheSentinel(', 'function bugReportDiagnostics(', 'async function submitBugReport(', 'async function copyTextRobust(']) {
  assert(!app.includes(symbol), `js/app.js no longer owns ${symbol}`);
}
assert(suite.includes("SERVICES.require('suiteSupport').create"), 'js/suite.js delegates common runtime support to ESM service');
assert(suite.includes("SERVICES.require('suiteAssistant').create"), 'js/suite.js delegates assistant/copilot UI to ESM service');
for (const symbol of ['function haversine(', 'function nearestTimeIndex(', 'function loadRadarArchive(', 'function refreshNetatmo(', 'function showLiveLightning(', 'function normalizeQuestion(', 'async function localAssistantAnswer(', 'async function remoteAssistantAnswer(', 'function addAssistant(']) {
  assert(!suite.includes(symbol), `js/suite.js no longer owns ${symbol}`);
}
for (const [source, name] of [[visualization,'visualization'],[suiteSupport,'suiteSupport'],[appUtilities,'appUtilities'],[suiteAssistant,'suiteAssistant'],[forecastHistory,'forecastHistory'],[modelIntelligence,'modelIntelligence'],[deviceSessions,'deviceSessions'],[appLifecycle,'appLifecycle'],[suiteIntegrations,'suiteIntegrations']]) {
  assert(source.includes(`serviceNames = Object.freeze(['${name}'])`), `${name} service declares graph contract`);
}
for (const logical of ['modules/esm/domains/visualization.mjs','modules/esm/domains/suite-support.mjs','modules/esm/domains/app-utilities.mjs','modules/esm/domains/suite-assistant.mjs','modules/esm/domains/forecast-history.mjs','modules/esm/domains/model-intelligence.mjs','modules/esm/domains/device-sessions.mjs','modules/esm/domains/app-lifecycle.mjs','modules/esm/domains/suite-integrations.mjs']) {
  assert(Boolean(manifest[logical]), `${logical} is fingerprinted`);
}
console.log(`P3 shell decomposition PASS (app ${appLines} lines, suite ${suiteLines} lines)`);
