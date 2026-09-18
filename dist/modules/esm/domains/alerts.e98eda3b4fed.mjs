const PROVIDES = Object.freeze(['alerts']);
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
            function publish({ severe = [], official = [], unread = 0 } = {}) {
                core?.patch?.('alerts', {
                    severe: Array.isArray(severe) ? severe : [],
                    official: Array.isArray(official) ? official : [],
                    unread: Math.max(0, Number(unread || 0))
                }, 'alerts/publish');
            }
            function materialChange(change) {
                if (!change || typeof change !== 'object') return false;
                return change.material === true || Math.abs(Number(change.timeShiftMinutes || 0)) >= 30
                    || Math.abs(Number(change.rainProbabilityDelta || 0)) >= 20
                    || Math.abs(Number(change.agreementDelta || 0)) >= 20;
            }
            provided.alerts = Object.freeze({ publish, materialChange, current: () => core?.getState?.().alerts || {} });
        })();
        }
    });
}

export const serviceNames = PROVIDES;
