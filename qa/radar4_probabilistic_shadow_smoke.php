<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/database/driver.php';
require_once $root . '/api/database/schema.php';
require_once $root . '/api/database/migrations.php';
require_once $root . '/api/intelligence/verification_helpers.php';
require_once $root . '/api/intelligence/radar4_probabilistic_helpers.php';

$ok = static function(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
};

$radar4 = [
    'available'=>true, 'mode'=>'shadow', 'authoritative'=>false, 'dominantCellId'=>'r4-main',
    'flow'=>['available'=>true, 'confidence'=>84, 'vectorSpread'=>.06, 'pairCount'=>3],
    'cells'=>[[
        'id'=>'r4-main', 'etaMinutes'=>24, 'trackConfidence'=>86, 'peak'=>.82, 'energy'=>12.4,
        'areaPixels'=>20, 'speedCellsMin'=>.14, 'growthDecayScore'=>38, 'stage'=>'growing', 'direction'=>'NE',
    ]],
];
$nowcastV4 = [
    'available'=>true,
    'arrivalDistribution'=>['available'=>true, 'mostLikelyRangeMinutes'=>[18,30]],
    'timeline'=>[
        ['minute'=>0,'rainProbabilityPct'=>16], ['minute'=>15,'rainProbabilityPct'=>45],
        ['minute'=>30,'rainProbabilityPct'=>82], ['minute'=>45,'rainProbabilityPct'=>68],
        ['minute'=>60,'rainProbabilityPct'=>40], ['minute'=>90,'rainProbabilityPct'=>18],
    ],
];
$consensus = ['modelsAvailable'=>5, 'primary'=>['weightedAgreementPct'=>74], 'hourly'=>[['time'=>gmdate('c')]]];
$lightning = ['available'=>true, 'degraded'=>false, 'recent30m'=>5, 'nearestKm'=>18, 'approaching'=>true];
$satellite = ['available'=>true, 'cloudAttenuationPct'=>76, 'support'=>.76];
$observations = ['available'=>true, 'sourceCount'=>3, 'qualityScore'=>88, 'rain'=>0, 'precipitation'=>0, 'storm'=>0, 'evidence'=>[]];
$official = ['relevant'=>[['severity'=>'orange','title'=>'Thunderstorm warning']], 'geospatial'=>true, 'mode'=>'edr-geospatial'];

$out = meteonexa_radar4_probabilistic_shadow($radar4, $nowcastV4, $consensus, $lightning, $satellite, $observations, $official, 480, 6.0);
$ok(!empty($out['available']) && ($out['mode'] ?? '') === 'shadow' && empty($out['authoritative']), 'P3.2 remains shadow-only');
$ok(($out['horizonMinutes'] ?? 0) === 120, 'P3.2 horizon is 120 minutes');
foreach (['rainStart','rainPeak','rainEnd'] as $field) {
    $d = (array)($out[$field] ?? []);
    $ok(!empty($d['available']), "$field distribution available");
    $ok((int)$d['p10Minutes'] <= (int)$d['p50Minutes'] && (int)$d['p50Minutes'] <= (int)$d['p90Minutes'], "$field quantiles ordered");
    $ok(count((array)$d['byMinutes']) === 25, "$field has 5-minute buckets through 120 minutes");
}
$sourceIds = array_column((array)($out['fusion']['sources'] ?? []), 'id');
foreach (['radar4','lightning','satellite','observations','models','official-warning-context'] as $id) $ok(in_array($id, $sourceIds, true), "fusion source $id");
$ok(!empty($out['policy']['officialWarningsRemainSeparateAuthority']) && !empty($out['policy']['authorityLockedToRadar3']), 'authority separation enforced');
$ok(($out['confidenceCalibration']['inputs']['terrainElevationM'] ?? null) === 480.0, 'terrain elevation context included');

$degraded = meteonexa_radar4_probabilistic_shadow($radar4, $nowcastV4, $consensus, ['available'=>false], ['available'=>false], ['available'=>false], [], 2600, 95.0);
$ok((int)$degraded['confidenceCalibration']['calibratedConfidence'] < (int)$out['confidenceCalibration']['calibratedConfidence'], 'distance/coverage/orography reduce confidence');

