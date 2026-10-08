<?php
declare(strict_types=1);
function meteonexa_ensemble_nearest_index(array $times, int $target, int $maxDelta = 10800): ?int
{
    $best = null;
    $delta = PHP_INT_MAX;
    foreach ($times as $index=>$value) {
        $ts = meteonexa_intel_time_utc($value);
        if ($ts === null) continue;
        $d = abs($ts - $target);
        if ($d < $delta) { $delta = $d; $best = (int)$index; }
    }
    return $best !== null && $delta <= $maxDelta ? $best : null;
}

function meteonexa_ensemble_queue_verification(PDO $pdo, string $deviceId, string $locationKey, array $raw): int
{
    if (!meteonexa_db_table_exists($pdo, 'ensemble_verification_samples')) return 0;
    $hourly = (array)($raw['hourly'] ?? []);
    $times = array_values((array)($hourly['time'] ?? []));
    if (!$times) return 0;
    $meta = (array)($raw['_meteonexaEnsemble'] ?? []);
    $modelId = (string)($meta['modelId'] ?? 'ecmwf_aifs025_ensemble');
    $issuedAt = gmdate('c');
    $insertSql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? "INSERT IGNORE INTO ensemble_verification_samples(device_id,location_key,model_id,metric,horizon_hours,target_time,member_count,members_json,p10,p50,p90,observed_value,crps,status,issued_at,verified_at,created_at) VALUES(:device,:location,:model,:metric,:horizon,:target,:count,:members,:p10,:p50,:p90,NULL,NULL,'pending',:issued,'',:created)" : "INSERT OR IGNORE INTO ensemble_verification_samples(device_id,location_key,model_id,metric,horizon_hours,target_time,member_count,members_json,p10,p50,p90,observed_value,crps,status,issued_at,verified_at,created_at) VALUES(:device,:location,:model,:metric,:horizon,:target,:count,:members,:p10,:p50,:p90,NULL,NULL,'pending',:issued,'',:created)";
    $insert = $pdo->prepare($insertSql);
    $now = time();
    $count = 0;
    foreach ([6,24,48,72] as $horizon) {
        $index = meteonexa_ensemble_nearest_index($times, $now + $horizon * 3600);
        if ($index === null) continue;
        $targetTs = meteonexa_intel_time_utc($times[$index] ?? '');
        if ($targetTs === null) continue;
        foreach (['temperature'=>'temperature_2m','wind'=>'wind_gusts_10m'] as $metric=>$variable) {
            $members = meteonexa_ensemble_values_at($hourly, meteonexa_ensemble_series_keys($hourly, $variable), $index);
            if (count($members) < 10) continue;
            $dist = meteonexa_ensemble_distribution($members);
            $insert->execute([
                ':device'=>$deviceId, ':location'=>$locationKey, ':model'=>$modelId, ':metric'=>$metric,
                ':horizon'=>$horizon, ':target'=>gmdate('c', $targetTs), ':count'=>count($members),
                ':members'=>json_encode(array_values($members), JSON_UNESCAPED_SLASHES),
                ':p10'=>$dist['p10'] ?? null, ':p50'=>$dist['p50'] ?? null, ':p90'=>$dist['p90'] ?? null,
                ':issued'=>$issuedAt, ':created'=>$issuedAt,
            ]);
            $count += $insert->rowCount() ? 1 : 0;
        }
    }
    return $count;
}

