<?php
declare(strict_types=1);

/** Radar4 P3.3 empirical calibration / multi-area verification helpers. */
function meteonexa_radar4_p33_location_coordinates(string $locationKey): ?array {
    $parts = explode(':', $locationKey, 2);
    if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) return null;
    $lat = (float)$parts[0];
    $lon = (float)$parts[1];
    if (abs($lat) > 90 || abs($lon) > 180) return null;
    return ['lat'=>$lat, 'lon'=>$lon];
}

function meteonexa_radar4_p33_area_key(string $locationKey): string {
    $coords = meteonexa_radar4_p33_location_coordinates($locationKey);
    if (!$coords) return 'unknown';
    // Coarse ~25 km cell: enough for multi-area calibration without duplicating
    // the exact location key into the report dimension.
    $lat = round($coords['lat'] * 4) / 4;
    $lon = round($coords['lon'] * 4) / 4;
    return number_format($lat, 2, '.', '') . ':' . number_format($lon, 2, '.', '');
}

function meteonexa_radar4_p33_season(int $ts, ?float $lat = null): string {
    $month = (int)gmdate('n', $ts);
    $north = $lat === null || $lat >= 0;
    $northSeason = match (true) {
        in_array($month, [12,1,2], true) => 'winter',
        in_array($month, [3,4,5], true) => 'spring',
        in_array($month, [6,7,8], true) => 'summer',
        default => 'autumn',
    };
    if ($north) return $northSeason;
    return ['winter'=>'summer','spring'=>'autumn','summer'=>'winter','autumn'=>'spring'][$northSeason];
}

function meteonexa_radar4_p33_distance_band(?float $km): string {
    if ($km === null || !is_finite($km)) return 'unknown';
    if ($km <= 15) return 'near';
    if ($km <= 40) return 'mid';
    if ($km <= 80) return 'far';
    return 'very-far';
}

function meteonexa_radar4_p33_coverage_band(int $count): string {
    if ($count >= 4) return 'full';
    if ($count === 3) return 'good';
    if ($count === 2) return 'partial';
    if ($count === 1) return 'sparse';
    return 'unknown';
}

function meteonexa_radar4_p33_weather_regime(array $shadow, array $lightning, array $satellite, array $observations): string {
    $lightCount = (int)($lightning['recent30m'] ?? 0);
    $storm = max((float)($observations['storm'] ?? 0), (float)($observations['rain'] ?? 0));
    $growth = (float)($shadow['growthDecay']['predictedScore'] ?? 0);
    $prob = (float)($shadow['eventProbabilityPct'] ?? 0);
    $cloud = (float)($satellite['cloudAttenuationPct'] ?? 0);
    if ($lightCount >= 3 || $storm >= .65 || ($growth >= 35 && $prob >= 60)) return 'convective';
    if ($prob >= 55 && $growth > -25 && $lightCount < 3) return 'stratiform';
    if ($growth <= -35) return 'decaying';
    if ($prob < 30 && $cloud < 45) return 'dry';
    return 'mixed';
}

function meteonexa_radar4_p33_terrain_profile_from_elevations(array $elevations, float $sampleRadiusKm = 5.0): array {
    $values = array_values(array_filter(array_map(static fn($v)=>is_numeric($v) ? (float)$v : null, $elevations), static fn($v)=>$v !== null));
    if (count($values) < 3) return ['available'=>false, 'source'=>'insufficient-dem-samples', 'sampleCount'=>count($values)];
    $center = $values[0];
    $min = min($values);
    $max = max($values);
    $relief = max(0.0, $max - $min);
    $radiusM = max(500.0, $sampleRadiusKm * 1000);
    $maxGradient = 0.0;
    foreach (array_slice($values, 1) as $value) $maxGradient = max($maxGradient, abs($value - $center) / $radiusM * 100);
    $terrainClass = match (true) {
        $relief >= 900 || $maxGradient >= 12 => 'mountainous',
        $relief >= 350 || $maxGradient >= 6 => 'complex',
        $relief >= 120 || $maxGradient >= 2.5 => 'rolling',
        default => 'flat',
    };
    return [
        'available'=>true,
        'source'=>'copernicus-dem-glo90-via-open-meteo',
        'sampleCount'=>count($values),
        'sampleRadiusKm'=>round($sampleRadiusKm, 1),
        'centerElevationM'=>round($center, 1),
        'minElevationM'=>round($min, 1),
        'maxElevationM'=>round($max, 1),
        'reliefM'=>round($relief, 1),
        'maxGradientPct'=>round($maxGradient, 2),
        'terrainClass'=>$terrainClass,
        'verifiable'=>true,
    ];
}

