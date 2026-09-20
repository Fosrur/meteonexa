<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/backend_i18n.php';
require_once dirname(__DIR__) . '/http_helpers.php';
require_once dirname(__DIR__) . '/public_helpers.php';

function meteonexa_radar_allowed_hosts(array $config): array
{
    $hosts = array_values(array_filter(array_map(static fn($value): string => strtolower(trim((string)$value)), (array)($config['radar']['allowed_hosts'] ?? []))));
    if ($hosts === []) $hosts = ['api.librewxr.net'];
    return $hosts;
}

function meteonexa_validate_radar_metadata(array $data, array $config): array
{
    $host = trim((string)($data['host'] ?? ''));
    if ($host === '' || empty($data['radar']['past'])) {
        throw new RuntimeException(meteonexa_backend_text('api.backend.radar_metadata_incomplete'));
    }
    meteonexa_validate_remote_url($host, meteonexa_radar_allowed_hosts($config));
    return $data;
}

function meteonexa_radar_metadata(array $config, int $maxAgeSeconds = 60): array
{
    $cachePath = meteonexa_storage_path() . '/radar-metadata-cache.json';
    if (is_file($cachePath) && time() - (int)filemtime($cachePath) <= $maxAgeSeconds) {
        $cached = json_decode((string)@file_get_contents($cachePath), true);
        if (is_array($cached)) {
            try { return meteonexa_validate_radar_metadata($cached, $config); }
            catch (Throwable $error) { /* discard unsafe/stale cache */ }
        }
    }
    $primary = trim((string)($config['radar']['metadata_url'] ?? 'https://api.librewxr.net/public/weather-maps.json'));
    $fallback = trim((string)($config['radar']['fallback_metadata_url'] ?? ''));
    $endpoints = array_values(array_unique(array_filter([$primary,$fallback])));
    $lastError = null; $data = null;
    foreach ($endpoints as $endpoint) {
        try {
            meteonexa_validate_remote_url($endpoint, meteonexa_radar_allowed_hosts($config));
            $candidate = meteonexa_http_json($endpoint, ['timeout' => 12, 'max_bytes'=>1048576, 'pin_dns'=>true]);
            $data = meteonexa_validate_radar_metadata($candidate, $config);
            $data['_meteonexa_metadata_source'] = $endpoint;
            break;
        } catch (Throwable $error) { $lastError = $error; }
    }
    if (!is_array($data)) throw ($lastError ?: new RuntimeException(meteonexa_backend_text('api.backend.radar_metadata_incomplete')));
    $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (is_string($encoded) && function_exists('meteonexa_atomic_write')) {
        meteonexa_atomic_write($cachePath, $encoded, 0660);
    }
    return $data;
}
