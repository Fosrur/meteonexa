import { createAssistantUi } from './assistant-ui.mjs';
import { createAssistantLocal } from './assistant-local.mjs';

export const serviceNames = Object.freeze(['suiteAssistant']);
export const dependencies = Object.freeze(['ai', 'auth', 'copilot', 'guestAccess', 'intelligence', 'security']);

function factory(window, deps, provided) {
    provided.suiteAssistant = Object.freeze({
        create(context) {
            const {
                API, KEYS, addDays, apiMessage, calculateRouteCore, clamp, currentLocale, environmentPeaks,
                geocodeCity, isGuest, loadEnvironment, loader, localTime, locationLabel, modelConfidence, n, nearestTimeIndex,
                nowcastBase, nowcastReliability, q, qa, safe, snapshot, stabilityScore, state, suite, tempText, toast, ui, temperature, weatherMeta
            } = context;
            if (!state || !suite || typeof q !== 'function' || typeof ui !== 'function') throw new Error('METEONEXA_SUITE_ASSISTANT_CONTEXT_INVALID');

            const { normalizeQuestion, assistantPattern, assistantMatches, parseTargetTime, weatherAt, confidenceData, assistantConfidence, dailyBest, assistantLanguageCode, localAssistantText, assistantSocialText, assistantSuggestionText, assistantUpcomingWeather, assistantRainCode, assistantSuggestionRows, renderAssistantSuggestions, assistantSocialIntent, assistantIsFollowUp, assistantJoin, assistantAdviceFor, assistantRiskReasons, assistantCompactOverview, assistantOverview, assistantNextHours, diversifyLocalAssistant, assistantIntentFromText, localAssistantAnswer } = createAssistantLocal(context);
            function assistantAiContext() {
                const current = weatherAt(new Date());
                const daily = state.weather?.daily || {};
                const forecast = (daily.time || []).slice(0, 7).map((date, index) => ({
                    date,
                    condition: weatherMeta(n(daily.weather_code?.[index]), 1).label,
                    minC: n(daily.temperature_2m_min?.[index]),
                    maxC: n(daily.temperature_2m_max?.[index]),
                    rainProbability: n(daily.precipitation_probability_max?.[index]),
                    windGustKmh: n(daily.wind_gusts_10m_max?.[index])
                }));
                const currentSnapshot = snapshot();
                const previousSnapshot = suite.snapshotPrevious || (() => { try { return JSON.parse(localStorage.getItem(KEYS.snapshot) || 'null'); } catch { return null; } })();
                const nowcast = nowcastBase();
                const changes = currentSnapshot && previousSnapshot && currentSnapshot.key === previousSnapshot.key ? {
                    comparedAt: previousSnapshot.at ? new Date(previousSnapshot.at).toISOString() : null,
                    rainTimingShiftMinutes: currentSnapshot.firstRain && previousSnapshot.firstRain
                        ? Math.round((new Date(currentSnapshot.firstRain) - new Date(previousSnapshot.firstRain)) / 60000)
                        : currentSnapshot.firstRain === previousSnapshot.firstRain ? 0 : null,
                    rainAccumulationDeltaMm: Math.round((currentSnapshot.rain - previousSnapshot.rain) * 10) / 10,
                    maxGustDeltaKmh: Math.round(currentSnapshot.wind - previousSnapshot.wind),
                    maxTemperatureDeltaC: Math.round((currentSnapshot.maxTemp - previousSnapshot.maxTemp) * 10) / 10,
                    stabilityScore: stabilityScore()
                } : null;
                const hourlyForecast = assistantUpcomingWeather(24).slice(0, 24).map(row => ({
                    time: row.time || null,
                    condition: weatherMeta(row.code, 1).label,
                    temperatureC: Math.round(n(row.temp) * 10) / 10,
                    feelsLikeC: Math.round(n(row.feels) * 10) / 10,
                    rainProbability: Math.round(n(row.rain)),
                    precipitationMm: Math.round(n(row.mm) * 100) / 100,
                    windKmh: Math.round(n(row.wind)),
                    gustKmh: Math.round(n(row.gust)),
                    humidity: Math.round(n(row.humidity)),
                    pressureHpa: Math.round(n(row.pressure) * 10) / 10,
                    visibilityKm: Math.round(Math.max(0, n(row.visibility)) * 10) / 10,
                    uvIndex: Math.round(Math.max(0, n(row.uv)) * 10) / 10
                }));
                return {
                    generatedAt: new Date().toISOString(),
                    location: {
                        name: locationLabel(),
                        timezone: state.weather?.timezone || state.location?.timezone || ''
                    },
                    current: {
                        condition: weatherMeta(current.code, 1).label,
                        temperatureC: current.temp,
                        feelsLikeC: current.feels,
                        rainProbability: current.rain,
                        precipitationMm: current.mm,
                        windKmh: current.wind,
                        gustKmh: current.gust,
                        humidity: current.humidity,
                        pressureHpa: current.pressure,
                        visibilityKm: current.visibility,
                        uvIndex: current.uv
                    },
                    hourlyForecast,
                    forecast,
                    nowcast: nowcast ? {
                        rainingNow: Boolean(nowcast.raining),
                        expectedStart: nowcast.startAt ? new Date(nowcast.startAt).toISOString() : null,
                        expectedEnd: nowcast.endAt ? new Date(nowcast.endAt).toISOString() : null,
                        totalMm: Math.round(n(nowcast.total) * 10) / 10,
                        reliability: nowcastReliability()
                    } : null,
                    changes,
                    route: suite.route ? {
                        origin: suite.route.origin?.name || '',
                        destination: suite.route.destination?.name || '',
                        distanceKm: Math.round(n(suite.route.distanceKm)),
                        durationMinutes: Math.round(n(suite.route.durationHours) * 60),
                        riskScore: Math.round(n(suite.route.best?.score)),
                        recommendedDeparture: suite.route.best?.at || null
                    } : null,
                    radarPrediction: (() => {
                        const intelligence = deps.intelligence?.current?.();
                        const motion = intelligence?.radarMotion || {};
                        return motion.available ? {
                            rainingNow: Boolean(intelligence?.analysis?.nowcast?.rainingNow),
                            etaMinutes: motion.etaMinutes ?? null,
                            direction: motion.direction || '',
                            confidence: motion.confidence ?? null
                        } : null;
                    })(),
                    ensemble: (() => {
                        const intelligence = deps.intelligence?.current?.();
                        const accuracy = intelligence?.accuracy || {};
                        const first = intelligence?.analysis?.events?.[0] || {};
                        return {
                            calibrated: Number(accuracy.samples || 0) >= 12,
                            dominantModel: Object.entries(accuracy.weights || {}).sort((a,b) => Number(b[1]) - Number(a[1]))[0]?.[0] || '',
                            sampleCount: Number(accuracy.samples || 0),
                            precipitationTotal: first.type === 'rain' ? Number(first.peak15m || 0) : null,
                            windMax: first.type === 'wind' ? Number(first.maxWind || 0) : null
                        };
                    })(),
                    impact: (() => {
                        const intelligence = deps.intelligence?.current?.();
                        return (intelligence?.analysis?.events || []).slice(0, 6).map(event => ({
                            activity: String(event.type || 'weather'),
                            score: Number(event.confidence || 0),
                            reason: String(event.body || '').slice(0, 160)
                        }));
                    })(),
                    intelligenceQuality: (() => {
                        const intelligence = deps.intelligence?.current?.() || {};
                        const primary = intelligence?.consensus?.primary || {};
                        const previous = intelligence?.previousRuns?.summary || {};
                        const change = intelligence?.forecastChange || {};
                        const skill = intelligence?.modelSkill || {};
                        const cell = intelligence?.cellTracking || {};
                        const sources = intelligence?.sourceFreshness || {};
                        const explain = intelligence?.explainability || {};
                        return {
                            consensus: primary && Object.keys(primary).length ? {
                                type: String(primary.type || ''),
                                votes: Number(primary.votes || 0),
                                available: Number(primary.available || 0),
                                agreementPct: Number(primary.agreementPct || 0),
                                windowAgreementPct: Number(primary.windowAgreementPct || 0),
                                startsAt: primary.startsAt || null,
                                endsAt: primary.endsAt || null,
                                agreeingModels: Array.isArray(primary.agreeingModels) ? primary.agreeingModels.slice(0, 5) : [],
                                outliers: Array.isArray(primary.outliers) ? primary.outliers.slice(0, 5) : []
                            } : null,
                            historicalSkill: {
                                available: Boolean(skill.available),
                                verifiedSamples: Number(skill.verifiedSamples || 0),
                                brier: Number.isFinite(Number(skill.brier)) ? Number(skill.brier) : null,
                                leadHours: Array.isArray(skill.leadHours) ? skill.leadHours.slice(0, 6) : []
                            },
                            forecastChange: change?.available ? {
                                changed: Boolean(change.changed),
                                timeShiftMinutes: change.timeShiftMinutes ?? null,
                                rainProbabilityDelta: change.rainProbabilityDelta ?? null,
                                agreementDelta: change.agreementDelta ?? null,
                                stabilityPct: change.stabilityPct ?? null
                            } : null,
                            previousRuns: previous?.available ? {
                                changed: Boolean(previous.changed),
                                timeShiftMinutes: previous.timeShiftMinutes ?? null,
                                rainTotalDelta: previous.rainTotalDelta ?? null,
                                stormHoursDelta: previous.stormHoursDelta ?? null,
                                temperatureMaxDelta: previous.temperatureMaxDelta ?? null,
                                windGustMaxDelta: previous.windGustMaxDelta ?? null
                            } : null,
                            sourceFreshness: Object.entries(sources).slice(0, 10).map(([source, row]) => ({
                                source,
                                available: Boolean(row?.available),
                                ageMinutes: Number.isFinite(Number(row?.ageMinutes)) ? Number(row.ageMinutes) : null,
                                kind: String(row?.kind || row?.freshnessBasis || '').slice(0, 40)
                            })),
                            explainability: Array.isArray(explain?.parts) ? explain.parts.slice(0, 10).map(row => ({
                                source: String(row?.source || '').slice(0, 40),
                                contribution: Number(row?.contribution || 0),
                                status: String(row?.status || '').slice(0, 30)
                            })) : [],
                            decisionWindows: Array.isArray(intelligence?.decisionWindows) ? intelligence.decisionWindows.slice(0, 11).map(row => ({
                                activity: String(row?.activity || '').slice(0, 40),
                                score: Number(row?.score || 0),
                                startsAt: row?.startsAt || null,
                                endsAt: row?.endsAt || null,
                                basis: String(row?.basis || '').slice(0, 40)
                            })) : [],
                            cellTracking: cell?.available ? {
                                direction: String(cell.direction || '').slice(0, 10),
                                growthPct: cell.growthPct ?? null,
                                stage: String(cell.stage || '').slice(0, 20),
                                impactProbability: cell.impactProbability ?? null,
                                etaMinutes: cell.etaMinutes ?? null,
                                trackConfidence: cell.trackConfidence ?? null,
                                ageMinutes: cell.ageMinutes ?? null,
                                lightningFused: Boolean(cell.lightningFused)
                            } : null
                        };
                    })()
                };
            }
            function setAssistantProviderStatus(remote, provider = '', model = '') {
                const dialog = q('#assistant-dialog');
                if (dialog) {
                    dialog.dataset.provider = remote ? 'remote' : 'local';
                    dialog.dataset.assistantMode = remote ? 'ai' : 'local';
                    if (remote && provider) dialog.dataset.aiProvider = String(provider).slice(0, 40);
                    else delete dialog.dataset.aiProvider;
                    if (remote && model) dialog.dataset.aiModel = String(model).slice(0, 120);
                    else delete dialog.dataset.aiModel;
                }
                qa('[data-assistant-mode-choice]').forEach(button => button.setAttribute('aria-pressed', button.dataset.assistantModeChoice === (remote ? 'ai' : 'local') ? 'true' : 'false'));
            }
            function setAssistantMode(mode, announce = false) {
                const requested = mode === 'ai' ? 'ai' : 'local';
                const next = requested === 'ai' && isGuest() ? 'local' : requested;
                suite.assistantMode = next;
                localStorage.setItem(KEYS.assistantMode, next);
                setAssistantProviderStatus(next === 'ai', next === 'ai' ? ui('assistant.provider.pending') : 'local');
                if (announce) {
                    toast(
                        ui(next === 'ai' ? 'assistant.mode.ai.toast.title' : 'assistant.mode.local.toast.title'),
                        ui(next === 'ai' ? 'assistant.mode.ai.toast.copy' : 'assistant.mode.local.toast.copy'),
                        'success'
                    );
                }
            }
            function assistantCanAnswerLocally(question) {
                const text = normalizeQuestion(question);
                if (!text || assistantMatches(text, 'assistant.pattern.clear'))
                    return true;
                if (assistantSocialIntent(text))
                    return true;
                if (!state.weather)
                    return true;
                const parsed = parseTargetTime(question);
                let intent = assistantIntentFromText(text);
                const previousIntent = suite.assistantContext.intent;
                const contextualIntents = ['rain_end', 'umbrella', 'clothing', 'next_hours', 'route', 'drive', 'best_day', 'compare_periods', 'travel', 'sea', 'sport', 'outdoors', 'ice', 'snow', 'humidity', 'pressure', 'visibility', 'uv', 'wind', 'temperature', 'rain', 'forecast', 'overview'];
                if (intent === 'unknown' && previousIntent && contextualIntents.includes(previousIntent) && (parsed.explicit || assistantIsFollowUp(text)))
                    intent = previousIntent;
                else if (intent === 'unknown' && parsed.explicit && assistantMatches(text, 'assistant.pattern.forecast'))
                    intent = 'forecast';
                return intent !== 'unknown';
            }
            function assistantPlainText(value) {
                let text=String(value||'').trim();
                if(!text)return '';
                text=text.replace(/!\[([^\]]*)\]\([^)]*\)/g,'$1').replace(/\[([^\]]+)\]\([^)]*\)/g,'$1');
                text=text.replace(/(^|\n)\s{0,3}#{1,6}\s+/g,'$1').replace(/(^|\n)\s*>\s?/g,'$1').replace(/(^|\n)\s*[-+*]\s+/g,'$1');
                text=text.replace(/\*\*([^*]+)\*\*/g,'$1').replace(/__([^_]+)__/g,'$1').replace(/`{1,3}/g,'').replace(/\*\*/g,'').replace(/__/g,'');
                return text.replace(/[ \t]+/g,' ').replace(/\n{3,}/g,'\n\n').trim();
            }
            async function copilotRouteForQuestion(question) {
                if (suite.route) return suite.route;
                const text = normalizeQuestion(question);
                const intent = assistantIntentFromText(text);
                if (!['route', 'travel', 'drive'].includes(intent)) return null;
                const destinationMatch = String(question || '').match(assistantPattern('assistant.pattern.destination'));
                if (!destinationMatch?.[1]) return null;
                try {
                    const destination = await geocodeCity(destinationMatch[1].trim());
                    const origin = { name: locationLabel(), latitude: n(state.location.latitude), longitude: n(state.location.longitude) };
                    const parsed = parseTargetTime(question);
                    const departure = parsed.explicit ? parsed.target : new Date(Date.now() + 60 * 60 * 1000);
                    const mode = /moto|motorcycle|scooter/i.test(question) ? 'motorcycle' : /bici|bike|cycling/i.test(question) ? 'bike' : /trek|hiking|a piedi|walk/i.test(question) ? 'trekking' : 'car';
                    return await calculateRouteCore(origin, destination, departure, mode);
                } catch {
                    return null;
                }
            }
            async function remoteAssistantAnswer(question) {
                if (suite.aiAvailability === false)
                    return null;
                const history = assistantRows().slice(-14).map(row => ({ role: row.role === 'assistant' ? 'assistant' : 'user', content: assistantPlainText(row.text || '').slice(0, 1800) }));
                const route = await copilotRouteForQuestion(question);
                const routePlan = deps.copilot?.routePlan?.(route) || null;
                try { deps.ai?.begin?.('assistant'); } catch { }
                const response = await fetch(API.ai, {
                    method: 'POST',
                    cache: 'no-store',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', ...(deps.security?.headers?.() || {}) },
                    body: JSON.stringify({
                        message: String(question || '').slice(0, 1800),
                        messages: history,
                        context: assistantAiContext(),
                        language: state.settings?.language || document.documentElement.lang || 'it',
                        latitude: Number(state.location?.latitude),
                        longitude: Number(state.location?.longitude),
                        locationName: locationLabel(),
                        routePlan
                    })
                });
                const data = await response.json().catch(() => ({}));
                if (response.status === 503 && data?.code === 'AI_NOT_CONFIGURED') {
                    suite.aiAvailability = false;
                    return null;
                }
                if (!response.ok || data?.ok === false) {
                    if (response.status === 401 && data?.code === 'AUTH_REQUIRED')
                        deps.auth?.handleAuthRequired?.();
                    throw new Error(apiMessage(data, response.status));
                }
                const answer = assistantPlainText(data?.answer || '');
                if (!answer)
                    throw new Error(ui('api.ai.empty_response'));
                try { deps.ai?.complete?.({provider:data?.provider,model:data?.model,toolRun:data?.toolRun,generatedAt:data?.generatedAt}); } catch { }
                const provider = String(data?.provider || 'online');
                suite.aiAvailability = true;
                return { text: answer, source: 'ai', provider, model: String(data?.model || '').trim(), decision: deps.copilot?.normalizeDecision?.(data?.decision) || null, toolRun: data?.toolRun || null };
            }
            async function assistantLocalAnswer(question) {
                return { text: diversifyLocalAssistant(await localAssistantAnswer(question)), source: 'local', provider: 'local' };
            }
            function addAssistantAiSwitch(question) {
                const root = q('#assistant-messages');
                if (!root)
                    return null;
                suite.aiSwitchPending = String(question || '').trim();
                const article = document.createElement('article');
                article.className = 'assistant-message assistant assistant-switch';
                article.innerHTML = `<span class="assistant-avatar" aria-hidden="true"><svg><use href="#i-sun"></use></svg></span><div class="assistant-message-bubble"><div class="assistant-message-meta"><strong>${safe(ui('assistant.name'))}</strong></div><p>${safe(ui('assistant.ai.offer.copy'))}</p><div class="assistant-switch-actions"><button class="button primary-button" data-assistant-switch="ai" type="button"><svg aria-hidden="true"><use href="#i-spark"></use></svg><span>${safe(ui('assistant.ai.offer.accept'))}</span></button><button class="button outline-button" data-assistant-switch="local" type="button"><span>${safe(ui('assistant.ai.offer.decline'))}</span></button></div></div>`;
                root.append(article);
                root.scrollTop = root.scrollHeight;
                setAssistantBusy(true);
                return article;
            }
            async function answerWithAi(question) {
                const pending = addAssistant('assistant', ui('assistant.thinking'), false, { pending: true, source: 'ai' });
                setAssistantBusy(true);
                try {
                    const result = await loader(ui('assistant.ai.loader.title'), ui('assistant.ai.loader.copy'), () => remoteAssistantAnswer(question), 520);
                    pending?.remove();
                    if (!result) {
                        setAssistantProviderStatus(true, ui('assistant.provider.unavailable'));
                        addAssistant('assistant', ui('assistant.ai.unavailable.explicit'), true, { source: 'local' });
                        return;
                    }
                    setAssistantProviderStatus(true, result.provider || 'AI', result.model || '');
                    addAssistant('assistant', result.text, true, { source: 'ai', provider: result.provider || 'AI', model: result.model || '', decision: result.decision || null, toolRun: result.toolRun || null });
                }
                catch (error) {
                    pending?.remove();
                    console.warn('ASSISTANT_AI_ERROR', error);
                    setAssistantProviderStatus(true, ui('assistant.provider.unavailable'));
                    addAssistant('assistant', ui('assistant.ai.error.explicit', { message: error.message || ui('assistant.error.generic') }), false, { source: 'local' });
                }
                finally {
                    setAssistantBusy(false);
                    setTimeout(() => q('#assistant-input')?.focus(), 30);
                }
            }
            async function openAssistantWithAi(question, displayText = '') {
                if (isGuest()) {
                    deps.guestAccess?.notify?.();
                    return;
                }
                const value = String(question || '').trim();
                if (!value || suite.assistantBusy) return;
                openAssistantDialog();
                setAssistantMode('ai', false);
                addAssistant('user', String(displayText || value).trim());
                await answerWithAi(value);
            }
            const {
                openAssistantDialog, closeAssistantDialog, assistantRows, saveAssistantRows,
                setAssistantBusy, copilotEvidenceHtml, addAssistant, resetAssistant, restoreAssistant
            } = createAssistantUi({
                deps, KEYS, isGuest, localTime, q, qa, safe, state, suite, ui,
                assistantPlainText, renderAssistantSuggestions, setAssistantMode, setAssistantProviderStatus
            });
            async function askAssistant(question) {
                const guest = isGuest();
                if (guest && suite.assistantMode !== 'local') setAssistantMode('local', false);
                if (assistantMatches(normalizeQuestion(question), 'assistant.pattern.clear')) {
                    resetAssistant();
                    return;
                }
                if (suite.assistantBusy)
                    return;
                const value = String(question || '').trim();
                if (!value) {
                    toast(ui('assistant.empty.toast.title'), ui('assistant.empty.toast.copy'), 'warning');
                    q('#assistant-input')?.focus();
                    return;
                }
                addAssistant('user', value);
                if (!guest && suite.assistantMode === 'ai') {
                    await answerWithAi(value);
                    return;
                }
                if (!assistantCanAnswerLocally(value) && !guest) {
                    addAssistantAiSwitch(value);
                    return;
                }
                const pending = addAssistant('assistant', ui('assistant.analyzing'), false, { pending: true, source: 'local' });
                setAssistantBusy(true);
                try {
                    const result = await assistantLocalAnswer(value);
                    pending?.remove();
                    if (result?.text)
                        addAssistant('assistant', result.text, true, { source: 'local', provider: 'local' });
                }
                catch (error) {
                    pending?.remove();
                    addAssistant('assistant', ui('assistant.error', { message: error.message || ui('assistant.error.generic') }), false, { source: 'local' });
                }
                finally {
                    setAssistantBusy(false);
                    setTimeout(() => q('#assistant-input')?.focus(), 30);
                }
            }

            return Object.freeze({
                renderAssistantSuggestions, setAssistantProviderStatus, setAssistantMode, openAssistantWithAi,
                openAssistantDialog, closeAssistantDialog, assistantRows, setAssistantBusy, addAssistant,
                resetAssistant, restoreAssistant, askAssistant, answerWithAi
            });
        }
    });
}

export function install(services) {
    return services.installModule({ provides: serviceNames, dependencies, factory });
}