function meteonexa_radar4_p33_terrain_profile(float $lat, float $lon, ?float $fallbackElevationM = null): array {
    if (!function_exists('meteonexa_intel_provider_cache') || !function_exists('meteonexa_http_json')) {
        return $fallbackElevationM === null ? ['available'=>false, 'source'=>'provider-unavailable'] : [
            'available'=>false, 'source'=>'single-elevation-fallback', 'centerElevationM'=>$fallbackElevationM,
        ];
    }
    try {
        return meteonexa_intel_provider_cache('radar4-terrain-v1', $lat, $lon, 21600, static function() use ($lat, $lon): array {
            $latDelta = 5.0 / 111.32;
            $cos = max(.2, cos(deg2rad($lat)));
            $lonDelta = 5.0 / (111.32 * $cos);
            $points = [
                [$lat,$lon], [$lat+$latDelta,$lon], [$lat-$latDelta,$lon], [$lat,$lon+$lonDelta], [$lat,$lon-$lonDelta],
                [$lat+$latDelta,$lon+$lonDelta], [$lat+$latDelta,$lon-$lonDelta], [$lat-$latDelta,$lon+$lonDelta], [$lat-$latDelta,$lon-$lonDelta],
            ];
            $lats = implode(',', array_map(static fn($p)=>number_format($p[0], 5, '.', ''), $points));
            $lons = implode(',', array_map(static fn($p)=>number_format($p[1], 5, '.', ''), $points));
            $url = 'https://api.open-meteo.com/v1/elevation?' . http_build_query(['latitude'=>$lats, 'longitude'=>$lons], '', '&', PHP_QUERY_RFC3986);
            // http_build_query escapes commas; Open-Meteo accepts arrays as comma-separated values after URL decoding.
            $raw = meteonexa_http_json($url, ['timeout'=>10, 'max_bytes'=>120000]);
            return meteonexa_radar4_p33_terrain_profile_from_elevations((array)($raw['elevation'] ?? []), 5.0);
        }, 604800);
    } catch (Throwable $ignored) {
        return $fallbackElevationM === null ? ['available'=>false, 'source'=>'terrain-provider-failed'] : [
            'available'=>false, 'source'=>'single-elevation-fallback', 'centerElevationM'=>$fallbackElevationM,
        ];
    }
}

function meteonexa_radar4_p33_context(string $locationKey, array $shadow, array $lightning, array $satellite, array $observations, array $terrainProfile, ?float $archiveDistanceKm, ?int $issuedTs = null): array {
    $coords = meteonexa_radar4_p33_location_coordinates($locationKey);
    $issuedTs ??= time();
    $coverage = (int)($shadow['confidenceCalibration']['inputs']['evidenceCoverageCount'] ?? 0);
    return [
        'areaKey'=>meteonexa_radar4_p33_area_key($locationKey),
        'distanceBand'=>meteonexa_radar4_p33_distance_band($archiveDistanceKm),
        'coverageBand'=>meteonexa_radar4_p33_coverage_band($coverage),
        'season'=>meteonexa_radar4_p33_season($issuedTs, $coords['lat'] ?? null),
        'weatherRegime'=>meteonexa_radar4_p33_weather_regime($shadow, $lightning, $satellite, $observations),
        'terrainClass'=>(string)($terrainProfile['terrainClass'] ?? 'unknown'),
        'terrainReliefM'=>is_numeric($terrainProfile['reliefM'] ?? null) ? (float)$terrainProfile['reliefM'] : null,
        'terrainGradientPct'=>is_numeric($terrainProfile['maxGradientPct'] ?? null) ? (float)$terrainProfile['maxGradientPct'] : null,
        'terrainSource'=>(string)($terrainProfile['source'] ?? 'unknown'),
    ];
}

