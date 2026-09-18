<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/database.php';
require_once __DIR__ . '/archive_helpers.php';

$config = load_config();
$isCli = PHP_SAPI === 'cli';
$secret = trim((string)($config['radar_archive']['cron_secret'] ?? ''));
$provided = trim((string)($_SERVER['HTTP_X_CRON_KEY'] ?? ''));
if (!$isCli && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') respond(['ok'=>false,'code'=>'METHOD_NOT_ALLOWED','message'=>'api.backend.method_not_allowed'],405);
if (!$isCli && ($secret === '' || !hash_equals($secret, $provided))) respond(['ok'=>false, 'code'=>'FORBIDDEN', 'message'=>meteonexa_backend_text('api.backend.invalid_radar_cron_key')], 403);

try {
    $pdo = meteonexa_db($config);
    $interval = max(10, min(180, (int)($config['radar_archive']['interval_minutes'] ?? 30))) * 60;
    $locations = $pdo->query('SELECT * FROM radar_archive_locations WHERE active = 1 ORDER BY updated_at DESC')->fetchAll();
    $results = [];
    foreach ($locations as $location) {
        $last = strtotime((string)($location['last_capture_at'] ?? '')) ?: 0;
        if (time() - $last < $interval) continue;
        try { $results[] = ['locationId'=>$location['id']] + meteonexa_capture_radar_location($pdo, $config, $location); }
        catch (Throwable $error) { $results[] = ['locationId'=>$location['id'], 'captured'=>false, 'message'=>meteonexa_backend_text('api.backend.radar_capture_failed')]; }
    }
    $prune = meteonexa_prune_radar_archive($pdo, $config);
    respond(['ok'=>true, 'processed'=>count($locations), 'results'=>$results, 'prune'=>$prune]);
} catch (Throwable $error) {
    respond(['ok'=>false, 'code'=>'RADAR_CAPTURE_FAILED', 'message'=>meteonexa_backend_text('api.backend.radar_capture_failed')], 500);
}
