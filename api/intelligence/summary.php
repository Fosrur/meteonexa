<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once __DIR__ . '/quality_helpers.php';
require_once __DIR__ . '/reliability_helpers.php';
require_once __DIR__ . '/nowcast_helpers.php';
require_once __DIR__ . '/confidence_helpers.php';
require_once __DIR__ . '/intelligence_extensions.php';
require_once __DIR__ . '/decision_timeline_helpers.php';
require_once __DIR__ . '/probabilistic_nowcast_helpers.php';
require_once __DIR__ . '/severe_outlook_helpers.php';
require_once __DIR__ . '/convective_v3.php';
require_once __DIR__ . '/convective_v4.php';
require_once __DIR__ . '/verification_helpers.php';
require_once __DIR__ . '/sun_cloud_helpers.php';
require_once dirname(__DIR__) . '/observations/providers.php';
require_once dirname(__DIR__) . '/account/account_helpers.php';
require_once dirname(__DIR__) . '/official/lifecycle_helpers.php';
require_once dirname(__DIR__) . '/pipeline/helpers.php';
require_once dirname(__DIR__) . '/calibration/helpers.php';
require_method('GET');
assert_same_origin();
$config = load_config();
$pdo = meteonexa_db($config);
$deviceId = clean_device_id($_GET['deviceId']??'');
$session = require_authenticated_device_session($pdo, $config, $deviceId);
require_ip_rate_limit($pdo, 'intelligence_summary_ip', 240, 3600);
require_device_rate_limit($pdo, 'intelligence_summary_device', $deviceId, 120, 3600);
$lat = query_float('lat', 999);
$lon = query_float('lon', 999);
if (abs($lat) > 90||abs($lon) > 180)respond(['ok'=>false, 'code'=>'INVALID_COORDINATES', 'message'=>meteonexa_backend_text('api.backend.invalid_coordinates')], 422);
$locationName = clean_text($_GET['location']??'', 120, '');
$admin1 = clean_text($_GET['admin1']??'', 120, '');
$locale = strtolower(clean_text($_GET['lang']??'en', 8, 'en'));
if (!in_array($locale,['it', 'en', 'fr', 'es', 'de'], true))$locale = 'en';
$requestStarted = microtime(true);
$requestId = meteonexa_request_id();
$accountHash = meteonexa_account_sync_hash($config, $session);
$accountAlerts = meteonexa_account_sync_read($pdo, $accountHash, 'alerts', 'profile');
$profile = is_array($accountAlerts['payload']??null) ? $accountAlerts['payload'] :[];
if (!$profile) {
    $st = $pdo->prepare('SELECT profile_json FROM alert_profiles WHERE device_id=:device');
    $st->execute([':device'=>$deviceId]);
    $row = $st->fetch();
    if ($row)$profile = json_decode((string)$row['profile_json'], true) ? :[];
}
$profile = meteonexa_intelligence_profile($profile);
$activityProfiles = meteonexa_account_activity_load($pdo, $accountHash);
$weather = meteonexa_intelligence_weather($lat, $lon, false, 72);
$air = meteonexa_intelligence_air($lat, $lon, false);
$locationKey = meteonexa_intelligence_location_key($lat, $lon);
$accuracy = meteonexa_intelligence_accuracy($pdo, $deviceId, $locationKey);
$models = meteonexa_intelq_fetch_models($lat, $lon, false, 72);
$canonicalConsensus = meteonexa_intelq_canonical_consensus($models);
$skill = meteonexa_intelq_skill_summary($pdo, $deviceId, $locationKey);
$skillV2 = meteonexa_recency_skill($pdo, $deviceId, $locationKey);
$championWeights = (array)($skillV2['weights']??[]);
$weightTournament = meteonexa_reliability_weight_tournament($pdo, $deviceId, $locationKey, $championWeights);
$weights = (array)($weightTournament['activeWeights']??$championWeights);
$consensus = meteonexa_weighted_consensus($models, $weights, meteonexa_reliability_tournament_weighting_meta($weightTournament));
$observations = meteonexa_observations_collect($pdo, $config, $deviceId, $lat, $lon, true);
$calibrationProgress = meteonexa_calibration_touch_current($pdo,['deviceId'=>$deviceId, 'locationKey'=>$locationKey], $observations, $models, $consensus);
if ((int)($calibrationProgress['verified']??0) > 0) {
    $skill = meteonexa_intelq_skill_summary($pdo, $deviceId, $locationKey);
    $skillV2 = meteonexa_recency_skill($pdo, $deviceId, $locationKey);
    $championWeights = (array)($skillV2['weights']??[]);
    $weightTournament = meteonexa_reliability_weight_tournament($pdo, $deviceId, $locationKey, $championWeights, null, true);
    $weights = (array)($weightTournament['activeWeights']??$championWeights);
    $consensus = meteonexa_weighted_consensus($models, $weights, meteonexa_reliability_tournament_weighting_meta($weightTournament));
}
$motion = meteonexa_radar_motion($pdo, $deviceId, $lat, $lon);
$lightning = meteonexa_intelligence_lightning($config, $lat, $lon);
$cellTracking = meteonexa_enrich_cell_tracking(meteonexa_intelq_cell_tracking($pdo, $deviceId, $lat, $lon, $lightning), $lat);
$motion['cellTracking'] = $cellTracking;
$satellite = meteonexa_intelligence_satellite($lat, $lon);
$official = meteonexa_intelq_meteoalarm_edr($config, $lat, $lon, $locale)??meteonexa_official_alerts($lat, $lon, $locationName, $admin1);
if (!isset($official['mode'])) {
    $official['mode'] = 'atom-text-fallback';
    $official['geospatial'] = false;
}
$official = meteonexa_official_track($pdo, $lat, $lon, $locationName, $official);
$official['generatedAt'] = gmdate('c');
$hyperlocal = meteonexa_intelligence_hyperlocal($pdo, $config, $deviceId, $lat, $lon);
$analysis = meteonexa_intelligence_analyze($weather, $air, $profile,['accuracy'=>$accuracy, 'radarMotion'=>$motion, 'lightning'=>$lightning, 'satellite'=>$satellite, 'official'=>$official, 'hyperlocal'=>$hyperlocal, 'forecastWindowHours'=>72]);
$severeOutlook = meteonexa_severe_outlook($analysis, $consensus, $models, $lightning, 72, (float)($profile['thresholds']['wind']??55));
$analysis = meteonexa_apply_severe_outlook($analysis, $severeOutlook);
$convectiveRiskV3 = meteonexa_convective_risk_v4($pdo, $deviceId, $locationKey, $lat, $lon, $severeOutlook, $lightning, (array)($motion['radar3Tracking']??[]), 72);
if (!empty($convectiveRiskV3['available'])) {
    foreach ($analysis['events'] as &$event) {
        if (($event['type']??'')==='storm'&&!empty($event['predictive']))$event['convectiveRisk'] = $convectiveRiskV3['peak']??null;
    }
    unset($event);
}
$metric = (string)($consensus['primary']['type']??'');
$raw = (float)(($consensus['primary']['weightedAgreementPct']??$consensus['primary']['agreementPct']??0) / 100);
$horizon = meteonexa_intelq_nearest_horizon($consensus['primary']['startsAt']??null);
$calibration = meteonexa_intelq_calibrate_probability($pdo, $deviceId, $locationKey, $metric, $raw, $horizon);
if (!empty($calibration['available'])&&!empty($calibration['publishable'])&&in_array($metric,['rain', 'storm', 'snow'], true)) {
    $analysis['rawConfidence'] = $analysis['confidence'];
    $analysis['confidence'] = (int)round(((int)$analysis['confidence'] * .55) +((int)$calibration['calibratedProbabilityPct'] * .45));
}
$snapshot = meteonexa_intelq_snapshot_from_consensus($consensus, $analysis);
$forecastChange = meteonexa_intelq_persist_run_snapshot($pdo, $deviceId, $locationKey, $snapshot);
$previousRuns = meteonexa_intelq_previous_runs($lat, $lon, false);
$forecastReliability = meteonexa_reliability_summary($pdo, $deviceId, $locationKey, $skill, $observations, $consensus, $calibration, $weightTournament);
$forecastReliability['weights'] = $weights;
$nowcastFusion = meteonexa_nowcast_fusion($consensus, $motion, $cellTracking, $lightning, $satellite, $official, $observations, $lat);
$nowcastFusion['trend'] = meteonexa_persist_nowcast($pdo, $deviceId, $locationKey, $nowcastFusion);
$weatherConfidence = meteonexa_weather_confidence($analysis, $consensus, $forecastReliability, $nowcastFusion);
$nowcastV2 = meteonexa_object_nowcast($pdo, $deviceId, $locationKey, $nowcastFusion, $consensus, $cellTracking, $motion);
$radarSkill = meteonexa_radar_skill_update($pdo, $deviceId, $locationKey, $nowcastV2, $nowcastFusion, $observations, (array)($motion['radarObservation']??[]), $hyperlocal, (array)($motion['radar3Tracking']??[]));
$nowcastV2['verifiedSkill'] = $radarSkill;
$nowcastV2['multiCellTracking'] = $motion['multiCellTracking']??['available'=>false, 'cells'=>[]];
$nowcastV2['radar3Tracking'] = $motion['radar3Tracking']??['available'=>false, 'cells'=>[]];
$nowcastV2['radar3Mode'] = $motion['radar3Mode']??'active-fallback-v2';
$nowcastV2['radar3RequestedMode'] = $motion['radar3RequestedMode']??'active';
$nowcastV2['radar3ProductionGate'] = $motion['radar3ProductionGate']??['eligible'=>false, 'reason'=>'unavailable'];
$nowcastV4 = meteonexa_probabilistic_nowcast($nowcastV2, $consensus, $lightning, $satellite, $convectiveRiskV3, $severeOutlook, $observations, $official);
$freshness = meteonexa_intelq_source_freshness($models, $motion, $lightning, $satellite, $official, $hyperlocal);
foreach ((array)($observations['evidence']??[]) as $obs)$freshness[] =['id'=>'obs-' .($obs['sourceType']??'source'), 'label'=>$obs['station']??$obs['source']??'Observation', 'available'=>true, 'retrievedAt'=>$obs['observedAt']??null, 'ageMinutes'=>$obs['ageMinutes']??null, 'freshnessBasis'=>'observation', 'kind'=>'independentObservation'];
$explainability = meteonexa_intelq_explainability($analysis, $consensus, $motion, $lightning, $satellite, $official, $hyperlocal, $skill);
$decisions = meteonexa_intelq_decision_windows($models, $activityProfiles);
$confidenceV2 = meteonexa_confidence_timeline($consensus, $weatherConfidence, $freshness, $skillV2, $forecastChange);
$decisionTimeline = meteonexa_decision_timeline($consensus, $activityProfiles, $nowcastV2, $confidenceV2, $decisions);
$forecastChangeV2 = meteonexa_forecast_change_timeline($pdo, $deviceId, $locationKey, $forecastChange, $nowcastV2);
$sunCloudWindow = meteonexa_sun_cloud_window($weather, $confidenceV2, $satellite);
$personalTwin = meteonexa_personal_weather_twin($activityProfiles, $decisions);
foreach ((array)($personalTwin['profiles']??[]) as $tw) {
    if (!empty($tw['bestStart']))meteonexa_queue_decision_verification($pdo, $deviceId, $locationKey, 'twin', (string)($tw['activity']??'activity'), $tw['bestStart']??null, $tw['bestEnd']??null, is_numeric($tw['score']??null) ? (float)$tw['score'] : null, $tw);
}
meteonexa_verify_decision_samples($pdo, $deviceId, $locationKey, $observations);
$predictiveVerification = meteonexa_predictive_alert_update($pdo, $deviceId, $locationKey, (array)($analysis['events']??[]), $observations);
$predictiveOpportunities = meteonexa_predictive_opportunity_update($pdo, $deviceId, $locationKey, (array)($analysis['events']??[]), (int)($profile['thresholds']['wind']??55));
$aiWeatherModels = meteonexa_ai_model_status($models);
$trustScoreboard = meteonexa_trust_scoreboard($pdo, $deviceId, $locationKey);
$freshnessTrust =['radarAgeMinutes'=>$motion['ageMinutes']??null, 'radarObservedAt'=>$motion['latestFrameAt']??null, 'modelsFresh'=>count(array_filter($models, static fn($m)=>!empty($m['available'])&&empty($m['stale']))), 'modelsExpected'=>count(meteonexa_intelq_model_definitions()), 'aifsAgeMinutes'=>null, 'aifsFetchAgeMinutes'=>null, 'aifsRunAgeMinutes'=>null, 'aifsRunEstimatedAt'=>null, 'aifsRunTimeEstimated'=>true, 'models'=>[]];
foreach ($freshness as $src) {
    if (($src['kind']??'')==='model')$freshnessTrust['models'][] =['id'=>$src['id']??'', 'fetchAgeMinutes'=>$src['fetchAgeMinutes']??$src['ageMinutes']??null, 'cacheAgeMinutes'=>$src['cacheAgeMinutes']??null, 'modelRunAgeMinutes'=>$src['modelRunAgeMinutes']??null, 'modelRunEstimatedAt'=>$src['modelRunEstimatedAt']??null, 'runTimeEstimated'=>!empty($src['runTimeEstimated']), 'stale'=>!empty($src['stale'])];
    if (($src['id']??'')==='aifs') {
        $freshnessTrust['aifsAgeMinutes'] = $src['ageMinutes']??null;
        $freshnessTrust['aifsFetchAgeMinutes'] = $src['fetchAgeMinutes']??$src['ageMinutes']??null;
        $freshnessTrust['aifsRunAgeMinutes'] = $src['modelRunAgeMinutes']??null;
        $freshnessTrust['aifsRunEstimatedAt'] = $src['modelRunEstimatedAt']??null;
        $freshnessTrust['aifsRunTimeEstimated'] = !empty($src['runTimeEstimated']);
    }
}
$retention = meteonexa_prune_verified_precision($pdo, $config);
$durationMs = round((microtime(true) - $requestStarted) * 1000, 1);
meteonexa_record_runtime_metric($pdo, 'intelligence', 'summary', 'ok', $durationMs,['freshModels'=>$freshnessTrust['modelsFresh'], 'radarAvailable'=>!empty($motion['available']), 'radar3Mode'=>$motion['radar3Mode']??'shadow'], $durationMs);
respond(['ok'=>true, 'mode'=>'authenticated', 'analysis'=>$analysis, 'accuracy'=>$accuracy, 'consensus'=>$canonicalConsensus, 'weightedConsensus'=>$consensus, 'modelSkill'=>$skill, 'modelSkillV2'=>$skillV2, 'confidenceCalibration'=>$calibration, 'calibrationProgress'=>$calibrationProgress, 'forecastReliability'=>$forecastReliability, 'observations'=>$observations, 'forecastChange'=>$forecastChange, 'forecastChangeV2'=>$forecastChangeV2, 'sunCloudWindow'=>$sunCloudWindow, 'previousRuns'=>$previousRuns, 'sourceFreshness'=>$freshness, 'explainability'=>$explainability, 'decisionWindows'=>$decisions, 'decisionTimeline'=>$decisionTimeline, 'activityProfiles'=>$activityProfiles, 'radarMotion'=>$motion, 'cellTracking'=>$cellTracking, 'nowcastFusion'=>$nowcastFusion, 'nowcastV2'=>$nowcastV2, 'nowcastV4'=>$nowcastV4, 'weatherConfidence'=>$weatherConfidence, 'confidenceV2'=>$confidenceV2, 'personalWeatherTwin'=>$personalTwin, 'aiWeatherModels'=>$aiWeatherModels, 'severeOutlook'=>$severeOutlook, 'convectiveRiskV3'=>$convectiveRiskV3, 'convectiveRiskV4'=>$convectiveRiskV3, 'trustScoreboard'=>$trustScoreboard, 'freshnessTrust'=>$freshnessTrust, 'predictiveVerification'=>$predictiveVerification, 'predictiveOpportunities'=>$predictiveOpportunities, 'retention'=>$retention, 'requestId'=>$requestId, 'pipelineHealth'=>meteonexa_pipeline_health_summary($pdo), 'lightning'=>$lightning, 'satellite'=>$satellite, 'official'=>$official, 'hyperlocal'=>$hyperlocal, 'privacy'=>['externalAiUsed'=>false, 'coordinatesPersistedByThisRequest'=>false, 'runSnapshotPersisted'=>true, 'observationEvidencePersisted'=>!empty($observations['available']), 'observationProviderCoordinatesRounded'=>true], 'engine'=>'20.1', 'generatedAt'=>gmdate('c')]);
