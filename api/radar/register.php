<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once __DIR__ . '/archive_helpers.php';

assert_same_origin();
require_method('POST');
$config = load_config();
$data = input_json();
try {
    [$lat, $lon] = meteonexa_validate_coordinates($data['latitude'] ?? null, $data['longitude'] ?? null);
    $deviceId = clean_device_id($data['deviceId'] ?? '');
    $pdo = meteonexa_db($config);
    require_authenticated_device_session($pdo, $config, $deviceId);
    require_ip_rate_limit($pdo, 'radar_register_ip', 60, 3600);
    require_global_rate_limit($pdo, 'radar_register_global', (int)($config['abuse_limits']['radar_register_global_hour'] ?? 600), 3600);
    require_device_rate_limit($pdo, 'radar_register_device', $deviceId, 30, 3600);
    $name = clean_text($data['name'] ?? meteonexa_backend_text('app.name'), 120, meteonexa_backend_text('app.name'));
    $id = hash('sha256', $deviceId . '|' . round($lat, 3) . ':' . round($lon, 3));
    $existing = $pdo->prepare('SELECT 1 FROM radar_archive_locations WHERE id=:id LIMIT 1');
    $existing->execute([':id'=>$id]);
    if (!$existing->fetchColumn()) {
        $maxPerDevice = max(1, min(50, (int)($config['radar_archive']['max_locations_per_device'] ?? 8)));
        $maxTotal = max($maxPerDevice, min(1000, (int)($config['radar_archive']['max_total_locations'] ?? 250)));
        $countDevice = $pdo->prepare('SELECT COUNT(*) FROM radar_archive_locations WHERE device_id=:device');
        $countDevice->execute([':device'=>$deviceId]);
        $total = (int)$pdo->query('SELECT COUNT(*) FROM radar_archive_locations')->fetchColumn();
        if ((int)$countDevice->fetchColumn() >= $maxPerDevice || $total >= $maxTotal) {
            respond(['ok'=>false,'code'=>'RADAR_QUOTA','message'=>'api.security.rate_limit'],429);
        }
    }
    $now = gmdate('c');
    $statement = $pdo->prepare('INSERT INTO radar_archive_locations(id, device_id, location_name, latitude, longitude, active, last_capture_at, created_at, updated_at)
        VALUES(:id, :device_id, :location_name, :latitude, :longitude, 1, NULL, :created_at, :updated_at)
        ON CONFLICT(id) DO UPDATE SET location_name = excluded.location_name, latitude = excluded.latitude, longitude = excluded.longitude, active = 1, updated_at = excluded.updated_at');
    $statement->execute([':id'=>$id, ':device_id'=>$deviceId, ':location_name'=>$name, ':latitude'=>$lat, ':longitude'=>$lon, ':created_at'=>$now, ':updated_at'=>$now]);
    $capture = null;
    $frameCountStatement = $pdo->prepare('SELECT COUNT(*) FROM radar_archive_frames WHERE location_id = :id');
    $frameCountStatement->execute([':id'=>$id]);
    $frameCount = (int)$frameCountStatement->fetchColumn();
    if ($frameCount < 2) {
        try {
            $capture = meteonexa_backfill_radar_location($pdo, $config, ['id'=>$id, 'latitude'=>$lat, 'longitude'=>$lon], 4);
        } catch (Throwable $error) {
            // A failed backfill must not prevent registration; fall back to the
            // single latest observation and let the scheduled capture grow it.
            try { $capture = meteonexa_capture_radar_location($pdo, $config, ['id'=>$id, 'latitude'=>$lat, 'longitude'=>$lon]); }
            catch (Throwable $captureError) { $capture = ['captured'=>false, 'message'=>meteonexa_backend_text('api.backend.radar_capture_failed')]; }
        }
    } else {
        $recent = $pdo->prepare('SELECT frame_time FROM radar_archive_frames WHERE location_id = :id ORDER BY frame_time DESC LIMIT 1');
        $recent->execute([':id'=>$id]);
        $last = (int)($recent->fetchColumn() ?: 0);
        if (time() - $last > 900) {
            try { $capture = meteonexa_capture_radar_location($pdo, $config, ['id'=>$id, 'latitude'=>$lat, 'longitude'=>$lon]); }
            catch (Throwable $error) { $capture = ['captured'=>false, 'message'=>meteonexa_backend_text('api.backend.radar_capture_failed')]; }
        }
    }
    respond(['ok'=>true, 'locationId'=>$id, 'capture'=>$capture]);
} catch (InvalidArgumentException $error) {
    respond(['ok'=>false, 'code'=>'INVALID_REQUEST', 'message'=>'api.backend.invalid_request'], 400);
} catch (Throwable $error) {
    meteonexa_log_event('radar_register_failed', $error);
    respond(['ok'=>false, 'code'=>'RADAR_REGISTER_FAILED', 'message'=>'api.backend.radar_register_failed'], 500);
}
