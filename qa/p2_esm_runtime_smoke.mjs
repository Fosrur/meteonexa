#!/usr/bin/env node
import { createServiceRegistry, installServiceRegistry, SERVICE_GLOBALS } from '../modules/esm/core/service-registry.mjs';
import { createRuntimeApiHost, installRuntimeApi, REQUIRED_RUNTIME_METHODS } from '../modules/esm/core/runtime-api.mjs';

const assert = (condition, message) => {
    if (!condition) throw new Error(`P2_ESM_SMOKE:${message}`);
    console.log(`PASS ${message}`);
};

const host = {};
const registry = createServiceRegistry(host);
assert(Object.isFrozen(registry), 'service registry immutable');
assert(registry.names.includes('runtimeApi') && SERVICE_GLOBALS.runtimeApi === 'MeteoNexaRuntimeAPI', 'service registry closed map');
let unknownRejected = false;
try { registry.get('not-a-service'); } catch (error) { unknownRejected = String(error.message).startsWith('METEONEXA_SERVICE_UNKNOWN:'); }
assert(unknownRejected, 'unknown service rejected');
const sample = Object.freeze({ ok: true });
registry.publish('core', sample);
assert(registry.require('core') === sample, 'published service resolved from internal registry');
assert(host.MeteoNexaCore === undefined, 'publish does not create compatibility global by default');
registry.exposeLegacy('core');
assert(host.MeteoNexaCore === sample, 'legacy exposure is explicit');

const installedRegistry = installServiceRegistry(host);
assert(installedRegistry === host.MeteoNexaServices, 'single service-registry global boundary installed');

const runtime = createRuntimeApiHost({ documentRef: null });
let notReadyRejected = false;
try { runtime.get(); } catch (error) { notReadyRejected = error.message === 'METEONEXA_RUNTIME_API_NOT_READY'; }
assert(notReadyRejected, 'runtime API rejects reads before publish');
let missingRejected = false;
try { runtime.publish({ getState() {} }); } catch (error) { missingRejected = String(error.message).startsWith('METEONEXA_RUNTIME_API_MISSING:'); }
assert(missingRejected, 'runtime API validates required methods');
const complete = Object.fromEntries(REQUIRED_RUNTIME_METHODS.map(name => [name, () => name]));
const published = runtime.publish({ build: '20.1', ...complete });
assert(Object.isFrozen(published), 'runtime API payload immutable');
assert(runtime.get() === published, 'runtime API returns authoritative payload');
const installedRuntime = installRuntimeApi(host, { documentRef: null }, installedRegistry);
assert(installedRegistry.get('runtimeApi') === installedRuntime, 'runtime API installed in service registry');
assert(host.MeteoNexaRuntimeAPI === undefined, 'runtime API no longer leaks a compatibility global');
console.log('P2 native ESM runtime PASS');
