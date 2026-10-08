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

            async function geocodeCity(query) {
                const term = String(query || '').trim();
                if (term.length < 2)
                    throw new Error(meteonexaText('locations.enter_at_least_two_characters'));
                const params = new URLSearchParams({
                    name: term,
                    count: '1',
                    language: String(state?.settings?.language || 'it').slice(0, 2),
                    format: 'json'
                });
                const data = await fetchJson(`${CONFIG.GEOCODING_API}?${params}`, { timeout: 10000, credentials: 'omit' });
                const result = Array.isArray(data?.results) ? data.results[0] : null;
                const latitude = Number(result?.latitude), longitude = Number(result?.longitude);
                if (!result || !Number.isFinite(latitude) || !Number.isFinite(longitude))
                    throw new Error(meteonexaText('locations.searchcities.no_locations_found'));
                return {
                    name: String(result.name || term),
                    admin1: String(result.admin1 || ''),
                    country: String(result.country || ''),
                    latitude,
                    longitude,
                    timezone: String(result.timezone || 'auto')
                };
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
            function pdfAscii(value) {
                return String(value ?? '').normalize('NFKD').replace(/[\u0300-\u036f]/g, '').replace(/[‘’]/g, "'").replace(/[“”]/g, '"').replace(/[–—]/g, '-').replace(/[^\x20-\x7E]/g, '?').replace(/([\\()])/g, '\\$1');
            }
            function buildTextPdf(lines) {
                const pageWidth = 595, pageHeight = 842, margin = 42, lineHeight = 13, fontSize = 9;
                const maxLines = Math.floor((pageHeight - margin * 2) / lineHeight), pages = [];
                for (let i = 0; i < lines.length; i += maxLines) pages.push(lines.slice(i, i + maxLines));
                if (!pages.length) pages.push([]);
                const objects = [], add = body => { objects.push(body); return objects.length; };
                const catalogId = add(''), pagesId = add(''), fontId = add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'), pageIds = [];
                pages.forEach(pageLines => {
                    const stream = ['BT', `/F1 ${fontSize} Tf`, `${margin} ${pageHeight - margin} Td`];
                    pageLines.forEach((line, index) => { if (index) stream.push(`0 -${lineHeight} Td`); stream.push(`(${pdfAscii(line)}) Tj`); });
                    stream.push('ET');
                    const content = stream.join('\n'), contentId = add(`<< /Length ${content.length} >>\nstream\n${content}\nendstream`);
                    pageIds.push(add(`<< /Type /Page /Parent ${pagesId} 0 R /MediaBox [0 0 ${pageWidth} ${pageHeight}] /Resources << /Font << /F1 ${fontId} 0 R >> >> /Contents ${contentId} 0 R >>`));
                });
                objects[catalogId - 1] = `<< /Type /Catalog /Pages ${pagesId} 0 R >>`;
                objects[pagesId - 1] = `<< /Type /Pages /Kids [${pageIds.map(id => `${id} 0 R`).join(' ')}] /Count ${pageIds.length} >>`;
                let pdf = '%PDF-1.4\n'; const offsets = [0];
                objects.forEach((body, index) => { offsets[index + 1] = pdf.length; pdf += `${index + 1} 0 obj\n${body}\nendobj\n`; });
                const xref = pdf.length; pdf += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`;
                for (let i = 1; i <= objects.length; i += 1) pdf += `${String(offsets[i]).padStart(10, '0')} 00000 n \n`;
                pdf += `trailer\n<< /Size ${objects.length + 1} /Root ${catalogId} 0 R >>\nstartxref\n${xref}\n%%EOF`;
                return new Blob([pdf], { type: 'application/pdf' });
            }
            function exportHistoryPdf(history) {
                const d = history?.current?.daily;
                if (!d?.time?.length) { toast(meteonexaText('suite.exporthistorycsv.history_not_loaded'), meteonexaText('suite.exporthistorycsv.load_period_first'), 'warning'); return false; }
                const headers = [meteonexaText('history.renderhistory.date'), meteonexaText('suite.exporthistorycsv.minimum_temperature_c'), meteonexaText('suite.exporthistorycsv.maximum_temperature_c'), meteonexaText('suite.exporthistorycsv.rain_mm'), meteonexaText('suite.exporthistorycsv.snow_cm'), meteonexaText('suite.exporthistorycsv.gusts_kmh')];
                const lines = ['MeteoNexa', `${meteonexaText('home.suite_history_pdf.history_summary')} ${history.start} - ${history.end}`, '', headers.join(' | '), ...d.time.map((date, i) => [date, d.temperature_2m_min[i], d.temperature_2m_max[i], d.precipitation_sum[i], d.snowfall_sum?.[i] ?? 0, d.wind_gusts_10m_max[i]].join(' | '))];
                const url = URL.createObjectURL(buildTextPdf(lines)), a = document.createElement('a');
                a.href = url; a.download = `meteonexa-history-${history.start}-${history.end}.pdf`; a.click(); setTimeout(() => URL.revokeObjectURL(url), 0); return true;
            }
            return Object.freeze({ BUILD, API, KEYS, q, qa, n, clamp, safe, currentLocale, mean, deviation, localDate, localTime, tempText,
                localizedLocationPart, locationLabel, locationKey, ui, apiMessage, toast, loader, deviceId, isGuest, fetchJson, geocodeCity, dateInput,
                addDays, setDateField, nearestTimeIndex, bearing, directionName, haversine, exportHistoryPdf });
        }
    });
}

export function install(services) {
    return services.installModule({ provides: serviceNames, dependencies, factory });
}
