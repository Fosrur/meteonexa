<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/database/driver.php';
require_once $root . '/api/database/schema.php';
require_once $root . '/api/database/migrations.php';
require_once $root . '/api/intelligence/verification_helpers.php';
require_once $root . '/api/intelligence/radar4_probabilistic_helpers.php';
require_once $root . '/api/intelligence/radar4_calibration_helpers.php';
require_once $root . '/api/intelligence/radar4_promotion_study_helpers.php';

$ok = static function(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
};

if (extension_loaded('pdo_sqlite')) {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE app_metadata(meta_key TEXT PRIMARY KEY,meta_value TEXT NOT NULL,updated_at TEXT NOT NULL)');
    $pdo->exec("INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES('schema_version','29','2026-09-30T00:00:00Z')");
    $pdo->exec("CREATE TABLE radar_eta_predictions(id INTEGER PRIMARY KEY AUTOINCREMENT,device_id TEXT NOT NULL,location_key TEXT NOT NULL,prediction_key TEXT NOT NULL,issued_at TEXT NOT NULL,predicted_at TEXT NOT NULL,eta_minutes INTEGER NOT NULL,tolerance_minutes INTEGER NOT NULL DEFAULT 10,confidence INTEGER NOT NULL DEFAULT 0,status TEXT NOT NULL DEFAULT 'pending',verified_at TEXT NOT NULL DEFAULT '',observed_at TEXT NOT NULL DEFAULT '',error_minutes REAL NULL,absolute_error_minutes REAL NULL,algorithm TEXT NOT NULL DEFAULT 'radar-v2',ground_truth_source TEXT NOT NULL DEFAULT '',ground_truth_quality INTEGER NOT NULL DEFAULT 0,ground_truth_json TEXT NOT NULL DEFAULT '',verification_method TEXT NOT NULL DEFAULT '',created_at TEXT NOT NULL,UNIQUE(device_id,location_key,prediction_key))");
    $version = meteonexa_run_sqlite_migrations($pdo, 29, 31);
    $ok($version === 31, 'P3.4 uses the schema 31 verification ledgers without a new migration');

    $blocked = meteonexa_radar4_p34_promotion_study($pdo, 'device-p34', ['datasetMature'=>false]);
    $ok(empty($blocked['available']) && empty($blocked['numericThresholdsFinalized']) && empty($blocked['activationAllowed']) && !empty($blocked['authorityLockedToRadar3']), 'P3.4 is blocked before P3.3 live maturity');

    $event = $pdo->prepare("INSERT INTO radar4_event_predictions(device_id,location_key,prediction_key,event_kind,issued_at,p10_at,p50_at,p90_at,event_probability,confidence,predicted_value,status,verified_at,observed_at,observed_value,error_minutes,absolute_error_minutes,absolute_error_value,within_interval,ground_truth_source,ground_truth_quality,ground_truth_json,verification_method,area_key,distance_band,coverage_band,season,weather_regime,terrain_class,terrain_relief_m,terrain_gradient_pct,event_observed,calibrated_probability,probability_brier,calibration_context_json,created_at) VALUES('device-p34',:loc,:key,:kind,:at,:at,:at,:at,:prob,85,:pv,'verified',:at,:at,:ov,:err,:abs,:absv,:within,'station',95,'{}','synthetic-p34',:area,:distance,:coverage,:season,:regime,:terrain,250,4,:observed,:calibrated,:brier,'{}',:at)");
    $areas = ['44.00:9.00','45.00:10.00','46.00:11.00'];
    $seasons = ['summer','autumn'];
    $regimes = ['convective','stratiform','mixed'];
    $probs = [20.0, 50.0, 80.0];
    foreach (['rain_start','rain_peak','rain_end'] as $kindIndex=>$kind) {
        for ($i=0; $i<180; $i++) {
            $key = 'event-'.$kind.'-'.$i;
            $prob = $probs[$i % 3];
            $score = hexdec(substr(hash('sha256', 'obs|'.$key), 0, 6)) % 100;
            $observed = $score < (int)$prob ? 1 : 0;
            $signedError = $observed ? (($i + $kindIndex) % 7) - 3 : null;
            $within = $observed ? (($i % 7) === 0 ? 0 : 1) : 0;
            $brier = (($prob / 100) - $observed) ** 2;
            $event->execute([
                ':loc'=>$areas[$i%3], ':key'=>$key, ':kind'=>$kind, ':at'=>'2026-09-15T12:00:00Z', ':prob'=>$prob,
                ':pv'=>null, ':ov'=>$observed, ':err'=>$signedError, ':abs'=>$signedError === null ? null : abs($signedError),
                ':absv'=>null, ':within'=>$within, ':area'=>$areas[$i%3], ':distance'=>['near','mid','far'][$i%3],
                ':coverage'=>['full','good','partial'][$i%3], ':season'=>$seasons[$i%2], ':regime'=>$regimes[$i%3],
                ':terrain'=>['flat','rolling','complex'][$i%3], ':observed'=>$observed, ':calibrated'=>$prob, ':brier'=>$brier,
            ]);
        }
    }
    for ($i=0; $i<60; $i++) {
        $event->execute([
            ':loc'=>$areas[$i%3], ':key'=>'growth-'.$i, ':kind'=>'growth_decay', ':at'=>'2026-09-15T12:00:00Z', ':prob'=>100,
            ':pv'=>20, ':ov'=>18, ':err'=>null, ':abs'=>null, ':absv'=>2, ':within'=>null, ':area'=>$areas[$i%3], ':distance'=>'near',
            ':coverage'=>'full', ':season'=>$seasons[$i%2], ':regime'=>$regimes[$i%3], ':terrain'=>'rolling', ':observed'=>null,
            ':calibrated'=>100, ':brier'=>null,
        ]);
    }

    $eta = $pdo->prepare("INSERT INTO radar_eta_predictions(device_id,location_key,prediction_key,issued_at,predicted_at,eta_minutes,tolerance_minutes,confidence,status,verified_at,observed_at,error_minutes,absolute_error_minutes,algorithm,ground_truth_source,ground_truth_quality,ground_truth_json,verification_method,area_key,distance_band,coverage_band,season,weather_regime,terrain_class,created_at) VALUES('device-p34',:loc,:key,:at,:at,20,10,88,'verified',:at,:at,:err,:abs,:alg,'station',95,'{}','synthetic-p34',:area,:distance,:coverage,:season,:regime,:terrain,:at)");
    foreach (['radar-v3','radar-v4'] as $alg) {
        for ($i=0; $i<180; $i++) {
            $err = $alg === 'radar-v4' ? 4 + ($i % 3) : 9 + ($i % 3);
            $eta->execute([
                ':loc'=>$areas[$i%3], ':key'=>'eta-'.$alg.'-'.$i, ':at'=>'2026-09-15T12:00:00Z', ':err'=>$err, ':abs'=>abs($err), ':alg'=>$alg,
                ':area'=>$areas[$i%3], ':distance'=>['near','mid','far'][$i%3], ':coverage'=>['full','good','partial'][$i%3],
                ':season'=>$seasons[$i%2], ':regime'=>$regimes[$i%3], ':terrain'=>['flat','rolling','complex'][$i%3],
            ]);
        }
    }

    $p33 = meteonexa_radar4_p33_calibration_report($pdo, 'device-p34');
    $ok(!empty($p33['datasetMature']), 'P3.4 synthetic fixture satisfies the P3.3 live-maturity gate');
    $study = meteonexa_radar4_p34_promotion_study($pdo, 'device-p34', $p33);
    $ok(!empty($study['available']) && strlen((string)($study['datasetFingerprint'] ?? '')) === 64, 'P3.4 freezes the evaluated ledger snapshot with SHA-256 fingerprint');
    $ok(($study['split']['method'] ?? '') === 'deterministic-sha256-70-30-v1' && !empty($study['numericThresholdsFinalized']), 'P3.4 derives thresholds only from deterministic train partition');
    $ok(!empty($study['holdoutValidated']) && !empty($study['stabilityValidated']), 'P3.4 validates holdout and segment stability');
    $ok(!empty($study['manualCanaryReviewEligible']) && ($study['releaseReview']['decision'] ?? '') === 'eligible-for-separate-manual-canary-review', 'P3.4 can open a separate manual canary review');
    $ok(empty($study['activationAllowed']) && !empty($study['authorityLockedToRadar3']) && !empty($study['productionDecisionsUnaffected']), 'P3.4 never changes production authority');

    $studyAgain = meteonexa_radar4_p34_promotion_study($pdo, 'device-p34', $p33);
    $ok(($studyAgain['datasetFingerprint'] ?? '') === ($study['datasetFingerprint'] ?? '') && ($studyAgain['split'] ?? []) === ($study['split'] ?? []), 'P3.4 dataset fingerprint and split are reproducible');
}

$summary = file_get_contents($root . '/api/intelligence/summary.php');
$ok(str_contains($summary, "require_once __DIR__ . '/radar4_promotion_study_helpers.php';"), 'summary loads P3.4 promotion-study helper');
$ok(str_contains($summary, "'radar4PromotionStudy'=>" . '$radar4PromotionStudy'), 'summary exposes P3.4 study without changing authority');

echo "Radar4 P3.4 promotion study smoke PASS\n";
