const PROVIDES = Object.freeze(['radar']);
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
            let guardTimer = 0;
            function begin(reason = 'load', timeoutMs = 14000) {
                clearTimeout(guardTimer);
                core?.patch?.('radar', { loading: true, loadReason: reason, error: '' }, 'radar/loading');
                guardTimer = window.setTimeout(() => {
                    core?.patch?.('radar', { loading: false, error: 'RADAR_LOAD_TIMEOUT' }, 'radar/timeout');
                    core?.events?.emit?.('radar-timeout', { reason });
                }, Math.max(3000, timeoutMs));
            }
            function complete(meta = {}) {
                clearTimeout(guardTimer);
                core?.patch?.('radar', { loading: false, loaded: true, error: '', ...meta }, 'radar/loaded');
            }
            function fail(error) {
                clearTimeout(guardTimer);
                core?.patch?.('radar', { loading: false, error: String(error?.code || error?.message || error || 'RADAR_FAILED') }, 'radar/failed');
            }
            function publishMotion(motion) { core?.patch?.('radar', { motion: motion || null }, 'radar/motion'); }
            provided.radar = Object.freeze({ begin, complete, fail, publishMotion, state: () => core?.getState?.().radar || {} });
        })();
        }
    });
}

export const serviceNames = PROVIDES;
