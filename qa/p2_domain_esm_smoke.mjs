#!/usr/bin/env node
const modules = [
  '../modules/esm/domains/visualization.mjs','../modules/esm/domains/suite-support.mjs','../modules/esm/domains/app-utilities.mjs','../modules/esm/domains/suite-assistant.mjs','../modules/esm/domains/locations.mjs','../modules/esm/domains/navigation.mjs','../modules/esm/domains/notifications.mjs',
  '../modules/esm/domains/weather.mjs','../modules/esm/domains/radar.mjs','../modules/esm/domains/alerts.mjs','../modules/esm/domains/auth.mjs',
  '../modules/esm/domains/auth-flow.mjs','../modules/esm/domains/account.mjs','../modules/esm/domains/radar-motion.mjs',
  '../modules/esm/domains/radar-controller.mjs','../modules/esm/domains/privacy.mjs','../modules/esm/domains/feedback.mjs','../modules/esm/domains/ai.mjs',
  '../modules/esm/features/route-weather.mjs','../modules/esm/features/copilot.mjs','../modules/esm/features/intelligence.mjs',
  '../modules/esm/features/decision-timeline.mjs','../modules/esm/features/watch-plan.mjs'
];
const assert=(value,message)=>{if(!value)throw new Error(`P2_DOMAIN_ESM_SMOKE:${message}`);console.log(`PASS ${message}`);};
const before = new Set(Object.keys(globalThis).filter(key => key.startsWith('MeteoNexa')));
for (const path of modules) {
  const mod = await import(path);
  assert(typeof mod.install === 'function', `${path} exports install()`);
  assert(Array.isArray(mod.serviceNames) && Object.isFrozen(mod.serviceNames) && mod.serviceNames.length > 0, `${path} declares immutable service names`);
}
const after = Object.keys(globalThis).filter(key => key.startsWith('MeteoNexa') && !before.has(key));
assert(after.length === 0, 'importing domain/feature ESM has no global side effects');
console.log('P2 domain/feature native ESM PASS');
