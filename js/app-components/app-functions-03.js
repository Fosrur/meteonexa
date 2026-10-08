'use strict';
function openHourDetail(index) {
    const hourly = state.weather?.hourly;
    if (!hourly?.time?.[index])
        return;
    state.selectedHourIndex = index;
    const time = hourly.time[index];
    const resolved = resolveFusedHourlyCondition(state.weather, index);
    const meta = resolved.meta;
    const previous = Number(hourly.temperature_2m[index - 1] ?? hourly.temperature_2m[index]);
    const current = Number(hourly.temperature_2m[index] ?? 0);
    const delta = current - previous;
    const displayDelta = state.settings.unit === 'fahrenheit' ? delta * 9 / 5 : delta;
    const trend = Math.abs(delta) < .2 ? "" + meteonexaText("weather.metricnotepressure.stable") : meteonexaText('hour.trend.delta', { value: `${displayDelta > 0 ? '+' : ''}${displayDelta.toFixed(1)}` });
    const wind = Number(hourly.wind_speed_10m[index] || 0);
    const gust = Number(hourly.wind_gusts_10m?.[index] || wind);
    const visibilityKm = Number(hourly.visibility[index] || 0) / 1000;
    const dateLabel = new Intl.DateTimeFormat(appLocale(), { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }).format(new Date(`${String(time).slice(0, 10)}T12:00:00Z`));
    $('#hour-detail-title').textContent = `${formatClock(time)} · ${capitalize(dateLabel)}`;
    $('#hour-detail-subtitle').textContent = `${shortLocationLabel()} · ${meta.label} · ${resolvedConditionEvidenceLabel(resolved)} · ${timeZoneOffsetLabel()}`;
    $('#hour-detail-art').innerHTML = weatherArt(resolved.code, resolved.isDay);
    $('#hour-detail-temp').textContent = temperature(hourly.temperature_2m[index]);
    $('#hour-detail-condition').textContent = resolved.dry ? t('weather.reliability.dry_condition', { condition: meta.label }) : meta.label;
    $('#hour-detail-trend').textContent = trend;
    $('#hour-detail-grid').innerHTML = [
        hourMetric("" + meteonexaText("history.renderhistory.feels_like"), temperature(hourly.apparent_temperature[index]), "" + meteonexaText("app.openhourdetail.thermal_sensation")),
        hourMetric("" + meteonexaText("history.renderhistory.rain"), `${Math.round(hourly.precipitation_probability[index] || 0)}%`, `${Number(resolved.precipitationMm || 0).toFixed(1)} mm`),
        hourMetric("" + meteonexaText("visualization.take.humidity"), `${Math.round(hourly.relative_humidity_2m[index] || 0)}%`, metricNoteHumidity(hourly.relative_humidity_2m[index] || 0)),
        hourMetric("" + meteonexaText("app.openhourdetail.dew_point"), temperature(hourly.dew_point_2m?.[index]), "" + meteonexaText("app.openhourdetail.perceived_condensation")),
        hourMetric("" + meteonexaText("visualization.take.wind"), `${Math.round(wind)} km/h ${windDirection(hourly.wind_direction_10m[index])}`, meteonexaText("app.openhourdetail.gusts_value_km_h", { value: Math.round(gust) })),
        hourMetric("" + meteonexaText("visualization.take.pressure"), `${Math.round(hourly.surface_pressure[index] || 0)} hPa`, metricNotePressure(hourly.surface_pressure[index] || 0)),
        hourMetric("" + meteonexaText("app.openhourdetail.visibility"), `${visibilityKm.toFixed(1)} km`, metricNoteVisibility(hourly.visibility[index] || 0)),
        hourMetric("" + meteonexaText("visualization.take.uv_index"), Number(hourly.uv_index[index] || 0).toFixed(1), uvLabel(hourly.uv_index[index] || 0)),
        hourMetric("" + meteonexaText("visualization.take.cloud_cover"), `${Math.round(hourly.cloud_cover[index] || 0)}%`, meta.label)
    ].join('');
    $('#hour-detail-prev').disabled = index <= 0;
    $('#hour-detail-next').disabled = index >= hourly.time.length - 1;
    const dialog = $('#hour-detail-dialog');
    if (!dialog.open)
        dialog.showModal();
}
function stepHourDetail(delta) {
    if (!Number.isInteger(state.selectedHourIndex))
        return;
    openHourDetail(clamp(state.selectedHourIndex + delta, 0, state.weather.hourly.time.length - 1));
}
function renderDaily() {
    const daily = state.weather.daily;
    const root = $('#daily-forecast');
    root.innerHTML = daily.time.slice(0, 10).map((date, index) => {
        const meta = weatherMeta(daily.weather_code[index], 1);
        return `<button class="day-row" type="button" data-day-index="${index}" aria-label="${escapeHTML(t('day.open_detail', { day: formatDay(date, index), condition: meta.label }))}">
      <div class="day-label"><strong>${escapeHTML(formatDay(date, index))}</strong><small>${escapeHTML(formatShortDate(date))}</small></div>
      ${weatherArt(daily.weather_code[index], 1)}
      <span class="day-rain"><svg><use href="#i-droplet"/></svg>${Math.round(daily.precipitation_probability_max[index] || 0)}%</span>
      <span class="day-temp"><span>${temperature(daily.temperature_2m_max[index])}</span><span>${temperature(daily.temperature_2m_min[index])}</span></span>
      <span class="day-row-chevron"><svg><use href="#i-chevron"/></svg></span>
    </button>`;
    }).join('');
    $$('.day-row', root).forEach(row => row.addEventListener('click', () => openDayDetail(Number(row.dataset.dayIndex))));
}
function formatDuration(seconds) {
    const totalMinutes = Math.max(0, Math.round(Number(seconds || 0) / 60));
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    return t("app.formatduration.value_h_value_min", { hours, minutes });
}
function dayHourlyIndices(dateValue) {
    const times = state.weather?.hourly?.time || [];
    return times.map((time, index) => ({ time, index }))
        .filter(item => String(item.time).slice(0, 10) === String(dateValue))
        .map(item => item.index);
}
function openDayDetail(index) {
    const daily = state.weather?.daily;
    if (!daily?.time?.[index])
        return;
    state.selectedDayIndex = index;
    const date = daily.time[index];
    const meta = weatherMeta(daily.weather_code[index], 1);
    const max = Number(daily.temperature_2m_max[index] || 0);
    const min = Number(daily.temperature_2m_min[index] || 0);
    const rangeC = max - min;
    const range = state.settings.unit === 'fahrenheit' ? rangeC * 9 / 5 : rangeC;
    const sunrise = formatClock(daily.sunrise[index]);
    const sunset = formatClock(daily.sunset[index]);
    const titleDate = capitalize(new Intl.DateTimeFormat(appLocale(), { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }).format(new Date(`${date}T12:00:00Z`)));
    $('#day-detail-title').textContent = titleDate;
    $('#day-detail-subtitle').textContent = `${shortLocationLabel()} · ${meta.label} · ${timeZoneOffsetLabel()}`;
    $('#day-detail-art').innerHTML = weatherArt(daily.weather_code[index], 1);
    $('#day-detail-temp').textContent = `${temperature(max)} / ${temperature(min)}`;
    $('#day-detail-condition').textContent = meta.label;
    $('#day-detail-range').textContent = `${range.toFixed(1)}°`;
    $('#day-detail-grid').innerHTML = [
        hourMetric(t("history.renderhistory.rain"), `${Math.round(daily.precipitation_probability_max[index] || 0)}%`, `${Number(daily.precipitation_sum[index] || 0).toFixed(1)} mm`),
        hourMetric(t("intelligence.rendermodelcomparison.maximum_wind"), `${Math.round(daily.wind_speed_10m_max[index] || 0)} km/h`, `${t("app.opendaydetail.maximum_gust")} ${Math.round(daily.wind_gusts_10m_max[index] || 0)} km/h`),
        hourMetric(t("app.opendaydetail.maximum_uv"), Number(daily.uv_index_max[index] || 0).toFixed(1), uvLabel(daily.uv_index_max[index] || 0)),
        hourMetric(t("app.opendaydetail.sunrise_sunset"), `${sunrise} / ${sunset}`, t("app.opendaydetail.sun")),
        hourMetric(t("app.opendaydetail.daylight_duration"), formatDuration(daily.daylight_duration[index]), `${formatDuration(daily.sunshine_duration[index])} ${t("app.opendaydetail.sun").toLowerCase()}`)
    ].join('');
    const hourly = state.weather.hourly;
    const indices = dayHourlyIndices(date);
    const hourlyRoot = $('#day-detail-hourly');
    hourlyRoot.innerHTML = indices.map(hourIndex => {
        const hourResolved = resolveFusedHourlyCondition(state.weather, hourIndex);
        const hourMeta = hourResolved.meta;
        return `<button class="hour-card day-hour-card" type="button" data-hour-index="${hourIndex}" aria-label="${escapeHTML(t('hour.open_detail', { time: formatClock(hourly.time[hourIndex]), condition: hourMeta.label }))}">
      <time>${escapeHTML(formatClock(hourly.time[hourIndex]))}</time>
      ${weatherArt(hourResolved.code, hourResolved.isDay)}
      <span class="hour-card-condition ${hourResolved.dry ? 'is-dry' : ''}">${escapeHTML(hourResolved.dry ? t('weather.reliability.dry_short') : hourMeta.label)}</span>
      <strong>${temperature(hourly.temperature_2m[hourIndex])}</strong>
      <small><svg><use href="#i-droplet"/></svg>${Number(hourResolved.precipitationMm || 0).toLocaleString(appLocale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 })} mm · ${Math.round(hourly.precipitation_probability[hourIndex] || 0)}%</small>
      <span class="hour-card-more">${escapeHTML(t("history.renderhistory.details"))}</span>
    </button>`;
    }).join('');
    $$('.day-hour-card', hourlyRoot).forEach(card => card.addEventListener('click', () => {
        $('#day-detail-dialog').close();
        requestAnimationFrame(() => openHourDetail(Number(card.dataset.hourIndex)));
    }));
    $('#day-detail-prev').disabled = index <= 0;
    $('#day-detail-next').disabled = index >= daily.time.length - 1;
    const dialog = $('#day-detail-dialog');
    if (!dialog.open)
        dialog.showModal();
    translateDOM(dialog);
}
function stepDayDetail(delta) {
    if (!Number.isInteger(state.selectedDayIndex))
        return;
    openDayDetail(clamp(state.selectedDayIndex + delta, 0, state.weather.daily.time.length - 1));
}
function airCurrentValues() {
    const hourly = state.air?.hourly;
    if (!hourly?.time?.length)
        return null;
    const index = localSeriesIndex(hourly.time, { weatherData: state.air });
    return {
        aqi: Number(hourly.european_aqi?.[index] ?? 0),
        pm10: Number(hourly.pm10?.[index] ?? 0),
        pm25: Number(hourly.pm2_5?.[index] ?? 0),
        ozone: Number(hourly.ozone?.[index] ?? 0),
        no2: Number(hourly.nitrogen_dioxide?.[index] ?? 0)
    };
}
function aqiMeta(value) {
    if (value <= 20)
        return { label: "" + meteonexaText("app.aqimeta.excellent"), description: "" + meteonexaText("app.aqimeta.very_clean_air_ideal_conditions_spending_time_outdoors"), color: '#48e39a' };
    if (value <= 40)
        return { label: "" + meteonexaText("weather.metricnotevisibility.good"), description: "" + meteonexaText("app.aqimeta.air_quality_good_most_people"), color: '#70e77d' };
    if (value <= 60)
        return { label: "" + meteonexaText("intelligence.extractnowcast.moderate"), description: "" + meteonexaText("app.aqimeta.fair_air_quality_sensitive_people_may_wish_limit"), color: '#ffd25e' };
    if (value <= 80)
        return { label: "" + meteonexaText("app.aqimeta.poor"), description: "" + meteonexaText("app.aqimeta.consider_less_intense_activities_especially_if_sensitive_pollutants"), color: '#ff9d52' };
    return { label: "" + meteonexaText("app.aqimeta.very_poor"), description: "" + meteonexaText("app.aqimeta.reduce_outdoor_activities_follow_local_guidance"), color: '#ff6572' };
}
function renderAirQuality() {
    const values = airCurrentValues();
    if (!values)
        return;
    const meta = aqiMeta(values.aqi);
    $('#aqi-value').textContent = Math.round(values.aqi);
    $('#aqi-badge').textContent = meta.label;
    $('#aqi-badge').style.color = meta.color;
    $('#aqi-badge').style.background = `${meta.color}18`;
    $('#aqi-label').textContent = meteonexaText("app.renderairquality.value_air", { quality: meta.label.toLowerCase() });
    $('#aqi-description').textContent = meta.description;
    $('#pm25').textContent = `${values.pm25.toFixed(1)} µg/m³`;
    $('#pm10').textContent = `${values.pm10.toFixed(1)} µg/m³`;
    $('#ozone').textContent = `${values.ozone.toFixed(0)} µg/m³`;
    $('#no2').textContent = `${values.no2.toFixed(0)} µg/m³`;
    const pathLength = 188.5;
    const fraction = clamp(values.aqi / 120, 0, 1);
    $('#aqi-progress').style.strokeDashoffset = String(pathLength * (1 - fraction));
    $('#aqi-progress').style.stroke = meta.color;
}
function renderSun() {
    const daily = state.weather.daily;
    const sunrise = new Date(daily.sunrise[0]);
    const sunset = new Date(daily.sunset[0]);
    const now = new Date();
    const daylight = Number(daily.daylight_duration?.[0] || (sunset - sunrise) / 1000);
    $('#sunrise').textContent = formatClock(sunrise);
    $('#sunset').textContent = formatClock(sunset);
    $('#solar-noon').textContent = formatClock(new Date((sunrise.getTime() + sunset.getTime()) / 2));
    $('#daylight-duration').textContent = meteonexaText("app.rendersun.value_h_value_min_daylight", { hours: Math.floor(daylight / 3600), minutes: Math.round((daylight % 3600) / 60) });
    const progress = clamp((now - sunrise) / Math.max(1, sunset - sunrise), 0, 1);
    const x = 6 + progress * 88;
    const y = 18 + Math.sin(progress * Math.PI) * 58;
    $('#sun-position').style.left = `calc(${x}% - 15px)`;
    $('#sun-position').style.bottom = `${17 + y}px`;
}
function findBestHour(predicate, maxHours = 24) {
    const data = state.weather;
    const start = currentHourlyIndex(data);
    let best = null;
    for (let i = start; i < Math.min(start + maxHours, data.hourly.time.length); i += 1) {
        const item = {
            index: i, time: data.hourly.time[i], temp: data.hourly.temperature_2m[i],
            rain: data.hourly.precipitation_probability[i], wind: data.hourly.wind_speed_10m[i],
            uv: data.hourly.uv_index[i], code: data.hourly.weather_code[i], isDay: data.hourly.is_day[i]
        };
        const score = predicate(item);
        if (score !== null && (!best || score > best.score))
            best = { ...item, score };
    }
    return best;
}
function renderInsights() {
    const walk = findBestHour(item => item.rain < 35 && item.temp > 12 && item.temp < 29 ? 100 - item.rain - Math.abs(item.temp - 21) * 2 - item.wind : null);
    const laundry = findBestHour(item => item.rain < 20 && item.isDay ? 100 - item.rain - item.wind / 2 : null);
    const sport = findBestHour(item => item.rain < 30 && item.uv < 6 && item.temp > 10 && item.temp < 27 ? 100 - item.rain - Math.abs(item.temp - 19) * 3 : null);
    const umbrella = findBestHour(item => item.rain >= 40 ? item.rain : null);
    const cards = [
        { icon: 'i-sun', title: "" + meteonexaText("app.renderinsights.walk"), copy: walk ? `${formatClock(walk.time)}, ${temperature(walk.temp)}` : "" + meteonexaText("app.renderinsights.better_postpone"), note: walk ? "" + meteonexaText("app.renderinsights.favorable_window") : "" + meteonexaText("app.renderinsights.variable_conditions") },
        { icon: 'i-wind', title: "" + meteonexaText("app.renderinsights.laundry"), copy: laundry ? meteonexaText("app.renderinsights.value_rain_value", { time: formatClock(laundry.time), value: Math.round(laundry.rain) }) : "" + meteonexaText("app.renderinsights.no_ideal_window"), note: laundry ? "" + meteonexaText("app.renderinsights.good_drying_conditions") : "" + meteonexaText("app.renderinsights.humidity_risk") },
        { icon: 'i-chart', title: "" + meteonexaText("app.renderinsights.outdoor_sports"), copy: sport ? `${formatClock(sport.time)}, UV ${Number(sport.uv).toFixed(1)}` : "" + meteonexaText("app.renderinsights.better_indoors"), note: sport ? "" + meteonexaText("app.renderinsights.best_comfort") : "" + meteonexaText("app.renderinsights.unfavorable_weather") },
        { icon: 'i-umbrella', title: "" + meteonexaText("intelligence.renderintelligencedecisions.umbrella"), copy: umbrella ? meteonexaText("app.renderinsights.possible_from_value", { time: formatClock(umbrella.time) }) : "" + meteonexaText("intelligence.renderintelligencedecisions.not_needed"), note: umbrella ? meteonexaText("app.renderinsights.value_probability", { value: Math.round(umbrella.rain) }) : "" + meteonexaText("app.renderinsights.low_risk") }
    ];
    $('#activity-insights').innerHTML = cards.map(card => `<article class="activity-card"><span><svg><use href="#${card.icon}"/></svg></span><strong>${escapeHTML(card.title)}</strong><small>${escapeHTML(card.copy)}</small><em>${escapeHTML(card.note)}</em></article>`).join('');
}
function modelDisplayName(id) {
    const key = {
        ecmwf: 'provider.ecmwf',
        aifs: 'provider.aifs',
        icon: 'provider.icon',
        gfs: 'provider.gfs',
        meteofrance: 'provider.meteofrance',
        ukmo: 'provider.ukmo'
    }[String(id || '').toLowerCase()];
    return key ? t(key) : String(id || '');
}
function impactActivityMeta(id) {
    return {
        run: { label: 'impact.activity.run', icon: 'i-gauge' },
        sea: { label: 'impact.activity.sea', icon: 'i-sun' },
        laundry: { label: 'impact.activity.laundry', icon: 'i-wind' },
        motorcycle: { label: 'impact.activity.motorcycle', icon: 'i-route' },
        trekking: { label: 'impact.activity.trekking', icon: 'i-location' },
        kids: { label: 'impact.activity.kids', icon: 'i-star' }
    }[id] || { label: 'impact.activity.run', icon: 'i-gauge' };
}
function impactHourlyRows(hours = 12) {
    const h = state.weather?.hourly;
    if (!h?.time?.length) return [];
    const start = currentHourlyIndex(state.weather);
    return h.time.slice(start, start + hours).map((time, offset) => {
        const index = start + offset;
        return {
            time,
            temperature: Number(h.temperature_2m?.[index] ?? state.weather?.current?.temperature_2m ?? 0),
            feels: Number(h.apparent_temperature?.[index] ?? h.temperature_2m?.[index] ?? 0),
            rain: Number(h.precipitation_probability?.[index] ?? 0),
            precipitation: Number(h.precipitation?.[index] ?? 0),
            gust: Number(h.wind_gusts_10m?.[index] ?? h.wind_speed_10m?.[index] ?? 0),
            wind: Number(h.wind_speed_10m?.[index] ?? 0),
            humidity: Number(h.relative_humidity_2m?.[index] ?? 0),
            visibility: Number(h.visibility?.[index] ?? 10000) / 1000,
            uv: Number(h.uv_index?.[index] ?? 0),
            cloud: Number(h.cloud_cover?.[index] ?? 0)
        };
    });
}
function impactScoreForRow(activity, row) {
    const penalties = [];
    const add = (value, key, params = {}) => {
        if (Number(value) > 0) penalties.push({ value: Number(value), key, params });
    };
    const rainPenalty = Math.max(0, row.rain - 20) * 0.55 + Math.min(35, row.precipitation * 12);
    if (activity === 'run') {
        add(rainPenalty, 'impact.reason.rain', { value: Math.round(row.rain) });
        add(Math.max(0, row.gust - 28) * 1.25, 'impact.reason.wind', { value: Math.round(row.gust) });
        add(row.feels < 5 ? (5 - row.feels) * 3.2 : row.feels > 28 ? (row.feels - 28) * 3.2 : 0, row.feels < 5 ? 'impact.reason.cold' : 'impact.reason.heat', { value: temperature(row.feels) });
        add(Math.max(0, row.uv - 7) * 5, 'impact.reason.uv', { value: row.uv.toFixed(1) });
        add(row.visibility < 4 ? (4 - row.visibility) * 8 : 0, 'impact.reason.visibility', { value: row.visibility.toFixed(1) });
    }
    else if (activity === 'sea') {
        add(rainPenalty * 1.05, 'impact.reason.rain', { value: Math.round(row.rain) });
        add(Math.max(0, row.gust - 34) * 1.25, 'impact.reason.wind', { value: Math.round(row.gust) });
        add(row.temperature < 22 ? (22 - row.temperature) * 4 : 0, 'impact.reason.cool_sea', { value: temperature(row.temperature) });
        add(Math.max(0, row.cloud - 80) * 0.25, 'impact.reason.cloud', { value: Math.round(row.cloud) });
        add(Math.max(0, row.uv - 9) * 4, 'impact.reason.uv', { value: row.uv.toFixed(1) });
    }
    else if (activity === 'laundry') {
        add(Math.max(0, row.rain - 5) * 0.9 + row.precipitation * 20, 'impact.reason.rain', { value: Math.round(row.rain) });
        add(Math.max(0, row.humidity - 68) * 0.8, 'impact.reason.humidity', { value: Math.round(row.humidity) });
        add(row.wind < 4 ? (4 - row.wind) * 5 : 0, 'impact.reason.no_breeze', { value: Math.round(row.wind) });
        add(Math.max(0, row.gust - 48) * 1.5, 'impact.reason.wind', { value: Math.round(row.gust) });
        add(Math.max(0, row.cloud - 85) * 0.3, 'impact.reason.cloud', { value: Math.round(row.cloud) });
    }
    else if (activity === 'motorcycle') {
        add(rainPenalty * 1.35, 'impact.reason.rain', { value: Math.round(row.rain) });
        add(Math.max(0, row.gust - 32) * 1.6, 'impact.reason.wind', { value: Math.round(row.gust) });
        add(row.visibility < 6 ? (6 - row.visibility) * 7 : 0, 'impact.reason.visibility', { value: row.visibility.toFixed(1) });
        add(row.feels < 4 ? (4 - row.feels) * 3 : row.feels > 34 ? (row.feels - 34) * 3 : 0, row.feels < 4 ? 'impact.reason.cold' : 'impact.reason.heat', { value: temperature(row.feels) });
    }
    else if (activity === 'trekking') {
        add(rainPenalty * 1.15, 'impact.reason.rain', { value: Math.round(row.rain) });
        add(Math.max(0, row.gust - 34) * 1.45, 'impact.reason.wind', { value: Math.round(row.gust) });
        add(row.visibility < 5 ? (5 - row.visibility) * 8 : 0, 'impact.reason.visibility', { value: row.visibility.toFixed(1) });
        add(Math.max(0, row.uv - 7) * 4.5, 'impact.reason.uv', { value: row.uv.toFixed(1) });
        add(row.feels < 3 ? (3 - row.feels) * 3 : row.feels > 30 ? (row.feels - 30) * 3 : 0, row.feels < 3 ? 'impact.reason.cold' : 'impact.reason.heat', { value: temperature(row.feels) });
    }
    else if (activity === 'kids') {
        add(rainPenalty * 1.2, 'impact.reason.rain', { value: Math.round(row.rain) });
        add(Math.max(0, row.gust - 30) * 1.5, 'impact.reason.wind', { value: Math.round(row.gust) });
        add(Math.max(0, row.uv - 6) * 5, 'impact.reason.uv', { value: row.uv.toFixed(1) });
        add(row.feels < 7 ? (7 - row.feels) * 3 : row.feels > 30 ? (row.feels - 30) * 4 : 0, row.feels < 7 ? 'impact.reason.cold' : 'impact.reason.heat', { value: temperature(row.feels) });
    }
    const penalty = penalties.reduce((sum, item) => sum + item.value, 0);
    penalties.sort((a, b) => b.value - a.value);
    return {
        score: clamp(Math.round(100 - penalty), 0, 100),
        reason: penalties[0]?.value >= 5 ? penalties[0] : { value: 0, key: 'impact.reason.good', params: {} }
    };
}
function impactSummary(activity) {
    const rows = impactHourlyRows(12);
    if (!rows.length) return null;
    const scored = rows.map(row => ({ row, ...impactScoreForRow(activity, row) }));
    let best = null;
    for (let index = 0; index < scored.length; index += 1) {
        const pair = scored.slice(index, Math.min(scored.length, index + 2));
        const score = Math.round(pair.reduce((sum, item) => sum + item.score, 0) / Math.max(1, pair.length));
        const candidate = { score, start: pair[0].row.time, end: pair.at(-1).row.time, reason: pair.sort((a, b) => a.score - b.score)[0].reason };
        if (!best || candidate.score > best.score) best = candidate;
    }
    return best;
}
function impactLevel(score) {
    return score >= 85 ? 'great' : score >= 70 ? 'good' : score >= 50 ? 'fair' : 'poor';
}
function renderPersonalImpact() {
    const grid = $('#impact-grid');
    const chips = $('#impact-preference-chips');
    if (!grid || !chips || !state.weather) return;
    chips.innerHTML = IMPACT_ACTIVITY_IDS.map(id => {
        const meta = impactActivityMeta(id);
        const active = personalWeatherPrefs.activities.includes(id);
        return `<button type="button" class="impact-preference-chip${active ? ' active' : ''}" data-impact-preference="${id}" aria-pressed="${active ? 'true' : 'false'}"><svg><use href="#${meta.icon}"/></svg><span>${escapeHTML(t(meta.label))}</span></button>`;
    }).join('');
    const selectedActivities = personalWeatherPrefs.activities.filter(id => IMPACT_ACTIVITY_IDS.includes(id));
    const visibleActivities = selectedActivities.length ? selectedActivities : IMPACT_ACTIVITY_IDS;
    grid.dataset.filtered = selectedActivities.length ? 'true' : 'false';
    grid.innerHTML = visibleActivities.map(id => {
        const meta = impactActivityMeta(id);
        const summary = impactSummary(id);
        if (!summary) return '';
        const end = new Date(summary.end);
        end.setHours(end.getHours() + 1);
        const windowText = t('impact.best_window.value', { start: formatClock(summary.start), end: formatClock(end) });
        return `<article class="impact-card" data-impact-activity="${id}" data-level="${impactLevel(summary.score)}"><span class="impact-card-icon"><svg><use href="#${meta.icon}"/></svg></span><h3>${escapeHTML(t(meta.label))}</h3><span class="impact-window">${escapeHTML(windowText)}</span><strong class="impact-score">${summary.score}</strong><p class="impact-reason">${escapeHTML(t(summary.reason.key, summary.reason.params))}</p></article>`;
    }).join('');
}
async function loadPersonalWeatherPreferences({ force = false } = {}) {
    if (personalWeatherLoading) return personalWeatherLoading;
    const mode = isGuestSession() ? 'guest' : 'authenticated';
    if (personalWeatherLoadedMode === mode && !force) return personalWeatherPrefs;
    const task = async () => {
        if (mode === 'guest' || (navigator.onLine !== false && !hasVerifiedServerSession())) {
            personalWeatherLoadedMode = mode;
            renderPersonalImpact();
            syncBriefingControls();
            renderProactiveInsight();
            return personalWeatherPrefs;
        }
        try {
            if (navigator.onLine && loadJSON(STORAGE.personalPending, false)) {
                const synced = await synchronizePersonalWeatherPreferences({ force: true });
                if (synced) {
                    personalWeatherLoadedMode = mode;
                    renderPersonalImpact();
                    syncBriefingControls();
                    renderStoredBriefing();
                    renderProactiveInsight();
                    maybeGenerateMorningBriefing();
                    return personalWeatherPrefs;
                }
            }
            const deviceId = SERVICES.get('security')?.deviceId || '';
            const data = await apiRequest(`api/preferences/personal.php?deviceId=${encodeURIComponent(deviceId)}`);
            if (data?.preferences) {
                personalWeatherPrefs = {
                    activities: Array.isArray(data.preferences.activities) ? data.preferences.activities.filter(id => IMPACT_ACTIVITY_IDS.includes(id)) : [],
                    briefingEnabled: data.preferences.briefingEnabled === true,
                    briefingHour: Number.isInteger(Number(data.preferences.briefingHour)) ? clamp(Number(data.preferences.briefingHour), 0, 23) : null,
                    briefingHourSet: data.preferences.briefingHourSet === true,
                    proactiveEnabled: data.preferences.proactiveEnabled === true
                };
                if (!personalWeatherPrefs.briefingHourSet)
                    personalWeatherPrefs.briefingHour = currentLocationHour();
                saveJSON(STORAGE.personalWeather, personalWeatherPrefs);
            }
        }
        catch (error) {
            console.warn('PERSONAL_WEATHER_PREFERENCES_LOAD_FAILED', error);
        }
        personalWeatherLoadedMode = mode;
        renderPersonalImpact();
        syncBriefingControls();
        renderStoredBriefing();
        renderProactiveInsight();
        maybeGenerateMorningBriefing();
        return personalWeatherPrefs;
    };
    personalWeatherLoading = task();
    try { return await personalWeatherLoading; }
    finally { personalWeatherLoading = null; }
}
async function savePersonalWeatherPreferences({ notify = false, fromSync = false } = {}) {
    personalWeatherPrefs.activities = personalWeatherPrefs.activities.filter(id => IMPACT_ACTIVITY_IDS.includes(id));
    if (!personalWeatherPrefs.briefingHourSet)
        personalWeatherPrefs.briefingHour = currentLocationHour();
    personalWeatherPrefs.briefingHour = clamp(Number(personalWeatherPrefs.briefingHour ?? currentLocationHour()), 0, 23);
    personalWeatherPrefs.proactiveEnabled = personalWeatherPrefs.proactiveEnabled === true;
    saveJSON(STORAGE.personalWeather, personalWeatherPrefs);
    renderPersonalImpact();
    syncBriefingControls();
    if (isGuestSession()) {
        saveJSON(STORAGE.personalPending, false);
        return;
    }
    if (navigator.onLine !== false && !hasVerifiedServerSession()) {
        saveJSON(STORAGE.personalPending, true);
        return;
    }
    if (!navigator.onLine) {
        saveJSON(STORAGE.personalPending, true);
        requestSafeBackgroundSync().catch(() => null);
        if (notify) showToast(t('offline.saved.title'), t('offline.saved.copy'), 'warning');
        return;
    }
    try {
        const deviceId = SERVICES.get('security')?.deviceId || '';
        await apiRequest('api/preferences/personal.php', { deviceId, ...personalWeatherPrefs });
        saveJSON(STORAGE.personalPending, false);
        if (notify && !fromSync) showToast(t('personal.toast.saved.title'), t('personal.toast.saved.copy'), 'success');
    }
    catch (error) {
        saveJSON(STORAGE.personalPending, true);
        requestSafeBackgroundSync().catch(() => null);
        if (notify && !fromSync) showToast(t('personal.toast.error.title'), error.message, 'warning');
        throw error;
    }
}
async function synchronizePersonalWeatherPreferences({ force = false } = {}) {
    if (isGuestSession() || !navigator.onLine || !hasVerifiedServerSession()) return false;
    if (!force && !loadJSON(STORAGE.personalPending, false)) return false;
    try {
        await savePersonalWeatherPreferences({ notify: false, fromSync: true });
        return !loadJSON(STORAGE.personalPending, false);
    }
    catch {
        return false;
    }
}
function modelVerificationForecasts(model) {
    const hourly = model?.raw?.hourly;
    if (!hourly?.time?.length) return [];
    return [1, 3, 6, 24, 48, 72].flatMap(horizon => {
        const index = Number(model.start || 0) + horizon;
        const targetTime = hourly.time?.[index];
        if (!targetTime) return [];
        return [{
            model: model.id,
            targetTime,
            horizonHours: horizon,
            temperature: Number(hourly.temperature_2m?.[index] ?? 0),
            precipitation: Number(hourly.precipitation?.[index] ?? 0),
            windGust: Number(hourly.wind_gusts_10m?.[index] ?? hourly.wind_speed_10m?.[index] ?? 0)
        }];
    });
}