function meteonexa_radar4_p33_probability_bin(float $probabilityPct): string {
    $p = max(0.0, min(100.0, $probabilityPct));
    if ($p >= 80) return '80-100';
    if ($p >= 60) return '60-80';
    if ($p >= 40) return '40-60';
    if ($p >= 20) return '20-40';
    return '0-20';
}

function meteonexa_radar4_p33_binary_metrics(array $rows): array {
    $n = 0; $positives = 0; $brier = 0.0; $probSum = 0.0;
    $bins = [];
    foreach ($rows as $row) {
        if (!is_numeric($row['event_observed'] ?? null)) continue;
        $obs = (int)$row['event_observed'] > 0 ? 1 : 0;
        $pPct = is_numeric($row['calibrated_probability'] ?? null) ? (float)$row['calibrated_probability'] : (float)($row['event_probability'] ?? 0);
        $pPct = max(0.0, min(100.0, $pPct));
        $p = $pPct / 100;
        $n++; $positives += $obs; $probSum += $pPct; $brier += ($p - $obs) ** 2;
        $bin = meteonexa_radar4_p33_probability_bin($pPct);
        $bins[$bin]['samples'] = (int)($bins[$bin]['samples'] ?? 0) + 1;
        $bins[$bin]['observed'] = (int)($bins[$bin]['observed'] ?? 0) + $obs;
        $bins[$bin]['probabilitySum'] = (float)($bins[$bin]['probabilitySum'] ?? 0) + $pPct;
    }
    $ordered = [];
    foreach (['0-20','20-40','40-60','60-80','80-100'] as $bin) {
        $d = $bins[$bin] ?? ['samples'=>0,'observed'=>0,'probabilitySum'=>0.0];
        $samples = (int)$d['samples'];
        $observedPct = $samples ? 100 * (int)$d['observed'] / $samples : null;
        $meanProbability = $samples ? (float)$d['probabilitySum'] / $samples : null;
        $ordered[$bin] = [
            'samples'=>$samples,
            'meanProbabilityPct'=>$meanProbability === null ? null : round($meanProbability, 1),
            'observedFrequencyPct'=>$observedPct === null ? null : round($observedPct, 1),
            'calibrationGapPct'=>($observedPct === null || $meanProbability === null) ? null : round($observedPct - $meanProbability, 1),
            'learning'=>$samples < 20,
        ];
    }
    return [
        'samples'=>$n,
        'positiveSamples'=>$positives,
        'negativeSamples'=>$n - $positives,
        'observedFrequencyPct'=>$n ? round(100 * $positives / $n, 1) : null,
        'meanProbabilityPct'=>$n ? round($probSum / $n, 1) : null,
        'brierScore'=>$n ? round($brier / $n, 4) : null,
        'bins'=>$ordered,
        'learning'=>$n < 50,
    ];
}

