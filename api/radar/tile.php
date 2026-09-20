<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/http_helpers.php';
require_once __DIR__ . '/metadata_helpers.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_method('GET');

$config = load_config();
$host = rtrim(trim((string)($_GET['host'] ?? '')), '/');
$path = trim((string)($_GET['path'] ?? ''));
$z = filter_var($_GET['z'] ?? null, FILTER_VALIDATE_INT);
$x = filter_var($_GET['x'] ?? null, FILTER_VALIDATE_INT);
$y = filter_var($_GET['y'] ?? null, FILTER_VALIDATE_INT);
$color = filter_var($_GET['color'] ?? 10, FILTER_VALIDATE_INT);
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
$color = max(0, min(12, (int)$color));
$signedValue = implode('|', [$device, $path, (int)$z, (int)$x, (int)$y, $color, $smooth]);
$frameSignedValue = $device . '|' . $host . '|' . $path;
$validLegacyTileSignature = $expires !== false && $signature !== ''
    && meteonexa_verify_resource_signature($config, 'radar-tile-v2', $signedValue, (int)$expires, $signature);
$validFrameSignature = $expires !== false && $frameSignature !== '' && $host !== ''
    && meteonexa_verify_resource_signature($config, 'radar-frame-v2', $frameSignedValue, (int)$expires, $frameSignature);
if (!$validLegacyTileSignature && !$validFrameSignature) {
    respond(['ok'=>false,'code'=>'INVALID_TILE_SIGNATURE','message'=>'api.security.device_access_denied'],403);
}
if ($validFrameSignature) meteonexa_validate_remote_url($host . '/', meteonexa_radar_allowed_hosts($config));

// Cache only already-authorized tile coordinates. This prevents a replay of a
// valid signed URL from repeatedly consuming the upstream radar provider while
// keeping signatures short-lived and tile-specific.
$tileCacheDir = meteonexa_storage_path() . '/provider-cache/radar-tiles';
$tileCacheKey = hash('sha256', implode('|', [$path,(int)$z,(int)$x,(int)$y,$color,$smooth]));
$tileCacheFile = $tileCacheDir . '/' . $tileCacheKey . '.png';
$emitPng = static function(string $body, string $cacheState): never {
    header_remove('Content-Type');
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=1800, stale-while-revalidate=21600');
    header('X-Content-Type-Options: nosniff');
    header('X-MeteoNexa-Provider-Cache: ' . $cacheState);
    echo $body;
    exit;
};
if (is_file($tileCacheFile) && time() - (int)filemtime($tileCacheFile) <= 1800) {
    $cachedTile = @file_get_contents($tileCacheFile);
    if (is_string($cachedTile) && strlen($cachedTile) <= 2097152 && str_starts_with($cachedTile, "\x89PNG\r\n\x1a\n")) {
        $emitPng($cachedTile, 'HIT');
    }
}

try {
    if (!$validFrameSignature) {
        // Compatibility path only for old tabs carrying v1 tile signatures.
        $metadata = meteonexa_radar_metadata($config, 60);
        $host = rtrim((string)($metadata['host'] ?? ''), '/');
        $allowedPaths = array_column((array)($metadata['radar']['past'] ?? []), 'path');
        if ($host === '' || !in_array($path, $allowedPaths, true)) throw new RuntimeException('RADAR_FRAME_UNAVAILABLE');
    }
    $url = $host . $path . '/256/' . $z . '/' . $x . '/' . $y . '/' . $color . '/' . $smooth . '.png';
    // Only cache misses consume upstream quota. Replays of an already cached
    // authorized tile are cheap local reads.
    $pdo = meteonexa_db($config);
    require_ip_rate_limit($pdo, 'radar_tile_upstream_ip', 1500, 3600);
    require_global_rate_limit($pdo, 'radar_tile_upstream_global', (int)($config['abuse_limits']['radar_tile_upstream_global_hour'] ?? 30000), 3600);
    $response = meteonexa_http_request($url, ['timeout' => 18, 'max_bytes'=>2097152, 'pin_dns'=>true, 'headers' => ['Accept: image/png,image/*;q=0.8']]);
    if ($response['status'] < 200 || $response['status'] >= 300 || !str_starts_with(strtolower((string)$response['content_type']), 'image/') || !str_starts_with((string)$response['body'], "\x89PNG\r\n\x1a\n")) {
        throw new RuntimeException('RADAR_TILE_UNAVAILABLE');
    }
    if (!is_dir($tileCacheDir)) @mkdir($tileCacheDir, 0770, true);
    if (is_dir($tileCacheDir)) {
        meteonexa_atomic_write($tileCacheFile, (string)$response['body'], 0660);
        if (random_int(1, 50) === 1) {
            $files = array_values(array_filter(glob($tileCacheDir . '/*.png') ?: [], 'is_file'));
            foreach ($files as $file) if (time() - (int)filemtime($file) > 21600) @unlink($file);
            $files = array_values(array_filter(glob($tileCacheDir . '/*.png') ?: [], 'is_file'));
            if (count($files) > 12000) {
                usort($files, static fn(string $a, string $b): int => ((int)filemtime($a)) <=> ((int)filemtime($b)));
                foreach (array_slice($files, 0, count($files) - 12000) as $file) @unlink($file);
            }
        }
    }
    $emitPng((string)$response['body'], 'MISS');
} catch (Throwable $error) {
    // A transparent HTTP 200 tile hides provider failures from both MapLibre and
    // the fallback <img> renderer, making a broken radar look like a valid dry
    // frame. Prefer a bounded stale tile when available; otherwise return an
    // explicit non-2xx image response so the client can surface/retry the error.
    if (is_file($tileCacheFile) && time() - (int)filemtime($tileCacheFile) <= 21600) {
        $staleTile = @file_get_contents($tileCacheFile);
        if (is_string($staleTile) && strlen($staleTile) <= 2097152 && str_starts_with($staleTile, "\x89PNG\r\n\x1a\n")) {
            $emitPng($staleTile, 'STALE');
        }
    }
    http_response_code(502);
    header_remove('Content-Type');
    header('Content-Type: image/png');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-MeteoNexa-Tile-Fallback: provider-error');
    // Valid 1x1 transparent PNG body; the 502 status still fires browser/map
    // error handling and never masquerades as successful precipitation data.
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true) ?: '';
    exit;
}
