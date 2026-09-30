<?php
declare(strict_types=1);

/**
 * Radar4 P3.4 promotion-study helpers.
 *
 * This layer is deliberately evaluation-only. It may determine that a frozen
 * live dataset satisfies the promotion-study guardrails, but it never changes
 * runtime authority: Radar3 remains authoritative until a separate release
 * explicitly implements and validates a canary/rollback path.
 */

function meteonexa_radar4_p34_row_key(array $row, string $namespace): string {
    $parts = [
        $namespace,
        (string)($row['prediction_key'] ?? ''),
        (string)($row['event_kind'] ?? ''),
        (string)($row['algorithm'] ?? ''),
        (string)($row['location_key'] ?? ''),
        (string)($row['issued_at'] ?? ''),
    ];
    return implode('|', $parts);
}

function meteonexa_radar4_p34_holdout_bucket(array $row, string $namespace): int {
    $hex = substr(hash('sha256', meteonexa_radar4_p34_row_key($row, $namespace)), 0, 8);
    return (int)(hexdec($hex) % 100);
}

function meteonexa_radar4_p34_split_rows(array $rows, string $namespace, int $holdoutPct = 30): array {
    $holdoutPct = max(20, min(40, $holdoutPct));
    $train = []; $holdout = [];
    foreach ($rows as $row) {
        if (meteonexa_radar4_p34_holdout_bucket((array)$row, $namespace) < $holdoutPct) $holdout[] = $row;
        else $train[] = $row;
    }
    return ['train'=>$train, 'holdout'=>$holdout, 'holdoutPct'=>$holdoutPct];
}

function meteonexa_radar4_p34_dataset_fingerprint(array $eventRows, array $etaRows): string {
    $keys = [];
    foreach ($eventRows as $row) {
        $keys[] = 'event|' . meteonexa_radar4_p34_row_key((array)$row, 'event') . '|' . implode('|', [
            (string)($row['status'] ?? ''),
            (string)($row['event_observed'] ?? ''),
            (string)($row['observed_at'] ?? ''),
            (string)($row['absolute_error_minutes'] ?? ''),
            (string)($row['probability_brier'] ?? ''),
        ]);
    }
    foreach ($etaRows as $row) {
        $keys[] = 'eta|' . meteonexa_radar4_p34_row_key((array)$row, 'eta') . '|' . implode('|', [
            (string)($row['status'] ?? ''),
            (string)($row['observed_at'] ?? ''),
            (string)($row['absolute_error_minutes'] ?? ''),
            (string)($row['tolerance_minutes'] ?? ''),
        ]);
    }
    sort($keys, SORT_STRING);
    return hash('sha256', implode("\n", $keys));
}

function meteonexa_radar4_p34_binary_raw_metrics(array $rows): array {
    $raw = [];
    foreach ($rows as $row) {
        $copy = (array)$row;
        $copy['calibrated_probability'] = $copy['event_probability'] ?? null;
        $raw[] = $copy;
    }
    return meteonexa_radar4_p33_binary_metrics($raw);
}

function meteonexa_radar4_p34_weighted_reliability_gap(array $binary): ?float {
    $weighted = 0.0; $samples = 0;
    foreach ((array)($binary['bins'] ?? []) as $bin) {
        $n = (int)($bin['samples'] ?? 0);
        if ($n <= 0 || !is_numeric($bin['calibrationGapPct'] ?? null)) continue;
        $weighted += abs((float)$bin['calibrationGapPct']) * $n;
        $samples += $n;
    }
    return $samples > 0 ? round($weighted / $samples, 2) : null;
}

function meteonexa_radar4_p34_event_metrics(array $rows): array {
    $byKind = [];
    foreach ($rows as $row) $byKind[(string)($row['event_kind'] ?? 'unknown')][] = $row;
    $out = [];
    foreach ($byKind as $kind=>$group) {
        $calibrated = meteonexa_radar4_p33_binary_metrics($group);
        $raw = meteonexa_radar4_p34_binary_raw_metrics($group);
        $out[$kind] = [
            'binary'=>$calibrated,
            'rawBinary'=>$raw,
            'brierDeltaVsRaw'=>(is_numeric($calibrated['brierScore'] ?? null) && is_numeric($raw['brierScore'] ?? null))
                ? round((float)$calibrated['brierScore'] - (float)$raw['brierScore'], 4) : null,
            'weightedReliabilityGapPct'=>meteonexa_radar4_p34_weighted_reliability_gap($calibrated),
            'timing'=>$kind === 'growth_decay' ? null : meteonexa_radar4_p33_timing_metrics($group),
        ];
    }
    return $out;
}

