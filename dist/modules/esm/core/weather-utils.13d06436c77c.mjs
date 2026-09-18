export function create(context = {}) {
    const { state, config: CONFIG, storage: STORAGE, saveJSON, appLocale, t, $, $$ } = context;
    if (!state || !CONFIG || !STORAGE || typeof saveJSON !== 'function' || typeof appLocale !== 'function' || typeof t !== 'function') {
        throw new Error('WEATHER_UTILS_CONFIG_INVALID');
    }
    const meteonexaText = (key, params = {}) => globalThis.meteonexaText?.(key, params) ?? String(key ?? '');
    let weatherSvgId = 0;
    function escapeHTML(value = '') {
        return String(value).replace(/[&<>'"]/g, char => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
        })[char]);
    }
    function clamp(value, min, max) { return Math.min(max, Math.max(min, value)); }
    function optionalFiniteNumber(value) {
        if (value === null || value === undefined || value === '') return null;
        const number = Number(value);
        return Number.isFinite(number) ? number : null;
    }
    function sleep(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }
    function debounce(fn, delay = 250) {
        let timer;
        return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), delay); };
    }
    function capitalize(value = '') { return value ? value.charAt(0).toUpperCase() + value.slice(1) : ''; }
    function nowTime() { return new Intl.DateTimeFormat(appLocale(), { hour: '2-digit', minute: '2-digit' }).format(new Date()); }
    function isValidTimeZone(timeZone) {
        if (!timeZone || timeZone === 'auto')
            return false;
        try {
            new Intl.DateTimeFormat(appLocale(), { timeZone }).format(new Date());
            return true;
        }
        catch {
            return false;
        }
    }
    function resolveLocationTimeZone(locationData = state.location, weatherData = state.weather) {
        const candidates = [weatherData?.timezone, locationData?.timezone, Intl.DateTimeFormat().resolvedOptions().timeZone, CONFIG.DEFAULT_LOCATION?.timezone, 'UTC'];
        return candidates.find(isValidTimeZone) || 'UTC';
    }
    function formatUtcOffsetSeconds(seconds) {
        const value = Number(seconds);
        if (!Number.isFinite(value))
            return '';
        const sign = value >= 0 ? '+' : '-';
        const absoluteMinutes = Math.round(Math.abs(value) / 60);
        const hours = Math.floor(absoluteMinutes / 60);
        const minutes = absoluteMinutes % 60;
        return `UTC${sign}${hours}${minutes ? `:${String(minutes).padStart(2, '0')}` : ''}`;
    }
    function timeZoneOffsetLabel(locationData = state.location, weatherData = state.weather, date = new Date()) {
        const zone = resolveLocationTimeZone(locationData, weatherData);
        const weatherZone = weatherData?.timezone;
        const locationOffset = Number(locationData?.utcOffsetSeconds);
        const weatherOffset = Number(weatherData?.utc_offset_seconds);
        if (weatherZone === zone && Number.isFinite(weatherOffset))
            return formatUtcOffsetSeconds(weatherOffset);
        if (locationData?.timezone === zone && Number.isFinite(locationOffset))
            return formatUtcOffsetSeconds(locationOffset);
        try {
            const value = new Intl.DateTimeFormat('en-US', {
                timeZone: zone,
                timeZoneName: 'shortOffset',
                hour: '2-digit'
            }).formatToParts(date).find(part => part.type === 'timeZoneName')?.value || '';
            if (value)
                return value.replace(/^GMT/, 'UTC').replace(':00', '');
        }
        catch { }
        try {
            const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
                timeZone: zone,
                year: 'numeric', month: '2-digit', day: '2-digit',
                hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23'
            }).formatToParts(date).filter(part => part.type !== 'literal').map(part => [part.type, part.value]));
            const utcValue = Date.UTC(Number(parts.year), Number(parts.month) - 1, Number(parts.day), Number(parts.hour), Number(parts.minute), Number(parts.second));
            return formatUtcOffsetSeconds(Math.round((utcValue - date.getTime()) / 1000));
        }
        catch {
            return 'UTC';
        }
    }
    function formatLocationLocalTime(locationData = state.location, date = new Date(), weatherData = state.weather) {
        const timeZone = resolveLocationTimeZone(locationData, weatherData);
        try {
            return new Intl.DateTimeFormat(appLocale(), { timeZone, hour: '2-digit', minute: '2-digit' }).format(date);
        }
        catch {
            return nowTime();
        }
    }
    function formatTimeZoneName(timeZone) {
        if (!timeZone)
            return 'UTC';
        return String(timeZone).replace(/_/g, ' ');
    }
    function locationTimeZoneSummary(locationData = state.location, weatherData = state.weather, { compact = false } = {}) {
        const timeZone = resolveLocationTimeZone(locationData, weatherData);
        const localTime = formatLocationLocalTime(locationData, new Date(), weatherData);
        const offset = timeZoneOffsetLabel(locationData, weatherData);
        const abbreviation = weatherData?.timezone_abbreviation || locationData?.timezoneAbbreviation || '';
        const zoneDetails = [formatTimeZoneName(timeZone), abbreviation && abbreviation !== timeZone ? abbreviation : '', offset].filter(Boolean).join(' · ');
        return compact ? `${localTime} · ${offset}` : `${t("weather.locationtimezonesummary.local_time")} ${localTime} · ${zoneDetails}`;
    }
    function applyWeatherTimeZoneMetadata(weatherData) {
        if (!weatherData)
            return;
        const timeZone = isValidTimeZone(weatherData.timezone) ? weatherData.timezone : resolveLocationTimeZone(state.location, weatherData);
        const metadata = {
            timezone: timeZone,
            timezoneAbbreviation: weatherData.timezone_abbreviation || state.location?.timezoneAbbreviation || '',
            utcOffsetSeconds: Number.isFinite(Number(weatherData.utc_offset_seconds)) ? Number(weatherData.utc_offset_seconds) : state.location?.utcOffsetSeconds
        };
        state.location = { ...state.location, ...metadata };
        state.favorites = state.favorites.map(item => sameLocation(item, state.location) ? { ...item, ...metadata } : item);
        state.recent = state.recent.map(item => sameLocation(item, state.location) ? { ...item, ...metadata } : item);
        saveJSON(STORAGE.location, state.location);
        saveJSON(STORAGE.favorites, state.favorites);
        saveJSON(STORAGE.recent, state.recent);
    }
    function refreshTimeZoneLabels() {
        const summary = locationTimeZoneSummary();
        const compact = locationTimeZoneSummary(state.location, state.weather, { compact: true });
        const top = $('#top-timezone');
        const hero = $('#hero-timezone');
        if (top) {
            top.textContent = compact;
            top.dataset.tooltip = summary;
        }
        if (hero) {
            hero.textContent = summary;
            hero.dataset.tooltip = summary;
        }
        $$('.favorite-timezone[data-timezone]').forEach(element => {
            const timeZone = element.dataset.timezone || 'UTC';
            const utcOffsetSeconds = element.dataset.offset === '' ? undefined : Number(element.dataset.offset);
            const data = { timezone: timeZone, utcOffsetSeconds };
            element.textContent = locationTimeZoneSummary(data, null, { compact: false });
        });
    }
    function localizedLocationPart(value = '') {
        return value ? t(String(value)) : '';
    }
    function fullLocationLabel(locationData = state.location) {
        return [locationData.name, locationData.admin1, locationData.country].filter(Boolean).map(localizedLocationPart).join(', ');
    }
    function shortLocationLabel(locationData = state.location) {
        return [locationData.name, locationData.admin1].filter(Boolean).map(localizedLocationPart).join(', ');
    }
    function locationKey(locationData = state.location) {
        return `${Number(locationData.latitude).toFixed(3)},${Number(locationData.longitude).toFixed(3)}`;
    }
    function normalizeLocationIdentityPart(value = '') {
        return String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim()
            .toLocaleLowerCase(appLocale())
            .replace(/\s+/g, ' ');
    }
    function locationIdentity(locationData = state.location) {
        return [locationData?.name, locationData?.admin1, locationData?.country]
            .map(normalizeLocationIdentityPart)
            .filter(Boolean)
            .join('|');
    }
    function sameLocation(first, second) {
        if (!first || !second)
            return false;
        const firstIdentity = locationIdentity(first);
        const secondIdentity = locationIdentity(second);
        if (firstIdentity && secondIdentity && firstIdentity === secondIdentity)
            return true;
        const firstLat = Number(first.latitude), firstLon = Number(first.longitude);
        const secondLat = Number(second.latitude), secondLon = Number(second.longitude);
        return Number.isFinite(firstLat) && Number.isFinite(firstLon)
            && Number.isFinite(secondLat) && Number.isFinite(secondLon)
            && Math.abs(firstLat - secondLat) < .025
            && Math.abs(firstLon - secondLon) < .025
            && normalizeLocationIdentityPart(first.name) === normalizeLocationIdentityPart(second.name);
    }
    function uniqueLocations(items = []) {
        return items.filter((item, index, list) => item && index === list.findIndex(candidate => sameLocation(item, candidate)));
    }
    function formatDay(dateValue, index = 0) {
        if (index === 0)
            return t("weather.formatday.today");
        if (index === 1)
            return t("weather.formatday.tomorrow");
        return capitalize(new Intl.DateTimeFormat(appLocale(), { weekday: 'long', timeZone: 'UTC' }).format(new Date(`${dateValue}T12:00:00Z`)));
    }
    function formatShortDate(dateValue) {
        return new Intl.DateTimeFormat(appLocale(), { day: '2-digit', month: 'short', timeZone: 'UTC' }).format(new Date(`${dateValue}T12:00:00Z`));
    }
    function formatClock(dateValue) {
        const match = typeof dateValue === 'string' ? dateValue.match(/T(\d{2}:\d{2})/) : null;
        if (match)
            return match[1];
        return new Intl.DateTimeFormat(appLocale(), { hour: '2-digit', minute: '2-digit' }).format(new Date(dateValue));
    }
    function localDateHourKey(locationData = state.location, weatherData = state.weather, date = new Date()) {
        const timeZone = resolveLocationTimeZone(locationData, weatherData);
        try {
            const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
                timeZone,
                year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', hourCycle: 'h23'
            }).formatToParts(date).filter(part => part.type !== 'literal').map(part => [part.type, part.value]));
            return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}`;
        }
        catch {
            return new Date(date).toISOString().slice(0, 13);
        }
    }
    function localSeriesIndex(times = [], { currentTime = '', locationData = state.location, weatherData = state.weather } = {}) {
        if (!times.length)
            return 0;
        const target = String(currentTime || '').slice(0, 13) || localDateHourKey(locationData, weatherData);
        const exact = times.findIndex(time => String(time).slice(0, 13) === target);
        if (exact >= 0)
            return exact;
        const next = times.findIndex(time => String(time).slice(0, 13) >= target);
        return next < 0 ? times.length - 1 : Math.max(0, next);
    }
    function windDirection(degrees = 0) {
        const names = String(meteonexaText('compass.short') || '').split('|').filter(Boolean);
        return (names.length === 8 ? names : Array(8).fill('')).at(Math.round(Number(degrees) / 45) % 8) || '';
    }
    function convertTemp(celsius) {
        const value = Number(celsius);
        return state.settings.unit === 'fahrenheit' ? value * 9 / 5 + 32 : value;
    }
    function temperature(value, digits = 0) {
        if (value === null || value === undefined || Number.isNaN(Number(value)))
            return '--°';
        return `${convertTemp(value).toFixed(digits)}°`;
    }
    function unitLabel() { return state.settings.unit === 'fahrenheit' ? '°F' : "" + meteonexaText("weather.unitlabel.c"); }
    function metricNoteHumidity(value) {
        if (value < 35)
            return t("weather.metricnotehumidity.dry_air");
        if (value <= 65)
            return t("weather.metricnotehumidity.ideal_level");
        return t("weather.metricnotehumidity.humid_air");
    }
    function metricNotePressure(value) {
        if (value < 1000)
            return t("weather.metricnotepressure.low");
        if (value > 1022)
            return t("weather.metricnotepressure.high");
        return t("weather.metricnotepressure.stable");
    }
    function metricNoteVisibility(valueMeters) {
        const km = Number(valueMeters || 0) / 1000;
        if (km >= 15)
            return t("weather.metricnotevisibility.excellent");
        if (km >= 8)
            return t("weather.metricnotevisibility.good");
        return t("weather.metricnotevisibility.reduced");
    }
    function uvLabel(value) {
        if (value < 3)
            return t("weather.uvlabel.low");
        if (value < 6)
            return t("weather.uvlabel.moderate");
        if (value < 8)
            return t("weather.uvlabel.high");
        if (value < 11)
            return t("intelligence.confidencefrommodels.very_high");
        return t("weather.uvlabel.extreme");
    }
    function weatherMeta(code, isDay = 1) {
        const numeric = Number(code);
        if (numeric === 0)
            return { label: isDay ? t("weather.weathermeta.clear") : t("weather.weathermeta.clear_sky"), kind: isDay ? 'clear' : 'night', icon: isDay ? 'clear' : 'night' };
        if ([1, 2].includes(numeric))
            return { label: t("weather.weathermeta.partly_cloudy"), kind: isDay ? 'clear' : 'night', icon: isDay ? 'partly' : 'partly-night' };
        if (numeric === 3)
            return { label: t("weather.weathermeta.cloudy"), kind: 'cloud', icon: 'cloud' };
        if ([45, 48].includes(numeric))
            return { label: t("weather.weathermeta.fog"), kind: 'fog', icon: 'fog' };
        if ([51, 53, 55, 56, 57].includes(numeric))
            return { label: t("weather.weathermeta.drizzle"), kind: 'rain', icon: 'drizzle' };
        if ([61, 63, 65, 66, 67, 80, 81, 82].includes(numeric))
            return { label: numeric >= 80 ? t("weather.weathermeta.showers") : t("history.renderhistory.rain"), kind: 'rain', icon: 'rain' };
        if ([71, 73, 75, 77, 85, 86].includes(numeric))
            return { label: t("history.renderhistory.snow"), kind: 'snow', icon: 'snow' };
        if ([95, 96, 99].includes(numeric))
            return { label: t("weather.weathermeta.thunderstorm"), kind: 'storm', icon: 'storm' };
        return { label: t("intelligence.confidencefrommodels.variable"), kind: 'cloud', icon: 'partly' };
    }
    function weatherArt(code, isDay = 1) {
        const { icon } = weatherMeta(code, isDay);
        const id = ++weatherSvgId;
        const cloud = `
        <g class="wx-cloud-back" opacity=".72"><path d="M56 78c-14.7 0-25-8.7-25-21 0-11 8.1-19.4 19.2-20.8C54.7 24.9 64.4 18 76.5 18c15 0 27.5 10.8 30 25.2 2.2-.8 4.7-1.2 7.3-1.2 12.8 0 23.2 9.7 23.2 21.6 0 8.4-5.1 15.7-12.6 19.4H56Z" fill="url(#cloudBack${id})"/></g>
        <g class="wx-cloud-front"><path d="M48 102c-17 0-29-10.2-29-24.4 0-12.8 9.5-22.4 22.2-24.1C46.5 39.7 58 31.8 72 31.8c17.6 0 32.2 12.7 35.2 29.5 2.6-.9 5.4-1.4 8.4-1.4 15 0 27.2 11.4 27.2 25.4 0 9.9-6 18.5-14.8 22.7H48Z" fill="url(#cloudFront${id})"/></g>`;
        const defs = `<defs>
        <linearGradient id="cloudFront${id}" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#ffffff"/><stop offset=".55" stop-color="#e7f1ff"/><stop offset="1" stop-color="#a8bedc"/></linearGradient>
        <linearGradient id="cloudBack${id}" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#dff1ff"/><stop offset="1" stop-color="#7e9fca"/></linearGradient>
        <radialGradient id="sun${id}" cx="35%" cy="30%"><stop stop-color="#fff6a8"/><stop offset=".58" stop-color="#ffc92e"/><stop offset="1" stop-color="#ff8a00"/></radialGradient>
        <linearGradient id="moon${id}" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#f9fbff"/><stop offset="1" stop-color="#9fc7f2"/></linearGradient>
      </defs>`;
        const sun = `<g class="wx-sun" transform-origin="56px 48px"><g stroke="#ffd44f" stroke-width="4" stroke-linecap="round"><path d="M56 8v10M56 78v10M16 48h10M86 48h10M28 20l7 7M77 69l7 7M28 76l7-7M77 27l7-7"/></g><circle cx="56" cy="48" r="24" fill="url(#sun${id})"/></g>`;
        const moon = `<g><path d="M85 20c-18 4-29 23-23 40 5 16 22 25 38 20-8 11-22 17-36 13-20-5-32-26-26-46 6-20 27-32 47-27Z" fill="url(#moon${id})"/><circle cx="105" cy="26" r="2" fill="#d7ecff"/><circle cx="118" cy="43" r="1.5" fill="#d7ecff"/></g>`;
        const rain = `<g stroke="#4bc7ff" stroke-width="5" stroke-linecap="round"><path class="wx-rain" d="M52 111l-5 12"/><path class="wx-rain" d="M83 111l-5 12"/><path class="wx-rain" d="M114 111l-5 12"/></g>`;
        const drizzle = `<g stroke="#65d7ff" stroke-width="4" stroke-linecap="round"><path class="wx-rain" d="M63 112l-3 7"/><path class="wx-rain" d="M95 112l-3 7"/></g>`;
        const snow = `<g fill="#dff7ff"><circle class="wx-snow" cx="54" cy="118" r="4"/><circle class="wx-snow" cx="82" cy="114" r="4"/><circle class="wx-snow" cx="110" cy="120" r="4"/></g>`;
        const bolt = `<path class="wx-bolt" d="m86 104-14 24h13l-5 20 23-31H90l8-13Z" fill="#ffe061"/>`;
        const fog = `<g stroke="#b7d5ed" stroke-width="5" stroke-linecap="round" opacity=".85"><path d="M31 94h91"/><path d="M46 109h89"/><path d="M28 124h75"/></g>`;
        let body = '';
        if (icon === 'clear')
            body = sun;
        else if (icon === 'night')
            body = moon;
        else if (icon === 'partly')
            body = `${sun}${cloud}`;
        else if (icon === 'partly-night')
            body = `${moon}${cloud}`;
        else if (icon === 'cloud')
            body = cloud;
        else if (icon === 'fog')
            body = `${cloud}${fog}`;
        else if (icon === 'drizzle')
            body = `${cloud}${drizzle}`;
        else if (icon === 'rain')
            body = `${cloud}${rain}`;
        else if (icon === 'snow')
            body = `${cloud}${snow}`;
        else if (icon === 'storm')
            body = `${cloud}${bolt}${rain}`;
        return `<svg class="weather-svg weather-svg-${icon}" viewBox="0 0 160 145" preserveAspectRatio="xMidYMid meet" aria-hidden="true">${defs}${body}</svg>`;
    }
    function normalizeLocation(result) {
        return {
            name: result.name || result.city || meteonexaText('location.current'),
            admin1: result.admin1 || result.principalSubdivision || '',
            country: result.country || result.countryName || '',
            countryCode: result.country_code || result.countryCode || '',
            latitude: Number(result.latitude),
            longitude: Number(result.longitude),
            timezone: result.timezone || 'auto',
            timezoneAbbreviation: result.timezone_abbreviation || result.timezoneAbbreviation || '',
            utcOffsetSeconds: Number.isFinite(Number(result.utc_offset_seconds ?? result.utcOffsetSeconds)) ? Number(result.utc_offset_seconds ?? result.utcOffsetSeconds) : undefined
        };
    }
    function createPreviewWeather() {
        const base = new Date();
        base.setMinutes(0, 0, 0);
        const hourly = {
            time: [], temperature_2m: [], apparent_temperature: [], precipitation_probability: [], precipitation: [],
            weather_code: [], relative_humidity_2m: [], visibility: [], wind_speed_10m: [], wind_direction_10m: [],
            wind_gusts_10m: [], dew_point_2m: [], surface_pressure: [], uv_index: [], cloud_cover: [], is_day: []
        };
        for (let i = 0; i < 120; i += 1) {
            const date = new Date(base.getTime() + i * 3600000);
            const hour = date.getHours();
            const dayWave = Math.sin(((hour - 7) / 24) * Math.PI * 2);
            const temp = 22 + dayWave * 5 + Math.sin(i / 9) * 1.2;
            const rainChance = Math.max(0, Math.round(18 + Math.sin(i / 5) * 16 + (i > 30 && i < 42 ? 35 : 0)));
            hourly.time.push(date.toISOString().slice(0, 19));
            hourly.temperature_2m.push(Number(temp.toFixed(1)));
            hourly.apparent_temperature.push(Number((temp + .8).toFixed(1)));
            hourly.precipitation_probability.push(clamp(rainChance, 0, 95));
            hourly.precipitation.push(rainChance > 55 ? Number((rainChance / 32).toFixed(1)) : 0);
            hourly.weather_code.push(rainChance > 65 ? 61 : rainChance > 36 ? 2 : hour > 20 || hour < 6 ? 0 : i % 9 < 4 ? 1 : 2);
            hourly.relative_humidity_2m.push(Math.round(56 + Math.sin(i / 8) * 14));
            hourly.visibility.push(18000 - rainChance * 55);
            hourly.wind_speed_10m.push(Math.round(10 + Math.sin(i / 6) * 5));
            hourly.wind_direction_10m.push((45 + i * 7) % 360);
            hourly.wind_gusts_10m.push(Math.round(18 + Math.abs(Math.sin(i / 5)) * 14));
            hourly.dew_point_2m.push(Number((temp - (100 - hourly.relative_humidity_2m[i]) / 5).toFixed(1)));
            hourly.surface_pressure.push(Math.round(1015 + Math.sin(i / 12) * 5));
            hourly.uv_index.push(hour >= 7 && hour <= 19 ? Math.max(0, 6 - Math.abs(13 - hour) * .8) : 0);
            hourly.cloud_cover.push(clamp(rainChance + 15, 10, 95));
            hourly.is_day.push(hour >= 6 && hour < 20 ? 1 : 0);
        }
        const daily = {
            time: [], weather_code: [], temperature_2m_max: [], temperature_2m_min: [], apparent_temperature_max: [],
            apparent_temperature_min: [], sunrise: [], sunset: [], daylight_duration: [], sunshine_duration: [],
            uv_index_max: [], precipitation_sum: [], precipitation_probability_max: [], wind_speed_10m_max: [], wind_gusts_10m_max: []
        };
        for (let i = 0; i < 10; i += 1) {
            const date = new Date(base);
            date.setDate(date.getDate() + i);
            const isoDate = date.toISOString().slice(0, 10);
            const max = 27 - i * .25 + Math.sin(i) * 1.4;
            const min = 18 - i * .18 + Math.cos(i) * .8;
            const rain = i === 3 ? 78 : i === 4 ? 58 : Math.round(18 + Math.abs(Math.sin(i * 1.7)) * 32);
            daily.time.push(isoDate);
            daily.weather_code.push(rain > 70 ? 61 : rain > 45 ? 2 : i % 4 === 0 ? 0 : 1);
            daily.temperature_2m_max.push(Number(max.toFixed(1)));
            daily.temperature_2m_min.push(Number(min.toFixed(1)));
            daily.apparent_temperature_max.push(Number((max + .8).toFixed(1)));
            daily.apparent_temperature_min.push(Number((min + .3).toFixed(1)));
            daily.sunrise.push(`${isoDate}T06:34`);
            daily.sunset.push(`${isoDate}T20:06`);
            daily.daylight_duration.push(48720 - i * 130);
            daily.sunshine_duration.push(32000 - rain * 150);
            daily.uv_index_max.push(Number((5.8 - i * .08).toFixed(1)));
            daily.precipitation_sum.push(rain > 55 ? Number((rain / 16).toFixed(1)) : Number((rain / 70).toFixed(1)));
            daily.precipitation_probability_max.push(rain);
            daily.wind_speed_10m_max.push(18 + i);
            daily.wind_gusts_10m_max.push(30 + i * 2);
        }
        return {
            latitude: state.location.latitude,
            longitude: state.location.longitude,
            timezone: state.location.timezone || 'Europe/Rome',
            current: {
                time: new Date().toISOString().slice(0, 19), temperature_2m: 22.4, relative_humidity_2m: 56,
                apparent_temperature: 22.1, is_day: 1, precipitation: 0, rain: 0, weather_code: 2,
                cloud_cover: 36, surface_pressure: 1015, wind_speed_10m: 12, wind_direction_10m: 45,
                wind_gusts_10m: 22
            },
            hourly,
            daily,
            fetchedAt: Date.now(),
            source: 'preview'
        };
    }
    function createPreviewAir() {
        const time = [];
        const european_aqi = [], pm10 = [], pm2_5 = [], ozone = [], nitrogen_dioxide = [];
        const base = new Date();
        base.setMinutes(0, 0, 0);
        for (let i = 0; i < 72; i += 1) {
            time.push(new Date(base.getTime() + i * 3600000).toISOString().slice(0, 19));
            european_aqi.push(Math.round(27 + Math.sin(i / 9) * 7));
            pm10.push(Number((18 + Math.sin(i / 7) * 4).toFixed(1)));
            pm2_5.push(Number((8 + Math.sin(i / 8) * 2).toFixed(1)));
            ozone.push(Number((54 + Math.sin(i / 5) * 8).toFixed(1)));
            nitrogen_dioxide.push(Number((15 + Math.sin(i / 6) * 4).toFixed(1)));
        }
        return { hourly: { time, european_aqi, pm10, pm2_5, ozone, nitrogen_dioxide }, fetchedAt: Date.now(), source: 'preview' };
    }
    return Object.freeze({
        escapeHTML,
        clamp,
        optionalFiniteNumber,
        sleep,
        debounce,
        capitalize,
        nowTime,
        isValidTimeZone,
        resolveLocationTimeZone,
        formatUtcOffsetSeconds,
        timeZoneOffsetLabel,
        formatLocationLocalTime,
        formatTimeZoneName,
        locationTimeZoneSummary,
        applyWeatherTimeZoneMetadata,
        refreshTimeZoneLabels,
        localizedLocationPart,
        fullLocationLabel,
        shortLocationLabel,
        locationKey,
        normalizeLocationIdentityPart,
        locationIdentity,
        sameLocation,
        uniqueLocations,
        formatDay,
        formatShortDate,
        formatClock,
        localDateHourKey,
        localSeriesIndex,
        windDirection,
        convertTemp,
        temperature,
        unitLabel,
        metricNoteHumidity,
        metricNotePressure,
        metricNoteVisibility,
        uvLabel,
        weatherMeta,
        weatherArt,
        normalizeLocation,
        createPreviewWeather,
        createPreviewAir
    });
}

export const weatherUtilsService = Object.freeze({ create });

export function installWeatherUtils(host = globalThis, services = host?.MeteoNexaServices) {
    if (!host || typeof host !== 'object') throw new Error('METEONEXA_WEATHER_UTILS_HOST_INVALID');
    const existing = services?.get?.('weatherUtils');
    if (existing) return existing;
    if (!services?.publish) throw new Error('METEONEXA_SERVICES_NOT_LOADED');
    return services.publish('weatherUtils', weatherUtilsService);
}
