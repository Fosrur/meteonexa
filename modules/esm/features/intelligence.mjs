const PROVIDES = Object.freeze(['intelligenceV2']);
export const dependencies = Object.freeze(['alerts', 'intelligence', 'core']);

export function install(services, host = globalThis) {
    return services.installModule({
        services,
        host,
        provides: PROVIDES,
        dependencies,
        factory(window, deps, provided) {
        (() => {
            const core = deps.core;
            const q = (selector, root = document) => root.querySelector(selector);
            const safe = value => String(value ?? '').replace(/[&<>'"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch]));
            const text = (key, params = {}) => window.meteonexaText?.(key, params) || key;
            const localTime = value => {
                try {
                    return new Intl.DateTimeFormat(document.documentElement.lang || navigator.language || 'it', {
                        hour: '2-digit', minute: '2-digit'
                    }).format(new Date(value));
                } catch { return '--'; }
            };
        
            function renderNowcast(v2 = {}, v4 = {}) {
                const root = q('#intel-nowcast-content');
                if (!root) return;
                if (!v2.available) {
                    root.innerHTML = `<div class="intel-empty">${safe(text('intel.nowcast.unavailable'))}</div>`;
                    return;
                }
                if (!v4.available) {
                    const start = v2.rainStartAt ? localTime(v2.rainStartAt) : text('intel.nowcast.no_start');
                    const end = v2.rainEndAt ? localTime(v2.rainEndAt) : text('intel.nowcast.no_end');
                    const bars = (v2.timeline || []).map(row => {
                        const minute = Number(row.minute || 0), probability = Number(row.precipitationProbability || 0);
                        const pointTitle = text('intel.nowcast.point_title', { minutes: minute, probability });
                        return `<span class="intel-nowcast-bar" style="--p:${Math.max(2, Math.min(100, probability))}" title="${safe(pointTitle)}" aria-label="${safe(pointTitle)}"><i></i><small>${minute % 15 === 0 ? `+${minute}` : ''}</small></span>`;
                    }).join('');
                    root.innerHTML = `<div class="intel-nowcast-summary"><div><small>${safe(text('intel.nowcast.start'))}</small><strong>${safe(start)}</strong></div><div><small>${safe(text('intel.nowcast.end'))}</small><strong>${safe(end)}</strong></div><div><small>${safe(text('intel.nowcast.confidence'))}</small><strong>${Math.round(Number(v2.confidence || 0))}%</strong></div></div><div class="intel-nowcast-chart">${bars}</div>`;
                    return;
                }
                const generated = Date.parse(v4.generatedAt || v2.generatedAt || new Date().toISOString());
                const atMinute = minute => Number.isFinite(generated) ? localTime(new Date(generated + Number(minute || 0) * 60000).toISOString()) : `+${minute}`;
                const arrival = v4.arrivalDistribution || {};
                const range = Array.isArray(arrival.mostLikelyRangeMinutes) ? arrival.mostLikelyRangeMinutes : null;
                const arrivalLabel = arrival.available && range ? text('probnow.nowcast.arrival_range', { from: atMinute(range[0]), to: atMinute(range[1]) }) : text('probnow.nowcast.arrival_unavailable');
                const by = minutes => {
                    const rows = Array.isArray(arrival.byMinutes) ? arrival.byMinutes : [];
                    const row = rows.find(item => Number(item.minute) >= minutes) || rows.at(-1);
                    return row ? Math.round(Number(row.probabilityPct || 0)) : null;
                };
                const thresholds = [15,30,45,60].map(minutes => {
                    const probability = by(minutes);
                    return probability == null ? '' : `<span>${safe(text('probnow.nowcast.arrival_by', { probability, minutes }))}</span>`;
                }).filter(Boolean).join('');
                const hail=v4.hailPotential||{};
                const authority=text(v4.radarAuthority==='radar3-verified'?'probnow.nowcast.radar_verified':'probnow.nowcast.radar_guarded');
                const sourceLabels={radar3:'verified.source.radarCell',radar2:'verified.source.radarMotion',lightning:'verified.source.lightning',satellite:'verified.source.satellite',observations:'probnow.source.observations',models:'verified.source.nwp',official:'verified.source.official'};
                const sources=(v4.sources||[]).filter(row=>row.available).map(row=>`<span>${safe(text(sourceLabels[row.id]||'probnow.source.observations'))}</span>`).join('');
                const rows=Array.isArray(v4.timeline)?v4.timeline:[];
                const bars=rows.map(row=>{
                    const minute=Number(row.minute||0), probability=Math.round(Number(row.rainProbabilityPct||0)), low=Math.round(Number(row.rainLowPct||0)), high=Math.round(Number(row.rainHighPct||0));
                    const title=text('probnow.nowcast.point_label',{minutes:minute,probability,low,high});
                    return `<span class="intel-nowcast-bar probnow-nowcast-bar" style="--p:${Math.max(2,Math.min(100,probability))};--lo:${Math.max(0,Math.min(100,low))};--range:${Math.max(2,Math.min(100,high)-Math.max(0,low))}" title="${safe(title)}" aria-label="${safe(title)}"><i></i><small>${minute%15===0?`+${minute}`:''}</small></span>`;
                }).join('');
                const endProb=v4.rainEndBy90Pct==null?text('probnow.nowcast.not_applicable'):`${Math.round(Number(v4.rainEndBy90Pct))}%`;
                root.innerHTML=`<div class="intel-nowcast-summary probnow-nowcast-summary"><div><small>${safe(text('probnow.nowcast.arrival'))}</small><strong>${safe(arrivalLabel)}</strong></div><div><small>${safe(text('probnow.nowcast.rain_peak'))}</small><strong>${Math.round(Number(v4.peakRainProbabilityPct||0))}%</strong></div><div><small>${safe(text('probnow.nowcast.storm_peak'))}</small><strong>${Math.round(Number(v4.peakStormProbabilityPct||0))}%</strong></div><div><small>${safe(text('probnow.nowcast.confidence'))}</small><strong>${Math.round(Number(v4.confidence||0))}%</strong></div></div><div class="probnow-nowcast-prob-grid"><div><small>${safe(text('probnow.nowcast.gust'))}</small><strong>${Math.round(Number(v4.peakGustProbabilityPct||0))}%</strong></div><div><small>${safe(text('probnow.nowcast.hail'))}</small><strong>${hail.available?`${Math.round(Number(hail.score||0))}/100`:safe(text('probnow.nowcast.not_available'))}</strong></div><div><small>${safe(text('probnow.nowcast.rain_end'))}</small><strong>${safe(endProb)}</strong></div></div>${thresholds?`<div class="probnow-arrival-strip"><strong>${safe(text('probnow.nowcast.arrival_distribution'))}</strong><div>${thresholds}</div></div>`:''}<div class="probnow-nowcast-evidence"><span>${safe(authority)}</span>${sources}</div><div class="intel-nowcast-chart" role="img" aria-label="${safe(text('probnow.nowcast.chart_label'))}">${bars}</div><p class="probnow-nowcast-note">${safe(text('probnow.nowcast.disclaimer'))}</p>`;
            }
        
            function renderConfidence(v2 = {}, freshness = {}) {
                const root = q('#intel-confidence-content');
                if (!root) return;
                if (!v2.available) {
                    root.innerHTML = `<div class="intel-empty">${safe(text('intel.confidence.unavailable'))}</div>`;
                    return;
                }
                const rows = (v2.timeline || []).slice(0, 24);
                const changeLabel = v2.forecastChange?.material ? text('intel.change.material') : text('intel.change.stable');
                const radar=freshness.radarAgeMinutes==null?text('trust.fresh.radar_unknown'):text('trust.fresh.radar',{minutes:Math.max(0,Math.round(Number(freshness.radarAgeMinutes)))});
                const aifs=freshness.aifsFetchAgeMinutes==null?text('trust.fresh.aifs_unknown'):(freshness.aifsRunAgeMinutes==null?text('trust.fresh.aifs',{minutes:Math.max(0,Math.round(Number(freshness.aifsFetchAgeMinutes)))}):text(freshness.aifsRunTimeEstimated?'trust.fresh.aifs_cycle_estimated':'trust.fresh.aifs_cycle',{run:Math.max(0,Math.round(Number(freshness.aifsRunAgeMinutes))),fetch:Math.max(0,Math.round(Number(freshness.aifsFetchAgeMinutes)))}));
                const models=text('trust.fresh.models',{count:Number(freshness.modelsFresh||0),expected:Number(freshness.modelsExpected||freshness.modelsFresh||0)});
                root.innerHTML = `<div class="intel-confidence-head"><strong>${Math.round(Number(v2.score || 0))}/100</strong><span>${safe(changeLabel)}</span></div><div class="intel-confidence-timeline" role="img" aria-label="${safe(text('intel.confidence.chart_label'))}">${rows.map((row, index) => {
                    const score = Math.round(Number(row.score || 0));
                    const time = localTime(row.time);
                    const pointLabel = text('intel.confidence.point_label', { time, score });
                    return `<div class="intel-confidence-hour" style="--score:${Math.max(5, Math.min(100, score))}" title="${safe(pointLabel)}" aria-label="${safe(pointLabel)}"><i></i><small>${index % 3 === 0 ? safe(time) : ''}</small><b>${score}</b></div>`;
                }).join('')}</div><div class="intel-confidence-meta"><span>${safe(text('intel.confidence.freshness', { value: Number(v2.freshnessScore || 0) }))}</span><span>${safe(text('intel.confidence.samples', { value: Number(v2.verifiedSamples || 0) }))}</span></div><div class="trust-freshness-strip"><span>${safe(radar)}</span><span>${safe(aifs)}</span><span>${safe(models)}</span></div>`;
            }
        
            function renderTwin(twin = {}) {
                const root = q('#intel-twin-content');
                if (!root) return;
                if (!twin.available) {
                    root.innerHTML = `<div class="intel-empty">${safe(text('intel.twin.unavailable'))}</div>`;
                    return;
                }
                root.innerHTML = `<div class="intel-twin-grid">${(twin.profiles || []).slice(0, 10).map(row => {
                    const activity = text(`intel.twin.activity.${row.activity}`);
                    const windowLabel = row.bestStart
                        ? text('intel.twin.best_window', { from: localTime(row.bestStart), to: localTime(row.bestEnd) })
                        : text('intel.twin.learning');
                    return `<article data-status="${safe(row.status || 'learning')}"><div><strong>${safe(activity)}</strong><span>${Math.round(Number(row.score || 0))}/100</span></div><small>${safe(windowLabel)}</small></article>`;
                }).join('')}</div>`;
            }
        
            function renderSunCloud(payload = {}) {
                const root = q('#intel-sun-cloud-content');
                if (!root) return;
                if (!payload.available) {
                    root.innerHTML = `<div class="intel-empty">${safe(text('suncloud.unavailable'))}</div>`;
                    return;
                }
                const best = payload.bestWindow || null;
                const windows = Array.isArray(payload.windows) ? payload.windows : [];
                const bestMarkup = best ? `<div class="suncloud-best"><div><small>${safe(text('suncloud.best'))}</small><strong>${safe(text('suncloud.window',{from:localTime(best.startsAt),to:localTime(best.endsAt)}))}</strong></div><span>${Math.round(Number(best.score||0))}/100</span></div><div class="suncloud-metrics"><div><small>${safe(text('suncloud.cloud'))}</small><strong>${Math.round(Number(best.cloudPct||0))}%</strong></div><div><small>${safe(text('suncloud.rain'))}</small><strong>${Math.round(Number(best.rainProbabilityPct||0))}%</strong></div><div><small>${safe(text('suncloud.radiation'))}</small><strong>${Math.round(Number(best.shortwaveWm2||0))} W/m²</strong></div><div><small>${safe(text('suncloud.confidence'))}</small><strong>${Math.round(Number(best.confidence||0))}%</strong></div></div>` : `<div class="intel-empty">${safe(text('suncloud.none'))}</div>`;
                const list = windows.slice(0,4).map(row=>`<article><time>${safe(text('suncloud.window',{from:localTime(row.startsAt),to:localTime(row.endsAt)}))}</time><div><strong>${Math.round(Number(row.score||0))}/100</strong><small>${safe(text('suncloud.row',{cloud:Math.round(Number(row.cloudPct||0)),rain:Math.round(Number(row.rainProbabilityPct||0)),confidence:Math.round(Number(row.confidence||0))}))}</small></div></article>`).join('');
                const satellite = payload.satelliteAttenuationPct == null ? text('suncloud.satellite_unavailable') : text('suncloud.satellite',{value:Math.round(Number(payload.satelliteAttenuationPct||0))});
                root.innerHTML = `${bestMarkup}${list?`<div class="suncloud-list">${list}</div>`:''}<p class="suncloud-note">${safe(satellite)} · ${safe(text('suncloud.method'))}</p>`;
            }

            function renderModels(models = {}) {
                const root = q('#intel-ai-models-content');
                if (!root) return;
                const rows = Array.isArray(models.models) ? models.models : [];
                root.innerHTML = rows.length ? rows.map(row => {
                    const meta = text('intel.model.meta', {
                        provider: String(row.provider || ''),
                        gateway: String(row.gateway || ''),
                        resolution: String(row.resolution || ''),
                        step: String(row.temporalResolution || '')
                    });
                    return `<div class="intel-model-row"><div><strong>${safe(row.label)}</strong><small>${safe(meta)}</small></div><span class="soft-badge">${safe(row.available ? text('intel.model.live') : text('intel.model.unavailable'))}</span></div>`;
                }).join('') : `<div class="intel-empty">${safe(text('intel.model.unavailable'))}</div>`;
            }
        
            function render(data) {
                if (!data) return;
                core?.patch?.('intelligence', {
                    raw: data,
                    nowcastV2: data.nowcastV2 || null,
                    nowcastV4: data.nowcastV4 || null,
                    confidenceV2: data.confidenceV2 || null,
                    twin: data.personalWeatherTwin || null,
                    sunCloudWindow: data.sunCloudWindow || null
                }, 'intelligence/v2');
                const nowcastPayload={...(data.nowcastV2||{}),convectiveRisk:data.convectiveRiskV3||null};
                renderNowcast(nowcastPayload, data.nowcastV4 || {});
                renderConfidence(data.confidenceV2 || {}, data.freshnessTrust || {});
                renderTwin(data.personalWeatherTwin || {});
                renderModels(data.aiWeatherModels || {});
                renderSunCloud(data.sunCloudWindow || {});
                deps.alerts?.publish?.({
                    severe: data.analysis?.events || [],
                    official: data.official?.relevant || [],
                    unread: core?.getState?.().alerts?.unread || 0
                });
            }
        
            document.addEventListener('meteonexa:intelligence-data', event => render(event.detail?.data));
            document.addEventListener('meteonexa:ready', () => setTimeout(() => render(deps.intelligence?.current?.()), 500));
            provided.intelligenceV2 = Object.freeze({
                render,
                current: () => core?.getState?.().intelligence || {}
            });
        })();
        }
    });
}

export const serviceNames = PROVIDES;
