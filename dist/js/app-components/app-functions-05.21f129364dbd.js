'use strict';
function proactiveTriggerSnapshot() {
    const triggers = [];
    let severity = 0;
    if (state.severeWeather.authoritative === true && state.severeWeather.degraded !== true) {
        for (const event of (state.severeWeather.events || []).slice(0, 2)) {
            const label = String(event.title || event.body || '').trim();
            if (label) triggers.push(label);
            const rank = { red: 99, orange: 94, yellow: 86 }[String(event.severity || '').toLowerCase()] || 86;
            severity = Math.max(severity, rank, Number(event.confidence || 0));
        }
    }
    const nowcast = extractNowcast();
    const nowMinutes = nowcast.firstWet >= 0 && nowcast.start
        ? Math.max(0, Math.round((new Date(nowcast.start).getTime() - Date.now()) / 60000))
        : null;
    if (nowcast.rainingNow) {
        triggers.push(t('proactive.trigger.raining'));
        severity = Math.max(severity, 88);
    } else if (Number.isFinite(nowMinutes) && nowMinutes <= 45) {
        triggers.push(t('proactive.trigger.rain_soon', { minutes: nowMinutes }));
        severity = Math.max(severity, 82 - Math.min(30, nowMinutes / 2));
    }
    const motion = state.radar.motion;
    const radarEta = optionalFiniteNumber(motion?.etaMinutes);
    if (motion && radarEta !== null && radarEta <= 45 && Number(motion.confidence || 0) >= 55) {
        triggers.push(t('proactive.trigger.radar_eta', { minutes: Math.round(radarEta), confidence: motion.confidence }));
        severity = Math.max(severity, 84);
    }
    const metrics = proactiveChangeMetrics();
    if (Number.isFinite(Number(metrics.rainTimingShiftMinutes)) && Math.abs(Number(metrics.rainTimingShiftMinutes)) >= 30) {
        triggers.push(t('proactive.trigger.rain_shift', {
            minutes: Math.abs(Number(metrics.rainTimingShiftMinutes)),
            direction: Number(metrics.rainTimingShiftMinutes) < 0 ? t('change.direction.earlier') : t('change.direction.later')
        }));
        severity = Math.max(severity, 68);
    }
    if (Math.abs(Number(metrics.maxGustDeltaKmh || 0)) >= 12) {
        triggers.push(t('proactive.trigger.wind_change', { value: Math.round(Math.abs(Number(metrics.maxGustDeltaKmh))) }));
        severity = Math.max(severity, 64);
    }
    const selectedImpacts = personalWeatherPrefs.activities.flatMap(id => {
        const summary = impactSummary(id);
        return summary ? [{ id, summary }] : [];
    });
    for (const item of selectedImpacts) {
        if (item.summary.score <= 55) {
            triggers.push(t('proactive.trigger.activity_risk', { activity: t(impactActivityMeta(item.id).label), score: item.summary.score }));
            severity = Math.max(severity, 72);
        } else if (item.summary.score >= 88) {
            triggers.push(t('proactive.trigger.activity_window', { activity: t(impactActivityMeta(item.id).label), time: formatClock(item.summary.start) }));
            severity = Math.max(severity, 54);
        }
    }
    const confidence = confidenceFromModels();
    if (confidence.score < 58) {
        triggers.push(t('proactive.trigger.low_confidence', { score: confidence.score }));
        severity = Math.max(severity, 62);
    }
    const fingerprintBase = [
        intelligenceLocationKey(),
        Math.floor(Date.now() / (30 * 60 * 1000)),
        ...triggers.map(value => String(value).slice(0, 90))
    ].join('|');
    let hash = 2166136261;
    for (let i = 0; i < fingerprintBase.length; i += 1) {
        hash ^= fingerprintBase.charCodeAt(i);
        hash = Math.imul(hash, 16777619);
    }
    return {
        triggers,
        severity: Math.round(severity),
        fingerprint: (hash >>> 0).toString(16),
        metrics
    };
}
function renderProactiveInsight() {
    const panel = $('#proactive-ai-panel');
    const output = $('#proactive-output');
    const status = $('#proactive-status');
    if (!panel || !output) return;
    const enabled = personalWeatherPrefs.proactiveEnabled === true;
    if (status) {
        status.textContent = enabled ? t('proactive.status.on') : t('proactive.status.off');
        status.classList.toggle('active', enabled);
    }
    const stored = proactiveStoredInsight();
    if (stored?.answer) {
        output.classList.remove('is-thinking');
        output.innerHTML = `<span><svg><use href="#i-spark"/></svg></span><div><strong>${escapeHTML(t('proactive.ready.title'))}</strong>${briefingAnswerHtml(stored.answer)}<small class="proactive-meta">${escapeHTML(t('proactive.ready.meta', { time: formatClock(new Date(stored.at || Date.now())) }))}</small></div>`;
        return;
    }
    output.classList.remove('is-thinking');
    if (state.weather && !isGuestSession()) {
        const snapshot = proactiveTriggerSnapshot();
        const triggers = Array.isArray(snapshot.triggers) ? snapshot.triggers.slice(0, 3) : [];
        const body = triggers.length
            ? `<ul class="proactive-local-list">${triggers.map(item => `<li>${escapeHTML(item)}</li>`).join('')}</ul>`
            : `<p>${escapeHTML(t('proactive.local.clear'))}</p>`;
        output.innerHTML = `<span><svg><use href="#i-spark"/></svg></span><div><strong>${escapeHTML(t('proactive.local.title'))}</strong>${body}<small class="proactive-meta">${escapeHTML(t(enabled ? 'proactive.local.enabled_note' : 'proactive.local.disabled_note'))}</small></div>`;
        return;
    }
    output.innerHTML = `<span><svg><use href="#i-spark"/></svg></span><div><strong>${escapeHTML(t(enabled ? 'proactive.monitoring.title' : 'proactive.empty.title'))}</strong><p>${escapeHTML(t(enabled ? 'proactive.monitoring.copy' : 'proactive.empty.copy'))}</p></div>`;
}
async function maybeGenerateProactiveInsight({ manual = false, force = false } = {}) {
    if (proactiveGenerating || isGuestSession() || (navigator.onLine !== false && !hasVerifiedServerSession()) || !isUiFeatureVisible('feature.ai.proactive')) return null;
    if (!personalWeatherPrefs.proactiveEnabled && !manual) return null;
    if (!navigator.onLine) {
        if (manual) showToast(t('proactive.offline.title'), t('proactive.offline.copy'), 'warning');
        return null;
    }
    const trigger = proactiveTriggerSnapshot();
    if (!manual && trigger.severity < 55) {
        renderProactiveInsight();
        return null;
    }
    const previous = proactiveStoredInsight();
    const sameFingerprint = previous?.fingerprint === trigger.fingerprint;
    const recent = previous?.at && Date.now() - Number(previous.at) < 3 * 60 * 60 * 1000;
    if (!force && !manual && sameFingerprint && recent) return previous;
    const dayKey = briefingLocalDateKey();
    const dailyCount = previous?.day === dayKey ? Number(previous.dailyCount || 0) : 0;
    if (!manual && dailyCount >= 3) return previous;

    proactiveGenerating = true;
    SERVICES.get('panelLoader')?.set?.('#proactive-ai-panel', true, t('panel.loading'));
    const output = $('#proactive-output');
    if (output) {
        output.classList.add('is-thinking');
        output.innerHTML = `<span><svg><use href="#i-spark"/></svg></span><div><strong>${escapeHTML(t('proactive.thinking.title'))}</strong><p>${escapeHTML(t('proactive.thinking.copy'))}</p></div>`;
    }
    const triggerText = trigger.triggers.length ? trigger.triggers.join(' | ') : t('proactive.trigger.none');
    const prompt = t('proactive.ai.prompt', { location: shortLocationLabel(state.location), triggers: triggerText });
    try {
        const result = await apiRequest('api/ai/chat.php', {
            mode: 'proactive',
            message: prompt,
            language: state.settings.language,
            messages: previous?.answer ? [{ role: 'assistant', content: String(previous.answer).slice(0, 1200) }] : [],
            context: briefingAiContext()
        }, { timeout: 50000 });
        const answer = String(result?.answer || '').trim();
        if (!answer) throw new Error(t('proactive.error.empty'));
        const next = {
            answer,
            at: Date.now(),
            day: dayKey,
            dailyCount: dailyCount + 1,
            fingerprint: trigger.fingerprint,
            severity: trigger.severity,
            provider: result.provider || '',
            model: result.model || ''
        };
        saveJSON(STORAGE.proactiveInsight, next);
        renderProactiveInsight();
        if (manual) showToast(t('proactive.toast.ready.title'), t('proactive.toast.ready.copy'), 'success');
        else showToast(t('proactive.toast.new.title'), t('proactive.toast.new.copy'), 'info', 5200);
        return next;
    }
    catch (error) {
        if (output) {
            output.classList.remove('is-thinking');
            output.innerHTML = `<span><svg><use href="#i-alert"/></svg></span><div><strong>${escapeHTML(t('proactive.error.title'))}</strong><p>${escapeHTML(error.message || t('proactive.error.copy'))}</p></div>`;
        }
        if (manual) showToast(t('proactive.error.title'), error.message || t('proactive.error.copy'), 'warning');
        return null;
    }
    finally {
        proactiveGenerating = false;
        SERVICES.get('panelLoader')?.set?.('#proactive-ai-panel', false);
    }
}
function updateThresholdsPanelFollow() {
    thresholdsFollowRaf = 0;
    const panel = $('#thresholds-panel');
    const dashboard = $('#page-alerts .alerts-dashboard');
    const page = $('#page-alerts');
    if (!panel || !dashboard || !page)
        return;
    const desktop = window.matchMedia('(min-width: 1101px)').matches;
    if (!desktop || state.currentPage !== 'alerts' || !page.classList.contains('active-page')) {
        panel.style.removeProperty('--threshold-follow-y');
        return;
    }
    const dashboardRect = dashboard.getBoundingClientRect();
    const topbarRect = $('.topbar')?.getBoundingClientRect();
    const topGuard = Math.max(0, topbarRect?.bottom || 0) + 18;
    const desired = topGuard - dashboardRect.top;
    const maxOffset = Math.max(0, dashboard.scrollHeight - panel.offsetHeight);
    const offset = clamp(desired, 0, maxOffset);
    panel.style.setProperty('--threshold-follow-y', `${Math.round(offset)}px`);
}
function scheduleThresholdsPanelFollow() {
    if (thresholdsFollowRaf)
        return;
    thresholdsFollowRaf = requestAnimationFrame(updateThresholdsPanelFollow);
}
function initializeThresholdsPanelFollow() {
    document.addEventListener('scroll', scheduleThresholdsPanelFollow, { passive: true, capture: true });
    window.addEventListener('resize', scheduleThresholdsPanelFollow, { passive: true });
    window.visualViewport?.addEventListener('resize', scheduleThresholdsPanelFollow, { passive: true });
    window.visualViewport?.addEventListener('scroll', scheduleThresholdsPanelFollow, { passive: true });
    if ('ResizeObserver' in window) {
        thresholdsFollowObserver = new ResizeObserver(scheduleThresholdsPanelFollow);
        const panel = $('#thresholds-panel');
        const dashboard = $('#page-alerts .alerts-dashboard');
        const mainColumn = $('#page-alerts .alerts-main-column');
        if (panel)
            thresholdsFollowObserver.observe(panel);
        if (dashboard)
            thresholdsFollowObserver.observe(dashboard);
        if (mainColumn)
            thresholdsFollowObserver.observe(mainColumn);
    }
    scheduleThresholdsPanelFollow();
}
function pruneAlertReadState() {
    const now = Date.now();
    const cutoff = now - 14 * 86400000;
    const entries = Object.entries(state.alertReads || {})
        .filter(([, value]) => Number(value) >= cutoff)
        .sort((a, b) => Number(b[1]) - Number(a[1]))
        .slice(0, 160);
    state.alertReads = Object.fromEntries(entries);
    saveJSON(STORAGE.alertReads, state.alertReads);
}
function alertStateId(key) {
    return `${locationKey()}:${String(key || '')}`;
}
function isAlertRead(id) {
    return Boolean(id && Number(state.alertReads?.[id] || 0) > 0);
}
function markAlertRead(id) {
    if (!id) return;
    state.alertReads ||= {};
    state.alertReads[id] = Date.now();
    pruneAlertReadState();
}
function officialAlertStateId(warning) {
    if (!warning) return '';
    const raw = officialAlertEventId(warning.id || warning.providerAlertId || warning.identifier || warning.title || 'official');
    const starts = String(warning.startsAt || '').slice(0, 16);
    const ends = String(warning.endsAt || '').slice(0, 16);
    const severity = String(warning.severity || warning.level || '').toLowerCase();
    return alertStateId(`official:${raw}:${starts}:${ends}:${severity}`);
}
function activeOfficialAlertUnreadCount() {
    const rows = Array.isArray(state.officialAlerts?.relevant) ? state.officialAlerts.relevant : [];
    if (!rows.length) return 0;
    return rows.reduce((count, warning) => count + (isAlertRead(officialAlertStateId(warning)) ? 0 : 1), 0);
}
function updateAlertBadgeCount() {
    const localUnread = Math.max(0, Number(state.alertWeatherUnreadCount || 0)) + activeOfficialAlertUnreadCount();
    const inboxUnread = !isGuestSession() && state.notificationInboxLoaded ? Math.max(0, Number(state.notificationInboxUnread || 0)) : 0;
    const total = Math.max(0, localUnread + inboxUnread);
    const label = total > 99 ? '99+' : String(total);
    const navAlertCount = $('#alert-count');
    if (navAlertCount) { navAlertCount.textContent = label; navAlertCount.hidden = total === 0; }
    const mobileAlertCount = $('#mobile-alert-count');
    if (mobileAlertCount) { mobileAlertCount.textContent = label; mobileAlertCount.hidden = total === 0; }
}
function markAllCurrentAlertsRead() {
    $$('[data-alert-id]', $('#alerts-list')).forEach(node => markAlertRead(String(node.dataset.alertId || '')));
    const officialRows = Array.isArray(state.officialAlerts?.relevant) ? state.officialAlerts.relevant : [];
    officialRows.forEach(warning => markAlertRead(officialAlertStateId(warning)));
    renderAlerts();
    renderNotificationOfficialAlert();
}
function renderAlerts() {
    if (!$('#alerts-summary')) return;
    if (!state.weather?.daily) {
        state.alertWeatherUnreadCount = 0;
        updateAlertBadgeCount();
        return;
    }
    pruneAlertReadState();
    const daily = state.weather.daily;
    const alerts = [];
    const days = daily.time.slice(0, 7).map((date, index) => {
        const when = formatDay(date, index);
        const shortDate = formatShortDate(date);
        const rain = Number(daily.precipitation_probability_max[index] || 0);
        const rainMm = Number(daily.precipitation_sum[index] || 0);
        const wind = Number(daily.wind_gusts_10m_max[index] || 0);
        const heat = Number(daily.temperature_2m_max[index] || 0);
        const low = Number(daily.temperature_2m_min[index] || 0);
        const code = Number(daily.weather_code[index]);
        const storm = [95, 96, 99].includes(code);
        const ratios = {
            rain: state.thresholds.rain > 0 ? rain / state.thresholds.rain : 0,
            wind: state.thresholds.wind > 0 ? wind / state.thresholds.wind : 0,
            heat: state.thresholds.heat > 0 ? heat / state.thresholds.heat : 0,
            storm: storm ? 1.35 : 0
        };
        const maxRatio = Math.max(ratios.rain, ratios.wind, ratios.heat, ratios.storm);
        const level = maxRatio >= 1.15 ? 'danger' : maxRatio >= 1 ? 'warning' : maxRatio >= .72 ? 'watch' : 'calm';
        const levelLabel = level === 'danger' ? "" + meteonexaText("app.renderalerts.high.variant_2") : level === 'warning' ? "" + meteonexaText("app.renderalerts.caution") : level === 'watch' ? "" + meteonexaText("app.renderalerts.watch") : "" + meteonexaText("app.renderalerts.normal");
        if (storm)
            alerts.push({ key: `${date}:storm`, level: 'danger', icon: 'i-bell', title: "" + meteonexaText("notifications.notificationcandidates.possible_thunderstorms"), copy: "" + meteonexaText("app.renderalerts.thunderstorms_forecast_check_official_local_updates"), when });
        if (rain >= state.thresholds.rain)
            alerts.push({ key: `${date}:rain:${state.thresholds.rain}`, level: 'warning', icon: 'i-umbrella', title: "" + meteonexaText("app.renderalerts.high_rain_probability"), copy: meteonexaText("app.renderalerts.value_probability_value_mm_forecast", { value: Math.round(rain), mm: rainMm.toFixed(1) }), when });
        if (wind >= state.thresholds.wind)
            alerts.push({ key: `${date}:wind:${state.thresholds.wind}`, level: 'danger', icon: 'i-wind', title: "" + meteonexaText("app.renderalerts.strong_wind_gusts"), copy: meteonexaText("notifications.gusts_forecast_up_value_km_h", { value: Math.round(wind) }), when });
        if (heat >= state.thresholds.heat)
            alerts.push({ key: `${date}:heat:${state.thresholds.heat}`, level: 'warning', icon: 'i-sun', title: "" + meteonexaText("app.renderalerts.extreme_heat"), copy: meteonexaText("notifications.notificationcandidates.forecast_high_value", { value: temperature(heat) }), when });
        return { date, when, shortDate, rain, rainMm, wind, heat, low, code, level, levelLabel, maxRatio };
    });
    alerts.forEach(alert => { alert.id = alertStateId(alert.key); alert.read = isAlertRead(alert.id); });
    const unreadAlerts = alerts.filter(alert => !alert.read);
    state.alertWeatherUnreadCount = unreadAlerts.length;
    const severity = { calm: 0, watch: 1, warning: 2, danger: 3 };
    const peak = days.reduce((best, day) => severity[day.level] > severity[best.level] || (severity[day.level] === severity[best.level] && day.maxRatio > best.maxRatio) ? day : best, days[0]);
    const summary = $('#alerts-summary');
    const summaryLevel = alerts.some(item => item.level === 'danger') ? 'danger' : alerts.length ? 'warning' : peak?.level === 'watch' ? 'watch' : 'success';
    summary?.classList.remove('success', 'watch', 'warning', 'danger');
    summary?.classList.add(summaryLevel);
    const summaryTitle = alerts.length === 0
        ? (peak?.level === 'watch' ? "" + meteonexaText("app.renderalerts.stable_conditions_one_day_watch") : "" + meteonexaText("app.renderalerts.no_critical_issues_detected"))
        : unreadAlerts.length === 0 ? meteonexaText('alerts.all_read.title')
        : unreadAlerts.length === 1 ? meteonexaText('alerts.unread.one') : meteonexaText('alerts.unread.many', { count: unreadAlerts.length });
    const summaryText = alerts.length === 0
        ? "" + meteonexaText("app.renderalerts.next_seven_days_remain_below_set_limits_monitoring") : unreadAlerts.length === 0 ? meteonexaText('alerts.all_read.copy') : "" + meteonexaText("app.renderalerts.review_highlighted_events_adjust_thresholds_needs");
    $('#alerts-summary-title').textContent = summaryTitle;
    $('#alerts-summary-text').textContent = summaryText;
    $('#alerts-event-total').textContent = String(alerts.length);
    $('#alerts-peak-day').textContent = peak?.when || '--';
    $('#alerts-peak-level').textContent = peak?.levelLabel || "" + meteonexaText("weather.uvlabel.low");
    $('#alert-events-count').textContent = meteonexaText(alerts.length === 1 ? 'app.renderalerts.value_event' : 'app.renderalerts.value_events', { count: alerts.length });
    $('#alert-monitor-badge').textContent = meteonexaText("history.renderhistory.value_days_analyzed", { count: days.length });
    $('#alert-days').innerHTML = days.map(day => "" + "\n    <article class=\"alert-day-card " + day.level + "\" tabindex=\"0\" aria-label=\"" + escapeHTML(meteonexaText("app.renderalerts.value_value_risk", { day: day.when, level: day.levelLabel })) + "\">\n      <div class=\"alert-day-head\"><span><strong>" + escapeHTML(day.when) + "</strong><small>" + escapeHTML(day.shortDate) + "</small></span><em>" + escapeHTML(day.levelLabel) + "</em></div>\n      <div class=\"alert-day-weather\"><span class=\"alert-day-art\">" + weatherArt(day.code, 1) + "</span><div><strong>" + temperature(day.heat) + "</strong><small>" + escapeHTML(meteonexaText('temperature.minimum.inline', { value: temperature(day.low) })) + "</small></div></div>\n      <div class=\"alert-day-metrics\">\n        <span><svg><use href=\"#i-umbrella\"/></svg><b>" + Math.round(day.rain) + "%</b><small>" + day.rainMm.toFixed(1) + " mm</small></span>\n        <span><svg><use href=\"#i-wind\"/></svg><b>" + Math.round(day.wind) + "</b><small>km/h</small></span>\n        <span><svg><use href=\"#i-sun\"/></svg><b>" + temperature(day.heat) + "</b><small>" + escapeHTML(meteonexaText("app.renderalerts.high")) + "</small></span>\n      </div>\n      <div class=\"alert-risk-track\"><i style=\"--risk:" + Math.min(100, Math.round(day.maxRatio * 74)) + "%\"></i></div>\n    </article>").join('');
    if (alerts.length) {
        $('#alerts-list').innerHTML = alerts.slice(0, 10).map(alert => `<article class="alert-card ${alert.level}${alert.read ? ' is-read' : ''}" data-alert-id="${escapeHTML(alert.id)}"><span class="alert-card-icon"><svg><use href="#${alert.icon}"/></svg></span><div class="alert-card-main"><h3>${escapeHTML(alert.title)}</h3><p>${escapeHTML(alert.copy)}</p></div><div class="alert-card-actions">${alert.read ? '' : '<i class="alert-unread-dot" aria-hidden="true"></i>'}<span class="alert-when">${escapeHTML(alert.when)}</span>${alert.read ? '' : `<button class="alert-read-button" data-alert-mark-read type="button" aria-label="${escapeHTML(meteonexaText('alerts.mark_read'))}" title="${escapeHTML(meteonexaText('alerts.mark_read'))}"><svg><use href="#i-eye"/></svg></button>`}</div></article>`).join('');
    }
    else {
        $('#alerts-list').innerHTML = "" + "<div class=\"alerts-empty-state\"><span><svg><use href=\"#i-shield\"/></svg></span><div><strong>" + escapeHTML(meteonexaText("app.renderalerts.everything_under_control")) + "</strong><p>" + escapeHTML(meteonexaText("app.renderalerts.no_event_exceeds_current_thresholds_can_still_review")) + "</p></div></div>";
    }
    const markAll = $('#alerts-mark-all-read');
    if (markAll) markAll.hidden = unreadAlerts.length === 0 && activeOfficialAlertUnreadCount() === 0;
    updateAlertBadgeCount();
    scheduleThresholdsPanelFollow();
}
function renderDetailsTable() {
    const data = state.weather;
    const start = currentHourlyIndex(data);
    const rows = data.hourly.time.slice(start, start + 24).map((time, offset) => {
        const i = start + offset;
        const rowId = `hour-details-${i}`;
        const resolved = resolveFusedHourlyCondition(data, i);
        return "" + "<div class=\"hourly-table-row\" data-hour-row>\n      <strong class=\"hourly-time\">" + (offset === 0 ? "" + escapeHTML(meteonexaText("app.renderhourly.now")) : formatClock(time)) + "</strong>\n      <span class=\"condition-cell\">" + weatherArt(resolved.code, resolved.isDay) + "<b>" + escapeHTML(resolved.meta.label) + "</b></span>\n      <span class=\"hourly-primary-value\"><small>" + escapeHTML(meteonexaText("history.yrain.temperature")) + "</small><b>" + temperature(data.hourly.temperature_2m[i]) + "</b></span>\n      <span class=\"hourly-primary-value\"><small>" + escapeHTML(meteonexaText("history.renderhistory.rain")) + "</small><b>" + Number(resolved.precipitationMm || 0).toLocaleString(appLocale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + " mm · " + Math.round(data.hourly.precipitation_probability[i] || 0) + "%</b></span>\n      <span class=\"hourly-primary-value\"><small>" + escapeHTML(meteonexaText("visualization.take.wind")) + "</small><b>" + Math.round(data.hourly.wind_speed_10m[i] || 0) + " km/h</b></span>\n      <button class=\"hourly-row-toggle\" type=\"button\" aria-expanded=\"false\" aria-controls=\"" + rowId + "\" aria-label=\"" + escapeHTML(meteonexaText('hour.expand', { time: offset === 0 ? meteonexaText('hour.current_conditions') : formatClock(time) })) + "\"><svg><use href=\"#i-chevron\"/></svg></button>\n      <div class=\"hourly-row-details\" id=\"" + rowId + "\" hidden>\n        <span><small>" + escapeHTML(meteonexaText("history.renderhistory.feels_like")) + "</small><strong>" + temperature(data.hourly.apparent_temperature[i]) + "</strong></span>\n        <span><small>" + escapeHTML(meteonexaText("visualization.take.humidity")) + "</small><strong>" + Math.round(data.hourly.relative_humidity_2m[i] || 0) + "%</strong></span>\n        <span><small>" + escapeHTML(meteonexaText("app.openhourdetail.visibility")) + "</small><strong>" + (Number(data.hourly.visibility[i] || 0) / 1000).toFixed(1) + " km</strong></span>\n        <span><small>" + escapeHTML(meteonexaText("history.renderhistory.gusts")) + "</small><strong>" + Math.round(data.hourly.wind_gusts_10m[i] || 0) + " km/h</strong></span>\n        <span><small>" + escapeHTML(meteonexaText("visualization.take.pressure")) + "</small><strong>" + Math.round(data.hourly.surface_pressure[i] || 0) + " hPa</strong></span>\n        <span><small>" + escapeHTML(meteonexaText("app.openhourdetail.dew_point")) + "</small><strong>" + temperature(data.hourly.dew_point_2m[i]) + "</strong></span>\n      </div>\n    </div>";
    }).join('');
    const root = $('#hourly-table');
    root.innerHTML = "" + "<div class=\"hourly-table-row header\"><span>" + escapeHTML(meteonexaText("app.currentsharepayload.time")) + "</span><span>" + escapeHTML(meteonexaText("history.renderhistory.condition")) + "</span><span>" + escapeHTML(meteonexaText("app.renderdetailstable.temp")) + "</span><span>" + escapeHTML(meteonexaText("history.renderhistory.rain")) + "</span><span>" + escapeHTML(meteonexaText("visualization.take.wind")) + "</span><span aria-hidden=\"true\"></span></div>" + rows;
    $$('.hourly-row-toggle', root).forEach(button => button.addEventListener('click', () => {
        const expanded = button.getAttribute('aria-expanded') === 'true';
        button.setAttribute('aria-expanded', String(!expanded));
        const details = document.getElementById(button.getAttribute('aria-controls'));
        if (details)
            details.hidden = expanded;
        button.closest('[data-hour-row]')?.classList.toggle('expanded', !expanded);
    }));
}
async function loadRadarBaseData() {
    if (state.radar.baseData)
        return state.radar.baseData;
    if (state.radar.baseLoading)
        return state.radar.baseLoading;
    state.radar.baseLoading = fetch(CONFIG.BASEMAP_DATA, { cache: 'force-cache' })
        .then(response => response.ok ? response.json() : Promise.reject(new Error("" + meteonexaText("app.loadradarbasedata.base_map_unavailable"))))
        .then(data => { state.radar.baseData = data; renderRadarMap(); return data; })
        .catch(error => { console.warn("" + meteonexaText("app.loadradarbasedata.local_vector_base_map_unavailable"), error); return null; })
        .finally(() => { state.radar.baseLoading = null; });
    return state.radar.baseLoading;
}
function geoFeatureName(feature, fallback = '') {
    const properties = feature?.properties || {};
    return properties.reg_name || properties.prov_name || properties.name || properties.NAME_1 || fallback;
}
function walkGeoRings(geometry, callback) {
    if (!geometry)
        return;
    if (geometry.type === 'Polygon') {
        (geometry.coordinates || []).forEach(ring => callback(ring));
        return;
    }
    if (geometry.type === 'MultiPolygon') {
        (geometry.coordinates || []).forEach(polygon => (polygon || []).forEach(ring => callback(ring)));
        return;
    }
    if (geometry.type === 'GeometryCollection') {
        (geometry.geometries || []).forEach(item => walkGeoRings(item, callback));
    }
}
function compactGeoCollection(collection, stride = 4) {
    if (!collection?.features?.length)
        return null;
    const features = collection.features.map(feature => {
        const rings = [];
        let minLon = Infinity, minLat = Infinity, maxLon = -Infinity, maxLat = -Infinity;
        walkGeoRings(feature.geometry, ring => {
            if (!Array.isArray(ring) || ring.length < 3)
                return;
            const sampled = [];
            ring.forEach((pair, index) => {
                const lon = Number(pair?.[0]), lat = Number(pair?.[1]);
                if (!Number.isFinite(lon) || !Number.isFinite(lat))
                    return;
                minLon = Math.min(minLon, lon);
                maxLon = Math.max(maxLon, lon);
                minLat = Math.min(minLat, lat);
                maxLat = Math.max(maxLat, lat);
                if (index === 0 || index === ring.length - 1 || index % stride === 0)
                    sampled.push([lon, lat]);
            });
            if (sampled.length >= 3) {
                const first = sampled[0], last = sampled[sampled.length - 1];
                if (first[0] !== last[0] || first[1] !== last[1])
                    sampled.push([...first]);
                rings.push(sampled);
            }
        });
        const bbox = Array.isArray(feature.bbox) && feature.bbox.length >= 4
            ? feature.bbox.map(Number)
            : [minLon, minLat, maxLon, maxLat];
        const center = bbox.every(Number.isFinite)
            ? { lon: (bbox[0] + bbox[2]) / 2, lat: (bbox[1] + bbox[3]) / 2 }
            : null;
        return { name: geoFeatureName(feature), rings, center };
    }).filter(feature => feature.rings.length);
    return { features };
}
async function loadRadarAdminData() {
    if (state.radar.adminData.regions || state.radar.adminData.metros)
        return state.radar.adminData;
    if (state.radar.adminLoading)
        return state.radar.adminLoading;
    const sources = [CONFIG.ITALY_REGIONS_DATA, CONFIG.ITALY_METRO_DATA];
    state.radar.adminLoading = Promise.allSettled(sources.map(url => fetchJSON(url, { timeout: 22000 })))
        .then(results => {
        if (results[0].status === 'fulfilled')
            state.radar.adminData.regions = compactGeoCollection(results[0].value, 5);
        if (results[1].status === 'fulfilled')
            state.radar.adminData.metros = compactGeoCollection(results[1].value, 3);
        renderRadarMap();
        return state.radar.adminData;
    })
        .catch(error => {
        console.warn(meteonexaText("app.loadradaradmindata.italian_administrative_boundaries_unavailable"), error);
        return state.radar.adminData;
    })
        .finally(() => { state.radar.adminLoading = null; });
    return state.radar.adminLoading;
}
