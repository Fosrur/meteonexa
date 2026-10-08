import { createVisualizationChartCore } from './visualization-chart-core.mjs';
import { createVisualizationAtmosphere } from './visualization-atmosphere.mjs';
import { createVisualizationMotionCharts } from './visualization-motion-charts.mjs';

export const serviceNames = Object.freeze(['visualization']);
export const dependencies = Object.freeze(['radarMotion', 'radarController']);

function factory(window, deps, provided) {
    void deps;
    provided.visualization = Object.freeze({
        create(context) {
            const {
                state, CONFIG, STORAGE, $, $$, clamp, appLocale, capitalize, meteonexaText,
                escapeHTML, temperature, convertTemp, unitLabel, formatClock, windDirection,
                currentHourlyIndex, t, currentResolvedCondition, isUiFeatureVisible, intelligenceLocationKey,
                localSeriesIndex, drawHistoryChart, saveJSON, debounce, normalizeLocation, withLoader, addRecent,
                updateSelectedLocationUI, fullLocationLabel, loadWeather, renderAll, searchCities, getCurrentLocationData,
                locationErrorMessage, persistLocalSettings, fetchJSON, sleep, formatLocationLocalTime, showToast,
                drawRadarBaseMap, loadRadarBaseData, loadRadarAdminData
            } = context;
            if (!state || typeof $ !== 'function' || typeof $$ !== 'function') {
                throw new Error('METEONEXA_VISUALIZATION_CONTEXT_INVALID');
            }
        const {
            canvasSetup, hideChartTooltip, registerChartInteraction,
            drawChartAxisTitle, chartUnitAxisLabel, schedulePrimaryChartMotion
        } = createVisualizationChartCore({ window, state, $, clamp, appLocale, meteonexaText });
        function drawChart(canvas, hours, detailed = false, motionElapsed = null) {
            const setup = canvasSetup(canvas);
            if (!setup || !state.weather)
                return;
            const { ctx, width, height } = setup;
            const data = state.weather;
            const start = currentHourlyIndex(data);
            const count = Math.min(hours, data.hourly.time.length - start);
            const temperatures = data.hourly.temperature_2m.slice(start, start + count).map(convertTemp);
            const feels = data.hourly.apparent_temperature.slice(start, start + count).map(convertTemp);
            const rain = data.hourly.precipitation_probability.slice(start, start + count).map(Number);
            const labels = data.hourly.time.slice(start, start + count);
            const pad = detailed ? { left: 58, right: 54, top: 27, bottom: 48 } : { left: 52, right: 50, top: 22, bottom: 44 };
            const chartWidth = width - pad.left - pad.right;
            const chartHeight = height - pad.top - pad.bottom;
            const minTemp = Math.floor(Math.min(...temperatures, ...(detailed ? feels : temperatures)) - 2);
            const maxTemp = Math.ceil(Math.max(...temperatures, ...(detailed ? feels : temperatures)) + 2);
            const x = index => pad.left + index / Math.max(1, count - 1) * chartWidth;
            const yTemp = value => pad.top + (maxTemp - value) / Math.max(1, maxTemp - minTemp) * chartHeight;
            const yRain = value => pad.top + chartHeight - value / 100 * chartHeight;
            ctx.clearRect(0, 0, width, height);
            const css = getComputedStyle(document.body);
            const gridColor = document.body.dataset.theme === 'light' ? 'rgba(27,83,142,.10)' : 'rgba(146,194,255,.08)';
            const textColor = css.getPropertyValue('--muted').trim() || '#7189a6';
            ctx.font = '11px Inter, system-ui, sans-serif';
            ctx.textBaseline = 'middle';
            for (let line = 0; line <= 4; line += 1) {
                const y = pad.top + line / 4 * chartHeight;
                ctx.strokeStyle = gridColor;
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.moveTo(pad.left, y);
                ctx.lineTo(width - pad.right, y);
                ctx.stroke();
                const value = Math.round(maxTemp - line / 4 * (maxTemp - minTemp));
                ctx.fillStyle = textColor;
                ctx.textAlign = 'right';
                ctx.fillText(`${value}°`, pad.left - 8, y);
            }
            ctx.font = '600 10px Inter, system-ui, sans-serif';
            ctx.fillStyle = textColor;
            ctx.textAlign = 'left';
            for (let row = 0; row <= 4; row += 1) {
                const y = pad.top + row / 4 * chartHeight;
                ctx.fillText(`${Math.round(100 - row * 25)}%`, width - pad.right + 7, y);
            }
            drawChartAxisTitle(ctx, chartUnitAxisLabel(t('history.yrain.temperature'), unitLabel()), 11, pad.top + chartHeight / 2, { rotate: -Math.PI / 2 });
            drawChartAxisTitle(ctx, chartUnitAxisLabel(t('visualization.yrain.rain_probability'), '%'), width - 11, pad.top + chartHeight / 2, { rotate: Math.PI / 2 });
            drawChartAxisTitle(ctx, t('app.currentsharepayload.time'), pad.left + chartWidth / 2, height - 7);
            const barWidth = Math.max(2, chartWidth / count * .42);
            rain.forEach((value, index) => {
                const barHeight = chartHeight - (yRain(value) - pad.top);
                const gradient = ctx.createLinearGradient(0, yRain(value), 0, pad.top + chartHeight);
                gradient.addColorStop(0, 'rgba(116,102,255,.65)');
                gradient.addColorStop(1, 'rgba(49,127,255,.04)');
                ctx.fillStyle = gradient;
                ctx.beginPath();
                ctx.roundRect(x(index) - barWidth / 2, yRain(value), barWidth, Math.max(1, barHeight), [4, 4, 0, 0]);
                ctx.fill();
            });
            function plot(values, stroke, fill, lineWidth = 2.4, dashed = false) {
                ctx.save();
                if (dashed)
                    ctx.setLineDash([5, 5]);
                ctx.beginPath();
                values.forEach((value, index) => {
                    const pointX = x(index), pointY = yTemp(value);
                    if (index === 0)
                        ctx.moveTo(pointX, pointY);
                    else
                        ctx.lineTo(pointX, pointY);
                });
                ctx.strokeStyle = stroke;
                ctx.lineWidth = lineWidth;
                ctx.lineJoin = 'round';
                ctx.lineCap = 'round';
                ctx.stroke();
                if (fill) {
                    const gradient = ctx.createLinearGradient(0, pad.top, 0, pad.top + chartHeight);
                    gradient.addColorStop(0, fill);
                    gradient.addColorStop(1, 'rgba(50,184,255,0)');
                    ctx.lineTo(x(count - 1), pad.top + chartHeight);
                    ctx.lineTo(x(0), pad.top + chartHeight);
                    ctx.closePath();
                    ctx.fillStyle = gradient;
                    ctx.fill();
                }
                ctx.restore();
            }
            plot(temperatures, '#42c8ff', 'rgba(48,188,255,.17)', 2.5);
            if (detailed)
                plot(feels, '#ffbd54', null, 1.7, true);
            temperatures.forEach((value, index) => {
                ctx.beginPath();
                ctx.arc(x(index), yTemp(value), detailed ? 2.8 : 2.4, 0, Math.PI * 2);
                ctx.fillStyle = '#72dcff';
                ctx.fill();
            });
            if (detailed)
                feels.forEach((value, index) => {
                    ctx.beginPath();
                    ctx.arc(x(index), yTemp(value), 2.1, 0, Math.PI * 2);
                    ctx.fillStyle = '#ffd07c';
                    ctx.fill();
                });
            const labelStep = detailed ? Math.max(1, Math.ceil(count / 10)) : Math.max(1, Math.ceil(count / 8));
            labels.forEach((label, index) => {
                if (index % labelStep !== 0 && index !== count - 1)
                    return;
                ctx.fillStyle = textColor;
                ctx.textAlign = index === 0 ? 'left' : index === count - 1 ? 'right' : 'center';
                ctx.fillText(index === 0 ? "" + escapeHTML(meteonexaText("app.currentsharepayload.time")) : formatClock(label), x(index), height - 12);
            });
            if (motionElapsed !== null && !state.settings.reduceMotion) {
                const motionSeries = [
                    { label: "" + escapeHTML(meteonexaText("history.yrain.temperature")), values: temperatures, color: '#42c8ff' },
                    ...(detailed ? [{ label: "" + escapeHTML(meteonexaText("history.renderhistory.feels_like")), values: feels, color: '#ffbd54' }] : []),
                    { label: "" + meteonexaText("visualization.yrain.rain_probability"), values: rain, color: '#8b7cff' }
                ];
                const rainSeriesIndex = motionSeries.length - 1;
                drawChartPlayhead(ctx, motionSeries, labels, x, (datasetIndex, value) => datasetIndex === rainSeriesIndex ? yRain(value) : yTemp(value), pad, chartHeight, motionElapsed, !detailed);
            }
            registerChartInteraction(canvas, {
                title: detailed ? meteonexaText("visualization.full_48_hour_forecast") : meteonexaText("visualization.next_24_hour_trend"),
                labels,
                pad,
                series: [
                    { label: "" + escapeHTML(meteonexaText("history.yrain.temperature")), values: temperatures, unit: unitLabel(), color: '#42c8ff', digits: 1 },
                    ...(detailed ? [{ label: "" + escapeHTML(meteonexaText("history.renderhistory.feels_like")), values: feels, unit: unitLabel(), color: '#ffbd54', digits: 1 }] : []),
                    { label: "" + meteonexaText("visualization.yrain.rain_probability"), values: rain, unit: '%', color: '#8b7cff', digits: 0 }
                ]
            });
            if (motionElapsed === null && !state.settings.reduceMotion) {
                schedulePrimaryChartMotion(canvas, elapsed => drawChart(canvas, hours, detailed, elapsed));
            }
        }
        function drawHomeChart() { drawChart($('#home-chart'), 24, false); }
        function drawDetailChart() { drawChart($('#detail-chart'), 48, true); }
        function drawAllDetailCharts() {
            drawDetailChart();
            drawTrendCharts(true);
        }
        const {
            initializeWeatherFX, resizeWeatherFX, updateWeatherAtmosphere
        } = createVisualizationAtmosphere({ window, state, $, clamp, currentResolvedCondition, meteonexaText });
        const { drawMotionChart } = createVisualizationMotionCharts({
            window, state, $, clamp, canvasSetup, appLocale, drawChartAxisTitle,
            chartUnitAxisLabel, registerChartInteraction
        });
        function drawTrendCharts(includeDetails = false) {
            if (!state.weather?.hourly)
                return;
            const hourly = state.weather.hourly;
            const start = Math.max(0, currentHourlyIndex(state.weather));
            const available = Math.max(1, (hourly.time || []).length - start);
            const labels = hours => (hourly.time || []).slice(start, start + Math.min(hours, available));
            const take = (key, hours, fallback = 0) => {
                const source = Array.isArray(hourly[key]) ? hourly[key] : [];
                const count = Math.min(hours, available);
                return Array.from({ length: count }, (_, offset) => {
                    const value = Number(source[start + offset]);
                    return Number.isFinite(value) ? value : (typeof fallback === 'function' ? fallback(offset) : fallback);
                });
            };
            const labels24 = labels(24);
            const wind24 = take('wind_speed_10m', 24);
            const gust24 = take('wind_gusts_10m', 24, offset => wind24[offset] || 0);
            const humidity24 = take('relative_humidity_2m', 24);
            const pressure24 = take('surface_pressure', 24, 1013);
            const uv24 = take('uv_index', 24);
            const cloud24 = take('cloud_cover', 24);
            const currentWind = wind24[0] ?? 0;
            const currentHumidity = humidity24[0] ?? 0;
            const currentUv = uv24[0] ?? 0;
            if ($('#wind-chart-value'))
                $('#wind-chart-value').textContent = `${Math.round(currentWind)} km/h`;
            if ($('#humidity-chart-value'))
                $('#humidity-chart-value').textContent = `${Math.round(currentHumidity)}%`;
            if ($('#uv-chart-value'))
                $('#uv-chart-value').textContent = `UV ${Number(currentUv).toFixed(1)}`;
            drawMotionChart($('#wind-chart'), [
                { label: "" + escapeHTML(meteonexaText("visualization.take.wind")), unit: ' km/h', digits: 0, values: wind24, color: '#41d5ff', fill: 'rgba(65,213,255,.25)' },
                { label: "" + escapeHTML(meteonexaText("history.renderhistory.gusts")), unit: ' km/h', digits: 0, values: gust24, color: '#9b7cff', dashed: true }
            ], labels24, { compact: true, title: "" + meteonexaText("visualization.wind_gusts_next_24_hours") });
            drawMotionChart($('#humidity-chart'), [
                { label: "" + escapeHTML(meteonexaText("visualization.take.humidity")), unit: '%', digits: 0, values: humidity24, color: '#49d7ff', fill: 'rgba(73,215,255,.22)', min: 0, max: 100 },
                { label: "" + escapeHTML(meteonexaText("visualization.take.pressure")), unit: ' hPa', digits: 0, values: pressure24, color: '#54eda4', dashed: true }
            ], labels24, { compact: true, title: "" + meteonexaText("visualization.humidity_pressure_next_24_hours") });
            drawMotionChart($('#uv-chart'), [
                { label: "" + meteonexaText("visualization.take.uv_index"), unit: '', digits: 1, values: uv24, color: '#ffca55', fill: 'rgba(255,202,85,.22)', min: 0, max: Math.max(11, ...uv24) },
                { label: "" + meteonexaText("visualization.take.cloud_cover"), unit: '%', digits: 0, values: cloud24, color: '#9bb8e8', dashed: true, min: 0, max: 100 }
            ], labels24, { compact: true, title: "" + meteonexaText("visualization.uv_index_cloud_cover_next_24_hours") });
            if (!includeDetails && state.currentPage !== 'details')
                return;
            const labels48 = labels(48);
            const wind48 = take('wind_speed_10m', 48);
            const gust48 = take('wind_gusts_10m', 48, offset => wind48[offset] || 0);
            const humidity48 = take('relative_humidity_2m', 48);
            const pressure48 = take('surface_pressure', 48, 1013);
            const uv48 = take('uv_index', 48);
            const cloud48 = take('cloud_cover', 48);
            drawMotionChart($('#detail-wind-chart'), [
                { label: "" + escapeHTML(meteonexaText("visualization.take.wind")), unit: ' km/h', digits: 0, values: wind48, color: '#35d2ff', fill: 'rgba(53,210,255,.24)' },
                { label: "" + escapeHTML(meteonexaText("history.renderhistory.gusts")), unit: ' km/h', digits: 0, values: gust48, color: '#a779ff', dashed: true }
            ], labels48, { title: "" + meteonexaText("visualization.wind_gusts_48_hours") });
            drawMotionChart($('#detail-comfort-chart'), [
                { label: "" + escapeHTML(meteonexaText("visualization.take.humidity")), unit: '%', digits: 0, values: humidity48, color: '#4bdcff', fill: 'rgba(75,220,255,.20)', min: 0, max: 100 },
                { label: "" + escapeHTML(meteonexaText("visualization.take.pressure")), unit: ' hPa', digits: 0, values: pressure48, color: '#50ed9f', dashed: true }
            ], labels48, { title: "" + meteonexaText("visualization.humidity_pressure_48_hours") });
            drawMotionChart($('#detail-sky-chart'), [
                { label: "" + meteonexaText("visualization.take.uv_index"), unit: '', digits: 1, values: uv48, color: '#ffc94d', fill: 'rgba(255,201,77,.20)', min: 0, max: Math.max(11, ...uv48) },
                { label: "" + meteonexaText("visualization.take.cloud_cover"), unit: '%', digits: 0, values: cloud48, color: '#9ebcff', dashed: true, min: 0, max: 100 }
            ], labels48, { title: "" + meteonexaText("visualization.uv_index_cloud_cover_48_hours") });
            const air = state.air?.hourly;
            if (air?.time?.length) {
                const found = localSeriesIndex(air.time, { weatherData: state.air });
                const airStart = Math.max(0, found - 1);
                const airLabels = air.time.slice(airStart, airStart + 48);
                drawMotionChart($('#detail-air-chart'), [
                    { label: "" + meteonexaText("visualization.take.european_aqi"), unit: '', digits: 0, values: (air.european_aqi || []).slice(airStart, airStart + 48), color: '#59e99a', fill: 'rgba(89,233,154,.20)', min: 0, max: 150 },
                    { label: "" + meteonexaText("visualization.take.pm2_5"), unit: ' µg/m³', digits: 1, values: (air.pm2_5 || []).slice(airStart, airStart + 48), color: '#ffb84c', dashed: true }
                ], airLabels, { title: "" + meteonexaText("visualization.air_quality_48_hours") });
            }
        }
        function initializeChartObservers() {
            if (!('ResizeObserver' in window))
                return;
            let timer = 0;
            const observer = new ResizeObserver(entries => {
                if (!state.weather || !entries.some(entry => entry.contentRect.width > 20 && entry.contentRect.height > 20))
                    return;
                clearTimeout(timer);
                timer = window.setTimeout(() => {
                    drawHomeChart();
                    drawTrendCharts(state.currentPage === 'details');
                    if (state.currentPage === 'details')
                        drawDetailChart();
                    if (state.currentPage === 'history')
                        drawHistoryChart();
                }, 120);
            });
            $$('.chart-wrap, .mini-chart-wrap, .detail-chart-wrap, .detail-motion-chart, .history-chart-wrap').forEach(element => observer.observe(element));
        }
        const { lonLatToWorld, worldToLonLat, radarTileUrl, analyzeRadarMotion, renderRadarMotion } = deps.radarMotion.create({
            state, storage: STORAGE, $, t, clamp, windDirection, saveJSON,
            isUiFeatureVisible, intelligenceLocationKey, escapeHTML
        });
        const {
            syncRadarVectorLayer, removeRadarVectorLayer, selectRadarLocation, performRadarCitySearch, useGpsFromRadar, setRadarMode,
            renderRadarMap, drawForecastRadarLayer, ensureRadar, setRadarFrame, stopRadarAnimation,
            toggleRadarAnimation, stepRadar, zoomRadar
        } = deps.radarController.create({
            state, CONFIG, STORAGE, $, $$, t, meteonexaText, escapeHTML, clamp, debounce,
            normalizeLocation, withLoader, saveJSON, addRecent, updateSelectedLocationUI, fullLocationLabel,
            loadWeather, renderAll, searchCities, getCurrentLocationData, locationErrorMessage,
            persistLocalSettings, fetchJSON, currentHourlyIndex, sleep, appLocale, formatLocationLocalTime,
            showToast, drawRadarBaseMap, loadRadarBaseData, loadRadarAdminData,
            lonLatToWorld, worldToLonLat, radarTileUrl, analyzeRadarMotion, renderRadarMotion
        });
            return Object.freeze({
                canvasSetup,
                hideChartTooltip,
                registerChartInteraction,
                drawChartAxisTitle,
                chartUnitAxisLabel,
                drawHomeChart,
                drawDetailChart,
                drawAllDetailCharts,
                initializeWeatherFX,
                resizeWeatherFX,
                updateWeatherAtmosphere,
                drawMotionChart,
                drawTrendCharts,
                initializeChartObservers,
                lonLatToWorld, worldToLonLat, radarTileUrl, analyzeRadarMotion, renderRadarMotion,
                syncRadarVectorLayer, removeRadarVectorLayer, selectRadarLocation, performRadarCitySearch, useGpsFromRadar, setRadarMode,
                renderRadarMap, drawForecastRadarLayer, ensureRadar, setRadarFrame, stopRadarAnimation,
                toggleRadarAnimation, stepRadar, zoomRadar
            });
        }
    });
}

export function install(services) {
    return services.installModule({ provides: serviceNames, dependencies, factory });
}
