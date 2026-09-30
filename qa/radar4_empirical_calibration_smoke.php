<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/database/driver.php';
require_once $root . '/api/database/schema.php';
require_once $root . '/api/database/migrations.php';
require_once $root . '/api/intelligence/verification_helpers.php';
require_once $root . '/api/intelligence/radar4_probabilistic_helpers.php';
require_once $root . '/api/intelligence/radar4_calibration_helpers.php';

$ok = static function(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
};

$terrain = meteonexa_radar4_p33_terrain_profile_from_elevations([180, 520, 40, 410, 60, 620, 90, 350, 70], 5.0);
$ok(!empty($terrain['available']), 'P3.3 terrain profile is available from multi-point DEM samples');
$ok(($terrain['terrainClass'] ?? '') !== 'flat' && (float)($terrain['reliefM'] ?? 0) >= 500, 'P3.3 terrain relief/complexity is measured');
$ok(meteonexa_radar4_p33_area_key('44.1234:9.9876') === '44.00:10.00', 'P3.3 coarse multi-area key');
$ok(meteonexa_radar4_p33_season(strtotime('2026-07-15T12:00:00Z'), 44.0) === 'summer', 'P3.3 northern season segmentation');
$ok(meteonexa_radar4_p33_distance_band(12) === 'near' && meteonexa_radar4_p33_distance_band(55) === 'far', 'P3.3 radar distance bands');

$radar4 = [
    'available'=>true, 'mode'=>'shadow', 'dominantCellId'=>'r4-main',
    'flow'=>['available'=>true,'confidence'=>86,'vectorSpread'=>.05,'pairCount'=>3],
    'cells'=>[['id'=>'r4-main','etaMinutes'=>25,'trackConfidence'=>88,'peak'=>.8,'energy'=>12,'areaPixels'=>22,'speedCellsMin'=>.15,'growthDecayScore'=>42,'stage'=>'growing','direction'=>'NE']],
];
$nowcastV4 = ['available'=>true,'timeline'=>[['minute'=>0,'rainProbabilityPct'=>15],['minute'=>30,'rainProbabilityPct'=>82],['minute'=>60,'rainProbabilityPct'=>35]]];
$consensus = ['modelsAvailable'=>5,'primary'=>['weightedAgreementPct'=>76],'hourly'=>[['time'=>gmdate('c')]]];
$lightning = ['available'=>true,'recent30m'=>5,'nearestKm'=>15,'approaching'=>true];
$satellite = ['available'=>true,'cloudAttenuationPct'=>78];
$observations = ['available'=>true,'sourceCount'=>3,'qualityScore'=>90,'rain'=>0,'precipitation'=>0,'storm'=>0,'evidence'=>[]];
$shadow = meteonexa_radar4_probabilistic_shadow($radar4, $nowcastV4, $consensus, $lightning, $satellite, $observations, [], 180, 12, $terrain);
$ok(!empty($shadow['available']) && !empty($shadow['confidenceCalibration']['policy']['terrainProfileAvailable']), 'P3.3 richer terrain profile calibrates confidence');
$ok(empty($shadow['confidenceCalibration']['policy']['orographyUsesElevationProxyOnly']), 'P3.3 no longer uses elevation-only proxy when DEM profile exists');

