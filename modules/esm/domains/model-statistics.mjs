export function createModelStatistics(context) {
    const {
        state, localSeriesIndex, clamp, meteonexaText
    } = context;
    if (!state || typeof localSeriesIndex !== 'function') {
        throw new Error('METEONEXA_MODEL_STATISTICS_CONTEXT_INVALID');
    }
function intelligenceLocationKey(locationData = state.location) {
    return `${Number(locationData?.latitude || 0).toFixed(4)}:${Number(locationData?.longitude || 0).toFixed(4)}`;
}
function numericValues(values = []) {
    return values.map(Number).filter(Number.isFinite);
}
function average(values = []) {
    const clean = numericValues(values);
    return clean.length ? clean.reduce((sum, value) => sum + value, 0) / clean.length : 0;
}
function standardDeviation(values = []) {
    const clean = numericValues(values);
    if (clean.length < 2)
        return 0;
    const mean = average(clean);
    return Math.sqrt(clean.reduce((sum, value) => sum + ((value - mean) ** 2), 0) / clean.length);
}
function summarizeModelForecast(raw, definition) {
    const hourly = raw?.hourly;
    if (!hourly?.time?.length)
        return null;
    const start = localSeriesIndex(hourly.time, { currentTime: state.weather?.current?.time || '', weatherData: raw });
    const end = Math.min(hourly.time.length, start + 24);
    const range = (key) => (hourly[key] || []).slice(start, end).map(Number);
    const temperatures = range("temperature_2m");
    const precipitation = range('precipitation');
    const winds = range('wind_speed_10m');
    const gusts = range('wind_gusts_10m');
    const firstWetOffset = precipitation.findIndex(value => Number(value) >= 0.1);
    const currentCode = Number(hourly.weather_code?.[start] ?? state.weather?.current?.weather_code ?? 0);
    return {
        ...definition,
        raw,
        start,
        temperatureMax: temperatures.length ? Math.max(...temperatures) : 0,
        temperatureMin: temperatures.length ? Math.min(...temperatures) : 0,
        temperatureAverage: average(temperatures),
        precipitationTotal: precipitation.reduce((sum, value) => sum + (Number.isFinite(value) ? value : 0), 0),
        rainHours: precipitation.filter(value => value >= 0.1).length,
        windMax: Math.max(0, ...numericValues(winds), ...numericValues(gusts)),
        firstRain: firstWetOffset >= 0 ? hourly.time[start + firstWetOffset] : '',
        code: currentCode,
        temperatureSeries: temperatures.slice(0, 12),
        precipitationSeries: precipitation.slice(0, 12)
    };
}
function summarizeServerIntelligenceModel(model, definition) {
    const rows = Array.isArray(model?.rows) ? model.rows : [];
    if (!rows.length)
        return null;
    const now = Date.now();
    let start = rows.findIndex(row => new Date(row.time).getTime() >= now - 30 * 60 * 1000);
    if (start < 0)
        start = 0;
    const windowRows = rows.slice(start, start + 24);
    const temperatures = windowRows.map(row => Number(row.temperature)).filter(Number.isFinite);
    if (!temperatures.length)
        return null;
    const precipitation = windowRows.map(row => Number(row.precipitation)).filter(Number.isFinite);
    const gusts = windowRows.map(row => Number(row.windGust)).filter(Number.isFinite);
    const firstWet = windowRows.find(row => Number(row.precipitation) >= 0.1);
    return {
        ...definition,
        serverOwned: true,
        stale: model?.stale === true,
        retrievedAt: model?.retrievedAt || null,
        temperatureMax: Math.max(...temperatures),
        temperatureMin: Math.min(...temperatures),
        temperatureAverage: average(temperatures),
        precipitationTotal: precipitation.reduce((sum, value) => sum + value, 0),
        rainHours: precipitation.filter(value => value >= 0.1).length,
        windMax: gusts.length ? Math.max(...gusts) : 0,
        firstRain: firstWet?.time || '',
        code: Number(windowRows[0]?.weatherCode ?? state.weather?.current?.weather_code ?? 0),
        temperatureSeries: windowRows.slice(0, 12).map(row => Number(row.temperature)).filter(Number.isFinite),
        precipitationSeries: windowRows.slice(0, 12).map(row => Math.max(0, Number(row.precipitation || 0)))
    };
}
function createPreviewIntelligenceModels() {
    if (!state.weather?.hourly)
        return [];
    const base = summarizeModelForecast(state.weather, { id: 'auto', label: meteonexaText('app.name'), description: "" + meteonexaText("intelligence.createpreviewintelligencemodels.blended_forecast") });
    if (!base)
        return [];
    const definitions = [
        { id: 'ecmwf', label: meteonexaText('provider.ecmwf'), description: "" + meteonexaText("intelligence.high_resolution_european_model"), temp: 0.4, rain: 0.88, wind: 1.02 },
        { id: 'aifs', label: meteonexaText('provider.aifs'), description: meteonexaText('model.aifs.description'), temp: 0.25, rain: 0.93, wind: 1.01 },
        { id: 'icon', label: meteonexaText('provider.icon'), description: "" + meteonexaText("intelligence.createpreviewintelligencemodels.european_icon_model"), temp: -0.3, rain: 1.08, wind: 0.96 },
        { id: 'gfs', label: meteonexaText('provider.gfs'), description: "" + meteonexaText("intelligence.createpreviewintelligencemodels.noaa_global_model"), temp: 0.1, rain: 0.98, wind: 1.06 },
        { id: 'meteofrance', label: meteonexaText('provider.meteofrance'), description: meteonexaText('accuracy.preview.meteofrance'), temp: 0.2, rain: 1.03, wind: 0.99 },
        { id: 'ukmo', label: meteonexaText('provider.ukmo'), description: meteonexaText('accuracy.preview.ukmo'), temp: -0.1, rain: 0.94, wind: 1.03 }
    ];
    return definitions.map(item => ({
        ...base,
        ...item,
        temperatureMax: base.temperatureMax + item.temp,
        temperatureMin: base.temperatureMin + item.temp,
        temperatureAverage: base.temperatureAverage + item.temp,
        precipitationTotal: base.precipitationTotal * item.rain,
        windMax: base.windMax * item.wind,
        temperatureSeries: base.temperatureSeries.map(value => value + item.temp),
        precipitationSeries: base.precipitationSeries.map(value => value * item.rain)
    }));
}
    return Object.freeze({ intelligenceLocationKey, numericValues, average, standardDeviation, summarizeModelForecast, summarizeServerIntelligenceModel, createPreviewIntelligenceModels });
}