function meteonexa_radar4_p33_timing_metrics(array $rows): array {
    $errors = []; $signed = []; $interval = [];
    foreach ($rows as $row) {
        if ((int)($row['event_observed'] ?? 1) !== 1 || !is_numeric($row['absolute_error_minutes'] ?? null)) continue;
        $errors[] = (float)$row['absolute_error_minutes'];
        if (is_numeric($row['error_minutes'] ?? null)) $signed[] = (float)$row['error_minutes'];
        if (is_numeric($row['within_interval'] ?? null)) $interval[] = (int)$row['within_interval'] ? 1 : 0;
    }
    sort($errors);
    $n = count($errors);
    return [
        'samples'=>$n,
        'maeMinutes'=>$n ? round(array_sum($errors) / $n, 2) : null,
        'biasP50Minutes'=>$signed ? round(array_sum($signed) / count($signed), 2) : null,
        'p90AbsoluteErrorMinutes'=>$n ? round($errors[(int)floor(($n - 1) * .9)], 2) : null,
        'intervalCoveragePct'=>$interval ? round(100 * array_sum($interval) / count($interval), 1) : null,
        'targetIntervalCoveragePct'=>80,
        'learning'=>$n < 30,
    ];
}

function meteonexa_radar4_p33_eta_metrics(array $rows): array {
    $errors = []; $within = [];
    foreach ($rows as $row) {
        if (!is_numeric($row['absolute_error_minutes'] ?? null)) continue;
        $errors[] = (float)$row['absolute_error_minutes'];
        $tol = is_numeric($row['tolerance_minutes'] ?? null) ? (float)$row['tolerance_minutes'] : 10.0;
        $within[] = end($errors) <= $tol ? 1 : 0;
    }
    sort($errors);
    $n = count($errors);
    return [
        'samples'=>$n,
        'maeMinutes'=>$n ? round(array_sum($errors) / $n, 2) : null,
        'p90Minutes'=>$n ? round($errors[(int)floor(($n - 1) * .9)], 2) : null,
        'withinTolerancePct'=>$n ? round(100 * array_sum($within) / $n, 1) : null,
        'learning'=>$n < 60,
    ];
}

function meteonexa_radar4_p33_segment_rows(array $rows, string $field, callable $metric): array {
    $groups = [];
    foreach ($rows as $row) {
        $key = trim((string)($row[$field] ?? '')) ?: 'unknown';
        $groups[$key][] = $row;
    }
    ksort($groups, SORT_STRING);
    $out = [];
    foreach ($groups as $key=>$group) $out[$key] = $metric($group);
    return $out;
}

