<?php
declare(strict_types=1);

/**
 * Radar4 P3.2 probabilistic shadow layer.
 *
 * This layer is deliberately non-authoritative. It converts Radar4 tracks and
 * independent supporting evidence into separate rain start / peak / end timing
 * distributions, then persists those predictions for retrospective skill
 * verification. Official warnings are contextual evidence only and retain their
 * own authority/lifecycle outside MeteoNexa's forecast decision path.
 */
function meteonexa_radar4_p32_clamp(float $value, float $min = 0.0, float $max = 100.0): float {
    return max($min, min($max, $value));
}

function meteonexa_radar4_dominant_cell(array $radar4): ?array {
    $preferred = (string)($radar4['dominantCellId'] ?? '');
    $best = null;
    foreach ((array)($radar4['cells'] ?? []) as $cell) {
        if (!is_array($cell)) continue;
        if ($preferred !== '' && (string)($cell['id'] ?? '') === $preferred) return $cell;
        if (!is_numeric($cell['etaMinutes'] ?? null)) continue;
        if ($best === null || (float)($cell['energy'] ?? 0) > (float)($best['energy'] ?? 0)) $best = $cell;
    }
    return $best;
}

function meteonexa_radar4_official_support(array $official): array {
    if (empty($official['relevant'])) return ['available'=>false, 'supportPct'=>0, 'severity'=>'none', 'authority'=>'official-only'];
    $rank = ['green'=>10, 'yellow'=>45, 'orange'=>72, 'red'=>92, 'minor'=>35, 'moderate'=>52, 'severe'=>75, 'extreme'=>94];
    $support = 30;
    $severity = 'unknown';
    foreach ((array)$official['relevant'] as $row) {
        if (!is_array($row)) continue;
        $candidate = strtolower(trim((string)($row['severity'] ?? $row['level'] ?? $row['awarenessLevel'] ?? '')));
        foreach ($rank as $needle=>$score) {
            if ($candidate === $needle || str_contains($candidate, $needle)) {
                if ($score > $support) {
                    $support = $score;
                    $severity = $needle;
                }
            }
        }
    }
    return [
        'available'=>true,
        'supportPct'=>(int)round(meteonexa_radar4_p32_clamp($support)),
        'severity'=>$severity,
        'authority'=>'official-only',
        'geospatial'=>!empty($official['geospatial']),
        'mode'=>$official['mode'] ?? 'unknown',
    ];
}

