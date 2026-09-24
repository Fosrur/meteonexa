<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/intelligence/engine_helpers.php';
require_once dirname(__DIR__) . '/intelligence/quality_helpers.php';
require_once dirname(__DIR__) . '/intelligence/reliability_helpers.php';
require_once dirname(__DIR__) . '/intelligence/nowcast_helpers.php';
require_once dirname(__DIR__) . '/intelligence/confidence_helpers.php';
require_once dirname(__DIR__) . '/intelligence/intelligence_extensions.php';
require_once dirname(__DIR__) . '/intelligence/decision_timeline_helpers.php';
require_once dirname(__DIR__) . '/intelligence/severe_outlook_helpers.php';
require_once dirname(__DIR__) . '/intelligence/convective_v4.php';
require_once dirname(__DIR__) . '/account/account_helpers.php';
require_once dirname(__DIR__) . '/route/weather_engine.php';

function meteonexa_copilot_keywords(string $message): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtolower($message);
}

function meteonexa_copilot_activity(string $message): ?string
{
    $text = meteonexa_copilot_keywords($message);
    $rules = [
        'motorcycle' => ['moto', 'motorcycle', 'scooter'],
        'bike' => ['bici', 'bike', 'cycling', 'cicl'],
        'run' => ['corsa', 'correre', 'run', 'running'],
        'sea' => ['mare', 'spiaggia', 'sea', 'beach'],
        'trekking' => ['trekking', 'hiking', 'escursion'],
        'kids' => ['bambin', 'kids', 'children'],
        'pets' => ['cane', 'animali', 'pets', 'dog'],
        'worksite' => ['cantiere', 'worksite'],
        'commute' => ['commute', 'pendolar', 'ufficio'],
        'event' => ['evento', 'event', 'cerimonia'],
        'photography' => ['foto', 'fotografia', 'photography'],
    ];
    foreach ($rules as $activity => $needles) foreach ($needles as $needle) if (str_contains($text, $needle)) return $activity;
    return null;
}

function meteonexa_copilot_selected_tools(string $message, bool $hasRoutePlan = false): array
{
    $text = meteonexa_copilot_keywords($message);
    $has = static function(array $needles) use ($text): bool {
        foreach ($needles as $needle) if (str_contains($text, $needle)) return true;
        return false;
    };
    $tools = ['current_forecast', 'confidence'];
    if ($has(['piogg', 'rain', 'tempor', 'storm', 'grand', 'hail', 'neve', 'snow', 'radar', 'arriv', 'finisc', 'when', 'quando'])) $tools[] = 'nowcast';
    if ($has(['affid', 'confiden', 'sicuro', 'accur', 'modello', 'model', 'cambi', 'change', 'perché', 'why'])) $tools[] = 'forecast_change';
    if (meteonexa_copilot_activity($message) !== null || $has(['attivit', 'activity', 'quando conviene', 'best window'])) $tools[] = 'decision';
    if ($hasRoutePlan || $has(['percorso', 'route', 'viaggio', 'travel', 'partire', 'departure', 'tratta'])) $tools[] = 'route';
    return array_values(array_unique($tools));
}

function meteonexa_copilot_route_plan(array $raw): array
{
    $points = meteonexa_route_points(is_array($raw['points'] ?? null) ? $raw['points'] : []);
    if (count($points) < 2) return [];
    $departure = trim((string)($raw['departureAt'] ?? ''));
    $duration = max(30, min(720, (int)($raw['durationMinutes'] ?? 120)));
    $mode = strtolower(trim((string)($raw['mode'] ?? 'car')));
    if (!in_array($mode, ['car', 'motorcycle', 'bike', 'walk', 'trekking'], true)) $mode = 'car';
    return [
        'origin' => clean_text($raw['origin'] ?? '', 120, ''),
        'destination' => clean_text($raw['destination'] ?? '', 120, ''),
        'points' => $points,
        'departureAt' => $departure,
        'durationMinutes' => $duration,
        'mode' => $mode,
    ];
}