$issueTs = 2_000_000_000;
$transitionSeries = [
    ['ts'=>$issueTs - 600, 'wet'=>true, 'precipitation'=>.5, 'quality'=>90, 'source'=>'station'],
    ['ts'=>$issueTs - 60, 'wet'=>false, 'precipitation'=>0.0, 'quality'=>90, 'source'=>'station'],
    ['ts'=>$issueTs + 900, 'wet'=>true, 'precipitation'=>.7, 'quality'=>90, 'source'=>'station'],
];
$startTruth = meteonexa_radar4_truth_from_series($transitionSeries, 'rain_start', $issueTs);
$ok((int)($startTruth['observedTs'] ?? 0) === $issueTs + 900, 'pre-issue wet spell cannot verify a later rain-start forecast');
$currentWetTruth = meteonexa_radar4_truth_from_series([
    ['ts'=>$issueTs - 60, 'wet'=>true, 'precipitation'=>.4, 'quality'=>90, 'source'=>'station'],
    ['ts'=>$issueTs + 300, 'wet'=>true, 'precipitation'=>.6, 'quality'=>90, 'source'=>'station'],
], 'rain_start', $issueTs);
$ok((int)($currentWetTruth['observedTs'] ?? 0) === $issueTs, 'rain already active at issue time verifies start at t0');

if (extension_loaded('pdo_sqlite')) {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE app_metadata(meta_key TEXT PRIMARY KEY,meta_value TEXT NOT NULL,updated_at TEXT NOT NULL)');
    $pdo->exec("INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES('schema_version','29','2026-09-30T00:00:00Z')");
    $pdo->exec('CREATE TABLE observation_evidence(id INTEGER PRIMARY KEY AUTOINCREMENT,device_id TEXT NOT NULL,location_key TEXT NOT NULL,source_type TEXT NOT NULL,source_id TEXT NOT NULL DEFAULT \'\',observed_at TEXT NOT NULL,temperature REAL NULL,precipitation REAL NULL,wind_gust REAL NULL,rain_event REAL NULL,storm_event REAL NULL,snow_event REAL NULL,distance_km REAL NULL,quality_score INTEGER NOT NULL DEFAULT 0,payload_json TEXT NOT NULL DEFAULT \'{}\',created_at TEXT NOT NULL)');
    $version = meteonexa_run_sqlite_migrations($pdo, 29, 30);
    $ok($version === 30 && meteonexa_db_table_exists($pdo, 'radar4_event_predictions'), 'schema 30 Radar4 event ledger');
    $queued = meteonexa_radar4_event_prediction_queue($pdo, 'device-p32', '44.0000:9.0000', $out);
    $ok($queued === 4, 'start/peak/end/growth predictions queued');

    $now = time();
    $issued = $now - 4 * 3600;
    $targets = ['rain_start'=>20, 'rain_peak'=>45, 'rain_end'=>90, 'growth_decay'=>30];
    foreach ($targets as $kind=>$minute) {
        $p50 = $issued + $minute * 60;
        $st = $pdo->prepare('UPDATE radar4_event_predictions SET issued_at=:issued,p10_at=:p10,p50_at=:p50,p90_at=:p90 WHERE event_kind=:kind');
        $st->execute([':issued'=>gmdate('c',$issued), ':p10'=>gmdate('c',$p50-600), ':p50'=>gmdate('c',$p50), ':p90'=>gmdate('c',$p50+600), ':kind'=>$kind]);
    }
    $obsInsert = $pdo->prepare('INSERT INTO observation_evidence(device_id,location_key,source_type,source_id,observed_at,precipitation,rain_event,storm_event,distance_km,quality_score,payload_json,created_at) VALUES(:d,:l,\'station\',:sid,:at,:p,:r,0,4,90,\'{}\',:at)');
    $samples = [
        [5,.2,0], [20,.4,1], [30,1.0,1], [45,2.4,1], [70,.7,1], [90,0.0,0], [110,0.0,0],
    ];
    foreach ($samples as $idx=>$sample) {
        [$minute,$precip,$rain] = $sample;
        $obsInsert->execute([':d'=>'device-p32', ':l'=>'44.0000:9.0000', ':sid'=>'s'.$idx, ':at'=>gmdate('c',$issued+$minute*60), ':p'=>$precip, ':r'=>$rain]);
    }
    $verification = meteonexa_radar4_event_verification_update($pdo, 'device-p32', '44.0000:9.0000', ['available'=>false]);
    $ok((int)($verification['verified'] ?? 0) === 4, 'all P3.2 event kinds verified from independent observation series');
    $skill = (array)($verification['skill'] ?? []);
    foreach (array_keys($targets) as $kind) $ok((int)($skill['byEvent'][$kind]['samples'] ?? 0) === 1, "skill sample for $kind");
    $ok(empty($skill['promotionEligible']) && !empty($skill['authorityLockedToRadar3']), 'P3.2 cannot promote Radar4');
}

$summary = file_get_contents($root . '/api/intelligence/summary.php');
$ok(str_contains($summary, "'radar4ProbabilisticShadow'=>" . '$radar4ProbabilisticShadow'), 'summary exposes P3.2 shadow payload');

echo "Radar4 P3.2 probabilistic shadow smoke PASS\n";
