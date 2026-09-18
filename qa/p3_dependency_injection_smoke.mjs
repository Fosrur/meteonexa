#!/usr/bin/env node
import fs from 'node:fs';
import { createServiceRegistry } from '../modules/esm/core/service-registry.mjs';

const modulePaths = [
  'modules/esm/domains/visualization.mjs','modules/esm/domains/forecast-history.mjs','modules/esm/domains/model-intelligence.mjs','modules/esm/domains/device-sessions.mjs','modules/esm/domains/app-lifecycle.mjs','modules/esm/domains/suite-support.mjs','modules/esm/domains/app-utilities.mjs','modules/esm/domains/suite-assistant.mjs','modules/esm/domains/suite-integrations.mjs','modules/esm/domains/locations.mjs','modules/esm/domains/navigation.mjs','modules/esm/domains/notifications.mjs',
  'modules/esm/domains/weather.mjs','modules/esm/domains/radar.mjs','modules/esm/domains/alerts.mjs','modules/esm/domains/auth.mjs',
  'modules/esm/domains/auth-flow.mjs','modules/esm/domains/account.mjs','modules/esm/domains/radar-motion.mjs',
  'modules/esm/domains/radar-controller.mjs','modules/esm/domains/privacy.mjs','modules/esm/domains/feedback.mjs','modules/esm/domains/ai.mjs',
  'modules/esm/features/route-weather.mjs','modules/esm/features/copilot.mjs','modules/esm/features/intelligence.mjs',
  'modules/esm/features/decision-timeline.mjs','modules/esm/features/watch-plan.mjs'
];
const assert=(value,message)=>{if(!value)throw new Error(`P3_DEPENDENCY_INJECTION:${message}`);console.log(`PASS ${message}`);};

for (const path of modulePaths) {
  const source = fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
  assert(!/window\.MeteoNexa[A-Za-z0-9_]*/.test(source), `${path} has no named MeteoNexa window dependency`);
  assert(!source.includes('SERVICE_KEYS'), `${path} has no compatibility service-key map`);
  assert(source.includes('factory(window, deps, provided)'), `${path} receives explicit dependency/provision views`);
}

const host = { document: { dispatchEvent(){} } };
const registry = createServiceRegistry(host);
const core = Object.freeze({ marker: 'core-service' });
registry.publish('core', core);
const installed = registry.installModule({
  provides: ['ai'],
  dependencies: ['core'],
  factory(actualHost, deps, provided) {
    assert(actualHost === host, 'module factory receives the real host only for browser/runtime APIs');
    assert(deps.core === core, 'declared dependency is injected from internal registry');
    provided.ai = Object.freeze({ marker: 'ai-service' });
  }
});
assert(installed.ai?.marker === 'ai-service', 'declared provided service is published centrally');
assert(registry.get('ai') === installed.ai, 'provided service resolves from internal registry');
assert(host.MeteoNexaAI === undefined, 'module installation does not recreate named compatibility globals');

let undeclaredRejected = false;
try {
  registry.installModule({
    provides: ['alerts'],
    dependencies: [],
    factory(_host, _deps, provided) { provided.ai = {}; }
  });
} catch (error) {
  undeclaredRejected = String(error?.message || error).includes('METEONEXA_MODULE_SERVICE_UNDECLARED');
}
assert(undeclaredRejected, 'module cannot publish an undeclared service');
console.log(`P3 dependency injection PASS (${modulePaths.length} modules)`);
