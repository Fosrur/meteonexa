const PROVIDES = Object.freeze(['locations']);
export const dependencies = Object.freeze(['advanced', 'metrics']);

export function install(services, host = globalThis) {
    return services.installModule({
        services,
        host,
        provides: PROVIDES,
        dependencies,
        factory(window, deps, provided) {
        (() => {
            const create = deps => {
                const {
                    state, CONFIG, STORAGE, PREVIEW_MODE, $, $$, meteonexaText, escapeHTML,
                    normalizeLocation, fullLocationLabel, shortLocationLabel, locationTimeZoneSummary,
                    localizedLocationPart, resolveLocationTimeZone, saveJSON, fetchJSON, withLoader,
                    showToast, weatherMeta, weatherArt, temperature, uniqueLocations, sameLocation,
                    locationKey, confirmAction, scheduleAccountFavoritesPush, goToPage, loadWeather
                } = deps;
        
                function updateSelectedLocationUI() {
                    const label = fullLocationLabel(state.location);
                    $('#location-result').innerHTML = `<strong>${escapeHTML(label)}</strong><small>${escapeHTML(locationTimeZoneSummary(state.location))}</small>`;
                    $('#location-ok').classList.add('show');
                    $('#continue-to-app').disabled = false;
                }
        
                async function getCurrentLocationData() {
                    if (!navigator.geolocation)
                        throw new Error("" + meteonexaText("locations.geolocation_not_supported_by_browser"));
                    if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
                        throw new Error("" + meteonexaText("locations.gps_location_requires_https"));
                    }
                    const position = await new Promise((resolve, reject) => navigator.geolocation.getCurrentPosition(resolve, reject, {
                        enableHighAccuracy: true, timeout: 16000, maximumAge: 120000
                    }));
                    const { latitude, longitude } = position.coords;
                    let result = { name: meteonexaText('location.current'), admin1: '', country: '', latitude, longitude, timezone: 'auto' };
                    try {
                        const reverse = await fetchJSON(`${CONFIG.REVERSE_GEOCODING_API}?latitude=${latitude}&longitude=${longitude}&localityLanguage=${encodeURIComponent(state.settings.language || 'it')}`, { timeout: 9000, credentials: 'omit' });
                        result = {
                            name: reverse.city || reverse.locality || reverse.principalSubdivision || meteonexaText('location.current'),
                            admin1: reverse.principalSubdivision || '',
                            country: reverse.countryName || '', latitude, longitude, timezone: 'auto'
                        };
                    }
                    catch {
                    }
                    return normalizeLocation(result);
                }
                function locationErrorMessage(error) {
                    if (error?.code === 1)
                        return "" + meteonexaText("locations.location_permission_denied_enable_browser_settings_search_city");
                    if (error?.code === 2)
                        return "" + meteonexaText("locations.device_cannot_determine_location");
                    if (error?.code === 3)
                        return "" + meteonexaText("locations.gps_detection_took_too_long_try_again");
                    return "" + meteonexaText("locations.locationerrormessage.location_unavailable");
                }
                async function detectLocation() {
                    await withLoader("" + meteonexaText("locations.detectlocation.detecting_location"), "" + meteonexaText("locations.finding_location_device_gps"), async () => {
                        state.location = await getCurrentLocationData();
                        state.radar.center = { lat: Number(state.location.latitude), lon: Number(state.location.longitude) };
                        saveJSON(STORAGE.location, state.location);
                        updateSelectedLocationUI();
                        showToast("" + meteonexaText("locations.detectlocation.location_detected"), shortLocationLabel(state.location), 'success');
                    }, 700).catch(error => showToast("" + meteonexaText("locations.detectlocation.location_unavailable"), locationErrorMessage(error), 'error'));
                }
                async function useCurrentLocationFromSearch() {
                    const button = $('#global-use-location');
                    const status = $('#global-location-status');
                    button?.setAttribute('aria-busy', 'true');
                    button?.classList.add('loading');
                    if (status)
                        status.textContent = "" + meteonexaText("locations.usecurrentlocationfromsearch.detecting_gps_location");
                    try {
                        const locationData = await getCurrentLocationData();
                        if (status)
                            status.textContent = meteonexaText("locations.usecurrentlocationfromsearch.location_detected_value", { location: shortLocationLabel(locationData) });
                        if (state.searchMode === 'favorite') {
                            await handleSearchSelection(locationData);
                        }
                        else {
                            await setLocation(locationData, { openHome: true });
                        }
                    }
                    catch (error) {
                        const message = locationErrorMessage(error);
                        if (status)
                            status.textContent = message;
                        showToast("" + meteonexaText("locations.detectlocation.location_unavailable"), message, 'error');
                    }
                    finally {
                        button?.removeAttribute('aria-busy');
                        button?.classList.remove('loading');
                    }
                }
                async function searchCities(query, target, onSelect, { compact = false } = {}) {
                    const term = query.trim();
                    if (term.length < 2) {
                        target.innerHTML = '';
                        return;
                    }
                    target.innerHTML = compact ? "" + "<div class=\"search-loading\">" + escapeHTML(meteonexaText("locations.searchcities.searching")) + "</div>" : "" + "<div class=\"search-loading\">" + escapeHTML(meteonexaText("locations.searchcities.searching_locations")) + "</div>";
                    try {
                        const data = await fetchJSON(`${CONFIG.GEOCODING_API}?name=${encodeURIComponent(term)}&count=20&language=${encodeURIComponent(state.settings.language || 'it')}&format=json`, { timeout: 10000 });
                        const results = data.results || [];
                        if (!results.length) {
                            target.innerHTML = "" + "<div class=\"search-empty\">" + escapeHTML(meteonexaText("locations.searchcities.no_locations_found")) + "</div>";
                            return;
                        }
                        if (compact) {
                            target.innerHTML = results.slice(0, 6).map((result, index) => {
                                const locationData = normalizeLocation(result);
                                const place = [result.admin1, result.country].filter(Boolean).join(', ');
                                const timezone = locationTimeZoneSummary(locationData, null, { compact: true });
                                return `
                        <button class="floating-result" type="button" data-index="${index}">
                          <span><strong>${escapeHTML(result.name)}</strong><small>${escapeHTML(place)} · ${escapeHTML(timezone)}</small></span>
                          <span class="country-code">${escapeHTML((result.country_code || '').toUpperCase()) || '→'}</span>
                        </button>`;
                            }).join('');
                            $$('.floating-result', target).forEach(button => button.addEventListener('click', () => onSelect(results[Number(button.dataset.index)])));
                        }
                        else {
                            target.innerHTML = results.map((result, index) => {
                                const locationData = normalizeLocation(result);
                                const place = [result.admin1, result.country].filter(Boolean).join(', ');
                                const timezone = locationTimeZoneSummary(locationData, null, { compact: true });
                                return `
                        <button class="search-result-row" type="button" data-index="${index}">
                          <span><svg><use href="#i-location"/></svg></span>
                          <span><strong>${escapeHTML(result.name)}</strong><small>${escapeHTML(place)} · ${escapeHTML(timezone)}</small></span>
                          <span class="search-country-code">${escapeHTML((result.country_code || '').toUpperCase())}</span><svg><use href="#i-chevron"/></svg>
                        </button>`;
                            }).join('');
                            $$('.search-result-row', target).forEach(button => button.addEventListener('click', () => onSelect(results[Number(button.dataset.index)])));
                        }
                    }
                    catch (error) {
                        target.innerHTML = `<div class="search-empty">${escapeHTML(navigator.onLine ? meteonexaText("locations.search_service_temporarily_unavailable") : meteonexaText("locations.offline_search_requires_connection"))}</div>`;
                    }
                }
                function selectOnboardingLocation(result) {
                    state.location = normalizeLocation(result);
                    state.radar.center = { lat: state.location.latitude, lon: state.location.longitude };
                    saveJSON(STORAGE.location, state.location);
                    $('#onboarding-results').innerHTML = '';
                    $('#onboarding-city').value = '';
                    updateSelectedLocationUI();
                    deps.metrics?.track?.('search_location');
                    showToast("" + meteonexaText("locations.selectonboardinglocation.location_selected"), shortLocationLabel(state.location), 'success');
                }
        
                function syncFavoriteUI() {
                    const active = isFavorite();
                    const buttons = [$('#header-favorite'), $('#hero-favorite')].filter(Boolean);
                    buttons.forEach(button => {
                        button.classList.toggle('active', active);
                        button.setAttribute('aria-label', active ? "" + meteonexaText("locations.syncfavoriteui.remove_from_favorites") : "" + meteonexaText("locations.syncfavoriteui.add_favorites"));
                    });
                    $('#hero-favorite span').textContent = active ? "" + meteonexaText("locations.syncfavoriteui.favorites") : "" + meteonexaText("locations.syncfavoriteui.add_favorites");
                    $('#favorite-count').textContent = String(state.favorites.length);
                    const mobileFavoriteCount = $('#mobile-favorite-count');
                    if (mobileFavoriteCount) mobileFavoriteCount.textContent = String(state.favorites.length);
                }
                function isFavorite(locationData = state.location) {
                    const key = locationKey(locationData);
                    return state.favorites.some(item => locationKey(item) === key);
                }
                async function toggleCurrentFavorite() {
                    await withLoader(isFavorite() ? "" + meteonexaText("locations.togglecurrentfavorite.removing_favorite") : "" + meteonexaText("locations.togglecurrentfavorite.saving_favorite"), "" + meteonexaText("locations.togglecurrentfavorite.updating_saved_locations"), async () => {
                        const key = locationKey();
                        if (isFavorite()) {
                            state.favorites = state.favorites.filter(item => locationKey(item) !== key);
                            showToast("" + meteonexaText("locations.togglecurrentfavorite.favorite_removed"), meteonexaText("locations.value_no_longer_saved_locations", { location: state.location.name }), 'info');
                        }
                        else {
                            state.favorites = [...state.favorites, { ...state.location }];
                            showToast("" + meteonexaText("locations.togglecurrentfavorite.favorite_added"), meteonexaText("locations.value_has_been_saved", { location: state.location.name }), 'success');
                        }
                        saveJSON(STORAGE.favorites, state.favorites);
                        scheduleAccountFavoritesPush();
                        syncFavoriteUI();
                        const star = $('#header-favorite');
                        star?.classList.add('burst');
                        setTimeout(() => star?.classList.remove('burst'), 650);
                        if (state.currentPage === 'favorites')
                            await renderFavorites();
                    }, 380);
                }
                async function renderFavorites() {
                    const root = $('#favorites-grid');
                    syncFavoriteUI();
                    root.dataset.count = String(state.favorites.length);
                    root.classList.toggle('has-favorites', state.favorites.length > 0);
                    if (!state.favorites.length) {
                        root.innerHTML = "" + "<div class=\"empty-state\"><div><span class=\"empty-state-icon\"><svg><use href=\"#i-star\"/></svg></span><h2>" + escapeHTML(meteonexaText("locations.renderfavorites.no_favorite_locations")) + "</h2><p>" + escapeHTML(meteonexaText("locations.press_star_home_screen_search_city_add")) + "</p><button id=\"empty-add-favorite\" class=\"button primary-button\" type=\"button\"><svg><use href=\"#i-plus\"/></svg>" + escapeHTML(meteonexaText("locations.renderfavorites.add_location")) + "</button></div></div>";
                        $('#empty-add-favorite').addEventListener('click', () => openSearch('favorite'));
                        return;
                    }
                    root.innerHTML = state.favorites.map((locationData, index) => "" + "\n    <article class=\"favorite-card\" data-index=\"" + index + "\">\n      <div class=\"favorite-card-header\"><div><h2>" + escapeHTML(localizedLocationPart(locationData.name)) + "</h2><p>" + escapeHTML([locationData.admin1, locationData.country].filter(Boolean).map(localizedLocationPart).join(', ')) + "</p><small class=\"favorite-timezone\" data-timezone=\"" + escapeHTML(resolveLocationTimeZone(locationData, null)) + "\" data-offset=\"" + (Number.isFinite(Number(locationData.utcOffsetSeconds)) ? Number(locationData.utcOffsetSeconds) : '') + "\">" + escapeHTML(locationTimeZoneSummary(locationData, null)) + "</small></div><button class=\"remove-favorite\" data-remove=\"" + index + "\" type=\"button\" aria-label=\"" + escapeHTML(meteonexaText('locations.renderfavorites.remove_value', { location: locationData.name })) + "\"><svg><use href=\"#i-close\"/></svg></button></div>\n      <div class=\"favorite-weather\"><div><span class=\"favorite-temp\">--\u00B0</span><span class=\"favorite-condition\">" + escapeHTML(meteonexaText("locations.renderfavorites.updating")) + "</span></div><span class=\"favorite-art\">" + weatherArt(2, 1) + "</span></div>\n      <div class=\"favorite-footer\"><span>" + escapeHTML(meteonexaText("history.renderhistory.feels_like")) + " <b class=\"fav-feels\">--\u00B0</b></span><span>" + escapeHTML(meteonexaText("history.renderhistory.rain")) + " <b class=\"fav-rain\">--%</b></span></div>\n    </article>").join('');
                    $$('.favorite-card', root).forEach(card => card.addEventListener('click', event => {
                        if (event.target.closest('[data-remove]'))
                            return;
                        const selected = state.favorites[Number(card.dataset.index)];
                        setLocation(selected, { openHome: true });
                    }));
                    $$('[data-remove]', root).forEach(button => button.addEventListener('click', async (event) => {
                        event.stopPropagation();
                        const index = Number(button.dataset.remove);
                        const selected = state.favorites[index];
                        const confirmed = await confirmAction("" + meteonexaText("locations.renderfavorites.remove_favorite"), meteonexaText("locations.value_will_removed_from_saved_locations", { location: selected.name }), { confirmLabel: "" + meteonexaText("locations.renderfavorites.remove"), icon: '#i-star', kind: 'remove' });
                        if (!confirmed)
                            return;
                        await withLoader("" + meteonexaText("locations.renderfavorites.removing_location"), "" + meteonexaText("locations.renderfavorites.updating_favorites"), async () => {
                            state.favorites.splice(index, 1);
                            saveJSON(STORAGE.favorites, state.favorites);
                            scheduleAccountFavoritesPush();
                            await renderFavorites();
                            showToast("" + meteonexaText("locations.renderfavorites.location_removed"), selected.name, 'success');
                        }, 350);
                    }));
                    await Promise.all(state.favorites.map(async (locationData, index) => {
                        let weather = null;
                        try {
                            if (!PREVIEW_MODE && navigator.onLine) {
                                const params = new URLSearchParams({
                                    latitude: locationData.latitude, longitude: locationData.longitude,
                                    current: 'temperature_2m,apparent_temperature,weather_code,is_day',
                                    hourly: 'precipitation_probability', forecast_hours: '1', timezone: 'auto'
                                });
                                weather = await fetchJSON(`${CONFIG.WEATHER_API}?${params}`, { timeout: 8000 });
                            }
                        }
                        catch { }
                        if (!weather) {
                            const seed = Math.abs(Number(locationData.latitude) * 7 + Number(locationData.longitude) * 3);
                            weather = { current: { temperature_2m: 16 + seed % 13, apparent_temperature: 16 + seed % 12, weather_code: Math.round(seed) % 4, is_day: 1 }, hourly: { precipitation_probability: [Math.round(seed * 3) % 60] } };
                        }
                        if (weather?.timezone) {
                            locationData.timezone = weather.timezone;
                            locationData.timezoneAbbreviation = weather.timezone_abbreviation || locationData.timezoneAbbreviation || '';
                            if (Number.isFinite(Number(weather.utc_offset_seconds)))
                                locationData.utcOffsetSeconds = Number(weather.utc_offset_seconds);
                        }
                        const card = $(`.favorite-card[data-index="${index}"]`, root);
                        if (!card)
                            return;
                        const timezoneElement = $('.favorite-timezone', card);
                        if (timezoneElement) {
                            timezoneElement.dataset.timezone = resolveLocationTimeZone(locationData, weather);
                            timezoneElement.dataset.offset = Number.isFinite(Number(locationData.utcOffsetSeconds)) ? String(locationData.utcOffsetSeconds) : '';
                            timezoneElement.textContent = locationTimeZoneSummary(locationData, weather);
                        }
                        $('.favorite-temp', card).textContent = temperature(weather.current.temperature_2m);
                        $('.favorite-condition', card).textContent = weatherMeta(weather.current.weather_code, weather.current.is_day).label;
                        $('.favorite-art', card).innerHTML = weatherArt(weather.current.weather_code, weather.current.is_day);
                        $('.fav-feels', card).textContent = temperature(weather.current.apparent_temperature);
                        $('.fav-rain', card).textContent = `${Math.round(weather.hourly?.precipitation_probability?.[0] || 0)}%`;
                    }));
                    saveJSON(STORAGE.favorites, state.favorites);
                }
                function addRecent(locationData) {
                    state.recent = uniqueLocations([locationData, ...state.recent.filter(item => !sameLocation(item, locationData))]).slice(0, 6);
                    saveJSON(STORAGE.recent, state.recent);
                }
                async function setLocation(locationData, { openHome = true } = {}) {
                    await withLoader("" + meteonexaText("locations.setlocation.changing_location"), meteonexaText("locations.setlocation.loading_weather_value", { location: locationData.name }), async () => {
                        state.location = { ...locationData };
                        state.radar.center = { lat: Number(locationData.latitude), lon: Number(locationData.longitude) };
                        state.radar.loaded = false;
                        state.radar.liveFrames = [];
                        state.radar.forecastFrames = [];
                        state.radar.forecastGrid = [];
                        state.radar.frames = [];
                        state.radar.liveAvailable = false;
                        state.radar.forecastAvailable = false;
                        if (state.radar.timer) {
                            clearInterval(state.radar.timer);
                            state.radar.timer = null;
                        }
                        state.weather = null;
                        state.air = null;
                        state.officialAlerts = null;
                        state.officialAlertsFetchedAt = 0;
                        state.officialAlertsLocationKey = '';
                        state.officialAlertsRequest = null;
                        state.severeWeather.events = [];
                        state.severeWeather.fetchedAt = 0;
                        state.severeWeather.locationKey = '';
                        state.severeWeather.authoritative = false;
                        state.severeWeather.degraded = true;
                        state.history.data = null;
                        state.history.locationKey = '';
                        state.history.rangeKey = '';
                        state.intelligence.models = [];
                        state.intelligence.fetchedAt = 0;
                        state.intelligence.locationKey = '';
                        state.intelligence.previousSnapshot = null;
                        state.intelligence.currentSnapshot = null;
                        saveJSON(STORAGE.location, state.location);
                        saveJSON(STORAGE.weather, null);
                        saveJSON(STORAGE.air, null);
                        addRecent(state.location);
                        if ($('#search-dialog')?.open)
                            $('#search-dialog').close();
                        if (openHome)
                            await goToPage('home', { loader: false });
                        await loadWeather({ force: true, silent: true });
                        deps.advanced?.locationChanged?.();
                        showToast("" + meteonexaText("locations.setlocation.location_updated"), fullLocationLabel(state.location), 'success');
                    }, 700);
                }
                document.addEventListener('meteonexa:activate-saved-location', event => {
                    const locationData = event?.detail;
                    if (!locationData || !Number.isFinite(Number(locationData.latitude)) || !Number.isFinite(Number(locationData.longitude))) return;
                    setLocation(locationData, { openHome: false }).then(() => goToPage('intelligence', { loader: false })).catch(() => {});
                });
        
                function renderRecentSearches() {
                    const cleaned = uniqueLocations(state.recent).slice(0, 6);
                    if (cleaned.length !== state.recent.length) {
                        state.recent = cleaned;
                        saveJSON(STORAGE.recent, state.recent);
                    }
                }
                function openSearch(mode = 'switch') {
                    state.searchMode = mode;
                    $('#global-city-search').value = '';
                    $('#global-search-results').innerHTML = '';
                    const locationStatus = $('#global-location-status');
                    if (locationStatus)
                        locationStatus.textContent = '';
                    $('#search-dialog').showModal();
                    setTimeout(() => $('#global-city-search').focus(), 100);
                }
                async function handleSearchSelection(rawResult) {
                    const locationData = normalizeLocation(rawResult);
                    addRecent(locationData);
                    if (state.searchMode === 'favorite') {
                        await withLoader("" + meteonexaText("locations.handlesearchselection.location_added"), meteonexaText("locations.handlesearchselection.saving_value_favorites", { location: locationData.name }), async () => {
                            if (!isFavorite(locationData))
                                state.favorites.push(locationData);
                            saveJSON(STORAGE.favorites, state.favorites);
                            scheduleAccountFavoritesPush();
                            syncFavoriteUI();
                            $('#search-dialog').close();
                            await renderFavorites();
                            showToast("" + meteonexaText("locations.togglecurrentfavorite.favorite_added"), locationData.name, 'success');
                        }, 450);
                    }
                    else {
                        await setLocation(locationData, { openHome: true });
                    }
                    deps.metrics?.track?.('search_location');
                }
                function clearCommandSearch({ clearInput = false } = {}) {
                    const input = $('#command-city-search');
                    const results = $('#command-search-results');
                    if (clearInput && input)
                        input.value = '';
                    if (results)
                        results.innerHTML = '';
                }
                async function selectCommandLocation(rawResult) {
                    const locationData = normalizeLocation(rawResult);
                    clearCommandSearch({ clearInput: true });
                    await setLocation(locationData, { openHome: true });
                    deps.metrics?.track?.('search_location');
                }
                async function performCommandSearch() {
                    const input = $('#command-city-search');
                    const results = $('#command-search-results');
                    const query = input?.value.trim() || '';
                    if (query.length < 2) {
                        showToast("" + meteonexaText("locations.performcommandsearch.search_too_short"), "" + meteonexaText("locations.enter_at_least_two_characters"), 'warning');
                        input?.focus();
                        return;
                    }
                    await searchCities(query, results, selectCommandLocation, { compact: true });
                }
                async function performGlobalSearch() {
                    const query = $('#global-city-search').value.trim();
                    if (query.length < 2) {
                        showToast("" + meteonexaText("locations.performcommandsearch.search_too_short"), "" + meteonexaText("locations.enter_at_least_two_characters"), 'warning');
                        return;
                    }
                    await withLoader("" + meteonexaText("locations.performglobalsearch.location_search"), meteonexaText("locations.performglobalsearch.searching_value", { query: query }), async () => {
                        await searchCities(query, $('#global-search-results'), handleSearchSelection);
                    }, 350);
                }
                return Object.freeze({
                    updateSelectedLocationUI, getCurrentLocationData, locationErrorMessage, detectLocation,
                    useCurrentLocationFromSearch, searchCities, selectOnboardingLocation, syncFavoriteUI,
                    isFavorite, toggleCurrentFavorite, renderFavorites, addRecent, setLocation,
                    renderRecentSearches, openSearch, handleSearchSelection, clearCommandSearch,
                    selectCommandLocation, performCommandSearch, performGlobalSearch
                });
            };
            provided.locations = Object.freeze({ create });
        })();
        }
    });
}

export const serviceNames = PROVIDES;
