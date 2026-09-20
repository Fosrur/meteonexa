<?php
declare(strict_types=1);
/** MeteoNexa — probabilistic Nowcast 4.0 helpers. */
function meteonexa_probability_bound(float $value, float $min = 0, float $max = 100) : float {
    return max($min, min($max, $value));
}
function meteonexa_time_in_window(int $target, ? string $start, ? string $end) : bool {
    $s = $start ? meteonexa_intel_time_utc($start) : null;
    $e = $end ? meteonexa_intel_time_utc($end) : null;
    if ($s===null)return false;
    if ($e===null)$e = $s + 3600;
    return $target>=$s - 900&&$target<=$e + 900;
}
function meteonexa_nearest_convective(array $convective, int $target) : ? array {
    $best = null;
    $delta = PHP_INT_MAX;
    foreach ((array)($convective['timeline']??[]) as $row) {
        $ts = meteonexa_intel_time_utc((string)($row['time']??''));
        if ($ts===null)continue;
        $d = abs($ts - $target);
        if ($d < $delta) {
            $delta = $d;
            $best = $row;
        }
    }
    return $best;
}
function meteonexa_arrival_distribution(array $v2) : array {
    $eta = is_numeric($v2['etaMinutes']??null) ? (int)$v2['etaMinutes'] : null;
    $confidence = (int)meteonexa_probability_bound((float)($v2['confidence']??0));
    if ($eta===null||$confidence < 45)return['available'=>false, 'basis'=>'no-reliable-eta', 'byMinutes'=>[]];
    $range = is_array($v2['etaRangeMinutes']??null) ? array_values($v2['etaRangeMinutes']) : null;
    $lo = $range&&isset($range[0])&&is_numeric($range[0]) ? max(0, (int)$range[0]) : max(0, $eta - 10);
    $hi = $range&&isset($range[1])&&is_numeric($range[1]) ? min(90, (int)$range[1]) : min(90, $eta + 10);
    if ($hi < $lo) {
        $tmp = $lo;
        $lo = $hi;
        $hi = $tmp;
    }
    $sigma = max(5.0, max(5,($hi - $lo) / 2) / 1.35);
    $eventLikelihood = 0;
    foreach ((array)($v2['timeline']??[]) as $row) if ((int)($row['minute']??0)<=60)$eventLikelihood = max($eventLikelihood, (int)($row['precipitationProbability']??0));
    $eventLikelihood = (int)meteonexa_probability_bound($eventLikelihood);
    $weights =[];
    $total = 0.0;
    for ($m = 0; $m<=90; $m+=5) {
        $z =($m - $eta) / $sigma;
        $w = exp( - .5 * $z * $z);
        $weights[$m] = $w;
        $total+=$w;
    }
    $cdf = 0.0;
    $by =[];
    foreach ($weights as $m=>$w) {
        $cdf+=$w;
        $by[] =['minute'=>$m, 'probabilityPct'=>(int)round($eventLikelihood *($total > 0 ? $cdf / $total : 0))];
    }
    $quantile = function(float $q)use($weights, $total) : int {
        $acc = 0.0;
        foreach ($weights as $m=>$w) {
            $acc+=$w;
            if ($total > 0&&$acc / $total>=$q)return (int)$m;
        }
        return 90;
    };
    return['available'=>true, 'basis'=>'eta-distribution-v1', 'etaMinutes'=>$eta, 'mostLikelyRangeMinutes'=>[$quantile(.25), $quantile(.75)], 'eventProbabilityPct'=>$eventLikelihood, 'byMinutes'=>$by];
}
function meteonexa_nowcast_source_summary(array $v2, array $lightning, array $satellite, array $observations, array $official, array $consensus) : array {
    $rows =[];
    $radar3 = (string)($v2['radar3Mode']??'active-fallback-v2');
    $rows[] =['id'=>$radar3==='active' ? 'radar3' : 'radar2', 'available'=>!empty($v2['available']), 'authoritative'=>true, 'mode'=>$radar3];
    $rows[] =['id'=>'lightning', 'available'=>!empty($lightning['available'])&&empty($lightning['degraded']), 'authoritative'=>false];
    $rows[] =['id'=>'satellite', 'available'=>!empty($satellite['available']), 'authoritative'=>false];
    $rows[] =['id'=>'observations', 'available'=>!empty($observations['available'])||!empty($observations['evidence']), 'authoritative'=>false, 'count'=>(int)($observations['sourceCount']??count((array)($observations['evidence']??[])))];
    $rows[] =['id'=>'models', 'available'=>!empty($consensus['hourly']), 'authoritative'=>false, 'count'=>(int)($consensus['modelsAvailable']??0)];
    $rows[] =['id'=>'official', 'available'=>!empty($official['relevant']), 'authoritative'=>false];
    return $rows;
}
function meteonexa_probabilistic_nowcast(array $v2, array $consensus, array $lightning, array $satellite, array $convective, array $severe, array $observations, array $official) : array {
    if (empty($v2['available']))return['available'=>false, 'method'=>'probabilistic-nowcast-v4', 'timeline'=>[], 'arrivalDistribution'=>['available'=>false, 'byMinutes'=>[]], 'generatedAt'=>gmdate('c')];
    $now = time();
    $lightCount = (int)($lightning['recent30m']??0);
    $lightDistance = is_numeric($lightning['nearestKm']??null) ? (float)$lightning['nearestKm'] : null;
    $lightScore = 0.0;
    if (!empty($lightning['available'])&&empty($lightning['degraded'])) {
        $lightScore = min(100, $lightCount * 9 +($lightDistance===null ? 0 : max(0, 45 - $lightDistance) * 1.6));
        if (!empty($lightning['approaching']))$lightScore = min(100, $lightScore + 12);
    }
    $satScore = !empty($satellite['available']) ? (float)($satellite['cloudAttenuationPct']??0) : 0.0;
    $hail = (array)($convective['hailPotential']??[]);
    $hailScore = !empty($hail['available']) ? (int)meteonexa_probability_bound((float)($hail['score']??0)) : null;
    $timeline =[];
    foreach ((array)($v2['timeline']??[]) as $base) {
        $minute = (int)($base['minute']??0);
        if ($minute < 0||$minute > 90)continue;
        $target = $now + $minute * 60;
        $rain = (int)meteonexa_probability_bound((float)($base['precipitationProbability']??0));
        $unc = (int)meteonexa_probability_bound((float)($base['uncertaintyPct']??15), 0, 45);
        $conv = meteonexa_nearest_convective($convective, $target);
        $convProb = is_array($conv) ? (float)($conv['calibratedProbabilityPct']??$conv['score']??0) : (float)($convective['peak']['calibratedProbabilityPct']??$convective['peak']['score']??0);
        $liveDecay = max(.15, 1 - $minute / 105);
        $storm = (int)round(meteonexa_probability_bound($convProb * .62 + $lightScore * $liveDecay * .28 + $satScore * .10));
        $gust = 0.0;
        foreach ((array)($severe['events']??[]) as $event) {
            if (($event['type']??'')!=='wind'||!meteonexa_time_in_window($target, $event['startsAt']??null, $event['endsAt']??null))continue;
            $gust = max($gust, (float)($event['modelAgreementPct']??0) * .72 + (float)($event['confidence']??0) * .28);
        }
        $timeline[] =['minute'=>$minute, 'at'=>$base['at']??gmdate('c', $target), 'rainProbabilityPct'=>$rain, 'rainLowPct'=>(int)round(max(0, $rain - $unc)), 'rainHighPct'=>(int)round(min(100, $rain + $unc)), 'stormProbabilityPct'=>$storm, 'gustProbabilityPct'=>(int)round(meteonexa_probability_bound($gust)), 'hailPotentialScore'=>$hailScore, 'confidence'=>(int)meteonexa_probability_bound((float)($base['confidence']??$v2['confidence']??0))];
    }
    $first = $timeline[0]??null;
    $last = $timeline ? end($timeline) : null;
    $currentlyWet = is_array($first)&&(int)($first['rainProbabilityPct']??0)>=50;
    $endBy90 = $currentlyWet&&is_array($last) ? (int)round(meteonexa_probability_bound(100 - (float)($last['rainProbabilityPct']??100))) : null;
    $arrival = meteonexa_arrival_distribution($v2);
    $radarMode = (string)($v2['radar3Mode']??'active-fallback-v2');
    $authority = $radarMode==='active' ? 'radar3-verified' : 'radar2-authoritative';
    return['available'=>true, 'method'=>'probabilistic-nowcast-v4', 'horizonMinutes'=>90, 'stepMinutes'=>5, 'timeline'=>$timeline, 'arrivalDistribution'=>$arrival, 'rainEndBy90Pct'=>$endBy90, 'peakRainProbabilityPct'=>$timeline ? max(array_column($timeline, 'rainProbabilityPct')) : 0, 'peakStormProbabilityPct'=>$timeline ? max(array_column($timeline, 'stormProbabilityPct')) : 0, 'peakGustProbabilityPct'=>$timeline ? max(array_column($timeline, 'gustProbabilityPct')) : 0, 'hailPotential'=>$hailScore===null ?['available'=>false] :['available'=>true, 'score'=>$hailScore, 'level'=>$hail['level']??'unknown', 'probability'=>false], 'confidence'=>(int)meteonexa_probability_bound((float)($v2['confidence']??0)), 'radarAuthority'=>$authority, 'radar3Mode'=>$radarMode, 'radar3ProductionGate'=>$v2['radar3ProductionGate']??null, 'sources'=>meteonexa_nowcast_source_summary($v2, $lightning, $satellite, $observations, $official, $consensus), 'policy'=>['probabilistic'=>true, 'hailIsPotentialScoreNotProbability'=>true, 'officialWarningsRemainSeparate'=>true, 'radar3ProbationHonoured'=>true], 'generatedAt'=>gmdate('c')];
}
