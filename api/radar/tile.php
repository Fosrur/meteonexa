<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/http_helpers.php';
require_once __DIR__ . '/metadata_helpers.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_method('GET');

$config = load_config();
$path = trim((string)($_GET['path'] ?? ''));
$z = filter_var($_GET['z'] ?? null, FILTER_VALIDATE_INT);
$x = filter_var($_GET['x'] ?? null, FILTER_VALIDATE_INT);
$y = filter_var($_GET['y'] ?? null, FILTER_VALIDATE_INT);
$color = filter_var($_GET['color'] ?? 2, FILTER_VALIDATE_INT);
$smooth = preg_match('/^[01]_[01]$/', (string)($_GET['smooth'] ?? '1_1')) ? (string)$_GET['smooth'] : '1_1';
$device = clean_device_id($_GET['deviceId'] ?? '');
$expires = filter_var($_GET['exp'] ?? null, FILTER_VALIDATE_INT);
$signature = trim((string)($_GET['sig'] ?? ''));
$frameSignature = trim((string)($_GET['frameSig'] ?? ''));

if (!preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]+$#', $path) || $z === false || $x === false || $y === false || $z < 0 || $z > 7 || $x < 0 || $y < 0) {
    respond(['ok' => false, 'code' => 'INVALID_TILE', 'message' => meteonexa_backend_text('api.backend.invalid_tile')], 400);
}
$max = (2 ** $z) - 1;
if ($x > $max || $y > $max) respond(['ok' => false, 'code' => 'INVALID_TILE_RANGE', 'message' => meteonexa_backend_text('api.backend.tile_out_of_range')], 400);
$color = max(0, min(8, (int)$color));
$signedValue = implode('|', [$device, $path, (int)$z, (int)$x, (int)$y, $color, $smooth]);
$frameSignedValue = $device . '|' . $path;
$validLegacyTileSignature = $expires !== false && $signature !== ''
    && meteonexa_verify_resource_signature($config, 'radar-tile-v2', $signedValue, (int)$expires, $signature);
$validFrameSignature = $expires !== false && $frameSignature !== ''
    && meteonexa_verify_resource_signature($config, 'radar-frame-v1', $frameSignedValue, (int)$expires, $frameSignature);
if (!$validLegacyTileSignature && !$validFrameSignature) {
    respond(['ok'=>false,'code'=>'INVALID_TILE_SIGNATURE','message'=>'api.security.device_access_denied'],403);
}

// Cache only already-authorized tile coordinates. This prevents a replay of a
// valid signed URL from repeatedly consuming the upstream radar provider while
// keeping signatures short-lived and tile-specific.
$tileCacheDir = meteonexa_storage_path() . '/provider-cache/radar-tiles';
$tileCacheKey = hash('sha256', implode('|', [$path,(int)$z,(int)$x,(int)$y,$color,$smooth]));
$tileCacheFile = $tileCacheDir . '/' . $tileCacheKey . '.png';
$emitPng = static function(string $body, string $cacheState): never {
    header_remove('Content-Type');
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=300, stale-while-revalidate=600');
    header('X-Content-Type-Options: nosniff');
    header('X-MeteoNexa-Provider-Cache: ' . $cacheState);
    echo $body;
    exit;
};
if (is_file($tileCacheFile) && time() - (int)filemtime($tileCacheFile) <= 300) {
    $cachedTile = @file_get_contents($tileCacheFile);
    if (is_string($cachedTile) && strlen($cachedTile) <= 2097152 && str_starts_with($cachedTile, "\x89PNG\r\n\x1a\n")) {
        $emitPng($cachedTile, 'HIT');
    }
}

try {
    $metadata = meteonexa_radar_metadata($config, 60);
    $host = rtrim((string)($metadata['host'] ?? ''), '/');
    $allowedPaths = array_column((array)($metadata['radar']['past'] ?? []), 'path');
    $isRadarTimestampPath = preg_match('#^/v[0-9]+/radar/[0-9]{9,12}$#', $path) === 1;
    if ($host === '' || (!$isRadarTimestampPath && !in_array($path, $allowedPaths, true))) throw new RuntimeException('RADAR_FRAME_UNAVAILABLE');
    $url = $host . $path . '/256/' . $z . '/' . $x . '/' . $y . '/' . $color . '/' . $smooth . '.png';
    // Only cache misses consume upstream quota. Replays of an already cached
    // authorized tile are cheap local reads.
    $pdo = meteonexa_db($config);
    require_ip_rate_limit($pdo, 'radar_tile_upstream_ip', 1500, 3600);
    require_global_rate_limit($pdo, 'radar_tile_upstream_global', (int)($config['abuse_limits']['radar_tile_upstream_global_hour'] ?? 5000), 3600);
    $response = meteonexa_http_request($url, ['timeout' => 18, 'max_bytes'=>2097152, 'pin_dns'=>true, 'headers' => ['Accept: image/png,image/*;q=0.8']]);
    if ($response['status'] < 200 || $response['status'] >= 300 || !str_starts_with(strtolower((string)$response['content_type']), 'image/') || !str_starts_with((string)$response['body'], "\x89PNG\r\n\x1a\n")) {
        throw new RuntimeException('RADAR_TILE_UNAVAILABLE');
    }
    if (!is_dir($tileCacheDir)) @mkdir($tileCacheDir, 0770, true);
    if (is_dir($tileCacheDir)) {
        meteonexa_atomic_write($tileCacheFile, (string)$response['body'], 0660);
        if (random_int(1, 50) === 1) {
            $files = array_values(array_filter(glob($tileCacheDir . '/*.png') ?: [], 'is_file'));
            foreach ($files as $file) if (time() - (int)filemtime($file) > 900) @unlink($file);
            $files = array_values(array_filter(glob($tileCacheDir . '/*.png') ?: [], 'is_file'));
            if (count($files) > 1500) {
                usort($files, static fn(string $a, string $b): int => ((int)filemtime($a)) <=> ((int)filemtime($b)));
                foreach (array_slice($files, 0, count($files) - 1500) as $file) @unlink($file);
            }
        }
    }
    $emitPng((string)$response['body'], 'MISS');
} catch (Throwable $error) {
    http_response_code(200);
    header_remove('Content-Type');
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    header('X-MeteoNexa-Tile-Fallback: 1');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256"></svg>';
    exit;
}
