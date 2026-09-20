export const serviceNames = Object.freeze(['deviceSessions']);
export const dependencies = Object.freeze([]);

function factory(window, deps, provided) {
    void deps;
    provided.deviceSessions = Object.freeze({
        create(context) {
            const {
                state, $, $$, STORAGE, SESSION_FLAGS, meteonexaText, appLocale, apiRequest, confirmAction,
                withLoader, showToast, showGuestAccessNotice, nextPaint, applyGuestAccessUI, updateProfileUI,
                showWelcome, reconcileEmailServerSession
            } = context;
            if (!state || typeof $ !== 'function' || typeof apiRequest !== 'function') {
                throw new Error('METEONEXA_DEVICE_SESSIONS_CONTEXT_INVALID');
            }
            function syncDevicesSettingsButton() {
                const button = $('#settings-devices-action');
                if (!button) return;
                const available = state.session?.type === 'email' && state.authServerVerified === true;
                button.hidden = !available;
                if (!available) {
                    const status = $('#settings-devices-status');
                    if (status) status.textContent = '';
                }
            }
            function formatAccessDateTime(timestamp) {
                const value = Number(timestamp || 0);
                if (!value) return meteonexaText('devices.not_available');
                try { return new Intl.DateTimeFormat(appLocale(), { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value * 1000)); }
                catch { return new Date(value * 1000).toLocaleString(); }
            }
            function deviceAccessLabel(row) {
                const parts = [row.deviceType, row.platform, row.browser].map(value => String(value || '').trim()).filter(Boolean);
                return [...new Set(parts)].join(' · ') || meteonexaText('devices.unknown_device');
            }
            function renderDeviceAccessList(data) {
                const list = $('#devices-access-list');
                const summary = $('#devices-access-summary');
                const allRows = Array.isArray(data?.devices) ? data.devices : [];
                const activeRows = Array.isArray(data?.activeDevices) ? data.activeDevices : allRows.filter(row => row.active);
                const historyRows = Array.isArray(data?.history) ? data.history : allRows.filter(row => !row.active);
                const activeCount = activeRows.length;
                const otherActive = activeRows.filter(row => !row.current).length;
                const retentionDays = Number(data?.retentionDays || 30);
                if (summary) {
                    summary.replaceChildren();
                    const a = document.createElement('span'); a.className = 'soft-badge'; a.textContent = meteonexaText('devices.active_count').replace('{count}', String(activeCount));
                    const b = document.createElement('span'); b.className = 'soft-badge'; b.textContent = meteonexaText('devices.history_retention').replace('{days}', String(retentionDays));
                    summary.append(a,b);
                }
                const revokeOthers = $('#devices-revoke-others');
                const revocableOthersCount = Number.isFinite(Number(data?.revocableOthersCount))
                    ? Math.max(0, Number(data.revocableOthersCount))
                    : otherActive;
                if (revokeOthers) {
                    revokeOthers.disabled = revocableOthersCount < 1;
                    revokeOthers.title = revocableOthersCount < 1 ? meteonexaText('devices.revoke_others_none') : '';
                    revokeOthers.setAttribute('aria-disabled', String(revocableOthersCount < 1));
                }
                const settingsStatus = $('#settings-devices-status');
                if (settingsStatus) settingsStatus.textContent = meteonexaText('devices.active_short').replace('{count}', String(activeCount));
                if (!list) return;
                list.replaceChildren();
            
                const buildCard = row => {
                    const card = document.createElement('article');
                    card.className = `device-access-card${row.current ? ' is-current' : ''}${row.active ? '' : ' is-ended'}`;
                    const head = document.createElement('div'); head.className = 'device-access-head';
                    const icon = document.createElement('span'); icon.className='device-access-icon'; icon.innerHTML='<svg aria-hidden="true"><use href="#i-phone"></use></svg>';
                    const title = document.createElement('span'); title.className='device-access-title';
                    const strong=document.createElement('strong'); strong.textContent=deviceAccessLabel(row);
                    const small=document.createElement('small');
                    const stateLabel = row.current ? meteonexaText('devices.current') : row.active ? meteonexaText('devices.active') : meteonexaText('devices.ended');
                    small.textContent = `${stateLabel}${row.clientMode === 'pwa' ? ' · PWA' : ''}`;
                    title.append(strong,small); head.append(icon,title); card.append(head);
                    const actions=document.createElement('div'); actions.className='device-access-actions';
                    if (row.active) {
                        const revoke=document.createElement('button'); revoke.type='button'; revoke.className='button outline-button'; revoke.textContent=row.current?meteonexaText('devices.disconnect_this'):meteonexaText('devices.disconnect');
                        revoke.addEventListener('click',()=>revokeDeviceAccess(row)); actions.append(revoke);
                    } else {
                        const remove=document.createElement('button'); remove.type='button'; remove.className='button outline-button device-history-delete'; remove.setAttribute('aria-label',meteonexaText('devices.history.delete')); remove.innerHTML='<svg aria-hidden="true"><use href="#i-trash"></use></svg><span>'+meteonexaText('devices.history.delete')+'</span>';
                        remove.addEventListener('click',()=>deletePastDeviceAccess(row)); actions.append(remove);
                    }
                    card.append(actions);
                    const meta=document.createElement('div'); meta.className='device-access-meta';
                    const items=[
                        [meteonexaText('devices.ip'), row.ip || meteonexaText('devices.not_available')],
                        [meteonexaText('devices.location'), row.location || meteonexaText('devices.location_unavailable')],
                        [meteonexaText('devices.login_at'), formatAccessDateTime(row.createdAt)],
                        [meteonexaText('devices.last_seen'), formatAccessDateTime(row.lastSeenAt)],
                        [meteonexaText('devices.timezone'), row.timezone || meteonexaText('devices.not_available')],
                        [meteonexaText('devices.status'), stateLabel],
                    ];
                    items.forEach(([label,value])=>{ const span=document.createElement('span'); const b=document.createElement('b'); b.textContent=label; span.append(b,document.createTextNode(String(value))); meta.append(span); });
                    card.append(meta);
                    return card;
                };
            
                if (!activeRows.length && !historyRows.length) {
                    const empty = document.createElement('div'); empty.className = 'devices-access-empty'; empty.textContent = meteonexaText('devices.empty'); list.append(empty); return;
                }
            
                const activeSection = document.createElement('section');
                activeSection.className = 'devices-access-section is-active';
                const activeTitle = document.createElement('h3');
                activeTitle.className = 'devices-access-section-title';
                activeTitle.textContent = meteonexaText('devices.active_count').replace('{count}', String(activeCount));
                activeSection.append(activeTitle);
                const activeGrid = document.createElement('div'); activeGrid.className = 'devices-access-section-grid';
                if (activeRows.length) activeRows.forEach(row => activeGrid.append(buildCard(row)));
                else {
                    const empty = document.createElement('div'); empty.className='devices-access-empty'; empty.textContent=meteonexaText('devices.active_count').replace('{count}','0'); activeGrid.append(empty);
                }
                activeSection.append(activeGrid);
                list.append(activeSection);
            
                if (historyRows.length) {
                    const details = document.createElement('details'); details.className='devices-history-disclosure';
                    const disclosureTitle = document.createElement('summary');
                    disclosureTitle.textContent = `${meteonexaText('devices.history_retention').replace('{days}', String(retentionDays))} · ${historyRows.length}`;
                    details.append(disclosureTitle);
                    const historyGrid = document.createElement('div'); historyGrid.className='devices-access-section-grid is-history';
                    historyRows.forEach(row => historyGrid.append(buildCard(row)));
                    details.append(historyGrid);
                    list.append(details);
                }
            }
            
            async function approximateDeviceLocationHeader({ prompt = true } = {}) {
                if (!navigator.geolocation || state.session?.type !== 'email' || state.authServerVerified !== true) return {};
                const cacheKey='meteonexa_approx_access_location_v1';
                try {
                    const cached=JSON.parse(sessionStorage.getItem(cacheKey)||'null');
                    if(cached?.value && Date.now()-Number(cached.at||0)<30*60*1000) return {'X-MeteoNexa-Approx-Location':String(cached.value)};
                } catch { }
                try {
                    if (!prompt && navigator.permissions?.query) {
                        const permission=await navigator.permissions.query({name:'geolocation'});
                        if(permission.state!=='granted') return {};
                    }
                } catch { }
                return new Promise(resolve=>{
                    navigator.geolocation.getCurrentPosition(position=>{
                        const lat=Number(position?.coords?.latitude),lon=Number(position?.coords?.longitude);
                        if(!Number.isFinite(lat)||!Number.isFinite(lon)){resolve({});return;}
                        const value=`${lat.toFixed(1)},${lon.toFixed(1)}`;
                        try{sessionStorage.setItem(cacheKey,JSON.stringify({value,at:Date.now()}));}catch{}
                        resolve({'X-MeteoNexa-Approx-Location':value});
                    },()=>resolve({}),{enableHighAccuracy:false,maximumAge:15*60*1000,timeout:5500});
                });
            }
            async function restoreDevicesDialogShell() {
                const dialog=$('#devices-access-dialog');
                if(!dialog||dialog.open)return;
                await nextPaint();
                try{dialog.showModal();}catch{}
            }
            async function loadDeviceAccessHistory({ locationHeaders = null } = {}) {
                const list = $('#devices-access-list');
                if (list) { list.replaceChildren(); const loading=document.createElement('div'); loading.className='devices-access-empty'; loading.textContent=meteonexaText('devices.loading'); list.append(loading); }
                try {
                    const data = await apiRequest('api/auth/devices.php', null, { timeout:8000, headers: locationHeaders || {} });
                    renderDeviceAccessList(data);
                } catch (error) {
                    if (list) { list.replaceChildren(); const empty=document.createElement('div'); empty.className='devices-access-empty'; empty.textContent=error.message || meteonexaText('devices.load_error'); list.append(empty); }
                }
            }
            async function openDeviceAccessDialog() {
                if (state.session?.type !== 'email' || state.authServerVerified !== true) { showGuestAccessNotice(); return; }
                $('#settings-dialog')?.close();
                const dialog=$('#devices-access-dialog');
                if (dialog && !dialog.open) dialog.showModal();
                const locationHeaders=await approximateDeviceLocationHeader({prompt:true});
                await loadDeviceAccessHistory({locationHeaders});
            }
            async function revokeDeviceAccess(row) {
                const devicesWasOpen=$('#devices-access-dialog')?.open===true;
                const confirmed = await confirmAction(meteonexaText('devices.disconnect_confirm_title'), meteonexaText(row.current ? 'devices.disconnect_current_confirm_copy' : 'devices.disconnect_confirm_copy'), { confirmLabel: meteonexaText('devices.disconnect'), kind:'danger', icon:'#i-logout' });
                if (devicesWasOpen) await restoreDevicesDialogShell();
                if (!confirmed) return;
                try {
                    const result = await withLoader(meteonexaText('devices.disconnecting'), meteonexaText('devices.disconnecting_copy'), ()=>apiRequest('api/auth/devices.php',{action:'revoke',accessId:Number(row.id)}), 300);
                    if (result.currentRevoked) {
                        $('#devices-access-dialog')?.close();
                        try { localStorage.removeItem(STORAGE.session); } catch { }
                        try { sessionStorage.setItem(SESSION_FLAGS.forceAuth, '1'); } catch { }
                        state.session=null; state.authServerVerified=false; state.diagnosticsAllowed=false;
                        try { applyGuestAccessUI(); } catch { }
                        try { updateProfileUI(); } catch { }
                        showWelcome('auth-view');
                        showToast(meteonexaText('devices.disconnected'), meteonexaText('devices.current_disconnected'), 'success');
                        return;
                    }
                    await loadDeviceAccessHistory();
                    showToast(meteonexaText('devices.disconnected'), meteonexaText('devices.disconnected_copy'), 'success');
                } catch(error) { showToast(meteonexaText('devices.error'), error.message, 'error'); }
            }
            async function deletePastDeviceAccess(row) {
                const devicesWasOpen=$('#devices-access-dialog')?.open===true;
                const confirmed=await confirmAction(meteonexaText('devices.history.delete_confirm_title'),meteonexaText('devices.history.delete_confirm_copy'),{confirmLabel:meteonexaText('devices.history.delete'),kind:'danger',icon:'#i-trash'});
                if(devicesWasOpen)await restoreDevicesDialogShell();
                if(!confirmed)return;
                try{
                    await withLoader(meteonexaText('devices.history.delete'),meteonexaText('devices.history.clear_working'),()=>apiRequest('api/auth/devices.php',{action:'deleteHistory',accessId:Number(row.id)}),220);
                    await loadDeviceAccessHistory();
                    showToast(meteonexaText('devices.history.deleted'),meteonexaText('devices.history.deleted_copy'),'success');
                }catch(error){showToast(meteonexaText('devices.error'),error.message,'error');}
            }
            
            async function revokeOtherDeviceAccesses() {
                const confirmed=await confirmAction(meteonexaText('devices.revoke_others_confirm_title'), meteonexaText('devices.revoke_others_confirm_copy'), {confirmLabel:meteonexaText('devices.revoke_others'),kind:'danger',icon:'#i-logout'});
                if(!confirmed)return;
                try { const result=await withLoader(meteonexaText('devices.disconnecting'),meteonexaText('devices.disconnecting_copy'),()=>apiRequest('api/auth/devices.php',{action:'revokeOthers'}),300); await loadDeviceAccessHistory(); showToast(meteonexaText('devices.disconnected'),meteonexaText('devices.revoke_others_done').replace('{count}',String(result.revoked||0)),'success'); }
                catch(error){showToast(meteonexaText('devices.error'),error.message,'error');}
            }
            let lastRemoteSessionCheckAt = 0;
            async function reconcileRemoteDeviceRevocation({ force = false } = {}) {
                if (state.session?.type !== 'email' || navigator.onLine === false) return true;
                const now = Date.now();
                if (!force && now - lastRemoteSessionCheckAt < 15000) return state.authServerVerified === true;
                lastRemoteSessionCheckAt = now;
                const hadEmailSession = state.session?.type === 'email';
                const valid = await reconcileEmailServerSession();
                if (!valid && hadEmailSession && state.session?.type !== 'email') {
                    try { sessionStorage.setItem(SESSION_FLAGS.forceAuth, '1'); } catch { }
                    try { applyGuestAccessUI(); } catch { }
                    try { updateProfileUI(); } catch { }
                    showWelcome('auth-view');
                    showToast(meteonexaText('devices.disconnected'), meteonexaText('devices.current_disconnected'), 'warning', 5200);
                    return false;
                }
                return valid;
            }
            return Object.freeze({ syncDevicesSettingsButton, formatAccessDateTime, deviceAccessLabel, renderDeviceAccessList, approximateDeviceLocationHeader, restoreDevicesDialogShell, loadDeviceAccessHistory, openDeviceAccessDialog, revokeDeviceAccess, deletePastDeviceAccess, revokeOtherDeviceAccesses, reconcileRemoteDeviceRevocation });
        }
    });
}

export function install(services) {
    return services.installModule({ provides: serviceNames, dependencies, factory });
}