function meteonexa_radar4_evidence_fusion(array $radar4, array $nowcastV4, array $consensus, array $lightning, array $satellite, array $observations, array $official): array {
    $cell = meteonexa_radar4_dominant_cell($radar4);
    $sources = [];
    $weighted = 0.0;
    $weightSum = 0.0;
    $add = static function(string $id, bool $available, float $score, float $weight, array $meta = []) use (&$sources, &$weighted, &$weightSum): void {
        $score = meteonexa_radar4_p32_clamp($score);
        $sources[] = array_merge(['id'=>$id, 'available'=>$available, 'supportPct'=>(int)round($score), 'weight'=>$weight], $meta);
        if (!$available) return;
        $weighted += $score * $weight;
        $weightSum += $weight;
    };

    $trackConfidence = (int)($cell['trackConfidence'] ?? 0);
    $peak = (float)($cell['peak'] ?? 0);
    $energy = (float)($cell['energy'] ?? 0);
    $radarSupport = $cell ? min(100.0, $trackConfidence * .72 + min(16.0, $peak * 18) + min(12.0, $energy * .8)) : 0.0;
    $add('radar4', $cell !== null && !empty($radar4['available']), $radarSupport, .45, ['authoritative'=>false, 'mode'=>'shadow']);

    $lightAvailable = !empty($lightning['available']) && empty($lightning['degraded']);
    $lightCount = (int)($lightning['recent30m'] ?? 0);
    $lightDistance = is_numeric($lightning['nearestKm'] ?? null) ? (float)$lightning['nearestKm'] : null;
    $lightScore = $lightCount * 10 + ($lightDistance === null ? 0 : max(0, 45 - $lightDistance) * 1.4) + (!empty($lightning['approaching']) ? 12 : 0);
    $add('lightning', $lightAvailable, $lightScore, .14, ['authoritative'=>false]);

    $satAvailable = !empty($satellite['available']);
    $satScore = $satAvailable ? (float)($satellite['cloudAttenuationPct'] ?? ((float)($satellite['support'] ?? 0) * 100)) : 0.0;
    $add('satellite', $satAvailable, $satScore, .10, ['authoritative'=>false]);

    $obsAvailable = !empty($observations['available']) || !empty($observations['evidence']);
    $obsQuality = (int)($observations['qualityScore'] ?? 0);
    $obsRain = max((float)($observations['rain'] ?? 0), min(1.0, (float)($observations['precipitation'] ?? 0) / 2), (float)($observations['storm'] ?? 0));
    $obsScore = $obsRain * 78 + $obsQuality * .22;
    $add('observations', $obsAvailable, $obsScore, .14, ['authoritative'=>false, 'qualityScore'=>$obsQuality, 'count'=>(int)($observations['sourceCount'] ?? count((array)($observations['evidence'] ?? [])))]);

    $modelPeak = 0.0;
    foreach ((array)($nowcastV4['timeline'] ?? []) as $row) $modelPeak = max($modelPeak, (float)($row['rainProbabilityPct'] ?? 0));
    if ($modelPeak <= 0) $modelPeak = (float)($consensus['primary']['weightedAgreementPct'] ?? $consensus['primary']['agreementPct'] ?? 0);
    $modelAvailable = !empty($nowcastV4['available']) || !empty($consensus['hourly']) || !empty($consensus['modelsAvailable']);
    $add('models', $modelAvailable, $modelPeak, .13, ['authoritative'=>false, 'count'=>(int)($consensus['modelsAvailable'] ?? 0)]);

    $officialSupport = meteonexa_radar4_official_support($official);
    $add('official-warning-context', !empty($officialSupport['available']), (float)$officialSupport['supportPct'], .04, ['authoritative'=>true, 'authorityScope'=>'warning-only', 'severity'=>$officialSupport['severity']]);

    $score = $weightSum > 0 ? $weighted / $weightSum : 0.0;
    return [
        'available'=>$weightSum > 0 && $cell !== null,
        'supportPct'=>(int)round(meteonexa_radar4_p32_clamp($score)),
        'sources'=>$sources,
        'officialWarningContext'=>$officialSupport,
        'policy'=>[
            'shadowOnly'=>true,
            'radar4NonAuthoritative'=>true,
            'officialWarningsRemainSeparateAuthority'=>true,
            'productionDecisionsUnaffected'=>true,
        ],
    ];
}