function meteonexa_ensemble_verify_pending(PDO $pdo, string $deviceId, string $locationKey, array $observations): int
{
    if (!meteonexa_db_table_exists($pdo, 'ensemble_verification_samples') || empty($observations['available']) || empty($observations['independentFromNwp'])) return 0;
    $observedTs = meteonexa_intel_time_utc($observations['observedAt'] ?? '');
    if ($observedTs === null) return 0;
    $select = $pdo->prepare("SELECT id,metric,target_time,members_json FROM ensemble_verification_samples WHERE device_id=:device AND location_key=:location AND status='pending' ORDER BY id ASC LIMIT 300");
    $select->execute([':device'=>$deviceId, ':location'=>$locationKey]);
    $update = $pdo->prepare("UPDATE ensemble_verification_samples SET observed_value=:observed,crps=:crps,status='verified',verified_at=:verified WHERE id=:id AND status='pending'");
    $verified = 0;
    foreach ($select->fetchAll() as $row) {
        $targetTs = meteonexa_intel_time_utc($row['target_time'] ?? '');
        if ($targetTs === null || abs($targetTs - $observedTs) > 5400) continue;
        $metric = (string)($row['metric'] ?? '');
        $observed = $observations[$metric] ?? null;
        if (!is_numeric($observed)) continue;
        $members = json_decode((string)($row['members_json'] ?? ''), true);
        if (!is_array($members)) continue;
        $crps = meteonexa_ensemble_crps($members, (float)$observed);
        if ($crps === null) continue;
        $update->execute([':observed'=>(float)$observed, ':crps'=>round($crps, 6), ':verified'=>gmdate('c'), ':id'=>(int)$row['id']]);
        $verified += $update->rowCount() ? 1 : 0;
    }
    return $verified;
}

function meteonexa_ensemble_verification_summary(PDO $pdo, string $deviceId, string $locationKey): array
{
    if (!meteonexa_db_table_exists($pdo, 'ensemble_verification_samples')) return ['available'=>false,'samples'=>0];
    $st = $pdo->prepare("SELECT model_id,metric,horizon_hours,COUNT(*) samples,AVG(crps) mean_crps FROM ensemble_verification_samples WHERE device_id=:device AND location_key=:location AND status='verified' AND crps IS NOT NULL GROUP BY model_id,metric,horizon_hours ORDER BY metric,horizon_hours");
    $st->execute([':device'=>$deviceId, ':location'=>$locationKey]);
    $rows = [];
    $samples = 0;
    foreach ($st->fetchAll() as $row) {
        $n = (int)($row['samples'] ?? 0); $samples += $n;
        $rows[] = ['modelId'=>(string)$row['model_id'],'metric'=>(string)$row['metric'],'horizonHours'=>(int)$row['horizon_hours'],'samples'=>$n,'meanCrps'=>is_numeric($row['mean_crps'] ?? null) ? round((float)$row['mean_crps'],4) : null];
    }
    return ['available'=>$samples > 0,'samples'=>$samples,'rows'=>$rows,'method'=>'empirical-crps-independent-observations-v1'];
}

function meteonexa_ensemble_prune_verification(PDO $pdo): int
{
    if (!meteonexa_db_table_exists($pdo, 'ensemble_verification_samples')) return 0;
    $cutoff = gmdate('c', time() - 120 * 86400);
    $st = $pdo->prepare("DELETE FROM ensemble_verification_samples WHERE created_at<:cutoff AND (status='verified' OR target_time<:cutoff)");
    $st->execute([':cutoff'=>$cutoff]);
    return $st->rowCount();
}

function meteonexa_ensemble_verification_touch(PDO $pdo, string $deviceId, string $locationKey, array $raw, array $observations): array
{
    try {
        $verified = meteonexa_ensemble_verify_pending($pdo, $deviceId, $locationKey, $observations);
        $queued = meteonexa_ensemble_queue_verification($pdo, $deviceId, $locationKey, $raw);
        $pruned = meteonexa_ensemble_prune_verification($pdo);
        return ['available'=>true,'verified'=>$verified,'queued'=>$queued,'pruned'=>$pruned,'skill'=>meteonexa_ensemble_verification_summary($pdo,$deviceId,$locationKey)];
    } catch (Throwable $ignored) {
        return ['available'=>false,'verified'=>0,'queued'=>0,'pruned'=>0,'reason'=>'verification_touch_failed'];
    }
}

