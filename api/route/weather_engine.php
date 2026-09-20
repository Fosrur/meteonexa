<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/http_helpers.php';
require_once dirname(__DIR__) . '/intelligence/engine_helpers.php';

function meteonexa_route_clamp(float $value, float $min = 0.0, float $max = 100.0): float
{
    return max($min, min($max, $value));
}

function meteonexa_route_points(array $raw, int $limit = 24): array
{
    $points = [];
    foreach (array_slice($raw, 0, max(2, min(24, $limit))) as $row) {
        if (!is_array($row) || !is_numeric($row['latitude'] ?? null) || !is_numeric($row['longitude'] ?? null)) continue;
        $lat = (float)$row['latitude'];
        $lon = (float)$row['longitude'];
        if (abs($lat) > 90 || abs($lon) > 180) continue;
        $points[] = [
            'latitude' => round($lat, 5),
            'longitude' => round($lon, 5),
            'ratio' => is_numeric($row['ratio'] ?? null) ? meteonexa_route_clamp((float)$row['ratio'], 0, 1) : null,
            'bearing' => is_numeric($row['bearing'] ?? null) ? round((float)$row['bearing'], 1) : 0.0,
            'label' => trim(substr((string)($row['label'] ?? ''), 0, 80)),
        ];
    }
    if (count($points) < 2) return [];
    $last = max(1, count($points) - 1);
    foreach ($points as $index => &$point) {
        if ($point['ratio'] === null) $point['ratio'] = $index / $last;
    }
    unset($point);
    return $points;
}

function meteonexa_route_fetch_weather(array $points): array
{
    if (count($points) < 2 || count($points) > 24) throw new InvalidArgumentException('ROUTE_POINTS_INVALID');
    $params = http_build_query([
        'latitude' => implode(',', array_column($points, 'latitude')),
        'longitude' => implode(',', array_column($points, 'longitude')),
        'hourly' => 'temperature_2m,apparent_temperature,precipitation_probability,precipitation,snowfall,weather_code,visibility,wind_speed_10m,wind_direction_10m,wind_gusts_10m,cape,relative_humidity_2m,uv_index',
        'timezone' => 'UTC',
        'forecast_days' => 4,
        'wind_speed_unit' => 'kmh',
    ], '', '&', PHP_QUERY_RFC3986);
    $payload = meteonexa_http_json('https://api.open-meteo.com/v1/forecast?' . $params, ['timeout' => 24, 'max_bytes' => 4200000]);
    $weather = array_is_list($payload) ? $payload : [$payload];
    if (count($weather) !== count($points)) throw new RuntimeException('ROUTE_WEATHER_COUNT');
    return $weather;
}

function meteonexa_route_nearest_index(array $times, int $target): int
{
    $best = 0; $distance = PHP_INT_MAX;
    foreach ($times as $index => $time) {
        $ts = meteonexa_intel_time_utc((string)$time);
        if ($ts === null) continue;
        $delta = abs($ts - $target);
        if ($delta < $distance) { $distance = $delta; $best = (int)$index; }
    }
    return $best;
}

function meteonexa_route_point_risk(array $row, int $index, float $bearing, string $mode, ?array $thresholds = null): array
{
    $h = is_array($row['hourly'] ?? null) ? $row['hourly'] : [];
    $value = static fn(string $key, float $default = 0.0): float => is_numeric($h[$key][$index] ?? null) ? (float)$h[$key][$index] : $default;
    $temp = $value('temperature_2m');
    $rain = $value('precipitation_probability');
    $mm = $value('precipitation');
    $snow = $value('snowfall');
    $wind = $value('wind_speed_10m');
    $gust = $value('wind_gusts_10m');
    $windDirection = $value('wind_direction_10m');
    $visibility = max(0.0, $value('visibility') / 1000.0);
    $cape = $value('cape');
    $humidity = $value('relative_humidity_2m');
    $uv = $value('uv_index');
    $code = (int)round($value('weather_code'));
    $crosswind = $wind * abs(sin(deg2rad($windDirection - $bearing)));
    $ice = $temp <= 2 && ($mm > .05 || $snow > 0);
    $thunder = $cape >= 800 || in_array($code, [95, 96, 99], true);
    $score = max(
        $rain * .65,
        min(100, $gust * 1.2),
        $visibility < 1 ? 95 : ($visibility < 3 ? 70 : 0),
        $ice ? 90 : 0,
        $thunder ? 90 : 0,
        min(100, $crosswind * (in_array($mode, ['bike', 'motorcycle'], true) ? 2.2 : 1.2))
    );
    if (is_array($thresholds)) {
        $rainMax = (float)($thresholds['rainMax'] ?? 35);
        $gustMax = (float)($thresholds['gustMax'] ?? 45);
        $tempMin = (float)($thresholds['tempMin'] ?? -10);
        $tempMax = (float)($thresholds['tempMax'] ?? 40);
        $visibilityMin = (float)($thresholds['visibilityMin'] ?? 3);
        $personal = max(
            $rain > $rainMax ? 55 + min(45, ($rain - $rainMax) * 1.2) : 0,
            $gust > $gustMax ? 55 + min(45, ($gust - $gustMax) * 2) : 0,
            $temp < $tempMin ? 55 + min(45, ($tempMin - $temp) * 6) : 0,
            $temp > $tempMax ? 55 + min(45, ($temp - $tempMax) * 6) : 0,
            $visibility < $visibilityMin ? 55 + min(45, ($visibilityMin - $visibility) * 12) : 0
        );
        $score = max($score, $personal);
    }
    $reasons = [];
    if ($thunder) $reasons[] = 'storm';
    if ($ice) $reasons[] = 'ice';
    if ($rain >= 55 || $mm >= 1) $reasons[] = 'rain';
    if ($gust >= 45) $reasons[] = 'gust';
    if ($crosswind >= 25) $reasons[] = 'crosswind';
    if ($visibility > 0 && $visibility < 3) $reasons[] = 'visibility';
    return [
        'risk' => round(meteonexa_route_clamp($score), 1),
        'temperatureC' => round($temp, 1),
        'rainProbability' => (int)round(meteonexa_route_clamp($rain)),
        'precipitationMm' => round(max(0, $mm), 2),
        'snowfallCm' => round(max(0, $snow), 2),
        'windKmh' => round(max(0, $wind), 1),
        'gustKmh' => round(max(0, $gust), 1),
        'crosswindKmh' => round(max(0, $crosswind), 1),
        'visibilityKm' => round($visibility, 1),
        'cape' => round(max(0, $cape)),
        'humidity' => (int)round(meteonexa_route_clamp($humidity)),
        'uvIndex' => round(max(0, $uv), 1),
        'ice' => $ice,
        'thunder' => $thunder,
        'reasons' => array_values(array_unique($reasons)),
    ];
}