function meteonexa_radar4_confidence_calibration(array $radar4, array $observations, array $satellite, ?float $elevationM = null, ?float $archiveDistanceKm = null): array {
    $cell = meteonexa_radar4_dominant_cell($radar4);
    if (!$cell) return ['available'=>false, 'rawConfidence'=>0, 'calibratedConfidence'=>0];
    $raw = (float)($cell['trackConfidence'] ?? 0);
    $flowConfidence = (float)($radar4['flow']['confidence'] ?? 0);
    $spread = is_numeric($radar4['flow']['vectorSpread'] ?? null) ? (float)$radar4['flow']['vectorSpread'] : 0.0;
    $obsQuality = (float)($observations['qualityScore'] ?? 0);
    $obsCount = (int)($observations['sourceCount'] ?? count((array)($observations['evidence'] ?? [])));
    $sourceCoverage = 1 + (!empty($satellite['available']) ? 1 : 0) + ($obsCount > 0 ? 1 : 0) + ((int)($radar4['flow']['pairCount'] ?? 0) >= 2 ? 1 : 0);

    $flowModifier = ($flowConfidence - 55) * .08;
    $observationModifier = $obsCount > 0 ? (($obsQuality - 60) * .09 + min(4, $obsCount - 1)) : -4.0;
    $coverageModifier = min(6.0, max(-3.0, ($sourceCoverage - 2) * 2.2));
    $distancePenalty = $archiveDistanceKm === null ? 0.0 : min(15.0, max(0.0, $archiveDistanceKm - 10) * .18);
    $spreadPenalty = min(18.0, $spread * 42);
    $terrainPenalty = 0.0;
    if ($elevationM !== null) {
        if ($elevationM >= 2500) $terrainPenalty = 12.0;
        elseif ($elevationM >= 1800) $terrainPenalty = 8.0;
        elseif ($elevationM >= 1000) $terrainPenalty = 4.0;
        elseif ($elevationM >= 600) $terrainPenalty = 2.0;
    }
    $satellitePenalty = empty($satellite['available']) ? 3.0 : 0.0;
    $calibrated = meteonexa_radar4_p32_clamp($raw + $flowModifier + $observationModifier + $coverageModifier - $distancePenalty - $spreadPenalty - $terrainPenalty - $satellitePenalty, 12, 97);
    return [
        'available'=>true,
        'rawConfidence'=>(int)round($raw),
        'calibratedConfidence'=>(int)round($calibrated),
        'inputs'=>[
            'flowConfidence'=>(int)round($flowConfidence),
            'vectorSpread'=>round($spread, 3),
            'observationQualityScore'=>(int)round($obsQuality),
            'observationSourceCount'=>$obsCount,
            'evidenceCoverageCount'=>$sourceCoverage,
            'radarArchiveDistanceKm'=>$archiveDistanceKm === null ? null : round($archiveDistanceKm, 1),
            'terrainElevationM'=>$elevationM === null ? null : round($elevationM),
        ],
        'modifiers'=>[
            'flow'=>round($flowModifier, 1),
            'observations'=>round($observationModifier, 1),
            'coverage'=>round($coverageModifier, 1),
            'radarDistancePenalty'=>round($distancePenalty, 1),
            'vectorSpreadPenalty'=>round($spreadPenalty, 1),
            'orographyPenalty'=>round($terrainPenalty, 1),
            'satelliteAvailabilityPenalty'=>round($satellitePenalty, 1),
        ],
        'policy'=>[
            'orographyUsesElevationProxyOnly'=>true,
            'noTerrainSlopeInference'=>true,
            'confidenceCalibrationShadowOnly'=>true,
        ],
    ];
}

function meteonexa_radar4_time_distribution(?float $meanMinute, float $sigmaMinutes, float $eventProbabilityPct, int $horizonMinutes = 120, int $stepMinutes = 5): array {
    if ($meanMinute === null || !is_finite($meanMinute)) return ['available'=>false, 'eventProbabilityPct'=>(int)round(meteonexa_radar4_p32_clamp($eventProbabilityPct)), 'byMinutes'=>[]];
    $horizonMinutes = max(30, min(180, $horizonMinutes));
    $stepMinutes = max(1, min(15, $stepMinutes));
    $meanMinute = meteonexa_radar4_p32_clamp($meanMinute, 0, $horizonMinutes);
    $sigmaMinutes = max(3.0, min(45.0, $sigmaMinutes));
    $weights = [];
    $total = 0.0;
    for ($minute = 0; $minute <= $horizonMinutes; $minute += $stepMinutes) {
        $z = ($minute - $meanMinute) / $sigmaMinutes;
        $w = exp(-.5 * $z * $z);
        $weights[$minute] = $w;
        $total += $w;
    }
    $quantile = static function(float $q) use ($weights, $total): int {
        $acc = 0.0;
        foreach ($weights as $minute=>$w) {
            $acc += $w;
            if ($total > 0 && $acc / $total >= $q) return (int)$minute;
        }
        return (int)array_key_last($weights);
    };
    $eventProbabilityPct = meteonexa_radar4_p32_clamp($eventProbabilityPct);
    $cdf = 0.0;
    $rows = [];
    foreach ($weights as $minute=>$w) {
        $fraction = $total > 0 ? $w / $total : 0.0;
        $cdf += $fraction;
        $rows[] = [
            'minute'=>(int)$minute,
            'probabilityMassPct'=>round($eventProbabilityPct * $fraction, 2),
            'cumulativePct'=>round($eventProbabilityPct * $cdf, 1),
        ];
    }
    return [
        'available'=>true,
        'eventProbabilityPct'=>(int)round($eventProbabilityPct),
        'p10Minutes'=>$quantile(.10),
        'p50Minutes'=>$quantile(.50),
        'p90Minutes'=>$quantile(.90),
        'mostLikelyRangeMinutes'=>[$quantile(.25), $quantile(.75)],
        'sigmaMinutes'=>round($sigmaMinutes, 1),
        'byMinutes'=>$rows,
    ];
}

