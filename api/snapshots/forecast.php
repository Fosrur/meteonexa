<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';

assert_same_origin();
$config = load_config();
$pdo = meteonexa_db($config);
require_method('GET', 'POST');

function meteonexa_snapshot_location_key(mixed $value): string
{
    $key = clean_text($value, 80);
    if (preg_match('/^(-?\d{1,3}(?:\.\d{1,6})?):(-?\d{1,3}(?:\.\d{1,6})?)$/', $key, $match) !== 1) {
        respond(['ok'=>false,'code'=>'INVALID_SNAPSHOT','message'=>meteonexa_backend_text('api.backend.invalid_snapshot')],422);
    }
    $lat = (float)$match[1];
    $lon = (float)$match[2];
    if (abs($lat) > 90 || abs($lon) > 180) {
        respond(['ok'=>false,'code'=>'INVALID_SNAPSHOT','message'=>meteonexa_backend_text('api.backend.invalid_snapshot')],422);
    }
    return $key;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $deviceId = clean_device_id($_GET['deviceId'] ?? '');
    require_authenticated_device_session($pdo, $config, $deviceId);
    require_device_rate_limit($pdo, 'snapshot_read_device', $deviceId, 240, 3600);
    $key = meteonexa_snapshot_location_key($_GET['locationKey'] ?? '');
    $statement = $pdo->prepare('SELECT snapshot_json, created_at FROM forecast_snapshots WHERE device_id=:device AND location_key=:key ORDER BY id DESC LIMIT 2');
    $statement->execute([':device'=>$deviceId,':key'=>$key]);
    $rows = array_map(static fn(array $row): array => ['snapshot'=>json_decode((string)$row['snapshot_json'],true),'createdAt'=>$row['created_at']], $statement->fetchAll());
    respond(['ok'=>true,'rows'=>$rows]);
}

$data = input_json();
$deviceId = clean_device_id($data['deviceId'] ?? '');
require_authenticated_device_session($pdo, $config, $deviceId);
require_ip_rate_limit($pdo, 'snapshot_write_ip', 180, 3600);
require_device_rate_limit($pdo, 'snapshot_write_device', $deviceId, 120, 3600);
$key = meteonexa_snapshot_location_key($data['locationKey'] ?? '');
$snapshot = $data['snapshot'] ?? null;
if (!is_array($snapshot)) respond(['ok'=>false,'code'=>'INVALID_SNAPSHOT','message'=>meteonexa_backend_text('api.backend.invalid_snapshot')],422);
$json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json) || strlen($json) > 20000) respond(['ok'=>false,'code'=>'SNAPSHOT_TOO_LARGE','message'=>meteonexa_backend_text('api.backend.snapshot_too_large')],413);
$statement = $pdo->prepare('INSERT INTO forecast_snapshots(device_id,location_key,snapshot_json,created_at) VALUES(:device,:key,:json,:created)');
$statement->execute([':device'=>$deviceId,':key'=>$key,':json'=>$json,':created'=>gmdate('c')]);
$cleanup = $pdo->prepare('DELETE FROM forecast_snapshots WHERE id IN (SELECT id FROM forecast_snapshots WHERE device_id=:device AND location_key=:key ORDER BY id DESC LIMIT -1 OFFSET 12)');
$cleanup->execute([':device'=>$deviceId,':key'=>$key]);
$deviceCleanup = $pdo->prepare('DELETE FROM forecast_snapshots WHERE id IN (SELECT id FROM forecast_snapshots WHERE device_id=:device ORDER BY id DESC LIMIT -1 OFFSET 500)');
$deviceCleanup->execute([':device'=>$deviceId]);
$pdo->prepare('DELETE FROM forecast_snapshots WHERE created_at < :cutoff')->execute([':cutoff'=>gmdate('c', time()-30*86400)]);
meteonexa_prune_rows_to_limit($pdo, 'forecast_snapshots', (int)($config['storage_limits']['forecast_snapshots'] ?? 50000));
respond(['ok'=>true]);
