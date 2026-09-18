<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend_i18n.php';
require_once dirname(__DIR__) . '/http_helpers.php';
require_once __DIR__ . '/metadata_helpers.php';
function meteonexa_archive_slug(string $value) : string {
    return preg_replace('/[^a-zA-Z0-9_-]+/', '-', $value) ? : 'location';
}
function meteonexa_radar_archive_limit_bytes(array $config) : int {
    $megabytes = max(64, min(5120, (int)($config['radar_archive']['max_storage_mb']??512)));
    return $megabytes * 1024 * 1024;
}
function meteonexa_remove_radar_archive_frame(PDO $pdo, array $row) : int {
    $bytes = max(0, (int)($row['bytes_size']??0));
    $path = rtrim(meteonexa_storage_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)($row['image_path']??''), '/\\');
    if (is_file($path))@unlink($path);
    $statement = $pdo->prepare('DELETE FROM radar_archive_frames WHERE id = :id');
    $statement->execute([':id'=>$row['id']]);
    return $bytes;
}
function meteonexa_enforce_radar_archive_quota(PDO $pdo, array $config, int $reserveBytes = 0) : array {
    $limit = meteonexa_radar_archive_limit_bytes($config);
    $reserve = max(0, min($limit, $reserveBytes));
    $target = max(0, $limit - $reserve);
    $total = max(0, (int)$pdo->query('SELECT COALESCE(SUM(bytes_size), 0) FROM radar_archive_frames')->fetchColumn());
    $deleted = 0;
    $freed = 0;
    while ($total > $target) {
        $statement = $pdo->query('SELECT id, image_path, bytes_size FROM radar_archive_frames ORDER BY frame_time ASC, id ASC LIMIT 250');
        $rows = $statement->fetchAll();
        if ($rows===[])break;
        foreach ($rows as $row) {
            if ($total<=$target)break;
            $bytes = meteonexa_remove_radar_archive_frame($pdo, $row);
            $total = max(0, $total - $bytes);
            $freed+=$bytes;
            $deleted++;
        }
    }
    return['deleted'=>$deleted, 'freed_bytes'=>$freed, 'bytes_after'=>$total, 'limit_bytes'=>$limit];
}
function meteonexa_capture_radar_frame(PDO $pdo, array $config, array $location, string $host, array $frame) : array {
    $host = rtrim($host, '/');
    $path = (string)($frame['path']??'');
    $frameTime = (int)($frame['time']??0);
    if ($host===''||$frameTime < 1||preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]+$#', $path)!==1) {
        throw new RuntimeException('RADAR_FRAME_UNAVAILABLE');
    }
    $locationId = (string)$location['id'];
    $exists = $pdo->prepare('SELECT id, image_path FROM radar_archive_frames WHERE location_id = :location_id AND frame_time = :frame_time LIMIT 1');
    $exists->execute([':location_id'=>$locationId, ':frame_time'=>$frameTime]);
    $existing = $exists->fetch();
    if (is_array($existing))return['captured'=>false, 'existing'=>true, 'frame_time'=>$frameTime];
    $zoom = max(4, min(7, (int)($config['radar_archive']['zoom']??7)));
    [$x, $y] = meteonexa_tile_coordinates((float)$location['latitude'], (float)$location['longitude'], $zoom);
    $tileUrl = $host . $path . '/256/' . $zoom . '/' . $x . '/' . $y . '/2/1_1.png';
    $fetchStarted = microtime(true);
    $image = meteonexa_http_request($tileUrl,['timeout'=>6, 'max_bytes'=>2097152, 'pin_dns'=>true, 'headers'=>['Accept: image/png,image/*;q=0.8']]);
    $fetchLatencyMs = (int)round((microtime(true) - $fetchStarted) * 1000);
    if ($image['status'] < 200||$image['status']>=300||!str_starts_with(strtolower((string)$image['content_type']), 'image/')||!str_starts_with((string)$image['body'], "\x89PNG\r\n\x1a\n")) {
        throw new RuntimeException('RADAR_CAPTURE_FAILED');
    }
    $imageBytes = strlen((string)$image['body']);
    meteonexa_enforce_radar_archive_quota($pdo, $config, $imageBytes);
    $storageRoot = meteonexa_storage_path();
    $root = $storageRoot . '/radar-archive';
    $directory = $root . '/' . meteonexa_archive_slug($locationId) . '/' . gmdate('Y/m/d', $frameTime);
    if (!is_dir($directory)&&!@mkdir($directory, 0770, true)&&!is_dir($directory))throw new RuntimeException(meteonexa_backend_text('api.backend.radar_archive_not_writable'));
    $filename = $frameTime . '-z' . $zoom . '-x' . $x . '-y' . $y . '.png';
    $absolute = $directory . '/' . $filename;
    if (!function_exists('meteonexa_atomic_write')||!meteonexa_atomic_write($absolute, (string)$image['body'], 0660)) {
        throw new RuntimeException(meteonexa_backend_text('api.backend.radar_save_failed'));
    }
    $relative = substr($absolute, strlen(rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR));
    try {
        $statement = $pdo->prepare('INSERT INTO radar_archive_frames(location_id, frame_time, zoom_level, tile_x, tile_y, image_path, bytes_size, source, created_at)
            VALUES(:location_id, :frame_time, :zoom_level, :tile_x, :tile_y, :image_path, :bytes_size, :source, :created_at)');
        $statement->execute([':location_id'=>$locationId, ':frame_time'=>$frameTime, ':zoom_level'=>$zoom, ':tile_x'=>$x, ':tile_y'=>$y, ':image_path'=>$relative, ':bytes_size'=>$imageBytes, ':source'=>'LibreWXR', ':created_at'=>gmdate('c'),]);
        $frameId = (int)$pdo->lastInsertId();
        if ($frameId > 0&&meteonexa_db_table_exists($pdo, 'radar_frame_quality')) {
            $coverage = 0.0;
            $grid = function_exists('meteonexa_radar_signal_grid') ? meteonexa_radar_signal_grid($absolute, 24) : null;
            if (is_array($grid)&&!empty($grid['grid'])) {
                $active = 0;
                $total = 0;
                foreach ((array)$grid['grid'] as $r) foreach ((array)$r as $v) {
                    $total++;
                    if ((float)$v > .05)$active++;
                }
                $coverage = $total ? round($active / $total, 4) : 0.0;
            }
            $age = max(0, time() - $frameTime);
            $quality = (int)round(max(25, min(100, 100 - max(0, $age - 600) / 90 - max(0, $fetchLatencyMs - 2000) / 200)));
            $pdo->prepare('INSERT INTO radar_frame_quality(frame_id,quality_score,signal_coverage,fetch_latency_ms,provider_frame_age_seconds,checked_at) VALUES(:frame,:quality,:coverage,:latency,:age,:checked) ON CONFLICT(frame_id) DO UPDATE SET quality_score=excluded.quality_score,signal_coverage=excluded.signal_coverage,fetch_latency_ms=excluded.fetch_latency_ms,provider_frame_age_seconds=excluded.provider_frame_age_seconds,checked_at=excluded.checked_at')->execute([':frame'=>$frameId, ':quality'=>$quality, ':coverage'=>$coverage, ':latency'=>$fetchLatencyMs, ':age'=>$age, ':checked'=>gmdate('c')]);
        }
    } catch (Throwable $error) {
        @unlink($absolute);
        throw $error;
    }
    $pdo->prepare('UPDATE radar_archive_locations SET last_capture_at = :captured, updated_at = :updated WHERE id = :id')->execute([':captured'=>gmdate('c', $frameTime), ':updated'=>gmdate('c'), ':id'=>$locationId]);
    return['captured'=>true, 'existing'=>false, 'frame_time'=>$frameTime, 'bytes'=>$imageBytes, 'fetchLatencyMs'=>$fetchLatencyMs, 'frameAgeSeconds'=>max(0, time() - $frameTime)];
}
function meteonexa_capture_radar_location(PDO $pdo, array $config, array $location) : array {
    $metadata = meteonexa_radar_metadata($config, 60);
    $host = rtrim((string)($metadata['host']??''), '/');
    $frames = array_values(array_filter((array)($metadata['radar']['past']??[]), static fn($row) : bool=>is_array($row)&&isset($row['path'], $row['time'])));
    $frame = $frames ? $frames[array_key_last($frames)] : null;
    if ($host===''||!is_array($frame))throw new RuntimeException('RADAR_FRAME_UNAVAILABLE');
    return meteonexa_capture_radar_frame($pdo, $config, $location, $host, $frame);
}
/**
 * Seed a newly registered archive with the provider's recent observed frames.
 * This makes the player useful immediately instead of waiting hours for cron.
 * Paths come only from the validated LibreWXR metadata feed; the caller cannot
 * choose an upstream URL. The limit is intentionally small to bound disk and
 * upstream usage on shared hosting.
 */
