<?php
declare(strict_types=1);

/**
 * MeteoNexa — lightweight forecast reliability fusion.
 *
 * This endpoint is intentionally account-independent and read-only. It exists
 * so that Panoramica can use the same deterministic multi-model evidence for
 * guests and authenticated users without exposing five upstream model calls to
 * the browser or coupling the home page to the heavier Intelligence workflow.
 *
 * Reliability rules:
 * - provider responses are reused through the existing bounded server cache;
 * - fresh model responses are preferred;
 * - stale provider-cache fallbacks are used only when fewer than three fresh
 *   models are available and the response is explicitly marked degraded;
 * - the client is forbidden from using a degraded fusion to suppress/raise a
 *   base Open-Meteo condition (15-minute local nowcast may still refine it).
 *
 * Privacy: no account/session/device identifier is requested and no selected
 * location or forecast is persisted as user/account state by this endpoint.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once dirname(__DIR__) . '/intelligence/quality_helpers.php';

assert_same_origin();
require_method('GET');

$config = load_config();
$pdo = meteonexa_db($config);
require_ip_rate_limit($pdo, 'weather_fusion_ip', 240, 3600);
require_global_rate_limit($pdo, 'weather_fusion_global', 2400, 3600);

$lat = query_float('lat', 999.0);
$lon = query_float('lon', 999.0);
if (!is_finite($lat) || !is_finite($lon) || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
    respond(['ok'=>false,'code'=>'INVALID_COORDINATES','message'=>'api.backend.invalid_coordinates'], 422);
}

// Three decimals (~110 m latitude) are more than enough for a home forecast,
// reduce needless cache fragmentation and avoid forwarding browser precision
// that does not improve the model grid result.
$lat = round($lat, 3);
$lon = round($lon, 3);

try {
    $modelDefinitions = meteonexa_intelq_model_definitions();
    $modelsExpected = count($modelDefinitions);
    $models = meteonexa_intelq_fetch_models($lat, $lon, false, 30);

    $freshModels = array_filter($models, static fn(array $model): bool =>
        !empty($model['available']) && empty($model['stale'])
    );
    $availableModels = array_filter($models, static fn(array $model): bool => !empty($model['available']));

    // Canonical consensus is shared with Intelligence/Advanced. It prefers at
    // least three fresh models and otherwise exposes a marked degraded fallback.
    $consensus = meteonexa_intelq_canonical_consensus($models);
    $useFreshConsensus = ($consensus['sourceMode'] ?? '') === 'fresh_consensus';

    $rows = [];
    foreach (array_slice((array)($consensus['hourly'] ?? []), 0, 30) as $row) {
        $rows[] = [
            'time'=>(string)($row['time'] ?? ''),
            'available'=>(int)($row['available'] ?? 0),
            'rainVotes'=>(int)($row['rainVotes'] ?? 0),
            'stormVotes'=>(int)($row['stormVotes'] ?? 0),
            'snowVotes'=>(int)($row['snowVotes'] ?? 0),
            'precipitationVotes'=>(int)($row['precipitationVotes'] ?? 0),
            'precipitationMean'=>is_numeric($row['precipitationMean'] ?? null) ? (float)$row['precipitationMean'] : null,
            'precipitationMedian'=>is_numeric($row['precipitationMedian'] ?? null) ? (float)$row['precipitationMedian'] : null,
            'precipitationWetMean'=>is_numeric($row['precipitationWetMean'] ?? null) ? (float)$row['precipitationWetMean'] : null,
            'precipitationMax'=>is_numeric($row['precipitationMax'] ?? null) ? (float)$row['precipitationMax'] : null,
            'temperatureSpread'=>is_numeric($row['temperatureSpread'] ?? null) ? (float)$row['temperatureSpread'] : null,
        ];
    }

    $modelEvidence = [];
    foreach ($models as $id=>$model) {
        if (empty($model['available'])) continue;
        $modelEvidence[] = [
            'id'=>(string)$id,
            'label'=>(string)($model['label'] ?? strtoupper((string)$id)),
            'stale'=>!empty($model['stale']),
            'retrievedAt'=>$model['retrievedAt'] ?? null,
            'ageMinutes'=>is_numeric($model['ageMinutes'] ?? null) ? (int)$model['ageMinutes'] : null,
            'rows'=>array_slice((array)($model['rows'] ?? []), 0, 30),
        ];
    }

    $sources = [];
    foreach ($models as $id=>$model) {
        $sources[] = [
            'id'=>(string)$id,
            'label'=>(string)($model['label'] ?? strtoupper((string)$id)),
            'available'=>!empty($model['available']),
            'stale'=>!empty($model['stale']),
            'ageMinutes'=>is_numeric($model['ageMinutes'] ?? null) ? (int)$model['ageMinutes'] : null,
        ];
    }

    $freshCount = count($freshModels);
    $availableCount = count($availableModels);
    $degraded = !$useFreshConsensus;
    respond([
        'ok'=>true,
        'fusion'=>[
            'available'=>$availableCount >= 2,
            'degraded'=>$degraded,
            'mode'=>(string)($consensus['sourceMode'] ?? ($useFreshConsensus ? 'fresh_consensus' : ($availableCount >= 2 ? 'stale_fallback' : 'insufficient_models'))),
            'modelsExpected'=>$modelsExpected,
            'modelsAvailable'=>$availableCount,
            'freshModelsAvailable'=>$freshCount,
            'generatedAt'=>gmdate('c'),
            'hourly'=>$rows,
            'consensus'=>[
                'available'=>!empty($consensus['available']),
                'modelsExpected'=>(int)($consensus['modelsExpected'] ?? $modelsExpected),
                'modelsAvailable'=>(int)($consensus['modelsAvailable'] ?? $availableCount),
                'freshModelsAvailable'=>(int)($consensus['freshModelsAvailable'] ?? $freshCount),
                'primary'=>$consensus['primary'] ?? null,
                'rainWindows'=>$consensus['rainWindows'] ?? [],
                'stormWindows'=>$consensus['stormWindows'] ?? [],
            ],
            'sources'=>$sources,
            'models'=>$modelEvidence,
        ],
        'privacy'=>[
            'accountDataUsed'=>false,
            'deviceIdUsed'=>false,
            'coordinatesPersistedAsAccountData'=>false,
            'providerResponseCacheUsed'=>true,
            'providerCacheStaleWindowSeconds'=>21600,
            'externalAiUsed'=>false,
            'coordinatePrecisionDecimals'=>3,
        ],
        'engine'=>'20.1',
    ]);
} catch (Throwable $error) {
    // Panoramica is fail-soft: the browser will keep the base live forecast and
    // local 15-minute evidence. Do not leak upstream/server internals.
    meteonexa_log_event('weather_fusion_failed', $error);
    respond(['ok'=>false,'code'=>'FUSION_UNAVAILABLE','message'=>'api.backend.service_unavailable'], 503);
}
