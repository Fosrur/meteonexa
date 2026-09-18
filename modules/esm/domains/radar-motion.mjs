const PROVIDES = Object.freeze(['radarMotion']);
export const dependencies = Object.freeze(['security']);

export function install(services, host = globalThis) {
    return services.installModule({
        services,
        host,
        provides: PROVIDES,
        dependencies,
        factory(window, deps, provided) {
        (() => {
            function create(deps) {
                const { state, storage: STORAGE, $, t } = deps;
                const clamp = (...args) => deps.clamp(...args);
                const windDirection = (...args) => deps.windDirection(...args);
                const saveJSON = (...args) => deps.saveJSON(...args);
                const isUiFeatureVisible = (...args) => deps.isUiFeatureVisible(...args);
                const intelligenceLocationKey = (...args) => deps.intelligenceLocationKey(...args);
                const escapeHTML = (...args) => deps.escapeHTML(...args);
                function lonLatToWorld(lat, lon, zoom) {
                    const size = 256 * 2 ** zoom;
                    const sin = Math.sin(clamp(lat, -85.0511, 85.0511) * Math.PI / 180);
                    return { x: (lon + 180) / 360 * size, y: (0.5 - Math.log((1 + sin) / (1 - sin)) / (4 * Math.PI)) * size };
                }
                function worldToLonLat(x, y, zoom) {
                    const size = 256 * 2 ** zoom;
                    const lon = ((x / size) * 360 + 540) % 360 - 180;
                    const n = Math.PI - 2 * Math.PI * y / size;
                    const lat = 180 / Math.PI * Math.atan(.5 * (Math.exp(n) - Math.exp(-n)));
                    return { lat, lon };
                }
                function radarTileUrl(frame, zoom, x, y) {
                    if (!frame?.path || !frame?.frameSignature || !frame?.tileExpires)
                        return '';
                    const params = new URLSearchParams({
                        path: frame.path, z: String(zoom), x: String(x), y: String(y), color: String(frame.tileColor ?? 2), smooth: String(frame.tileSmooth || '1_1'),
                        deviceId: deps.security?.deviceId || '', exp: String(frame.tileExpires), frameSig: frame.frameSignature
                    });
                    return `api/radar/tile.php?${params}`;
                }
        
                async function radarImageBitmap(url) {
                    const response = await fetch(url, { cache: 'force-cache', credentials: 'same-origin' });
                    if (!response.ok) throw new Error('RADAR_TILE_HTTP');
                    const type = String(response.headers.get('content-type') || '').toLowerCase();
                    if (!type.startsWith('image/png')) throw new Error('RADAR_TILE_NOT_PNG');
                    const blob = await response.blob();
                    if (typeof createImageBitmap === 'function') return await createImageBitmap(blob);
                    return await new Promise((resolve, reject) => {
                        const objectUrl = URL.createObjectURL(blob);
                        const image = new Image();
                        image.onload = () => { URL.revokeObjectURL(objectUrl); resolve(image); };
                        image.onerror = () => { URL.revokeObjectURL(objectUrl); reject(new Error('RADAR_TILE_IMAGE')); };
                        image.src = objectUrl;
                    });
                }
                function radarMotionCanvas(width, height) {
                    if (typeof OffscreenCanvas === 'function') return new OffscreenCanvas(width, height);
                    const canvas = document.createElement('canvas');
                    canvas.width = width; canvas.height = height;
                    return canvas;
                }
                async function radarMotionMatrix(frame, { size = 176, cropPixels = 352 } = {}) {
                    const zoom = clamp(Number(frame?.tileZoom ?? 7), 0, 7);
                    const world = lonLatToWorld(Number(state.location.latitude), Number(state.location.longitude), zoom);
                    const centerTileX = Math.floor(world.x / 256);
                    const centerTileY = Math.floor(world.y / 256);
                    const scale = 2 ** zoom;
                    const composite = radarMotionCanvas(768, 768);
                    const ctx = composite.getContext('2d', { willReadFrequently: true });
                    if (!ctx) throw new Error('RADAR_CANVAS_CONTEXT');
                    ctx.clearRect(0, 0, 768, 768);
                    const requests = [];
                    for (let oy = -1; oy <= 1; oy += 1) {
                        for (let ox = -1; ox <= 1; ox += 1) {
                            const x = (centerTileX + ox + scale) % scale;
                            const y = clamp(centerTileY + oy, 0, scale - 1);
                            const url = radarTileUrl(frame, zoom, x, y);
                            requests.push(radarImageBitmap(url).then(bitmap => ({ bitmap, ox, oy })).catch(() => null));
                        }
                    }
                    const tiles = (await Promise.all(requests)).filter(Boolean);
                    if (tiles.length < 5) throw new Error('RADAR_TILES_INSUFFICIENT');
                    for (const item of tiles) {
                        ctx.drawImage(item.bitmap, (item.ox + 1) * 256, (item.oy + 1) * 256, 256, 256);
                        item.bitmap.close?.();
                    }
                    const centerX = 256 + (world.x - centerTileX * 256);
                    const centerY = 256 + (world.y - centerTileY * 256);
                    const half = cropPixels / 2;
                    const sample = radarMotionCanvas(size, size);
                    const sctx = sample.getContext('2d', { willReadFrequently: true });
                    sctx.clearRect(0, 0, size, size);
                    sctx.drawImage(composite, centerX - half, centerY - half, cropPixels, cropPixels, 0, 0, size, size);
                    const rgba = sctx.getImageData(0, 0, size, size).data;
                    const values = new Float32Array(size * size);
                    let active = 0;
                    for (let i = 0, p = 0; i < rgba.length; i += 4, p += 1) {
                        const alpha = rgba[i + 3] / 255;
                        const max = Math.max(rgba[i], rgba[i + 1], rgba[i + 2]);
                        const min = Math.min(rgba[i], rgba[i + 1], rgba[i + 2]);
                        const chroma = (max - min) / 255;
                        const value = alpha < .04 ? 0 : clamp(alpha * (.38 + chroma * .85), 0, 1);
                        values[p] = value;
                        if (value >= .08) active += 1;
                    }
                    return { values, size, active, zoom, cropPixels, centerIndex: Math.floor(size / 2) };
                }
                function radarBestShift(previous, current, maxShift = 11) {
                    if (!previous || !current || previous.size !== current.size) return null;
                    const size = current.size;
                    let best = null;
                    for (let dy = -maxShift; dy <= maxShift; dy += 1) {
                        for (let dx = -maxShift; dx <= maxShift; dx += 1) {
                            let ab = 0, aa = 0, bb = 0, used = 0;
                            for (let y = maxShift; y < size - maxShift; y += 2) {
                                const py = y - dy;
                                if (py < 0 || py >= size) continue;
                                for (let x = maxShift; x < size - maxShift; x += 2) {
                                    const px = x - dx;
                                    if (px < 0 || px >= size) continue;
                                    const a = previous.values[py * size + px];
                                    const b = current.values[y * size + x];
                                    if (a < .035 && b < .035) continue;
                                    ab += a * b; aa += a * a; bb += b * b; used += 1;
                                }
                            }
                            if (used < 24 || aa <= 0 || bb <= 0) continue;
                            const correlation = ab / Math.sqrt(aa * bb);
                            const density = clamp(used / 260, 0, 1);
                            const score = correlation * (.78 + density * .22);
                            if (!best || score > best.score) best = { dx, dy, correlation, score, used };
                        }
                    }
                    return best;
                }
                function radarMotionSample(matrix, x, y, radius = 2) {
                    const size = matrix.size;
                    let sum = 0, count = 0;
                    for (let oy = -radius; oy <= radius; oy += 1) {
                        for (let ox = -radius; ox <= radius; ox += 1) {
                            const sx = Math.round(x + ox), sy = Math.round(y + oy);
                            if (sx < 0 || sy < 0 || sx >= size || sy >= size) continue;
                            sum += matrix.values[sy * size + sx];
                            count += 1;
                        }
                    }
                    return count ? sum / count : 0;
                }
                function radarMotionDirection(degrees) {
                    return windDirection((degrees + 360) % 360);
                }
                function radarMotionPrediction(matrix, vector, frameMinutes) {
                    const center = matrix.centerIndex;
                    const threshold = .075;
                    const current = radarMotionSample(matrix, center, center, 2);
                    let firstWet = current >= threshold ? 0 : null;
                    let firstDry = null;
                    const points = [];
                    for (let minutes = 0; minutes <= 90; minutes += 5) {
                        const factor = minutes / Math.max(1, frameMinutes);
                        // To predict what reaches the location, sample the current field upwind
                        // along the inverse motion vector.
                        const x = center - vector.dx * factor;
                        const y = center - vector.dy * factor;
                        const signal = radarMotionSample(matrix, x, y, 2);
                        points.push({ minutes, signal });
                        if (firstWet === null && signal >= threshold) firstWet = minutes;
                        if (current >= threshold && firstDry === null && minutes > 0 && signal < threshold) firstDry = minutes;
                    }
                    return { current, firstWet, firstDry, points };
                }
                async function analyzeRadarMotion({ force = false } = {}) {
                    if (!isUiFeatureVisible('feature.radar.predictive')) return null;
                    if (state.radar.motionRequest) return state.radar.motionRequest;
                    if (!navigator.onLine || state.radar.liveFrames.length < 2) {
                        // Never surface a cached prediction as if it belonged to the current
                        // radar session/location. A predictive ETA is meaningful only after at
                        // least two recent frames have been acquired in this session.
                        state.radar.motion = {
                            analyzedAt: Date.now(),
                            unavailable: true,
                            offline: !navigator.onLine,
                            frameCount: state.radar.liveFrames.length,
                            confidence: 0,
                            etaMinutes: null,
                            exitMinutes: null,
                            points: []
                        };
                        renderRadarMotion();
                        return state.radar.motion;
                    }
                    const latest = state.radar.liveFrames.at(-1);
                    const previous = state.radar.liveFrames.at(-2);
                    const before = state.radar.liveFrames.at(-3) || null;
                    const signature = `${previous?.time || 0}:${latest?.time || 0}:${intelligenceLocationKey()}`;
                    if (!force && state.radar.motion?.signature === signature) {
                        renderRadarMotion();
                        return state.radar.motion;
                    }
                    state.radar.motionRequest = (async () => {
                        try {
                            const [latestMatrix, previousMatrix, beforeMatrix] = await Promise.all([
                                radarMotionMatrix(latest),
                                radarMotionMatrix(previous),
                                before ? radarMotionMatrix(before).catch(() => null) : Promise.resolve(null)
                            ]);
                            if (latestMatrix.active < 18 || previousMatrix.active < 18) {
                                state.radar.motion = {
                                    signature, analyzedAt: Date.now(), unavailable: true,
                                    reason: 'insufficient_signal', frameCount: state.radar.liveFrames.length,
                                    confidence: 0, dry: true, direction: '', speedKmh: null,
                                    etaMinutes: null, exitMinutes: null, points: []
                                };
                            } else {
                                const newestShift = radarBestShift(previousMatrix, latestMatrix);
                                if (!newestShift) throw new Error('RADAR_FLOW_UNAVAILABLE');
                                let dx = newestShift.dx, dy = newestShift.dy;
                                let consistency = .62;
                                if (beforeMatrix?.active >= 18) {
                                    const priorShift = radarBestShift(beforeMatrix, previousMatrix);
                                    if (priorShift) {
                                        const delta = Math.hypot(newestShift.dx - priorShift.dx, newestShift.dy - priorShift.dy);
                                        consistency = clamp(1 - delta / 12, .2, 1);
                                        dx = newestShift.dx * .68 + priorShift.dx * .32;
                                        dy = newestShift.dy * .68 + priorShift.dy * .32;
                                    }
                                }
                                const frameMinutes = clamp(Math.abs(Number(latest.time) - Number(previous.time)) / 60 || 10, 4, 20);
                                const prediction = radarMotionPrediction(latestMatrix, { dx, dy }, frameMinutes);
                                const matrixPixelOriginal = latestMatrix.cropPixels / latestMatrix.size;
                                const metersPerPixel = 156543.03392 * Math.cos(Number(state.location.latitude) * Math.PI / 180) / (2 ** latestMatrix.zoom);
                                const speedKmh = Math.hypot(dx, dy) * matrixPixelOriginal * metersPerPixel / (frameMinutes * 60) * 3.6;
                                const degrees = (Math.atan2(dx, -dy) * 180 / Math.PI + 360) % 360;
                                const signalConfidence = clamp((latestMatrix.active + previousMatrix.active) / 700, .15, 1);
                                const confidence = Math.round(clamp((newestShift.correlation * .58 + consistency * .27 + signalConfidence * .15) * 100, 28, 96));
                                state.radar.motion = {
                                    signature, analyzedAt: Date.now(), confidence,
                                    dry: latestMatrix.active < 24 && prediction.current < .05,
                                    direction: radarMotionDirection(degrees), directionDegrees: degrees,
                                    speedKmh: Math.round(speedKmh),
                                    etaMinutes: prediction.firstWet,
                                    exitMinutes: prediction.firstDry,
                                    rainingNow: prediction.current >= .075,
                                    vector: { dx: Number(dx.toFixed(2)), dy: Number(dy.toFixed(2)) },
                                    points: prediction.points.map(point => ({ minutes: point.minutes, signal: Number(point.signal.toFixed(3)) }))
                                };
                            }
                            saveJSON(STORAGE.radarMotion, state.radar.motion);
                        }
                        catch (error) {
                            console.warn('RADAR_MOTION_ANALYSIS_FAILED', error);
                            if (!state.radar.motion) state.radar.motion = { analyzedAt: Date.now(), confidence: 0, unavailable: true };
                        }
                        renderRadarMotion();
                        return state.radar.motion;
                    })();
                    try { return await state.radar.motionRequest; }
                    finally { state.radar.motionRequest = null; }
                }
                function radarPredictiveMinute(value) {
                    if (value === null || value === undefined || value === '') return null;
                    const minutes = Number(value);
                    return Number.isFinite(minutes) && minutes >= 0 ? Math.round(minutes) : null;
                }
                function radarPredictiveIntensity(signal) {
                    const value = Number(signal);
                    if (!Number.isFinite(value) || value <= .025) return { key: 'dry', level: 0 };
                    if (value < .10) return { key: 'weak', level: 1 };
                    if (value < .22) return { key: 'moderate', level: 2 };
                    return { key: 'strong', level: 3 };
                }
                function renderRadarMotion() {
                    const root = $('#radar-predictive-panel');
                    if (!root) return;
                    const motion = state.radar.motion;
                    const eta = $('#radar-predictive-eta');
                    const movement = $('#radar-predictive-movement');
                    const confidence = $('#radar-predictive-confidence');
                    const copy = $('#radar-predictive-copy');
                    const track = $('#radar-predictive-track');
                    const frameCount = Math.max(0, Number(motion?.frameCount ?? state.radar.liveFrames.length ?? 0));
        
                    const renderWaitingTrack = () => {
                        if (!track) return;
                        track.setAttribute('role', 'status');
                        track.setAttribute('aria-live', 'polite');
                        track.innerHTML = `<div class="radar-predictive-empty"><strong>${escapeHTML(t('radar.predictive.frames.title'))}</strong><span>${escapeHTML(t('radar.predictive.frames.progress', { current: Math.min(frameCount, 2), required: 2 }))}</span></div>`;
                    };
        
                    if (!motion || motion.unavailable || state.radar.liveFrames.length < 2) {
                        const weakSignal = motion?.reason === 'insufficient_signal' && state.radar.liveFrames.length >= 2;
                        if (eta) eta.textContent = weakSignal
                            ? t('radar.predictive.signal.no_cell')
                            : (motion?.offline ? t('radar.predictive.offline.short') : t('radar.predictive.frames.waiting'));
                        if (movement) movement.textContent = weakSignal ? t('radar.predictive.signal.motion_unavailable') : t('radar.predictive.motion.pending');
                        if (confidence) confidence.textContent = '--';
                        if (copy) copy.textContent = weakSignal
                            ? t('radar.predictive.signal.copy')
                            : (motion?.offline ? t('radar.predictive.offline.copy') : t('radar.predictive.frames.copy'));
                        if (weakSignal && track) {
                            track.setAttribute('role', 'status');
                            track.setAttribute('aria-live', 'polite');
                            track.innerHTML = `<div class="radar-predictive-empty"><strong>${escapeHTML(t('radar.predictive.signal.title'))}</strong><span>${escapeHTML(t('radar.predictive.signal.detail'))}</span></div>`;
                        } else {
                            renderWaitingTrack();
                        }
                        return;
                    }
        
                    const etaMinutes = radarPredictiveMinute(motion.etaMinutes);
                    const exitMinutes = radarPredictiveMinute(motion.exitMinutes);
                    if (eta) {
                        eta.textContent = motion.rainingNow
                            ? (exitMinutes !== null ? t('radar.predictive.exit', { minutes: exitMinutes }) : t('radar.predictive.raining'))
                            : (etaMinutes !== null ? t('radar.predictive.arrival', { minutes: etaMinutes }) : t('radar.predictive.no_arrival'));
                    }
        
                    const speed = Number(motion.speedKmh);
                    const moving = Number.isFinite(speed) && speed >= 2 && String(motion.direction || '').trim() !== '';
                    if (movement) movement.textContent = moving
                        ? t('radar.predictive.movement', { direction: motion.direction, speed: Math.round(speed) })
                        : t('radar.predictive.stationary');
        
                    const conf = Number(motion.confidence);
                    if (confidence) confidence.textContent = Number.isFinite(conf) && conf > 0 ? `${Math.round(conf)}%` : '--';
                    if (copy) copy.textContent = motion.dry ? t('radar.predictive.dry.copy') : t('radar.predictive.explanation');
        
                    if (track) {
                        const points = Array.isArray(motion.points) ? motion.points : [];
                        const selected = points.filter(point => Number(point.minutes) % 15 === 0);
                        if (!selected.length) {
                            renderWaitingTrack();
                            return;
                        }
                        track.setAttribute('role', 'list');
                        track.setAttribute('aria-label', t('radar.predictive.track.aria'));
                        track.removeAttribute('aria-live');
                        track.innerHTML = selected.map(point => {
                            const minutes = Math.max(0, Number(point.minutes || 0));
                            const signal = Number(point.signal || 0);
                            const intensity = radarPredictiveIntensity(signal);
                            const timeLabel = minutes === 0 ? t('radar.predictive.track.now') : t('radar.predictive.track.minutes', { minutes });
                            const intensityLabel = t(`radar.predictive.track.${intensity.key}`);
                            const itemLabel = t('radar.predictive.track.item_aria', { time: timeLabel, intensity: intensityLabel });
                            const level = clamp(signal / .35, 0, 1);
                            return `<span class="radar-motion-point" data-intensity="${intensity.key}" role="listitem" aria-label="${escapeHTML(itemLabel)}" style="--rain-level:${level.toFixed(3)}"><strong>${escapeHTML(timeLabel)}</strong><i><b></b></i><small>${escapeHTML(intensityLabel)}</small></span>`;
                        }).join('');
                    }
                }
        
                return Object.freeze({ lonLatToWorld, worldToLonLat, radarTileUrl, analyzeRadarMotion, renderRadarMotion });
            }
            provided.radarMotion = Object.freeze({ create });
        })();
        }
    });
}

export const serviceNames = PROVIDES;
