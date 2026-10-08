'use strict';
function drawRadarAdministrativeLayer(ctx, overlay, centerWorld, zoom, width, height, options = {}) {
    if (!overlay?.features?.length)
        return;
    const { stroke = 'rgba(79,224,209,.72)', fill = 'rgba(79,224,209,.025)', width: lineWidth = 1.2, dash = [], labelsAt = 6.2, labelColor = '#a5f5e9' } = options;
    ctx.save();
    ctx.strokeStyle = stroke;
    ctx.fillStyle = fill;
    ctx.lineWidth = lineWidth;
    ctx.lineJoin = 'round';
    ctx.lineCap = 'round';
    ctx.setLineDash(dash);
    for (const feature of overlay.features) {
        let hasVisiblePath = false;
        ctx.beginPath();
        for (const ring of feature.rings) {
            let started = false;
            let previous = null;
            for (const coordinates of ring) {
                const point = radarCanvasPoint(coordinates[1], coordinates[0], centerWorld, zoom, width, height);
                const visible = point.x > -220 && point.x < width + 220 && point.y > -220 && point.y < height + 220;
                const jump = previous && Math.abs(point.x - previous.x) > width * .68;
                if (!visible || jump) {
                    started = false;
                    previous = point;
                    continue;
                }
                if (!started) {
                    ctx.moveTo(point.x, point.y);
                    started = true;
                    hasVisiblePath = true;
                }
                else
                    ctx.lineTo(point.x, point.y);
                previous = point;
            }
            if (started)
                ctx.closePath();
        }
        if (hasVisiblePath) {
            if (fill !== 'transparent')
                ctx.fill('evenodd');
            ctx.stroke();
        }
    }
    ctx.setLineDash([]);
    if (zoom >= labelsAt) {
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.font = `800 ${zoom >= 7.5 ? 12 : 10}px Inter, system-ui, sans-serif`;
        for (const feature of overlay.features) {
            if (!feature.center || !feature.name)
                continue;
            const point = radarCanvasPoint(feature.center.lat, feature.center.lon, centerWorld, zoom, width, height);
            if (point.x < 34 || point.x > width - 34 || point.y < 34 || point.y > height - 34)
                continue;
            ctx.lineWidth = 4;
            ctx.strokeStyle = 'rgba(3,16,34,.88)';
            ctx.strokeText(feature.name, point.x, point.y);
            ctx.fillStyle = labelColor;
            ctx.fillText(feature.name, point.x, point.y);
        }
    }
    ctx.restore();
}
function radarCanvasPoint(lat, lon, centerWorld, zoom, width, height) {
    const pointWorld = lonLatToWorld(lat, lon, zoom);
    const worldSize = 256 * 2 ** zoom;
    let dx = pointWorld.x - centerWorld.x;
    if (dx > worldSize / 2)
        dx -= worldSize;
    if (dx < -worldSize / 2)
        dx += worldSize;
    return { x: width / 2 + dx, y: height / 2 + pointWorld.y - centerWorld.y };
}
function drawRadarBaseMap() {
    const canvas = $('#radar-base-canvas');
    const container = $('#radar-map');
    if (!canvas || !container)
        return;
    const rect = container.getBoundingClientRect();
    const width = Math.max(1, Math.round(rect.width)), height = Math.max(1, Math.round(rect.height));
    const dpr = Math.min(2, devicePixelRatio || 1);
    if (canvas.width !== Math.round(width * dpr) || canvas.height !== Math.round(height * dpr)) {
        canvas.width = Math.round(width * dpr);
        canvas.height = Math.round(height * dpr);
        canvas.style.width = `${width}px`;
        canvas.style.height = `${height}px`;
    }
    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, width, height);
    const useVectorMap = Boolean(state.radar.vectorMapReady);
    const center = state.radar.center || { lat: Number(state.location.latitude), lon: Number(state.location.longitude) };
    const centerWorld = lonLatToWorld(center.lat, center.lon, state.radar.zoom);
    if (!useVectorMap) {
        const bg = ctx.createLinearGradient(0, 0, width, height);
        bg.addColorStop(0, '#071b36');
        bg.addColorStop(.52, '#0a2747');
        bg.addColorStop(1, '#041327');
        ctx.fillStyle = bg;
        ctx.fillRect(0, 0, width, height);
        const glow = ctx.createRadialGradient(width * .52, height * .48, 0, width * .52, height * .48, Math.max(width, height) * .64);
        glow.addColorStop(0, 'rgba(17,135,255,.18)');
        glow.addColorStop(.55, 'rgba(26,91,164,.07)');
        glow.addColorStop(1, 'rgba(0,0,0,.18)');
        ctx.fillStyle = glow;
        ctx.fillRect(0, 0, width, height);
        ctx.strokeStyle = 'rgba(76,178,255,.09)';
        ctx.lineWidth = 1;
        for (let lat = -80; lat <= 80; lat += 10) {
            ctx.beginPath();
            let started = false;
            for (let lon = -180; lon <= 180; lon += 3) {
                const point = radarCanvasPoint(lat, lon, centerWorld, state.radar.zoom, width, height);
                if (point.x < -80 || point.x > width + 80 || point.y < -80 || point.y > height + 80) {
                    started = false;
                    continue;
                }
                if (!started) {
                    ctx.moveTo(point.x, point.y);
                    started = true;
                }
                else
                    ctx.lineTo(point.x, point.y);
            }
            ctx.stroke();
        }
        for (let lon = -180; lon < 180; lon += 10) {
            ctx.beginPath();
            let started = false;
            for (let lat = -82; lat <= 82; lat += 2) {
                const point = radarCanvasPoint(lat, lon, centerWorld, state.radar.zoom, width, height);
                if (point.x < -80 || point.x > width + 80 || point.y < -80 || point.y > height + 80) {
                    started = false;
                    continue;
                }
                if (!started) {
                    ctx.moveTo(point.x, point.y);
                    started = true;
                }
                else
                    ctx.lineTo(point.x, point.y);
            }
            ctx.stroke();
        }
        const drawSegments = (segments, stroke, lineWidth) => {
            if (!segments)
                return;
            ctx.strokeStyle = stroke;
            ctx.lineWidth = lineWidth;
            ctx.lineJoin = 'round';
            ctx.lineCap = 'round';
            for (const segment of segments) {
                ctx.beginPath();
                let started = false, previous = null;
                for (const coordinates of segment) {
                    const point = radarCanvasPoint(coordinates[1], coordinates[0], centerWorld, state.radar.zoom, width, height);
                    const visible = point.x > -160 && point.x < width + 160 && point.y > -160 && point.y < height + 160;
                    const jump = previous && Math.abs(point.x - previous.x) > width * .65;
                    if (!visible || jump) {
                        started = false;
                        previous = point;
                        continue;
                    }
                    if (!started) {
                        ctx.moveTo(point.x, point.y);
                        started = true;
                    }
                    else
                        ctx.lineTo(point.x, point.y);
                    previous = point;
                }
                ctx.stroke();
            }
        };
        drawSegments(state.radar.baseData?.coastlines, 'rgba(95,210,255,.48)', 1.35);
        drawSegments(state.radar.baseData?.countries, 'rgba(166,215,255,.20)', .75);
        drawRadarAdministrativeLayer(ctx, state.radar.adminData.regions, centerWorld, state.radar.zoom, width, height, {
            stroke: 'rgba(77,232,207,.68)', fill: 'rgba(44,205,181,.018)', width: 1.25, labelsAt: 6.1, labelColor: '#a3f3e5'
        });
        drawRadarAdministrativeLayer(ctx, state.radar.adminData.metros, centerWorld, state.radar.zoom, width, height, {
            stroke: 'rgba(174,128,255,.82)', fill: 'rgba(151,101,255,.025)', width: 1.4, dash: [5, 4], labelsAt: 7.0, labelColor: '#dcc8ff'
        });
    }
    else {
        const shade = ctx.createLinearGradient(0, 0, 0, height);
        shade.addColorStop(0, 'rgba(2,14,29,.08)');
        shade.addColorStop(.6, 'rgba(2,17,34,.13)');
        shade.addColorStop(1, 'rgba(1,10,23,.24)');
        ctx.fillStyle = shade;
        ctx.fillRect(0, 0, width, height);
        const drawFallbackSegments = (segments, stroke, lineWidth) => {
            if (!segments)
                return;
            ctx.strokeStyle = stroke;
            ctx.lineWidth = lineWidth;
            ctx.lineJoin = 'round';
            ctx.lineCap = 'round';
            for (const segment of segments) {
                ctx.beginPath();
                let started = false, previous = null;
                for (const coordinates of segment) {
                    const point = radarCanvasPoint(coordinates[1], coordinates[0], centerWorld, state.radar.zoom, width, height);
                    const visible = point.x > -140 && point.x < width + 140 && point.y > -140 && point.y < height + 140;
                    const jump = previous && Math.abs(point.x - previous.x) > width * .68;
                    if (!visible || jump) {
                        started = false;
                        previous = point;
                        continue;
                    }
                    if (!started) {
                        ctx.moveTo(point.x, point.y);
                        started = true;
                    }
                    else
                        ctx.lineTo(point.x, point.y);
                    previous = point;
                }
                ctx.stroke();
            }
        };
        drawFallbackSegments(state.radar.baseData?.coastlines, 'rgba(101,218,255,.24)', .9);
        drawFallbackSegments(state.radar.baseData?.countries, 'rgba(181,226,255,.13)', .65);
    }
    const cx = width / 2, cy = height / 2;
    ctx.strokeStyle = 'rgba(45,169,255,.24)';
    ctx.lineWidth = 1.2;
    [55, 110, 175].forEach(radius => { ctx.beginPath(); ctx.arc(cx, cy, radius, 0, Math.PI * 2); ctx.stroke(); });
    const angle = (performance.now() / 2600) % (Math.PI * 2);
    const sweep = ctx.createRadialGradient(cx, cy, 10, cx, cy, 210);
    sweep.addColorStop(0, 'rgba(61,211,255,.15)');
    sweep.addColorStop(1, 'rgba(61,211,255,0)');
    ctx.save();
    ctx.translate(cx, cy);
    ctx.rotate(angle);
    ctx.fillStyle = sweep;
    ctx.beginPath();
    ctx.moveTo(0, 0);
    ctx.arc(0, 0, 210, -.18, .18);
    ctx.closePath();
    ctx.fill();
    ctx.restore();
    ctx.fillStyle = 'rgba(202,232,255,.72)';
    ctx.font = '600 12px Inter, system-ui, sans-serif';
    ctx.textAlign = 'left';
    ctx.fillText(`${center.lat.toFixed(2)}° · ${center.lon.toFixed(2)}°`, 18, height - 18);
    const cityLabel = shortLocationLabel(state.location);
    if (cityLabel) {
        ctx.textAlign = 'center';
        ctx.font = '800 12px Inter, system-ui, sans-serif';
        ctx.lineWidth = 4;
        ctx.strokeStyle = 'rgba(2,14,31,.9)';
        ctx.strokeText(cityLabel, cx, cy + 34);
        ctx.fillStyle = '#e9f8ff';
        ctx.fillText(cityLabel, cx, cy + 34);
    }
}
function privacyReturnHash() {
    const page = String(state.currentPage || 'home');
    const allowed = new Set(['home','radar','favorites','details','history','intelligence','advanced','bug-report','route','devices']);
    return `#${allowed.has(page) ? page : 'home'}`;
}
function privacyPageHref() {
    const url = new URL('privacy.html', location.href);
    url.searchParams.set('return', privacyReturnHash());
    return `${url.pathname.split('/').pop()}${url.search}`;
}
function syncPrivacyContextCopy() {
    const guest = isGuestSession();
    const note = $('.privacy-ai-note');
    const title = $('.privacy-fact-ai strong');
    const copy = $('.privacy-fact-ai small');
    if (note) note.setAttribute('data-i18n-key', guest ? 'privacy.ai.short_notice.guest' : 'privacy.ai.short_notice');
    if (title) title.setAttribute('data-i18n-key', guest ? 'privacy.ai.fact.guest.title' : 'privacy.ai.fact.title');
    if (copy) copy.setAttribute('data-i18n-key', guest ? 'privacy.ai.fact.guest.copy' : 'privacy.ai.fact.copy');
    $$('[data-privacy-link]').forEach(link => {
        link.setAttribute('href', privacyPageHref());
        link.removeAttribute('target');
        link.removeAttribute('rel');
    });
    translateDOM(document);
}
function acknowledgePrivacyNotice() {
    state.privacyNotice = SERVICES.get('privacy')?.acknowledge?.(Date.now()) || { version: PRIVACY_NOTICE_VERSION, acknowledgedAt: Date.now() };
    saveJSON(STORAGE.privacyNotice, state.privacyNotice);
    const notice = $('#privacy-notice');
    if (notice)
        notice.hidden = true;
}
function openPrivacyCenter() {
    syncPrivacyContextCopy();
    const dialog = $('#privacy-dialog');
    if (dialog && !dialog.open)
        dialog.showModal();
}
function initializePrivacyNotice() {
    syncPrivacyContextCopy();
    const notice = $('#privacy-notice');
    if (!notice)
        return;
    notice.hidden = state.privacyNotice?.version === PRIVACY_NOTICE_VERSION;
}
function getToastHost() {
    const openDialogs = $$('dialog.app-dialog[open], dialog.assistant-dialog[open]');
    const activeDialog = openDialogs.at(-1);
    if (activeDialog) {
        let region = $('.dialog-toast-region', activeDialog);
        if (!region) {
            region = document.createElement('div');
            region.className = 'toast-region dialog-toast-region';
            region.setAttribute('aria-live', 'polite');
            region.setAttribute('aria-atomic', 'true');
            activeDialog.appendChild(region);
        }
        return region;
    }
    return $('#toast-region');
}
function showToast(title, message = '', type = 'info', timeout = 3600) {
    const root = getToastHost();
    if (!root)
        return;
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    const icon = type === 'success' ? '✓' : type === 'error' ? '!' : type === 'warning' ? '!' : 'i';
    toast.innerHTML = `<span class="toast-icon">${icon}</span><span><strong>${escapeHTML(t(title))}</strong><small>${escapeHTML(t(message))}</small></span><button class="toast-close" type="button" aria-label="${escapeHTML(t("app.showtoast.close"))}"><svg><use href="#i-close"/></svg></button>`;
    root.appendChild(toast);
    const remove = () => {
        if (!toast.isConnected)
            return;
        toast.classList.add('removing');
        setTimeout(() => toast.remove(), 250);
    };
    $('.toast-close', toast).addEventListener('click', remove);
    setTimeout(remove, timeout);
}
function weatherNeedsForegroundRefresh(maxAgeMs = 5 * 60 * 1000) {
    if (!state.weather?.fetchedAt)
        return true;
    return Date.now() - Number(state.weather.fetchedAt) >= maxAgeMs;
}
function refreshWeatherOnForeground() {
    if (!state.bootComplete || $('#weather-app')?.hidden !== false || !weatherNeedsForegroundRefresh())
        return Promise.resolve(state.weather);
    const now = Date.now();
    if (now - Number(state.lastForegroundRefreshAt || 0) < 1500)
        return state.weatherRequest || Promise.resolve(state.weather);
    state.lastForegroundRefreshAt = now;
    return loadWeather({ force: false, silent: true });
}
async function requestSafeBackgroundSync() {
    if (!('serviceWorker' in navigator))
        return false;
    try {
        const registration = await navigator.serviceWorker.ready;
        if (registration?.sync?.register) {
            await registration.sync.register('meteonexa-safe-sync');
            return true;
        }
    }
    catch (error) {
        console.debug('SAFE_BACKGROUND_SYNC_UNAVAILABLE', error?.message || error);
    }
    return false;
}
function updateNetworkStatus() {
    const banner = $('#network-banner');
    const online = navigator.onLine;
    banner.textContent = online ? meteonexaText('offline.back_online') : meteonexaText('offline.cached_mode');
    banner.className = `network-banner show ${online ? '' : 'offline'}`;
    setTimeout(() => banner.classList.remove('show'), 3600);
    if (state.weather)
        renderHeader();
    if (!online) {
        showToast(t('offline.mode.title'), t('offline.mode.copy'), 'warning', 5200);
        return;
    }
    if (!$('#weather-app').hidden) {
        Promise.allSettled([
            loadWeather({ force: true, silent: true }),
            synchronizeRemotePreferences({ force: true }),
            synchronizePersonalWeatherPreferences({ force: true }),
            state.currentPage === 'intelligence' ? loadIntelligence({ force: true, silent: true }) : Promise.resolve(),
            state.currentPage === 'radar' ? ensureRadar(true, { silent: true }) : Promise.resolve()
        ]).then(results => {
            const failed = results.some(result => result.status === 'rejected');
            showToast(t(failed ? 'offline.sync.partial.title' : 'offline.sync.done.title'),
                t(failed ? 'offline.sync.partial.copy' : 'offline.sync.done.copy'),
                failed ? 'warning' : 'success', 4400);
        }).catch(() => {});
    }
}
function updateLiveClocks() {
    const deviceTime = $('#device-time');
    if (deviceTime)
        deviceTime.textContent = nowTime();
    refreshTimeZoneLabels();
}
function startLiveClocks() {
    if (window.__METEONEXA_BOOT_CLOCK__) {
        clearInterval(window.__METEONEXA_BOOT_CLOCK__);
        window.__METEONEXA_BOOT_CLOCK__ = null;
    }
    clearTimeout(state.clockTimer);
    const tick = () => {
        updateLiveClocks();
        const delay = Math.max(250, 1000 - (Date.now() % 1000) + 20);
        state.clockTimer = setTimeout(tick, delay);
    };
    tick();
}
function scheduleRefresh() {
    clearTimeout(state.refreshTimer);
    const minutes = Math.max(1, Number(state.settings.refresh || CONFIG.REFRESH_MINUTES));
    const interval = minutes * 60 * 1000;
    const tick = async () => {
        if (!document.hidden && !$('#weather-app').hidden) {
            await loadWeather({ force: true, silent: true });
            if (state.currentPage === "radar" && navigator.onLine)
                ensureRadar(true, { silent: true });
        }
        state.refreshTimer = setTimeout(tick, interval);
    };
    const alignedDelay = state.bootComplete
        ? Math.max(1000, interval - (Date.now() % interval) + 120)
        : interval;
    state.refreshTimer = setTimeout(tick, alignedDelay);
}
function startRealtimeSynchronization() {
    clearInterval(state.preferenceSyncTimer);
    if ('BroadcastChannel' in window && !state.syncChannel) {
        try {
            state.syncChannel = new BroadcastChannel('meteonexa-realtime-v1');
            state.syncChannel.addEventListener('message', event => {
                if (event.data?.type === 'preferences-updated')
                    synchronizeRemotePreferences({ force: true });
            });
        }
        catch {
            state.syncChannel = null;
        }
    }
    state.preferenceSyncTimer = setInterval(() => synchronizeRemotePreferences(), 10000);
    scheduleSevereWeatherMonitor();
}
