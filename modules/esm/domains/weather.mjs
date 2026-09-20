const PROVIDES = Object.freeze(['weather']);
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
            function publish(weather, location, air = null, meta = {}) {
                core?.patch?.('weather', {
                    data: weather || null,
                    air: air || null,
                    location: location ? { ...location } : null,
                    source: String(weather?.source || meta.source || 'none'),
                    fetchedAt: Number(weather?.fetchedAt || meta.fetchedAt || 0),
                    lastError: meta.error ? String(meta.error) : ''
                }, 'weather/publish');
                core?.events?.emit?.('weather-updated', { weather, location, air, meta });
            }
            const current = () => core?.getState?.().weather || {};
            const isFresh = (maxAgeMs = 10 * 60 * 1000) => {
                const value = current();
                return value.source === 'live' && value.fetchedAt > 0 && Date.now() - value.fetchedAt <= maxAgeMs;
            };
            provided.weather = Object.freeze({ publish, current, isFresh });
        })();
        }
    });
}

export const serviceNames = PROVIDES;