if (extension_loaded('pdo_sqlite')) {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE app_metadata(meta_key TEXT PRIMARY KEY,meta_value TEXT NOT NULL,updated_at TEXT NOT NULL)');
    $pdo->exec("INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES('schema_version','29','2026-09-30T00:00:00Z')");
    $pdo->exec("CREATE TABLE observation_evidence(id INTEGER PRIMARY KEY AUTOINCREMENT,device_id TEXT NOT NULL,location_key TEXT NOT NULL,source_type TEXT NOT NULL,source_id TEXT NOT NULL DEFAULT '',observed_at TEXT NOT NULL,temperature REAL NULL,precipitation REAL NULL,wind_gust REAL NULL,rain_event REAL NULL,storm_event REAL NULL,snow_event REAL NULL,distance_km REAL NULL,quality_score INTEGER NOT NULL DEFAULT 0,payload_json TEXT NOT NULL DEFAULT '{}',created_at TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE radar_eta_predictions(id INTEGER PRIMARY KEY AUTOINCREMENT,device_id TEXT NOT NULL,location_key TEXT NOT NULL,prediction_key TEXT NOT NULL,issued_at TEXT NOT NULL,predicted_at TEXT NOT NULL,eta_minutes INTEGER NOT NULL,tolerance_minutes INTEGER NOT NULL DEFAULT 10,confidence INTEGER NOT NULL DEFAULT 0,status TEXT NOT NULL DEFAULT 'pending',verified_at TEXT NOT NULL DEFAULT '',observed_at TEXT NOT NULL DEFAULT '',error_minutes REAL NULL,absolute_error_minutes REAL NULL,algorithm TEXT NOT NULL DEFAULT 'radar-v2',ground_truth_source TEXT NOT NULL DEFAULT '',ground_truth_quality INTEGER NOT NULL DEFAULT 0,ground_truth_json TEXT NOT NULL DEFAULT '',verification_method TEXT NOT NULL DEFAULT '',created_at TEXT NOT NULL,UNIQUE(device_id,location_key,prediction_key))");
    $version = meteonexa_run_sqlite_migrations($pdo, 29, 31);
    $ok($version === 31, 'schema 31 migration completes');
    foreach (['area_key','distance_band','coverage_band','season','weather_regime','terrain_class','event_observed','calibrated_probability','probability_brier','calibration_context_json'] as $column) {
        $ok(meteonexa_db_column_exists($pdo, 'radar4_event_predictions', $column), "schema 31 event column $column");
    }
    foreach (['area_key','distance_band','coverage_band','season','weather_regime','terrain_class'] as $column) {
        $ok(meteonexa_db_column_exists($pdo, 'radar_eta_predictions', $column), "schema 31 ETA column $column");
    }

    $emptyReport = meteonexa_radar4_p33_calibration_report($pdo, 'device-p33');
    $shadow = meteonexa_radar4_p33_apply_empirical_calibration($shadow, $emptyReport);
    $context = meteonexa_radar4_p33_context('44.1234:9.9876', $shadow, $lightning, $satellite, $observations, $terrain, 12, strtotime('2026-07-15T12:00:00Z'));
    $ok(($context['weatherRegime'] ?? '') === 'convective' && ($context['terrainClass'] ?? '') === ($terrain['terrainClass'] ?? ''), 'P3.3 calibration context classifies regime and terrain');
    $queued = meteonexa_radar4_event_prediction_queue($pdo, 'device-p33', '44.1234:9.9876', $shadow, $context);
    $ok($queued === 4, 'P3.3 queues start/peak/end/growth with calibration context');
    $stored = $pdo->query("SELECT area_key,distance_band,season,weather_regime,terrain_class,calibrated_probability FROM radar4_event_predictions WHERE event_kind='rain_start' LIMIT 1")->fetch();
    $ok(($stored['area_key'] ?? '') === '44.00:10.00' && ($stored['distance_band'] ?? '') === 'near', 'P3.3 persists multi-area/distance context');

    // Convert queued rows into mature past predictions and feed a high-quality,
    // continuously dry independent observation window. Rain events become true
    // binary negatives; missing data would remain unverified instead.
    $issued = time() - 4 * 3600;
    $pdo->exec("UPDATE radar4_event_predictions SET issued_at='" . gmdate('c',$issued) . "',p10_at='" . gmdate('c',$issued+600) . "',p50_at='" . gmdate('c',$issued+1800) . "',p90_at='" . gmdate('c',$issued+3600) . "'");
    $obs = $pdo->prepare("INSERT INTO observation_evidence(device_id,location_key,source_type,source_id,observed_at,precipitation,rain_event,storm_event,distance_km,quality_score,payload_json,created_at) VALUES('device-p33','44.1234:9.9876','station',:sid,:at,0,0,0,3,92,'{}',:at)");
    foreach ([0,30,60,100,130] as $i=>$minute) $obs->execute([':sid'=>'dry'.$i, ':at'=>gmdate('c',$issued+$minute*60)]);
    $verification = meteonexa_radar4_event_verification_update($pdo, 'device-p33', '44.1234:9.9876', [], $context);
    $negativeCount = (int)$pdo->query("SELECT COUNT(*) FROM radar4_event_predictions WHERE event_kind IN ('rain_start','rain_peak','rain_end') AND status='verified' AND event_observed=0 AND probability_brier IS NOT NULL")->fetchColumn();
    $ok($negativeCount === 3, 'P3.3 verifies observed dry windows as binary negatives with Brier samples');

    // Seed a mature synthetic calibration set to exercise segmentation, coverage,
    // bias, reliability and the separate future promotion-study guardrail.
    $insertEvent = $pdo->prepare("INSERT INTO radar4_event_predictions(device_id,location_key,prediction_key,event_kind,issued_at,p10_at,p50_at,p90_at,event_probability,confidence,predicted_value,status,verified_at,observed_at,observed_value,error_minutes,absolute_error_minutes,absolute_error_value,within_interval,ground_truth_source,ground_truth_quality,ground_truth_json,verification_method,area_key,distance_band,coverage_band,season,weather_regime,terrain_class,terrain_relief_m,terrain_gradient_pct,event_observed,calibrated_probability,probability_brier,calibration_context_json,created_at) VALUES('device-mature',:loc,:key,:kind,:at,:at,:at,:at,:prob,80,:pv,'verified',:at,:at,:ov,:err,:abs,:absv,:within,'station',90,'{}','synthetic-qa',:area,:distance,:coverage,:season,:regime,:terrain,300,5,:observed,:calibrated,:brier,'{}',:at)");
    $areas = ['44.00:9.00','45.00:10.00','46.00:11.00'];
    $seasons = ['summer','autumn'];
    $regimes = ['convective','stratiform','mixed'];
    foreach (['rain_start','rain_peak','rain_end'] as $kind) {
        for ($i=0; $i<60; $i++) {
            $observed = $i % 3 === 0 ? 0 : 1;
            $prob = $i % 2 ? 70.0 : 50.0;
            $err = $observed ? (($i % 9) - 4) : null;
            $insertEvent->execute([
                ':loc'=>$areas[$i%3], ':key'=>'e-'.$kind.'-'.$i, ':kind'=>$kind, ':at'=>'2026-09-01T00:00:00Z', ':prob'=>$prob, ':pv'=>null, ':ov'=>$observed,
                ':err'=>$err, ':abs'=>$err === null ? null : abs($err), ':absv'=>null, ':within'=>$observed ? ($i%5 ? 1 : 0) : 0,
                ':area'=>$areas[$i%3], ':distance'=>['near','mid','far'][$i%3], ':coverage'=>['full','good','partial'][$i%3], ':season'=>$seasons[$i%2], ':regime'=>$regimes[$i%3], ':terrain'=>['flat','rolling','complex'][$i%3],
                ':observed'=>$observed, ':calibrated'=>$prob, ':brier'=>(($prob/100)-$observed)**2,
            ]);
        }
    }
    for ($i=0; $i<30; $i++) {
        $insertEvent->execute([
            ':loc'=>$areas[$i%3], ':key'=>'g-'.$i, ':kind'=>'growth_decay', ':at'=>'2026-09-01T00:00:00Z', ':prob'=>100, ':pv'=>20, ':ov'=>18,
            ':err'=>null, ':abs'=>null, ':absv'=>2, ':within'=>null, ':area'=>$areas[$i%3], ':distance'=>'near', ':coverage'=>'full', ':season'=>$seasons[$i%2], ':regime'=>$regimes[$i%3], ':terrain'=>'rolling', ':observed'=>null, ':calibrated'=>100, ':brier'=>null,
        ]);
    }
    $insertEta = $pdo->prepare("INSERT INTO radar_eta_predictions(device_id,location_key,prediction_key,issued_at,predicted_at,eta_minutes,tolerance_minutes,confidence,status,verified_at,observed_at,error_minutes,absolute_error_minutes,algorithm,ground_truth_source,ground_truth_quality,ground_truth_json,verification_method,area_key,distance_band,coverage_band,season,weather_regime,terrain_class,created_at) VALUES('device-mature',:loc,:key,:at,:at,20,10,80,'verified',:at,:at,:err,:abs,:alg,'station',90,'{}','qa',:area,:distance,:coverage,:season,:regime,:terrain,:at)");
    foreach (['radar-v3','radar-v4'] as $alg) {
        for ($i=0; $i<60; $i++) {
            $err = $alg === 'radar-v4' ? 4 + ($i%3) : 6 + ($i%4);
            $insertEta->execute([':loc'=>$areas[$i%3], ':key'=>'eta-'.$alg.'-'.$i, ':at'=>'2026-09-01T00:00:00Z', ':err'=>$err, ':abs'=>abs($err), ':alg'=>$alg, ':area'=>$areas[$i%3], ':distance'=>['near','mid','far'][$i%3], ':coverage'=>['full','good','partial'][$i%3], ':season'=>$seasons[$i%2], ':regime'=>$regimes[$i%3], ':terrain'=>['flat','rolling','complex'][$i%3]]);
        }
    }
    $report = meteonexa_radar4_p33_calibration_report($pdo, 'device-mature');
    $ok(!empty($report['datasetMature']) && ($report['areas'] ?? 0) >= 3 && ($report['seasons'] ?? 0) >= 2 && ($report['weatherRegimes'] ?? 0) >= 3, 'P3.3 maturity requires multi-area/multi-season/multi-regime evidence');
    $ok(is_numeric($report['events']['rain_start']['timing']['biasP50Minutes'] ?? null) && is_numeric($report['events']['rain_start']['timing']['intervalCoveragePct'] ?? null), 'P3.3 reports P50 bias and P10-P90 coverage');
    $ok(is_numeric($report['events']['rain_start']['binary']['brierScore'] ?? null) && !empty($report['events']['rain_start']['binary']['bins']), 'P3.3 reports Brier/reliability bins');
    $ok(isset($report['segments']['distanceBand']['near']) && isset($report['segments']['season']['summer']), 'P3.3 segments reliability by distance and season');
    $ok(isset($report['eta']['radar-v3']) && isset($report['eta']['radar-v4']), 'P3.3 compares Radar3/Radar4 ETA skill');
    $ok(!empty($report['futurePromotionStudy']['eligible']) && empty($report['futurePromotionStudy']['numericThresholdsFinalized']) && empty($report['futurePromotionStudy']['activationAllowed']), 'P3.3 maturity only opens a separate promotion study; authority remains locked');

    $cal = meteonexa_radar4_p33_empirical_probability($report, 'rain_start', 70);
    $ok(!empty($cal['available']) && is_numeric($cal['calibratedProbabilityPct']), 'P3.3 empirical probability calibration activates only with enough samples');
}

$summary = file_get_contents($root . '/api/intelligence/summary.php');
$ok(str_contains($summary, "'radar4Calibration'=>" . '$radar4CalibrationReport'), 'summary exposes P3.3 calibration report');
$ok(str_contains($summary, 'meteonexa_radar4_p33_terrain_profile'), 'summary uses P3.3 terrain profile');

echo "Radar4 P3.3 empirical calibration smoke PASS\n";
