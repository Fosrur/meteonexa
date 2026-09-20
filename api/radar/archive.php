<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/http_helpers.php';
require_once dirname(__DIR__) . '/public_helpers.php';

require_method('GET');
assert_same_origin();
$config = load_config();
try {
    [$lat, $lon] = meteonexa_validate_coordinates($_GET['lat'] ?? null, $_GET['lon'] ?? null);
    $deviceId = clean_device_id($_GET['deviceId'] ?? '');
    $date = trim((string)($_GET['date'] ?? gmdate('Y-m-d')));
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
    if (!$start || $start->format('Y-m-d') !== $date) throw new InvalidArgumentException(meteonexa_backend_text('api.backend.invalid_archive_date'));
    $end = $start->modify('+1 day');
    $pdo = meteonexa_db($config);
    require_ip_rate_limit($pdo, 'radar_archive_ip', 600, 3600);
    require_authenticated_device_session($pdo, $config, $deviceId);
    require_device_rate_limit($pdo, 'radar_archive_device', $deviceId, 300, 3600);
    $locationId = hash('sha256', $deviceId . '|' . round($lat, 3) . ':' . round($lon, 3));
    $statement = $pdo->prepare('SELECT id, frame_time, zoom_level, tile_x, tile_y, bytes_size, source FROM radar_archive_frames
        WHERE location_id = :location_id AND frame_time >= :start AND frame_time < :end ORDER BY frame_time ASC LIMIT 288');
    $statement->execute([':location_id'=>$locationId, ':start'=>$start->getTimestamp(), ':end'=>$end->getTimestamp()]);
    $imageExpires = time() + 3600;
    $frames = array_map(static function(array $row) use ($config, $deviceId, $imageExpires): array {
        $id = (int)$row['id'];
        $signedValue = $id . '|' . $deviceId;
        $sig = meteonexa_sign_resource($config, 'radar-image', $signedValue, $imageExpires);
        $query = http_build_query(['id'=>$id,'deviceId'=>$deviceId,'exp'=>$imageExpires,'sig'=>$sig]);
        return [
            'id'=>$id, 'time'=>(int)$row['frame_time'], 'zoom'=>(int)$row['zoom_level'],
            'x'=>(int)$row['tile_x'], 'y'=>(int)$row['tile_y'], 'bytes'=>(int)$row['bytes_size'],
            'source'=>meteonexa_backend_text('provider.librewxr'), 'imageUrl'=>'api/radar/image.php?' . $query,
        ];
    }, $statement->fetchAll());
    $first = $pdo->prepare('SELECT MIN(frame_time) FROM radar_archive_frames WHERE location_id = :location_id');
    $first->execute([':location_id'=>$locationId]);
    respond(['ok'=>true, 'date'=>$date, 'frames'=>$frames, 'firstAvailable'=>(int)($first->fetchColumn() ?: 0), 'retentionDays'=>max(1,min(366,(int)($config['radar_archive']['retention_days'] ?? 366)))]);
} catch (InvalidArgumentException $error) {
    respond(['ok'=>false, 'code'=>'INVALID_REQUEST', 'message'=>'api.backend.invalid_request'], 400);
} catch (Throwable $error) {
    meteonexa_log_event('radar_archive_failed', $error);
    respond(['ok'=>false, 'code'=>'RADAR_ARCHIVE_FAILED', 'message'=>'api.backend.radar_archive_failed'], 500);
}
