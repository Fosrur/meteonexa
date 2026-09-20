<?php
declare(strict_types=1);

/**
 * MeteoNexa — reliability-first severe weather monitor.
 *
 * Public/read-only for both guest and email sessions. It deliberately accepts no
 * account/device identifier, rounds coordinates to 3 decimals, and stores no
 * user/location profile. Upstream data may use bounded technical provider cache.
 * Stale weather is never allowed to create a new severe-event signal.
 */
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once dirname(__DIR__) . '/intelligence/engine_helpers.php';
require_once dirname(__DIR__) . '/intelligence/quality_helpers.php';
require_once dirname(__DIR__) . '/intelligence/severe_outlook_helpers.php';

assert_same_origin();
require_method('GET');
$config = load_config();
$pdo = meteonexa_db($config);
require_ip_rate_limit($pdo, 'weather_severe_public_ip', 240, 3600);
require_global_rate_limit($pdo, 'weather_severe_public_global', 3600, 3600);

$lat = query_float('lat', 999.0);
$lon = query_float('lon', 999.0);
if (!is_finite($lat) || !is_finite($lon) || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
    respond(['ok'=>false,'code'=>'INVALID_COORDINATES','message'=>'api.backend.invalid_coordinates'], 422);
}
$lat = round($lat, 3); $lon = round($lon, 3);

try {
    $weather = meteonexa_intelligence_weather($lat, $lon, false, 72);
    $weatherStale = !empty($weather['_meteonexaCache']['stale']);
    $lightning = meteonexa_intelligence_lightning($config, $lat, $lon);
    $profile = meteonexa_intelligence_profile([
        'minimumSeverity'=>'yellow',
        'events'=>[
            'rain'=>false,'storm'=>true,'hail'=>true,'wind'=>true,'snow'=>true,
            'ice'=>true,'fog'=>true,'heat'=>true,'aqi'=>false,'official'=>false,
        ],
    ]);
    $analysis = meteonexa_intelligence_analyze($weather, null, $profile, [
        'forecastWindowHours'=>72,
        'accuracy'=>['score'=>62],
        'lightning'=>$lightning,
    ]);
    $models=meteonexa_intelq_fetch_models($lat,$lon,false,72);
    $consensus=meteonexa_intelq_consensus($models);
    $outlook=meteonexa_severe_outlook($analysis,$consensus,$models,$lightning,72,55.0);
    $analysis=meteonexa_apply_severe_outlook($analysis,$outlook);
    $allowed=['hail','storm','snow','wind','ice','fog','heat'];
    $events=array_values(array_filter((array)($analysis['events']??[]),static fn($event):bool=>is_array($event)&&in_array((string)($event['type']??''),$allowed,true)));
    // Stale provider evidence may be shown as degraded diagnostics only; it must
    // never initiate a severe alert in Panoramica or a local notification.
    if($weatherStale)$events=[];
    respond([
        'ok'=>true,
        'monitor'=>[
            'authoritative'=>!$weatherStale,
            'degraded'=>$weatherStale,
            'events'=>$events,
            'generatedAt'=>gmdate('c'),
            'pollAfterSeconds'=>120,
            'lightningAvailable'=>!empty($lightning['available']),
            'severeOutlook'=>$outlook,
            'modelsAvailable'=>(int)($consensus['modelsAvailable']??0),
            'modelsExpected'=>(int)($consensus['modelsExpected']??count(meteonexa_intelq_model_definitions())),
        ],
        'privacy'=>[
            'accountDataUsed'=>false,'deviceIdUsed'=>false,
            'coordinatePrecisionDecimals'=>3,'coordinatesPersistedAsAccountData'=>false,
            'providerResponseCacheUsed'=>true,'externalAiUsed'=>false,
        ],
        'engine'=>'20.1',
    ]);
} catch(Throwable $error) {
    meteonexa_log_event('weather_severe_public_failed',$error);
    respond(['ok'=>false,'code'=>'SEVERE_MONITOR_UNAVAILABLE','message'=>'api.backend.service_unavailable'],503);
}
