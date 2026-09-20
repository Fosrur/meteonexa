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

            function normalizeQuestion(value) { return String(value || '').toLocaleLowerCase(currentLocale()).normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
            function assistantPattern(key, flags = 'i') {
                const source = String(ui(key) || '');
                const decoded = source.replace(/\\\\/g, '\\');
                try { return new RegExp(decoded, flags); } catch { return /$a/; }
            }
            function assistantMatches(value, key) { return assistantPattern(key).test(value); }
            function parseTargetTime(text) {
                const now = new Date(), lower = normalizeQuestion(text);
                let day = 0;
                if (assistantMatches(lower, 'assistant.pattern.day_after_tomorrow'))
                    day = 2;
                else if (assistantMatches(lower, 'assistant.pattern.tomorrow'))
                    day = 1;
                const weekdays = [ui('weekday.sunday'), ui('weekday.monday'), ui('weekday.tuesday'), ui('weekday.wednesday'), ui('weekday.thursday'), ui('weekday.friday'), ui('weekday.saturday')].map(normalizeQuestion);
                weekdays.forEach((name, index) => {
                    if (name && lower.includes(name)) {
                        const delta = (index - now.getDay() + 7) % 7;
                        day = delta || 7;
                    }
                });
                const target = addDays(now, day);
                let hour = null, minute = 0;
                const match = lower.match(assistantPattern('assistant.pattern.time'));
                if (match) {
                    hour = clamp(Number(match[1]), 0, 23);
                    minute = Number(match[2] || 0);
                }
                else if (assistantMatches(lower, 'assistant.pattern.morning'))
                    hour = 9;
                else if (assistantMatches(lower, 'assistant.pattern.afternoon'))
                    hour = 15;
                else if (assistantMatches(lower, 'assistant.pattern.evening'))
                    hour = 19;
                else if (assistantMatches(lower, 'assistant.pattern.night'))
                    hour = 2;
                if (hour !== null)
                    target.setHours(hour, minute, 0, 0);
                return { target, explicit: hour !== null || day > 0, day };
            }
            function weatherAt(date) { const h = state.weather?.hourly || {}, index = nearestTimeIndex(h.time || [], date); return { index, time: h.time?.[index], temp: n(h.temperature_2m?.[index]), feels: n(h.apparent_temperature?.[index]), rain: n(h.precipitation_probability?.[index]), mm: n(h.precipitation?.[index]), wind: n(h.wind_speed_10m?.[index]), gust: n(h.wind_gusts_10m?.[index]), uv: n(h.uv_index?.[index]), humidity: n(h.relative_humidity_2m?.[index]), visibility: n(h.visibility?.[index]) / 1000, code: n(h.weather_code?.[index]), snow: n(h.snowfall?.[index]), pressure: n(h.surface_pressure?.[index]) }; }
            function confidenceData() { const score = Math.round((modelConfidence().score + nowcastReliability()) / 2); return { score, label: score >= 80 ? ui('assistant.confidence.high') : score >= 65 ? ui('assistant.confidence.good') : ui('assistant.confidence.variable') }; }
            function assistantConfidence() { const value = confidenceData(); return ui('assistant.confidence.phrase', { score: value.score, label: value.label }); }
            function dailyBest(metric = 'general') {
                const d = state.weather?.daily || {}, rows = (d.time || []).slice(0, 7).map((date, i) => {
                    const rain = n(d.precipitation_probability_max?.[i]), gust = n(d.wind_gusts_10m_max?.[i]), max = n(d.temperature_2m_max?.[i]), min = n(d.temperature_2m_min?.[i]);
                    let score = rain * .6 + gust * .45 + Math.max(0, max - 32) * 4 + Math.max(0, 4 - min) * 3;
                    if (metric === 'sea')
                        score += Math.abs(max - 28) * 1.8;
                    if (metric === 'sport')
                        score += Math.abs(max - 19) * 1.2;
                    return { date, i, rain, gust, max, min, score };
                });
                return rows.sort((a, b) => a.score - b.score)[0];
            }


            function assistantLanguageCode() {
                const value = String(state?.settings?.language || document.documentElement.lang || currentLocale() || 'it').toLowerCase();
                return ['it', 'en', 'fr', 'es', 'de'].find(code => value.startsWith(code)) || 'it';
            }
            function localAssistantText(key, params = {}) {
                return String(ui(`assistant.local.${key}`, params) || key).replace(/\s+/g, ' ').trim();
            }
            function assistantSocialText(key) {
                return String(ui(`assistant.social.${key}`) || '');
            }

            function assistantSuggestionText(key, params = {}) {
                return String(ui(`assistant.suggestion.${key}`, params) || key).replace(/\s+/g, ' ').trim();
            }
            function assistantUpcomingWeather(hours = 12) {
                const hourly = state.weather?.hourly || {}, times = hourly.time || [];
                if (!times.length)
                    return [];
                const start = Math.max(0, nearestTimeIndex(times, new Date()));
                return times.slice(start, start + Math.max(2, hours + 1)).map(time => weatherAt(new Date(time))).filter(row => row.time && Number.isFinite(row.temp));
            }
            function assistantRainCode(code) {
                return [51, 53, 55, 56, 57, 61, 63, 65, 66, 67, 80, 81, 82, 95, 96, 99].includes(Math.round(n(code)));
            }
            function assistantSuggestionRows() {
                if (!state.weather)
                    return ['nextHours', 'tomorrow', 'outdoors', 'drive'].map(key => ({ key, params: {} }));
                const current = weatherAt(new Date()), upcoming = assistantUpcomingWeather(18);
                const rows = upcoming.length ? upcoming : [current];
                const nowcast = nowcastBase();
                const previous = suite.snapshotPrevious || (() => { try { return JSON.parse(localStorage.getItem(KEYS.snapshot) || 'null'); } catch { return null; } })();
                const currentSnapshot = snapshot();
                const rainingNow = Boolean(nowcast?.raining) || current.mm >= .15 || assistantRainCode(current.code);
                const rainSoon = rows.slice(1).find(row => row.rain >= 45 || row.mm >= .2 || assistantRainCode(row.code));
                const windy = rows.reduce((best, row) => row.gust > best.gust ? row : best, rows[0]);
                const visibleRows = rows.filter(row => row.visibility > 0);
                const lowVisibility = visibleRows.length ? visibleRows.reduce((best, row) => row.visibility < best.visibility ? row : best, visibleRows[0]) : current;
                const hottest = rows.reduce((best, row) => row.feels > best.feels ? row : best, rows[0]);
                const coldest = rows.reduce((best, row) => row.feels < best.feels ? row : best, rows[0]);
                const activity = rows.find(row => row.rain < 25 && row.gust < 35 && row.temp > 4 && row.temp < 31 && row.visibility >= 5) || rows[0];
                const suggestions = [];
                const addRaw = (question, priority = 50) => {
                    const text = String(question || '').trim();
                    if (!text || suggestions.some(item => item.question === text)) return;
                    suggestions.push({ question: text, priority });
                };
                const add = (key, row = current, extra = {}, priority = 40) => addRaw(assistantSuggestionText(key, {
                    time: localTime(new Date(row?.time || Date.now())), rain: Math.round(n(row?.rain)), gust: Math.round(n(row?.gust)),
                    visibility: Math.max(0, n(row?.visibility)).toFixed(1), temp: tempText(n(row?.feels || row?.temp)), uv: Math.max(0, n(row?.uv)).toFixed(1), ...extra
                }), priority);

                // Changes since the previous forecast are the most useful follow-up prompts.
                if (previous && currentSnapshot && previous.key === currentSnapshot.key) {
                    if (previous.firstRain && currentSnapshot.firstRain) {
                        const shift = Math.round((new Date(currentSnapshot.firstRain) - new Date(previous.firstRain)) / 60000);
                        if (Math.abs(shift) >= 20)
                            addRaw(ui(shift < 0 ? 'assistant.suggestion.change_rain_earlier' : 'assistant.suggestion.change_rain_later', { minutes: Math.abs(shift), time: localTime(currentSnapshot.firstRain) }), 100);
                    } else if (!previous.firstRain && currentSnapshot.firstRain) {
                        addRaw(ui('assistant.suggestion.rain_new', { time: localTime(currentSnapshot.firstRain) }), 100);
                    } else if (previous.firstRain && !currentSnapshot.firstRain) {
                        addRaw(ui('assistant.suggestion.rain_removed'), 100);
                    }
                    const gustDelta = Math.round(currentSnapshot.wind - previous.wind);
                    if (Math.abs(gustDelta) >= 8)
                        addRaw(ui('assistant.suggestion.change_wind', { delta: Math.abs(gustDelta), direction: gustDelta > 0 ? ui('assistant.change.more') : ui('assistant.change.less') }), 92);
                }
                if (suite.route) add('route', current, {}, 95);
                if (rainingNow) {
                    addRaw(ui('assistant.suggestion.raining_now', { end: nowcast?.endAt ? localTime(nowcast.endAt) : ui('assistant.time.uncertain') }), 90);
                    add('umbrella', current, {}, 76); add('drive', current, {}, 72);
                } else if (rainSoon) {
                    addRaw(ui('assistant.suggestion.rain_specific', { time: localTime(rainSoon.time), rain: Math.round(rainSoon.rain) }), 88);
                    add('umbrella', rainSoon, {}, 74);
                }
                if (windy.gust >= 45)
                    addRaw(ui('assistant.suggestion.wind_specific', { time: localTime(windy.time), gust: Math.round(windy.gust) }), 84);
                if (lowVisibility.visibility > 0 && lowVisibility.visibility < 4)
                    addRaw(ui('assistant.suggestion.visibility_specific', { time: localTime(lowVisibility.time), visibility: lowVisibility.visibility.toFixed(1) }), 82);
                const feelsDelta = Math.round((hottest.feels - coldest.feels) * 10) / 10;
                if (feelsDelta >= 7)
                    addRaw(ui('assistant.suggestion.temperature_swing', { delta: feelsDelta.toFixed(0), low: tempText(coldest.feels), high: tempText(hottest.feels) }), 70);
                addRaw(ui('assistant.suggestion.best_window', { time: localTime(activity.time), rain: Math.round(activity.rain), gust: Math.round(activity.gust) }), 65);
                add('tomorrow', current, {}, 35);
                add('nextHours', current, {}, 30);
                return suggestions.sort((a, b) => b.priority - a.priority).slice(0, 4).map(item => ({ question: item.question }));
            }
            function renderAssistantSuggestions() {
                const root = q('#assistant-suggestions');
                if (!root)
                    return;
                const rows = assistantSuggestionRows();
                root.innerHTML = rows.map(({ key, params, question }) => {
                    const text = question || assistantSuggestionText(key, params);
                    return `<button data-assistant-question="${safe(text)}" type="button">${safe(text)}</button>`;
                }).join('');
            }
            function assistantSocialIntent(value) {
                const text = normalizeQuestion(value).replace(/[!?.,;:]+/g, ' ').replace(/\s+/g, ' ').trim();
                if (!text)
                    return 'clarification';
                if (assistantMatches(text, 'assistant.pattern.social_greeting'))
                    return 'greeting';
                if (assistantMatches(text, 'assistant.pattern.social_thanks'))
                    return 'thanks';
                if (assistantMatches(text, 'assistant.pattern.social_wellbeing'))
                    return 'wellbeing';
                if (assistantMatches(text, 'assistant.pattern.social_identity'))
                    return 'identity';
                if (assistantMatches(text, 'assistant.pattern.social_capabilities'))
                    return 'capabilities';
                if (assistantMatches(text, 'assistant.pattern.social_acknowledgement'))
                    return 'acknowledgement';
                if (assistantMatches(text, 'assistant.pattern.social_clarification'))
                    return 'clarification';
                return null;
            }
            function assistantIsFollowUp(value) {
                return assistantMatches(normalizeQuestion(value).trim(), 'assistant.pattern.follow_up');
            }
            function assistantJoin(items = []) {
                const values = items.filter(Boolean);
                if (!values.length)
                    return '';
                const language = assistantLanguageCode();
                const conjunction = ` ${ui('assistant.conjunction')} `;
                return values.length === 1 ? values[0] : `${values.slice(0, -1).join(', ')}${conjunction}${values.at(-1)}`;
            }
            function assistantAdviceFor(w) {
                const advice = [];
                const icy = w.temp <= 2 && (w.mm > .05 || w.snow > 0);
                if (w.rain >= 45 || w.mm >= .2)
                    advice.push(localAssistantText('adviceUmbrella'));
                if (w.gust >= 45)
                    advice.push(localAssistantText('adviceWind'));
                if (w.feels >= 31)
                    advice.push(localAssistantText('adviceHeat'));
                else if (w.feels <= 7)
                    advice.push(localAssistantText('adviceCold'));
                if (w.visibility > 0 && w.visibility < 4)
                    advice.push(localAssistantText('adviceVisibility'));
                if (w.uv >= 6)
                    advice.push(localAssistantText('adviceUv'));
                if (icy)
                    advice.push(localAssistantText('adviceIce'));
                const sentence = assistantJoin(advice.slice(0, 3));
                return sentence ? `${sentence.charAt(0).toUpperCase()}${sentence.slice(1)}.` : localAssistantText('adviceNone');
            }
            function assistantRiskReasons(w) {
                const reasons = [];
                if (w.rain >= 50 || w.mm >= .5)
                    reasons.push(localAssistantText('reasonRain'));
                if (w.gust >= 50)
                    reasons.push(localAssistantText('reasonWind'));
                if (w.visibility > 0 && w.visibility < 3)
                    reasons.push(localAssistantText('reasonVisibility'));
                if (w.temp <= 2 && (w.mm > .05 || w.snow > 0))
                    reasons.push(localAssistantText('reasonIce'));
                if (w.feels >= 34)
                    reasons.push(localAssistantText('reasonHeat'));
                return reasons;
            }
            function assistantCompactOverview(w) {
                return ui('assistant.compact_overview', {
                    condition: weatherMeta(w.code, 1).label,
                    temperature: tempText(w.temp),
                    rain: Math.round(w.rain),
                    rainLabel: ui('history.renderhistory.rain').toLocaleLowerCase(currentLocale()),
                    wind: Math.round(w.wind)
                });
            }
            function assistantOverview(target, w, confidence) {
                return localAssistantText('overview', {
                    location: locationLabel(), time: localTime(target), condition: weatherMeta(w.code, 1).label,
                    temperature: tempText(w.temp), feels: tempText(w.feels), rain: Math.round(w.rain), wind: Math.round(w.wind),
                    gust: Math.round(w.gust), visibility: Math.max(0, w.visibility).toFixed(1), uv: Math.max(0, w.uv).toFixed(1),
                    advice: assistantAdviceFor(w), confidence
                });
            }
            function assistantNextHours(hours, confidence) {
                const hourly = state.weather?.hourly || {}, times = hourly.time || [];
                if (!times.length)
                    return ui('assistant.answer.no_data');
                const start = Math.max(0, nearestTimeIndex(times, new Date()));
                const rows = times.slice(start, start + Math.max(2, hours + 1)).map((time, offset) => weatherAt(new Date(time))).filter(row => Number.isFinite(row.temp));
                if (!rows.length)
                    return ui('assistant.answer.no_data');
                const rainPeak = rows.reduce((best, row) => row.rain > best.rain ? row : best, rows[0]);
                const worst = rows.reduce((best, row) => assistantRiskReasons(row).length > assistantRiskReasons(best).length || row.rain + row.gust > best.rain + best.gust ? row : best, rows[0]);
                return localAssistantText('nextHours', {
                    hours, min: tempText(Math.min(...rows.map(row => row.temp))), max: tempText(Math.max(...rows.map(row => row.temp))),
                    rain: Math.round(rainPeak.rain), rainTime: localTime(new Date(rainPeak.time || Date.now())), gust: Math.round(Math.max(...rows.map(row => row.gust))),
                    advice: assistantAdviceFor(worst), confidence
                });
            }
            function diversifyLocalAssistant(answer) {
                const base = String(answer || '').replace(/\s+/g, ' ').trim();
                if (!base)
                    return base;
                const context = suite.assistantContext;
                if (context.lastLocalBase === base)
                    context.localRepeatCount = n(context.localRepeatCount) + 1;
                else {
                    context.lastLocalBase = base;
                    context.localRepeatCount = 0;
                }
                if (!context.localRepeatCount)
                    return base;

                const rows = assistantUpcomingWeather(12);
                if (!rows.length)
                    return base;
                const variant = (context.localRepeatCount - 1) % 3;
                let detail = '';
                if (variant === 0) {
                    const next = rows.slice(0, 4);
                    detail = localAssistantText('repeatNext3', {
                        min: tempText(Math.min(...next.map(row => row.temp))),
                        max: tempText(Math.max(...next.map(row => row.temp))),
                        rain: Math.round(Math.max(...next.map(row => row.rain))),
                        gust: Math.round(Math.max(...next.map(row => row.gust)))
                    });
                }
                else if (variant === 1) {
                    const peak = rows.reduce((worst, row) => {
                        const score = n(row.rain) * .75 + n(row.gust) * .55 + (row.visibility > 0 && row.visibility < 4 ? 30 : 0);
                        const worstScore = n(worst.rain) * .75 + n(worst.gust) * .55 + (worst.visibility > 0 && worst.visibility < 4 ? 30 : 0);
                        return score > worstScore ? row : worst;
                    }, rows[0]);
                    detail = localAssistantText('repeatPeak', {
                        time: localTime(peak.time),
                        condition: weatherMeta(peak.code, 1).label,
                        rain: Math.round(peak.rain),
                        gust: Math.round(peak.gust)
                    });
                }
                else {
                    const best = rows.reduce((winner, row) => {
                        const score = n(row.rain) * .7 + n(row.gust) * .5 + Math.abs(n(row.temp) - 20) * 1.5;
                        const winnerScore = n(winner.rain) * .7 + n(winner.gust) * .5 + Math.abs(n(winner.temp) - 20) * 1.5;
                        return score < winnerScore ? row : winner;
                    }, rows[0]);
                    detail = localAssistantText('repeatBest', {
                        time: localTime(best.time),
                        rain: Math.round(best.rain),
                        gust: Math.round(best.gust),
                        temperature: tempText(best.temp)
                    });
                }
                return detail ? `${base} ${detail}` : base;
            }

            function assistantIntentFromText(text) {
                const extra = {
                    umbrella: assistantPattern('assistant.pattern.intent_umbrella'),
                    clothing: assistantPattern('assistant.pattern.intent_clothing'),
                    drive: assistantPattern('assistant.pattern.intent_drive'),
                    outdoors: assistantPattern('assistant.pattern.intent_outdoors'),
                    nextHours: assistantPattern('assistant.pattern.intent_next_hours'),
                    route: assistantPattern('assistant.pattern.intent_route'),
                    advice: assistantPattern('assistant.pattern.intent_advice')
                };
                if (assistantMatches(text, 'assistant.pattern.rain_end')) return 'rain_end';
                if (extra.umbrella.test(text)) return 'umbrella';
                if (extra.clothing.test(text)) return 'clothing';
                if (extra.nextHours.test(text)) return 'next_hours';
                if (extra.route.test(text)) return 'route';
                if (extra.drive.test(text)) return 'drive';
                if (assistantMatches(text, 'assistant.pattern.best_day')) return 'best_day';
                if (assistantMatches(text, 'assistant.pattern.compare_periods')) return 'compare_periods';
                if (assistantMatches(text, 'assistant.pattern.travel')) return 'travel';
                if (assistantMatches(text, 'assistant.pattern.sea')) return 'sea';
                if (assistantMatches(text, 'assistant.pattern.sport')) return 'sport';
                if (extra.outdoors.test(text)) return 'outdoors';
                if (assistantMatches(text, 'assistant.pattern.ice')) return 'ice';
                if (assistantMatches(text, 'assistant.pattern.snow')) return 'snow';
                if (assistantMatches(text, 'assistant.pattern.humidity')) return 'humidity';
                if (assistantMatches(text, 'assistant.pattern.pressure')) return 'pressure';
                if (assistantMatches(text, 'assistant.pattern.visibility')) return 'visibility';
                if (assistantMatches(text, 'assistant.pattern.uv')) return 'uv';
                if (assistantMatches(text, 'assistant.pattern.wind')) return 'wind';
                if (assistantMatches(text, 'assistant.pattern.temperature')) return 'temperature';
                if (assistantMatches(text, 'assistant.pattern.rain')) return 'rain';
                if (extra.advice.test(text)) return 'overview';
                if (assistantMatches(text, 'assistant.pattern.forecast')) return 'forecast';
                return 'unknown';
            }
            async function localAssistantAnswer(question) {
                const text = normalizeQuestion(question);
                if (assistantMatches(text, 'assistant.pattern.clear')) {
                    resetAssistant();
                    return '';
                }
                const socialIntent = assistantSocialIntent(text);
                if (socialIntent) {
                    suite.assistantContext.lastSocialIntent = socialIntent;
                    return assistantSocialText(socialIntent);
                }
                if (!state.weather)
                    return ui('assistant.answer.no_data');
                const parsed = parseTargetTime(question);
                const previousIntent = suite.assistantContext.intent;
                const target = parsed.explicit ? parsed.target : (suite.assistantContext.target ? new Date(suite.assistantContext.target) : new Date());
                const w = weatherAt(target), now = nowcastBase(), confidence = assistantConfidence();
                let intent = assistantIntentFromText(text);
                const contextualIntents = ['rain_end', 'umbrella', 'clothing', 'next_hours', 'route', 'drive', 'best_day', 'compare_periods', 'travel', 'sea', 'sport', 'outdoors', 'ice', 'snow', 'humidity', 'pressure', 'visibility', 'uv', 'wind', 'temperature', 'rain', 'forecast', 'overview'];
                if (intent === 'unknown' && previousIntent && contextualIntents.includes(previousIntent) && (parsed.explicit || assistantIsFollowUp(text)))
                    intent = previousIntent;
                else if (intent === 'unknown' && parsed.explicit && assistantMatches(text, 'assistant.pattern.forecast'))
                    intent = 'forecast';
                if (intent === 'unknown')
                    return assistantSocialText('unknown');
                suite.assistantContext.intent = intent;
                suite.assistantContext.target = target.toISOString();
                suite.assistantContext.lastQuestion = String(question || '').trim();

                if (intent === 'rain_end')
                    return now?.raining ? (now.endAt ? ui('assistant.answer.rain_end', { time: localTime(now.endAt), confidence }) : ui('assistant.answer.rain_continues', { confidence })) : ui('assistant.answer.no_rain_now', { confidence });
                if (intent === 'umbrella') {
                    const key = w.rain >= 45 || w.mm >= .2 ? 'umbrellaYes' : w.rain >= 20 ? 'umbrellaMaybe' : 'umbrellaNo';
                    return localAssistantText(key, { time: localTime(target), rain: Math.round(w.rain), mm: Math.max(0, w.mm).toFixed(1), confidence });
                }
                if (intent === 'clothing') {
                    const clothing = w.feels >= 29 ? 'clothesHot' : w.feels >= 19 ? 'clothesMild' : w.feels >= 9 ? 'clothesCool' : 'clothesCold';
                    const extra = `${w.rain >= 40 ? localAssistantText('extraUmbrella') : ''}${w.gust >= 45 ? localAssistantText('extraWind') : ''}`;
                    return localAssistantText('clothing', { time: localTime(target), temperature: tempText(w.temp), feels: tempText(w.feels), clothing: localAssistantText(clothing), extra, confidence });
                }
                if (intent === 'next_hours')
                    return assistantNextHours(6, confidence);
                if (intent === 'route') {
                    if (!suite.route)
                        return localAssistantText('routeMissing');
                    const risk = Math.round(n(suite.route.best?.score));
                    const hours = Math.floor(n(suite.route.durationHours)), minutes = Math.round(n(suite.route.durationHours) % 1 * 60);
                    const routeWeatherRow = suite.route.best?.rows?.reduce((worst, row) => n(row.score) > n(worst?.score) ? row : worst, null) || w;
                    return localAssistantText('route', {
                        origin: suite.route.origin?.name || '', destination: suite.route.destination?.name || '', distance: Math.round(n(suite.route.distanceKm)),
                        duration: ui('route.duration.value', { hours, minutes }), risk, departure: localTime(suite.route.best?.at || suite.route.departure || target),
                        advice: assistantAdviceFor(routeWeatherRow), confidence
                    });
                }
                if (intent === 'drive') {
                    const reasons = assistantRiskReasons(w).filter(reason => reason !== localAssistantText('reasonHeat'));
                    return localAssistantText(reasons.length ? 'driveRisk' : 'driveGood', { time: localTime(target), reasons: assistantJoin(reasons), visibility: Math.max(0, w.visibility).toFixed(1), rain: Math.round(w.rain), gust: Math.round(w.gust), confidence });
                }
                if (intent === 'outdoors') {
                    const reasons = assistantRiskReasons(w);
                    return localAssistantText(reasons.length ? 'outdoorRisk' : 'outdoorGood', { time: localTime(target), reasons: assistantJoin(reasons), overview: assistantCompactOverview(w), confidence });
                }
                if (intent === 'rain') {
                    if (now?.first >= 0 && !parsed.explicit) {
                        const minutes = Math.max(0, Math.round((now.startAt - Date.now()) / 60000));
                        return now.raining ? ui('assistant.answer.raining_now', { end: now.endAt ? localTime(now.endAt) : ui('assistant.time.beyond'), confidence }) : ui('assistant.answer.rain_start', { minutes, start: localTime(now.startAt), end: now.endAt ? localTime(now.endAt) : ui('assistant.time.beyond'), confidence });
                    }
                    return ui('assistant.answer.rain_probability', { time: localTime(target), rain: Math.round(w.rain), confidence });
                }
                if (intent === 'temperature')
                    return `${ui('assistant.answer.temperature', { time: localTime(target), temperature: tempText(w.temp), feels: tempText(w.feels), confidence })} ${assistantAdviceFor(w)}`;
                if (intent === 'wind')
                    return `${ui('assistant.answer.wind', { time: localTime(target), wind: Math.round(w.wind), gust: Math.round(w.gust), confidence })} ${w.gust >= 45 ? assistantAdviceFor(w) : ''}`.trim();
                if (intent === 'humidity')
                    return ui('assistant.answer.humidity', { time: localTime(target), humidity: Math.round(w.humidity), confidence });
                if (intent === 'pressure')
                    return ui('assistant.answer.pressure', { time: localTime(target), pressure: Math.round(w.pressure), confidence });
                if (intent === 'visibility')
                    return ui('assistant.answer.visibility', { time: localTime(target), visibility: w.visibility.toFixed(1), confidence });
                if (intent === 'uv')
                    return `${ui('assistant.answer.uv', { time: localTime(target), uv: w.uv.toFixed(1), confidence })} ${w.uv >= 6 ? assistantAdviceFor(w) : ''}`.trim();
                if (intent === 'ice') {
                    const risk = w.temp <= 2 && (w.mm > .05 || w.snow > 0);
                    return ui(risk ? 'assistant.answer.ice_yes' : 'assistant.answer.ice_no', { time: localTime(target), temperature: tempText(w.temp), confidence });
                }
                if (intent === 'snow')
                    return ui(w.snow > 0 ? 'assistant.answer.snow_yes' : 'assistant.answer.snow_no', { time: localTime(target), snow: w.snow.toFixed(1), temperature: tempText(w.temp), confidence });
                if (intent === 'sport') {
                    const good = w.rain < 35 && w.gust < 40 && w.temp > 3 && w.temp < 31 && w.visibility > 3;
                    return `${ui(good ? 'assistant.answer.sport_yes' : 'assistant.answer.sport_no', { time: localTime(target), temperature: tempText(w.temp), rain: Math.round(w.rain), gust: Math.round(w.gust), visibility: w.visibility.toFixed(1), confidence })} ${assistantAdviceFor(w)}`;
                }
                if (intent === 'sea') {
                    await loadEnvironment();
                    const env = environmentPeaks(), good = w.rain < 30 && w.gust < 35 && (env.wave === 0 || env.wave < 1.5);
                    return `${ui(good ? 'assistant.answer.sea_yes' : 'assistant.answer.sea_no', { rain: Math.round(w.rain), gust: Math.round(w.gust), wave: env.wave ? env.wave.toFixed(1) : '--', confidence })} ${assistantAdviceFor(w)}`;
                }
                if (intent === 'travel') {
                    if (suite.route)
                        return localAssistantAnswer(ui('assistant.suggestion.route'));
                    const match = question.match(assistantPattern('assistant.pattern.destination'));
                    if (match) {
                        try {
                            const destination = await geocodeCity(match[1].trim()), origin = { name: locationLabel(), latitude: n(state.location.latitude), longitude: n(state.location.longitude) }, route = await calculateRouteCore(origin, destination, target, 'car');
                            return ui('assistant.answer.travel', { destination: destination.name, time: localTime(route.best.at), risk: Math.round(route.best.score), confidence });
                        }
                        catch { }
                    }
                    return ui('assistant.answer.travel_missing', { confidence });
                }
                if (intent === 'best_day') {
                    const best = dailyBest(assistantMatches(text, 'assistant.pattern.sea') ? 'sea' : assistantMatches(text, 'assistant.pattern.sport') ? 'sport' : 'general');
                    return best ? ui('assistant.answer.best_day', { date: new Intl.DateTimeFormat(currentLocale(), { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date(`${best.date}T12:00:00`)), rain: Math.round(best.rain), gust: Math.round(best.gust), max: tempText(best.max), confidence }) : ui('assistant.answer.no_data');
                }
                if (intent === 'compare_periods') {
                    const morning = weatherAt(new Date(target).setHours(9, 0, 0, 0)), afternoon = weatherAt(new Date(target).setHours(15, 0, 0, 0)), score = row => row.rain + row.gust + Math.abs(row.temp - 20) * 2, best = score(morning) <= score(afternoon) ? ui('assistant.period.morning') : ui('assistant.period.afternoon');
                    return ui('assistant.answer.compare_periods', { best, morningRain: Math.round(morning.rain), afternoonRain: Math.round(afternoon.rain), confidence });
                }
                if (intent === 'forecast')
                    return assistantOverview(target, w, confidence);
                return assistantOverview(target, w, confidence);
            }
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
            function openAssistantDialog() {
                const dialog = q('#assistant-dialog');
                if (!dialog)
                    return;
                const guest = isGuest();
                qa('[data-assistant-mode-choice="ai"]').forEach(button => {
                    button.hidden = guest;
                    button.disabled = guest;
                    button.setAttribute('aria-hidden', guest ? 'true' : 'false');
                });
                qa('[data-assistant-mode-choice="local"]').forEach(button => {
                    button.hidden = false;
                    button.disabled = false;
                });
                if (guest) setAssistantMode('local', false);
                restoreAssistant();
                renderAssistantSuggestions();
                if (!dialog.open && typeof dialog.showModal === 'function')
                    dialog.showModal();
                else
                    dialog.setAttribute('open', '');
                setTimeout(() => q('#assistant-input')?.focus(), 80);
            }
            function closeAssistantDialog() {
                const dialog = q('#assistant-dialog');
                if (!dialog)
                    return;
                if (suite.aiSwitchPending) {
                    suite.aiSwitchPending = null;
                    setAssistantBusy(false);
                }
                if (dialog.open && typeof dialog.close === 'function')
                    dialog.close();
                else
                    dialog.removeAttribute('open');
            }
            function assistantRows() {
                try {
                    return JSON.parse(sessionStorage.getItem(KEYS.assistant) || '[]');
                }
                catch {
                    return [];
                }
            }
            function saveAssistantRows(rows) { sessionStorage.setItem(KEYS.assistant, JSON.stringify(rows.slice(-30))); }
            function setAssistantBusy(busy) {
                suite.assistantBusy = Boolean(busy);
                const form = q('#assistant-form');
                const send = q('#assistant-send');
                const clear = q('#assistant-clear');
                if (form)
                    form.setAttribute('aria-busy', busy ? 'true' : 'false');
                if (send)
                    send.disabled = Boolean(busy);
                if (clear)
                    clear.disabled = Boolean(busy);
            }
            function copilotEvidenceHtml(rawDecision) {
                const decision = deps.copilot?.normalizeDecision?.(rawDecision) || rawDecision;
                if (!decision?.available) return '';
                const items = [];
                const status = ['good','caution','avoid','learning'].includes(decision.status) ? decision.status : 'learning';
                items.push(`<span class="copilot-evidence-status ${safe(status)}">${safe(ui(`copilot.status.${status}`))}</span>`);
                if (Number.isFinite(decision.score)) items.push(`<span><small>${safe(ui('copilot.metric.score'))}</small><strong>${Math.round(decision.score)}/100</strong></span>`);
                if (Number.isFinite(decision.confidence)) items.push(`<span><small>${safe(ui('copilot.metric.confidence'))}</small><strong>${Math.round(decision.confidence)}%</strong></span>`);
                if (decision.route?.bestDeparture) items.push(`<span><small>${safe(ui('copilot.metric.best_departure'))}</small><strong>${safe(localTime(decision.route.bestDeparture))}</strong></span>`);
                if (Number.isFinite(decision.route?.selectedRisk)) items.push(`<span><small>${safe(ui('copilot.metric.route_risk'))}</small><strong>${Math.round(decision.route.selectedRisk)}/100</strong></span>`);
                if (Number.isFinite(decision.sourceCount)) items.push(`<span><small>${safe(ui('copilot.metric.sources'))}</small><strong>${Math.round(decision.sourceCount)}</strong></span>`);
                return `<div class="copilot-evidence"><div class="copilot-evidence-head"><svg aria-hidden="true"><use href="#i-shield"></use></svg><strong>${safe(ui('copilot.evidence.title'))}</strong></div><div class="copilot-evidence-grid">${items.join('')}</div></div>`;
            }
            function addAssistant(role, text, persist = true, meta = {}) {
                text = role === 'assistant' ? assistantPlainText(text) : String(text || '').trim();
                if (!text)
                    return null;
                const root = q('#assistant-messages');
                if (!root)
                    return null;
                const article = document.createElement('article');
                const source = role === 'assistant' && meta?.source === 'ai' ? 'ai' : 'local';
                const pending = role === 'assistant' && Boolean(meta?.pending);
                article.className = `assistant-message ${role}${source === 'ai' ? ' is-ai' : ''}${pending ? ' is-thinking' : ''}`;
                if (role === 'assistant') {
                    article.dataset.source = source;
                    const avatarIcon = source === 'ai' ? 'i-spark' : 'i-sun';
                    const avatarLabel = source === 'ai' ? ` role="img" aria-label="${safe(ui('assistant.response.ai.aria_label'))}" title="${safe(ui('assistant.response.ai.aria_label'))}"` : ' aria-hidden="true"';
                    const body = pending
                        ? `<p class="assistant-thinking-text"><span>${safe(text)}</span><span aria-hidden="true" class="assistant-thinking-dots"><i></i><i></i><i></i></span></p>`
                        : `<p>${safe(text)}</p>`;
                    const sourceBadge = source === 'ai' ? `<span class="assistant-source-badge ai">${safe(ui('assistant.response.source.ai'))}</span>` : '';
                    const evidence = source === 'ai' && !pending ? copilotEvidenceHtml(meta?.decision) : '';
                    article.innerHTML = `<span class="assistant-avatar${source === 'ai' ? ' ai' : ''}"${avatarLabel}><svg aria-hidden="true"><use href="#${avatarIcon}"></use></svg></span><div class="assistant-message-bubble"><div class="assistant-message-meta"><strong>${safe(ui('assistant.name'))}</strong>${sourceBadge}</div>${body}${evidence}</div>`;
                }
                else {
                    article.innerHTML = `<div class="assistant-message-bubble"><div class="assistant-message-meta"><strong>${safe(ui('assistant.you'))}</strong></div><p>${safe(text)}</p></div>`;
                }
                root.append(article);
                root.scrollTop = root.scrollHeight;
                if (persist) {
                    const rows = assistantRows();
                    const row = { role, text, at: Date.now() };
                    if (role === 'assistant') {
                        row.source = source;
                        if (meta?.provider) row.provider = String(meta.provider).slice(0, 60);
                        if (meta?.model) row.model = String(meta.model).slice(0, 120);
                        if (meta?.decision?.available) row.decision = meta.decision;
                    }
                    rows.push(row);
                    saveAssistantRows(rows);
                }
                return article;
            }
            function resetAssistant() {
                sessionStorage.removeItem(KEYS.assistant);
                suite.assistantContext = {};
                suite.aiSwitchPending = null;
                setAssistantMode('local');
                const root = q('#assistant-messages');
                if (root)
                    root.innerHTML = '';
                addAssistant('assistant', ui('assistant.welcome'), false);
            }
            function restoreAssistant() {
                const root = q('#assistant-messages');
                if (!root)
                    return;
                root.innerHTML = '';
                setAssistantProviderStatus(suite.assistantMode === 'ai', suite.assistantMode === 'ai' ? ui('assistant.provider.pending') : 'local');
                const rows = assistantRows();
                if (!rows.length) {
                    addAssistant('assistant', ui('assistant.welcome'), false);
                    return;
                }
                rows.forEach(row => addAssistant(row.role, row.text, false, { source: row.source || 'local', provider: row.provider || '', model: row.model || '', decision: row.decision || null }));
            }
            async function askAssistant(question) {
                const guest = isGuest();
                if (guest && suite.assistantMode !== 'local') setAssistantMode('local', false);
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
