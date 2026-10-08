import { createForecastFusion } from './forecast-fusion.mjs';
import { createHistoryExplorer } from './history-explorer.mjs';

export const serviceNames = Object.freeze(['forecastHistory']);
export const dependencies = Object.freeze([]);

function factory(window, deps, provided) {
    void window;
    void deps;
    provided.forecastHistory = Object.freeze({
        create(context) {
            const fusion = createForecastFusion(context);
            const history = createHistoryExplorer(context);
            return Object.freeze({ ...fusion, ...history });
        }
    });
}

export function install(services) {
    return services.installModule({ provides: serviceNames, dependencies, factory });
}
