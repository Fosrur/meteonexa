window.METEONEXA_CONFIG = Object.freeze({
    APP_NAME_KEY: 'app.name',
    DEFAULT_LOCATION: Object.freeze({
        nameKey: 'location.default.name', admin1Key: 'location.default.admin1', countryKey: 'location.default.country',
        name: '', admin1: '', country: '',
        latitude: 45.4642, longitude: 9.1900, timezone: 'Europe/Rome'
    }),
    WEATHER_API: 'https://api.open-meteo.com/v1/forecast',
    ENSEMBLE_API: 'https://ensemble-api.open-meteo.com/v1/ensemble',
    HISTORICAL_API: 'https://archive-api.open-meteo.com/v1/archive',
    HISTORICAL_WEATHER_API: 'https://archive-api.open-meteo.com/v1/archive',
    PREVIOUS_RUNS_API: 'https://previous-runs-api.open-meteo.com/v1/forecast',
    MARINE_API: 'https://marine-api.open-meteo.com/v1/marine',
    AIR_QUALITY_API: 'https://air-quality-api.open-meteo.com/v1/air-quality',
    GEOCODING_API: 'https://geocoding-api.open-meteo.com/v1/search',
    REVERSE_GEOCODING_API: 'api/location/reverse.php',
    RADAR_API: 'api/radar/frames.php',
    OPENFREEMAP_STYLE: 'https://tiles.openfreemap.org/styles/liberty',
    BASEMAP_DATA: './assets/data/world-lines.json',
    ITALY_REGIONS_DATA: 'vendor-asset.php?asset=italy-regions',
    ITALY_METRO_DATA: 'vendor-asset.php?asset=italy-metros',
    RADAR_REFRESH_MINUTES: 5,
    REFRESH_MINUTES: 5,
    REQUEST_TIMEOUT_MS: 8500
});
