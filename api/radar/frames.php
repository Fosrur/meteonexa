<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/backend_i18n.php';
require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/metadata_helpers.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_method('GET');

assert_same_origin();
$config = load_config();
$pdo = meteonexa_db($config);
require_ip_rate_limit($pdo, 'radar_frames_ip', 240, 3600);
$device = clean_device_id($_SERVER['HTTP_X_METEONEXA_DEVICE_ID'] ?? '');
require_device_access($pdo, $device);
require_device_rate_limit($pdo, 'radar_frames_device', $device, 120, 3600);

try {
    [$latitude, $longitude] = meteonexa_validate_coordinates($_GET['lat'] ?? null, $_GET['lon'] ?? null);
    $data = meteonexa_radar_metadata($config, 45);
    $host = trim((string)($data['host'] ?? ''));
    $past = array_values(array_filter((array)($data['radar']['past'] ?? []), static fn($row): bool => is_array($row) && isset($row['path'], $row['time'])));
    if ($host === '' || count($past) < 2) throw new RuntimeException(meteonexa_backend_text('api.backend.radar_frames_insufficient'));
    $past = array_slice($past, -8);
    $expires = time() + 600;
    $zoom = 7;
    $color = 10;
    $smooth = '1_1';
    [$centerX, $centerY] = meteonexa_tile_coordinates($latitude, $longitude, $zoom);
    $scale = 2 ** $zoom;

    $past = array_map(static function(array $frame) use ($config, $device, $host, $expires, $zoom, $color, $smooth, $centerX, $centerY, $scale): array {
        $path = (string)($frame['path'] ?? '');
        $signatures = [];
        for ($oy = -1; $oy <= 1; $oy++) {
            for ($ox = -1; $ox <= 1; $ox++) {
                $x = ($centerX + $ox + $scale) % $scale;
                $y = max(0, min($scale - 1, $centerY + $oy));
                $key = $zoom . ':' . $x . ':' . $y;
                $signedValue = implode('|', [$device, $path, $zoom, $x, $y, $color, $smooth]);
                $signatures[$key] = meteonexa_sign_resource($config, 'radar-tile-v2', $signedValue, $expires);
            }
        }
        $frame['tileExpires'] = $expires;
        $frame['frameSignature'] = meteonexa_sign_resource($config, 'radar-frame-v2', $device . '|' . $host . '|' . $path, $expires);
        $frame['tileHost'] = $host;
        $frame['tileZoom'] = $zoom;
        $frame['tileColor'] = $color;
        $frame['tileSmooth'] = $smooth;
        $frame['tileSignatures'] = $signatures;
        return $frame;
    }, $past);
    respond(['ok' => true, 'host' => $host, 'frames' => $past, 'source' => meteonexa_backend_text('provider.librewxr')]);
} catch (InvalidArgumentException $error) {
    respond(['ok'=>false,'code'=>'INVALID_COORDINATES','message'=>'api.backend.invalid_coordinates'],422);
} catch (Throwable $error) {
    respond(['ok' => false, 'code' => 'RADAR_UNAVAILABLE', 'message' => meteonexa_backend_text('api.backend.radar_unavailable')], 503);
}
