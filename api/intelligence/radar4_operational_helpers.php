<?php
declare(strict_types=1);

/**
 * Radar4 P3 operational-evidence helpers.
 *
 * P3.1-P3.4 are code-complete. This layer closes the remaining software gap
 * between that code and the live-evidence gate: it can run Radar4 shadow
 * collection server-side, expose exact maturity deficits and persist immutable
 * promotion-study snapshots. It never enables Radar4 production authority.
 */
require_once __DIR__ . '/nowcast_helpers.php';
require_once __DIR__ . '/intelligence_extensions.php';
require_once __DIR__ . '/probabilistic_nowcast_helpers.php';
require_once __DIR__ . '/verification_helpers.php';
require_once __DIR__ . '/radar4_probabilistic_helpers.php';
require_once __DIR__ . '/radar4_calibration_helpers.php';
require_once __DIR__ . '/radar4_promotion_study_helpers.php';
require_once dirname(__DIR__) . '/official/lifecycle_helpers.php';
require_once dirname(__DIR__) . '/official/administrative_area_match.php';

function meteonexa_radar4_p3_progress_check(string $id, int $actual, int $required): array {
    $required = max(1, $required);
    return [
        'id'=>$id,
        'actual'=>max(0, $actual),
        'required'=>$required,
        'missing'=>max(0, $required - $actual),
        'completionPct'=>round(min(100, 100 * max(0, $actual) / $required), 1),
        'pass'=>$actual >= $required,
    ];
}

function meteonexa_radar4_p3_evidence_progress(array $p33): array {
    $requirements = (array)($p33['maturityRequirements'] ?? [
        'minimumAreas'=>3,
        'minimumSeasons'=>2,
        'minimumWeatherRegimes'=>3,
        'minimumBinarySamplesPerRainEvent'=>50,
        'minimumPositiveTimingSamplesPerRainEvent'=>30,
        'minimumGrowthSamples'=>30,
        'minimumEtaSamplesPerAlgorithm'=>60,
    ]);
    $checks = [];
    $checks[] = meteonexa_radar4_p3_progress_check('areas', (int)($p33['areas'] ?? 0), (int)($requirements['minimumAreas'] ?? 3));
    $checks[] = meteonexa_radar4_p3_progress_check('seasons', (int)($p33['seasons'] ?? 0), (int)($requirements['minimumSeasons'] ?? 2));
    $checks[] = meteonexa_radar4_p3_progress_check('weather-regimes', (int)($p33['weatherRegimes'] ?? 0), (int)($requirements['minimumWeatherRegimes'] ?? 3));
    foreach (['rain_start','rain_peak','rain_end'] as $kind) {
        $event = (array)($p33['events'][$kind] ?? []);
        $checks[] = meteonexa_radar4_p3_progress_check(
            $kind . '-binary',
            (int)($event['binary']['samples'] ?? 0),
            (int)($requirements['minimumBinarySamplesPerRainEvent'] ?? 50)
        );
        $checks[] = meteonexa_radar4_p3_progress_check(
            $kind . '-timing',
            (int)($event['timing']['samples'] ?? 0),
            (int)($requirements['minimumPositiveTimingSamplesPerRainEvent'] ?? 30)
        );
    }
    $checks[] = meteonexa_radar4_p3_progress_check(
        'growth-decay',
        (int)($p33['growthSamples'] ?? 0),
        (int)($requirements['minimumGrowthSamples'] ?? 30)
    );
    foreach (['radar-v3','radar-v4'] as $algorithm) {
        $checks[] = meteonexa_radar4_p3_progress_check(
            $algorithm . '-eta',
            (int)($p33['eta'][$algorithm]['samples'] ?? 0),
            (int)($requirements['minimumEtaSamplesPerAlgorithm'] ?? 60)
        );
    }
    $score = $checks ? array_sum(array_column($checks, 'completionPct')) / count($checks) : 0.0;
    $passed = count(array_filter($checks, static fn(array $row): bool => !empty($row['pass'])));
    return [
        'datasetMature'=>!empty($p33['datasetMature']),
        'completionPct'=>round($score, 1),
        'checksPassed'=>$passed,
        'checksTotal'=>count($checks),
        'checks'=>$checks,
        'remaining'=>array_values(array_filter($checks, static fn(array $row): bool => empty($row['pass']))),
    ];
}