function meteonexa_copilot_route_thresholds(array $profiles, string $mode): ?array
{
    $key = $mode === 'motorcycle' ? 'motorcycle' : ($mode === 'bike' ? 'bike' : (in_array($mode, ['walk', 'trekking'], true) ? 'trekking' : ''));
    return $key !== '' && is_array($profiles[$key] ?? null) ? $profiles[$key] : null;
}

function meteonexa_copilot_decision_summary(?array $activityRow, array $confidence, array $nowcast, array $route = []): array
{
    $status = 'learning';
    $score = null;
    $best = null;
    $alternative = null;
    $risk = 'none';
    if (is_array($activityRow)) {
        $best = $activityRow['bestWindow'] ?? null;
        $alternative = $activityRow['alternativeWindow'] ?? null;
        $score = is_array($best) && is_numeric($best['score'] ?? null) ? (int)$best['score'] : null;
        if ($score !== null) $status = $score >= 75 ? 'good' : ($score >= 50 ? 'caution' : 'avoid');
        $timeline = is_array($activityRow['timeline'] ?? null) ? $activityRow['timeline'] : [];
        if ($timeline) {
            $worst = null;
            foreach ($timeline as $row) if ($worst === null || (int)($row['score'] ?? 100) < (int)($worst['score'] ?? 100)) $worst = $row;
            if (is_array($worst)) $risk = (string)($worst['risk'] ?? 'none');
        }
    }
    if (!empty($route['available'])) {
        $routeSafety = (int)($route['selectedSafetyScore'] ?? 0);
        if ($score === null || $routeSafety < $score) $score = $routeSafety;
        $status = (string)($route['status'] ?? $status);
        $critical = is_array($route['criticalSegment'] ?? null) ? $route['criticalSegment'] : [];
        $reasons = (array)($critical['reasons'] ?? []);
        if ($reasons) $risk = (string)$reasons[0];
    }
    $confidenceScore = (int)($confidence['score'] ?? 0);
    if ($confidenceScore < 45 && $status === 'good') $status = 'caution';
    if ((int)($nowcast['impactProbability'] ?? 0) >= 70 && in_array($status, ['good', 'caution'], true)) $status = 'avoid';
    return [
        'status' => $status,
        'score' => $score,
        'confidence' => $confidenceScore,
        'dominantRisk' => $risk,
        'bestWindow' => $best,
        'alternativeWindow' => $alternative,
        'route' => !empty($route['available']) ? [
            'status' => $route['status'] ?? null,
            'selectedRisk' => $route['selectedRisk'] ?? null,
            'selectedSafetyScore' => $route['selectedSafetyScore'] ?? null,
            'bestDeparture' => $route['bestDeparture']['at'] ?? null,
            'bestRisk' => $route['bestDeparture']['risk'] ?? null,
            'improvementPoints' => $route['improvementPoints'] ?? null,
            'criticalSegment' => $route['criticalSegment'] ?? null,
            'sampleCount' => $route['sampleCount'] ?? null,
        ] : null,
        'nowcast' => !empty($nowcast['available']) ? [
            'impactProbability' => $nowcast['impactProbability'] ?? null,
            'etaMinutes' => $nowcast['etaMinutes'] ?? null,
            'etaRangeMinutes' => $nowcast['etaRangeMinutes'] ?? null,
            'confidence' => $nowcast['confidence'] ?? null,
        ] : null,
    ];
}

