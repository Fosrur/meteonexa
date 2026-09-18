'use strict';
(() => {
    const welcome = document.getElementById('welcome');
    const resolveScene = weather => {
        const current = weather?.current || null;
        const fetchedAt = Number(weather?.fetchedAt || 0);
        const freshEnough = fetchedAt > 0 && Date.now() - fetchedAt <= 6 * 3600000;
        if (!current || !freshEnough) {
            const hour = new Date().getHours();
            return hour >= 20 || hour < 6 ? 'night' : 'neutral';
        }
        const code = Number(current.weather_code ?? -1);
        const isDay = Number(current.is_day ?? 1) === 1;
        if ([95, 96, 99].includes(code)) return 'storm';
        if ([71, 73, 75, 77, 85, 86].includes(code)) return 'snow';
        if ([51, 53, 55, 56, 57, 61, 63, 65, 66, 67, 80, 81, 82].includes(code) || Number(current.rain || current.precipitation || 0) > 0) return 'rain';
        if ([45, 48].includes(code)) return 'fog';
        if ([1, 2, 3].includes(code)) return isDay ? 'cloud' : 'night-cloud';
        if (code === 0) return isDay ? 'sun' : 'night';
        return isDay ? 'neutral' : 'night';
    };
    if (welcome) {
        let weather = null;
        try { weather = JSON.parse(localStorage.getItem('meteonexa_v3_weather') || 'null'); } catch { }
        const scene = resolveScene(weather);
        welcome.dataset.loginWeather = scene;
        welcome.dataset.loginWeatherSource = weather?.current ? 'local-cache' : 'neutral';
        // The markup starts hidden (`ready=false`), so the default sun can never
        // paint for a frame before the cached weather scene is known.
        requestAnimationFrame(() => { welcome.dataset.loginWeatherReady = 'true'; });
    }

    const target = document.getElementById('device-time');
    if (!target) return;
    const update = () => {
        const locale = navigator.languages?.[0] || navigator.language || document.documentElement.lang || 'it-IT';
        target.textContent = new Intl.DateTimeFormat(locale, { hour: '2-digit', minute: '2-digit' }).format(new Date());
    };
    update();
    window.__METEONEXA_BOOT_CLOCK__ = window.setInterval(update, 1000);
})();
