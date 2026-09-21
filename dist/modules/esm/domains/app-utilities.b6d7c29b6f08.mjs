export const serviceNames = Object.freeze(['appUtilities']);
export const dependencies = Object.freeze(['feedback']);

function factory(window, deps, provided) {
    provided.appUtilities = Object.freeze({
        create(context) {
            const {
                state, $, APP_BUILD, STORAGE, currentHourlyIndex, escapeHTML, isGuestSession,
                locationTimeZoneSummary, renderAlerts, saveJSON, shortLocationLabel, t, temperature, validEmail,
                weatherMeta, withLoader, showToast, meteonexaText
            } = context;
            if (!state || typeof $ !== 'function' || typeof t !== 'function') throw new Error('METEONEXA_APP_UTILITIES_CONTEXT_INVALID');

            async function copyTextRobust(text) {
                if (navigator.clipboard?.writeText && window.isSecureContext) {
                    await navigator.clipboard.writeText(text);
                    return;
                }
                const area = document.createElement('textarea');
                area.value = text;
                area.setAttribute('readonly', '');
                area.style.position = 'fixed';
                area.style.opacity = '0';
                document.body.appendChild(area);
                area.select();
                const ok = document.execCommand('copy');
                area.remove();
                if (!ok)
                    throw new Error("" + meteonexaText("app.copytextrobust.copy_unavailable"));
            }
            function currentSharePayload() {
                if (!state.weather?.current)
                    return null;
                const current = state.weather.current;
                const meta = weatherMeta(current.weather_code, current.is_day);
                const rainIndex = currentHourlyIndex(state.weather);
                const text = meteonexaText("app.currentsharepayload.value_value_value_feels_like_value_wind_value", { location: shortLocationLabel(), temperature: temperature(current.temperature_2m), condition: meta.label, feels: temperature(current.apparent_temperature), wind: Math.round(current.wind_speed_10m || 0), rain: Math.round(state.weather.hourly?.precipitation_probability?.[rainIndex] || 0), timezone: locationTimeZoneSummary() });
                const url = location.href.split('#')[0];
                return { title: `${meteonexaText("app.currentsharepayload.weather")}${meteonexaText("app.currentsharepayload.time")} · ${shortLocationLabel()}`, text, url, combined: `${text}
            ${url}` };
            }
            async function shareCurrentWeather() {
                const payload = currentSharePayload();
                if (!payload)
                    return;
                $('#share-preview').textContent = payload.text;
                $('#share-system').hidden = !navigator.share;
                const dialog = $('#share-dialog');
                if (!dialog.open)
                    dialog.showModal();
            }
            async function runSystemShare() {
                const payload = currentSharePayload();
                if (!payload)
                    return;
                try {
                    await navigator.share({ title: payload.title, text: payload.text, url: payload.url });
                    $('#share-dialog').close();
                }
                catch (error) {
                    if (error?.name !== 'AbortError')
                        showToast("" + meteonexaText("app.runsystemshare.sharing_unavailable"), "" + meteonexaText("app.runsystemshare.use_copy_whatsapp_email"), 'warning');
                }
            }
            async function copyCurrentShare() {
                const payload = currentSharePayload();
                if (!payload)
                    return;
                await withLoader("" + meteonexaText("app.copycurrentshare.copying_share_content"), "" + meteonexaText("app.copycurrentshare.preparing_text_link"), async () => {
                    try {
                        await copyTextRobust(payload.combined);
                        showToast("" + meteonexaText("app.copycurrentshare.weather_copied"), "" + meteonexaText("app.copycurrentshare.text_link_copied_clipboard"), 'success');
                        $('#share-dialog').close();
                    }
                    catch {
                        showToast("" + meteonexaText("app.copytextrobust.copy_unavailable"), "" + meteonexaText("app.copycurrentshare.select_displayed_text_manually"), 'warning');
                    }
                }, 240);
            }
            function openShareChannel(channel) {
                const payload = currentSharePayload();
                if (!payload)
                    return;
                const encoded = encodeURIComponent(payload.combined);
                const target = channel === 'whatsapp'
                    ? `https://wa.me/?text=${encoded}`
                    : `mailto:?subject=${encodeURIComponent(payload.title)}&body=${encoded}`;
                window.open(target, '_blank', 'noopener,noreferrer');
            }
            function updateThreshold(type, value) {
                state.thresholds[type] = Number(value);
                saveJSON(STORAGE.thresholds, state.thresholds);
                $('#rain-threshold-output').textContent = `${state.thresholds.rain}%`;
                $('#wind-threshold-output').textContent = `${state.thresholds.wind} km/h`;
                $('#heat-threshold-output').textContent = `${state.thresholds.heat}°C`;
                renderAlerts();
            }
            function bugReportDiagnostics() {
                const viewport = window.visualViewport;
                return {
                    build: APP_BUILD,
                    page: String(state.currentPage || 'home').slice(0, 40),
                    session: isGuestSession() ? 'guest' : 'authenticated',
                    language: String(state.settings?.language || document.documentElement.lang || 'it').slice(0, 12),
                    theme: String(state.settings?.theme || 'system').slice(0, 16),
                    online: navigator.onLine !== false,
                    standalone: Boolean(window.matchMedia?.('(display-mode: standalone)')?.matches || navigator.standalone === true),
                    viewport: `${Math.round(viewport?.width || window.innerWidth || 0)}x${Math.round(viewport?.height || window.innerHeight || 0)}`,
                    platform: String(navigator.userAgentData?.platform || navigator.platform || 'unknown').slice(0, 80),
                    touch: Number(navigator.maxTouchPoints || 0) > 0
                };
            }
            const BUG_MEDIA_LIMITS = Object.freeze({ maxFiles: 6, imageBytes: 8 * 1024 * 1024, videoBytes: 12 * 1024 * 1024, totalBytes: 15 * 1024 * 1024, recordSeconds: 30 });
            const BUG_MEDIA_TYPES = new Set(['image/jpeg','image/png','image/webp','video/mp4','video/webm','video/quicktime']);
            let bugReportMedia = [];
            let bugRecorder = null;
            let bugRecorderStream = null;
            let bugRecorderChunks = [];
            let bugRecorderTimer = null;
            let bugRecorderStartedAt = 0;
            let bugRecorderDiscard = false;
            let bugRecorderFinalizing = false;
            function refreshBugReportContext() {
                const form = $('#bug-report-form');
                if (!form) return;
                form.dataset.sessionMode = isGuestSession() ? 'guest' : 'authenticated';
            }
            function bugCategoryValue() { return String($('#bug-category')?.value || 'overview'); }
            function syncBugCategoryOther() {
                const other = bugCategoryValue() === 'other';
                const wrap = $('#bug-category-other-wrap');
                if (wrap) wrap.hidden = !other;
                if (!other && $('#bug-category-other')) $('#bug-category-other').value = '';
            }
            function bugMediaTotalBytes() { return bugReportMedia.reduce((sum, item) => sum + Number(item.file?.size || 0), 0); }
            function formatBugFileSize(bytes) {
                const value = Number(bytes || 0);
                if (value < 1024 * 1024) return `${Math.max(1, Math.round(value / 1024))} KB`;
                return `${(value / 1024 / 1024).toFixed(1)} MB`;
            }
            function renderBugReportMedia() {
                const root = $('#bug-attachment-list');
                if (!root) return;
                root.innerHTML = bugReportMedia.map((item, index) => `<article class="bug-attachment-item" data-bug-media-index="${index}"><span class="bug-attachment-thumb">${item.kind === 'image' ? `<img alt="" src="${escapeHTML(item.url)}">` : `<video muted playsinline preload="metadata" src="${escapeHTML(item.url)}"></video>`}</span><span class="bug-attachment-meta"><strong>${escapeHTML(item.file.name)}</strong><small>${escapeHTML(formatBugFileSize(item.file.size))} · ${escapeHTML(item.kind === 'image' ? meteonexaText('bug.media.image') : meteonexaText('bug.media.video'))}</small></span><button class="bug-attachment-remove" data-bug-media-remove="${index}" type="button" aria-label="${escapeHTML(meteonexaText('bug.media.remove'))}" title="${escapeHTML(meteonexaText('bug.media.remove'))}"><svg><use href="#i-trash"/></svg></button></article>`).join('');
            }
            function clearBugReportMedia() {
                bugReportMedia.forEach(item => { try { URL.revokeObjectURL(item.url); } catch {} });
                bugReportMedia = [];
                const input = $('#bug-attachments');
                if (input) input.value = '';
                renderBugReportMedia();
            }
            function addBugReportFiles(files) {
                const incoming = [...(files || [])].filter(Boolean);
                if (!incoming.length) return 0;
                let accepted = 0;
                for (const file of incoming) {
                    if (bugReportMedia.length >= BUG_MEDIA_LIMITS.maxFiles) {
                        showToast(t('bug.media.limit.title'), t('bug.media.limit.count', { count: BUG_MEDIA_LIMITS.maxFiles }), 'warning');
                        break;
                    }
                    const type = String(file.type || '').toLowerCase();
                    if (!BUG_MEDIA_TYPES.has(type)) {
                        showToast(t('bug.media.invalid.title'), t('bug.media.invalid.copy'), 'warning');
                        continue;
                    }
                    const kind = type.startsWith('image/') ? 'image' : 'video';
                    const perFile = kind === 'image' ? BUG_MEDIA_LIMITS.imageBytes : BUG_MEDIA_LIMITS.videoBytes;
                    if (Number(file.size || 0) < 1 || Number(file.size || 0) > perFile) {
                        showToast(t('bug.media.limit.title'), kind === 'image' ? t('bug.media.limit.image') : t('bug.media.limit.video'), 'warning');
                        continue;
                    }
                    if (bugMediaTotalBytes() + Number(file.size || 0) > BUG_MEDIA_LIMITS.totalBytes) {
                        showToast(t('bug.media.limit.title'), t('bug.media.limit.total'), 'warning');
                        continue;
                    }
                    bugReportMedia.push({ file, kind, url: URL.createObjectURL(file) });
                    accepted++;
                }
                if (accepted) {
                    renderBugReportMedia();
                    requestAnimationFrame(() => {
                        const root = $('#bug-attachment-list');
                        const latest = root?.lastElementChild;
                        if (latest && typeof latest.scrollIntoView === 'function') latest.scrollIntoView({ block:'nearest', behavior:'smooth' });
                    });
                }
                return accepted;
            }
            function stopBugRecorderTracks() {
                try { bugRecorderStream?.getTracks?.().forEach(track => track.stop()); } catch {}
                bugRecorderStream = null;
                const preview = $('#bug-recording-video');
                if (preview) preview.srcObject = null;
            }
            function setBugRecorderFinalizing(finalizing) {
                bugRecorderFinalizing = Boolean(finalizing);
                const stop = $('#bug-recording-stop');
                const cancel = $('#bug-recording-cancel');
                if (stop) { stop.disabled = bugRecorderFinalizing; stop.setAttribute('aria-busy', bugRecorderFinalizing ? 'true' : 'false'); }
                if (cancel) cancel.disabled = bugRecorderFinalizing;
            }
            function resetBugRecorderUI() {
                if (bugRecorderTimer) clearInterval(bugRecorderTimer);
                bugRecorderTimer = null;
                const panel = $('#bug-recording-panel');
                if (panel) panel.hidden = true;
                const timer = $('#bug-recording-timer');
                if (timer) timer.textContent = '00:00';
                stopBugRecorderTracks();
                bugRecorder = null;
                bugRecorderChunks = [];
                bugRecorderStartedAt = 0;
                setBugRecorderFinalizing(false);
            }
            async function startBugVideoRecording() {
                if (!navigator.mediaDevices?.getDisplayMedia || typeof MediaRecorder === 'undefined') {
                    showToast(t('bug.recording.unsupported.title'), t('bug.recording.unsupported.copy'), 'warning', 7000);
                    return;
                }
                if (bugReportMedia.length >= BUG_MEDIA_LIMITS.maxFiles) {
                    showToast(t('bug.media.limit.title'), t('bug.media.limit.count', { count: BUG_MEDIA_LIMITS.maxFiles }), 'warning');
                    return;
                }
                if (bugRecorder?.state === 'recording' || bugRecorderFinalizing) return;
                try {
                    bugRecorderDiscard = false;
                    setBugRecorderFinalizing(false);
                    const stream = await navigator.mediaDevices.getDisplayMedia({ video: { frameRate: { ideal: 15, max: 30 } }, audio: true });
                    bugRecorderStream = stream;
                    const preferred = ['video/webm;codecs=vp9,opus','video/webm;codecs=vp8,opus','video/webm','video/mp4'];
                    const mimeType = preferred.find(type => MediaRecorder.isTypeSupported?.(type)) || '';
                    const options = { videoBitsPerSecond: 1500000, audioBitsPerSecond: 96000 };
                    if (mimeType) options.mimeType = mimeType;
                    const recorder = new MediaRecorder(stream, options);
                    const chunks = [];
                    let recordedBytes = 0;
                    bugRecorder = recorder;
                    bugRecorderChunks = chunks;
                    recorder.addEventListener('dataavailable', event => {
                        if (!event.data?.size) return;
                        chunks.push(event.data);
                        recordedBytes += Number(event.data.size || 0);
                        const remainingTotal = Math.max(0, BUG_MEDIA_LIMITS.totalBytes - bugMediaTotalBytes());
                        const safeLimit = Math.min(BUG_MEDIA_LIMITS.videoBytes, remainingTotal);
                        if (safeLimit > 0 && recordedBytes >= Math.max(1024 * 1024, safeLimit - 512 * 1024) && recorder.state === 'recording' && !bugRecorderFinalizing)
                            stopBugVideoRecording({ discard:false });
                    });
                    recorder.addEventListener('stop', async () => {
                        // Some MediaRecorder implementations dispatch their last dataavailable
                        // task immediately around stop. Give that task one turn before sealing
                        // the attachment, while keeping the chunks in this recording's closure.
                        await new Promise(resolve => setTimeout(resolve, 80));
                        const discard = bugRecorderDiscard;
                        const chunkType = chunks.find(item => String(item?.type || '').startsWith('video/'))?.type || '';
                        const actualType = String(recorder.mimeType || chunkType || mimeType || 'video/webm').split(';')[0].toLowerCase();
                        const blob = discard ? null : new Blob(chunks.slice(), { type: actualType });
                        resetBugRecorderUI();
                        if (discard) return;
                        if (!blob?.size) {
                            showToast(t('bug.media.invalid.title'), t('bug.media.invalid.copy'), 'warning', 6200);
                            return;
                        }
                        const ext = actualType.includes('mp4') ? 'mp4' : actualType.includes('quicktime') ? 'mov' : 'webm';
                        const file = new File([blob], `meteonexa-bug-${Date.now()}.${ext}`, { type: actualType, lastModified: Date.now() });
                        addBugReportFiles([file]);
                    }, { once: true });
                    recorder.addEventListener('error', () => {
                        bugRecorderDiscard = true;
                        resetBugRecorderUI();
                        showToast(t('bug.recording.denied.title'), t('bug.recording.denied.copy'), 'warning', 6200);
                    }, { once: true });
                    const preview = $('#bug-recording-video');
                    if (preview) preview.srcObject = stream;
                    stream.getVideoTracks?.().forEach(track => track.addEventListener('ended', () => {
                        if (recorder === bugRecorder && recorder.state === 'recording') stopBugVideoRecording({ discard:false });
                    }, { once: true }));
                    const panel = $('#bug-recording-panel');
                    if (panel) panel.hidden = false;
                    bugRecorderStartedAt = Date.now();
                    recorder.start(500);
                    const syncTimer = () => {
                        const seconds = Math.min(BUG_MEDIA_LIMITS.recordSeconds, Math.floor((Date.now() - bugRecorderStartedAt) / 1000));
                        const node = $('#bug-recording-timer');
                        if (node) node.textContent = `00:${String(seconds).padStart(2,'0')}`;
                        if (seconds >= BUG_MEDIA_LIMITS.recordSeconds && recorder === bugRecorder && recorder.state === 'recording')
                            stopBugVideoRecording({ discard:false });
                    };
                    syncTimer();
                    bugRecorderTimer = setInterval(syncTimer, 500);
                } catch (error) {
                    resetBugRecorderUI();
                    showToast(t('bug.recording.denied.title'), t('bug.recording.denied.copy'), 'warning', 6200);
                }
            }
            function stopBugVideoRecording({ discard = false } = {}) {
                bugRecorderDiscard = Boolean(discard);
                const recorder = bugRecorder;
                if (!recorder || recorder.state !== 'recording') {
                    resetBugRecorderUI();
                    return;
                }
                if (bugRecorderFinalizing) return;
                setBugRecorderFinalizing(true);
                // Force a dataavailable chunk before stop. This is required for
                // browsers that otherwise finish the recording with no usable blob
                // when the user presses “Ferma e allega”.
                try { recorder.requestData?.(); } catch {}
                setTimeout(() => {
                    try { if (recorder.state === 'recording') recorder.stop(); }
                    catch { resetBugRecorderUI(); }
                }, 0);
            }
            async function submitBugReport(event) {
                event?.preventDefault?.();
                const category = bugCategoryValue();
                const categoryOther = String($('#bug-category-other')?.value || '').trim();
                const title = String($('#bug-title')?.value || '').trim();
                const description = String($('#bug-description')?.value || '').trim();
                const steps = String($('#bug-steps')?.value || '').trim();
                const contactEmail = String($('#bug-contact-email')?.value || '').trim();
                if (category === 'other' && categoryOther.length < 3) {
                    showToast(t('bug.validation.category'), t('bug.validation.category.copy'), 'warning');
                    $('#bug-category-other')?.focus();
                    return;
                }
                if (title.length < 5) {
                    showToast(t('bug.validation.title'), t('bug.validation.title.copy'), 'warning');
                    $('#bug-title')?.focus();
                    return;
                }
                if (description.length < 20) {
                    showToast(t('bug.validation.description'), t('bug.validation.description.copy'), 'warning');
                    $('#bug-description')?.focus();
                    return;
                }
                if (contactEmail && !validEmail(contactEmail)) {
                    showToast(t('bug.validation.email'), t('bug.validation.email.copy'), 'warning');
                    $('#bug-contact-email')?.focus();
                    return;
                }
                const submit = $('#bug-submit');
                if (submit?.disabled) return;
                submit.disabled = true;
                try { deps.feedback?.begin?.(); } catch { }
                try {
                    const payload = new FormData();
                    payload.append('category', category);
                    payload.append('categoryOther', categoryOther);
                    payload.append('title', title);
                    payload.append('description', description);
                    payload.append('steps', steps);
                    payload.append('contactEmail', contactEmail);
                    payload.append('website', String($('#bug-website')?.value || ''));
                    payload.append('language', String(state.settings?.language || document.documentElement.lang || 'it'));
                    if ($('#bug-include-diagnostics')?.checked) payload.append('diagnostics', JSON.stringify(bugReportDiagnostics()));
                    bugReportMedia.forEach(item => payload.append('attachments[]', item.file, item.file.name));
                    const result = await withLoader(t('bug.sending.title'), t('bug.sending.copy'), async () => {
                        const response = await fetch('api/feedback/bug-report.php', { method:'POST', body:payload, credentials:'same-origin', cache:'no-store', headers:{ 'Accept':'application/json' } });
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok || data?.ok === false) {
                            const key = String(data?.message || 'bug.error.send_failed');
                            const error = new Error(t(key));
                            error.code = data?.code || 'BUG_REPORT_FAILED';
                            throw error;
                        }
                        return data;
                    }, 320);
                    ['#bug-description','#bug-steps','#bug-title','#bug-category-other','#bug-website'].forEach(selector => { if ($(selector)) $(selector).value = ''; });
                    if ($('#bug-category')) $('#bug-category').value = 'overview';
                    syncBugCategoryOther();
                    clearBugReportMedia();
                    const reportId = String(result?.reportId || '').trim();
                    try { deps.feedback?.done?.(result); } catch { }
                    showToast(t('bug.success.title'), reportId ? t('bug.success.copy.id', { id: reportId }) : t('bug.success.copy'), 'success', 6200);
                }
                catch (error) {
                    try { deps.feedback?.fail?.(error); } catch { }
                    showToast(t('bug.error.title'), error?.message || t('bug.error.send_failed'), 'danger', 6500);
                }
                finally {
                    submit.disabled = false;
                }
            }

            function removeBugReportMedia(index) {
                const item = bugReportMedia[Number(index)];
                if (!item) return false;
                try { URL.revokeObjectURL(item.url); } catch {}
                bugReportMedia.splice(Number(index), 1);
                renderBugReportMedia();
                return true;
            }

            return Object.freeze({
                shareCurrentWeather, runSystemShare, copyCurrentShare, openShareChannel, updateThreshold,
                refreshBugReportContext, syncBugCategoryOther, clearBugReportMedia, addBugReportFiles,
                startBugVideoRecording, stopBugVideoRecording, submitBugReport, renderBugReportMedia,
                removeBugReportMedia
            });
        }
    });
}

export function install(services) {
    return services.installModule({ provides: serviceNames, dependencies, factory });
}
