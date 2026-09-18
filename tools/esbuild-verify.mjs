#!/usr/bin/env node
import { build } from 'esbuild';

const entries = [
    'modules/esm/bootstrap.mjs',
    'modules/esm/core/service-registry.mjs',
    'modules/esm/core/runtime-api.mjs',
    'modules/esm/core/store.mjs',
    'modules/esm/core/runtime-state.mjs',
    'modules/esm/core/tooltips.mjs',
    'modules/esm/core/weather-utils.mjs',
    'modules/esm/core/i18n-preferences.mjs',
    'modules/esm/domains/visualization.mjs','modules/esm/domains/forecast-history.mjs','modules/esm/domains/model-intelligence.mjs','modules/esm/domains/device-sessions.mjs','modules/esm/domains/app-lifecycle.mjs','modules/esm/domains/suite-support.mjs','modules/esm/domains/app-utilities.mjs','modules/esm/domains/suite-assistant.mjs','modules/esm/domains/suite-integrations.mjs','modules/esm/domains/locations.mjs',
    'modules/esm/domains/navigation.mjs',
    'modules/esm/domains/notifications.mjs',
    'modules/esm/domains/weather.mjs',
    'modules/esm/domains/radar.mjs',
    'modules/esm/domains/alerts.mjs',
    'modules/esm/domains/auth.mjs',
    'modules/esm/domains/auth-flow.mjs',
    'modules/esm/domains/account.mjs',
    'modules/esm/domains/radar-motion.mjs',
    'modules/esm/domains/radar-controller.mjs',
    'modules/esm/domains/privacy.mjs',
    'modules/esm/domains/feedback.mjs',
    'modules/esm/domains/ai.mjs',
    'modules/esm/features/route-weather.mjs',
    'modules/esm/features/copilot.mjs',
    'modules/esm/features/intelligence.mjs',
    'modules/esm/features/decision-timeline.mjs',
    'modules/esm/features/watch-plan.mjs',
];

await build({
    entryPoints: entries,
    bundle: true,
    format: 'esm',
    platform: 'browser',
    target: ['es2022'],
    outdir: '.build/esbuild-verify',
    write: true,
    logLevel: 'warning'
});
console.log(`PASS esbuild ESM graph (${entries.length} entries)`);
