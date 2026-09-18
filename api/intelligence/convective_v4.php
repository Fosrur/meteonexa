<?php
declare(strict_types=1);
require_once __DIR__ . '/convective_v3.php';
require_once __DIR__ . '/verification_helpers.php';
function meteonexa_convective_calibration_map(PDO $pdo, string $deviceId, string $locationKey) : array {
    if (!meteonexa_db_table_exists($pdo, 'predictive_alert_opportunities'))return[];
    try {
        $st = $pdo->prepare("SELECT raw_score,observed FROM predictive_alert_opportunities WHERE device_id=:d AND location_key=:l AND event_type='storm' AND status='verified' AND observed IS NOT NULL AND raw_score>=0 ORDER BY id DESC LIMIT 2000");
        $st->execute([':d'=>$deviceId, ':l'=>$locationKey]);
        $bins =[];
        foreach ($st->fetchAll() as $r) {
            $bucket = max(0, min(9, (int)floor((float)$r['raw_score'] / 10)));
            $bins[$bucket]['n'] =($bins[$bucket]['n']??0) + 1;
            $bins[$bucket]['hits'] =($bins[$bucket]['hits']??0) + (int)$r['observed'];
        }
        foreach ($bins as $bucket=>&$b) {
            $n = (int)$b['n'];
            $hits = (int)$b['hits'];
            $b['observedRatePct'] = round(100 *($hits + 2) /($n + 4), 1);
            $b['publishable'] = $n>=30;
        }
        unset($b);
        return $bins;
    } catch (Throwable $ignored) {
        return[];
    }
}
function meteonexa_convective_calibrate_score(float $raw, array $map) : array {
    $bucket = max(0, min(9, (int)floor($raw / 10)));
    $b = $map[$bucket]??null;
    if (!$b)return['probabilityPct'=>round($raw), 'samples'=>0, 'mode'=>'raw-learning'];
    $n = (int)$b['n'];
    $observed = (float)$b['observedRatePct'];
    $evidence = min(1, $n / 30);
    $calibrated = round($raw *(1 - $evidence) + $observed * $evidence);
    return['probabilityPct'=>(int)max(0, min(100, $calibrated)), 'samples'=>$n, 'mode'=>$n>=30 ? 'locally-calibrated' : 'shrunk-learning', 'observedRatePct'=>$observed];
}
function meteonexa_hail_potential(array $peak, array $lightning, array $radar3) : array {
    if (!$peak)return['available'=>false, 'level'=>'unknown', 'score'=>null];
    $cape = (float)($peak['cape']??0);
    $shear = (float)($peak['shear850to500Kmh']??0);
    $freezing = is_numeric($peak['freezingLevelM']??null) ? (float)$peak['freezingLevelM'] : null;
    $ensemble = (float)($peak['ensembleStormAgreementPct']??0);
    $lightningCount = (int)($lightning['recent30m']??0);
    $growth = 0.0;
    foreach ((array)($radar3['cells']??[]) as $cell)$growth = max($growth, (float)($cell['growthPct']??0));
    $score = 0.0;
    $score+=min(30, $cape / 90);
    $score+=min(22, $shear / 3);
    if ($freezing!==null) {
        if ($freezing>=1600&&$freezing<=3400)$score+=18;
        elseif ($freezing<=4200)$score+=8;
    }
    $score+=min(12, $lightningCount * 1.5);
    $score+=min(10, max(0, $growth) / 6);
    $score+=min(8, $ensemble / 12.5);
    $score = (int)round(max(0, min(100, $score)));
    $level = $score>=72 ? 'high' :($score>=50 ? 'moderate' :($score>=30 ? 'elevated' : 'low'));
    return['available'=>true, 'score'=>$score, 'level'=>$level, 'official'=>false, 'method'=>'hail-potential-v1', 'ingredients'=>['cape'=>round($cape), 'shear850to500Kmh'=>round($shear, 1), 'freezingLevelM'=>$freezing===null ? null : round($freezing), 'lightning30m'=>$lightningCount, 'radarGrowthPct'=>round($growth, 1), 'ensembleStormAgreementPct'=>round($ensemble)], 'note'=>'potential-not-deterministic-hail-forecast'];
}
function meteonexa_convective_risk_v4(PDO $pdo, string $deviceId, string $locationKey, float $lat, float $lon, array $severeOutlook, array $lightning, array $radar3, int $hours = 72) : array {
    $base = meteonexa_convective_risk_v3($lat, $lon, $severeOutlook, $hours);
    if (empty($base['available'])) {
        $base['method'] = 'convective-risk-v4';
        $base['calibration'] =['mode'=>'unavailable', 'samples'=>0];
        $base['hailPotential'] =['available'=>false];
        return $base;
    }
    $map = meteonexa_convective_calibration_map($pdo, $deviceId, $locationKey);
    $totalSamples = 0;
    foreach ($map as $b)$totalSamples+=(int)($b['n']??0);
    foreach ($base['timeline'] as &$row) {
        $cal = meteonexa_convective_calibrate_score((float)($row['score']??0), $map);
        $row['rawScore'] = $row['score'];
        $row['calibratedProbabilityPct'] = $cal['probabilityPct'];
        $row['calibrationSamples'] = $cal['samples'];
        $row['calibrationMode'] = $cal['mode'];
    }
    unset($row);
    $peak = $base['peak']??null;
    if (is_array($peak)) {
        $cal = meteonexa_convective_calibrate_score((float)($peak['score']??0), $map);
        $peak['rawScore'] = $peak['score'];
        $peak['calibratedProbabilityPct'] = $cal['probabilityPct'];
        $peak['calibrationSamples'] = $cal['samples'];
        $peak['calibrationMode'] = $cal['mode'];
        $base['peak'] = $peak;
    }
    $base['method'] = 'convective-risk-v4-calibrated';
    $base['calibration'] =['samples'=>$totalSamples, 'mode'=>$totalSamples>=60 ? 'local-history' : 'learning', 'bins'=>$map];
    $base['hailPotential'] = meteonexa_hail_potential((array)($base['peak']??[]), $lightning, $radar3);
    return $base;
}