function meteonexa_radar4_nowcast_peak_minute(array $nowcastV4): ?int {
    $bestMinute = null;
    $bestProbability = -1.0;
    foreach ((array)($nowcastV4['timeline'] ?? []) as $row) {
        $minute = (int)($row['minute'] ?? -1);
        if ($minute < 0 || $minute > 120) continue;
        $probability = (float)($row['rainProbabilityPct'] ?? 0);
        if ($probability > $bestProbability) {
            $bestProbability = $probability;
            $bestMinute = $minute;
        }
    }
    return $bestMinute;
}

function meteonexa_radar4_probabilistic_shadow(array $radar4, array $nowcastV4, array $consensus, array $lightning, array $satellite, array $observations, array $official, ?float $elevationM = null, ?float $archiveDistanceKm = null): array {
    $cell = meteonexa_radar4_dominant_cell($radar4);
    $fusion = meteonexa_radar4_evidence_fusion($radar4, $nowcastV4, $consensus, $lightning, $satellite, $observations, $official);
    $calibration = meteonexa_radar4_confidence_calibration($radar4, $observations, $satellite, $elevationM, $archiveDistanceKm);
    if (!$cell || empty($radar4['available'])) {
        return [
            'available'=>false,
            'mode'=>'shadow',
            'authoritative'=>false,
            'horizonMinutes'=>120,
            'method'=>'radar4-probabilistic-multievidence-v1',
            'fusion'=>$fusion,
            'confidenceCalibration'=>$calibration,
            'policy'=>['authorityLockedToRadar3'=>true, 'productionDecisionsUnaffected'=>true, 'officialWarningsRemainSeparateAuthority'=>true],
        ];
    }

    $confidence = (int)($calibration['calibratedConfidence'] ?? $cell['trackConfidence'] ?? 0);
    $eventProbability = (int)round(meteonexa_radar4_p32_clamp((float)($fusion['supportPct'] ?? 0) * .72 + $confidence * .28));
    $eta = is_numeric($cell['etaMinutes'] ?? null) ? (float)$cell['etaMinutes'] : null;
    $arrival = (array)($nowcastV4['arrivalDistribution'] ?? []);
    if ($eta === null && !empty($arrival['available'])) {
        $range = (array)($arrival['mostLikelyRangeMinutes'] ?? []);
        if (isset($range[0], $range[1]) && is_numeric($range[0]) && is_numeric($range[1])) $eta = ((float)$range[0] + (float)$range[1]) / 2;
    }

    $first = (array)(($nowcastV4['timeline'] ?? [])[0] ?? []);
    $currentlyWet = (float)($observations['rain'] ?? 0) >= .5 || (float)($observations['precipitation'] ?? 0) >= .1 || (int)($first['rainProbabilityPct'] ?? 0) >= 58;
    $growth = (float)($cell['growthDecayScore'] ?? 0);
    $speed = max(.015, (float)($cell['speedCellsMin'] ?? 0));
    $area = max(1, (int)($cell['areaPixels'] ?? 1));
    $passage = meteonexa_radar4_p32_clamp((sqrt($area) / $speed) * .65, 15, 70);
    $passage *= 1 + max(-.30, min(.35, $growth / 220));

    $startMean = $currentlyWet ? 0.0 : $eta;
    $peakFromNowcast = meteonexa_radar4_nowcast_peak_minute($nowcastV4);
    $trackPeak = $startMean === null ? null : min(120.0, $startMean + max(5.0, min(30.0, $passage * .38 + max(0.0, $growth) * .05)));
    $peakMean = $trackPeak;
    if ($peakFromNowcast !== null) $peakMean = $peakMean === null ? (float)$peakFromNowcast : $peakMean * .68 + $peakFromNowcast * .32;
    $endMean = $startMean === null ? null : min(120.0, $startMean + $passage);
    if (is_numeric($nowcastV4['rainEndMinutes'] ?? null)) $endMean = $endMean === null ? (float)$nowcastV4['rainEndMinutes'] : $endMean * .72 + (float)$nowcastV4['rainEndMinutes'] * .28;

    $spread = is_numeric($radar4['flow']['vectorSpread'] ?? null) ? (float)$radar4['flow']['vectorSpread'] : 0.0;
    $baseSigma = 5.0 + (100 - $confidence) * .18 + min(12.0, $spread * 30) + ($archiveDistanceKm === null ? 0 : min(8.0, $archiveDistanceKm * .08));
    $startProbability = $currentlyWet ? max(92, $eventProbability) : $eventProbability;
    $peakProbability = (int)round(meteonexa_radar4_p32_clamp($eventProbability + ($growth > 20 ? 5 : ($growth < -35 ? -8 : 0))));
    $endProbability = (int)round(meteonexa_radar4_p32_clamp($currentlyWet ? max(82, $eventProbability) : $eventProbability * .93));

    $start = meteonexa_radar4_time_distribution($startMean, $currentlyWet ? 3.5 : $baseSigma, $startProbability);
    $peakDistribution = meteonexa_radar4_time_distribution($peakMean, $baseSigma * 1.12, $peakProbability);
    $end = meteonexa_radar4_time_distribution($endMean, $baseSigma * 1.35, $endProbability);

    return [
        'available'=>!empty($start['available']) || !empty($peakDistribution['available']) || !empty($end['available']),
        'mode'=>'shadow',
        'authoritative'=>false,
        'authority'=>'radar3',
        'method'=>'radar4-probabilistic-multievidence-v1',
        'horizonMinutes'=>120,
        'stepMinutes'=>5,
        'rainStart'=>$start,
        'rainPeak'=>$peakDistribution,
        'rainEnd'=>$end,
        'growthDecay'=>[
            'predictedScore'=>(int)round($growth),
            'stage'=>$cell['stage'] ?? 'unknown',
            'verificationTargetMinutes'=>30,
        ],
        'dominantCell'=>[
            'id'=>$cell['id'] ?? null,
            'etaMinutes'=>$cell['etaMinutes'] ?? null,
            'trackConfidence'=>(int)($cell['trackConfidence'] ?? 0),
            'growthDecayScore'=>(int)round($growth),
            'stage'=>$cell['stage'] ?? null,
            'direction'=>$cell['direction'] ?? null,
        ],
        'fusion'=>$fusion,
        'confidenceCalibration'=>$calibration,
        'eventProbabilityPct'=>$eventProbability,
        'currentlyWet'=>$currentlyWet,
        'policy'=>[
            'shadowOnly'=>true,
            'authorityLockedToRadar3'=>true,
            'productionDecisionsUnaffected'=>true,
            'officialWarningsRemainSeparateAuthority'=>true,
            'promotionDisabledInP32'=>true,
            'requiresLiveMultiareaVerification'=>true,
        ],
        'generatedAt'=>gmdate('c'),
    ];
}

