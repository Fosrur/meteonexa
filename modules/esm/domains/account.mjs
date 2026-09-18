const PROVIDES = Object.freeze(['accountSync', 'accountDomain']);
export const dependencies = Object.freeze(['security']);

export function install(services, host = globalThis) {
    return services.installModule({
        services,
        host,
        provides: PROVIDES,
        dependencies,
        factory(window, deps, provided) {
        (() => {
            // The service registry validates every declared service synchronously.
            // accountDomain.create() runs later from app.js, so publish a stable facade
            // now and hydrate its implementation when the account controller is created.
            let accountSyncImpl = null;
            const accountSyncService = Object.freeze({
                refresh: options => accountSyncImpl?.refresh?.(options) ?? null,
                pushFavorites: () => accountSyncImpl?.pushFavorites?.() ?? null,
                scheduleFavorites: delay => accountSyncImpl?.scheduleFavorites?.(delay),
                getActivityProfiles: () => accountSyncImpl?.getActivityProfiles?.() ?? {},
                getActivityProfile: activity => accountSyncImpl?.getActivityProfile?.(activity) ?? null,
                saveActivityProfile: (...args) => {
                    if (!accountSyncImpl?.saveActivityProfile) {
                        return Promise.reject(new Error('METEONEXA_ACCOUNT_SYNC_NOT_READY'));
                    }
                    return accountSyncImpl.saveActivityProfile(...args);
                }
            });
            provided.accountSync = accountSyncService;

            function create(deps) {
                const { state, storage: STORAGE, saveJSON, loadJSON } = deps;
                const isGuestSession = (...args) => deps.isGuestSession(...args);
                const hasVerifiedServerSession = (...args) => deps.hasVerifiedServerSession(...args);
                const apiRequest = (...args) => deps.apiRequest(...args);
                const uniqueLocations = (...args) => deps.uniqueLocations(...args);
                const normalizeLocation = (...args) => deps.normalizeLocation(...args);
                const syncFavoriteUI = (...args) => deps.syncFavoriteUI(...args);
                const renderFavorites = (...args) => deps.renderFavorites(...args);
                const updateSelectedLocationUI = (...args) => deps.updateSelectedLocationUI(...args);
                const meteonexaText = (...args) => deps.meteonexaText(...args);
                // Account-scoped synchronization. The server remains authoritative
                // and every request still requires the device-bound HttpOnly session + proof.
                const accountSyncState = { loaded: false, loading: false, pushTimer: 0, activityProfiles: {} };
                function accountSyncDeviceId() { return deps.security?.deviceId || ''; }
                function accountSyncFavoriteRows() {
                    return (state.favorites || []).slice(0, 24).map(item => ({
                        name: String(item?.name || '').slice(0, 191), admin1: String(item?.admin1 || '').slice(0, 191), country: String(item?.country || '').slice(0, 120),
                        latitude: Number(item?.latitude), longitude: Number(item?.longitude), timezone: String(item?.timezone || 'auto').slice(0, 80),
                        utcOffsetSeconds: Number.isFinite(Number(item?.utcOffsetSeconds)) ? Number(item.utcOffsetSeconds) : null
                    })).filter(item => item.name && Number.isFinite(item.latitude) && Number.isFinite(item.longitude));
                }
                async function pushAccountFavorites() {
                    if (isGuestSession() || !hasVerifiedServerSession()) return null;
                    return apiRequest('api/account/sync.php', { deviceId: accountSyncDeviceId(), action: 'favorites', favorites: accountSyncFavoriteRows() }, { timeout: 8000, notifyAuthRequired: false });
                }
                function scheduleAccountFavoritesPush(delay = 420) {
                    if (isGuestSession() || !hasVerifiedServerSession()) return;
                    clearTimeout(accountSyncState.pushTimer);
                    accountSyncState.pushTimer = window.setTimeout(() => pushAccountFavorites().catch(error => console.warn('ACCOUNT_FAVORITES_SYNC_FAILED', error)), delay);
                }
                async function pullAccountSync({ force = false } = {}) {
                    if (isGuestSession() || !hasVerifiedServerSession() || accountSyncState.loading || (accountSyncState.loaded && !force)) return null;
                    accountSyncState.loading = true;
                    try {
                        const deviceId = accountSyncDeviceId();
                        const data = await apiRequest(`api/account/sync.php?deviceId=${encodeURIComponent(deviceId)}`, null, { timeout: 8000, notifyAuthRequired: false });
                        if (Array.isArray(data?.favorites)) {
                            state.favorites = uniqueLocations(data.favorites.map(item => normalizeLocation(item))).slice(0, 24);
                            saveJSON(STORAGE.favorites, state.favorites);
                            syncFavoriteUI();
                            if (state.currentPage === 'favorites') renderFavorites().catch(() => {});
                        } else if ((state.favorites || []).length) {
                            await pushAccountFavorites().catch(() => {});
                        }
                        // Hydrate the device mirror for alert workers; GET also migrates an older
                        // per-device profile to account scope on first access.
                        await apiRequest(`api/preferences/alerts.php?deviceId=${encodeURIComponent(deviceId)}`, null, { timeout: 6500, notifyAuthRequired: false }).catch(() => null);
                        accountSyncState.activityProfiles = data?.activityProfiles && typeof data.activityProfiles === 'object' ? data.activityProfiles : {};
                        accountSyncState.loaded = true;
                        document.dispatchEvent(new CustomEvent('meteonexa:account-sync', { detail: { activityProfiles: accountSyncState.activityProfiles, alerts: data?.alerts || null } }));
                        return data;
                    } finally { accountSyncState.loading = false; }
                }
                async function saveAccountActivityProfile(activity, thresholds) {
                    if (isGuestSession() || !hasVerifiedServerSession()) throw new Error(meteonexaText('api.security.auth_required'));
                    const data = await apiRequest('api/account/sync.php', { deviceId: accountSyncDeviceId(), action: 'activity-profile', activity, thresholds }, { timeout: 8000 });
                    accountSyncState.activityProfiles[activity] = data?.thresholds || thresholds;
                    document.dispatchEvent(new CustomEvent('meteonexa:account-sync', { detail: { activityProfiles: accountSyncState.activityProfiles } }));
                    return data?.thresholds || thresholds;
                }
                accountSyncImpl = Object.freeze({
                    refresh: options => pullAccountSync({ force: Boolean(options?.force) }),
                    pushFavorites: () => pushAccountFavorites(), scheduleFavorites: delay => scheduleAccountFavoritesPush(delay),
                    getActivityProfiles: () => ({ ...accountSyncState.activityProfiles }),
                    getActivityProfile: activity => accountSyncState.activityProfiles?.[activity] || null,
                    saveActivityProfile: saveAccountActivityProfile
                });
                async function hydrateAuthenticatedAccountAfterLogin({ adoptSavedLocation = true } = {}) {
                    if (!hasVerifiedServerSession() || isGuestSession()) return null;
                    accountSyncState.loaded = false;
                    const deviceId = accountSyncDeviceId();
                    const hadLocalLocation = Boolean(loadJSON(STORAGE.location, null));
                    let adoptedLocation = false;
                    const [syncData, locationsData] = await Promise.all([
                        pullAccountSync({ force: true }).catch(error => { console.warn('ACCOUNT_SYNC_LOGIN_PULL_FAILED', error); return null; }),
                        apiRequest(`api/locations/manage.php?deviceId=${encodeURIComponent(deviceId)}`, null, { timeout: 8000, notifyAuthRequired: false }).catch(error => { console.warn('ACCOUNT_LOCATIONS_LOGIN_PULL_FAILED', error); return null; })
                    ]);
                    if (adoptSavedLocation && !hadLocalLocation && Array.isArray(locationsData?.rows) && locationsData.rows.length) {
                        const preferred = [...locationsData.rows].sort((a,b) => {
                            const rank = value => ({home:0,work:1,family:2,second_home:3,custom:4})[String(value||'custom')] ?? 5;
                            return rank(a.role)-rank(b.role);
                        })[0];
                        if (preferred && Number.isFinite(Number(preferred.latitude)) && Number.isFinite(Number(preferred.longitude))) {
                            state.location = normalizeLocation({
                                name: preferred.location_name || preferred.name || preferred.label,
                                admin1: preferred.admin1 || '', latitude: Number(preferred.latitude), longitude: Number(preferred.longitude), timezone: preferred.timezone || 'auto'
                            });
                            saveJSON(STORAGE.location, state.location);
                            adoptedLocation = true;
                            updateSelectedLocationUI();
                        }
                    }
                    return { syncData, locationsData, hadLocalLocation, adoptedLocation };
                }
        
                return Object.freeze({
                    accountSyncDeviceId, pushAccountFavorites, scheduleAccountFavoritesPush,
                    pullAccountSync, saveAccountActivityProfile, hydrateAuthenticatedAccountAfterLogin
                });
            }
            provided.accountDomain = Object.freeze({ create });
        })();
        }
    });
}

export const serviceNames = PROVIDES;
