export function createVisualizationAtmosphere(context) {
    const { window, state, $, clamp, currentResolvedCondition, meteonexaText } = context;
    if (!state || typeof $ !== 'function') {
        throw new Error('METEONEXA_VISUALIZATION_ATMOSPHERE_CONTEXT_INVALID');
    }
const weatherFX = {
    canvas: null, ctx: null, kind: '', isDay: true, particles: [], raf: null,
    width: 0, height: 0, dpr: 1, last: 0, lightningAt: 0
};
function initializeWeatherFX() {
    weatherFX.canvas = $('#weather-fx-canvas');
    if (!weatherFX.canvas)
        return;
    weatherFX.ctx = weatherFX.canvas.getContext('2d');
    resizeWeatherFX();
    if (!weatherFX.raf)
        weatherFX.raf = requestAnimationFrame(weatherFXFrame);
}
function resizeWeatherFX() {
    if (!weatherFX.canvas || !weatherFX.ctx)
        return;
    const dpr = Math.min(1.5, window.devicePixelRatio || 1);
    const host = weatherFX.canvas.parentElement;
    const rect = host?.getBoundingClientRect();
    const width = Math.max(320, Math.round(rect?.width || innerWidth));
    const height = Math.max(420, Math.round(rect?.height || innerHeight));
    weatherFX.width = width;
    weatherFX.height = height;
    weatherFX.dpr = dpr;
    weatherFX.canvas.width = Math.max(1, Math.round(width * dpr));
    weatherFX.canvas.height = Math.max(1, Math.round(height * dpr));
    weatherFX.canvas.style.width = '100%';
    weatherFX.canvas.style.height = '100%';
    weatherFX.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    buildWeatherParticles();
}
function buildWeatherParticles() {
    if (!weatherFX.width || !weatherFX.height)
        return;
    const mobileFactor = innerWidth < 720 ? .58 : 1;
    const reduced = state.settings.reduceMotion ? .22 : 1;
    const counts = { rain: 150, storm: 190, snow: 105, night: 68, clear: 42, cloud: 35, fog: 18 };
    const count = Math.max(6, Math.round((counts[weatherFX.kind] || 30) * mobileFactor * reduced));
    weatherFX.particles = Array.from({ length: count }, (_, i) => ({
        x: Math.random() * weatherFX.width,
        y: Math.random() * weatherFX.height,
        z: .35 + Math.random() * .9,
        speed: .4 + Math.random() * 1.5,
        size: 1 + Math.random() * 3.2,
        drift: -0.45 + Math.random() * .9,
        phase: Math.random() * Math.PI * 2,
        seed: i
    }));
}
function updateWeatherAtmosphere(force = false) {
    if (!state.weather?.current)
        return;
    const current = state.weather.current;
    const resolved = currentResolvedCondition(state.weather);
    const meta = resolved.meta;
    const kind = meta.kind === 'cloud' ? 'cloud' : meta.kind;
    const isDay = Boolean(resolved.isDay);
    if (!force && weatherFX.kind === kind && weatherFX.isDay === isDay)
        return;
    weatherFX.kind = kind;
    weatherFX.isDay = isDay;
    const root = $('#weather-atmosphere');
    if (root) {
        root.className = `weather-atmosphere weather-${kind} ${isDay ? 'is-day' : 'is-night'}`;
        root.style.setProperty('--cloud-cover', String(clamp(Number(current.cloud_cover || 0) / 100, .1, 1)));
        root.style.setProperty('--rain-strength', String(clamp(Number(current.precipitation || current.rain || 0) / 8, .2, 1)));
    }
    document.body.dataset.weather = kind;
    document.body.dataset.daylight = isDay ? 'day' : 'night';
    buildWeatherParticles();
}
function weatherFXFrame(timestamp) {
    weatherFX.raf = requestAnimationFrame(weatherFXFrame);
    if (!weatherFX.ctx || document.hidden)
        return;
    if (timestamp - weatherFX.last < (state.settings.reduceMotion ? 70 : 30))
        return;
    const dt = Math.min(2.2, (timestamp - (weatherFX.last || timestamp)) / 16.67);
    weatherFX.last = timestamp;
    const { ctx, width, height, particles, kind } = weatherFX;
    ctx.clearRect(0, 0, width, height);
    if (!particles.length)
        return;
    if (kind === 'rain' || kind === 'storm') {
        ctx.lineCap = 'round';
        particles.forEach(particle => {
            particle.x += (1.4 + particle.z * 2.3) * dt;
            particle.y += (8 + particle.speed * 8) * dt;
            if (particle.y > height + 30 || particle.x > width + 30) {
                particle.y = -30;
                particle.x = Math.random() * width - width * .15;
            }
            const length = 12 + particle.z * 24;
            const gradient = ctx.createLinearGradient(particle.x, particle.y, particle.x - 6, particle.y - length);
            gradient.addColorStop(0, `rgba(80,205,255,${.18 + particle.z * .28})`);
            gradient.addColorStop(1, 'rgba(80,205,255,0)');
            ctx.strokeStyle = gradient;
            ctx.lineWidth = .7 + particle.z * 1.25;
            ctx.beginPath();
            ctx.moveTo(particle.x, particle.y);
            ctx.lineTo(particle.x - 7, particle.y - length);
            ctx.stroke();
        });
        if (kind === 'storm' && timestamp > weatherFX.lightningAt) {
            weatherFX.lightningAt = timestamp + 2600 + Math.random() * 5200;
            const flash = $('.atmosphere-lightning');
            flash?.classList.remove('flash');
            requestAnimationFrame(() => flash?.classList.add('flash'));
        }
    }
    else if (kind === 'snow') {
        particles.forEach(particle => {
            particle.phase += .012 * dt;
            particle.x += (Math.sin(particle.phase) * .7 + particle.drift) * dt;
            particle.y += (1.2 + particle.speed * 1.9) * dt;
            if (particle.y > height + 12) {
                particle.y = -12;
                particle.x = Math.random() * width;
            }
            ctx.fillStyle = `rgba(230,248,255,${.28 + particle.z * .55})`;
            ctx.beginPath();
            ctx.arc(particle.x, particle.y, particle.size * particle.z, 0, Math.PI * 2);
            ctx.fill();
        });
    }
    else if (kind === 'fog' || kind === 'cloud') {
        particles.forEach(particle => {
            particle.x += (.42 + particle.speed * .46) * dt;
            if (particle.x > width + 260)
                particle.x = -260;
            const w = 120 + particle.z * 250;
            const gradient = ctx.createLinearGradient(particle.x - w, 0, particle.x + w, 0);
            gradient.addColorStop(0, 'rgba(195,224,255,0)');
            gradient.addColorStop(.5, `rgba(205,235,255,${kind === 'fog' ? .07 + particle.z * .1 : .055 + particle.z * .095})`);
            gradient.addColorStop(1, 'rgba(195,224,255,0)');
            ctx.fillStyle = gradient;
            ctx.fillRect(particle.x - w, particle.y, w * 2, 28 + particle.z * 65);
        });
    }
    else {
        particles.forEach(particle => {
            particle.phase += .015 * dt;
            particle.x += particle.drift * .06 * dt;
            const alpha = (.12 + .35 * (Math.sin(particle.phase) * .5 + .5)) * particle.z;
            ctx.fillStyle = weatherFX.isDay ? `rgba(255,230,145,${alpha})` : `rgba(178,222,255,${alpha})`;
            ctx.beginPath();
            ctx.arc(particle.x, particle.y, weatherFX.isDay ? particle.size * .55 : particle.size * .42, 0, Math.PI * 2);
            ctx.fill();
        });
    }
}
    return Object.freeze({
        initializeWeatherFX, resizeWeatherFX, updateWeatherAtmosphere
    });
}