function meteonexa_route_evaluate(array $points, array $weather, int $startTs, int $durationMinutes, string $mode, ?array $thresholds = null): array
{
    $durationSeconds = max(60, $durationMinutes * 60);
    $rows = [];
    foreach ($points as $index => $point) {
        $at = $startTs + (int)round($durationSeconds * (float)($point['ratio'] ?? 0));
        $times = (array)($weather[$index]['hourly']['time'] ?? []);
        $nearest = meteonexa_route_nearest_index($times, $at);
        $risk = meteonexa_route_point_risk((array)($weather[$index] ?? []), $nearest, (float)($point['bearing'] ?? 0), $mode, $thresholds);
        $rows[] = array_merge([
            'index' => $index,
            'ratio' => round((float)($point['ratio'] ?? 0), 4),
            'label' => (string)($point['label'] ?? ''),
            'at' => gmdate('c', $at),
        ], $risk);
    }
    $critical = null;
    foreach ($rows as $row) if ($critical === null || (float)$row['risk'] > (float)$critical['risk']) $critical = $row;
    $risk = $critical ? (float)$critical['risk'] : 0.0;
    return ['risk' => round($risk, 1), 'safetyScore' => (int)round(100 - $risk), 'critical' => $critical, 'rows' => $rows];
}

function meteonexa_route_analyze(array $rawPoints, string $departureAt, int $durationMinutes, string $mode = 'car', ?array $thresholds = null, bool $includeWeather = false): array
{
    $points = meteonexa_route_points($rawPoints);
    if (count($points) < 2) throw new InvalidArgumentException('ROUTE_POINTS_INVALID');
    if (!in_array($mode, ['car', 'motorcycle', 'bike', 'walk', 'trekking'], true)) $mode = 'car';
    $durationMinutes = max(30, min(720, $durationMinutes));
    $startTs = meteonexa_intel_time_utc($departureAt) ?? time();
    $weather = meteonexa_route_fetch_weather($points);
    $selected = meteonexa_route_evaluate($points, $weather, $startTs, $durationMinutes, $mode, $thresholds);
    $candidates = [];
    for ($offset = -2; $offset <= 12; $offset++) {
        $candidateTs = $startTs + $offset * 1800;
        if ($candidateTs < time() - 1800) continue;
        $evaluation = meteonexa_route_evaluate($points, $weather, $candidateTs, $durationMinutes, $mode, $thresholds);
        $candidates[] = ['at' => gmdate('c', $candidateTs), 'risk' => $evaluation['risk'], 'safetyScore' => $evaluation['safetyScore'], 'critical' => $evaluation['critical']];
    }
    usort($candidates, static fn($a, $b) => [$a['risk'], $a['at']] <=> [$b['risk'], $b['at']]);
    $best = $candidates[0] ?? ['at' => gmdate('c', $startTs), 'risk' => $selected['risk'], 'safetyScore' => $selected['safetyScore'], 'critical' => $selected['critical']];
    $improvement = max(0, (float)$selected['risk'] - (float)$best['risk']);
    $status = $selected['risk'] >= 70 ? 'avoid' : ($selected['risk'] >= 40 ? 'caution' : 'good');
    $result = [
        'available' => true,
        'method' => 'route-weather-orchestration',
        'mode' => $mode,
        'departureAt' => gmdate('c', $startTs),
        'durationMinutes' => $durationMinutes,
        'sampleCount' => count($points),
        'selectedRisk' => $selected['risk'],
        'selectedSafetyScore' => $selected['safetyScore'],
        'status' => $status,
        'criticalSegment' => $selected['critical'],
        'bestDeparture' => $best,
        'improvementPoints' => round($improvement, 1),
        'candidateCount' => count($candidates),
        'candidates' => array_slice($candidates, 0, 8),
        'source' => 'Open-Meteo',
        'privacy' => ['coordinatesProcessedServerSide' => true, 'coordinatesExcludedFromLlm' => true],
        'generatedAt' => gmdate('c'),
    ];
    if ($includeWeather) $result['weather'] = $weather;
    return $result;
}