function meteonexa_backfill_radar_location(PDO $pdo, array $config, array $location, int $limit = 4) : array {
    $limit = max(2, min(6, $limit));
    $metadata = meteonexa_radar_metadata($config, 60);
    $host = rtrim((string)($metadata['host']??''), '/');
    $frames = array_values(array_filter((array)($metadata['radar']['past']??[]), static fn($row) : bool=>is_array($row)&&isset($row['path'], $row['time'])));
    if ($host===''||count($frames) < 2)throw new RuntimeException('RADAR_FRAME_UNAVAILABLE');
    $frames = array_slice($frames, - $limit);
    $captured = 0;
    $existing = 0;
    $errors = 0;
    $latest = 0;
    foreach ($frames as $frame) {
        try {
            $result = meteonexa_capture_radar_frame($pdo, $config, $location, $host, $frame);
            $latest = max($latest, (int)($result['frame_time']??0));
            if (($result['captured']??false)===true)$captured++;
            elseif (($result['existing']??false)===true)$existing++;
        } catch (Throwable $error) {
            $errors++;
        }
    }
    if ($captured===0&&$existing===0)throw new RuntimeException('RADAR_BACKFILL_FAILED');
    return['captured'=>$captured > 0, 'backfilled'=>$captured, 'existing'=>$existing, 'errors'=>$errors, 'frame_time'=>$latest];
}
function meteonexa_prune_radar_archive(PDO $pdo, array $config) : array {
    $retentionDays = max(1, min(366, (int)($config['radar_archive']['retention_days']??366)));
    $cutoff = time() - $retentionDays * 86400;
    $statement = $pdo->prepare('SELECT id, image_path, bytes_size FROM radar_archive_frames WHERE frame_time < :cutoff ORDER BY frame_time ASC');
    $statement->execute([':cutoff'=>$cutoff]);
    $rows = $statement->fetchAll();
    $deleted = 0;
    $freed = 0;
    foreach ($rows as $row) {
        $freed+=meteonexa_remove_radar_archive_frame($pdo, $row);
        $deleted++;
    }
    $quota = meteonexa_enforce_radar_archive_quota($pdo, $config);
    return['deleted'=>$deleted + (int)$quota['deleted'], 'retention_deleted'=>$deleted, 'quota_deleted'=>(int)$quota['deleted'], 'freed_bytes'=>$freed + (int)$quota['freed_bytes'], 'bytes_after'=>(int)$quota['bytes_after'], 'limit_bytes'=>(int)$quota['limit_bytes'], 'retention_days'=>$retentionDays,];
}
