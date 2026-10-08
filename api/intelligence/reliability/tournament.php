<?php
declare(strict_types=1);
function meteonexa_reliability_tournament_cache_key(string $device, string $location) : string {
    return 'reliability_cc_v1_' . substr(hash('sha256',$device . '|' . $location), 0, 40);
}
function meteonexa_reliability_tournament_cache_read(PDO $pdo, string $key) : ?array {
    if (!meteonexa_db_table_exists($pdo, 'app_metadata'))return null;
    try {
        $st = $pdo->prepare('SELECT meta_value,updated_at FROM app_metadata WHERE meta_key=:k LIMIT 1');
        $st->execute([':k'=>$key]);
        $row = $st->fetch();
        if (!$row)return null;
        $value = json_decode((string)$row['meta_value'], true);
        if (!is_array($value))return null;
        $value['_cacheUpdatedAt'] = $row['updated_at']??null;
        return $value;
    } catch (Throwable $e) {
        return null;
    }
}
function meteonexa_reliability_tournament_cache_write(PDO $pdo, string $key, array $value) : void {
    if (!meteonexa_db_table_exists($pdo, 'app_metadata'))return;
    try {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver==='mysql'
            ? 'INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:k,:v,:u) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=VALUES(updated_at)'
            : 'INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:k,:v,:u) ON CONFLICT(meta_key) DO UPDATE SET meta_value=excluded.meta_value,updated_at=excluded.updated_at';
        $pdo->prepare($sql)->execute([':k'=>$key,':v'=>json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':u'=>gmdate('c')]);
    } catch (Throwable $e) {
        
    }
}
function meteonexa_reliability_tournament_weighting_meta(array $tournament) : array {
    return[
        'mode'=>(string)($tournament['mode']??'champion'),
        'champion'=>(string)($tournament['champion']??'recency-decay-skill-v2'),
        'challenger'=>(string)($tournament['challenger']??'seasonal-decayed-skill-shrunk'),
        'promotedBuckets'=>(int)($tournament['promotedBuckets']??0),
        'promotionPolicy'=>'automatic-per-bucket-after-out-of-sample-shadow-gates',
    ];
}
function meteonexa_reliability_weight_tournament(PDO $pdo, string $device, string $location, array $championWeights, ?int $referenceTime = null, bool $forceRefresh = false) : array {
    $policy = meteonexa_reliability_shadow_policy();
    $reference = $referenceTime ?? time();
    $fallback =['available'=>false,'mode'=>'champion','champion'=>'recency-decay-skill-v2','challenger'=>'seasonal-decayed-skill-shrunk','activeWeights'=>$championWeights,'challengerWeights'=>[],'promotedBuckets'=>0,'buckets'=>[],'policy'=>$policy,'generatedAt'=>gmdate('c',$reference)];
    if (!meteonexa_db_table_exists($pdo, 'model_skill_samples'))return $fallback;
    try {
        $fingerprintStmt = $pdo->prepare("SELECT COUNT(*) samples,MAX(verified_at) newest FROM model_skill_samples WHERE device_id=:d AND location_key=:l AND verified_at<>'' AND model_name<>'consensus' AND metric IN ('rain','storm','snow')");
        $fingerprintStmt->execute([':d'=>$device,':l'=>$location]);
        $fingerprint = $fingerprintStmt->fetch() ? :[];
        $sourceSamples = (int)($fingerprint['samples']??0);
        $sourceNewest = (string)($fingerprint['newest']??'');
        if ($sourceSamples<=0)return $fallback;
        $season = meteonexa_reliability_season($reference);
        $seasonSignature = implode('|', array_map(static fn($h)=>$h . ':' . meteonexa_reliability_season($reference + $h * 3600), [1,3,6,24,48,72]));
        $key = meteonexa_reliability_tournament_cache_key($device,$location);
        $cached = !$forceRefresh ? meteonexa_reliability_tournament_cache_read($pdo,$key) : null;
        $cachedAt = is_array($cached) ? strtotime((string)($cached['evaluatedAt']??$cached['_cacheUpdatedAt']??'')) : false;
        $cacheFresh = is_array($cached)
            &&($cached['version']??'')===$policy['version']
            &&(int)($cached['sourceSamples']??-1)===$sourceSamples
            &&(string)($cached['sourceNewestVerifiedAt']??'')===$sourceNewest
            &&(string)($cached['season']??'')===$season
            &&(string)($cached['seasonSignature']??'')===$seasonSignature
            &&$cachedAt!==false
            &&$cachedAt>=$reference-(int)$policy['cacheTtlSeconds'];
        if ($cacheFresh) {
            $challengerWeights = (array)($cached['challengerWeights']??[]);
            $active = $championWeights;
            $promoted = 0;
            foreach ((array)($cached['buckets']??[]) as $bucket) {
                if (empty($bucket['promoted']))continue;
                $metric = (string)($bucket['metric']??'');
                $h = (int)($bucket['horizonHours']??0);
                if (!isset($challengerWeights[$metric][$h]))continue;
                $active[$metric][$h] = $challengerWeights[$metric][$h];
                $promoted++;
            }
            return array_replace($fallback,['available'=>true,'mode'=>$promoted>0 ? 'guarded-challenger' : 'champion','activeWeights'=>$active,'challengerWeights'=>$challengerWeights,'promotedBuckets'=>$promoted,'buckets'=>(array)($cached['buckets']??[]),'sourceSamples'=>$sourceSamples,'sourceNewestVerifiedAt'=>$sourceNewest,'cacheHit'=>true,'evaluatedAt'=>$cached['evaluatedAt']??null]);
        }
        $st = $pdo->prepare("SELECT model_name,metric,horizon_hours,target_time,predicted_value,observed_value,error_value,brier_score,verified_at FROM model_skill_samples WHERE device_id=:d AND location_key=:l AND verified_at<>'' AND model_name<>'consensus' AND metric IN ('rain','storm','snow') ORDER BY verified_at DESC LIMIT 15000");
        $st->execute([':d'=>$device,':l'=>$location]);
        $rows = $st->fetchAll();
        $challengerWeights = meteonexa_reliability_skill_weights_from_rows($rows, $reference);
        $buckets =[];
        $active = $championWeights;
        $promoted = 0;
        foreach (['rain','storm','snow'] as $metric) foreach ([1,3,6,24,48,72] as $h) {
            $bucket = meteonexa_reliability_shadow_bucket($rows,$metric,$h,$reference);
            $buckets[] = $bucket;
            if (empty($bucket['promoted'])||!isset($challengerWeights[$metric][$h]))continue;
            $active[$metric][$h] = $challengerWeights[$metric][$h];
            $promoted++;
        }
        $evaluatedAt = gmdate('c',$reference);
        $cacheValue =['version'=>$policy['version'],'season'=>$season,'seasonSignature'=>$seasonSignature,'sourceSamples'=>$sourceSamples,'sourceNewestVerifiedAt'=>$sourceNewest,'challengerWeights'=>$challengerWeights,'buckets'=>$buckets,'evaluatedAt'=>$evaluatedAt];
        meteonexa_reliability_tournament_cache_write($pdo,$key,$cacheValue);
        return array_replace($fallback,['available'=>true,'mode'=>$promoted>0 ? 'guarded-challenger' : 'champion','activeWeights'=>$active,'challengerWeights'=>$challengerWeights,'promotedBuckets'=>$promoted,'buckets'=>$buckets,'sourceSamples'=>$sourceSamples,'sourceNewestVerifiedAt'=>$sourceNewest,'cacheHit'=>false,'evaluatedAt'=>$evaluatedAt]);
    } catch (Throwable $e) {
        return $fallback;
    }
}
function meteonexa_reliability_diagram(PDO $pdo, string $device, string $location, string $metric, int $horizonHours, int $bins = 5, ?int $referenceTime = null) : array {
    $horizon = in_array($horizonHours,[1,3,6,24,48,72], true) ? $horizonHours : 24;
    $binCount = max(4, min(10, $bins));
    if (!in_array($metric,['rain','storm','snow'], true)||!meteonexa_db_table_exists($pdo, 'model_skill_samples'))return['available'=>false,'metric'=>$metric,'horizonHours'=>$horizon,'bins'=>[],'samples'=>0,'effectiveSamples'=>0.0,'brier'=>null];
    try {
        $reference = $referenceTime ?? time();
        $halfLifeDays = 30.0;
        $st = $pdo->prepare("SELECT predicted_value,observed_value,brier_score,verified_at FROM model_skill_samples WHERE device_id=:d AND location_key=:l AND model_name='consensus' AND metric=:m AND horizon_hours=:h AND verified_at<>''");
        $st->execute([':d'=>$device, ':l'=>$location, ':m'=>$metric, ':h'=>$horizon]);
        $bucket = array_fill(0, $binCount,['samples'=>0,'weight'=>0.0,'forecast'=>0.0,'observed'=>0.0,'brier'=>0.0]);
        $samples = 0;
        $effective = 0.0;
        $weightedBrier = 0.0;
        foreach ($st->fetchAll() as $r) {
            if (!is_numeric($r['predicted_value']??null)||!is_numeric($r['observed_value']??null))continue;
            $probability = meteonexa_intel_clamp((float)$r['predicted_value'], 0, 1);
            $observed = (float)$r['observed_value']>=.5 ? 1.0 : 0.0;
            $brier = is_numeric($r['brier_score']??null) ? max(0.0, (float)$r['brier_score']) : meteonexa_intelq_brier($probability, $observed);
            $weight = meteonexa_reliability_decay_weight($r['verified_at']??'', $halfLifeDays, $reference);
            if ($weight<=0.0)continue;
            $index = min($binCount - 1, (int)floor($probability * $binCount));
            $bucket[$index]['samples']++;
            $bucket[$index]['weight']+=$weight;
            $bucket[$index]['forecast']+=$probability * $weight;
            $bucket[$index]['observed']+=$observed * $weight;
            $bucket[$index]['brier']+=$brier * $weight;
            $samples++;
            $effective+=$weight;
            $weightedBrier+=$brier * $weight;
        }
        $rows =[];
        $weightedGap = 0.0;
        foreach ($bucket as $index=>$item) {
            $weight =(float)$item['weight'];
            if ($weight<=0.0)continue;
            $forecast =(float)$item['forecast'] / $weight;
            $observed =(float)$item['observed'] / $weight;
            $low = $index / $binCount;
            $high =($index + 1) / $binCount;
            $gap = abs($forecast - $observed);
            $weightedGap+=$gap * $weight;
            $rows[] =[
                'rangePct'=>[(int)round($low * 100),(int)round($high * 100)],
                'forecastPct'=>(int)round($forecast * 100),
                'observedPct'=>(int)round($observed * 100),
                'calibrationGapPct'=>(int)round($gap * 100),
                'samples'=>(int)$item['samples'],
                'effectiveSamples'=>round($weight, 2),
                'brier'=>round((float)$item['brier'] / $weight, 4),
            ];
        }
        $level = meteonexa_reliability_sample_level($samples);
        return[
            'available'=>$samples>0,
            'publishable'=>!empty($level['publishable']),
            'metric'=>$metric,
            'horizonHours'=>$horizon,
            'season'=>meteonexa_reliability_season($reference),
            'decayHalfLifeDays'=>(int)$halfLifeDays,
            'samples'=>$samples,
            'effectiveSamples'=>round($effective, 2),
            'brier'=>$effective>0.0 ? round($weightedBrier / $effective, 4) : null,
            'meanCalibrationGapPct'=>$effective>0.0 ? round(100 * $weightedGap / $effective, 1) : null,
            'calibrationLevel'=>$level['id'],
            'bins'=>$rows,
        ];
    } catch (Throwable $e) {
        return['available'=>false,'metric'=>$metric,'horizonHours'=>$horizon,'bins'=>[],'samples'=>0,'effectiveSamples'=>0.0,'brier'=>null];
    }
}