function meteonexa_radar4_p3_operational_readiness(array $p33, array $p34): array {
    $progress = meteonexa_radar4_p3_evidence_progress($p33);
    $datasetMature = !empty($p33['datasetMature']);
    $studyAvailable = !empty($p34['available']);
    $studyEligible = !empty($p34['manualCanaryReviewEligible']);
    $stage = 'collecting-live-evidence';
    $next = 'continue-server-side-shadow-collection';
    if ($datasetMature && !$studyAvailable) {
        $stage = 'promotion-study-pending';
        $next = 'run-p34-on-frozen-live-dataset';
    } elseif ($studyAvailable && !$studyEligible) {
        $stage = 'promotion-study-guardrails-not-met';
        $next = 'keep-radar4-shadow-and-collect-more-evidence';
    } elseif ($studyEligible) {
        $stage = 'manual-canary-release-review-ready';
        $next = 'open-separate-manual-canary-release-review';
    }
    return [
        'available'=>!empty($p33['available']) || $studyAvailable,
        'phase'=>'P3-operational-evidence',
        'coreCodeComplete'=>true,
        'stage'=>$stage,
        'nextAction'=>$next,
        'liveEvidence'=>$progress,
        'promotionStudy'=>[
            'available'=>$studyAvailable,
            'datasetFingerprint'=>$p34['datasetFingerprint'] ?? null,
            'holdoutValidated'=>!empty($p34['holdoutValidated']),
            'stabilityValidated'=>!empty($p34['stabilityValidated']),
            'manualCanaryReviewEligible'=>$studyEligible,
            'reason'=>$p34['reason'] ?? ($datasetMature ? 'study-not-yet-available' : 'dataset-not-mature'),
        ],
        'operationalEvidenceComplete'=>$datasetMature && $studyEligible,
        'productionActivationAllowed'=>false,
        'automaticPromotion'=>false,
        'authorityLockedToRadar3'=>true,
        'productionDecisionsUnaffected'=>true,
        'canaryRelease'=>[
            'eligibleForSeparateReview'=>$studyEligible,
            'requiresSeparateRelease'=>true,
            'requiresSameDatasetFingerprint'=>true,
            'datasetFingerprint'=>$studyEligible ? ($p34['datasetFingerprint'] ?? null) : null,
            'scopeConfigured'=>false,
            'trafficPercent'=>null,
            'rollbackRequired'=>true,
            'observabilityRequired'=>true,
            'abortCriteriaMustBeExplicit'=>true,
            'activationImplementedHere'=>false,
        ],
    ];
}

function meteonexa_radar4_p34_persist_review_snapshot(string $deviceId, array $study, array $operational): array {
    $fingerprint = strtolower(trim((string)($study['datasetFingerprint'] ?? '')));
    if (empty($study['available']) || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
        return ['persisted'=>false, 'existing'=>false, 'reason'=>'promotion-study-snapshot-unavailable'];
    }
    if (!function_exists('meteonexa_storage_path') || !function_exists('meteonexa_atomic_write')) {
        return ['persisted'=>false, 'existing'=>false, 'reason'=>'storage-helper-unavailable'];
    }
    try {
        $deviceRef = substr(hash('sha256', $deviceId), 0, 20);
        $relative = 'radar4-promotion-reviews/' . $deviceRef . '/' . $fingerprint . '.json';
        $path = rtrim(meteonexa_storage_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($path)) {
            return ['persisted'=>true, 'existing'=>true, 'immutable'=>true, 'reference'=>$relative, 'datasetFingerprint'=>$fingerprint];
        }
        $payload = [
            'schema'=>'radar4-promotion-review-v1',
            'deviceRef'=>$deviceRef,
            'datasetFingerprint'=>$fingerprint,
            'study'=>$study,
            'operationalReadiness'=>$operational,
            'createdAt'=>gmdate('c'),
            'authorityLockedToRadar3'=>true,
            'automaticPromotion'=>false,
            'activationAllowed'=>false,
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json) || !meteonexa_atomic_write($path, $json . "\n", 0600)) {
            return ['persisted'=>false, 'existing'=>false, 'reason'=>'snapshot-write-failed'];
        }
        return ['persisted'=>true, 'existing'=>false, 'immutable'=>true, 'reference'=>$relative, 'datasetFingerprint'=>$fingerprint];
    } catch (Throwable $ignored) {
        return ['persisted'=>false, 'existing'=>false, 'reason'=>'snapshot-write-failed'];
    }
}

/**
 * Server-side Radar4 shadow evidence cycle for registered pipeline locations.
 * Reuses calibration models/observations already fetched by the scheduled
 * worker; additional sources are provider-cached. This makes P3 live evidence
 * collection independent from opening the PWA.
 */
