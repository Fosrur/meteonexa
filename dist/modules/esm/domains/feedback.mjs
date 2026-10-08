const PROVIDES = Object.freeze(['feedback']);
export const dependencies = Object.freeze(['core']);

export function install(services, host = globalThis) {
    return services.installModule({
        services,
        host,
        provides: PROVIDES,
        dependencies,
        factory(window, deps, provided) {
        (() => {
            const core = deps.core;
            function begin() { core?.patch?.('feedback', { sending: true, lastResult: null }, 'feedback/sending'); }
            function done(result) { core?.patch?.('feedback', { sending: false, lastResult: result || { ok: true } }, 'feedback/sent'); }
            function fail(error) { core?.patch?.('feedback', { sending: false, lastResult: { ok: false, error: String(error?.message || error || '') } }, 'feedback/failed'); }
            provided.feedback = Object.freeze({ begin, done, fail, current: () => core?.getState?.().feedback || {} });
        })();
        }
    });
}

export const serviceNames = PROVIDES;
