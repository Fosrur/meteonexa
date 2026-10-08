<?php
declare(strict_types=1);
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

function meteonexa_radar4_confidence_calibration(array $radar4, array $observations, array $satellite, ?float $elevationM = null, ?float $archiveDistanceKm = null, array $terrainProfile = []): array {
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
    $terrainProfileAvailable = !empty($terrainProfile['available']);
    if ($terrainProfileAvailable) {
        $relief = is_numeric($terrainProfile['reliefM'] ?? null) ? (float)$terrainProfile['reliefM'] : 0.0;
        $gradient = is_numeric($terrainProfile['maxGradientPct'] ?? null) ? (float)$terrainProfile['maxGradientPct'] : 0.0;
        
        
        
        $terrainPenalty = min(15.0, max(0.0, $relief - 120) / 120 + max(0.0, $gradient - 2.0) * .65);
    } elseif ($elevationM !== null) {
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
            'terrainReliefM'=>is_numeric($terrainProfile['reliefM'] ?? null) ? round((float)$terrainProfile['reliefM'], 1) : null,
            'terrainGradientPct'=>is_numeric($terrainProfile['maxGradientPct'] ?? null) ? round((float)$terrainProfile['maxGradientPct'], 2) : null,
            'terrainClass'=>$terrainProfile['terrainClass'] ?? 'unknown',
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
            'orographyUsesElevationProxyOnly'=>!$terrainProfileAvailable,
            'terrainProfileAvailable'=>$terrainProfileAvailable,
            'terrainProfileSource'=>$terrainProfile['source'] ?? ($elevationM !== null ? 'single-elevation-proxy' : 'unavailable'),
            'noTerrainSlopeInference'=>!$terrainProfileAvailable,
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

function meteonexa_radar4_probabilistic_shadow(array $radar4, array $nowcastV4, array $consensus, array $lightning, array $satellite, array $observations, array $official, ?float $elevationM = null, ?float $archiveDistanceKm = null, array $terrainProfile = []): array {
    $cell = meteonexa_radar4_dominant_cell($radar4);
    $fusion = meteonexa_radar4_evidence_fusion($radar4, $nowcastV4, $consensus, $lightning, $satellite, $observations, $official);
    $calibration = meteonexa_radar4_confidence_calibration($radar4, $observations, $satellite, $elevationM, $archiveDistanceKm, $terrainProfile);
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