function meteonexa_probabilistic_ensemble(float $lat, float $lon, array $physicsModel = [], array $observations = [], int $forecastHours = 360, ?PDO $pdo = null, string $deviceId = '', string $locationKey = ''): array
{
    $raw = meteonexa_ensemble_fetch($lat, $lon, $forecastHours);
    if (!$raw) return ['available'=>false, 'model'=>'ECMWF AIFS ENS', 'reason'=>'provider_unavailable', 'policy'=>['authority'=>'probabilistic-evidence-only','physicsReference'=>'ECMWF IFS deterministic']];
    $meta = (array)($raw['_meteonexaEnsemble'] ?? []);
    $rows = meteonexa_ensemble_rows($raw);
    if (!$rows) return ['available'=>false, 'model'=>(string)($meta['label'] ?? 'ECMWF AIFS ENS'), 'reason'=>'no_member_rows', 'policy'=>['authority'=>'probabilistic-evidence-only','physicsReference'=>'ECMWF IFS deterministic']];
    $now = time();
    $headline = null; $headlineDelta = PHP_INT_MAX;
    foreach ($rows as $row) {
        $delta = abs((int)$row['timestamp'] - ($now + 24 * 3600));
        if ($delta < $headlineDelta) { $headlineDelta = $delta; $headline = $row; }
    }
    $memberCount = 0;
    foreach (['temperature','precipitation','windGust'] as $metric) $memberCount = max($memberCount, (int)($headline[$metric]['members'] ?? 0));
    $cache = (array)($raw['_meteonexaCache'] ?? []);
    $verification = ['available'=>false,'reason'=>'not_persisted'];
    if ($pdo instanceof PDO && $deviceId !== '' && $locationKey !== '') $verification = meteonexa_ensemble_verification_touch($pdo, $deviceId, $locationKey, $raw, $observations);
    return [
        'available'=>true,
        'model'=>(string)($meta['label'] ?? 'ECMWF AIFS ENS'),
        'modelId'=>(string)($meta['modelId'] ?? 'ecmwf_aifs025_ensemble'),
        'sourceType'=>(string)($meta['sourceType'] ?? 'ai-ensemble'),
        'fallbackUsed'=>(string)($meta['id'] ?? 'aifs_ens') !== 'aifs_ens',
        'memberCount'=>$memberCount,
        'membersExpected'=>(int)($meta['membersExpected'] ?? 51),
        'memberCoveragePct'=>(int)round(100 * $memberCount / max(1,(int)($meta['membersExpected'] ?? 51))),
        'temporalResolution'=>'native-model-resolution',
        'forecastHours'=>max(168, min(360, $forecastHours)),
        'headline24h'=>$headline,
        'shortRange'=>array_values(array_filter($rows, static fn(array $row): bool => (int)$row['timestamp'] <= time() + 72 * 3600)),
        'extendedScenarios'=>meteonexa_ensemble_member_daily_scenarios($raw, $now),
        'uncertainty'=>meteonexa_ensemble_uncertainty($rows, 72),
        'physicsComparison'=>meteonexa_ensemble_physics_comparison($rows, $physicsModel, 72),
        'verification'=>$verification,
        'freshness'=>[
            'stale'=>!empty($cache['stale']),
            'cacheHit'=>!empty($cache['cacheHit']),
            'ageMinutes'=>is_numeric($cache['ageSeconds'] ?? null) ? (int)ceil((int)$cache['ageSeconds'] / 60) : null,
            'fetchedAt'=>$cache['fetchedAt'] ?? null,
        ],
        'policy'=>[
            'authority'=>'probabilistic-evidence-only',
            'physicsReference'=>'ECMWF IFS deterministic',
            'deterministicConsensusUnaffected'=>true,
            'extendedRangeIsDailyScenario'=>true,
            'crpsRequiresIndependentObservations'=>true,
        ],
        'generatedAt'=>gmdate('c'),
    ];
}
