<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once dirname(__DIR__) . '/http_helpers.php';

require_method('GET');
assert_same_origin();
$config = load_config();
$pdo = meteonexa_db($config);
require_ip_rate_limit($pdo, 'lightning_ip', 120, 3600);
require_global_rate_limit($pdo, 'lightning_global', (int)($config['abuse_limits']['lightning_global_hour'] ?? 1200), 3600);
$device = clean_device_id($_SERVER['HTTP_X_METEONEXA_DEVICE_ID'] ?? '');
require_device_access($pdo, $device);
require_device_rate_limit($pdo, 'lightning_device', $device, 120, 3600);
$lat = query_float('lat', 999);
$lon = query_float('lon', 999);
if (abs($lat) > 90 || abs($lon) > 180) {
    respond(['ok'=>false, 'code'=>'INVALID_COORDINATES', 'message'=>meteonexa_backend_text('api.backend.invalid_coordinates')], 422);
}
$client = trim((string)($config['lightning']['client_id'] ?? ''));
$secret = trim((string)($config['lightning']['client_secret'] ?? ''));
if ($client === '' || $secret === '') {
    respond(['ok'=>false, 'code'=>'LIGHTNING_NOT_CONFIGURED', 'message'=>meteonexa_backend_text('api.backend.lightning_not_configured'), 'configured'=>false], 503);
}
$radius = max(1, min(100, query_int('radius', (int)($config['lightning']['radius_km'] ?? 100))));
$limit = max(1, min(500, query_int('limit', 250)));
$cacheDir = meteonexa_storage_path() . '/provider-cache';
$cacheKey = hash('sha256', number_format($lat, 2, '.', '') . ':' . number_format($lon, 2, '.', '') . ':' . $radius . ':' . $limit);
$cacheFile = $cacheDir . '/lightning-' . $cacheKey . '.json';
if (is_dir($cacheDir) && random_int(1, 20) === 1) {
    $candidates = glob($cacheDir . '/lightning-*.json') ?: [];
    foreach ($candidates as $candidate) {
        if (is_file($candidate) && time() - (int)filemtime($candidate) > 600) @unlink($candidate);
    }
    $candidates = array_values(array_filter(glob($cacheDir . '/lightning-*.json') ?: [], 'is_file'));
    if (count($candidates) > 500) {
        usort($candidates, static fn(string $a, string $b): int => ((int)filemtime($a)) <=> ((int)filemtime($b)));
        foreach (array_slice($candidates, 0, count($candidates) - 500) as $candidate) @unlink($candidate);
    }
}
if (is_file($cacheFile) && time() - (int)filemtime($cacheFile) <= 45) {
    $cached = json_decode((string)@file_get_contents($cacheFile), true);
    if (is_array($cached)) respond($cached + ['cached'=>true]);
}
$place = $lat . ',' . $lon;
$url = 'https://data.api.xweather.com/lightning/closest?p=' . rawurlencode($place)
    . '&radius=' . $radius . 'km&limit=' . $limit
    . '&client_id=' . rawurlencode($client) . '&client_secret=' . rawurlencode($secret);
try {
    $raw = meteonexa_http_json($url, ['timeout'=>18,'max_bytes'=>2097152]);
    $response = $raw['response'] ?? [];
    $strikes = [];
    $cutoff = time() - 300;
    foreach (is_array($response) ? $response : [] as $item) {
        $loc = is_array($item['loc'] ?? null) ? $item['loc'] : [];
        $ob = is_array($item['ob'] ?? null) ? $item['ob'] : [];
        $relative = is_array($item['relativeTo'] ?? null) ? $item['relativeTo'] : [];
        $timestamp = (int)($ob['timestamp'] ?? 0);
        $strikeLat = (float)($loc['lat'] ?? 999);
        $strikeLon = (float)($loc['long'] ?? 999);
        if ($timestamp < $cutoff || abs($strikeLat) > 90 || abs($strikeLon) > 180) continue;
        $strikes[] = [
            'latitude'=>$strikeLat,
            'longitude'=>$strikeLon,
            'timestamp'=>$timestamp,
            'ageSeconds'=>max(0, time() - $timestamp),
            'polarity'=>(string)($ob['polarity'] ?? ''),
            'amperage'=>(float)($ob['amperage'] ?? 0),
            'pulseType'=>(string)($ob['pulseType'] ?? ''),
            'distanceKm'=>(float)($relative['distanceKM'] ?? 0),
            'bearing'=>(float)($relative['bearing'] ?? 0),
        ];
    }
    $payload = ['ok'=>true, 'configured'=>true, 'source'=>meteonexa_backend_text('provider.xweather_lightning'), 'radiusKm'=>$radius, 'strikes'=>$strikes, 'updatedAt'=>time(), 'cached'=>false];
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (is_string($encoded)) meteonexa_atomic_write($cacheFile, $encoded, 0660);
    respond($payload);
} catch (Throwable $error) {
    respond(['ok'=>false, 'code'=>'LIGHTNING_PROVIDER_ERROR', 'message'=>meteonexa_backend_text('api.backend.lightning_provider_error'), 'configured'=>true], 502);
}
