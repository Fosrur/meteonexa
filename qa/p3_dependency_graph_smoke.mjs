#!/usr/bin/env node
import { SERVICE_GLOBALS, createServiceRegistry } from '../modules/esm/core/service-registry.mjs';

const modules = [
  '../modules/esm/domains/visualization.mjs','../modules/esm/domains/forecast-history.mjs','../modules/esm/domains/model-intelligence.mjs','../modules/esm/domains/device-sessions.mjs','../modules/esm/domains/app-lifecycle.mjs','../modules/esm/domains/suite-support.mjs','../modules/esm/domains/app-utilities.mjs','../modules/esm/domains/suite-assistant.mjs','../modules/esm/domains/suite-integrations.mjs','../modules/esm/domains/locations.mjs','../modules/esm/domains/navigation.mjs','../modules/esm/domains/notifications.mjs',
  '../modules/esm/domains/weather.mjs','../modules/esm/domains/radar.mjs','../modules/esm/domains/alerts.mjs','../modules/esm/domains/auth.mjs',
  '../modules/esm/domains/auth-flow.mjs','../modules/esm/domains/account.mjs','../modules/esm/domains/radar-motion.mjs',
  '../modules/esm/domains/radar-controller.mjs','../modules/esm/domains/privacy.mjs','../modules/esm/domains/feedback.mjs','../modules/esm/domains/ai.mjs',
  '../modules/esm/features/route-weather.mjs','../modules/esm/features/copilot.mjs','../modules/esm/features/intelligence.mjs',
  '../modules/esm/features/decision-timeline.mjs','../modules/esm/features/watch-plan.mjs'
];
const assert=(v,m)=>{if(!v)throw new Error(`P3_DEPENDENCY_GRAPH:${m}`);console.log(`PASS ${m}`);};
const known = new Set(Object.keys(SERVICE_GLOBALS));
const providers = new Map();
for (const path of modules) {
  const mod = await import(path);
  assert(Array.isArray(mod.serviceNames) && Object.isFrozen(mod.serviceNames), `${path} immutable provides`);
  assert(Array.isArray(mod.dependencies) && Object.isFrozen(mod.dependencies), `${path} immutable dependencies`);
  for (const name of [...mod.serviceNames, ...mod.dependencies]) assert(known.has(name), `${path} known service ${name}`);
  for (const name of mod.serviceNames) {
    assert(!providers.has(name), `${name} has a single ESM provider`);
    providers.set(name, path);
  }
  assert(!mod.dependencies.some(name => mod.serviceNames.includes(name)), `${path} has no self dependency`);
}
assert(providers.size >= 28, 'domain/feature graph exposes expected services');

const host = { document: { dispatchEvent(){} } };
const registry = createServiceRegistry(host);
assert(typeof registry.installModule === 'function', 'registry exposes centralized installModule');
assert(typeof registry.publish === 'function' && typeof registry.get === 'function', 'registry service contract intact');
console.log(`P3 dependency graph PASS (${modules.length} modules, ${providers.size} provided services)`);
