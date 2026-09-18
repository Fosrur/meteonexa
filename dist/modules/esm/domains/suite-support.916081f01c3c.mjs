export const serviceNames = Object.freeze(['suiteSupport']);
export const dependencies = Object.freeze([]);

function factory(window, deps, provided) {
    void deps;
    provided.suiteSupport = Object.freeze({
        create(context) {
            const {
                CONFIG, SERVICES, SECURITY, state, appLocale, temperature, t,
                showToast, withLoader, meteonexaText
            } = context;
            if (!CONFIG || !SERVICES || !SECURITY || !state) {
                throw new Error('METEONEXA_SUITE_SUPPORT_CONTEXT_INVALID');
            }
            const BUILD = '20.1';
            const API = Object.freeze({
                alertPreferences: 'api/preferences/alerts.php',
                snapshots: 'api/snapshots/forecast.php',
                route: 'api/route/calculate.php',
                routeWeather: 'api/route/weather.php',
                ai: 'api/ai/chat.php',
                officialAlerts: 'api/official/alerts.php',
                weatherFusion: 'api/weather/fusion.php'
            });
            const KEYS = Object.freeze({
                device: 'meteonexa_suite_device_id',
                alerts: 'meteonexa_suite_alert_profile',
                notification: 'meteonexa_suite_nowcast_notice',
                snapshot: 'meteonexa_suite_snapshot',
                assistant: 'meteonexa_suite_assistant_history_v3',
                assistantMode: 'meteonexa_suite_assistant_mode_v2'
            });
            const q = (selector, root = window.document) => root.querySelector(selector);
            const qa = (selector, root = window.document) => [...root.querySelectorAll(selector)];
            const n = value => Number.isFinite(Number(value)) ? Number(value) : 0;
            const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
            const safe = value => String(value ?? '').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char]));
            const currentLocale = () => typeof appLocale === 'function' ? appLocale() : (window.navigator.languages?.[0] || window.navigator.language || 'it-IT');
            const mean = values => { const rows = values.map(Number).filter(Number.isFinite); return rows.length ? rows.reduce((a, b) => a + b, 0) / rows.length : 0; };
            const deviation = values => {
                const rows = values.map(Number).filter(Number.isFinite);
                if (rows.length < 2) return 0;
                const m = mean(rows);
                return Math.sqrt(mean(rows.map(v => (v - m) ** 2)));
            };
            const localDate = value => new Intl.DateTimeFormat(currentLocale(), { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(value));
            const localTime = value => new Intl.DateTimeFormat(currentLocale(), { hour: '2-digit', minute: '2-digit' }).format(new Date(value));
            const tempText = value => typeof temperature === 'function' ? temperature(value) : `${Math.round(n(value))}°C`;
            const localizedLocationPart = (value, key) => {
                const current = String(value || '').trim();
                if (current && current !== key) return current;
                const translated = String(meteonexaText?.(key) || '').trim();
                return translated && translated !== key ? translated : '';
            };
            const locationLabel = () => [
                localizedLocationPart(state?.location?.name, CONFIG.DEFAULT_LOCATION.nameKey),
                localizedLocationPart(state?.location?.admin1, CONFIG.DEFAULT_LOCATION.admin1Key)
            ].filter(Boolean).join(', ') || String(meteonexaText('locations.selectonboardinglocation.location_selected'));
            const locationKey = () => `${n(state?.location?.latitude).toFixed(4)}:${n(state?.location?.longitude).toFixed(4)}`;
            const ui = (key, params = {}) => typeof t === 'function' ? t(key, params) : key;
            const apiMessage = (data, status) => {
                const raw = String(data?.message || '').trim();
                if (raw) {
                    const translated = String(meteonexaText?.(raw) || '').trim();
                    if (translated && translated !== raw) return translated;
                    return raw;
                }
                return meteonexaText('suite.apimessage.service_unavailable_value', { p0: status });
            };
            const toast = (title, copy, type = 'success') => typeof showToast === 'function' ? showToast(title, copy, type) : console.log(title, copy);
            const loader = (title, copy, action, minDuration = 420) => SERVICES.get('loader')?.run
                ? SERVICES.get('loader').run(title, copy, action, minDuration)
                : (typeof withLoader === 'function' ? withLoader(title, copy, action, minDuration) : action());
            const deviceId = SECURITY.deviceId;
            const isGuest = () => SERVICES.get('guestAccess')?.isGuest?.() === true
                || state?.session?.type !== 'email'
                || (window.navigator.onLine !== false && SERVICES.get('auth')?.serverVerified?.() !== true);
            async function fetchJson(url, options = {}) {
                const controller = new AbortController();
                const timer = window.setTimeout(() => controller.abort(), options.timeout || 18000);
                try {
                    const response = await window.fetch(url, {
                        method: options.method || 'GET', cache: 'no-store', credentials: options.credentials || 'same-origin', signal: controller.signal,
                        headers: { 'Accept': 'application/json', ...(new URL(url, window.location.href).origin === window.location.origin ? SECURITY.headers() : {}), ...(options.body ? { 'Content-Type': 'application/json' } : {}), ...(options.headers || {}) },
                        body: options.body ? JSON.stringify(options.body) : undefined
                    });
                    const data = await response.json().catch(() => ({}));
                    if (!response.ok || data?.ok === false) {
                        if (response.status === 401 && data?.code === 'AUTH_REQUIRED') SERVICES.get('auth')?.handleAuthRequired?.();
                        const requestError = new Error(apiMessage(data, response.status));
                        requestError.code = String(data?.code || '');
                        requestError.payload = data;
                        requestError.status = response.status;
                        throw requestError;
                    }
                    return data;
                } catch (error) {
                    if (error?.name === 'AbortError') throw new Error(meteonexaText('network.request.timeout'));
                    if (error instanceof TypeError) throw new Error(meteonexaText('network.request.failed'));
                    throw error;
                } finally {
                    window.clearTimeout(timer);
                }
            }
            function dateInput(date) {
                const value = new Date(date);
                const y = value.getFullYear(), m = String(value.getMonth() + 1).padStart(2, '0'), d = String(value.getDate()).padStart(2, '0');
                return `${y}-${m}-${d}`;
            }
            function addDays(value, days) { const date = new Date(value); date.setDate(date.getDate() + days); return date; }
            function setDateField(id, value) {
                const input = q(`#${id}`);
                if (input) { input.value = value; input.dispatchEvent(new Event('meteo-date-sync')); }
                SERVICES.get('datePicker')?.sync?.(id);
            }
            function nearestTimeIndex(times, target) {
                let best = 0, distance = Infinity;
                const at = new Date(target).getTime();
                (times || []).forEach((time, index) => {
                    const delta = Math.abs(new Date(time).getTime() - at);
                    if (delta < distance) { distance = delta; best = index; }
                });
                return best;
            }
            function bearing(a, b) {
                const p1 = a.latitude * Math.PI / 180, p2 = b.latitude * Math.PI / 180, dl = (b.longitude - a.longitude) * Math.PI / 180;
                const y = Math.sin(dl) * Math.cos(p2), x = Math.cos(p1) * Math.sin(p2) - Math.sin(p1) * Math.cos(p2) * Math.cos(dl);
                return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
            }
            function directionName(degrees) {
                if (!Number.isFinite(degrees)) return String(meteonexaText('intelligence.confidencefrommodels.variable'));
                const labels = String(meteonexaText('compass.short') || '').split('|').filter(Boolean);
                return (labels.length === 8 ? labels : Array(8).fill('')).at(Math.round(degrees / 45) % 8) || '';
            }
            function haversine(a, b) {
                const R = 6371, dLat = (b.latitude - a.latitude) * Math.PI / 180, dLon = (b.longitude - a.longitude) * Math.PI / 180;
                const p1 = a.latitude * Math.PI / 180, p2 = b.latitude * Math.PI / 180;
                const x = Math.sin(dLat / 2) ** 2 + Math.cos(p1) * Math.cos(p2) * Math.sin(dLon / 2) ** 2;
                return R * 2 * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x));
            }
            return Object.freeze({ BUILD, API, KEYS, q, qa, n, clamp, safe, currentLocale, mean, deviation, localDate, localTime, tempText,
                localizedLocationPart, locationLabel, locationKey, ui, apiMessage, toast, loader, deviceId, isGuest, fetchJson, dateInput,
                addDays, setDateField, nearestTimeIndex, bearing, directionName, haversine });
        }
    });
}

export function install(services) {
    return services.installModule({ provides: serviceNames, dependencies, factory });
}