function meteonexa_radar4_p33_calibration_report(PDO $pdo, string $deviceId): array {
    $base = [
        'available'=>false, 'phase'=>'P3.3', 'shadowOnly'=>true, 'authorityLockedToRadar3'=>true,
        'datasetMature'=>false, 'areas'=>0, 'seasons'=>0, 'weatherRegimes'=>0, 'growthSamples'=>0,
        'events'=>[], 'eta'=>[], 'segments'=>[],
        'futurePromotionStudy'=>['eligible'=>false,'numericThresholdsFinalized'=>false,'activationAllowed'=>false],
    ];
    if (!meteonexa_db_table_exists($pdo, 'radar4_event_predictions')) return $base;
    try {
        $eventSql = "SELECT location_key,event_kind,event_probability,calibrated_probability,event_observed,probability_brier,error_minutes,absolute_error_minutes,absolute_error_value,within_interval,area_key,distance_band,coverage_band,season,weather_regime,terrain_class FROM radar4_event_predictions WHERE device_id=:d AND status='verified' ORDER BY id DESC LIMIT 12000";
        $st = $pdo->prepare($eventSql);
        $st->execute([':d'=>$deviceId]);
        $eventRows = $st->fetchAll();
        $areas = []; $seasons = []; $regimes = [];
        foreach ($eventRows as $row) {
            if (($row['area_key'] ?? '') !== '') $areas[(string)$row['area_key']] = true;
            if (($row['season'] ?? '') !== '' && ($row['season'] ?? '') !== 'unknown') $seasons[(string)$row['season']] = true;
            if (($row['weather_regime'] ?? '') !== '' && ($row['weather_regime'] ?? '') !== 'unknown') $regimes[(string)$row['weather_regime']] = true;
        }
        $byEvent = [];
        foreach ($eventRows as $row) $byEvent[(string)($row['event_kind'] ?? 'unknown')][] = $row;
        foreach ($byEvent as $kind=>$rows) {
            $base['events'][$kind] = [
                'binary'=>meteonexa_radar4_p33_binary_metrics($rows),
                'timing'=>$kind === 'growth_decay' ? null : meteonexa_radar4_p33_timing_metrics($rows),
                'growthMae'=> $kind === 'growth_decay' ? (function(array $r): ?float {
                    $values = array_values(array_filter(array_map(static fn($x)=>is_numeric($x['absolute_error_value'] ?? null) ? (float)$x['absolute_error_value'] : null, $r), static fn($x)=>$x !== null));
                    return $values ? round(array_sum($values) / count($values), 2) : null;
                })($rows) : null,
            ];
        }
        $base['segments'] = [
            'distanceBand'=>meteonexa_radar4_p33_segment_rows($eventRows, 'distance_band', 'meteonexa_radar4_p33_binary_metrics'),
            'coverageBand'=>meteonexa_radar4_p33_segment_rows($eventRows, 'coverage_band', 'meteonexa_radar4_p33_binary_metrics'),
            'season'=>meteonexa_radar4_p33_segment_rows($eventRows, 'season', 'meteonexa_radar4_p33_binary_metrics'),
            'weatherRegime'=>meteonexa_radar4_p33_segment_rows($eventRows, 'weather_regime', 'meteonexa_radar4_p33_binary_metrics'),
            'terrainClass'=>meteonexa_radar4_p33_segment_rows($eventRows, 'terrain_class', 'meteonexa_radar4_p33_binary_metrics'),
        ];

        $etaRows = [];
        if (meteonexa_db_table_exists($pdo, 'radar_eta_predictions') && meteonexa_db_column_exists($pdo, 'radar_eta_predictions', 'area_key')) {
            $eta = $pdo->prepare("SELECT algorithm,absolute_error_minutes,tolerance_minutes,area_key,distance_band,coverage_band,season,weather_regime,terrain_class FROM radar_eta_predictions WHERE device_id=:d AND status='verified' AND algorithm IN ('radar-v3','radar-v4') AND absolute_error_minutes IS NOT NULL ORDER BY id DESC LIMIT 12000");
            $eta->execute([':d'=>$deviceId]);
            $etaRows = $eta->fetchAll();
            $byAlg = [];
            foreach ($etaRows as $row) $byAlg[(string)$row['algorithm']][] = $row;
            foreach ($byAlg as $alg=>$rows) $base['eta'][$alg] = meteonexa_radar4_p33_eta_metrics($rows);
            $base['etaSegments'] = [
                'area'=>meteonexa_radar4_p33_segment_rows($etaRows, 'area_key', 'meteonexa_radar4_p33_eta_metrics'),
                'weatherRegime'=>meteonexa_radar4_p33_segment_rows($etaRows, 'weather_regime', 'meteonexa_radar4_p33_eta_metrics'),
            ];
        }

        $base['areas'] = count($areas);
        $base['seasons'] = count($seasons);
        $base['weatherRegimes'] = count($regimes);
        $requiredKinds = ['rain_start','rain_peak','rain_end'];
        $enoughEvents = true;
        foreach ($requiredKinds as $kind) {
            if ((int)($base['events'][$kind]['binary']['samples'] ?? 0) < 50 || (int)($base['events'][$kind]['timing']['samples'] ?? 0) < 30) $enoughEvents = false;
        }
        $base['growthSamples'] = count($byEvent['growth_decay'] ?? []);
        $enoughGrowth = $base['growthSamples'] >= 30;
        $eta3 = (int)($base['eta']['radar-v3']['samples'] ?? 0);
        $eta4 = (int)($base['eta']['radar-v4']['samples'] ?? 0);
        $datasetMature = $base['areas'] >= 3 && $base['seasons'] >= 2 && $base['weatherRegimes'] >= 3 && $enoughEvents && $enoughGrowth && $eta3 >= 60 && $eta4 >= 60;
        $base['available'] = !empty($eventRows) || !empty($etaRows);
        $base['datasetMature'] = $datasetMature;
        $base['maturityRequirements'] = [
            'minimumAreas'=>3, 'minimumSeasons'=>2, 'minimumWeatherRegimes'=>3,
            'minimumBinarySamplesPerRainEvent'=>50, 'minimumPositiveTimingSamplesPerRainEvent'=>30,
            'minimumGrowthSamples'=>30, 'minimumEtaSamplesPerAlgorithm'=>60,
        ];
        $base['futurePromotionStudy'] = [
            'eligible'=>$datasetMature,
            'numericThresholdsFinalized'=>false,
            'activationAllowed'=>false,
            'authorityLockedToRadar3'=>true,
            'reason'=>$datasetMature ? 'dataset_mature_ready_for_separate_promotion-study' : 'live_multiarea_dataset_not_mature',
            'metricsRequired'=>['radar4-vs-radar3-eta-mae','eta-within-tolerance','p50-bias','p10-p90-coverage','probability-brier','probability-reliability-by-segment'],
        ];
        return $base;
    } catch (Throwable $ignored) {
        $base['reason'] = 'calibration-report-query-failed';
        return $base;
    }
}

