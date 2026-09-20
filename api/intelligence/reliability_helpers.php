<?php
declare(strict_types=1);
require_once __DIR__ . '/quality_helpers.php';
function meteonexa_reliability_sample_level(int $n) : array {
    return $n>=100 ?['id'=>'consolidated', 'publishable'=>true] :($n>=30 ?['id'=>'building', 'publishable'=>true] :($n>=10 ?['id'=>'preliminary', 'publishable'=>false] :['id'=>'initial', 'publishable'=>false]));
}
function meteonexa_wilson_interval(float $rate, int $n, float $z = 1.96) : array {
    if ($n<=0)return[0, 1];
    $p = meteonexa_intel_clamp($rate, 0, 1);
    $z2 = $z * $z;
    $den = 1 + $z2 / $n;
    $center =($p + $z2 /(2 * $n)) / $den;
    $m = $z * sqrt(max(0, $p *(1 - $p) / $n + $z2 /(4 * $n * $n))) / $den;
    return[meteonexa_intel_clamp($center - $m, 0, 1), meteonexa_intel_clamp($center + $m, 0, 1)];
}
function meteonexa_reliability_skill_weights(PDO $pdo, string $device, string $location) : array {
    if (!meteonexa_db_table_exists($pdo, 'model_skill_samples'))return[];
    try {
        $st = $pdo->prepare("SELECT model_name,metric,horizon_hours,COUNT(*) samples,AVG(error_value) mae,AVG(brier_score) brier FROM model_skill_samples WHERE device_id=:d AND location_key=:l AND verified_at<>'' AND model_name<>'consensus' GROUP BY model_name,metric,horizon_hours");
        $st->execute([':d'=>$device, ':l'=>$location]);
        $out =[];
        foreach ($st->fetchAll() as $r) {
            $m = (string)$r['metric'];
            $h = (int)$r['horizon_hours'];
            $n = (int)$r['samples'];
            $raw = in_array($m,['rain', 'storm', 'snow'], true) ? 1 - meteonexa_intel_clamp((float)($r['brier']??.25), 0, 1) : 1 /(1 + max(0, (float)($r['mae']??9)));
            $w = .55 +($raw - .55) * min(1, $n / 100);
            $out[$m][$h][(string)$r['model_name']] = max(.18, min(1, $w));
        }
        foreach ($out as $m=>$hs) foreach ($hs as $h=>$weights) {
            $sum = array_sum($weights);
            foreach ($weights as $model=>$w)$out[$m][$h][$model] = round($w / $sum, 4);
        }
        return $out;
    } catch (Throwable $e) {
        return[];
    }
}
function meteonexa_weighted_consensus(array $models, array $weights) : array {
    $base = meteonexa_intelq_consensus($models);
    $hourly =[];
    foreach ((array)($base['hourly']??[]) as $row) {
        $h = meteonexa_intelq_nearest_horizon($row['time']??null);
        foreach (['rain', 'storm', 'snow'] as $metric) {
            $map = (array)($row[$metric . 'Models']??[]);
            $mw = (array)($weights[$metric][$h]??[]);
            $yes = 0;
            $total = 0;
            $applied =[];
            foreach ($map as $model=>$hit) {
                $w = (float)($mw[$model]??1);
                $yes+=($hit ? $w : 0);
                $total+=$w;
                $applied[$model] = round($w, 4);
            }
            $row[$metric . 'WeightedPct'] = $total ? (int)round(100 * $yes / $total) : 0;
            $row[$metric . 'Weights'] = $applied;
        }
        $row['weightHorizonHours'] = $h;
        $hourly[] = $row;
    }
    $base['hourly'] = $hourly;
    $p = (array)($base['primary']??[]);
    if ($p) {
        foreach ($hourly as $r) {
            if ((string)($r['time']??'')!==(string)($p['representativeAt']??''))continue;
            $t = (string)($p['type']??'rain');
            $base['primary']['weightedAgreementPct'] = (int)($r[$t . 'WeightedPct']??$p['agreementPct']??0);
            break;
        }
    }
    $base['weighting'] =['mode'=>$weights ? 'historical-skill-shrunk' : 'uniform'];
    return $base;
}
function meteonexa_forecast_run_stability(PDO $pdo, string $device, string $location) : array {
    if (!meteonexa_db_table_exists($pdo, 'forecast_run_snapshots'))return['available'=>false, 'samples'=>0];
    $st = $pdo->prepare('SELECT snapshot_json,created_at FROM forecast_run_snapshots WHERE device_id=:d AND location_key=:l ORDER BY id DESC LIMIT 8');
    $st->execute([':d'=>$device, ':l'=>$location]);
    $rows = array_reverse($st->fetchAll());
    if (count($rows) < 2)return['available'=>false, 'samples'=>count($rows)];
    $scores =[];
    $prev = null;
    foreach ($rows as $r) {
        $snap = json_decode((string)$r['snapshot_json'], true);
        if (!is_array($snap))continue;
        if ($prev) {
            $c = meteonexa_intelq_compare_snapshots($prev, $snap);
            if ($c['available']??false)$scores[] = (int)$c['stabilityPct'];
        }
        $prev = $snap;
    }
    $avg = $scores ? (int)round(array_sum($scores) / count($scores)) : null;
    return['available'=>$scores!==[], 'samples'=>count($rows), 'stabilityPct'=>$avg, 'trend'=>$avg===null ? 'unknown' :($avg < 60 ? 'volatile' :($avg < 80 ? 'changing' : 'stable'))];
}
function meteonexa_reliability_summary(PDO $pdo, string $device, string $location, array $skill, array $obs, array $consensus, array $calibration) : array {
    $p = (array)($consensus['primary']??[]);
    $metric = (string)($p['type']??'');
    $h = meteonexa_intelq_nearest_horizon($p['startsAt']??null);
    $samples = (int)($calibration['samples']??0);
    return['available'=>!empty($obs['available'])||$samples > 0, 'independentObservations'=>(bool)($obs['independentFromNwp']??false), 'observationSourceCount'=>(int)($obs['sourceCount']??0), 'observationSourceTypes'=>(array)($obs['sourceTypes']??[]), 'observationQualityScore'=>(int)($obs['qualityScore']??0), 'metric'=>$metric, 'horizonHours'=>$h, 'metricVerifiedSamples'=>$samples, 'calibrationLevel'=>meteonexa_reliability_sample_level($samples), 'totalVerifiedSamples'=>(int)($skill['verifiedSamples']??0), 'weightedAgreementPct'=>(int)($p['weightedAgreementPct']??$p['agreementPct']??0), 'uniformAgreementPct'=>(int)($p['agreementPct']??0), 'runStability'=>meteonexa_forecast_run_stability($pdo, $device, $location), 'calibration'=>$calibration];
}
