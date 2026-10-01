const PROVIDES = Object.freeze(['copilot']);
export const dependencies = Object.freeze([]);

export function install(services, host = globalThis) {
    return services.installModule({
        services,
        host,
        provides: PROVIDES,
        dependencies,
        factory(window, deps, provided) {
        (() => {
            const finite = value => Number.isFinite(Number(value)) ? Number(value) : null;
            function routePlan(route) {
                if (!route || !Array.isArray(route.points) || route.points.length < 2) return null;
                const points = route.points.slice(0, 24).map((point, index, rows) => ({
                    latitude: finite(point.latitude),
                    longitude: finite(point.longitude),
                    ratio: finite(point.ratio) ?? (rows.length > 1 ? index / (rows.length - 1) : 0),
                    bearing: finite(point.bearing) ?? 0,
                    label: String(point.name || '').slice(0, 80)
                })).filter(point => point.latitude !== null && point.longitude !== null && Math.abs(point.latitude) <= 90 && Math.abs(point.longitude) <= 180);
                if (points.length < 2) return null;
                const departure = route.departure instanceof Date ? route.departure : new Date(route.departure || Date.now());
                return {
                    origin: String(route.origin?.name || '').slice(0, 120),
                    destination: String(route.destination?.name || '').slice(0, 120),
                    mode: ['car', 'motorcycle', 'bike', 'walk', 'trekking'].includes(String(route.mode || '')) ? String(route.mode) : 'car',
                    departureAt: Number.isNaN(departure.getTime()) ? new Date().toISOString() : departure.toISOString(),
                    durationMinutes: Math.max(30, Math.min(720, Math.round(Number(route.durationHours || 2) * 60))),
                    points
                };
            }
            function normalizeDecision(raw) {
                const value = raw && typeof raw === 'object' ? raw : {};
                const route = value.route && typeof value.route === 'object' ? value.route : null;
                const critical = route?.criticalSegment && typeof route.criticalSegment === 'object' ? route.criticalSegment : null;
                return {
                    available: Boolean(value.status || value.score !== undefined || route),
                    status: ['good', 'caution', 'avoid', 'learning'].includes(String(value.status || '')) ? String(value.status) : 'learning',
                    score: finite(value.score),
                    confidence: finite(value.confidence),
                    dominantRisk: String(value.dominantRisk || 'none').slice(0, 32),
                    activity: String(value.activity || '').slice(0, 32),
                    bestWindow: value.bestWindow || null,
                    alternativeWindow: value.alternativeWindow || null,
                    route: route ? {
                        selectedRisk: finite(route.selectedRisk),
                        selectedSafetyScore: finite(route.selectedSafetyScore),
                        bestDeparture: route.bestDeparture || null,
                        bestRisk: finite(route.bestRisk),
                        improvementPoints: finite(route.improvementPoints),
                        sampleCount: finite(route.sampleCount),
                        criticalSegment: critical ? {
                            ratio: finite(critical.ratio),
                            at: critical.at || null,
                            risk: finite(critical.risk),
                            rainProbability: finite(critical.rainProbability),
                            gustKmh: finite(critical.gustKmh),
                            crosswindKmh: finite(critical.crosswindKmh),
                            visibilityKm: finite(critical.visibilityKm),
                            reasons: Array.isArray(critical.reasons) ? critical.reasons.slice(0, 6).map(String) : []
                        } : null
                    } : null,
                    sourceCount: finite(value.sourceCount),
                    generatedAt: value.generatedAt || null
                };
            }
            provided.copilot = Object.freeze({ routePlan, normalizeDecision });
        })();
        }
    });
}

export const serviceNames = PROVIDES;