function meteonexa_radar4_p34_eta_metrics_by_algorithm(array $rows): array {
    $byAlg = [];
    foreach ($rows as $row) $byAlg[(string)($row['algorithm'] ?? 'unknown')][] = $row;
    $out = [];
    foreach ($byAlg as $alg=>$group) $out[$alg] = meteonexa_radar4_p33_eta_metrics($group);
    return $out;
}

function meteonexa_radar4_p34_eta_comparison(array $metrics): array {
    $v3 = (array)($metrics['radar-v3'] ?? []);
    $v4 = (array)($metrics['radar-v4'] ?? []);
    $mae3 = is_numeric($v3['maeMinutes'] ?? null) ? (float)$v3['maeMinutes'] : null;
    $mae4 = is_numeric($v4['maeMinutes'] ?? null) ? (float)$v4['maeMinutes'] : null;
    $within3 = is_numeric($v3['withinTolerancePct'] ?? null) ? (float)$v3['withinTolerancePct'] : null;
    $within4 = is_numeric($v4['withinTolerancePct'] ?? null) ? (float)$v4['withinTolerancePct'] : null;
    $absolute = ($mae3 !== null && $mae4 !== null) ? $mae3 - $mae4 : null;
    $relative = ($absolute !== null && $mae3 > 0) ? 100 * $absolute / $mae3 : null;
    return [
        'radar3'=>$v3,
        'radar4'=>$v4,
        'maeImprovementMinutes'=>$absolute === null ? null : round($absolute, 2),
        'maeImprovementPct'=>$relative === null ? null : round($relative, 1),
        'withinToleranceDeltaPct'=>($within3 === null || $within4 === null) ? null : round($within4 - $within3, 1),
    ];
}

function meteonexa_radar4_p34_derive_thresholds(array $trainEvents, array $trainEta): array {
    $etaCmp = meteonexa_radar4_p34_eta_comparison($trainEta);
    $trainEtaMinutes = max(0.0, (float)($etaCmp['maeImprovementMinutes'] ?? 0));
    $trainEtaPct = max(0.0, (float)($etaCmp['maeImprovementPct'] ?? 0));
    $eventThresholds = [];
    foreach (['rain_start','rain_peak','rain_end'] as $kind) {
        $timing = (array)($trainEvents[$kind]['timing'] ?? []);
        $bias = abs((float)($timing['biasP50Minutes'] ?? 0));
        $coverage = is_numeric($timing['intervalCoveragePct'] ?? null) ? (float)$timing['intervalCoveragePct'] : 80.0;
        $binary = (array)($trainEvents[$kind]['binary'] ?? []);
        $gap = meteonexa_radar4_p34_weighted_reliability_gap($binary);
        $eventThresholds[$kind] = [
            'maxAbsP50BiasMinutes'=>round(max(6.0, min(15.0, $bias * 1.35 + 3.0)), 1),
            'minP10P90CoveragePct'=>round(max(68.0, min(78.0, $coverage - 5.0)), 1),
            'maxP10P90CoveragePct'=>95.0,
            'maxBrierRegressionVsRaw'=>0.01,
            'maxWeightedReliabilityGapPct'=>round(max(10.0, min(20.0, ($gap ?? 12.0) + 3.0)), 1),
        ];
    }
    return [
        'derivation'=>'train-only-bounded-guardrails-v1',
        'eta'=>[
            'minMaeImprovementMinutes'=>round(max(0.5, min(2.0, $trainEtaMinutes * .5)), 2),
            'minMaeImprovementPct'=>round(max(5.0, min(15.0, $trainEtaPct * .5)), 1),
            'minWithinToleranceDeltaPct'=>-2.0,
        ],
        'events'=>$eventThresholds,
        'minimumHoldout'=>[
            'binarySamplesPerRainEvent'=>12,
            'positiveTimingSamplesPerRainEvent'=>8,
            'etaSamplesPerAlgorithm'=>15,
        ],
    ];
}

