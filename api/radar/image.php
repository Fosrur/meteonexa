<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_method('GET');
$config = load_config();
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$deviceId = clean_device_id($_GET['deviceId'] ?? '');
$expires = filter_var($_GET['exp'] ?? null, FILTER_VALIDATE_INT);
$signature = trim((string)($_GET['sig'] ?? ''));
if ($id === false || $id < 1 || $expires === false || !meteonexa_verify_resource_signature($config, 'radar-image', $id . '|' . $deviceId, (int)$expires, $signature)) {
    respond(['ok'=>false, 'code'=>'INVALID_IMAGE', 'message'=>'api.backend.invalid_radar_image'], 403);
}
$pdo = meteonexa_db($config);
$statement = $pdo->prepare('SELECT f.image_path, f.frame_time FROM radar_archive_frames f JOIN radar_archive_locations l ON l.id=f.location_id WHERE f.id=:id AND l.device_id=:device LIMIT 1');
$statement->execute([':id'=>$id, ':device'=>$deviceId]);
$row = $statement->fetch();
if (!is_array($row)) respond(['ok'=>false, 'code'=>'NOT_FOUND', 'message'=>'api.radar.frame_not_found'], 404);
$storage = realpath(meteonexa_storage_path());
$path = realpath(rtrim(meteonexa_storage_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$row['image_path'], '/\\'));
if (!$storage || !$path || !str_starts_with($path, $storage . DIRECTORY_SEPARATOR) || !is_file($path)) respond(['ok'=>false, 'code'=>'NOT_FOUND', 'message'=>'api.radar.file_not_found'], 404);
header_remove('Content-Type');
header('Content-Type: image/png');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int)$row['frame_time']) . ' GMT');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
