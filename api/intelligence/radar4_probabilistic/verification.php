<?php
declare(strict_types=1);
function meteonexa_radar4_event_prediction_queue(PDO $pdo, string $deviceId, string $locationKey, array $shadow, array $context = []): int {
    if (!meteonexa_db_table_exists($pdo, 'radar4_event_predictions') || empty($shadow['available'])) return 0;
    $now = time();
    $issued = gmdate('c', $now);
    $driver = meteonexa_pdo_driver($pdo);
    $verb = $driver === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
    $hasP33 = meteonexa_db_column_exists($pdo, 'radar4_event_predictions', 'area_key');
    $columns = 'device_id,location_key,prediction_key,event_kind,issued_at,p10_at,p50_at,p90_at,event_probability,confidence,predicted_value,status,verified_at,observed_at,observed_value,error_minutes,absolute_error_minutes,absolute_error_value,within_interval,ground_truth_source,ground_truth_quality,ground_truth_json,verification_method';
    $values = ":d,:l,:k,:kind,:issued,:p10,:p50,:p90,:prob,:confidence,:pred,'pending','', '',NULL,NULL,NULL,NULL,NULL,'',0,'',''";
    if ($hasP33) {
        $columns .= ',area_key,distance_band,coverage_band,season,weather_regime,terrain_class,terrain_relief_m,terrain_gradient_pct,event_observed,calibrated_probability,probability_brier,calibration_context_json';
        $values .= ",:area,:distance,:coverage,:season,:regime,:terrain,:relief,:gradient,NULL,:calibrated,NULL,:context";
    }
    $columns .= ',created_at';
    $values .= ',:created';
    $st = $pdo->prepare($verb . ' INTO radar4_event_predictions(' . $columns . ') VALUES(' . $values . ')');
    $inserted = 0;
    foreach (['rain_start'=>'rainStart', 'rain_peak'=>'rainPeak', 'rain_end'=>'rainEnd'] as $kind=>$field) {
        $distribution = (array)($shadow[$field] ?? []);
        if (empty($distribution['available']) || !is_numeric($distribution['p50Minutes'] ?? null)) continue;
        $p10 = $now + (int)$distribution['p10Minutes'] * 60;
        $p50 = $now + (int)$distribution['p50Minutes'] * 60;
        $p90 = $now + (int)$distribution['p90Minutes'] * 60;
        $key = substr(hash('sha256', $deviceId . '|' . $locationKey . '|radar4-p33|' . $kind . '|' . gmdate('Y-m-d\TH:i', intdiv($now, 300) * 300)), 0, 48);
        $params = [
            ':d'=>$deviceId, ':l'=>$locationKey, ':k'=>$key, ':kind'=>$kind, ':issued'=>$issued,
            ':p10'=>gmdate('c', $p10), ':p50'=>gmdate('c', $p50), ':p90'=>gmdate('c', $p90),
            ':prob'=>(float)($distribution['eventProbabilityPct'] ?? 0), ':confidence'=>(int)($shadow['confidenceCalibration']['calibratedConfidence'] ?? 0), ':pred'=>null, ':created'=>$issued,
        ];
        if ($hasP33) {
            $empirical = (array)($distribution['empiricalCalibration'] ?? []);
            $params += [
                ':area'=>(string)($context['areaKey'] ?? ''), ':distance'=>(string)($context['distanceBand'] ?? 'unknown'),
                ':coverage'=>(string)($context['coverageBand'] ?? 'unknown'), ':season'=>(string)($context['season'] ?? 'unknown'),
                ':regime'=>(string)($context['weatherRegime'] ?? 'unknown'), ':terrain'=>(string)($context['terrainClass'] ?? 'unknown'),
                ':relief'=>$context['terrainReliefM'] ?? null, ':gradient'=>$context['terrainGradientPct'] ?? null,
                ':calibrated'=>is_numeric($empirical['calibratedProbabilityPct'] ?? null) ? (float)$empirical['calibratedProbabilityPct'] : (float)($distribution['eventProbabilityPct'] ?? 0),
                ':context'=>json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }
        $st->execute($params);
        $inserted += $st->rowCount() > 0 ? 1 : 0;
    }
    $growth = (array)($shadow['growthDecay'] ?? []);
    if (is_numeric($growth['predictedScore'] ?? null)) {
        $target = $now + max(10, min(60, (int)($growth['verificationTargetMinutes'] ?? 30))) * 60;
        $key = substr(hash('sha256', $deviceId . '|' . $locationKey . '|radar4-p33|growth|' . gmdate('Y-m-d\TH:i', intdiv($now, 300) * 300)), 0, 48);
        $params = [
            ':d'=>$deviceId, ':l'=>$locationKey, ':k'=>$key, ':kind'=>'growth_decay', ':issued'=>$issued,
            ':p10'=>gmdate('c', $target - 600), ':p50'=>gmdate('c', $target), ':p90'=>gmdate('c', $target + 600),
            ':prob'=>100, ':confidence'=>(int)($shadow['confidenceCalibration']['calibratedConfidence'] ?? 0), ':pred'=>(float)$growth['predictedScore'], ':created'=>$issued,
        ];
        if ($hasP33) {
            $params += [
                ':area'=>(string)($context['areaKey'] ?? ''), ':distance'=>(string)($context['distanceBand'] ?? 'unknown'),
                ':coverage'=>(string)($context['coverageBand'] ?? 'unknown'), ':season'=>(string)($context['season'] ?? 'unknown'),
                ':regime'=>(string)($context['weatherRegime'] ?? 'unknown'), ':terrain'=>(string)($context['terrainClass'] ?? 'unknown'),
                ':relief'=>$context['terrainReliefM'] ?? null, ':gradient'=>$context['terrainGradientPct'] ?? null,
                ':calibrated'=>100.0, ':context'=>json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }
        $st->execute($params);
        $inserted += $st->rowCount() > 0 ? 1 : 0;
    }
    return $inserted;
}

function meteonexa_radar4_observation_series(PDO $pdo, string $deviceId, string $locationKey, int $fromTs, int $toTs): array {
    if (!meteonexa_db_table_exists($pdo, 'observation_evidence')) return [];
    try {
        $st = $pdo->prepare('SELECT observed_at,precipitation,rain_event,storm_event,quality_score,source_type,distance_km FROM observation_evidence WHERE device_id=:d AND location_key=:l AND observed_at>=:f AND observed_at<=:t ORDER BY observed_at ASC,quality_score DESC');
        $st->execute([':d'=>$deviceId, ':l'=>$locationKey, ':f'=>gmdate('c', $fromTs), ':t'=>gmdate('c', $toTs)]);
        $buckets = [];
        foreach ($st->fetchAll() as $row) {
            $ts = meteonexa_verification_ts($row['observed_at'] ?? '');
            if ($ts === null) continue;
            $key = gmdate('Y-m-d\TH:i', $ts);
            $quality = (int)($row['quality_score'] ?? 0);
            if (isset($buckets[$key]) && (int)$buckets[$key]['quality'] >= $quality) continue;
            $precip = is_numeric($row['precipitation'] ?? null) ? max(0.0, (float)$row['precipitation']) : null;
            $rain = is_numeric($row['rain_event'] ?? null) ? (float)$row['rain_event'] : 0.0;
            $storm = is_numeric($row['storm_event'] ?? null) ? (float)$row['storm_event'] : 0.0;
            $wet = ($precip !== null && $precip >= .1) || $rain >= .5 || $storm >= .5;
            $buckets[$key] = [
                'ts'=>$ts, 'at'=>gmdate('c', $ts), 'wet'=>$wet, 'precipitation'=>$precip,
                'quality'=>$quality, 'source'=>(string)($row['source_type'] ?? 'observation'),
                'distanceKm'=>is_numeric($row['distance_km'] ?? null) ? (float)$row['distance_km'] : null,
            ];
        }
        return array_values($buckets);
    } catch (Throwable $ignored) {
        return [];
    }
}

function meteonexa_radar4_truth_from_series(array $series, string $kind, int $issuedTs): ?array {
    $good = array_values(array_filter($series, static fn($row)=>(int)($row['quality'] ?? 0) >= 55));
    if (!$good) return null;

    
    
    
    $stateAtIssue = null;
    foreach ($good as $row) {
        if ((int)($row['ts'] ?? 0) > $issuedTs) break;
        $stateAtIssue = $row;
    }

    if ($kind === 'rain_start') {
        if ($stateAtIssue && !empty($stateAtIssue['wet'])) {
            return ['observedTs'=>$issuedTs, 'value'=>1.0, 'source'=>$stateAtIssue['source'], 'quality'=>(int)$stateAtIssue['quality'], 'detail'=>$stateAtIssue, 'stateAtIssue'=>'wet'];
        }
        foreach ($good as $row) {
            if ((int)($row['ts'] ?? 0) < $issuedTs || empty($row['wet'])) continue;
            return ['observedTs'=>(int)$row['ts'], 'value'=>1.0, 'source'=>$row['source'], 'quality'=>(int)$row['quality'], 'detail'=>$row, 'stateAtIssue'=>'dry_or_unknown'];
        }
        return null;
    }
    if ($kind === 'rain_peak') {
        $best = null;
        foreach ($good as $row) {
            if ((int)($row['ts'] ?? 0) < $issuedTs || !is_numeric($row['precipitation'] ?? null)) continue;
            if ($best === null || (float)$row['precipitation'] > (float)$best['precipitation']) $best = $row;
        }
        if (!$best || (float)$best['precipitation'] < .1) return null;
        return ['observedTs'=>(int)$best['ts'], 'value'=>(float)$best['precipitation'], 'source'=>$best['source'], 'quality'=>(int)$best['quality'], 'detail'=>$best];
    }
    if ($kind === 'rain_end') {
        $seenWet = $stateAtIssue && !empty($stateAtIssue['wet']);
        $lastWetTs = $seenWet ? $issuedTs : null;
        foreach ($good as $row) {
            $rowTs = (int)($row['ts'] ?? 0);
            if ($rowTs < $issuedTs) continue;
            if (!empty($row['wet'])) {
                $seenWet = true;
                $lastWetTs = $rowTs;
                continue;
            }
            if ($seenWet && $lastWetTs !== null && $rowTs >= $lastWetTs + 600) {
                return ['observedTs'=>$rowTs, 'value'=>0.0, 'source'=>$row['source'], 'quality'=>(int)$row['quality'], 'detail'=>$row];
            }
        }
        return null;
    }
    if ($kind === 'growth_decay') {
        $early = null;
        $late = null;
        foreach ($good as $row) {
            if (!is_numeric($row['precipitation'] ?? null)) continue;
            $ageMin = ((int)$row['ts'] - $issuedTs) / 60;
            if ($ageMin >= 0 && $ageMin <= 15 && ($early === null || (int)$row['quality'] > (int)$early['quality'])) $early = $row;
            if ($ageMin >= 20 && $ageMin <= 50 && ($late === null || (int)$row['quality'] > (int)$late['quality'])) $late = $row;
        }
        if (!$early || !$late) return null;
        $a = (float)$early['precipitation'];
        $b = (float)$late['precipitation'];
        $den = max(.2, max($a, $b));
        $score = meteonexa_radar4_p32_clamp(100 * ($b - $a) / $den, -100, 100);
        return [
            'observedTs'=>(int)$late['ts'], 'value'=>round($score, 1), 'source'=>'observation-trend',
            'quality'=>(int)round(((int)$early['quality'] + (int)$late['quality']) / 2),
            'detail'=>['early'=>$early, 'late'=>$late],
        ];
    }
    return null;
}

function meteonexa_radar4_negative_event_truth(array $series, int $issuedTs, int $minimumSpanMinutes = 90): ?array {
    $good = array_values(array_filter($series, static fn($row)=>(int)($row['quality'] ?? 0) >= 65 && (int)($row['ts'] ?? 0) >= $issuedTs));
    if (count($good) < 4) return null;
    $firstTs = (int)($good[0]['ts'] ?? 0);
    $lastTs = (int)($good[count($good) - 1]['ts'] ?? 0);
    if (($lastTs - $firstTs) < $minimumSpanMinutes * 60) return null;
    foreach ($good as $row) if (!empty($row['wet'])) return null;
    $qualities = array_map(static fn($row)=>(int)($row['quality'] ?? 0), $good);
    $sources = array_values(array_unique(array_map(static fn($row)=>(string)($row['source'] ?? 'observation'), $good)));
    return [
        'observedTs'=>$lastTs,
        'value'=>0.0,
        'source'=>'independent-negative-series',
        'quality'=>(int)round(array_sum($qualities) / max(1, count($qualities))),
        'detail'=>['sampleCount'=>count($good),'spanMinutes'=>round(($lastTs - $firstTs) / 60,1),'sources'=>$sources],
    ];
}

function meteonexa_radar4_event_skill_summary(PDO $pdo, string $deviceId, string $locationKey): array {
    if (!meteonexa_db_table_exists($pdo, 'radar4_event_predictions')) return ['available'=>false, 'samples'=>0, 'learning'=>true];
    $result = ['available'=>false, 'samples'=>0, 'learning'=>true, 'byEvent'=>[], 'promotionEligible'=>false, 'authorityLockedToRadar3'=>true];
    try {
        $hasP33 = meteonexa_db_column_exists($pdo, 'radar4_event_predictions', 'event_observed');
        $extra = $hasP33
            ? ',SUM(CASE WHEN event_observed=1 THEN 1 ELSE 0 END) positive_samples,SUM(CASE WHEN event_observed=0 THEN 1 ELSE 0 END) negative_samples,AVG(probability_brier) brier_score'
            : ',0 positive_samples,0 negative_samples,NULL brier_score';
        $st = $pdo->prepare("SELECT event_kind,COUNT(*) samples,AVG(absolute_error_minutes) mae_minutes,AVG(absolute_error_value) mae_value,AVG(CASE WHEN within_interval=1 THEN 1.0 WHEN within_interval=0 THEN 0.0 ELSE NULL END) interval_coverage" . $extra . " FROM radar4_event_predictions WHERE device_id=:d AND location_key=:l AND status='verified' GROUP BY event_kind");
        $st->execute([':d'=>$deviceId, ':l'=>$locationKey]);
        $total = 0;
        foreach ($st->fetchAll() as $row) {
            $kind = (string)$row['event_kind'];
            $samples = (int)($row['samples'] ?? 0);
            $positive = $hasP33 && $kind !== 'growth_decay' ? (int)($row['positive_samples'] ?? 0) : $samples;
            $negative = $hasP33 && $kind !== 'growth_decay' ? (int)($row['negative_samples'] ?? 0) : 0;
            $total += $samples;
            $result['byEvent'][$kind] = [
                'samples'=>$samples,
                'positiveSamples'=>$positive,
                'negativeSamples'=>$negative,
                'maeMinutes'=>is_numeric($row['mae_minutes'] ?? null) ? round((float)$row['mae_minutes'], 2) : null,
                'maeValue'=>is_numeric($row['mae_value'] ?? null) ? round((float)$row['mae_value'], 2) : null,
                'intervalCoveragePct'=>is_numeric($row['interval_coverage'] ?? null) ? round((float)$row['interval_coverage'] * 100, 1) : null,
                'brierScore'=>is_numeric($row['brier_score'] ?? null) ? round((float)$row['brier_score'], 4) : null,
                'learning'=>$positive < 30,
            ];
        }
        $result['samples'] = $total;
        $result['available'] = $total > 0;
        $eventKinds = ['rain_start', 'rain_peak', 'rain_end', 'growth_decay'];
        $mature = true;
        foreach ($eventKinds as $kind) {
            $timingSamples = $kind === 'growth_decay'
                ? (int)($result['byEvent'][$kind]['samples'] ?? 0)
                : (int)($result['byEvent'][$kind]['positiveSamples'] ?? $result['byEvent'][$kind]['samples'] ?? 0);
            if ($timingSamples < 30) $mature = false;
        }
        $result['learning'] = !$mature;
        $result['datasetMature'] = $mature;
        $result['minimumPositiveTimingSamplesPerEvent'] = 30;
        $result['promotionEligible'] = false;
        $result['reason'] = $mature ? 'local_timing_skill_mature_global_p33_gate_still_required' : 'collecting_live_event_samples';
        return $result;
    } catch (Throwable $ignored) {
        $result['reason'] = 'skill_query_failed';
        return $result;
    }
}

function meteonexa_radar4_event_verification_update(PDO $pdo, string $deviceId, string $locationKey, array $shadow, array $context = []): array {
    $queued = meteonexa_radar4_event_prediction_queue($pdo, $deviceId, $locationKey, $shadow, $context);
    if (!meteonexa_db_table_exists($pdo, 'radar4_event_predictions')) return ['available'=>false, 'queued'=>$queued, 'verified'=>0, 'skill'=>['available'=>false]];
    $now = time();
    $verified = 0;
    $hasP33 = meteonexa_db_column_exists($pdo, 'radar4_event_predictions', 'event_observed');
    try {
        $st = $pdo->prepare("SELECT * FROM radar4_event_predictions WHERE device_id=:d AND location_key=:l AND status='pending' ORDER BY id ASC LIMIT 120");
        $st->execute([':d'=>$deviceId, ':l'=>$locationKey]);
        foreach ($st->fetchAll() as $row) {
            $kind = (string)($row['event_kind'] ?? '');
            $issuedTs = meteonexa_verification_ts($row['issued_at'] ?? '');
            $p50Ts = meteonexa_verification_ts($row['p50_at'] ?? '');
            $p90Ts = meteonexa_verification_ts($row['p90_at'] ?? '');
            if ($issuedTs === null || $p50Ts === null || $p90Ts === null) continue;
            $maturityTs = $kind === 'growth_decay' ? $issuedTs + 55 * 60 : $issuedTs + 150 * 60;
            if ($now < $maturityTs) continue;
            $series = meteonexa_radar4_observation_series($pdo, $deviceId, $locationKey, $issuedTs - 900, $issuedTs + 150 * 60);
            $truth = meteonexa_radar4_truth_from_series($series, $kind, $issuedTs);
            $eventObserved = null;
            $negative = false;
            if (!$truth && $hasP33 && $kind !== 'growth_decay') {
                
                
                
                $truth = meteonexa_radar4_negative_event_truth($series, $issuedTs, 90);
                if ($truth) {
                    $eventObserved = 0;
                    $negative = true;
                }
            }
            if (!$truth) {
                if ($now >= $issuedTs + 6 * 3600) {
                    $up = $pdo->prepare("UPDATE radar4_event_predictions SET status='expired_unverified',verified_at=:v,verification_method='insufficient-independent-observation' WHERE id=:id AND status='pending'");
                    $up->execute([':v'=>gmdate('c', $now), ':id'=>(int)$row['id']]);
                }
                continue;
            }
            $p10Ts = meteonexa_verification_ts($row['p10_at'] ?? '') ?? $p50Ts;
            $observedTs = (int)$truth['observedTs'];
            $errMin = null;
            $absErrMin = null;
            $valueErr = null;
            $absValueErr = null;
            $within = null;
            if ($kind === 'growth_decay') {
                $predictedValue = is_numeric($row['predicted_value'] ?? null) ? (float)$row['predicted_value'] : 0.0;
                $valueErr = round((float)$truth['value'] - $predictedValue, 2);
                $absValueErr = abs($valueErr);
            } elseif (!$negative) {
                $eventObserved = 1;
                $errMin = round(($observedTs - $p50Ts) / 60, 2);
                $absErrMin = abs($errMin);
                $within = $observedTs >= $p10Ts && $observedTs <= $p90Ts ? 1 : 0;
            } else {
                $within = 0;
            }

            $p33Sql = '';
            $params = [
                ':v'=>gmdate('c', $now), ':o'=>gmdate('c', $observedTs), ':ov'=>$truth['value'] ?? null,
                ':em'=>$errMin, ':aem'=>$absErrMin, ':aev'=>$absValueErr, ':wi'=>$within,
                ':gs'=>(string)($truth['source'] ?? 'observation'), ':gq'=>(int)($truth['quality'] ?? 0),
                ':gj'=>json_encode($truth, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':id'=>(int)$row['id'],
            ];
            if ($hasP33 && $kind !== 'growth_decay') {
                $probabilityPct = is_numeric($row['calibrated_probability'] ?? null) ? (float)$row['calibrated_probability'] : (float)($row['event_probability'] ?? 0);
                $probability = max(0.0, min(1.0, $probabilityPct / 100));
                $brier = ($probability - (int)$eventObserved) ** 2;
                $p33Sql = ',event_observed=:eo,probability_brier=:pb';
                $params[':eo'] = (int)$eventObserved;
                $params[':pb'] = round($brier, 6);
            }
            $method = $negative ? 'independent-negative-observation-series' : 'independent-observation-series';
            $up = $pdo->prepare("UPDATE radar4_event_predictions SET status='verified',verified_at=:v,observed_at=:o,observed_value=:ov,error_minutes=:em,absolute_error_minutes=:aem,absolute_error_value=:aev,within_interval=:wi,ground_truth_source=:gs,ground_truth_quality=:gq,ground_truth_json=:gj,verification_method='" . $method . "'" . $p33Sql . " WHERE id=:id AND status='pending'");
            $up->execute($params);
            $verified += $up->rowCount() > 0 ? 1 : 0;
        }
    } catch (Throwable $ignored) {
    }
    return [
        'available'=>true,
        'queued'=>$queued,
        'verified'=>$verified,
        'skill'=>meteonexa_radar4_event_skill_summary($pdo, $deviceId, $locationKey),
        'policy'=>['promotionDisabledInP32'=>true, 'promotionDisabledInP33'=>true, 'authorityLockedToRadar3'=>true],
    ];
}