function meteonexa_radar4_p34_event_holdout_checks(array $metrics, array $thresholds): array {
    $checks = [];
    foreach (['rain_start','rain_peak','rain_end'] as $kind) {
        $m = (array)($metrics[$kind] ?? []);
        $binary = (array)($m['binary'] ?? []);
        $timing = (array)($m['timing'] ?? []);
        $t = (array)($thresholds['events'][$kind] ?? []);
        $checks[$kind] = [
            'binarySamples'=>(int)($binary['samples'] ?? 0),
            'timingSamples'=>(int)($timing['samples'] ?? 0),
            'brierDeltaVsRaw'=>$m['brierDeltaVsRaw'] ?? null,
            'weightedReliabilityGapPct'=>$m['weightedReliabilityGapPct'] ?? null,
            'absP50BiasMinutes'=>is_numeric($timing['biasP50Minutes'] ?? null) ? round(abs((float)$timing['biasP50Minutes']), 2) : null,
            'intervalCoveragePct'=>$timing['intervalCoveragePct'] ?? null,
        ];
        $checks[$kind]['pass'] =
            $checks[$kind]['binarySamples'] >= (int)($thresholds['minimumHoldout']['binarySamplesPerRainEvent'] ?? 12)
            && $checks[$kind]['timingSamples'] >= (int)($thresholds['minimumHoldout']['positiveTimingSamplesPerRainEvent'] ?? 8)
            && is_numeric($checks[$kind]['brierDeltaVsRaw']) && (float)$checks[$kind]['brierDeltaVsRaw'] <= (float)($t['maxBrierRegressionVsRaw'] ?? .01)
            && is_numeric($checks[$kind]['weightedReliabilityGapPct']) && (float)$checks[$kind]['weightedReliabilityGapPct'] <= (float)($t['maxWeightedReliabilityGapPct'] ?? 20)
            && is_numeric($checks[$kind]['absP50BiasMinutes']) && (float)$checks[$kind]['absP50BiasMinutes'] <= (float)($t['maxAbsP50BiasMinutes'] ?? 15)
            && is_numeric($checks[$kind]['intervalCoveragePct'])
            && (float)$checks[$kind]['intervalCoveragePct'] >= (float)($t['minP10P90CoveragePct'] ?? 68)
            && (float)$checks[$kind]['intervalCoveragePct'] <= (float)($t['maxP10P90CoveragePct'] ?? 95);
    }
    return $checks;
}

function meteonexa_radar4_p34_segment_stability(array $eventRows, array $etaRows): array {
    $dimensions = ['area_key'=>'area','season'=>'season','weather_regime'=>'weatherRegime'];
    $result = ['pass'=>true, 'dimensions'=>[]];
    foreach ($dimensions as $field=>$name) {
        $eventGroups = []; $etaGroups = [];
        foreach ($eventRows as $row) $eventGroups[(string)($row[$field] ?? 'unknown')][] = $row;
        foreach ($etaRows as $row) $etaGroups[(string)($row[$field] ?? 'unknown')][] = $row;
        $keys = array_values(array_unique(array_merge(array_keys($eventGroups), array_keys($etaGroups))));
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            if ($key === '' || $key === 'unknown') continue;
            $ev = (array)($eventGroups[$key] ?? []);
            $et = (array)($etaGroups[$key] ?? []);
            $binary = meteonexa_radar4_p33_binary_metrics($ev);
            $eta = meteonexa_radar4_p34_eta_comparison(meteonexa_radar4_p34_eta_metrics_by_algorithm($et));
            $eventEnough = (int)($binary['samples'] ?? 0) >= 12;
            $eta3n = (int)($eta['radar3']['samples'] ?? 0);
            $eta4n = (int)($eta['radar4']['samples'] ?? 0);
            $etaEnough = $eta3n >= 5 && $eta4n >= 5;
            $brierOk = !$eventEnough || ((float)($binary['brierScore'] ?? 1) <= .35 && (float)(meteonexa_radar4_p34_weighted_reliability_gap($binary) ?? 100) <= 22.0);
            $etaOk = !$etaEnough || ((float)($eta['maeImprovementMinutes'] ?? -99) >= -1.0 && (float)($eta['withinToleranceDeltaPct'] ?? -99) >= -5.0);
            $pass = $brierOk && $etaOk;
            if (!$pass) $result['pass'] = false;
            $result['dimensions'][$name][$key] = [
                'eventSamples'=>(int)($binary['samples'] ?? 0),
                'eventBrier'=>$binary['brierScore'] ?? null,
                'eventReliabilityGapPct'=>meteonexa_radar4_p34_weighted_reliability_gap($binary),
                'etaRadar3Samples'=>$eta3n,
                'etaRadar4Samples'=>$eta4n,
                'etaMaeImprovementMinutes'=>$eta['maeImprovementMinutes'] ?? null,
                'etaWithinToleranceDeltaPct'=>$eta['withinToleranceDeltaPct'] ?? null,
                'evaluated'=>$eventEnough || $etaEnough,
                'pass'=>$pass,
            ];
        }
    }
    return $result;
}