function meteonexa_copilot_orchestrate(PDO $pdo, array $config, string $deviceId, array $session, string $message, float $lat, float $lon, string $locationName = '', array $routePlanRaw = []): array
{
    if (abs($lat) > 90 || abs($lon) > 180) throw new InvalidArgumentException('INVALID_COORDINATES');
    $routePlan = meteonexa_copilot_route_plan($routePlanRaw);
    $selected = meteonexa_copilot_selected_tools($message, $routePlan !== []);
    $locationKey = meteonexa_intelligence_location_key($lat, $lon);
    $tools = [];
    $sources = [];

    $weather = meteonexa_intelligence_weather($lat, $lon, false, 72);
    $current = (array)($weather['current'] ?? []);
    $hourly = (array)($weather['hourly'] ?? []);
    $tools['current_forecast'] = [
        'generatedAt' => gmdate('c'),
        'location' => ['name' => clean_text($locationName, 120, '')],
        'timezone' => clean_text($weather['timezone'] ?? '', 80, ''),
        'current' => array_filter([
            'time' => $current['time'] ?? null,
            'temperatureC' => $current['temperature_2m'] ?? null,
            'feelsLikeC' => $current['apparent_temperature'] ?? null,
            'precipitationMm' => $current['precipitation'] ?? null,
            'weatherCode' => $current['weather_code'] ?? null,
            'windKmh' => $current['wind_speed_10m'] ?? null,
            'gustKmh' => $current['wind_gusts_10m'] ?? null,
        ], static fn($v) => $v !== null),
        'nextHours' => [],
    ];
    $times = (array)($hourly['time'] ?? []);
    for ($i = 0; $i < min(12, count($times)); $i++) {
        $tools['current_forecast']['nextHours'][] = array_filter([
            'time' => $times[$i] ?? null,
            'temperatureC' => $hourly['temperature_2m'][$i] ?? null,
            'rainProbability' => $hourly['precipitation_probability'][$i] ?? null,
            'precipitationMm' => $hourly['precipitation'][$i] ?? null,
            'weatherCode' => $hourly['weather_code'][$i] ?? null,
            'gustKmh' => $hourly['wind_gusts_10m'][$i] ?? null,
        ], static fn($v) => $v !== null);
    }
    $sources[] = ['id' => 'open-meteo-best-match', 'type' => 'weather', 'generatedAt' => gmdate('c')];

    $models = meteonexa_intelq_fetch_models($lat, $lon, false, 72);
    $skill = meteonexa_intelq_skill_summary($pdo, $deviceId, $locationKey);
    $skillV2 = meteonexa_recency_skill($pdo, $deviceId, $locationKey);
    $championWeights = (array)($skillV2['weights'] ?? []);
    $weightTournament = meteonexa_reliability_weight_tournament($pdo, $deviceId, $locationKey, $championWeights);
    $weights = (array)($weightTournament['activeWeights'] ?? $championWeights);
    $canonicalConsensus = meteonexa_intelq_canonical_consensus($models);
    $weightedConsensus = meteonexa_weighted_consensus($models, $weights, meteonexa_reliability_tournament_weighting_meta($weightTournament));
    $consensus = $canonicalConsensus;
    $canonicalPrimary = (array)($canonicalConsensus['primary'] ?? []);
    $tools['model_consensus'] = [
        'modelsExpected' => count(meteonexa_intelq_model_definitions()),
        'modelsAvailable' => (int)($canonicalConsensus['modelsAvailable'] ?? $canonicalConsensus['availableModels'] ?? count(array_filter($models, static fn($model) => !empty($model['available'])))),
        'primary' => array_filter([
            'agreementPct' => $canonicalPrimary['agreementPct'] ?? null,
            'temperatureC' => $canonicalPrimary['temperature'] ?? $canonicalPrimary['temperatureC'] ?? null,
            'rainProbability' => $canonicalPrimary['precipitationProbability'] ?? $canonicalPrimary['rainProbability'] ?? null,
        ], static fn($value) => $value !== null),
        'localWeightsAppliedInternally' => $weights !== [],
        'weightingMode' => $weightTournament['mode'] ?? 'champion',
        'promotedWeightBuckets' => (int)($weightTournament['promotedBuckets'] ?? 0),
        'weightedAgreementPct' => $weightedConsensus['primary']['weightedAgreementPct'] ?? null,
    ];
    foreach ($models as $id => $model) {
        $sources[] = ['id' => $id, 'label' => $model['label'] ?? $id, 'type' => $id === 'aifs' ? 'ai-weather-model' : 'nwp', 'available' => !empty($model['available']), 'retrievedAt' => $model['retrievedAt'] ?? null];
    }

    $freshness = meteonexa_intelq_source_freshness($models, [], [], [], [], []);
    $primary = (array)($consensus['primary'] ?? []);
    $analysis = ['confidence' => (int)($primary['weightedAgreementPct'] ?? $primary['agreementPct'] ?? 60)];
    $reliability = ['metricVerifiedSamples' => (int)($skill['verifiedSamples'] ?? 0), 'observationQualityScore' => null, 'runStability' => meteonexa_forecast_run_stability($pdo, $deviceId, $locationKey)];
    $baseConfidence = meteonexa_weather_confidence($analysis, $consensus, $reliability, ['available' => false]);
    $confidence = meteonexa_confidence_timeline($consensus, $baseConfidence, $freshness, $skillV2, ['available' => false]);
    $tools['confidence'] = $confidence;

    $motion = [];
    $cell = [];
    $nowcast = ['available' => false];
    $official = ['available' => false, 'relevant' => []];
    $severeOutlook = ['available' => false, 'events' => []];
    $convectiveRisk = ['available' => false];
    if (in_array('nowcast', $selected, true) || in_array('decision', $selected, true) || in_array('route', $selected, true)) {
        $motion = meteonexa_radar_motion($pdo, $deviceId, $lat, $lon);
        $cell = meteonexa_enrich_cell_tracking(meteonexa_intelq_cell_tracking($pdo, $deviceId, $lat, $lon, ['available' => false]), $lat);
        $lightning = meteonexa_intelligence_lightning($config, $lat, $lon);
        $satellite = meteonexa_intelligence_satellite($lat, $lon);
        $official = meteonexa_official_alerts($lat, $lon, $locationName, '');
        $fusion = meteonexa_nowcast_fusion($consensus, $motion, $cell, $lightning, $satellite, $official, ['sourceCount' => 0], $lat);
        $nowcast = meteonexa_object_nowcast($pdo, $deviceId, $locationKey, $fusion, $consensus, $cell, $motion);
        $tools['nowcast'] = $nowcast;
        $severeOutlook = meteonexa_severe_outlook($analysis, $consensus, $models, $lightning, 72, 55.0);
        $convectiveRisk = meteonexa_convective_risk_v4($pdo, $deviceId, $locationKey, $lat, $lon, $severeOutlook, $lightning, (array)($motion['radar3Tracking'] ?? []), 72);
        $officialRows = [];
        foreach (array_slice((array)($official['relevant'] ?? []), 0, 3) as $warning) {
            if (!is_array($warning)) continue;
            $officialRows[] = array_filter([
                'event' => clean_text($warning['event'] ?? '', 80, ''),
                'title' => clean_text($warning['title'] ?? '', 140, ''),
                'severity' => clean_text($warning['severity'] ?? '', 20, ''),
                'startsAt' => $warning['startsAt'] ?? null,
                'endsAt' => $warning['endsAt'] ?? null,
            ], static fn($value) => $value !== null && $value !== '');
        }
        $tools['official_warnings'] = [
            'available' => !empty($official['available']),
            'relevantCount' => count((array)($official['relevant'] ?? [])),
            'warnings' => $officialRows,
            'source' => (string)($official['source'] ?? 'MeteoAlarm'),
        ];
        $convectivePeak = (array)($convectiveRisk['peak'] ?? []);
        $tools['convective_risk'] = [
            'available' => !empty($convectiveRisk['available']),
            'probabilityPct' => $convectivePeak['calibratedProbabilityPct'] ?? $convectivePeak['score'] ?? null,
            'confidence' => $convectivePeak['confidence'] ?? null,
            'startsAt' => $convectivePeak['time'] ?? null,
            'hailPotential' => $convectiveRisk['hailPotential'] ?? ['available' => false],
            'calibrationMode' => $convectiveRisk['calibration']['mode'] ?? null,
        ];
        $sources[] = ['id' => 'radar', 'type' => 'radar', 'available' => !empty($motion['available'])];
        $sources[] = ['id' => 'lightning', 'type' => 'lightning', 'available' => !empty($lightning['available'])];
        $sources[] = ['id' => 'satellite', 'type' => 'satellite', 'available' => !empty($satellite['available'])];
        $sources[] = ['id' => 'official-alerts', 'type' => 'official', 'available' => !empty($official['relevant'])];
    }

    $accountHash = meteonexa_account_sync_hash($config, $session);
    $profiles = meteonexa_account_activity_load($pdo, $accountHash);
    $trust = meteonexa_trust_scoreboard($pdo, $deviceId, $locationKey);
    $tools['trust_score'] = [
        'learning' => !empty($trust['learning']),
        'temperatureMae' => $trust['temperatureMae'] ?? null,
        'precipitationBrier' => $trust['precipitationBrier'] ?? null,
        'stormBrier' => $trust['stormBrier'] ?? null,
        'radarEtaMaeMinutes' => $trust['radarEtaMaeMinutes'] ?? null,
        'radarEtaWithinTolerancePct' => $trust['radarEtaWithinTolerancePct'] ?? null,
        'samples' => $trust['samples'] ?? [],
    ];
    $decisionWindows = meteonexa_intelq_decision_windows($models, $profiles);
    $timeline = meteonexa_decision_timeline($consensus, $profiles, $nowcast, $confidence, $decisionWindows);
    $activity = meteonexa_copilot_activity($message) ?? 'commute';
    $activityRow = is_array($timeline['activities'][$activity] ?? null) ? $timeline['activities'][$activity] : null;
    if (in_array('decision', $selected, true) || in_array('route', $selected, true)) {
        $tools['decision'] = ['activity' => $activity, 'activityResult' => $activityRow, 'timelineGeneratedAt' => $timeline['generatedAt'] ?? null];
    }

    $route = [];
    if (in_array('route', $selected, true) && $routePlan !== []) {
        $thresholds = meteonexa_copilot_route_thresholds($profiles, (string)$routePlan['mode']);
        $route = meteonexa_route_analyze($routePlan['points'], (string)$routePlan['departureAt'], (int)$routePlan['durationMinutes'], (string)$routePlan['mode'], $thresholds);
        $tools['route'] = [
            'origin' => $routePlan['origin'],
            'destination' => $routePlan['destination'],
            'mode' => $routePlan['mode'],
            'departureAt' => $route['departureAt'] ?? null,
            'durationMinutes' => $route['durationMinutes'] ?? null,
            'selectedRisk' => $route['selectedRisk'] ?? null,
            'selectedSafetyScore' => $route['selectedSafetyScore'] ?? null,
            'status' => $route['status'] ?? null,
            'criticalSegment' => $route['criticalSegment'] ?? null,
            'criticalWindKmh' => $route['criticalSegment']['windKmh'] ?? null,
            'criticalGustKmh' => $route['criticalSegment']['gustKmh'] ?? null,
            'criticalCrosswindKmh' => $route['criticalSegment']['crosswindKmh'] ?? null,
            'bestDeparture' => $route['bestDeparture'] ?? null,
            'improvementPoints' => $route['improvementPoints'] ?? null,
            'sampleCount' => $route['sampleCount'] ?? null,
        ];
        $sources[] = ['id' => 'route-weather', 'type' => 'route-weather', 'available' => true];
    }

    $decision = meteonexa_copilot_decision_summary($activityRow, $confidence, $nowcast, $route);
    $decision['activity'] = $activity;
    $decision['routeRequested'] = in_array('route', $selected, true);
    $decision['routeAvailable'] = !empty($route['available']);
    $decision['sourceCount'] = count(array_filter($sources, static fn($row) => ($row['available'] ?? true) !== false));
    $decision['generatedAt'] = gmdate('c');

    return [
        'toolRun' => [
            'selected' => $selected,
            'executed' => array_keys($tools),
            'serverGenerated' => true,
            'deterministicDecision' => true,
            'preciseCoordinatesExcludedFromLlm' => true,
            'routeCoordinatesExcludedFromLlm' => true,
            'generatedAt' => gmdate('c'),
        ],
        'tools' => $tools,
        'decision' => $decision,
        'sources' => $sources,
    ];
}