function meteonexa_radar4_live_shadow_cycle(PDO $pdo, array $config, array $loc, array $calibration): array {
    if (($config['radar4']['mode'] ?? 'shadow') !== 'shadow') {
        return ['available'=>false, 'status'=>'off', 'reason'=>'radar4-shadow-disabled'];
    }
    $deviceId = trim((string)($loc['deviceId'] ?? ''));
    $lat = is_numeric($loc['latitude'] ?? null) ? (float)$loc['latitude'] : 999.0;
    $lon = is_numeric($loc['longitude'] ?? null) ? (float)$loc['longitude'] : 999.0;
    if ($deviceId === '' || abs($lat) > 90 || abs($lon) > 180) {
        return ['available'=>false, 'status'=>'invalid-location', 'reason'=>'invalid-live-shadow-location'];
    }
    $locationKey = (string)($loc['locationKey'] ?? meteonexa_intelligence_location_key($lat, $lon));
    $observations = (array)($calibration['observations'] ?? []);
    $models = (array)($calibration['models'] ?? []);
    $consensus = (array)($calibration['consensus'] ?? []);
    if (!$consensus) {
        $skill = meteonexa_recency_skill($pdo, $deviceId, $locationKey);
        $weights = (array)($skill['weights'] ?? []);
        $consensus = meteonexa_weighted_consensus($models, $weights);
    }

    $motion = meteonexa_radar_motion($pdo, $deviceId, $lat, $lon);
    $lightning = meteonexa_intelligence_lightning($config, $lat, $lon);
    $cellTracking = meteonexa_enrich_cell_tracking(meteonexa_intelq_cell_tracking($pdo, $deviceId, $lat, $lon, $lightning), $lat);
    $motion['cellTracking'] = $cellTracking;
    $satellite = meteonexa_intelligence_satellite($lat, $lon);
    $official = meteonexa_intelq_meteoalarm_edr($config, $lat, $lon, 'it') ?? meteonexa_official_alerts($lat, $lon, (string)($loc['locationName'] ?? ''), (string)($loc['admin1'] ?? ''));
    $official = meteonexa_official_apply_position_area_match($official, (string)($loc['locationName'] ?? ''), (string)($loc['admin1'] ?? ''));
    if (!isset($official['mode'])) {
        $official['mode'] = 'atom-text-fallback';
        $official['geospatial'] = false;
    }
    $official = meteonexa_official_track($pdo, $lat, $lon, (string)($loc['locationName'] ?? ''), $official);

    $fusion = meteonexa_nowcast_fusion($consensus, $motion, $cellTracking, $lightning, $satellite, $official, $observations, $lat);
    $nowcast = meteonexa_object_nowcast($pdo, $deviceId, $locationKey, $fusion, $consensus, $cellTracking, $motion);
    $nowcast['radar3Tracking'] = $motion['radar3Tracking'] ?? ['available'=>false, 'cells'=>[]];
    $nowcast['radar3Mode'] = $motion['radar3Mode'] ?? 'active-fallback-v2';
    $nowcast['radar3ProductionGate'] = $motion['radar3ProductionGate'] ?? ['eligible'=>false, 'reason'=>'unavailable'];
    $nowcast['radar4Tracking'] = $motion['radar4Tracking'] ?? ['available'=>false, 'cells'=>[], 'mode'=>'shadow'];
    $nowcast['radar4Mode'] = 'shadow';
    $nowcastV4 = meteonexa_probabilistic_nowcast($nowcast, $consensus, $lightning, $satellite, [], [], $observations, $official);

    $archiveDistance = is_numeric($motion['archiveDistanceKm'] ?? null) ? (float)$motion['archiveDistanceKm'] : null;
    $terrain = meteonexa_radar4_p33_terrain_profile($lat, $lon, null);
    $shadow = meteonexa_radar4_probabilistic_shadow(
        (array)($motion['radar4Tracking'] ?? []),
        $nowcastV4,
        $consensus,
        $lightning,
        $satellite,
        $observations,
        $official,
        null,
        $archiveDistance,
        $terrain
    );
    $calibrationBefore = meteonexa_radar4_p33_calibration_report($pdo, $deviceId);
    $shadow = meteonexa_radar4_p33_apply_empirical_calibration($shadow, $calibrationBefore);
    $context = meteonexa_radar4_p33_context($locationKey, $shadow, $lightning, $satellite, $observations, $terrain, $archiveDistance);
    $etaSkill = meteonexa_radar_skill_update(
        $pdo,
        $deviceId,
        $locationKey,
        $nowcast,
        $fusion,
        $observations,
        (array)($motion['radarObservation'] ?? []),
        [],
        (array)($motion['radar3Tracking'] ?? []),
        (array)($motion['radar4Tracking'] ?? []),
        $context
    );
    $verification = meteonexa_radar4_event_verification_update($pdo, $deviceId, $locationKey, $shadow, $context);
    $p33 = meteonexa_radar4_p33_calibration_report($pdo, $deviceId);
    $p34 = meteonexa_radar4_p34_promotion_study($pdo, $deviceId, $p33);
    $operational = meteonexa_radar4_p3_operational_readiness($p33, $p34);
    $snapshot = meteonexa_radar4_p34_persist_review_snapshot($deviceId, $p34, $operational);

    return [
        'available'=>true,
        'status'=>!empty($p34['manualCanaryReviewEligible']) ? 'manual-canary-review-ready' : (!empty($p33['datasetMature']) ? 'promotion-study-running' : 'collecting-live-evidence'),
        'shadowAvailable'=>!empty($shadow['available']),
        'eventQueued'=>(int)($verification['queued'] ?? 0),
        'eventVerified'=>(int)($verification['verified'] ?? 0),
        'etaSamples'=>(int)($etaSkill['samples'] ?? 0),
        'datasetMature'=>!empty($p33['datasetMature']),
        'evidenceCompletionPct'=>(float)($operational['liveEvidence']['completionPct'] ?? 0),
        'manualCanaryReviewEligible'=>!empty($p34['manualCanaryReviewEligible']),
        'datasetFingerprint'=>$p34['datasetFingerprint'] ?? null,
        'reviewSnapshot'=>$snapshot,
        'authorityLockedToRadar3'=>true,
        'productionDecisionsUnaffected'=>true,
    ];
}
