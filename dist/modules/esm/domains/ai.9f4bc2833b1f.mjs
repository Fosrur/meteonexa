const PROVIDES = Object.freeze(['ai']);
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
            function begin(mode = 'assistant') { core?.patch?.('ai', { busy: true, mode }, 'ai/request'); }
            function complete(result = {}) {
                core?.patch?.('ai', {
                    busy: false,
                    provider: String(result.provider || ''),
                    model: String(result.model || ''),
                    lastToolRun: result.toolRun || null,
                    generatedAt: result.generatedAt || new Date().toISOString()
                }, 'ai/complete');
            }
            function fail(error) { core?.patch?.('ai', { busy: false, error: String(error?.message || error || '') }, 'ai/failed'); }
            provided.ai = Object.freeze({ begin, complete, fail, current: () => core?.getState?.().ai || {} });
        })();
        }
    });
}

export const serviceNames = PROVIDES;
