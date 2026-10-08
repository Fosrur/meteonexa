const PROVIDES = Object.freeze(['routeWeather']);
export const dependencies = Object.freeze(['core']);

export function install(services, host = globalThis) {
    return services.installModule({
        services,
        host,
        provides: PROVIDES,
        dependencies,
        factory(window, deps, provided) {
        (() => {
            const core=deps.core;
            function summarize(route){if(!route||!Array.isArray(route.points)||!route.points.length)return null;const rows=route.points;const maxRisk=Math.max(...rows.map(r=>Number(r.score||0)));const critical=rows.reduce((best,row)=>Number(row.score||0)>Number(best?.score||-1)?row:best,null);const crossSection=rows.map((row,index)=>({index,ratio:Number(row.ratio||0),at:row.at instanceof Date?row.at.toISOString():row.at,risk:Math.round(Number(row.score||0)),rain:Math.round(Number(row.rain||0)),gust:Math.round(Number(row.gust||0)),crosswind:Math.round(Number(row.crosswind||0)),visibility:Number(row.visibility||0),ice:Boolean(row.ice),thunder:Boolean(row.thunder)}));return{sampleCount:rows.length,maxRisk:Math.round(maxRisk),critical,crossSection,bestDeparture:route.best?.at||null,selectedDeparture:route.departure||null,departureCandidates:Array.isArray(route.candidates)?route.candidates.length:0,generatedAt:new Date().toISOString()};}
            function publish(route){const summary=summarize(route);core?.patch?.('route',{raw:route||null,summary},'route/weather');core?.events?.emit?.('route-weather-updated',{route,summary});return summary;}
            provided.routeWeather=Object.freeze({publish,summarize,current:()=>core?.getState?.().route||{}});
        })();
        }
    });
}

export const serviceNames = PROVIDES;
