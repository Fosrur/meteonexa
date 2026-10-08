'use strict';
function bindEvents() {
    bindNotificationEvents();
    initializeUiTooltips();
    syncVisualViewportMetrics();
    window.visualViewport?.addEventListener('resize', syncVisualViewportMetrics);
    window.visualViewport?.addEventListener('scroll', syncVisualViewportMetrics);
    startLiveClocks();
    initializeThresholdsPanelFollow();
    const guestLoginButton = $('#guest-login');
    if (!guestLoginButton) throw new Error('METEONEXA_GUEST_LOGIN_BUTTON_MISSING');
    const activateGuestAccess = (button, touchGuard) => {
        if (touchGuard) armGuestMobileFocusGuard(2600);
        button?.blur?.();
        startLocalSession({ type: 'guest', name: "" + meteonexaText("app.updateprofileui.guest") }, { touchGuard }).catch(error => console.warn('GUEST_SESSION_START_FAILED', error));
    };
    guestLoginButton.addEventListener('pointerdown', event => {
        const touchGuard = event.pointerType === 'touch' || matchMedia('(pointer: coarse)').matches;
        if (!touchGuard) return;
        event.preventDefault();
        event.stopPropagation();
        guestTouchActivationArmed = true;
        armGuestMobileFocusGuard(2800);
    }, { passive: false });
    guestLoginButton.addEventListener('pointerup', event => {
        if (!guestTouchActivationArmed) return;
        event.preventDefault();
        event.stopPropagation();
        guestTouchActivationArmed = false;
        guestTouchActivationAt = Date.now();
        activateGuestAccess(event.currentTarget, true);
    }, { passive: false });
    guestLoginButton.addEventListener('pointercancel', () => { guestTouchActivationArmed = false; });
    guestLoginButton.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        if (Date.now() - guestTouchActivationAt < 900) return;
        activateGuestAccess(event.currentTarget, false);
    });
    document.addEventListener('focusin', event => {
        if (Date.now() >= mobileFocusGuardUntil) return;
        const target = event.target;
        if (!(target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target?.isContentEditable)) return;
        target.blur?.();
    }, true);
    $$('[data-auth]').forEach(button => button.addEventListener('click', () => button.dataset.auth === 'email' ? beginEmailAccessFlow() : openAuthDialog(button.dataset.auth)));
    $('#back-to-auth').addEventListener('click', () => setOnboardingView('auth-view'));
    $('#detect-location').addEventListener('click', detectLocation);
    $('#continue-to-app').addEventListener('click', async () => {
        if (isGuestSession()) {
            await showApp({ refresh: true, waitForWeather: false, forceWeather: true });
            return;
        }
        await withLoader("" + meteonexaText("app.activateguestaccess.preparing_dashboard"), "" + meteonexaText("app.activateguestaccess.preparing_weather_experience"), async () => await showApp({ refresh: true, waitForWeather: true, forceWeather: true }), 650);
    });
    $('#onboarding-search-btn').addEventListener('click', () => searchCities($('#onboarding-city').value, $('#onboarding-results'), selectOnboardingLocation, { compact: true }));
    $('#onboarding-city').addEventListener('input', debounce(event => searchCities(event.target.value, $('#onboarding-results'), selectOnboardingLocation, { compact: true }), 420));
    $('#onboarding-city').addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            $('#onboarding-search-btn').click();
        }
    });
    $('#auth-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!$('#auth-verify-step').hidden)
            await verifyEmailCode();
        else
            await requestEmailCode();
    });
    $('#auth-send-code').addEventListener('click', () => requestEmailCode());
    $('#auth-resend').addEventListener('click', () => requestEmailCode({ resend: true }));
    $('#auth-change-email').addEventListener('click', () => { stopAuthResendTimer(); setAuthStep('request'); setTimeout(() => $('#auth-primary').focus(), 80); });
    $('#auth-primary').addEventListener('input', () => clearFieldError('auth-primary'));
    $('#auth-code').addEventListener('input', event => { event.target.value = event.target.value.replace(/\D/g, '').slice(0, 6); clearFieldError('auth-code'); });
    $$('[data-dialog-close]').forEach(button => button.addEventListener('click', event => {
        event.preventDefault();
        const dialog = button.closest('dialog');
        if (dialog?.id === 'auth-dialog')
            stopAuthResendTimer();
        dialog?.close('cancel');
    }));
    document.addEventListener('click', event => {
        const pageButton = event.target.closest('button[data-page], [role="button"][data-page]');
        if (pageButton && !pageButton.disabled) {
            event.preventDefault();
            event.stopPropagation();
            if (!SERVICES.get('navigation')?.request?.(pageButton.dataset.page)) goToPage(pageButton.dataset.page);
        }
    });
    document.addEventListener('meteonexa:navigation-request', event => { const detail=event?.detail||{}; goToPage(detail.page, detail.options||{}).catch(error => SERVICES.get('navigation')?.failed?.(detail.page,error)); });
    $('#trust-brief-ai')?.addEventListener('click', () => {
        const prompt = t('trust.brief.ai_prompt');
        const display = t('trust.brief.ai_display');
        SERVICES.get('suite')?.openAssistantWithAi?.(prompt, display);
    });
    const confirmDialog = $('#confirm-dialog');
    const confirmForm = $('#confirm-form');
    const handleConfirmChoice = (event, confirmed) => {
        event.preventDefault();
        event.stopPropagation();
        settleConfirmDialog(confirmed);
    };
    $('#confirm-cancel')?.addEventListener('click', event => handleConfirmChoice(event, false), { capture: true, passive: false });
    $('#confirm-ok')?.addEventListener('click', event => handleConfirmChoice(event, true), { capture: true, passive: false });
    confirmForm?.addEventListener('submit', event => {
        event.preventDefault();
        settleConfirmDialog(true);
    });
    confirmDialog?.addEventListener('cancel', event => {
        event.preventDefault();
        settleConfirmDialog(false);
    });
    confirmDialog?.addEventListener('close', () => {
        if (pendingConfirmRequest)
            settleConfirmDialog(confirmDialog.returnValue === 'confirm', { closeDialog: false });
    });
    confirmDialog?.addEventListener('click', event => {
        if (event.target === confirmDialog)
            settleConfirmDialog(false);
    });
    $$('[data-action="logout"]').forEach(button => {
        button.addEventListener('click', event => {
            event.preventDefault();
            event.stopPropagation();
            logout({ returnToProfile: Boolean(button.closest('#profile-dialog')) });
        }, { passive: false });
    });
    $('#menu-button').addEventListener('click', toggleSidebarNavigation, { passive: false });
    $('#sidebar-close')?.addEventListener('click', () => {
        if (sidebarIsDrawer())
            setMobileSidebarOpen(false);
        else
            setSidebarCollapsed(true);
    });
    $('#sidebar-scrim').addEventListener('click', () => setMobileSidebarOpen(false));
    const closeProfileForAction = () => { const dialog = $('#profile-dialog'); if (dialog?.open) dialog.close(); };
    $('#refresh-button').addEventListener('click', async () => { closeProfileForAction(); await loadWeather({ force: true }); await SERVICES.get('advanced')?.afterRefresh?.(); });
    $('#share-button').addEventListener('click', () => { closeProfileForAction(); shareCurrentWeather(); });
    $('#share-system').addEventListener('click', runSystemShare);
    $('#share-copy').addEventListener('click', copyCurrentShare);
    $('#share-whatsapp').addEventListener('click', () => openShareChannel('whatsapp'));
    $('#share-email').addEventListener('click', () => openShareChannel('email'));
    $('#hour-detail-prev').addEventListener('click', () => stepHourDetail(-1));
    $('#hour-detail-next').addEventListener('click', () => stepHourDetail(1));
    $('#day-detail-prev').addEventListener('click', () => stepDayDetail(-1));
    $('#day-detail-next').addEventListener('click', () => stepDayDetail(1));
    $('#header-favorite').addEventListener('click', () => { closeProfileForAction(); toggleCurrentFavorite(); });
    $('#hero-favorite').addEventListener('click', toggleCurrentFavorite);
    $('#command-search')?.addEventListener('submit', event => {
        event.preventDefault();
        performCommandSearch();
    });
    $('#command-city-search')?.addEventListener('input', debounce(event => {
        const query = event.target.value.trim();
        if (query.length >= 2)
            searchCities(query, $('#command-search-results'), selectCommandLocation, { compact: true });
        else
            clearCommandSearch();
    }, 360));
    $('#command-city-search')?.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            clearCommandSearch({ clearInput: true });
            event.currentTarget.blur();
        }
    });
    $('#location-button').addEventListener('click', () => openSearch('switch'));
    $('#add-favorite-button').addEventListener('click', () => openSearch('favorite'));
    $('#global-search-button').addEventListener('click', performGlobalSearch);
    $('#global-use-location')?.addEventListener('click', useCurrentLocationFromSearch);
    $('#global-city-search').addEventListener('input', debounce(event => {
        if (event.target.value.trim().length >= 2)
            searchCities(event.target.value, $('#global-search-results'), handleSearchSelection);
        else
            $('#global-search-results').innerHTML = '';
    }, 420));
    $('#global-city-search').addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            performGlobalSearch();
        }
    });
    document.addEventListener('click', event => {
        if (!event.target.closest('.command-search-shell'))
            clearCommandSearch();
    });
    $('#privacy-center-button').addEventListener('click', openPrivacyCenter);
    $('#privacy-notice-details')?.addEventListener('click', openPrivacyCenter);
    $$('[data-privacy-link]').forEach(link => link.addEventListener('click', () => {
        link.setAttribute('href', privacyPageHref());
        link.removeAttribute('target');
    }));
    $('#privacy-notice-ok')?.addEventListener('click', acknowledgePrivacyNotice);
    $('#privacy-close')?.addEventListener('click', () => $('#privacy-dialog')?.close());
    $('#profile-button').addEventListener('click', () => {
        updateProfileUI();
        $('#profile-dialog').showModal();
    });
    $('#profile-settings-shortcut').addEventListener('click', () => {
        $('#profile-dialog').close();
        rebuildLanguageOptions();
        setSettingsLanguageMenu(false);
        syncDevicesSettingsButton();
        $('#settings-dialog').showModal();
    });
    $('#settings-cache-action')?.addEventListener('click', () => clearApplicationCache());
    $('#settings-devices-action')?.addEventListener('click', openDeviceAccessDialog);
    $('#devices-revoke-others')?.addEventListener('click', revokeOtherDeviceAccesses);
    $('#settings-integrations')?.addEventListener('click', () => {
        if (isGuestSession()) {
            showGuestAccessNotice();
            return;
        }
        $('#settings-dialog')?.close();
        goToPage('devices');
    });
    
    $('#temperature-unit').addEventListener('change', event => withLoader(t("app.closeprofileforaction.changing_unit"), t("app.closeprofileforaction.converting_all_temperatures"), async () => { state.settings.unit = event.target.value; applySettings(); }, 300));
    $('#welcome-language-button')?.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        const isOpen = $('#welcome-language-button')?.getAttribute('aria-expanded') === 'true';
        setWelcomeLanguageMenu(!isOpen, { focusActive: !isOpen });
    });
    $$('[data-language-option]').forEach(option => option.addEventListener('click', async (event) => {
        event.preventDefault();
        event.stopPropagation();
        const nextLanguage = option.dataset.languageOption;
        setWelcomeLanguageMenu(false);
        await changeApplicationLanguage(nextLanguage);
    }));
    $('#settings-language-button')?.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        const isOpen = $('#settings-language-button')?.getAttribute('aria-expanded') === 'true';
        setSettingsLanguageMenu(!isOpen, { focusActive: !isOpen });
    });
    $$('[data-settings-language-option]').forEach(option => option.addEventListener('click', async (event) => {
        event.preventDefault();
        event.stopPropagation();
        const nextLanguage = option.dataset.settingsLanguageOption;
        setSettingsLanguageMenu(false);
        await changeApplicationLanguage(nextLanguage);
    }));
    document.addEventListener('pointerdown', event => {
        if (!event.target.closest('#welcome-language-picker'))
            setWelcomeLanguageMenu(false);
        if (!event.target.closest('#settings-language-picker'))
            setSettingsLanguageMenu(false);
    });
    $('#welcome-language-menu')?.addEventListener('keydown', event => {
        const options = $$('[data-language-option]', event.currentTarget);
        const currentIndex = options.indexOf(document.activeElement);
        if (event.key === 'Escape') {
            event.preventDefault();
            setWelcomeLanguageMenu(false, { restoreFocus: true });
            return;
        }
        if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key))
            return;
        event.preventDefault();
        let nextIndex = currentIndex;
        if (event.key === 'Home')
            nextIndex = 0;
        else if (event.key === 'End')
            nextIndex = options.length - 1;
        else if (event.key === 'ArrowDown')
            nextIndex = (currentIndex + 1 + options.length) % options.length;
        else
            nextIndex = (currentIndex - 1 + options.length) % options.length;
        options[nextIndex]?.focus({ preventScroll: true });
    });
    $('#settings-language-menu')?.addEventListener('keydown', event => {
        const options = $$('[data-settings-language-option]', event.currentTarget);
        const currentIndex = options.indexOf(document.activeElement);
        if (event.key === 'Escape') {
            event.preventDefault();
            setSettingsLanguageMenu(false, { restoreFocus: true });
            return;
        }
        if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key))
            return;
        event.preventDefault();
        let nextIndex = currentIndex;
        if (event.key === 'Home')
            nextIndex = 0;
        else if (event.key === 'End')
            nextIndex = options.length - 1;
        else if (event.key === 'ArrowDown')
            nextIndex = (currentIndex + 1 + options.length) % options.length;
        else
            nextIndex = (currentIndex - 1 + options.length) % options.length;
        options[nextIndex]?.focus({ preventScroll: true });
    });
    $$('[data-language-setting]').forEach(select => select.addEventListener('change', event => changeApplicationLanguage(event.target.value)));
    $('#theme-setting').addEventListener('change', event => {
        const next = event.target.value;
        state.preferenceLocalMutationAt = Date.now();
        markPreferencesPending(true);
        applyThemePreference(next, { persist: true, notify: true });
        requestAnimationFrame(() => {
            if (state.weather) {
                renderAll();
                updateWeatherAtmosphere(true);
            }
        });
        void saveRemotePreferences({ showConfirmation: true }).then(() => {
            applyThemePreference(state.settings.theme, { persist: true, notify: false });
        });
    });
    $('#refresh-setting').addEventListener('change', event => withLoader(t("app.closeprofileforaction.automatic_refresh"), t("app.closeprofileforaction.saving_new_frequency"), async () => { state.settings.refresh = Number(event.target.value); applySettings({ rerender: false }); }, 300));
    $('#reduce-motion-setting').addEventListener('change', event => withLoader("" + meteonexaText("app.closeprofileforaction.animation_preferences"), "" + meteonexaText("app.closeprofileforaction.applying_new_mode"), async () => { state.settings.reduceMotion = event.target.checked; applySettings({ rerender: false }); updateWeatherAtmosphere(true); }, 300));
    $('#radar-city-search')?.addEventListener('input', debounce(event => {
        const query = event.target.value.trim();
        const target = $('#radar-city-results');
        if (query.length >= 3) {
            target.classList.add('open');
            searchCities(query, target, selectRadarLocation, { compact: true });
        }
        else {
            target.classList.remove('open');
            target.innerHTML = '';
        }
    }, 380));
    $('#radar-city-search')?.addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            performRadarCitySearch();
        }
    });
    $('#radar-city-gps')?.addEventListener('click', useGpsFromRadar);
    document.addEventListener('pointerdown', event => {
        const shell = event.target.closest('.radar-city-search-shell');
        if (!shell) {
            const results = $('#radar-city-results');
            results?.classList.remove('open');
        }
    });
    $('#radar-mode-setting').addEventListener('change', event => withLoader("" + meteonexaText("app.radar_layer"), "" + meteonexaText("app.setting_initial_map"), async () => {
        state.settings.radarMode = event.target.value;
        persistLocalSettings();
        if (state.radar.loaded)
            setRadarMode(event.target.value, { notify: false });
    }, 280));
    $('#radar-play').addEventListener('click', toggleRadarAnimation);
    $('#radar-prev').addEventListener('click', () => stepRadar(-1));
    $('#radar-next').addEventListener('click', () => stepRadar(1));
    $('#radar-refresh').addEventListener('click', () => ensureRadar(true));
    $('#radar-retry').addEventListener('click', () => ensureRadar(true));
    $$('[data-radar-mode]').forEach(button => button.addEventListener('click', () => setRadarMode(button.dataset.radarMode)));
    $('#radar-speed').addEventListener('change', event => {
        state.radar.speed = Number(event.target.value);
        if (state.radar.timer) {
            stopRadarAnimation();
            toggleRadarAnimation();
        }
    });
    $('#radar-slider').addEventListener('input', event => setRadarFrame(Number(event.target.value)));
    $('#radar-zoom-in').addEventListener('click', () => zoomRadar(1));
    $('#radar-zoom-out').addEventListener('click', () => zoomRadar(-1));
    $('#radar-recenter').addEventListener('click', () => withLoader("" + meteonexaText("app.recentering_radar"), "" + meteonexaText("app.returning_map_current_location"), async () => {
        state.radar.center = { lat: Number(state.location.latitude), lon: Number(state.location.longitude) };
        state.radar.zoom = state.radar.mode === 'live' ? 7 : 6;
        renderRadarMap();
    }, 280));
    $('#radar-opacity').addEventListener('input', event => {
        state.radar.opacity = Number(event.target.value) / 100;
        $('#radar-opacity-value').textContent = `${event.target.value}%`;
        $$('.radar-overlay-tile').forEach(tile => { tile.style.opacity = String(state.radar.opacity); });
        if (state.radar.vectorMapReady && state.radar.mode === 'live')
            syncRadarVectorLayer({ force: false });
        if (state.radar.mode === 'forecast')
            drawForecastRadarLayer();
    });
    $('#radar-fullscreen').addEventListener('click', async () => {
        const card = $('#radar-card');
        try {
            if (!document.fullscreenElement)
                await card.requestFullscreen();
            else
                await document.exitFullscreen();
            setTimeout(renderRadarMap, 220);
        }
        catch {
            showToast("" + meteonexaText("app.full_screen_unavailable"), "" + meteonexaText("app.browser_does_not_allow_mode"), 'warning');
        }
    });
    $('#bug-report-form')?.addEventListener('submit', submitBugReport);
    $('#bug-category')?.addEventListener('change', syncBugCategoryOther);
    $('#bug-file-choose')?.addEventListener('click', event => { event.stopPropagation(); $('#bug-attachments')?.click(); });
    $('#bug-record-video')?.addEventListener('click', event => { event.stopPropagation(); startBugVideoRecording(); });
    $('#bug-attachments')?.addEventListener('change', event => addBugReportFiles(event.target.files));
    $('#bug-dropzone')?.addEventListener('click', event => { if (!event.target.closest('button')) $('#bug-attachments')?.click(); });
    $('#bug-dropzone')?.addEventListener('keydown', event => { if ((event.key === 'Enter' || event.key === ' ') && !event.target.closest('button')) { event.preventDefault(); $('#bug-attachments')?.click(); } });
    ['dragenter','dragover'].forEach(name => $('#bug-dropzone')?.addEventListener(name, event => { event.preventDefault(); event.stopPropagation(); $('#bug-dropzone')?.classList.add('is-dragover'); }));
    ['dragleave','drop'].forEach(name => $('#bug-dropzone')?.addEventListener(name, event => { event.preventDefault(); event.stopPropagation(); $('#bug-dropzone')?.classList.remove('is-dragover'); if (name === 'drop') addBugReportFiles(event.dataTransfer?.files); }));
    $('#bug-attachment-list')?.addEventListener('click', event => { const button = event.target.closest('[data-bug-media-remove]'); if (!button) return; removeBugReportMedia(Number(button.dataset.bugMediaRemove)); });
    $('#bug-recording-stop')?.addEventListener('click', () => stopBugVideoRecording({ discard:false }));
    $('#bug-recording-cancel')?.addEventListener('click', () => stopBugVideoRecording({ discard:true }));
    $('#alerts-mark-all-read')?.addEventListener('click', markAllCurrentAlertsRead);
    $('#alerts-list')?.addEventListener('click', event => { const button = event.target.closest('[data-alert-mark-read]'); if (!button) return; const card = button.closest('[data-alert-id]'); markAlertRead(String(card?.dataset.alertId || '')); renderAlerts(); });
    syncBugCategoryOther();
    $('#reset-thresholds').addEventListener('click', () => withLoader("" + meteonexaText("app.resetting_thresholds"), "" + meteonexaText("app.recalculating_alert_center"), async () => {
        state.thresholds = { ...DEFAULT_THRESHOLDS };
        $('#threshold-rain').value = String(state.thresholds.rain);
        $('#threshold-wind').value = String(state.thresholds.wind);
        $('#threshold-heat').value = String(state.thresholds.heat);
        updateThreshold('rain', state.thresholds.rain);
        updateThreshold('wind', state.thresholds.wind);
        updateThreshold('heat', state.thresholds.heat);
        showToast("" + meteonexaText("app.thresholds_reset"), "" + meteonexaText("app.recommended_values_have_been_restored"), 'success');
    }, 320));
    $('#threshold-rain').addEventListener('input', event => updateThreshold('rain', event.target.value));
    $('#threshold-wind').addEventListener('input', event => updateThreshold('wind', event.target.value));
    $('#threshold-heat').addEventListener('input', event => updateThreshold('heat', event.target.value));
    ['threshold-rain', 'threshold-wind', 'threshold-heat'].forEach(id => $('#' + id).addEventListener('change', () => { showToast("" + meteonexaText("app.thresholds_updated"), "" + meteonexaText("app.alerts_have_been_recalculated"), 'success', 2200); evaluateLocalWeatherNotifications(); }));
    $('#history-form')?.addEventListener('submit', event => {
        event.preventDefault();
        state.history.start = $('#history-start')?.value || '';
        state.history.end = $('#history-end')?.value || '';
        loadHistory({ force: true });
    });
    $$('[data-history-days]').forEach(button => button.addEventListener('click', () => {
        setHistoryRange(Number(button.dataset.historyDays || 30));
        loadHistory({ force: true });
    }));
    $('#intelligence-refresh')?.addEventListener('click', () => withLoader(
        t("intelligence.task.updating_analysis"),
        t("intelligence.retrieving_forecasts_from_main_models"),
        async () => {
            await loadWeather({ force: true, silent: true });
            await loadIntelligence({ force: true, silent: true });
            await syncVerifiedModelAccuracy({ force: true });
        },
        520
    ));
    $('#impact-preference-chips')?.addEventListener('click', event => {
        const button = event.target.closest('[data-impact-preference]');
        if (!button) return;
        const id = String(button.dataset.impactPreference || '');
        if (!IMPACT_ACTIVITY_IDS.includes(id)) return;
        const next = new Set(personalWeatherPrefs.activities);
        if (next.has(id)) next.delete(id); else next.add(id);
        personalWeatherPrefs.activities = IMPACT_ACTIVITY_IDS.filter(activity => next.has(activity));
        renderPersonalImpact();
        savePersonalWeatherPreferences().catch(error => console.warn('IMPACT_PREFERENCE_SAVE_FAILED', error));
    });
    $('#briefing-enabled')?.addEventListener('change', event => {
        personalWeatherPrefs.briefingEnabled = event.target.checked === true;
        savePersonalWeatherPreferences({ notify: true }).then(() => {
            if (personalWeatherPrefs.briefingEnabled) maybeGenerateMorningBriefing();
        }).catch(() => {});
    });
    $('#briefing-hour')?.addEventListener('change', event => {
        personalWeatherPrefs.briefingHour = clamp(Number(event.target.value || currentLocationHour()), 0, 23);
        personalWeatherPrefs.briefingHourSet = true;
        savePersonalWeatherPreferences({ notify: true }).catch(() => {});
    });
    $('#briefing-generate')?.addEventListener('click', () => withLoader(t('briefing.action.generate'), t('assistant.thinking'), () => generateAiBriefing({ manual: true }), 480));
    $('#proactive-enabled')?.addEventListener('change', event => {
        personalWeatherPrefs.proactiveEnabled = event.target.checked === true;
        savePersonalWeatherPreferences({ notify: true }).then(() => {
            renderProactiveInsight();
            if (personalWeatherPrefs.proactiveEnabled) maybeGenerateProactiveInsight({ manual: true }).catch(() => {});
        }).catch(() => {});
    });
    $('#proactive-run')?.addEventListener('click', () => withLoader(t('proactive.action.run'), t('assistant.thinking'), () => maybeGenerateProactiveInsight({ manual: true, force: true }), 480));
    
    
    bindGlobalLifecycleEvents();
}
function initializeThresholds() {
    $('#threshold-rain').value = String(state.thresholds.rain);
    $('#threshold-wind').value = String(state.thresholds.wind);
    $('#threshold-heat').value = String(state.thresholds.heat);
    updateThreshold('rain', state.thresholds.rain);
    updateThreshold('wind', state.thresholds.wind);
    updateThreshold('heat', state.thresholds.heat);
    syncNotificationButton();
}
