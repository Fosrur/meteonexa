export const serviceNames = Object.freeze(['suiteRoute']);
export const dependencies = Object.freeze([]);

function factory(window, deps, provided) {
    void deps;
    provided.suiteRoute = Object.freeze({
        create(context) {
            const {
                CONFIG, SERVICES, API, suite, q, qa, n, clamp, safe, ui, localTime, tempText,
                haversine, bearing, nearestTimeIndex, fetchJson, normalizeLocation, searchCities,
                syncEnhancedSelect, locationLabel, geocodeCity, loader, refreshAssistantSuggestions,
                toast, weatherArt
            } = context;
            const maplibregl = window.maplibregl;
    function sampleGeometry(coordinates, count = 8) {
        if (!coordinates?.length)
            return [];
        const points = coordinates.map(([longitude, latitude]) => ({ latitude, longitude })), segments = [], total = points.slice(1).reduce((sum, point, i) => { const distance = haversine(points[i], point); segments.push(distance); return sum + distance; }, 0), result = [];
        for (let i = 0; i < count; i++) {
            const target = total * i / (count - 1);
            let walked = 0, index = 0;
            while (index < segments.length - 1 && walked + segments[index] < target) {
                walked += segments[index];
                index++;
            }
            const ratio = segments[index] ? clamp((target - walked) / segments[index], 0, 1) : 0, a = points[index], b = points[Math.min(points.length - 1, index + 1)];
            result.push({ latitude: a.latitude + (b.latitude - a.latitude) * ratio, longitude: a.longitude + (b.longitude - a.longitude) * ratio, ratio: i / (count - 1), bearing: bearing(a, b) });
        }
        return result;
    }
    async function routeWeather(points) { const data = await fetchJson(API.routeWeather,{method:'POST',timeout:26000,body:{points:points.map(point=>({latitude:Number(point.latitude),longitude:Number(point.longitude),ratio:Number(point.ratio||0),bearing:Number(point.bearing||0)}))}}); return Array.isArray(data?.weather)?data.weather:[]; }
    function routeRisk(row, index, bearingValue, mode) { const h = row.hourly || {}, temp = n(h.temperature_2m?.[index]), rain = n(h.precipitation_probability?.[index]), mm = n(h.precipitation?.[index]), snow = n(h.snowfall?.[index]), wind = n(h.wind_speed_10m?.[index]), gust = n(h.wind_gusts_10m?.[index]), windDirection = n(h.wind_direction_10m?.[index]), visibility = n(h.visibility?.[index]) / 1000, cape = n(h.cape?.[index]), humidity = n(h.relative_humidity_2m?.[index]), uv = n(h.uv_index?.[index]), crosswind = wind * Math.abs(Math.sin((windDirection - bearingValue) * Math.PI / 180)), ice = temp <= 2 && (mm > .05 || snow > 0), thunder = cape >= 800 || [95, 96, 99].includes(n(h.weather_code?.[index])); let score = Math.max(rain * .65, Math.min(100, gust * 1.2), visibility < 1 ? 95 : visibility < 3 ? 70 : 0, ice ? 90 : 0, thunder ? 90 : 0, Math.min(100, crosswind * (mode === 'bike' || mode === 'motorcycle' ? 2.2 : 1.2)));
        const profileKey = mode === 'motorcycle' ? 'motorcycle' : mode === 'bike' ? 'bike' : mode === 'walk' ? 'trekking' : null;
        const thresholds = profileKey ? SERVICES.get('accountSync')?.getActivityProfile?.(profileKey) : null;
        if (thresholds) {
            const rainMax=n(thresholds.rainMax),gustMax=n(thresholds.gustMax),tempMin=n(thresholds.tempMin),tempMax=n(thresholds.tempMax),visibilityMin=n(thresholds.visibilityMin);
            const personal=Math.max(rain>rainMax?55+Math.min(45,(rain-rainMax)*1.2):0,gust>gustMax?55+Math.min(45,(gust-gustMax)*2):0,temp<tempMin?55+Math.min(45,(tempMin-temp)*6):0,temp>tempMax?55+Math.min(45,(temp-tempMax)*6):0,visibility<visibilityMin?55+Math.min(45,(visibilityMin-visibility)*12):0);
            score=Math.max(score,personal);
        }
        return { score, temp, rain, mm, snow, wind, gust, windDirection, visibility, crosswind, ice, thunder, humidity, uv, code: n(h.weather_code?.[index]) }; }
    async function calculateRouteCore(origin, destination, departure, mode = 'car') {
        const p = new URLSearchParams({ originLat: origin.latitude, originLon: origin.longitude, destinationLat: destination.latitude, destinationLon: destination.longitude, mode: mode === 'motorcycle' ? 'car' : mode }), route = await fetchJson(`${API.route}?${p}`), points = sampleGeometry(route.geometry.coordinates, 24), weatherRows = await routeWeather(points), duration = route.durationSeconds / 3600;
        const evaluate = startDate => points.map((point, i) => { const at = new Date(startDate.getTime() + duration * point.ratio * 3600000), row = weatherRows[i], index = nearestTimeIndex(row.hourly?.time || [], at), risk = routeRisk(row, index, point.bearing, mode); return { ...point, at, name: i === 0 ? origin.name : i === points.length - 1 ? destination.name : ui('route.stop.number', { number: i + 1 }), ...risk }; });
        const candidates = [];
        for (let offset = -2; offset <= 12; offset++) {
            const at = new Date(departure.getTime() + offset * 30 * 60000);
            if (at < Date.now() - 1800000)
                continue;
            const rows = evaluate(at), score = Math.max(...rows.map(r => r.score));
            candidates.push({ at, score, rows });
        }
        const selected = evaluate(departure), selectedScore = Math.max(...selected.map(r => r.score));
        const best = [...candidates].sort((a, b) => a.score - b.score || a.at - b.at)[0] || { at: departure, score: selectedScore, rows: selected };
        return { origin, destination, mode, departure, route, points: selected, selectedScore, best, candidates, distanceKm: route.distanceMeters / 1000, durationHours: duration, steps: route.steps || [] };
    }
    function routeDateValue(date = new Date()) { const value = new Date(date); return `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`; }
    function syncRouteDeparture() {
        const dateNode = q('#route-departure-date'), timeNode = q('#route-departure-time'), target = q('#route-departure');
        if (!dateNode || !timeNode || !target)
            return;
        target.value = `${dateNode.value || routeDateValue(new Date())}T${timeNode.value || '09:00'}`;
    }
    function routeResultLabel(raw) {
        if (typeof normalizeLocation === 'function') {
            const item = normalizeLocation(raw);
            return [item.name, item.admin1, item.country].filter(Boolean).join(', ');
        }
        return [raw?.name, raw?.admin1, raw?.country].filter(Boolean).join(', ');
    }
    function bindRouteAutocomplete(inputSelector, resultsSelector) {
        const input = q(inputSelector), results = q(resultsSelector);
        if (!input || !results || input.dataset.autocompleteBound)
            return;
        input.dataset.autocompleteBound = 'true';
        let timer = 0;
        const clear = () => { results.innerHTML = ''; results.classList.remove('open'); };
        input.addEventListener('input', () => {
            clearTimeout(timer);
            const query = input.value.trim();
            if (query.length < 2) {
                clear();
                return;
            }
            timer = setTimeout(() => {
                if (typeof searchCities !== 'function')
                    return;
                results.classList.add('open');
                searchCities(query, results, raw => { input.value = routeResultLabel(raw); input.dataset.latitude = String(raw.latitude ?? ''); input.dataset.longitude = String(raw.longitude ?? ''); clear(); }, { compact: true });
            }, 240);
        });
        input.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                clear();
                input.blur();
            }
        });
        document.addEventListener('pointerdown', event => {
            if (!event.target.closest(inputSelector) && !event.target.closest(resultsSelector))
                clear();
        });
    }
    function initializeRouteControls() {
        const hidden = q('#route-departure'), date = q('#route-departure-date'), time = q('#route-departure-time');
        let initial = hidden?.value ? new Date(hidden.value) : new Date(Date.now() + 60 * 60 * 1000);
        if (Number.isNaN(initial.getTime()))
            initial = new Date(Date.now() + 60 * 60 * 1000);
        initial.setMinutes(initial.getMinutes() < 30 ? 30 : 0, 0, 0);
        if (initial.getMinutes() === 0 && initial.getTime() < Date.now())
            initial.setHours(initial.getHours() + 1);
        if (date && !date.value) {
            date.value = routeDateValue(initial);
            date.dispatchEvent(new Event("meteo-date-sync"));
        }
        if (time) {
            const timeValue = `${String(initial.getHours()).padStart(2, '0')}:${String(initial.getMinutes()).padStart(2, '0')}`;
            time.value = timeValue;
            if (typeof syncEnhancedSelect === 'function')
                syncEnhancedSelect('route-departure-time', timeValue);
        }
        syncRouteDeparture();
        date?.addEventListener("meteo-date-sync", syncRouteDeparture);
        date?.addEventListener('change', syncRouteDeparture);
        time?.addEventListener('change', syncRouteDeparture);
        const originInput = q('#route-origin');
        if (originInput) {
            const currentOrigin = String(originInput.value || '').trim();
            if (!currentOrigin || currentOrigin.includes(CONFIG.DEFAULT_LOCATION.nameKey) || currentOrigin.includes(CONFIG.DEFAULT_LOCATION.admin1Key))
                originInput.value = locationLabel();
        }
        bindRouteAutocomplete('#route-origin', '#route-origin-results');
        bindRouteAutocomplete('#route-destination', '#route-destination-results');
        showRouteState(Boolean(suite.route));
    }
    function showRouteState(hasRoute) {
        const empty = q('#route-empty-state'), results = q('#route-results');
        if (empty)
            empty.hidden = hasRoute;
        if (results)
            results.hidden = !hasRoute;
    }
    function routeRiskLabel(score) { return score >= 75 ? ui('route.risk.high') : score >= 45 ? ui('route.risk.medium') : ui('route.risk.low'); }
    function distanceLabel(meters) { return meters >= 1000 ? ui('route.distance.km', { value: (meters / 1000).toFixed(meters >= 10000 ? 0 : 1) }) : ui('route.distance.m', { value: Math.max(1, Math.round(meters)) }); }
    function routeInstruction(step) {
        const maneuver = step?.maneuver || {}, name = step?.name || ui('route.instruction.road'), params = { street: name };
        if (maneuver.type === 'depart')
            return ui('route.instruction.depart', params);
        if (maneuver.type === 'arrive')
            return ui('route.instruction.arrive', params);
        if (maneuver.type === 'roundabout' || maneuver.type === 'rotary')
            return ui('route.instruction.roundabout', params);
        const modifier = String(maneuver.modifier || '').replaceAll(' ', '_');
        const key = ['left', 'right', 'slight_left', 'slight_right', 'sharp_left', 'sharp_right', 'straight', 'uturn'].includes(modifier) ? `route.instruction.${modifier}` : 'route.instruction.continue';
        return ui(key, params);
    }
    async function calculateRouteUI() {
        const originText = q('#route-origin')?.value.trim(), destinationText = q('#route-destination')?.value.trim();
        if (!originText || !destinationText) {
            toast(ui('route.toast.incomplete.title'), ui('route.toast.incomplete.copy'), 'warning');
            return;
        }
        await loader(ui('route.loader.title'), ui('route.loader.copy'), async () => { const [origin, destination] = await Promise.all([geocodeCity(originText), geocodeCity(destinationText)]), departure = new Date(q('#route-departure')?.value || Date.now()), mode = q('#suite-route-mode')?.value || 'car'; suite.route = await calculateRouteCore(origin, destination, departure, mode); SERVICES.get('routeWeather')?.publish?.(suite.route); renderRoute(); refreshAssistantSuggestions(); });
    }
    function routeCriticalLabel(row) {
        if (!row) return ui('route.intelligence.condition.clear');
        if (row.thunder) return ui('route.intelligence.condition.storm');
        if (row.ice) return ui('route.intelligence.condition.ice');
        if (row.visibility < 3) return ui('route.intelligence.condition.visibility', { value: row.visibility.toFixed(1) });
        if (row.crosswind >= 25 || row.gust >= 45) return ui('route.intelligence.condition.wind', { value: Math.round(Math.max(row.crosswind, row.gust)) });
        if (row.rain >= 55 || row.mm >= 1) return ui('route.intelligence.condition.rain', { value: Math.round(row.rain) });
        return ui('route.intelligence.condition.clear');
    }
    function renderRouteIntelligence(data) {
        const root = q('#route-intelligence-grid');
        const scoreNode = q('#route-intelligence-score');
        if (!root || !data) return;
        const selectedRisk = Math.round(n(data.selectedScore ?? Math.max(...data.points.map(p => p.score))));
        const bestRisk = Math.round(n(data.best?.score ?? selectedRisk));
        const improvement = Math.max(0, selectedRisk - bestRisk);
        const critical = (data.points || []).reduce((worst, row) => n(row.score) > n(worst?.score) ? row : worst, null);
        const bestCritical = (data.best?.rows || []).reduce((worst, row) => n(row.score) > n(worst?.score) ? row : worst, null);
        const safety = Math.max(0, 100 - bestRisk);
        if (scoreNode) scoreNode.textContent = `${safety}/100`;
        const departureCopy = improvement >= 8
            ? ui('route.intelligence.departure.improves', { time: localTime(data.best.at), from: selectedRisk, to: bestRisk })
            : ui('route.intelligence.departure.stable', { time: localTime(data.best.at), risk: bestRisk });
        const criticalCopy = critical
            ? ui('route.intelligence.critical.value', { stop: critical.name, time: localTime(critical.at), condition: routeCriticalLabel(critical) })
            : ui('route.intelligence.condition.clear');
        const avoidedCopy = improvement >= 8
            ? ui('route.intelligence.avoided.value', { condition: routeCriticalLabel(critical), bestCondition: routeCriticalLabel(bestCritical), points: improvement })
            : ui('route.intelligence.avoided.none');
        const rows = [
            ['i-clock', 'route.intelligence.departure.label', departureCopy],
            ['i-alert', 'route.intelligence.critical.label', criticalCopy],
            ['i-shield', 'route.intelligence.avoided.label', avoidedCopy]
        ];
        root.innerHTML = rows.map(([icon, label, value]) => `<div class="route-intelligence-item"><span><svg><use href="#${icon}"/></svg></span><div><small>${safe(ui(label))}</small><strong>${safe(value)}</strong></div></div>`).join('');
    }
    function renderRouteDepartureComparison(data) {
        const root = q('#route-departure-comparison');
        if (!root || !data) return;
        const candidates = (Array.isArray(data.candidates) ? data.candidates : []).slice().sort((a,b) => new Date(a.at) - new Date(b.at));
        if (!candidates.length) { root.innerHTML = `<div class="route-direction-empty">${safe(ui('route.compare.unavailable'))}</div>`; return; }
        const selectedTs = new Date(data.departure).getTime(), bestTs = new Date(data.best?.at || data.departure).getTime();
        root.innerHTML = candidates.map(candidate => {
            const ts = new Date(candidate.at).getTime(), risk = Math.max(0, Math.min(100, Math.round(n(candidate.score))));
            const isBest = Math.abs(ts - bestTs) < 1000, selected = Math.abs(ts - selectedTs) < 1000;
            const tags = [isBest ? ui('route.compare.best') : '', selected ? ui('route.compare.selected') : ''].filter(Boolean).join(' · ');
            return `<button class="route-departure-option${isBest?' is-best':''}${selected?' is-selected':''}" data-route-departure-at="${safe(new Date(candidate.at).toISOString())}" type="button" style="--risk:${risk}"><div class="route-departure-option-head"><strong>${safe(localTime(candidate.at))}</strong>${tags?`<span>${safe(tags)}</span>`:''}</div><div class="route-departure-riskbar"><i></i></div><small>${safe(ui('route.compare.risk',{value:risk}))}</small></button>`;
        }).join('');
    }
    function applyRouteDepartureCandidate(rawAt) {
        const data = suite.route;
        if (!data) return;
        const target = new Date(rawAt).getTime();
        const candidate = (data.candidates || []).find(row => Math.abs(new Date(row.at).getTime() - target) < 1000);
        if (!candidate) return;
        data.departure = new Date(candidate.at); data.points = candidate.rows; data.selectedScore = candidate.score;
        const date=q('#route-departure-date'), time=q('#route-departure-time');
        if (date) { date.value=routeDateValue(data.departure); date.dispatchEvent(new Event('meteo-date-sync')); }
        if (time) { const value=`${String(data.departure.getHours()).padStart(2,'0')}:${String(data.departure.getMinutes()).padStart(2,'0')}`; time.value=value; if(typeof syncEnhancedSelect==='function')syncEnhancedSelect('route-departure-time',value); }
        syncRouteDeparture(); renderRoute();
        toast(ui('route.compare.applied.title'),ui('route.compare.applied.copy',{time:localTime(data.departure)}),'success');
    }
    function renderRoute() {
        const data = suite.route;
        if (!data) {
            showRouteState(false);
            return;
        }
        showRouteState(true);
        const risk = Math.max(...data.points.map(p => p.score));
        q('#route-title').textContent = `${data.origin.name} → ${data.destination.name}`;
        q('#route-copy').textContent = risk >= 75 ? ui('route.summary.copy.high') : risk >= 45 ? ui('route.summary.copy.medium') : ui('route.summary.copy.low');
        q('#route-distance').textContent = `${Math.round(data.distanceKm)} km`;
        q('#route-duration').textContent = ui('route.duration.value', { hours: Math.floor(data.durationHours), minutes: Math.round(data.durationHours % 1 * 60) });
        q('#route-risk').textContent = routeRiskLabel(risk);
        q('#suite-route-best-time').textContent = data.best ? localTime(data.best.at) : '--';
        renderRouteIntelligence(data);
        renderRouteDepartureComparison(data);
        q('#route-timeline').innerHTML = data.points.map((p, i) => { const tags = [p.ice ? ui('route.tag.ice') : '', p.thunder ? ui('route.tag.storm') : '', p.snow > 0 ? ui('route.tag.snow') : '', p.crosswind >= 25 ? ui('route.tag.crosswind') : '', p.visibility < 3 ? ui('route.tag.visibility') : ''].filter(Boolean); return `<article class="route-stop"><div class="route-stop-line"><i></i><span>${i + 1}</span></div><div class="route-stop-card"><div class="route-stop-head"><div><small>${safe(localTime(p.at))}</small><strong>${safe(p.name)}</strong></div>${weatherArt(p.code, 1)}<b>${tempText(p.temp)}</b></div><div class="route-stop-metrics"><span><svg><use href="#i-umbrella"/></svg><strong>${Math.round(p.rain)}%</strong><small>${p.mm.toFixed(1)} mm</small></span><span><svg><use href="#i-wind"/></svg><strong>${Math.round(p.wind)} km/h</strong><small>${safe(ui('route.metric.crosswind', { value: Math.round(p.crosswind) }))}</small></span><span><svg><use href="#i-eye"/></svg><strong>${p.visibility.toFixed(1)} km</strong><small>${safe(ui('route.metric.visibility'))}</small></span></div>${tags.length ? `<div class="suite-risk-tags">${tags.map(tag => `<em>${safe(tag)}</em>`).join('')}</div>` : ''}</div></article>`; }).join('');
        renderRouteDirections();
        renderRouteMap();
    }
    function renderRouteDirections() {
        const root = q('#route-directions');
        if (!root)
            return;
        const steps = suite.route?.steps || [];
        root.innerHTML = steps.length ? steps.map((step, index) => `<article class="route-direction-step" data-route-step="${index}"><span>${index + 1}</span><div><strong>${safe(routeInstruction(step))}</strong><small>${safe(distanceLabel(n(step.distance)))} · ${safe(ui('route.duration.minutes', { minutes: Math.max(1, Math.round(n(step.duration) / 60)) }))}</small></div></article>`).join('') : `<div class="route-direction-empty">${safe(ui('route.directions.empty'))}</div>`;
    }
    function renderRouteMap() {
        const panel = q('#suite-route-map-panel'), root = q('#suite-route-map');
        if (!panel || !root || !window.maplibregl || !suite.route)
            return;
        suite.routeMapResizeObserver?.disconnect();
        suite.routeMapResizeObserver = null;
        if (suite.routeMap) {
            suite.routeMap.remove();
            suite.routeMap = null;
            suite.routeNavigation.marker = null;
        }
        const map = new maplibregl.Map({ container: root, style: CONFIG.OPENFREEMAP_STYLE, center: [suite.route.origin.longitude, suite.route.origin.latitude], zoom: 6, attributionControl: false });
        suite.routeMap = map;
        if (typeof ResizeObserver === 'function') {
            suite.routeMapResizeObserver = new ResizeObserver(() => {
                if (suite.routeMap === map)
                    map.resize();
            });
            suite.routeMapResizeObserver.observe(root);
        }
        map.on('load', () => {
            map.addSource('route-line', { type: 'geojson', data: { type: 'Feature', geometry: suite.route.route.geometry, properties: {} } });
            map.addLayer({ id: 'route-line', type: 'line', source: 'route-line', paint: { 'line-color': '#4fd8c8', 'line-width': 5, 'line-opacity': .88 } });
            suite.route.points.forEach((point, i) => new maplibregl.Marker({ color: i === 0 ? '#4fd8c8' : i === suite.route.points.length - 1 ? '#ff8b5f' : '#ffd456' }).setLngLat([point.longitude, point.latitude]).setPopup(new maplibregl.Popup({ offset: 18 }).setHTML(`<div class="suite-map-popup"><strong>${safe(point.name)}</strong><span>${safe(localTime(point.at))} · ${safe(ui('route.map.risk', { value: Math.round(point.score) }))}</span></div>`)).addTo(map));
            const bounds = new maplibregl.LngLatBounds();
            suite.route.route.geometry.coordinates.forEach(c => bounds.extend(c));
            const fitRoute = () => {
                if (suite.routeMap !== map)
                    return;
                map.resize();
                map.fitBounds(bounds, { padding: 45, duration: 0 });
            };
            requestAnimationFrame(fitRoute);
            setTimeout(fitRoute, 80);
        });
    }
    function nearestRoutePoint(position) { return (suite.route?.points || []).reduce((best, row) => { const distance = haversine(position, row); return !best || distance < best.distance ? { row, distance } : best; }, null); }
    function nearestRouteStep(position) {
        return (suite.route?.steps || []).reduce((best, row, index) => {
            const location = row?.maneuver?.location;
            if (!Array.isArray(location))
                return best;
            const distance = haversine(position, { latitude: n(location[1]), longitude: n(location[0]) });
            return !best || distance < best.distance ? { row, index, distance } : best;
        }, null);
    }
    function updateNavigation(position) {
        if (!suite.routeMap || !suite.route)
            return;
        const coords = [position.longitude, position.latitude];
        if (!suite.routeNavigation.marker)
            suite.routeNavigation.marker = new maplibregl.Marker({ color: '#37a7ff' }).setLngLat(coords).addTo(suite.routeMap);
        else
            suite.routeNavigation.marker.setLngLat(coords);
        suite.routeMap.easeTo({ center: coords, zoom: 13, duration: 650 });
        const nearest = nearestRoutePoint(position), step = nearestRouteStep(position);
        if (step) {
            q('#route-navigation-instruction').textContent = routeInstruction(step.row);
            q('#route-navigation-distance').textContent = distanceLabel(step.distance * 1000);
            qa('[data-route-step]').forEach((node, index) => node.classList.toggle('active', index === step.index));
        }
        if (nearest) {
            q('#route-navigation-weather').textContent = ui('route.navigation.weather', { temperature: tempText(nearest.row.temp), rain: Math.round(nearest.row.rain), wind: Math.round(nearest.row.wind) });
        }
    }
    function startRouteNavigation() {
        if (!suite.route)
            return;
        if (!navigator.geolocation) {
            toast(ui('route.navigation.unavailable.title'), ui('route.navigation.unavailable.copy'), 'warning');
            return;
        }
        q('#route-navigation-panel').hidden = false;
        suite.routeNavigation.active = true;
        suite.routeNavigation.watchId = navigator.geolocation.watchPosition(event => updateNavigation({ latitude: event.coords.latitude, longitude: event.coords.longitude }), error => { console.warn('ROUTE_GEOLOCATION_FAILED', error); toast(ui('route.navigation.error.title'), ui('route.navigation.error.copy'), 'error'); }, { enableHighAccuracy: true, maximumAge: 5000, timeout: 15000 });
        q('#route-navigation-instruction').textContent = ui('route.navigation.waiting');
        q('#route-navigation-weather').textContent = ui('route.navigation.waiting.copy');
    }
    function stopRouteNavigation() {
        if (suite.routeNavigation.watchId !== null)
            navigator.geolocation.clearWatch(suite.routeNavigation.watchId);
        suite.routeNavigation.watchId = null;
        suite.routeNavigation.active = false;
        suite.routeNavigation.marker?.remove();
        suite.routeNavigation.marker = null;
        const panel = q('#route-navigation-panel');
        if (panel)
            panel.hidden = true;
        qa('[data-route-step]').forEach(node => node.classList.remove('active'));
    }
    function clearRoute() { stopRouteNavigation(); suite.routeMapResizeObserver?.disconnect(); suite.routeMapResizeObserver = null; suite.routeMap?.remove(); suite.routeMap = null; suite.route = null; SERVICES.get('routeWeather')?.publish?.(null); q('#route-timeline').innerHTML = ''; q('#route-directions').innerHTML = ''; showRouteState(false); refreshAssistantSuggestions(); q('#route-empty-state')?.scrollIntoView({ behavior: 'smooth', block: 'center' }); }

            return Object.freeze({
                calculateRouteCore,
                initializeRouteControls,
                calculateRouteUI,
                applyRouteDepartureCandidate,
                startRouteNavigation,
                stopRouteNavigation,
                clearRoute
            });
        }
    });
}

export function install(services, host = globalThis) {
    return services.installModule({ services, host, provides: serviceNames, dependencies, factory });
}
