<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/public_helpers.php';
function meteonexa_pipeline_now() : string {
    return gmdate('c');
}
function meteonexa_pipeline_ms() : int {
    return (int)round(microtime(true) * 1000);
}
function meteonexa_pipeline_provider_state(PDO $pdo, string $provider) : ? array {
    if (!meteonexa_db_table_exists($pdo, 'weather_provider_health'))return null;
    $st = $pdo->prepare('SELECT * FROM weather_provider_health WHERE provider_id=:provider LIMIT 1');
    $st->execute([':provider'=>$provider]);
    $row = $st->fetch();
    return is_array($row) ? $row : null;
}
function meteonexa_pipeline_provider_due(PDO $pdo, string $provider, int $intervalSeconds, int $failureCooldown = 300) : bool {
    $row = meteonexa_pipeline_provider_state($pdo, $provider);
    if (!$row)return true;
    $last = strtotime((string)($row['last_attempt_at']??'')) ? : 0;
    $failures = (int)($row['consecutive_failures']??0);
    $wait = $failures>=3 ? max($intervalSeconds, min(1800, $failureCooldown *(1 + min(4, $failures - 3)))) : $intervalSeconds;
    return time() - $last>=$wait;
}
function meteonexa_pipeline_provider_result(PDO $pdo, string $provider, bool $ok, int $latencyMs, array $details =[], ? int $freshnessSeconds = null, string $status = '') : void {
    if (!meteonexa_db_table_exists($pdo, 'weather_provider_health'))return;
    $old = meteonexa_pipeline_provider_state($pdo, $provider);
    $now = meteonexa_pipeline_now();
    $failures = $ok ? 0 :((int)($old['consecutive_failures']??0) + 1);
    $state = $status!=='' ? $status :($ok ? 'ok' :($failures>=3 ? 'degraded' : 'error'));
    $payload = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ? : '{}';
    $sql = "INSERT INTO weather_provider_health(provider_id,status,last_attempt_at,last_success_at,last_failure_at,latency_ms,consecutive_failures,freshness_seconds,details_json,updated_at) VALUES(:provider,:status,:attempt,:success,:failure,:latency,:failures,:freshness,:details,:updated) ON CONFLICT(provider_id) DO UPDATE SET status=excluded.status,last_attempt_at=excluded.last_attempt_at,last_success_at=CASE WHEN excluded.last_success_at<>'' THEN excluded.last_success_at ELSE weather_provider_health.last_success_at END,last_failure_at=CASE WHEN excluded.last_failure_at<>'' THEN excluded.last_failure_at ELSE weather_provider_health.last_failure_at END,latency_ms=excluded.latency_ms,consecutive_failures=excluded.consecutive_failures,freshness_seconds=excluded.freshness_seconds,details_json=excluded.details_json,updated_at=excluded.updated_at";
    $pdo->prepare($sql)->execute([':provider'=>$provider, ':status'=>$state, ':attempt'=>$now, ':success'=>$ok ? $now : '', ':failure'=>$ok ? '' : $now, ':latency'=>max(0, $latencyMs), ':failures'=>$failures, ':freshness'=>$freshnessSeconds, ':details'=>$payload, ':updated'=>$now]);
}
function meteonexa_pipeline_run_start(PDO $pdo, string $worker) : array {
    $started = microtime(true);
    $id = 0;
    if (meteonexa_db_table_exists($pdo, 'weather_pipeline_runs')) {
        $st = $pdo->prepare('INSERT INTO weather_pipeline_runs(worker_name,status,started_at,finished_at,duration_ms,locations_processed,success_count,failure_count,details_json) VALUES(:worker,\'running\',:started,\'\',0,0,0,0,\'{}\')');
        $st->execute([':worker'=>$worker, ':started'=>meteonexa_pipeline_now()]);
        $id = (int)$pdo->lastInsertId();
    }
    return['id'=>$id, 'started'=>$started, 'worker'=>$worker];
}
function meteonexa_pipeline_run_finish(PDO $pdo, array $run, string $status, int $locations, int $successes, int $failures, array $details =[]) : void {
    if (empty($run['id'])||!meteonexa_db_table_exists($pdo, 'weather_pipeline_runs'))return;
    $duration = (int)round((microtime(true) - (float)$run['started']) * 1000);
    $payload = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ? : '{}';
    $pdo->prepare('UPDATE weather_pipeline_runs SET status=:status,finished_at=:finished,duration_ms=:duration,locations_processed=:locations,success_count=:successes,failure_count=:failures,details_json=:details WHERE id=:id')->execute([':status'=>$status, ':finished'=>meteonexa_pipeline_now(), ':duration'=>$duration, ':locations'=>$locations, ':successes'=>$successes, ':failures'=>$failures, ':details'=>$payload, ':id'=>(int)$run['id']]);
}
function meteonexa_pipeline_locations(PDO $pdo, int $limit = 40) : array {
    $items =[];
    $put = static function(array $r)use(&$items) : void {
        $device = trim((string)($r['device_id']??''));
        $lat = $r['latitude']??null;
        $lon = $r['longitude']??null;
        if ($device===''||!is_numeric($lat)||!is_numeric($lon)||abs((float)$lat) > 90||abs((float)$lon) > 180)return;
        $key = $device . '|' . number_format((float)$lat, 3, '.', '') . '|' . number_format((float)$lon, 3, '.', '');
        $items[$key] =['deviceId'=>$device, 'latitude'=>(float)$lat, 'longitude'=>(float)$lon, 'locationName'=>trim((string)($r['location_name']??$r['label']??'')), 'admin1'=>trim((string)($r['admin1']??''))];
    };
    $queries =[];
    if (meteonexa_db_table_exists($pdo, 'push_subscriptions'))$queries[] = 'SELECT device_id,latitude,longitude,location_name,\'\' admin1 FROM push_subscriptions WHERE active=1 AND latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY updated_at DESC';
    if (meteonexa_db_table_exists($pdo, 'saved_locations'))$queries[] = 'SELECT device_id,latitude,longitude,location_name,admin1 FROM saved_locations WHERE active=1 ORDER BY updated_at DESC';
    if (meteonexa_db_table_exists($pdo, 'radar_archive_locations'))$queries[] = 'SELECT device_id,latitude,longitude,location_name,\'\' admin1 FROM radar_archive_locations WHERE active=1 ORDER BY updated_at DESC';
    foreach ($queries as $sql) {
        if (count($items)>=$limit)break;
        foreach ($pdo->query($sql . ' LIMIT ' . max(10, $limit * 2))->fetchAll() as $r)$put($r);
    }
    return array_slice(array_values($items), 0, max(1, $limit));
}
function meteonexa_pipeline_health_summary(PDO $pdo) : array {
    if (!meteonexa_db_table_exists($pdo, 'weather_provider_health'))return['available'=>false, 'providers'=>[]];
    $rows = $pdo->query('SELECT provider_id,status,last_attempt_at,last_success_at,last_failure_at,latency_ms,consecutive_failures,freshness_seconds,updated_at FROM weather_provider_health ORDER BY provider_id')->fetchAll();
    $bad = 0;
    foreach ($rows as $r) if (!in_array((string)$r['status'],['ok', 'disabled', 'not_configured'], true))$bad++;
    return['available'=>true, 'status'=>$bad ? 'degraded' : 'ok', 'degradedProviders'=>$bad, 'providers'=>$rows];
}
