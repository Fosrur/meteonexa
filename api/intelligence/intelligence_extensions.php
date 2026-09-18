<?php
declare(strict_types=1);
require_once __DIR__ . '/nowcast_helpers.php';
require_once __DIR__ . '/confidence_helpers.php';
function meteonexa_intelligence_bound(float $value, float $min, float $max) : float {
    return max($min, min($max, $value));
}
function meteonexa_object_nowcast(PDO $pdo, string $deviceId, string $locationKey, array $fusion, array $consensus, array $cell, array $motion) : array {
    if (empty($fusion['available']))return['available'=>false, 'method'=>'object-nowcast-v2', 'timeline'=>[], 'generatedAt'=>gmdate('c')];
    $impact = (float)($fusion['impactProbability']??0);
    $confidence = (float)($fusion['confidence']??0);
    $eta = is_numeric($fusion['etaMinutes']??null) ? (int)$fusion['etaMinutes'] : null;
    $etaRange = is_array($fusion['etaRangeMinutes']??null) ? array_values($fusion['etaRangeMinutes']) : null;
    $growth = (float)($cell['growthPct']??$fusion['growthPct']??0);
    $stage = (string)($cell['stage']??$fusion['stage']??'stable');
    $hourly = (array)($consensus['hourly']??[]);
    $now = time();
    $timeline =[];
    for ($minute = 0; $minute<=90; $minute+=5) {
        $target = $now + $minute * 60;
        $nearest = null;
        $best = PHP_INT_MAX;
        foreach ($hourly as $row) {
            $ts = meteonexa_intel_time_utc((string)($row['time']??''));
            if ($ts===null)continue;
            $delta = abs($ts - $target);
            if ($delta < $best) {
                $best = $delta;
                $nearest = $row;
            }
        }
        $nwp = 0.0;
        if ($nearest) {
            if (is_numeric($nearest['rainWeightedPct']??null))$nwp = (float)$nearest['rainWeightedPct'];
            elseif ((int)($nearest['available']??0) > 0)$nwp = 100 * (int)($nearest['rainVotes']??0) / max(1, (int)$nearest['available']);
        }
        if ($eta!==null) {
            $width = max(12,($etaRange ? abs((int)$etaRange[1] - (int)$etaRange[0]) : 20));
            $radar = $impact * exp( - pow(($minute - $eta) / $width, 2));
            if ($minute < $eta)$radar = max($radar, $impact * meteonexa_intelligence_bound(1 - $minute / max(20, $eta * 1.8), .25, 1));
        } else {
            $radar = $impact * meteonexa_intelligence_bound(1 - $minute / 130, .20, 1);
        }
        $radarWeight = meteonexa_intelligence_bound(.82 - $minute / 190, .34, .82);
        $prob = $radar * $radarWeight + $nwp *(1 - $radarWeight);
        if ($growth > 15&&$minute<=45)$prob+=min(10, $growth * .12);
        elseif ($growth < - 15)$prob-=min(10, abs($growth) * .10);
        $prob = (int)round(meteonexa_intelligence_bound($prob, 0, 100));
        $uncertainty = (int)round(meteonexa_intelligence_bound(6 +(100 - $confidence) * .18 + $minute * .11, 5, 35));
        $timeline[] =['minute'=>$minute, 'at'=>gmdate('c', $target), 'precipitationProbability'=>$prob, 'confidence'=>(int)round(meteonexa_intelligence_bound($confidence - $minute * .16, 25, 99)), 'uncertaintyPct'=>$uncertainty, 'radarWeight'=>round($radarWeight, 2), 'nwpProbability'=>(int)round($nwp)];
    }
    $onset = null;
    $end = null;
    $active = false;
    foreach ($timeline as $row) {
        if (!$active&&$row['precipitationProbability']>=50) {
            $active = true;
            $onset = $row['minute'];
            continue;
        }
        if ($active&&$row['precipitationProbability'] < 35) {
            $end = $row['minute'];
            break;
        }
    }
    if ($active&&$end===null)$end = 90;
    $speed = is_numeric($fusion['speedKmh']??null) ? (float)$fusion['speedKmh'] : null;
    $cone =[];
    foreach ([15, 30, 45, 60, 90] as $minute) {
        $distance = $speed===null ? null : round($speed * $minute / 60, 1);
        $radius = round(2.5 + $minute *(1 - $confidence / 100) * .18 +($minute / 90) * 4, 1);
        $cone[] =['minute'=>$minute, 'distanceKm'=>$distance, 'radiusKm'=>$radius];
    }
    // ETA verification must never use the same fusion probability that produced
    // the forecast as its own ground truth. Only rows closed by the independent
    // observation verifier are eligible for the single-sample UI indicator.
    $verification =['available'=>false, 'errorMinutes'=>null, 'predictedAt'=>null, 'sampleAt'=>null, 'basis'=>'independent-observation-ledger'];
    if (meteonexa_db_table_exists($pdo, 'radar_eta_predictions')) {
        try {
            $st = $pdo->prepare("SELECT predicted_at,observed_at,error_minutes,verification_method FROM radar_eta_predictions WHERE device_id=:d AND location_key=:l AND status='verified' AND error_minutes IS NOT NULL AND verification_method LIKE 'independent%' ORDER BY id DESC LIMIT 1");
            $st->execute([':d'=>$deviceId, ':l'=>$locationKey]);
            $row = $st->fetch();
            if (is_array($row)) {
                $verification =['available'=>true, 'errorMinutes'=>(float)$row['error_minutes'], 'predictedAt'=>(string)$row['predicted_at'], 'sampleAt'=>(string)$row['observed_at'], 'basis'=>(string)($row['verification_method']??'independent-observation')];
            }
        } catch (Throwable $ignored) {
        }
    }
    return['available'=>true, 'method'=>'object-nowcast-v2', 'horizonMinutes'=>90, 'stepMinutes'=>5, 'impactProbability'=>(int)round($impact), 'confidence'=>(int)round($confidence), 'etaMinutes'=>$eta, 'etaRangeMinutes'=>$etaRange, 'rainStartMinutes'=>$onset, 'rainEndMinutes'=>$end, 'rainStartAt'=>$onset===null ? null : gmdate('c', $now + $onset * 60), 'rainEndAt'=>$end===null ? null : gmdate('c', $now + $end * 60), 'direction'=>$fusion['direction']??$motion['direction']??null, 'speedKmh'=>$speed, 'growthPct'=>$growth, 'stage'=>$stage, 'trajectoryCone'=>$cone, 'etaVerification'=>$verification, 'timeline'=>$timeline, 'generatedAt'=>gmdate('c')];
}
function meteonexa_confidence_timeline(array $consensus, array $weatherConfidence, array $freshness, array $skill, array $forecastChange) : array {
    $hourly = (array)($consensus['hourly']??[]);
    $freshAges =[];
    foreach ($freshness as $source) {
        if (!empty($source['available'])&&is_numeric($source['ageMinutes']??null))$freshAges[] = (float)$source['ageMinutes'];
    }
    $freshScore = $freshAges ? (int)round(meteonexa_intelligence_bound(100 -(array_sum($freshAges) / count($freshAges)) * .9, 35, 100)) : 65;
    $samples = (int)($skill['verifiedSamples']??0);
    $skillScore = $samples>=100 ? 92 :($samples>=30 ? 82 :($samples>=10 ? 68 : 55));
    $timeline =[];
    foreach (array_slice($hourly, 0, 48) as $index=>$row) {
        $available = max(1, (int)($row['available']??0));
        $agree = (float)($row['rainWeightedPct']??(100 * (int)($row['rainVotes']??0) / $available));
        $spreadTemp = (float)($row['temperatureSpread']??0);
        $spreadRain = (float)($row['precipitationSpread']??0);
        $spreadWind = (float)($row['windSpread']??0);
        $spreadPenalty = min(42, $spreadTemp * 3.2 + $spreadRain * 5.5 + $spreadWind * .35);
        $agreementScore = max($agree, 100 - $agree);
        $base = $agreementScore * .42 + $freshScore * .18 + $skillScore * .22 +(100 - $spreadPenalty) * .18;
        $score = (int)round(meteonexa_intelligence_bound($base, 20, 99));
        $timeline[] =['time'=>$row['time']??null, 'score'=>$score, 'agreementPct'=>(int)round($agree), 'freshnessScore'=>$freshScore, 'skillScore'=>$skillScore, 'temperatureSpread'=>round($spreadTemp, 1), 'precipitationSpread'=>round($spreadRain, 2), 'windSpread'=>round($spreadWind, 1), 'level'=>$score>=85 ? 'very_high' :($score>=72 ? 'high' :($score>=55 ? 'medium' : 'low'))];
    }
    $material = false;
    $reasons =[];
    if (!empty($forecastChange['available'])) {
        $time = abs((int)($forecastChange['timeShiftMinutes']??0));
        $rain = abs((int)($forecastChange['rainProbabilityDelta']??0));
        $agreement = abs((int)($forecastChange['agreementDelta']??0));
        if ($time>=30) {
            $material = true;
            $reasons[] = 'timing';
        }
        if ($rain>=20) {
            $material = true;
            $reasons[] = 'rain_probability';
        }
        if ($agreement>=20) {
            $material = true;
            $reasons[] = 'model_agreement';
        }
    }
    return['available'=>$timeline!==[], 'method'=>'confidence-timeline-v2', 'score'=>(int)($weatherConfidence['score']??($timeline[0]['score']??0)), 'timeline'=>$timeline, 'freshnessScore'=>$freshScore, 'verifiedSamples'=>$samples, 'forecastChange'=>['material'=>$material, 'reasons'=>$reasons, 'notifyRecommended'=>$material, 'timeShiftMinutes'=>$forecastChange['timeShiftMinutes']??null, 'rainProbabilityDelta'=>$forecastChange['rainProbabilityDelta']??null, 'agreementDelta'=>$forecastChange['agreementDelta']??null], 'generatedAt'=>gmdate('c')];
}
function meteonexa_personal_weather_twin(array $activityProfiles, array $decisionWindows) : array {
    $defaults = meteonexa_account_activity_defaults();
    $windows =[];
    foreach ($decisionWindows as $row) {
        $windows[(string)($row['activity']??'')] = $row;
    }
    $aliases =['outdoor'=>'outdoor_work'];
    $rows =[];
    foreach ($activityProfiles as $activity=>$thresholds) {
        $lookup = $aliases[$activity]??$activity;
        $window = $windows[$lookup]??null;
        $rows[] =['activity'=>$activity, 'thresholds'=>$thresholds, 'score'=>(int)($window['score']??0), 'bestStart'=>$window['startsAt']??null, 'bestEnd'=>$window['endsAt']??null, 'reasons'=>$window['reasons']??[], 'status'=>!$window ? 'learning' :(((int)$window['score']>=75) ? 'good' :(((int)$window['score']>=50) ? 'caution' : 'avoid'))];
    }
    usort($rows, static fn($a, $b)=>$b['score']<=>$a['score']);
    return['available'=>$rows!==[], 'profiles'=>$rows, 'supportedActivities'=>array_keys($defaults), 'method'=>'personal-weather-twin-v1', 'generatedAt'=>gmdate('c')];
}
function meteonexa_recency_skill(PDO $pdo, string $deviceId, string $locationKey) : array {
    $definitions = array_keys(meteonexa_intelq_model_definitions());
    $neutral =[];
    foreach (['rain', 'storm', 'snow'] as $metric) foreach ([1, 3, 6, 24, 48, 72] as $h) foreach ($definitions as $model)$neutral[$metric][$h][$model] = 1.0;
    if (!meteonexa_db_table_exists($pdo, 'model_skill_samples'))return['available'=>false, 'method'=>'recency-decay-skill-v2', 'verifiedSamples'=>0, 'weights'=>$neutral, 'buckets'=>[], 'productionEligibleBuckets'=>0, 'generatedAt'=>gmdate('c')];
    try {
        $st = $pdo->prepare("SELECT model_name,metric,horizon_hours,error_value,brier_score,verified_at FROM model_skill_samples WHERE device_id=:d AND location_key=:l AND verified_at<>'' AND model_name<>'consensus' ORDER BY id DESC LIMIT 5000");
        $st->execute([':d'=>$deviceId, ':l'=>$locationKey]);
        $groups =[];
        $total = 0;
        $now = time();
        foreach ($st->fetchAll() as $row) {
            $model = (string)$row['model_name'];
            $metric = (string)$row['metric'];
            $h = (int)$row['horizon_hours'];
            if ($model===''||$metric===''||!in_array($h,[1, 3, 6, 24, 48, 72], true))continue;
            $verified = meteonexa_intel_time_utc((string)($row['verified_at']??''))??$now;
            $ageDays = max(0,($now - $verified) / 86400);
            $decay = exp( - $ageDays / 60);
            $key = $model . '|' . $metric . '|' . $h;
            if (!isset($groups[$key]))$groups[$key] =['model'=>$model, 'metric'=>$metric, 'horizonHours'=>$h, 'samples'=>0, 'effectiveSamples'=>0.0, 'sumError'=>0.0, 'errorWeight'=>0.0, 'sumBrier'=>0.0, 'brierWeight'=>0.0, 'newestAt'=>null];
            $g = &$groups[$key];
            $g['samples']++;
            $g['effectiveSamples']+=$decay;
            if (is_numeric($row['error_value']??null)) {
                $g['sumError']+=(float)$row['error_value'] * $decay;
                $g['errorWeight']+=$decay;
            }
            if (is_numeric($row['brier_score']??null)) {
                $g['sumBrier']+=(float)$row['brier_score'] * $decay;
                $g['brierWeight']+=$decay;
            }
            $g['newestAt'] = $g['newestAt']??($row['verified_at']??null);
            unset($g);
            $total++;
        }
        $buckets =[];
        $rawWeights =[];
        $eligible = 0;
        foreach ($groups as $g) {
            $mae = $g['errorWeight'] > 0 ? $g['sumError'] / $g['errorWeight'] : null;
            $brier = $g['brierWeight'] > 0 ? $g['sumBrier'] / $g['brierWeight'] : null;
            $binary = in_array($g['metric'],['rain', 'storm', 'snow'], true);
            $raw = $binary&&$brier!==null ? 1 - meteonexa_intelligence_bound($brier, 0, 1) :($mae!==null ? 1 /(1 + $mae /($g['metric']==='wind' ? 10 : 3)) : 0.55);
            $maturity = meteonexa_intelligence_bound($g['effectiveSamples'] / 45, 0, 1);
            $shrunk = .55 +($raw - .55) * $maturity;
            $isEligible = $g['samples']>=30&&$g['effectiveSamples']>=15;
            if ($isEligible)$eligible++;
            $buckets[] =['model'=>$g['model'], 'metric'=>$g['metric'], 'horizonHours'=>$g['horizonHours'], 'samples'=>$g['samples'], 'effectiveSamples'=>round($g['effectiveSamples'], 1), 'mae'=>$mae===null ? null : round($mae, 3), 'brier'=>$brier===null ? null : round($brier, 4), 'skillScore'=>(int)round(meteonexa_intelligence_bound($shrunk * 100, 0, 100)), 'productionEligible'=>$isEligible, 'decayHalfLifeDays'=>round(log(2) * 60, 1), 'newestVerifiedAt'=>$g['newestAt']];
            if ($binary)$rawWeights[$g['metric']][$g['horizonHours']][$g['model']] = max(.25, min(1.75, $shrunk / .55));
        }
        $weights = $neutral;
        foreach (['rain', 'storm', 'snow'] as $metric) foreach ([1, 3, 6, 24, 48, 72] as $h) {
            $vals =[];
            foreach ($definitions as $model)$vals[$model] = (float)($rawWeights[$metric][$h][$model]??1.0);
            $mean = array_sum($vals) / max(1, count($vals));
            foreach ($vals as $model=>$value)$weights[$metric][$h][$model] = round($value / max(.01, $mean), 4);
        }
        usort($buckets, static fn($a, $b)=>[$a['metric'], $a['horizonHours'], $a['model']]<=>[$b['metric'], $b['horizonHours'], $b['model']]);
        return['available'=>$total > 0, 'method'=>'recency-decay-skill-v2', 'verifiedSamples'=>$total, 'weights'=>$weights, 'buckets'=>$buckets, 'productionEligibleBuckets'=>$eligible, 'decayTimeConstantDays'=>60, 'guardrails'=>['minimumSamples'=>30, 'minimumEffectiveSamples'=>15, 'neutralWeight'=>1.0, 'unseenModelStartsNeutral'=>true], 'generatedAt'=>gmdate('c')];
    } catch (Throwable $ignored) {
        return['available'=>false, 'method'=>'recency-decay-skill-v2', 'verifiedSamples'=>0, 'weights'=>$neutral, 'buckets'=>[], 'productionEligibleBuckets'=>0, 'generatedAt'=>gmdate('c')];
    }
}
function meteonexa_ai_model_status(array $models) : array {
    $rows =[];
    foreach ($models as $id=>$model) {
        if (!in_array($id,['aifs'], true))continue;
        $rows[] =['id'=>$id, 'label'=>$model['label']??strtoupper($id), 'available'=>!empty($model['available']), 'ageMinutes'=>$model['ageMinutes']??null, 'stale'=>!empty($model['stale']), 'role'=>'independent-ai-weather-evidence', 'operational'=>true, 'provider'=>'ECMWF', 'gateway'=>'Open-Meteo', 'resolution'=>'0.25°', 'temporalResolution'=>'6h'];
    }
    return['models'=>$rows, 'enabled'=>array_values(array_filter(array_column($rows, 'id'), static fn($id)=>$id!=='')), 'policy'=>'AI forecast models are evidence inputs; radar/observations remain authoritative for local nowcast', 'generatedAt'=>gmdate('c')];
}
