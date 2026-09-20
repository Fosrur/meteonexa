#!/usr/bin/env node
import { fileURLToPath, pathToFileURL } from 'node:url';
import { readdir, readFile } from 'node:fs/promises';
import { resolve, relative, join } from 'node:path';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const esmRoot = join(root, 'modules', 'esm');

function makeStub(name = 'stub') {
    let proxy;
    const fn = function () { return proxy; };
    proxy = new Proxy(fn, {
        get(target, prop) {
            if (prop === Symbol.toPrimitive) return () => '';
            if (prop === Symbol.iterator) return function* () {};
            if (prop === 'then') return undefined;
            if (prop === 'length') return 0;
            if (prop === 'name') return name;
            if (prop === 'toString') return () => '';
            if (prop === 'valueOf') return () => 0;
            if (!(prop in target)) target[prop] = makeStub(`${name}.${String(prop)}`);
            return target[prop];
        },
        set(target, prop, value) { target[prop] = value; return true; },
        apply() { return proxy; },
        construct() { return proxy; }
    });
    return proxy;
}

const browserStub = makeStub('browser');
for (const name of ['document', 'navigator', 'location', 'history', 'performance', 'CustomEvent', 'Image', 'Event', 'URLSearchParams']) {
    if (!(name in globalThis)) globalThis[name] = browserStub[name];
}
try { globalThis.document.documentElement = makeStub('documentElement'); } catch {}
try { globalThis.document.readyState = 'complete'; } catch {}
try { globalThis.location.href = 'https://meteonexa.test/'; } catch {}
try { globalThis.location.hash = ''; } catch {}
try { globalThis.navigator.language = 'it-IT'; } catch {}

const host = makeStub('host');
host.METEONEXA_CONFIG = {};
host.meteonexaText = key => String(key ?? '');
host.document = globalThis.document;
host.navigator = globalThis.navigator;
host.location = globalThis.location;
host.history = globalThis.history;
host.performance = { now: () => 0 };
host.setTimeout = () => 0;
host.clearTimeout = () => {};
host.confirm = () => false;

function dependency(name) {
    if (name === 'runtimeApi') {
        return {
            get: () => ({
                getState: () => ({}),
                showToast: () => {},
                withLoader: async (_a, _b, fn) => typeof fn === 'function' ? fn() : null,
                loadWeather: async () => null,
                syncEnhancedSelect: () => {},
                updateThreshold: () => {},
                appLocale: () => 'it-IT',
                temperature: value => String(value ?? ''),
                t: key => String(key ?? '')
            })
        };
    }
    if (name === 'suiteSupport') {
        return {
            create: () => ({
                BUILD: '20.1', q: () => null, qa: () => [], n: value => Number(value) || 0,
                clamp: value => value, safe: value => String(value ?? ''), currentLocale: () => 'it-IT',
                apiMessage: value => String(value ?? ''), toast: () => {},
                loader: async (_a, _b, fn) => typeof fn === 'function' ? fn() : null,
                deviceId: () => '', isGuest: () => true, fetchJson: async () => ({}),
                localTime: () => '', directionName: () => ''
            })
        };
    }
    if (name === 'security') {
        return {
            deviceId: '',
            signedFetch: async () => ({ ok: true, json: async () => ({}) }),
            proofHeaders: () => ({})
        };
    }
    return makeStub(`deps.${name}`);
}

async function collect(dir) {
    const files = [];
    for (const item of await readdir(dir, { withFileTypes: true })) {
        const full = join(dir, item.name);
        if (item.isDirectory()) files.push(...await collect(full));
        else if (item.isFile() && item.name.endsWith('.mjs')) files.push(full);
    }
    return files;
}

const candidates = [];
for (const file of await collect(esmRoot)) {
    const source = await readFile(file, 'utf8');
    if (source.includes('services.installModule') && source.includes('export function install')) candidates.push(file);
}

const failures = [];
for (const file of candidates.sort()) {
    const module = await import(`${pathToFileURL(file).href}?publication-smoke=${Date.now()}-${Math.random()}`);
    if (typeof module.install !== 'function') continue;
    let observed = null;
    const services = {
        installModule(spec) {
            const provided = {};
            const deps = new Proxy({}, { get: (_target, prop) => dependency(String(prop)) });
            try {
                spec.factory(host, deps, provided);
            } catch (error) {
                observed = {
                    provides: [...(spec.provides || [])],
                    provided: Object.keys(provided),
                    error: String(error?.stack || error)
                };
                return {};
            }
            const expected = [...(spec.provides || [])];
            observed = {
                provides: expected,
                provided: Object.keys(provided),
                missing: expected.filter(name => provided[name] == null)
            };
            return Object.freeze(Object.fromEntries(expected.filter(name => provided[name] != null).map(name => [name, provided[name]])));
        }
    };
    try {
        await module.install(services, host);
    } catch (error) {
        observed ||= { error: String(error?.stack || error), provides: [], provided: [] };
    }
    const rel = relative(root, file).replaceAll('\\', '/');
    if (!observed || observed.error || observed.missing?.length) {
        failures.push({ file: rel, ...observed });
        continue;
    }
    console.log(`PASS ${rel}: ${observed.provides.join(',')}`);
}

if (failures.length) {
    console.error(JSON.stringify(failures, null, 2));
    throw new Error(`METEONEXA_ESM_SERVICE_PUBLICATION_FAILED:${failures.length}`);
}

console.log(`PASS ESM service publication contract (${candidates.length} modules)`);
