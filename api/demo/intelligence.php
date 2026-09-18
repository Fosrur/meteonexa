<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once dirname(__DIR__) . '/intelligence/quality_helpers.php';
require_method('GET');
assert_same_origin();
$config = load_config();
$pdo = meteonexa_db($config);
require_ip_rate_limit($pdo, 'demo_intelligence_ip', 30, 3600);
$globalLimit = max(100, (int)($config['abuse_limits']['demo_intelligence_global_hour']??1500));
require_global_rate_limit($pdo, 'demo_intelligence_global', $globalLimit, 3600);
$lat = query_float('lat', 999);
$lon = query_float('lon', 999);
if (abs($lat) > 90||abs($lon) > 180)respond(['ok'=>false, 'code'=>'INVALID_COORDINATES', 'message'=>meteonexa_backend_text('api.backend.invalid_coordinates')], 422);
$location = clean_text($_GET['location']??'', 80, '');
$admin1 = clean_text($_GET['admin1']??'', 80, '');
$locale = strtolower(clean_text($_GET['lang']??'en', 8, 'en'));
if (!in_array($locale,['it', 'en', 'fr', 'es', 'de'], true))$locale = 'en';
// Guest demo stays account-free: no LLM, no device data and no exact-coordinate persistence.
$rLat = round($lat, 2);
$rLon = round($lon, 2);
$weather = meteonexa_intelligence_weather($rLat, $rLon, true, 72);
$air = meteonexa_intelligence_air($rLat, $rLon, true);
$models = meteonexa_intelq_fetch_models($rLat, $rLon, true, 72);
$consensus = meteonexa_intelq_canonical_consensus($models);
$satellite = meteonexa_intelligence_satellite($rLat, $rLon);
$official = meteonexa_intelq_meteoalarm_edr($config, $rLat, $rLon, $locale)??meteonexa_official_alerts($rLat, $rLon, $location, $admin1);
if (!isset($official['mode'])) {
    $official['mode'] = 'atom-text-fallback';
    $official['geospatial'] = false;
}
$official['generatedAt'] = gmdate('c');
$profile = meteonexa_intelligence_profile(['events'=>['official'=>true]]);
$analysis = meteonexa_intelligence_analyze($weather, $air, $profile,['accuracy'=>['score'=>62], 'radarMotion'=>['available'=>false], 'lightning'=>['available'=>false], 'satellite'=>$satellite, 'official'=>$official, 'forecastWindowHours'=>72]);
$previousRuns = meteonexa_intelq_previous_runs($rLat, $rLon, true);
$freshness = meteonexa_intelq_source_freshness($models,['available'=>false],['available'=>false], $satellite, $official,['available'=>false]);
$explainability = meteonexa_intelq_explainability($analysis, $consensus,['available'=>false],['available'=>false], $satellite, $official,['available'=>false],['available'=>false]);
$decisions = meteonexa_intelq_decision_windows($models);
respond(['ok'=>true, 'mode'=>'guest-demo', 'analysis'=>$analysis, 'consensus'=>$consensus, 'modelSkill'=>['available'=>false, 'verifiedSamples'=>0], 'confidenceCalibration'=>['available'=>false], 'calibrationProgress'=>['available'=>false, 'verified'=>0, 'queued'=>0, 'independentObservationAvailable'=>false, 'reason'=>'guest_demo_stateless'], 'forecastChange'=>['available'=>false], 'previousRuns'=>$previousRuns, 'sourceFreshness'=>$freshness, 'explainability'=>$explainability, 'decisionWindows'=>$decisions, 'cellTracking'=>['available'=>false, 'reason'=>'guest_no_personal_archive'], 'satellite'=>$satellite, 'official'=>$official, 'privacy'=>['externalAiUsed'=>false, 'accountDataUsed'=>false, 'coordinatesRoundedDecimals'=>2, 'coordinatesPersisted'=>false, 'runSnapshotPersisted'=>false, 'historyPersisted'=>false], 'limits'=>['remotePush'=>false, 'personalStations'=>false, 'aiCopilot'=>false], 'generatedAt'=>gmdate('c')]);
