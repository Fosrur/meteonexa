'use strict';
(() => {
    const SERVICES = window.MeteoNexaServices;
    if (!SERVICES) throw new Error('METEONEXA_SERVICES_NOT_LOADED');
    const BUILD = '20.1';
    const SECURITY = SERVICES.get('security');
    const PROFILE_KEY = 'meteonexa_suite_alert_profile';
    const q = (selector, root = document) => root.querySelector(selector);
    const qa = (selector, root = document) => [...root.querySelectorAll(selector)];
    const text = (key, params = {}) => { const value = window.meteonexaText?.(key, params); return value || (/^[a-z][a-z0-9_-]*(?:\.[a-z0-9_-]+)+$/i.test(String(key || '')) ? '' : String(key || '')); };
    const loader = (title, copy, task, minDuration = 420) => SERVICES.get('loader')?.run ? SERVICES.get('loader').run(title, copy, task, minDuration) : task();
    const safe = value => String(value ?? '').replace(/[&<>'"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch]));
    const isGuest = () => SERVICES.get('guestAccess')?.isGuest?.() === true || state?.session?.type !== 'email' || (navigator.onLine !== false && SERVICES.get('auth')?.serverVerified?.() !== true);
    const defaultEvents = Object.freeze({ rain:true, storm:true, hail:true, wind:true, snow:true, ice:true, fog:true, heat:true, aqi:true, official:true });
    let lastLocationKey = '';
    let request = null;
    let currentData = null;
    let lastLoadedAt = 0;
    let lastRequestKey = '';

    function locationKey() { return `${Number(state?.location?.latitude || 0).toFixed(3)}:${Number(state?.location?.longitude || 0).toFixed(3)}`; }
    function locationName() { return [state?.location?.name, state?.location?.admin1].filter(Boolean).map(v => String(v)).join(', ') || text('location.selected.generic'); }
    function loadProfile() {
        try {
            const raw = JSON.parse(localStorage.getItem(PROFILE_KEY) || '{}');
            const thresholds = raw.thresholds || { rain: raw.rain ?? 55, wind: raw.wind ?? 55, heat: raw.heat ?? 35, cold: raw.cold ?? 2 };
            return { ...raw, thresholds, events: { ...defaultEvents, ...(raw.events || {}) }, minimumSeverity: raw.minimumSeverity || 'yellow', quietHours: { enabled:false,start:'23:00',end:'07:00', ...(raw.quietHours || {}) } };
        } catch { return { thresholds:{rain:55,wind:55,heat:35,cold:2}, events:{...defaultEvents}, minimumSeverity:'yellow', quietHours:{enabled:false,start:'23:00',end:'07:00'} }; }
    }
    async function fetchJson(url, options = {}) {
        const response = await fetch(url, { method: options.method || 'GET', credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json', ...(options.body?{'Content-Type':'application/json'}:{}), ...(SECURITY?.headers?.() || {})}, body: options.body ? JSON.stringify(options.body) : undefined });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data?.ok === false) {
            if (response.status === 401 && data?.code === 'AUTH_REQUIRED') SERVICES.get('auth')?.handleAuthRequired?.();
            throw new Error(data?.message ? text(data.message) : `HTTP ${response.status}`);
        }
        return data;
    }
    function typeLabel(type) { return text(`smart.event.${type}`); }
    function severityLabel(value) { return text(`smart.severity.${value}`); }
    function intelligenceLocale() { return String(state?.settings?.language || document.documentElement.lang || 'it').replace('_','-'); }
    function intelligenceTimeZone() { return String(state?.weather?.timezone || state?.location?.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone || 'Europe/Rome'); }
    function calendarKey(date) {
        try {
            const parts = new Intl.DateTimeFormat('en-CA',{timeZone:intelligenceTimeZone(),year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(date);
            const get = type => parts.find(part => part.type === type)?.value || '';
            return `${get('year')}-${get('month')}-${get('day')}`;
        } catch { return date.toISOString().slice(0,10); }
    }
    function eventTiming(event = {}) {
        const rawStart=String(event.startsAt || ''); if(!rawStart) return '';
        const start=new Date(rawStart); if(Number.isNaN(start.getTime())) return '';
        const endRaw=String(event.endsAt || ''); const end=endRaw ? new Date(endRaw) : null;
        const locale=intelligenceLocale(), timeZone=intelligenceTimeZone();
        try {
            const dateFmt=new Intl.DateTimeFormat(locale,{weekday:'short',day:'numeric',month:'short',timeZone});
            const timeFmt=new Intl.DateTimeFormat(locale,{hour:'2-digit',minute:'2-digit',timeZone});
            const startLabel=`${dateFmt.format(start)} · ${timeFmt.format(start)}`;
            if(!end || Number.isNaN(end.getTime())) return startLabel;
            if(calendarKey(start)===calendarKey(end)) return `${startLabel}–${timeFmt.format(end)}`;
            return `${startLabel} → ${dateFmt.format(end)} · ${timeFmt.format(end)}`;
        } catch { return ''; }
    }
    function weatherLabel(code) {
        try { return typeof weatherMeta === 'function' ? String(weatherMeta(Number(code || 0),1)?.label || '') : ''; }
        catch { return ''; }
    }
    function renderDailyOutlook() {
        const root=q('#smart-day-outlook'); if(!root) return;
        const daily=state?.weather?.daily || {}; const times=Array.isArray(daily.time)?daily.time:[];
        if(!times.length){ root.innerHTML=''; return; }
        const locale=intelligenceLocale(), timeZone=intelligenceTimeZone();
        const cards=times.slice(0,4).map((day,index)=>{
            let dateLabel=String(day);
            try {
                const [y,m,d]=String(day).split('-').map(Number);
                dateLabel=new Intl.DateTimeFormat(locale,{weekday:'short',day:'numeric',month:'short',timeZone}).format(new Date(Date.UTC(y,m-1,d,12)));
            } catch { }
            const code=Number(daily.weather_code?.[index] ?? 0);
            const rain=Math.round(Number(daily.precipitation_probability_max?.[index] || 0));
            const mm=Number(daily.precipitation_sum?.[index] || 0);
            const tmin=Number(daily.temperature_2m_min?.[index]); const tmax=Number(daily.temperature_2m_max?.[index]);
            const gust=Math.round(Number(daily.wind_gusts_10m_max?.[index] || 0));
            const level=[95,96,99].includes(code)?'storm':(rain>=55||mm>=2?'rain':'normal');
            const temp=(Number.isFinite(tmin)&&Number.isFinite(tmax))?`${Math.round(tmin)}° / ${Math.round(tmax)}°`:'--';
            return `<article class="smart-day-card" data-weather-level="${level}"><strong>${safe(dateLabel)}</strong><span>${safe(weatherLabel(code) || text('smart.outlook.unknown'))}</span><small>${safe(text('smart.outlook.metrics',{rain,temp,mm:mm.toFixed(1),gust}))}</small></article>`;
        }).join('');
        root.innerHTML=`<div class="smart-day-outlook-head"><strong>${safe(text('smart.outlook.title'))}</strong><small>${safe(text('smart.outlook.copy'))}</small></div><div class="smart-day-outlook-grid">${cards}</div>`;
    }
    function officialWarningText(warning = {}) {
        const raw=String(warning.title||warning.officialTitle||warning.body||'').trim();
        const severityRaw=String(warning.severity||'').toLowerCase();
        const colorMatch=raw.match(/\b(yellow|orange|red)\b/i);
        const severity=['yellow','orange','red'].includes(severityRaw)?severityRaw:String(colorMatch?.[1]||'yellow').toLowerCase();
        const eventMap=[[/thunder|tempor/i,'thunderstorm'],[/rain|piogg/i,'rain'],[/snow|neve/i,'snow'],[/wind|vento/i,'wind'],[/ice|ghiacci/i,'ice'],[/fog|nebb/i,'fog'],[/heat|high temperature|caldo/i,'heat']];
        const eventId=(eventMap.find(([rx])=>rx.test(raw))||[])[1]||'weather';
        const areaMatch=raw.match(/(?:issued\s+for\s+italy\s*[-–:]\s*|italy\s*[-–:]\s*)(.+)$/i);
        const area=areaMatch?.[1]?String(areaMatch[1]).replace(/\s+warning.*$/i,'').trim():'';
        const level=text(`advanced.official.level.${severity}`),event=text(`advanced.official.event.${eventId}`);
        return area?text('advanced.official.summary.area',{level,event,area}):text('advanced.official.summary',{level,event});
    }
    function officialLifecycleCopy(event = {}) {
        const life=event.officialLifecycle||event.lifecycle||{},current=String(event.severity||'yellow').toLowerCase(),previous=String(life.previousSeverity||'').toLowerCase(),parts=[];
        const rank=v=>({green:0,yellow:1,orange:2,red:3})[v]??0;
        if(previous&&previous!==current&&['yellow','orange','red'].includes(previous)&&['yellow','orange','red'].includes(current))parts.push(text(rank(current)>rank(previous)?'home.official.lifecycle.escalated':'home.official.lifecycle.downgraded',{from:text(`advanced.official.level.${previous}`),to:text(`advanced.official.level.${current}`)}));
        const prevEnd=Date.parse(String(life.previousEndsAt||'')),curEnd=Date.parse(String(event.endsAt||''));
        if(Number.isFinite(prevEnd)&&Number.isFinite(curEnd)&&curEnd>prevEnd+60000)parts.push(text('home.official.lifecycle.extended',{until:eventTiming({startsAt:event.endsAt})||event.endsAt}));
        else if(Number.isFinite(prevEnd)&&Number.isFinite(curEnd)&&curEnd<prevEnd-60000)parts.push(text('home.official.lifecycle.shortened',{until:eventTiming({startsAt:event.endsAt})||event.endsAt}));
        if(!parts.length&&String(life.changeType||'')==='updated')parts.push(text('home.official.lifecycle.updated'));
        return parts.join(' · ');
    }
    function eventCopy(event = {}) {
        const location = locationName(), confidence = Math.round(Number(event.confidence || 0));
        let value = '';
        if (event.type === 'rain') value = event.etaMinutes == null ? text('smart.alert.rain.soon') : text('smart.alert.rain.minutes', { minutes: Math.max(0, Math.round(Number(event.etaMinutes))) });
        else if (event.type === 'storm') value = event.lightning?.nearestKm != null ? text('smart.alert.storm.distance', { distance: event.lightning.nearestKm }) : text('smart.alert.storm.nearby');
        else if (event.type === 'wind') value = `${event.maxWind ?? '--'} km/h`;
        else if (event.type === 'snow') value = `${event.maxSnow ?? '--'} cm/h`;
        else if (event.type === 'ice') value = `${event.minTemp ?? '--'} °C`;
        else if (event.type === 'fog') value = `${event.visibilityKm ?? '--'} km`;
        else if (event.type === 'heat') value = `${event.maxTemp ?? '--'} °C`;
        else if (event.type === 'aqi') value = String(event.aqi ?? '--');
        else if (event.type === 'official') value = [officialWarningText(event),officialLifecycleCopy(event)].filter(Boolean).join(' · ');
        const key = `smart.alert.${event.type}.body`;
        const translated = text(key, { location, confidence, value });
        return translated === key ? String(event.body || '') : translated;
    }
    function intelqWindow(startRaw, endRaw) {
        if (!startRaw) return text('intelq.window.unknown');
        const start=new Date(startRaw), end=endRaw?new Date(endRaw):null;
        if(Number.isNaN(start.getTime())) return text('intelq.window.unknown');
        try {
            const locale=intelligenceLocale(),timeZone=intelligenceTimeZone();
            const day=new Intl.DateTimeFormat(locale,{weekday:'short',day:'numeric',month:'short',timeZone});
            const clock=new Intl.DateTimeFormat(locale,{hour:'2-digit',minute:'2-digit',timeZone});
            return end&&!Number.isNaN(end.getTime()) ? `${day.format(start)} · ${clock.format(start)}–${clock.format(end)}` : `${day.format(start)} · ${clock.format(start)}`;
        } catch { return start.toISOString(); }
    }
    function intelqModelLabel(id) {
        const key=({ecmwf:'provider.ecmwf',aifs:'provider.aifs',icon:'provider.icon',gfs:'provider.gfs',meteofrance:'provider.meteofrance',ukmo:'provider.ukmo',consensus:'intelq.model.consensus'})[String(id||'').toLowerCase()];
        return key ? text(key) : String(id || '');
    }
    function renderIntelQConsensus(data) {
        const root=q('#intelq-consensus-main'),votesRoot=q('#intelq-model-votes');if(!root||!votesRoot)return;
        const consensus=data?.consensus||{},primary=consensus.primary||{},cal=data?.confidenceCalibration||{};
        if(!consensus.available){root.innerHTML=`<div class="intelq-empty">${safe(text('intelq.consensus.unavailable'))}</div>`;votesRoot.innerHTML='';return;}
        const type=primary.type?typeLabel(primary.type):text('intelq.consensus.clear');
        const when=primary.startsAt?intelqWindow(primary.startsAt,primary.endsAt):text('intelq.consensus.noevent');
        const agreement=Math.round(Number(primary.agreementPct||0));const votes=Number(primary.votes||0),available=Number(primary.available||consensus.modelsAvailable||0);
        const calibrated=(cal.available&&cal.publishable)?text('intelq.consensus.calibrated',{value:Math.round(Number(cal.calibratedProbabilityPct||0)),samples:Number(cal.samples||0)}):text('intelq.consensus.uncalibrated');
        root.innerHTML=`<strong>${safe(type)} · ${safe(when)}</strong><span>${safe(text('intelq.consensus.summary',{votes,available,agreement}))}</span><small class="intelq-history-note">${safe(calibrated)}</small>`;
        const modelVotes=primary.modelVotes||{};
        const modelIds=Array.isArray(consensus.modelIds)&&consensus.modelIds.length?consensus.modelIds:Object.keys(modelVotes);
        votesRoot.innerHTML=modelIds.map(id=>{
            const exists=modelIds.includes(id),yes=Boolean(modelVotes[id]);
            const stateLabel=!exists?text('intelq.vote.unavailable'):(yes?text('intelq.vote.yes'):text('intelq.vote.no'));
            return `<span class="${yes?'is-yes':'is-no'}"><strong>${safe(intelqModelLabel(id))}</strong><span class="intelq-vote-state">${safe(stateLabel)}</span></span>`;
        }).join('');
    }
    function renderIntelQSkill(data) {
        const root=q('#intelq-skill-content');if(!root)return;const skill=data?.modelSkill||{};
        if(data?.mode==='guest-demo'||data?.privacy?.historyPersisted===false){root.innerHTML=`<div class="intelq-empty">${safe(text('intelq.skill.guest_demo'))}</div>`;return;}
        if(!skill.available){const progress=data?.calibrationProgress||{};const key=progress.independentObservationAvailable===false?'intelq.skill.waiting_observation':'intelq.skill.learning';root.innerHTML=`<div class="intelq-empty">${safe(text(key,{samples:Number(skill.verifiedSamples||0),queued:Number(progress.queued||0)}))}</div>`;return;}
        const rows=[];for(const metric of ['rain','storm','snow','temperature','wind'])for(const horizon of ['1','3','6','24','48','72']){
            const entries=skill.byMetricHorizon?.[metric]?.[horizon];if(!Array.isArray(entries)||!entries.length)continue;const best=entries.find(entry=>entry?.eligibleForRanking===true);if(!best)continue;
            const value=(['rain','storm','snow'].includes(metric))?text('intelq.skill.brier',{value:Number(best.brier||0).toFixed(3)}):text('intelq.skill.mae',{value:Number(best.mae||0).toFixed(metric==='wind'?1:2)});
            rows.push(`<div class="intelq-skill-row"><strong>${safe(text(`intelq.metric.${metric}`))}</strong><span>+${safe(horizon)}h · ${safe(intelqModelLabel(best.model))}</span><span class="intelq-metric-good">${safe(value)}</span></div>`);
        }
        root.innerHTML=`<div class="intelq-skill-table">${rows.slice(0,12).join('')}</div><p class="intelq-history-note">${safe(text('intelq.skill.samples',{count:Number(skill.verifiedSamples||0),brier:skill.brier==null?'--':Number(skill.brier).toFixed(3)}))}</p>`;
    }
    function renderIntelQChange(data) {
        const root=q('#intelq-change-content');if(!root)return;const change=data?.forecastChange||{},previous=data?.previousRuns||{},run=previous.summary||{};
        const runPieces=[];
        if(run.available){
            if(run.timeShiftMinutes!=null&&Math.abs(Number(run.timeShiftMinutes))>=30)runPieces.push(Number(run.timeShiftMinutes)<0?text('intelq.previous.earlier',{minutes:Math.abs(Number(run.timeShiftMinutes))}):text('intelq.previous.later',{minutes:Math.abs(Number(run.timeShiftMinutes))}));
            if(Math.abs(Number(run.rainTotalDelta||0))>=.1)runPieces.push(text('intelq.previous.rain',{delta:Number(run.rainTotalDelta)>0?`+${Number(run.rainTotalDelta).toFixed(1)}`:Number(run.rainTotalDelta).toFixed(1)}));
            if(Number(run.stormHoursDelta||0)!==0)runPieces.push(text('intelq.previous.storm',{delta:Number(run.stormHoursDelta)>0?`+${run.stormHoursDelta}`:String(run.stormHoursDelta)}));
            if(Math.abs(Number(run.windGustMaxDelta||0))>=1)runPieces.push(text('intelq.previous.wind',{delta:Number(run.windGustMaxDelta)>0?`+${Number(run.windGustMaxDelta).toFixed(0)}`:Number(run.windGustMaxDelta).toFixed(0)}));
        }
        const history=previous.available?`<div class="intelq-run-compare"><strong>${safe(text(run.changed?'intelq.previous.changed':'intelq.previous.stable'))}</strong><p>${safe(runPieces.join(' · ')||text('intelq.previous.no_material'))}</p><small>${safe(text('intelq.previous.source'))}</small></div>`:'';
        if(data?.mode==='guest-demo'||data?.privacy?.historyPersisted===false){root.innerHTML=`<div class="intelq-empty">${safe(text('intelq.change.guest_demo'))}</div>${history}`;return;}
        if(!change.available){root.innerHTML=`<div class="intelq-empty">${safe(text('intelq.change.first'))}</div>${history}`;return;}
        const pieces=[];
        if(change.typeChanged)pieces.push(text('intelq.change.type'));
        if(change.timeShiftMinutes!=null&&Math.abs(Number(change.timeShiftMinutes))>=30)pieces.push(Number(change.timeShiftMinutes)<0?text('intelq.change.earlier',{minutes:Math.abs(Number(change.timeShiftMinutes))}):text('intelq.change.later',{minutes:Math.abs(Number(change.timeShiftMinutes))}));
        if(Number(change.rainProbabilityDelta||0)!==0)pieces.push(text('intelq.change.rain',{delta:Number(change.rainProbabilityDelta)>0?`+${change.rainProbabilityDelta}`:String(change.rainProbabilityDelta)}));
        if(Number(change.agreementDelta||0)!==0)pieces.push(text('intelq.change.agreement',{delta:Number(change.agreementDelta)>0?`+${change.agreementDelta}`:String(change.agreementDelta)}));
        root.innerHTML=`<div class="intelq-change-box"><strong>${safe(change.changed?text('intelq.change.changed'):text('intelq.change.stable'))}</strong><p>${safe(pieces.join(' · ')||text('intelq.change.no_material'))}</p><small>${safe(text('intelq.change.stability',{value:Math.round(Number(change.stabilityPct||0))}))}</small></div>${history}`;
    }
    function renderIntelQSources(data) {
        const root=q('#intelq-source-list');if(!root)return;const sources=Array.isArray(data?.sourceFreshness)?data.sourceFreshness:[];const parts=Object.fromEntries((data?.explainability?.parts||[]).map(p=>[p.source,p]));const primary=data?.consensus?.primary||{};
        root.innerHTML=sources.length?sources.map(src=>{
            const isModel=src.kind==='model';const part=isModel?parts.models:parts[src.id];
            let fresh=src.available?(src.ageMinutes==null?text('intelq.source.available'):(src.freshnessBasis==='retrieval'?text('intelq.source.retrieved',{minutes:Math.max(0,Math.round(Number(src.ageMinutes)))}):text('intelq.source.minutes',{minutes:Math.max(0,Math.round(Number(src.ageMinutes)))}))):text('intelq.source.unavailable');
            let contribution='';
            if(isModel&&src.available){const vote=primary.modelVotes?.[src.id];if(vote===true)contribution=text('intelq.vote.yes');else if(vote===false)contribution=text('intelq.vote.no');}
            else if(part)contribution=text('intelq.source.contribution',{value:Number(part.contribution||0)});
            const mode=src.mode?` · ${src.mode}`:'';
            const label=isModel?intelqModelLabel(src.id):(src.label||src.id);return `<div class="intelq-source-row"><div><strong>${safe(label)}</strong><small>${safe(fresh+mode)}</small></div><span>${safe(contribution)}</span></div>`;
        }).join(''):`<div class="intelq-empty">${safe(text('intelq.source.none'))}</div>`;
        const conditionRoot=q('#intelq-explain-condition');
        if(conditionRoot){
            const agreement=Math.round(Number(primary.agreementPct||primary.weightedAgreementPct||0));
            const radarAvailable=Boolean(data?.radarMotion?.available||data?.cellTracking?.available);
            const activeCell=Boolean(data?.cellTracking?.available)&&Number(data?.cellTracking?.impactProbability||0)>=45;
            const officialRelevant=Array.isArray(data?.official?.relevant)&&data.official.relevant.length>0;
            let key='intelq.explain.change.default';
            if(!radarAvailable)key='intelq.explain.change.radar';
            else if(agreement>0&&agreement<70)key='intelq.explain.change.agreement';
            else if(activeCell)key='intelq.explain.change.cell';
            else if(officialRelevant)key='intelq.explain.change.official';
            conditionRoot.innerHTML=`<strong>${safe(text('intelq.explain.change.title'))}</strong><p>${safe(text(key))}</p>`;
        }
    }
    function renderIntelQCell(data) {
        const root=q('#intelq-cell-content');if(!root)return;const cell=data?.cellTracking||data?.radarMotion?.cellTracking||{};
        if(!cell.available){root.innerHTML=`<div class="intelq-empty">${safe(text('intelq.cell.unavailable'))}</div>`;return;}
        root.innerHTML=`<div class="intelq-cell-box"><strong>${safe(text(`intelq.cell.stage.${cell.stage||'stable'}`))} · ${safe(cell.direction||'--')}</strong><p>${safe(text('intelq.cell.impact',{probability:Math.round(Number(cell.impactProbability||0)),growth:Number(cell.growthPct||0).toFixed(0)}))}</p><small>${safe(cell.etaMinutes!=null?text('intelq.cell.eta',{minutes:Math.round(Number(cell.etaMinutes))}):text('intelq.cell.noeta'))} · ${safe(text('intelq.source.minutes',{minutes:Number(cell.ageMinutes||0)}))}</small></div>`;
    }
    function renderIntelQDecisions(data) {
        const root=q('#intelq-decision-grid');if(!root)return;const rows=Array.isArray(data?.decisionWindows)?data.decisionWindows:[];
        root.innerHTML=rows.length?rows.slice(0,9).map(row=>`<article class="intelq-decision-card"><strong>${safe(text(`intelq.activity.${row.activity}`))}</strong><span>${safe(intelqWindow(row.startsAt,row.endsAt))}</span><small>${safe(text('intelq.decision.score',{score:Math.round(Number(row.score||0))}))}</small></article>`).join(''):`<div class="intelq-empty">${safe(text('intelq.decision.unavailable'))}</div>`;
    }
    function verifiedLevelId(value){if(value&&typeof value==='object')return String(value.id||'initial');return String(value||'initial');}
    function renderVerifiedReliability(data){const root=q('#verified-reliability-content');if(!root)return;const rel=data?.forecastReliability||{},cal=rel.calibration||data?.confidenceCalibration||{},run=rel.runStability||{};if(!rel.available){root.innerHTML=`<div class="intelq-empty">${safe(text('verified.reliability.unavailable'))}</div>`;return;}const level=verifiedLevelId(cal.calibrationLevel??rel.calibrationLevel??'initial'),samples=Number(cal.samples??rel.metricVerifiedSamples??0),sources=(rel.observationSourceTypes||[]).join(' · '),ci=Array.isArray(cal.uncertainty95)?`${Math.round(Number(cal.uncertainty95[0])*100)}–${Math.round(Number(cal.uncertainty95[1])*100)}%`:'--';const ens=data?.probabilisticEnsemble||{},h=ens?.headline24h||{},fmt=(d,unit)=>d?.available&&d.p10!=null&&d.p90!=null?`${Number(d.p10).toFixed(1)}–${Number(d.p90).toFixed(1)} ${unit}`:'--';const ensembleHtml=ens.available?`<div class="verified-kpi-grid ensemble-probabilistic-grid"><div><small>${safe(text('ensemble.card.title'))} · P10–P90</small><strong>${safe(fmt(h.temperature,'°C'))}</strong><span>${safe(text('history.plot.maximum_temperature'))}</span></div><div><small>${safe(text('ensemble.card.title'))} · P10–P90</small><strong>${safe(fmt(h.precipitation,'mm'))}</strong><span>${safe(text('intelligence.rendermodelcomparison.24h_rain'))}</span></div><div><small>${safe(text('ensemble.card.title'))} · P10–P90</small><strong>${safe(fmt(h.windGust,'km/h'))}</strong><span>${safe(text('intelligence.rendermodelcomparison.maximum_wind'))}</span></div></div>`:'';root.innerHTML=`<div class="verified-kpi-grid"><div><small>${safe(text('verified.reliability.level'))}</small><strong>${safe(text(`verified.level.${level}`))}</strong><span>${safe(text('verified.reliability.samples',{count:samples}))}</span></div><div><small>${safe(text('verified.reliability.observations'))}</small><strong>${Number(rel.observationSourceCount||0)}</strong><span>${safe(sources||text('verified.reliability.no_sources'))}</span></div><div><small>${safe(text('verified.reliability.weighted'))}</small><strong>${Math.round(Number(rel.weightedAgreementPct||0))}%</strong><span>${safe(text('verified.reliability.uniform',{value:Math.round(Number(rel.uniformAgreementPct||0))}))}</span></div><div><small>${safe(text('verified.reliability.stability'))}</small><strong>${run.available?`${Math.round(Number(run.stabilityPct||0))}%`:'--'}</strong><span>${safe(run.available?text(`verified.stability.${run.trend||'stable'}`):text('verified.reliability.no_history'))}</span></div></div>${ensembleHtml}<div class="verified-reliability-foot"><span>${safe(text('verified.reliability.ci',{value:ci}))}</span><span>${safe(text('verified.reliability.quality',{value:Number(rel.observationQualityScore||0)}))}</span></div>`;}
    function renderVerifiedNowcast(data){const root=q('#verified-nowcast-content');if(!root)return;const f=data?.nowcastFusion||{};if(!f.available){root.innerHTML=`<div class="intelq-empty">${safe(text('verified.nowcast.unavailable'))}</div>`;return;}const eta=Array.isArray(f.etaRangeMinutes)?text('verified.nowcast.eta_range',{from:Math.round(Number(f.etaRangeMinutes[0]||0)),to:Math.round(Number(f.etaRangeMinutes[1]||0))}):(f.etaMinutes!=null?text('verified.nowcast.eta',{minutes:Math.round(Number(f.etaMinutes))}):text('verified.nowcast.no_eta'));root.innerHTML=`<div class="verified-nowcast-hero verified-severity-${safe(f.severity||'green')}"><div><small>${safe(text('verified.nowcast.impact'))}</small><strong>${Math.round(Number(f.impactProbability||0))}%</strong><span>${safe(text('verified.nowcast.confidence',{value:Math.round(Number(f.confidence||0))}))}</span></div><div><small>${safe(text('verified.nowcast.eta_label'))}</small><strong>${safe(eta)}</strong><span>${safe(text(`verified.trend.${f.trend?.trend||'unknown'}`))}</span></div></div><div class="verified-motion-grid"><span><b>${safe(text('verified.nowcast.direction'))}</b>${safe(f.direction||'--')}</span><span><b>${safe(text('verified.nowcast.speed'))}</b>${f.speedKmh!=null?safe(`${Math.round(Number(f.speedKmh))} km/h`):'--'}</span><span><b>${safe(text('verified.nowcast.growth'))}</b>${f.growthPct!=null?safe(`${Math.round(Number(f.growthPct))}%`):'--'}</span><span><b>${safe(text('verified.nowcast.cap'))}</b>${safe(f.capGeoJson?text('verified.nowcast.available'):text('verified.nowcast.not_available'))}</span></div><div class="verified-source-chips">${(f.sourceIds||[]).map(id=>`<span>${safe(text(`verified.source.${id}`)||id)}</span>`).join('')}</div>`;}
    const personalDefaults={motorcycle:{rainMax:20,gustMax:40,tempMin:8,tempMax:36,visibilityMin:5},bike:{rainMax:25,gustMax:35,tempMin:5,tempMax:34,visibilityMin:4},run:{rainMax:35,gustMax:45,tempMin:-2,tempMax:32,visibilityMin:3},trekking:{rainMax:30,gustMax:50,tempMin:-5,tempMax:30,visibilityMin:3},sea:{rainMax:25,gustMax:35,tempMin:18,tempMax:38,visibilityMin:5},outdoor:{rainMax:40,gustMax:55,tempMin:-5,tempMax:36,visibilityMin:2},worksite:{rainMax:25,gustMax:45,tempMin:-5,tempMax:34,visibilityMin:3},commute:{rainMax:45,gustMax:60,tempMin:-10,tempMax:40,visibilityMin:2},kids:{rainMax:25,gustMax:35,tempMin:5,tempMax:30,visibilityMin:4},pets:{rainMax:35,gustMax:45,tempMin:0,tempMax:31,visibilityMin:3}};let personalActivity='motorcycle';let personalProfiles={};
    function renderPersonalConfidence(data){const root=q('#personal-confidence-content'),badge=q('#personal-confidence-status');if(!root)return;const c=data?.weatherConfidence||{};if(!c.available){root.innerHTML=`<div class="intelq-empty">${safe(text('personal.confidence.unavailable'))}</div>`;return;}const score=Math.round(Number(c.score||0)),level=String(c.level||'low');if(badge)badge.textContent=text(`personal.confidence.level.${level}`);root.innerHTML=`<div class="personal-confidence-hero"><div class="personal-confidence-ring" style="--score:${score}"><div><strong>${score}</strong><small>/100</small></div></div><div class="personal-confidence-copy"><strong>${safe(text(`personal.confidence.level.${level}`))} · ${safe(text(`personal.confidence.maturity.${c.maturity||'learning'}`))}</strong><p>${safe(c.verified?text('personal.confidence.verified_copy',{samples:Number(c.samples||0)}):text('personal.confidence.learning_copy',{samples:Number(c.samples||0)}))}</p></div></div><div class="personal-confidence-factors">${(c.parts||[]).map(p=>`<div class="personal-confidence-factor" style="--factor:${p.available&&p.score!=null?Math.max(0,Math.min(100,Math.round(Number(p.score)))):0}"><small>${safe(p.id==='ensemble'?text('ensemble.card.title'):text(`personal.confidence.factor.${p.id}`))}</small><strong>${p.available&&p.score!=null?Math.round(Number(p.score))+'/100':'--'}</strong><span>${safe(text(`personal.confidence.status.${p.status||'unavailable'}`))}</span></div>`).join('')}</div>`;}
    function renderPersonalActivity(data){personalProfiles={...personalDefaults,...personalProfiles,...(data?.activityProfiles||{})};const p=personalProfiles[personalActivity]||personalDefaults[personalActivity];const set=(id,v)=>{const n=q(id);if(n)n.value=String(v??'');};set('#personal-threshold-rain',p.rainMax);set('#personal-threshold-gust',p.gustMax);set('#personal-threshold-temp-min',p.tempMin);set('#personal-threshold-temp-max',p.tempMax);set('#personal-threshold-visibility',p.visibilityMin);qa('[data-personal-activity]').forEach(b=>b.classList.toggle('is-active',b.dataset.personalActivity===personalActivity));}
    async function savePersonalActivity(){if(isGuest()){SERVICES.get('guestAccess')?.notify?.();return;}const val=id=>Number(String(q(id)?.value??'').trim().replace(',','.'));const thresholds={rainMax:val('#personal-threshold-rain'),gustMax:val('#personal-threshold-gust'),tempMin:val('#personal-threshold-temp-min'),tempMax:val('#personal-threshold-temp-max'),visibilityMin:val('#personal-threshold-visibility')};let saved;if(typeof SERVICES.get('accountSync')?.saveActivityProfile==='function')saved=await SERVICES.get('accountSync').saveActivityProfile(personalActivity,thresholds);else{const data=await fetchJson('api/account/sync.php',{method:'POST',body:{deviceId:SECURITY?.deviceId||'',action:'activity-profile',activity:personalActivity,thresholds}});saved=data.thresholds||thresholds;}personalProfiles[personalActivity]=saved||thresholds;renderPersonalActivity({activityProfiles:{[personalActivity]:personalProfiles[personalActivity]}});lastRequestKey='';await refresh(true);}
    let savedLocationRole='home';
    async function loadIntelQLocations() {
        const panel=q('#intelq-locations-panel');if(!panel)return;const visible=SERVICES.get('uiVisibility')?.isVisible?.('feature.intelligence.locations')!==false;if(isGuest()||!visible){panel.hidden=true;return;}panel.hidden=false;
        const deviceId=SECURITY?.deviceId||'';if(!deviceId)return;
        try{const data=await fetchJson(`api/locations/manage.php?deviceId=${encodeURIComponent(deviceId)}`);renderIntelQLocations(data.rows||[]);}catch{renderIntelQLocations([]);}
    }
    function renderIntelQLocations(rows) {
        const root=q('#intelq-location-list');if(!root)return;
        root.innerHTML=rows.length?rows.map(row=>`<div class="intelq-location-row"><div><strong>${safe(row.label)}</strong><small>${safe(text(`intelq.role.${row.role==='second_home'?'second':row.role}`))} · ${safe([row.location_name,row.admin1].filter(Boolean).join(', '))}</small></div><button class="button outline-button" data-intelq-use-location="${Number(row.id)}" type="button">${safe(text('intelq.locations.use'))}</button><button class="button ghost-button" data-intelq-delete-location="${Number(row.id)}" type="button">${safe(text('intelq.locations.delete'))}</button></div>`).join(''):`<div class="intelq-empty">${safe(text('intelq.locations.empty'))}</div>`;
        root._intelqRows=rows;
    }
    async function saveIntelQLocation() {
        if(isGuest()){SERVICES.get('guestAccess')?.notify?.();return;}const input=q('#intelq-location-label');const label=String(input?.value||'').trim();if(!label||!state?.location)return;
        await fetchJson('api/locations/manage.php',{method:'POST',body:{deviceId:SECURITY?.deviceId||'',action:'save',role:savedLocationRole,label,name:String(state.location.name||label),admin1:String(state.location.admin1||''),latitude:Number(state.location.latitude),longitude:Number(state.location.longitude),timezone:intelligenceTimeZone()}});
        if(input)input.value='';await loadIntelQLocations();
    }
    function renderIntelQ(data) { renderIntelQConsensus(data);renderIntelQSkill(data);renderIntelQChange(data);renderIntelQSources(data);renderIntelQCell(data);renderVerifiedReliability(data);renderVerifiedNowcast(data);renderPersonalConfidence(data);renderPersonalActivity(data);renderIntelQDecisions(data);loadIntelQLocations(); }
    function render(data) {
        currentData = data;
        lastLoadedAt = Date.now();
        try { document.dispatchEvent(new CustomEvent('meteonexa:intelligence-data',{detail:{data}})); } catch { }
        const analysis = data?.analysis || {};
        const events = Array.isArray(analysis.events) ? analysis.events : [];
        const guest = data?.mode === 'guest-demo';
        const badge = q('#smart-mode-badge');
        if (badge) { badge.textContent = guest ? text('smart.demo.badge') : text('smart.live.badge'); badge.classList.toggle('smart-mode-demo', guest); }
        const title = q('#smart-summary-title'), copy = q('#smart-summary-copy'), confidence = q('#smart-confidence');
        // These nodes start with loading i18n keys in the static shell, but after
        // the API response their content is runtime data. Mark them dynamic so a
        // later catalogue/bootstrap translation pass cannot restore "Analisi in corso".
        if (title) { title.dataset.i18nDynamic = 'true'; title.textContent = events.length ? typeLabel(events[0].type) : text('smart.clear.title'); }
        if (copy) { const when=events.length?eventTiming(events[0]):''; copy.dataset.i18nDynamic = 'true'; copy.textContent = events.length ? [when,eventCopy(events[0])].filter(Boolean).join(' · ') : text('smart.clear.copy'); }
        if (confidence) confidence.textContent = `${Math.round(Number(analysis.confidence || 0))}%`;
        renderDailyOutlook();
        const root = q('#smart-event-grid');
        if (root) root.innerHTML = events.length ? events.slice(0, 8).map(event => { const when=eventTiming(event); return `<article class="smart-event-card" data-severity="${safe(event.severity)}"><strong><span>${safe(typeLabel(event.type))}</span><small>${safe(severityLabel(event.severity))} · ${Math.round(Number(event.confidence || 0))}%</small></strong>${when?`<span class="smart-event-when">${safe(when)}</span>`:''}<p>${safe(eventCopy(event))}</p></article>`; }).join('') : `<article class="smart-event-card"><strong>${safe(text('smart.clear.title'))}</strong><p>${safe(text('smart.clear.copy'))}</p></article>`;
        const motion = data?.radarMotion || analysis?.nowcast?.radarMotion || {};
        const lightning = data?.lightning || {};
        const satellite = data?.satellite || analysis?.nowcast?.satellite || {};
        const official = data?.official || {};
        const hyper = data?.hyperlocal || {};
        const accuracy = data?.accuracy || {};
        const set = (id, value) => { const node=q(id); if(node) node.textContent=value; };
        set('#smart-source-radar', `${text('smart.source.radar')}: ${motion.available ? `${motion.direction || '✓'} · ${motion.confidence || '--'}%` : text('smart.source.unavailable')}`);
        set('#smart-source-lightning', `${text('smart.source.lightning')}: ${lightning.available ? `${Number(lightning.count || 0)} · ${lightning.nearestKm ?? '--'} km` : text('smart.source.unavailable')}`);
        set('#smart-source-satellite', `${text('smart.source.satellite')}: ${satellite.available ? `${satellite.cloudAttenuationPct ?? '--'}%` : text('smart.source.unavailable')}`);
        set('#smart-source-official', `${text('smart.source.official')}: ${official.available === false ? text('smart.source.unavailable') : Number(official.relevant?.length || 0)}`);
        set('#smart-source-hyperlocal', `${text('smart.source.hyperlocal')}: ${hyper.available ? `${hyper.station || 'Netatmo'} · ${hyper.distanceKm ?? '--'} km` : text('smart.source.unavailable')}`);
        set('#smart-source-models', `${text('smart.source.models')}: ${accuracy.models ? `${accuracy.models} · ${accuracy.samples || 0} ${text('smart.samples')}` : (guest ? text('smart.demo.models') : text('smart.source.learning'))}`);
        const pipeline=data?.pipelineHealth||{};set('#smart-source-pipeline', `${text('smart.source.pipeline')}: ${guest?text('smart.source.not_applicable'):(pipeline.available===false?text('smart.source.learning'):text(`pipeline.status.${pipeline.status||'unknown'}`))}`);
        const note = q('#smart-demo-note'); if(note) note.hidden = !guest;
        const action = q('#smart-alerts-action'); if(action) action.querySelector('span').textContent = guest ? text('smart.demo.login') : text('smart.alerts.configure');
        const explain=q('#smart-ai-explain'); if(explain) explain.hidden=guest;
        renderIntelQ(data);
    }
    const SUMMARY_LOADING_PANELS=['#smart-intelligence-panel','#consensus-quality-panel','#intelq-skill-panel','#intelq-change-panel','#intelq-explain-panel','#intelq-cell-panel','#verified-reliability-panel','#verified-nowcast-panel','#personal-confidence-panel','#intelq-decision-panel'];
    function setSummaryPanelsLoading(busy){for(const selector of SUMMARY_LOADING_PANELS){const panel=q(selector);if(panel&&!panel.hidden)SERVICES.get('panelLoader')?.set?.(panel,busy,text('panel.loading'));}}
    async function refresh(force = false) {
        if (!q('#smart-intelligence-panel') || !state?.location) return;
        const guest = isGuest();
        const key = `${guest ? 'guest' : 'email'}:${locationKey()}`;
        if (!force && key === lastRequestKey && request) return request;
        // Route entry, hashchange and meteonexa:ready can happen close together on
        // cold boot. Reuse a just-rendered result instead of firing the demo API twice.
        if (!force && key === lastRequestKey && currentData && Date.now() - lastLoadedAt < 30000) return currentData;
        lastLocationKey = locationKey();
        lastRequestKey = key;
        const params = new URLSearchParams({ lat:String(state.location.latitude), lon:String(state.location.longitude), location:String(state.location.name || ''), admin1:String(state.location.admin1 || ''), lang:String(state?.settings?.language || document.documentElement.lang || 'it') });
        if (!guest) params.set('deviceId', SECURITY?.deviceId || '');
        const endpoint = guest ? `api/demo/intelligence.php?${params}` : `api/intelligence/summary.php?${params}`;
        const copy=q('#smart-summary-copy'); if(copy) copy.textContent=text('smart.loading.copy');
        setSummaryPanelsLoading(true);
        request = fetchJson(endpoint).then(render).catch(error => { if(copy) copy.textContent=error.message || text('smart.error'); }).finally(() => { setSummaryPanelsLoading(false); request=null; });
        return request;
    }
    async function syncProfile(profile) {
        localStorage.setItem(PROFILE_KEY, JSON.stringify(profile));
        if (isGuest()) return;
        const deviceId = SECURITY?.deviceId || '';
        await fetchJson('api/preferences/alerts.php', { method:'POST', body:{ deviceId, profile } }).catch(() => {});
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
        try {
            const reg=await navigator.serviceWorker.ready, sub=await reg.pushManager.getSubscription();
            if (!sub) return;
            await fetchJson('api/push/subscribe.php', { method:'POST', body:{ deviceId, subscription:sub.toJSON(), location:{ latitude:Number(state.location.latitude), longitude:Number(state.location.longitude), name:locationName() }, timezone:Intl.DateTimeFormat().resolvedOptions().timeZone || 'auto', language:state?.settings?.language || document.documentElement.lang || 'it', profile } });
        } catch { }
    }
    function populatePreferences() {
        const profile=loadProfile();
        qa('[data-smart-event]').forEach(input => { input.checked = profile.events[input.dataset.smartEvent] !== false; });
        const severity=q('#smart-minimum-severity'); if(severity) severity.value=profile.minimumSeverity || 'yellow';
        const enabled=q('#smart-quiet-enabled'); if(enabled) enabled.checked=Boolean(profile.quietHours?.enabled);
        const start=q('#smart-quiet-start'); if(start) start.value=profile.quietHours?.start || '23:00';
        const end=q('#smart-quiet-end'); if(end) end.value=profile.quietHours?.end || '07:00';
    }
    async function savePreferences() {
        const profile=loadProfile();
        qa('[data-smart-event]').forEach(input => { profile.events[input.dataset.smartEvent]=input.checked; });
        // The old rain toggle remains authoritative for rain and is mapped into the current forecast workflow.
        const rain=q('#notify-rain'); if(rain) profile.events.rain=rain.checked;
        profile.minimumSeverity=q('#smart-minimum-severity')?.value || 'yellow';
        profile.quietHours={ enabled:Boolean(q('#smart-quiet-enabled')?.checked), start:q('#smart-quiet-start')?.value || '23:00', end:q('#smart-quiet-end')?.value || '07:00' };
        await syncProfile(profile);
    }
    async function explainWithAssistant() {
        if (isGuest()) { SERVICES.get('guestAccess')?.notify?.(); return; }
        const first=currentData?.analysis?.events?.[0];
        const analysis=currentData?.analysis || {};
        const motion=currentData?.radarMotion || {};
        const lightning=currentData?.lightning || {};
        const official=currentData?.official || {};
        const hyper=currentData?.hyperlocal || {};
        const accuracy=currentData?.accuracy || {};
        const shortSituation=first ? `${typeLabel(first.type)}: ${eventCopy(first)} ${text('smart.ai.confidence.inline',{ confidence:Math.round(Number(first.confidence || analysis.confidence || 0)) })}` : text('smart.clear.copy');
        const evidence=[
            `evento=${String(first?.type || 'none')}`,
            `severita=${String(first?.severity || analysis.severity || 'green')}`,
            `confidence=${Math.round(Number(first?.confidence || analysis.confidence || 0))}%`,
            first?.horizon ? `orizzonte=${String(first.horizon)}` : '',
            first?.etaMinutes != null ? `ETA=${Math.max(0,Math.round(Number(first.etaMinutes)))}min` : '',
            motion.available ? `radar=${motion.direction || '?'};radarConfidence=${Math.round(Number(motion.confidence || 0))}%;radarETA=${motion.etaMinutes ?? '?'}min` : 'radar=non_disponibile',
            lightning.available ? `fulmini=${Number(lightning.count || 0)};distanzaFulmine=${lightning.nearestKm ?? '?'}km` : 'fulmini=non_disponibili',
            `modelliCampioni=${Number(accuracy.samples || 0)};modelli=${Number(accuracy.models || 0)}`,
            hyper.available ? `hyperlocal=${String(hyper.station || 'Netatmo')};distanza=${hyper.distanceKm ?? '?'}km` : 'hyperlocal=non_disponibile',
            Array.isArray(official.relevant) && official.relevant.length ? `allerteUfficiali=${official.relevant.slice(0,2).map(row=>officialWarningText(row)).join(' | ')}` : 'allerteUfficiali=nessuna_rilevante'
        ].filter(Boolean).join('; ');
        const prompt=text('smart.ai.prompt.verified',{ situation:shortSituation, evidence });
        if (typeof SERVICES.get('suite')?.openAssistantWithAi !== 'function') return;
        await loader(text('smart.ai.explain'), text('assistant.ai.loader.copy'), () => SERVICES.get('suite').openAssistantWithAi(prompt, shortSituation), 520);
    }
    function openAlerts() {
        if (isGuest()) { SERVICES.get('guestAccess')?.notify?.(); return; }
        const dialog=q('#notification-dialog');
        if(dialog && !dialog.open) dialog.showModal?.();
        populatePreferences();
        setTimeout(() => q('.smart-alert-preferences')?.scrollIntoView({behavior:'smooth',block:'nearest'}),100);
    }
    function bind() {
        q('#smart-refresh')?.addEventListener('click', () => loader(text('smart.refresh'), text('smart.loading.copy'), () => refresh(true), 420));
        q('#smart-alerts-action')?.addEventListener('click', openAlerts);
        q('#smart-ai-explain')?.addEventListener('click', explainWithAssistant);
        q('#intelligence-refresh')?.addEventListener('click', () => setTimeout(() => refresh(true), 200));
        q('#settings-notifications')?.addEventListener('click', () => setTimeout(populatePreferences, 80));
        qa('[data-smart-event],#smart-minimum-severity,#smart-quiet-enabled,#smart-quiet-start,#smart-quiet-end').forEach(node => node?.addEventListener('change', () => savePreferences()));
        q('#notify-rain')?.addEventListener('change', () => savePreferences());
        q('#intelq-role-chips')?.addEventListener('click', event => { const button=event.target.closest?.('[data-role]');if(!button)return;savedLocationRole=button.dataset.role||'custom';qa('#intelq-role-chips [data-role]').forEach(node=>node.classList.toggle('is-active',node===button)); });
        q('#intelq-save-location')?.addEventListener('click', () => loader(text('intelq.locations.save'), locationName(), saveIntelQLocation, 300));
        q('#personal-activity-chips')?.addEventListener('click',event=>{const b=event.target.closest?.('[data-personal-activity]');if(!b)return;personalActivity=b.dataset.personalActivity||'motorcycle';renderPersonalActivity(currentData);});
        q('#personal-save-activity')?.addEventListener('click',()=>loader(text('personal.activity.save'),text('personal.activity.syncing'),savePersonalActivity,320));
        document.addEventListener('meteonexa:account-sync',event=>{const detail=event?.detail||{};if(detail.alerts&&typeof detail.alerts==='object'){try{localStorage.setItem(PROFILE_KEY,JSON.stringify(detail.alerts));}catch{}populatePreferences();}if(detail.activityProfiles&&typeof detail.activityProfiles==='object')renderPersonalActivity({activityProfiles:detail.activityProfiles});});
        q('#intelq-location-list')?.addEventListener('click', async event => { const use=event.target.closest?.('[data-intelq-use-location]'),del=event.target.closest?.('[data-intelq-delete-location]');const root=q('#intelq-location-list');const rows=Array.isArray(root?._intelqRows)?root._intelqRows:[];
            if(use){const row=rows.find(item=>Number(item.id)===Number(use.dataset.intelqUseLocation));if(row)document.dispatchEvent(new CustomEvent('meteonexa:activate-saved-location',{detail:{name:row.location_name,admin1:row.admin1,latitude:Number(row.latitude),longitude:Number(row.longitude),timezone:row.timezone}}));}
            if(del){await fetchJson('api/locations/manage.php',{method:'POST',body:{deviceId:SECURITY?.deviceId||'',action:'delete',id:Number(del.dataset.intelqDeleteLocation)}}).catch(()=>{});await loadIntelQLocations();}
        });
        document.addEventListener('click', event => { if(event.target.closest?.('[data-page="intelligence"]')) setTimeout(() => refresh(false),350); });
        window.addEventListener('hashchange', () => { if(location.hash==='#intelligence') setTimeout(() => refresh(false),120); });
        window.addEventListener('storage', event => { if(event.key==='meteonexa_v3_location'){ lastLocationKey=''; lastRequestKey=''; if(location.hash==='#intelligence') refresh(true); } });
        // js/app.js emits this only after authentication/guest state and the final
        // location have been hydrated. This is the authoritative cold-boot trigger.
        document.addEventListener('meteonexa:ready', () => {
            if(state?.currentPage==='intelligence' || location.hash==='#intelligence') refresh(false);
        });
        populatePreferences();
    }
    const intelligenceApi=Object.freeze({ build:BUILD, refresh, syncProfile, current:() => currentData, reloadLocations:loadIntelQLocations });
    SERVICES.publish('intelligenceQuality', intelligenceApi);
    SERVICES.publish('intelligence', intelligenceApi);
    if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',bind,{once:true}); else bind();
})();
