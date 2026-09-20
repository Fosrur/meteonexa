<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once dirname(__DIR__) . '/intelligence/verification_helpers.php';
require_once __DIR__ . '/weather_engine.php';

assert_same_origin();
require_method('POST');
$config = load_config();
$pdo = meteonexa_db($config);
$device = clean_device_id($_SERVER['HTTP_X_METEONEXA_DEVICE_ID'] ?? '');
require_authenticated_device_session($pdo, $config, $device);
require_ip_rate_limit($pdo, 'route_weather_ip', 120, 3600);
require_device_rate_limit($pdo, 'route_weather_device', $device, 90, 3600);

$input = input_json();
$points = meteonexa_route_points(is_array($input['points'] ?? null) ? $input['points'] : []);
if (count($points) < 2) respond(['ok' => false, 'code' => 'ROUTE_POINTS_INVALID', 'message' => 'api.backend.route_unavailable'], 422);
$departure = trim((string)($input['departureAt'] ?? gmdate('c')));
$duration = max(30, min(720, (int)($input['durationMinutes'] ?? 120)));
$mode = clean_text($input['mode'] ?? 'car', 20, 'car');
if (!in_array($mode, ['car', 'motorcycle', 'bike', 'walk', 'trekking'], true)) $mode = 'car';

try {
    $analysis = meteonexa_route_analyze($points, $departure, $duration, $mode, null, true);
    $weather = is_array($analysis['weather'] ?? null) ? $analysis['weather'] : [];
    $startTs = meteonexa_intel_time_utc((string)($analysis['departureAt'] ?? $departure)) ?? time();
    $mid = $points[(int)floor((count($points) - 1) / 2)];
    $locationKey = meteonexa_intelligence_location_key((float)$mid['latitude'], (float)$mid['longitude']);
    meteonexa_queue_decision_verification($pdo, $device, $locationKey, 'route', 'route-weather', gmdate('c', $startTs), gmdate('c', $startTs + $duration * 60), (float)($analysis['selectedSafetyScore'] ?? 0), [
        'sampleCount' => count($points),
        'riskScore' => (float)($analysis['selectedRisk'] ?? 0),
        'departureAt' => gmdate('c', $startTs),
        'durationMinutes' => $duration,
    ]);
    meteonexa_record_runtime_metric($pdo, 'route', 'weather', 'ok', (float)($analysis['selectedRisk'] ?? 0), ['sampleCount' => count($points)]);
    respond([
        'ok' => true,
        'engine' => 'route-weather',
        'points' => $points,
        'weather' => $weather,
        'sampleCount' => count($points),
        'decision' => [
            'status' => $analysis['status'] ?? null,
            'selectedRisk' => $analysis['selectedRisk'] ?? null,
            'selectedSafetyScore' => $analysis['selectedSafetyScore'] ?? null,
            'criticalSegment' => $analysis['criticalSegment'] ?? null,
            'bestDeparture' => $analysis['bestDeparture'] ?? null,
            'improvementPoints' => $analysis['improvementPoints'] ?? null,
        ],
        'verification' => [
            'queued' => true,
            'locationKey' => $locationKey,
            'startsAt' => gmdate('c', $startTs),
            'endsAt' => gmdate('c', $startTs + $duration * 60),
            'recommendationScore' => (float)($analysis['selectedSafetyScore'] ?? 0),
        ],
        'source' => 'Open-Meteo',
        'privacy' => [
            'routeCoordinatesProcessedServerSide' => true,
            'thirdPartyReceivesSampledRouteCoordinates' => true,
            'browserDirectThirdPartyRouteRequest' => false,
        ],
        'generatedAt' => gmdate('c'),
    ]);
} catch (Throwable $error) {
    meteonexa_record_runtime_metric($pdo, 'route', 'weather', 'error', null, ['class' => get_class($error)]);
    respond(['ok' => false, 'code' => 'ROUTE_WEATHER_UNAVAILABLE', 'message' => 'api.backend.route_unavailable'], 503);
}
