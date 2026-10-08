<?php
declare(strict_types=1);
function meteonexa_weighted_consensus(array $models, array $weights, array $weightingMeta =[]) : array {
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
                
                
                
                $w = meteonexa_reliability_weight_value($mw, (string)$model);
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
    $base['weighting'] = $weightingMeta ?:['mode'=>$weights ? 'skill-weighted' : 'uniform'];
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
function meteonexa_reliability_summary(PDO $pdo, string $device, string $location, array $skill, array $obs, array $consensus, array $calibration, array $weightTournament =[]) : array {
    $p = (array)($consensus['primary']??[]);
    $metric = (string)($p['type']??'');
    $h = meteonexa_intelq_nearest_horizon($p['startsAt']??null);
    $samples = (int)($calibration['samples']??0);
    $diagram = in_array($metric,['rain','storm','snow'], true) ? meteonexa_reliability_diagram($pdo, $device, $location, $metric, $h) :['available'=>false,'metric'=>$metric,'horizonHours'=>$h,'bins'=>[],'samples'=>0,'effectiveSamples'=>0.0,'brier'=>null];
    return['available'=>!empty($obs['available'])||$samples > 0, 'independentObservations'=>(bool)($obs['independentFromNwp']??false), 'observationSourceCount'=>(int)($obs['sourceCount']??0), 'observationSourceTypes'=>(array)($obs['sourceTypes']??[]), 'observationQualityScore'=>(int)($obs['qualityScore']??0), 'metric'=>$metric, 'horizonHours'=>$h, 'metricVerifiedSamples'=>$samples, 'calibrationLevel'=>meteonexa_reliability_sample_level($samples), 'totalVerifiedSamples'=>(int)($skill['verifiedSamples']??0), 'weightedAgreementPct'=>(int)($p['weightedAgreementPct']??$p['agreementPct']??0), 'uniformAgreementPct'=>(int)($p['agreementPct']??0), 'season'=>meteonexa_reliability_season(time()+($h*3600)), 'weightingMode'=>(string)($weightTournament['mode']??$consensus['weighting']['mode']??'champion'), 'decayHalfLifeDays'=>30, 'weightTournament'=>$weightTournament, 'runStability'=>meteonexa_forecast_run_stability($pdo, $device, $location), 'calibration'=>$calibration, 'reliabilityDiagram'=>$diagram, 'freshnessContract'=>['modelRun'=>'provider-run metadata when available, otherwise explicitly estimated cadence', 'cache'=>'independent fetch/cache age']];
}