function meteonexa_radar4_p34_promotion_study(PDO $pdo, string $deviceId, ?array $p33Report = null): array {
    $p33Report ??= meteonexa_radar4_p33_calibration_report($pdo, $deviceId);
    $base = [
        'available'=>false,
        'phase'=>'P3.4',
        'studyMode'=>'shadow-evaluation-only',
        'datasetMature'=>!empty($p33Report['datasetMature']),
        'datasetFingerprint'=>null,
        'holdoutPct'=>30,
        'numericThresholdsFinalized'=>false,
        'holdoutValidated'=>false,
        'stabilityValidated'=>false,
        'manualCanaryReviewEligible'=>false,
        'activationAllowed'=>false,
        'authorityLockedToRadar3'=>true,
        'productionDecisionsUnaffected'=>true,
        'reason'=>'p33_live_dataset_not_mature',
    ];
    if (empty($p33Report['datasetMature'])) return $base;
    if (!meteonexa_db_table_exists($pdo, 'radar4_event_predictions') || !meteonexa_db_table_exists($pdo, 'radar_eta_predictions')) {
        $base['reason'] = 'verification-ledger-unavailable';
        return $base;
    }
    try {
        $events = $pdo->prepare("SELECT prediction_key,location_key,event_kind,issued_at,event_probability,calibrated_probability,event_observed,probability_brier,error_minutes,absolute_error_minutes,within_interval,status,observed_at,area_key,distance_band,coverage_band,season,weather_regime,terrain_class FROM radar4_event_predictions WHERE device_id=:d AND status='verified' ORDER BY id ASC LIMIT 20000");
        $events->execute([':d'=>$deviceId]);
        $eventRows = $events->fetchAll();
        $eta = $pdo->prepare("SELECT prediction_key,location_key,issued_at,observed_at,algorithm,absolute_error_minutes,tolerance_minutes,status,area_key,distance_band,coverage_band,season,weather_regime,terrain_class FROM radar_eta_predictions WHERE device_id=:d AND status='verified' AND algorithm IN ('radar-v3','radar-v4') AND absolute_error_minutes IS NOT NULL ORDER BY id ASC LIMIT 20000");
        $eta->execute([':d'=>$deviceId]);
        $etaRows = $eta->fetchAll();

        $eventSplit = meteonexa_radar4_p34_split_rows($eventRows, 'event', 30);
        $etaSplit = meteonexa_radar4_p34_split_rows($etaRows, 'eta', 30);
        $trainEvents = meteonexa_radar4_p34_event_metrics($eventSplit['train']);
        $holdoutEvents = meteonexa_radar4_p34_event_metrics($eventSplit['holdout']);
        $trainEtaMetrics = meteonexa_radar4_p34_eta_metrics_by_algorithm($etaSplit['train']);
        $holdoutEtaMetrics = meteonexa_radar4_p34_eta_metrics_by_algorithm($etaSplit['holdout']);
        $thresholds = meteonexa_radar4_p34_derive_thresholds($trainEvents, $trainEtaMetrics);
        $eventChecks = meteonexa_radar4_p34_event_holdout_checks($holdoutEvents, $thresholds);
        $etaCmp = meteonexa_radar4_p34_eta_comparison($holdoutEtaMetrics);
        $etaThreshold = (array)$thresholds['eta'];
        $etaPass =
            (int)($etaCmp['radar3']['samples'] ?? 0) >= (int)$thresholds['minimumHoldout']['etaSamplesPerAlgorithm']
            && (int)($etaCmp['radar4']['samples'] ?? 0) >= (int)$thresholds['minimumHoldout']['etaSamplesPerAlgorithm']
            && is_numeric($etaCmp['maeImprovementMinutes'] ?? null) && (float)$etaCmp['maeImprovementMinutes'] >= (float)$etaThreshold['minMaeImprovementMinutes']
            && is_numeric($etaCmp['maeImprovementPct'] ?? null) && (float)$etaCmp['maeImprovementPct'] >= (float)$etaThreshold['minMaeImprovementPct']
            && is_numeric($etaCmp['withinToleranceDeltaPct'] ?? null) && (float)$etaCmp['withinToleranceDeltaPct'] >= (float)$etaThreshold['minWithinToleranceDeltaPct'];
        $eventsPass = !empty($eventChecks) && count(array_filter($eventChecks, static fn($x)=>!empty($x['pass']))) === count($eventChecks);
        $stability = meteonexa_radar4_p34_segment_stability($eventSplit['holdout'], $etaSplit['holdout']);
        $holdoutPass = $eventsPass && $etaPass;
        $studyPass = $holdoutPass && !empty($stability['pass']);

        return array_replace($base, [
            'available'=>true,
            'datasetFingerprint'=>meteonexa_radar4_p34_dataset_fingerprint($eventRows, $etaRows),
            'datasetFreeze'=>[
                'logicalFreeze'=>true,
                'eventRows'=>count($eventRows),
                'etaRows'=>count($etaRows),
                'fingerprintAlgorithm'=>'sha256-canonical-verified-ledger-v1',
                'note'=>'Fingerprint identifies the exact verified ledger snapshot used by this study.',
            ],
            'split'=>[
                'method'=>'deterministic-sha256-70-30-v1',
                'trainEventRows'=>count($eventSplit['train']), 'holdoutEventRows'=>count($eventSplit['holdout']),
                'trainEtaRows'=>count($etaSplit['train']), 'holdoutEtaRows'=>count($etaSplit['holdout']),
            ],
            'thresholds'=>$thresholds,
            'numericThresholdsFinalized'=>true,
            'train'=>['events'=>$trainEvents, 'eta'=>meteonexa_radar4_p34_eta_comparison($trainEtaMetrics)],
            'holdout'=>['events'=>$holdoutEvents, 'eventChecks'=>$eventChecks, 'eta'=>$etaCmp, 'etaPass'=>$etaPass],
            'holdoutValidated'=>$holdoutPass,
            'stability'=>$stability,
            'stabilityValidated'=>!empty($stability['pass']),
            'manualCanaryReviewEligible'=>$studyPass,
            'activationAllowed'=>false,
            'authorityLockedToRadar3'=>true,
            'productionDecisionsUnaffected'=>true,
            'reason'=>$studyPass ? 'promotion-study-passed-manual-canary-review-only' : 'promotion-study-guardrails-not-met',
            'releaseReview'=>[
                'decision'=>$studyPass ? 'eligible-for-separate-manual-canary-review' : 'keep-radar4-shadow',
                'automaticPromotion'=>false,
                'requiresSeparateRelease'=>true,
                'requiresCanary'=>true,
                'requiresRollbackPlan'=>true,
                'requiresSameDatasetFingerprint'=>true,
            ],
        ]);
    } catch (Throwable $ignored) {
        $base['reason'] = 'promotion-study-query-failed';
        return $base;
    }
}