function meteonexa_radar4_event_prediction_queue(PDO $pdo, string $deviceId, string $locationKey, array $shadow): int {
    if (!meteonexa_db_table_exists($pdo, 'radar4_event_predictions') || empty($shadow['available'])) return 0;
    $now = time();
    $issued = gmdate('c', $now);
    $driver = meteonexa_pdo_driver($pdo);
    $verb = $driver === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
    $sql = $verb . " INTO radar4_event_predictions(device_id,location_key,prediction_key,event_kind,issued_at,p10_at,p50_at,p90_at,event_probability,confidence,predicted_value,status,verified_at,observed_at,observed_value,error_minutes,absolute_error_minutes,absolute_error_value,within_interval,ground_truth_source,ground_truth_quality,ground_truth_json,verification_method,created_at) VALUES(:d,:l,:k,:kind,:issued,:p10,:p50,:p90,:prob,:confidence,:pred,'pending','', '',NULL,NULL,NULL,NULL,NULL,'',0,'','',:created)";
    $st = $pdo->prepare($sql);
    $inserted = 0;
    foreach (['rain_start'=>'rainStart', 'rain_peak'=>'rainPeak', 'rain_end'=>'rainEnd'] as $kind=>$field) {
        $distribution = (array)($shadow[$field] ?? []);
        if (empty($distribution['available']) || !is_numeric($distribution['p50Minutes'] ?? null)) continue;
        $p10 = $now + (int)$distribution['p10Minutes'] * 60;
        $p50 = $now + (int)$distribution['p50Minutes'] * 60;
        $p90 = $now + (int)$distribution['p90Minutes'] * 60;
        $key = substr(hash('sha256', $deviceId . '|' . $locationKey . '|radar4-p32|' . $kind . '|' . gmdate('Y-m-d\TH:i', intdiv($now, 300) * 300)), 0, 48);
        $st->execute([
            ':d'=>$deviceId, ':l'=>$locationKey, ':k'=>$key, ':kind'=>$kind, ':issued'=>$issued,
            ':p10'=>gmdate('c', $p10), ':p50'=>gmdate('c', $p50), ':p90'=>gmdate('c', $p90),
            ':prob'=>(float)($distribution['eventProbabilityPct'] ?? 0), ':confidence'=>(int)($shadow['confidenceCalibration']['calibratedConfidence'] ?? 0), ':pred'=>null, ':created'=>$issued,
        ]);
        $inserted += $st->rowCount() > 0 ? 1 : 0;
    }
    $growth = (array)($shadow['growthDecay'] ?? []);
    if (is_numeric($growth['predictedScore'] ?? null)) {
        $target = $now + max(10, min(60, (int)($growth['verificationTargetMinutes'] ?? 30))) * 60;
        $key = substr(hash('sha256', $deviceId . '|' . $locationKey . '|radar4-p32|growth|' . gmdate('Y-m-d\TH:i', intdiv($now, 300) * 300)), 0, 48);
        $st->execute([
            ':d'=>$deviceId, ':l'=>$locationKey, ':k'=>$key, ':kind'=>'growth_decay', ':issued'=>$issued,
            ':p10'=>gmdate('c', $target - 600), ':p50'=>gmdate('c', $target), ':p90'=>gmdate('c', $target + 600),
            ':prob'=>100, ':confidence'=>(int)($shadow['confidenceCalibration']['calibratedConfidence'] ?? 0), ':pred'=>(float)$growth['predictedScore'], ':created'=>$issued,
        ]);
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

    // Establish the observed state at issue time without allowing an older wet
    // sample to verify a later, unrelated event. This matters when the ledger
    // window intentionally includes a short pre-issue lookback.
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

function meteonexa_radar4_event_skill_summary(PDO $pdo, string $deviceId, string $locationKey): array {
    if (!meteonexa_db_table_exists($pdo, 'radar4_event_predictions')) return ['available'=>false, 'samples'=>0, 'learning'=>true];
    $result = ['available'=>false, 'samples'=>0, 'learning'=>true, 'byEvent'=>[], 'promotionEligible'=>false, 'authorityLockedToRadar3'=>true];
    try {
        $st = $pdo->prepare("SELECT event_kind,COUNT(*) samples,AVG(absolute_error_minutes) mae_minutes,AVG(absolute_error_value) mae_value,AVG(CASE WHEN within_interval=1 THEN 1.0 WHEN within_interval=0 THEN 0.0 ELSE NULL END) interval_coverage FROM radar4_event_predictions WHERE device_id=:d AND location_key=:l AND status='verified' GROUP BY event_kind");
        $st->execute([':d'=>$deviceId, ':l'=>$locationKey]);
        $total = 0;
        foreach ($st->fetchAll() as $row) {
            $kind = (string)$row['event_kind'];
            $samples = (int)($row['samples'] ?? 0);
            $total += $samples;
            $result['byEvent'][$kind] = [
                'samples'=>$samples,
                'maeMinutes'=>is_numeric($row['mae_minutes'] ?? null) ? round((float)$row['mae_minutes'], 2) : null,
                'maeValue'=>is_numeric($row['mae_value'] ?? null) ? round((float)$row['mae_value'], 2) : null,
                'intervalCoveragePct'=>is_numeric($row['interval_coverage'] ?? null) ? round((float)$row['interval_coverage'] * 100, 1) : null,
                'learning'=>$samples < 30,
            ];
        }
        $result['samples'] = $total;
        $result['available'] = $total > 0;
        $eventKinds = ['rain_start', 'rain_peak', 'rain_end', 'growth_decay'];
        $mature = true;
        foreach ($eventKinds as $kind) if ((int)($result['byEvent'][$kind]['samples'] ?? 0) < 30) $mature = false;
        $result['learning'] = !$mature;
        $result['datasetMature'] = $mature;
        $result['minimumSamplesPerEvent'] = 30;
        $result['promotionEligible'] = false;
        $result['reason'] = $mature ? 'dataset_mature_promotion_still_disabled_in_p32' : 'collecting_live_multiarea_samples';
        return $result;
    } catch (Throwable $ignored) {
        $result['reason'] = 'skill_query_failed';
        return $result;
    }
}

function meteonexa_radar4_event_verification_update(PDO $pdo, string $deviceId, string $locationKey, array $shadow): array {
    $queued = meteonexa_radar4_event_prediction_queue($pdo, $deviceId, $locationKey, $shadow);
    if (!meteonexa_db_table_exists($pdo, 'radar4_event_predictions')) return ['available'=>false, 'queued'=>$queued, 'verified'=>0, 'skill'=>['available'=>false]];
    $now = time();
    $verified = 0;
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
            } else {
                $errMin = round(($observedTs - $p50Ts) / 60, 2);
                $absErrMin = abs($errMin);
                $within = $observedTs >= $p10Ts && $observedTs <= $p90Ts ? 1 : 0;
            }
            $up = $pdo->prepare("UPDATE radar4_event_predictions SET status='verified',verified_at=:v,observed_at=:o,observed_value=:ov,error_minutes=:em,absolute_error_minutes=:aem,absolute_error_value=:aev,within_interval=:wi,ground_truth_source=:gs,ground_truth_quality=:gq,ground_truth_json=:gj,verification_method='independent-observation-series' WHERE id=:id AND status='pending'");
            $up->execute([
                ':v'=>gmdate('c', $now), ':o'=>gmdate('c', $observedTs), ':ov'=>$truth['value'] ?? null,
                ':em'=>$errMin, ':aem'=>$absErrMin, ':aev'=>$absValueErr, ':wi'=>$within,
                ':gs'=>(string)($truth['source'] ?? 'observation'), ':gq'=>(int)($truth['quality'] ?? 0),
                ':gj'=>json_encode($truth, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':id'=>(int)$row['id'],
            ]);
            $verified += $up->rowCount() > 0 ? 1 : 0;
        }
    } catch (Throwable $ignored) {
    }
    return [
        'available'=>true,
        'queued'=>$queued,
        'verified'=>$verified,
        'skill'=>meteonexa_radar4_event_skill_summary($pdo, $deviceId, $locationKey),
        'policy'=>['promotionDisabledInP32'=>true, 'authorityLockedToRadar3'=>true],
    ];
}