function meteonexa_radar4_p33_empirical_probability(array $report, string $eventKind, float $rawProbabilityPct): array {
    $raw = max(0.0, min(100.0, $rawProbabilityPct));
    $bin = meteonexa_radar4_p33_probability_bin($raw);
    $metrics = (array)($report['events'][$eventKind]['binary']['bins'][$bin] ?? []);
    $samples = (int)($metrics['samples'] ?? 0);
    $observed = $metrics['observedFrequencyPct'] ?? null;
    if ($samples < 20 || !is_numeric($observed)) {
        return ['available'=>false,'rawProbabilityPct'=>round($raw,1),'calibratedProbabilityPct'=>round($raw,1),'bin'=>$bin,'samples'=>$samples,'reason'=>'insufficient-segment-samples'];
    }
    $weight = min(.75, $samples / ($samples + 50));
    $calibrated = $raw * (1 - $weight) + (float)$observed * $weight;
    return [
        'available'=>true, 'rawProbabilityPct'=>round($raw,1), 'calibratedProbabilityPct'=>round(max(0,min(100,$calibrated)),1),
        'bin'=>$bin, 'samples'=>$samples, 'observedFrequencyPct'=>(float)$observed, 'shrinkageWeight'=>round($weight,3),
        'shadowOnly'=>true,
    ];
}

function meteonexa_radar4_p33_apply_empirical_calibration(array $shadow, array $report): array {
    if (empty($shadow['available'])) return $shadow;
    foreach (['rain_start'=>'rainStart','rain_peak'=>'rainPeak','rain_end'=>'rainEnd'] as $kind=>$field) {
        if (!isset($shadow[$field]) || !is_array($shadow[$field])) continue;
        $raw = (float)($shadow[$field]['eventProbabilityPct'] ?? $shadow['eventProbabilityPct'] ?? 0);
        $shadow[$field]['empiricalCalibration'] = meteonexa_radar4_p33_empirical_probability($report, $kind, $raw);
    }
    $shadow['p33Calibration'] = [
        'available'=>!empty($report['available']), 'datasetMature'=>!empty($report['datasetMature']),
        'shadowOnly'=>true, 'authorityLockedToRadar3'=>true, 'reportPhase'=>'P3.3',
    ];
    $shadow['policy']['promotionDisabledInP33'] = true;
    $shadow['policy']['empiricalCalibrationCannotChangeProductionAuthority'] = true;
    return $shadow;
}
