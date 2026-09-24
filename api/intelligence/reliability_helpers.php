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
function meteonexa_reliability_season(mixed $value = null) : string {
    if ($value === null || $value === '') $ts = time();
    elseif (is_int($value) || is_float($value) || (is_string($value) && ctype_digit($value))) $ts = (int)$value;
    else {
        $parsed = strtotime((string)$value);
        $ts = $parsed === false ? time() : $parsed;
    }
    $month = (int)gmdate('n', $ts);
    return match (true) {
        in_array($month,[12,1,2], true) => 'winter',
        in_array($month,[3,4,5], true) => 'spring',
        in_array($month,[6,7,8], true) => 'summer',
        default => 'autumn',
    };
}
function meteonexa_reliability_decay_weight(mixed $verifiedAt, float $halfLifeDays = 30.0, ?int $referenceTime = null) : float {
    $reference = $referenceTime ?? time();
    $verified = strtotime((string)$verifiedAt);
    if ($verified === false) return 0.0;
    $ageDays = max(0.0, ($reference - $verified) / 86400);
    $halfLife = max(1.0, $halfLifeDays);
    return pow(0.5, $ageDays / $halfLife);
}
function meteonexa_reliability_loss_skill(string $metric, float $loss) : float {
    if (in_array($metric,['rain','storm','snow'], true)) return 1.0 - meteonexa_intel_clamp($loss, 0, 1);
    return 1.0 / (1.0 + max(0.0, $loss));
}
function meteonexa_reliability_skill_weights(PDO $pdo, string $device, string $location, ?int $referenceTime = null) : array {
    if (!meteonexa_db_table_exists($pdo, 'model_skill_samples'))return[];
    try {
        $reference = $referenceTime ?? time();
        $halfLifeDays = 30.0;
        $st = $pdo->prepare("SELECT model_name,metric,horizon_hours,target_time,error_value,brier_score,verified_at FROM model_skill_samples WHERE device_id=:d AND location_key=:l AND verified_at<>'' AND model_name<>'consensus'");
        $st->execute([':d'=>$device, ':l'=>$location]);
        $all =[];
        $seasonal =[];
        foreach ($st->fetchAll() as $r) {
            $model = trim((string)($r['model_name']??''));
            $metric = trim((string)($r['metric']??''));
            $h = (int)($r['horizon_hours']??0);
            if ($model===''||$metric===''||!in_array($h,[1,3,6,24,48,72], true))continue;
            $binary = in_array($metric,['rain','storm','snow'], true);
            $lossValue = $binary ?($r['brier_score']??null) :($r['error_value']??null);
            if (!is_numeric($lossValue))continue;
            $loss = max(0.0, (float)$lossValue);
            $decay = meteonexa_reliability_decay_weight($r['verified_at']??'', $halfLifeDays, $reference);
            if ($decay<=0.0)continue;
            if (!isset($all[$metric][$h][$model]))$all[$metric][$h][$model] =['weightedLoss'=>0.0,'effectiveSamples'=>0.0,'samples'=>0];
            $all[$metric][$h][$model]['weightedLoss']+=$loss * $decay;
            $all[$metric][$h][$model]['effectiveSamples']+=$decay;
            $all[$metric][$h][$model]['samples']++;
            $sampleSeason = meteonexa_reliability_season($r['target_time']??$r['verified_at']??'');
            if (!isset($seasonal[$metric][$h][$model][$sampleSeason]))$seasonal[$metric][$h][$model][$sampleSeason] =['weightedLoss'=>0.0,'effectiveSamples'=>0.0,'samples'=>0];
            $seasonal[$metric][$h][$model][$sampleSeason]['weightedLoss']+=$loss * $decay;
            $seasonal[$metric][$h][$model][$sampleSeason]['effectiveSamples']+=$decay;
            $seasonal[$metric][$h][$model][$sampleSeason]['samples']++;
        }
        $out =[];
        foreach ($all as $metric=>$horizons) foreach ($horizons as $h=>$models) {
            $scores =[];
            foreach ($models as $model=>$global) {
                $globalEff = max(0.0, (float)$global['effectiveSamples']);
                if ($globalEff<=0.0)continue;
                $globalLoss =(float)$global['weightedLoss'] / $globalEff;
                $targetSeason = meteonexa_reliability_season($reference +((int)$h * 3600));
                $season = $seasonal[$metric][$h][$model][$targetSeason]??null;
                $seasonEff = is_array($season) ? max(0.0, (float)$season['effectiveSamples']) : 0.0;
                $seasonLoss = $seasonEff>0.0 ?(float)$season['weightedLoss'] / $seasonEff : $globalLoss;
                // Seasonal specialization enters gradually. Until there are roughly
                // 30 effective same-season samples, fall back toward all-season skill.
                $seasonBlend = min(1.0, $seasonEff / 30.0);
                $blendedLoss = $seasonLoss * $seasonBlend + $globalLoss *(1.0 - $seasonBlend);
                $rawSkill = meteonexa_reliability_loss_skill((string)$metric, $blendedLoss);
                // Stronger low-sample shrinkage than the legacy n/100 rule. Temporal
                // decay means old samples contribute progressively less evidence.
                $evidence = min(1.0, $globalEff / 120.0);
                $prior = 0.55;
                $score = $prior +($rawSkill - $prior) * $evidence;
                $scores[$model] = max(0.18, min(1.0, $score));
            }
            $sum = array_sum($scores);
            if ($sum<=0.0)continue;
            foreach ($scores as $model=>$score)$out[$metric][$h][$model] = round($score / $sum, 4);
        }
        return $out;
    } catch (Throwable $e) {
        return[];
    }
}
function meteonexa_reliability_diagram(PDO $pdo, string $device, string $location, string $metric, int $horizonHours, int $bins = 5, ?int $referenceTime = null) : array {
    $horizon = in_array($horizonHours,[1,3,6,24,48,72], true) ? $horizonHours : 24;
    $binCount = max(4, min(10, $bins));
    if (!in_array($metric,['rain','storm','snow'], true)||!meteonexa_db_table_exists($pdo, 'model_skill_samples'))return['available'=>false,'metric'=>$metric,'horizonHours'=>$horizon,'bins'=>[],'samples'=>0,'effectiveSamples'=>0.0,'brier'=>null];
    try {
        $reference = $referenceTime ?? time();
        $halfLifeDays = 30.0;
        $st = $pdo->prepare("SELECT predicted_value,observed_value,brier_score,verified_at FROM model_skill_samples WHERE device_id=:d AND location_key=:l AND model_name='consensus' AND metric=:m AND horizon_hours=:h AND verified_at<>''");
        $st->execute([':d'=>$device, ':l'=>$location, ':m'=>$metric, ':h'=>$horizon]);
        $bucket = array_fill(0, $binCount,['samples'=>0,'weight'=>0.0,'forecast'=>0.0,'observed'=>0.0,'brier'=>0.0]);
        $samples = 0;
        $effective = 0.0;
        $weightedBrier = 0.0;
        foreach ($st->fetchAll() as $r) {
            if (!is_numeric($r['predicted_value']??null)||!is_numeric($r['observed_value']??null))continue;
            $probability = meteonexa_intel_clamp((float)$r['predicted_value'], 0, 1);
            $observed = (float)$r['observed_value']>=.5 ? 1.0 : 0.0;
            $brier = is_numeric($r['brier_score']??null) ? max(0.0, (float)$r['brier_score']) : meteonexa_intelq_brier($probability, $observed);
            $weight = meteonexa_reliability_decay_weight($r['verified_at']??'', $halfLifeDays, $reference);
            if ($weight<=0.0)continue;
            $index = min($binCount - 1, (int)floor($probability * $binCount));
            $bucket[$index]['samples']++;
            $bucket[$index]['weight']+=$weight;
            $bucket[$index]['forecast']+=$probability * $weight;
            $bucket[$index]['observed']+=$observed * $weight;
            $bucket[$index]['brier']+=$brier * $weight;
            $samples++;
            $effective+=$weight;
            $weightedBrier+=$brier * $weight;
        }
        $rows =[];
        $weightedGap = 0.0;
        foreach ($bucket as $index=>$item) {
            $weight =(float)$item['weight'];
            if ($weight<=0.0)continue;
            $forecast =(float)$item['forecast'] / $weight;
            $observed =(float)$item['observed'] / $weight;
            $low = $index / $binCount;
            $high =($index + 1) / $binCount;
            $gap = abs($forecast - $observed);
            $weightedGap+=$gap * $weight;
            $rows[] =[
                'rangePct'=>[(int)round($low * 100),(int)round($high * 100)],
                'forecastPct'=>(int)round($forecast * 100),
                'observedPct'=>(int)round($observed * 100),
                'calibrationGapPct'=>(int)round($gap * 100),
                'samples'=>(int)$item['samples'],
                'effectiveSamples'=>round($weight, 2),
                'brier'=>round((float)$item['brier'] / $weight, 4),
            ];
        }
        $level = meteonexa_reliability_sample_level($samples);
        return[
            'available'=>$samples>0,
            'publishable'=>!empty($level['publishable']),
            'metric'=>$metric,
            'horizonHours'=>$horizon,
            'season'=>meteonexa_reliability_season($reference),
            'decayHalfLifeDays'=>(int)$halfLifeDays,
            'samples'=>$samples,
            'effectiveSamples'=>round($effective, 2),
            'brier'=>$effective>0.0 ? round($weightedBrier / $effective, 4) : null,
            'meanCalibrationGapPct'=>$effective>0.0 ? round(100 * $weightedGap / $effective, 1) : null,
            'calibrationLevel'=>$level['id'],
            'bins'=>$rows,
        ];
    } catch (Throwable $e) {
        return['available'=>false,'metric'=>$metric,'horizonHours'=>$horizon,'bins'=>[],'samples'=>0,'effectiveSamples'=>0.0,'brier'=>null];
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
    $base['weighting'] =['mode'=>$weights ? 'seasonal-decayed-skill-shrunk' : 'uniform', 'seasonalByLeadTime'=>true, 'decayHalfLifeDays'=>30];
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
    $diagram = in_array($metric,['rain','storm','snow'], true) ? meteonexa_reliability_diagram($pdo, $device, $location, $metric, $h) :['available'=>false,'metric'=>$metric,'horizonHours'=>$h,'bins'=>[],'samples'=>0,'effectiveSamples'=>0.0,'brier'=>null];
    return['available'=>!empty($obs['available'])||$samples > 0, 'independentObservations'=>(bool)($obs['independentFromNwp']??false), 'observationSourceCount'=>(int)($obs['sourceCount']??0), 'observationSourceTypes'=>(array)($obs['sourceTypes']??[]), 'observationQualityScore'=>(int)($obs['qualityScore']??0), 'metric'=>$metric, 'horizonHours'=>$h, 'metricVerifiedSamples'=>$samples, 'calibrationLevel'=>meteonexa_reliability_sample_level($samples), 'totalVerifiedSamples'=>(int)($skill['verifiedSamples']??0), 'weightedAgreementPct'=>(int)($p['weightedAgreementPct']??$p['agreementPct']??0), 'uniformAgreementPct'=>(int)($p['agreementPct']??0), 'season'=>meteonexa_reliability_season(time()+($h*3600)), 'weightingMode'=>'seasonal-decayed-skill-shrunk', 'decayHalfLifeDays'=>30, 'runStability'=>meteonexa_forecast_run_stability($pdo, $device, $location), 'calibration'=>$calibration, 'reliabilityDiagram'=>$diagram, 'freshnessContract'=>['modelRun'=>'provider-run metadata when available, otherwise explicitly estimated cadence', 'cache'=>'independent fetch/cache age']];
}
