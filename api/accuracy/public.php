<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once dirname(__DIR__) . '/intelligence/verification_helpers.php';
assert_same_origin();
require_method('GET');
$config = load_config();
$pdo = meteonexa_db($config);
require_ip_rate_limit($pdo, 'public_local_accuracy', 120, 3600);
function meteonexa_public_accuracy_location_key(mixed $value) : string {
    $key = clean_text($value, 80);
    if (preg_match('/^(-?\d{1,3}(?:\.\d{1,6})?):(-?\d{1,3}(?:\.\d{1,6})?)$/', $key, $m)!==1||abs((float)$m[1]) > 90||abs((float)$m[2]) > 180) {
        respond(['ok'=>false, 'code'=>'ACCURACY_INVALID', 'message'=>'api.accuracy.invalid'], 422);
    }
    return sprintf('%.4f:%.4f', (float)$m[1], (float)$m[2]);
}
function meteonexa_public_accuracy_allowed(int $samples, int $contributors, int $minimumSamples = 20, int $minimumContributors = 3) : bool {
    return $samples>=$minimumSamples&&$contributors>=$minimumContributors;
}
function meteonexa_public_accuracy_binary(array $rows) : array {
    $tp = $fp = $fn = $tn = 0;
    foreach ($rows as $r) {
        $pred = (float)($r['predicted_value']??0)>=0.1;
        $obs = (float)($r['observed_value']??0)>=0.1;
        if ($pred&&$obs)$tp++;
        elseif ($pred&&!$obs)$fp++;
        elseif (!$pred&&$obs)$fn++;
        else $tn++;
    }
    $precision =($tp + $fp) > 0 ? 100 * $tp /($tp + $fp) : null;
    $recall =($tp + $fn) > 0 ? 100 * $tp /($tp + $fn) : null;
    return['samples'=>$tp + $fp + $fn + $tn, 'precisionPct'=>$precision===null ? null : round($precision, 1), 'recallPct'=>$recall===null ? null : round($recall, 1)];
}
$locationKey = meteonexa_public_accuracy_location_key($_GET['locationKey']??'');
$days = 60;
$cutoff = gmdate('c', time() - $days * 86400);
$minimumSamples = 20;
$minimumContributors = 3;
$out =['ok'=>true, 'version'=>'1.0', 'windowDays'=>$days, 'minimumSamples'=>$minimumSamples, 'minimumContributors'=>$minimumContributors, 'status'=>'learning', 'metrics'=>[], 'verifiedChecks'=>0, 'period'=>['first'=>null, 'last'=>null], 'generatedAt'=>gmdate('c')];
try {
    $st = $pdo->prepare("SELECT COUNT(*) samples,COUNT(DISTINCT device_id) contributors,AVG(ABS(error_value)) mae,MIN(verified_at) first_at,MAX(verified_at) last_at FROM model_skill_samples WHERE location_key=:l AND metric='temperature' AND verified_at<>'' AND verified_at>=:c AND error_value IS NOT NULL");
    $st->execute([':l'=>$locationKey, ':c'=>$cutoff]);
    $r = $st->fetch() ? :[];
    $n = (int)($r['samples']??0);
    $contributors = (int)($r['contributors']??0);
    if (meteonexa_public_accuracy_allowed($n, $contributors, $minimumSamples, $minimumContributors)) {
        $out['metrics']['temperature'] =['samples'=>$n, 'maeC'=>round((float)$r['mae'], 2)];
        $out['verifiedChecks']+=$n;
        $out['period']['first'] = $r['first_at'] ? : $out['period']['first'];
        $out['period']['last'] = $r['last_at'] ? : $out['period']['last'];
    }
    $st = $pdo->prepare("SELECT device_id,predicted_value,observed_value,verified_at FROM model_skill_samples WHERE location_key=:l AND metric='rain' AND verified_at<>'' AND verified_at>=:c AND predicted_value IS NOT NULL AND observed_value IS NOT NULL ORDER BY verified_at DESC LIMIT 5000");
    $st->execute([':l'=>$locationKey, ':c'=>$cutoff]);
    $rows = $st->fetchAll();
    $contributors = count(array_unique(array_map(static fn($x)=>(string)$x['device_id'], $rows)));
    $binary = meteonexa_public_accuracy_binary($rows);
    if (meteonexa_public_accuracy_allowed((int)$binary['samples'], $contributors, $minimumSamples, $minimumContributors)) {
        $out['metrics']['rain'] = $binary;
        $out['verifiedChecks']+=(int)$binary['samples'];
    }
    $st = $pdo->prepare("SELECT device_id,outcome,confidence,observed,verified_at FROM predictive_alert_opportunities WHERE location_key=:l AND event_type='storm' AND status='verified' AND verified_at>=:c ORDER BY verified_at DESC LIMIT 5000");
    $st->execute([':l'=>$locationKey, ':c'=>$cutoff]);
    $rows = $st->fetchAll();
    $contributors = count(array_unique(array_map(static fn($x)=>(string)$x['device_id'], $rows)));
    $storm = meteonexa_binary_metrics($rows);
    if (meteonexa_public_accuracy_allowed((int)($storm['samples']??0), $contributors, $minimumSamples, $minimumContributors)) {
        $out['metrics']['storm'] =['samples'=>(int)$storm['samples'], 'precisionPct'=>$storm['precisionPct'], 'recallPct'=>$storm['recallPct'], 'falseAlarmRatioPct'=>$storm['falseAlarmRatioPct']];
        $out['verifiedChecks']+=(int)$storm['samples'];
    }
    $algorithmColumn = meteonexa_db_column_exists($pdo, 'radar_eta_predictions', 'algorithm');
    $verificationColumn = meteonexa_db_column_exists($pdo, 'radar_eta_predictions', 'verification_method');
    $sql = "SELECT device_id," .($algorithmColumn ? 'algorithm,' : "'radar-v2' algorithm,") . "absolute_error_minutes,tolerance_minutes,verified_at FROM radar_eta_predictions WHERE location_key=:l AND status='verified' AND absolute_error_minutes IS NOT NULL AND verified_at>=:c" .($verificationColumn ? " AND verification_method LIKE 'independent%'" : '') . " ORDER BY verified_at DESC LIMIT 5000";
    $st = $pdo->prepare($sql);
    $st->execute([':l'=>$locationKey, ':c'=>$cutoff]);
    $groups =[];
    foreach ($st->fetchAll() as $r)$groups[(string)($r['algorithm'] ? : 'radar-v2')][] = $r;
    $published =[];
    foreach ($groups as $algorithm=>$rows) {
        $contributors = count(array_unique(array_map(static fn($x)=>(string)$x['device_id'], $rows)));
        $n = count($rows);
        if (!meteonexa_public_accuracy_allowed($n, $contributors, $minimumSamples, $minimumContributors))continue;
        $errors = array_map(static fn($x)=>(float)$x['absolute_error_minutes'], $rows);
        $within = count(array_filter($rows, static fn($x)=>(float)$x['absolute_error_minutes']<=(float)$x['tolerance_minutes']));
        $published[$algorithm] =['samples'=>$n, 'maeMinutes'=>round(array_sum($errors) / $n, 1), 'withinTolerancePct'=>round(100 * $within / $n, 1)];
    }
    if ($published) {
        $preferred = $published['radar-v2']??$published['radar-v3']??reset($published);
        $out['metrics']['radarEta'] = $preferred;
        $out['metrics']['radarEta']['algorithm'] = isset($published['radar-v2']) ? 'radar-v2' : 'radar-v3';
        $out['metrics']['radarAlgorithms'] = $published;
        $out['verifiedChecks']+=(int)$preferred['samples'];
    }
} catch (Throwable $ignored) {
}
$coreMetricCount = count(array_intersect(['temperature', 'rain', 'storm', 'radarEta'], array_keys($out['metrics'])));
$out['status'] = $coreMetricCount>=2 ? 'verified' : 'learning';
if ($out['status']!=='verified') {
    $out['verifiedChecks'] = 0;
    $out['period'] =['first'=>null, 'last'=>null];
}
respond($out);
