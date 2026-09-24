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
function meteonexa_reliability_skill_weights_from_rows(array $rows, ?int $referenceTime = null) : array {
    $reference = $referenceTime ?? time();
    $halfLifeDays = 30.0;
    $all =[];
    $seasonal =[];
    foreach ($rows as $r) {
        $model = trim((string)($r['model_name']??''));
        $metric = trim((string)($r['metric']??''));
        $h = (int)($r['horizon_hours']??0);
        if ($model===''||$model==='consensus'||$metric===''||!in_array($h,[1,3,6,24,48,72], true))continue;
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
            // Strong low-sample shrinkage prevents a short lucky streak from
            // immediately taking authority from the production champion.
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
}
function meteonexa_reliability_skill_weights(PDO $pdo, string $device, string $location, ?int $referenceTime = null) : array {
    if (!meteonexa_db_table_exists($pdo, 'model_skill_samples'))return[];
    try {
        $st = $pdo->prepare("SELECT model_name,metric,horizon_hours,target_time,error_value,brier_score,verified_at FROM model_skill_samples WHERE device_id=:d AND location_key=:l AND verified_at<>'' AND model_name<>'consensus' ORDER BY verified_at DESC LIMIT 5000");
        $st->execute([':d'=>$device, ':l'=>$location]);
        return meteonexa_reliability_skill_weights_from_rows($st->fetchAll(), $referenceTime);
    } catch (Throwable $e) {
        return[];
    }
}
function meteonexa_reliability_weight_value(array $bucket, string $model) : float {
    if (isset($bucket[$model])&&is_numeric($bucket[$model])&&(float)$bucket[$model]>0.0)return(float)$bucket[$model];
    $usable = array_values(array_filter(array_map(static fn($v)=>is_numeric($v) ? (float)$v : 0.0, $bucket), static fn($v)=>$v>0.0));
    return $usable ? array_sum($usable) / count($usable) : 1.0;
}
function meteonexa_reliability_champion_weights_from_rows(array $rows, ?int $referenceTime = null) : array {
    $reference = $referenceTime ?? time();
    $definitions = array_keys(meteonexa_intelq_model_definitions());
    $groups =[];
    foreach ($rows as $row) {
        $model = trim((string)($row['model_name']??''));
        $metric = trim((string)($row['metric']??''));
        $h = (int)($row['horizon_hours']??0);
        if ($model===''||$model==='consensus'||!in_array($metric,['rain','storm','snow'], true)||!in_array($h,[1,3,6,24,48,72], true)||!is_numeric($row['brier_score']??null))continue;
        $verified = strtotime((string)($row['verified_at']??''));
        if ($verified===false)$verified = $reference;
        $ageDays = max(0.0,($reference - $verified) / 86400);
        $decay = exp(-$ageDays / 60.0);
        $key = $metric . '|' . $h . '|' . $model;
        if (!isset($groups[$key]))$groups[$key] =['model'=>$model,'metric'=>$metric,'horizon'=>$h,'sumBrier'=>0.0,'weight'=>0.0];
        $groups[$key]['sumBrier']+=(float)$row['brier_score'] * $decay;
        $groups[$key]['weight']+=$decay;
    }
    $raw =[];
    foreach ($groups as $g) {
        if ((float)$g['weight']<=0.0)continue;
        $brier =(float)$g['sumBrier'] / (float)$g['weight'];
        $skill = 1.0 - meteonexa_intel_clamp($brier, 0, 1);
        $maturity = meteonexa_intel_clamp((float)$g['weight'] / 45.0, 0, 1);
        $shrunk = .55 +($skill - .55) * $maturity;
        $raw[$g['metric']][$g['horizon']][$g['model']] = max(.25, min(1.75, $shrunk / .55));
    }
    $out =[];
    foreach (['rain','storm','snow'] as $metric) foreach ([1,3,6,24,48,72] as $h) {
        $values =[];
        foreach ($definitions as $model)$values[$model] = (float)($raw[$metric][$h][$model]??1.0);
        $mean = array_sum($values) / max(1, count($values));
        foreach ($values as $model=>$value)$out[$metric][$h][$model] = round($value / max(.01, $mean), 4);
    }
    return $out;
}
function meteonexa_reliability_shadow_policy() : array {
    return[
        'version'=>'p1.2-champion-challenger-v1',
        'minimumTrainingTargets'=>30,
        'minimumEvaluationSamples'=>24,
        'minimumModelsPerSample'=>3,
        'minimumCoveragePct'=>90,
        'minimumAbsoluteBrierImprovement'=>0.01,
        'minimumRelativeBrierImprovementPct'=>5.0,
        'maximumCalibrationGapRegressionPct'=>2.0,
        'maximumSingleModelSharePct'=>60.0,
        'minimumStableWindows'=>2,
        'evaluationWindows'=>3,
        'maximumTargetsPerBucket'=>90,
        'cacheTtlSeconds'=>21600,
    ];
}
function meteonexa_reliability_shadow_groups(array $rows, string $metric, int $horizon) : array {
    $groups =[];
    foreach ($rows as $row) {
        if ((string)($row['metric']??'')!==$metric||(int)($row['horizon_hours']??0)!==$horizon)continue;
        $model = trim((string)($row['model_name']??''));
        if ($model===''||$model==='consensus'||!is_numeric($row['predicted_value']??null)||!is_numeric($row['observed_value']??null))continue;
        $target = (string)($row['target_time']??'');
        $ts = strtotime($target);
        if ($ts===false)continue;
        if (!isset($groups[$target]))$groups[$target] =['targetTime'=>$target,'timestamp'=>$ts,'observed'=>(float)$row['observed_value']>=.5 ? 1.0 : 0.0,'predictions'=>[]];
        $groups[$target]['predictions'][$model] = meteonexa_intel_clamp((float)$row['predicted_value'], 0, 1);
    }
    $groups = array_values($groups);
    usort($groups, static fn($a,$b)=>(int)$a['timestamp']<=>(int)$b['timestamp']);
    return $groups;
}
function meteonexa_reliability_shadow_calibration_gap(array $predictions, int $bins = 5) : ?float {
    $binCount = max(4, min(10, $bins));
    $bucket = array_fill(0, $binCount,['count'=>0,'forecast'=>0.0,'observed'=>0.0]);
    foreach ($predictions as $row) {
        if (!is_numeric($row['prediction']??null)||!is_numeric($row['observed']??null))continue;
        $probability = meteonexa_intel_clamp((float)$row['prediction'], 0, 1);
        $observed = (float)$row['observed']>=.5 ? 1.0 : 0.0;
        $index = min($binCount - 1, (int)floor($probability * $binCount));
        $bucket[$index]['count']++;
        $bucket[$index]['forecast']+=$probability;
        $bucket[$index]['observed']+=$observed;
    }
    $total = 0;
    $weightedGap = 0.0;
    foreach ($bucket as $item) {
        $count = (int)$item['count'];
        if ($count<=0)continue;
        $forecast = (float)$item['forecast'] / $count;
        $observed = (float)$item['observed'] / $count;
        $weightedGap+=abs($forecast - $observed) * $count;
        $total+=$count;
    }
    return $total>0 ? 100 * $weightedGap / $total : null;
}
function meteonexa_reliability_shadow_score(array $groups, array $weights, int $minimumModels = 3) : array {
    $losses =[];
    $predictions =[];
    foreach ($groups as $group) {
        $preds = (array)($group['predictions']??[]);
        if (count($preds)<$minimumModels)continue;
        $weighted = 0.0;
        $total = 0.0;
        foreach ($preds as $model=>$prediction) {
            $weight = meteonexa_reliability_weight_value($weights, (string)$model);
            $weighted+=(float)$prediction * $weight;
            $total+=$weight;
        }
        if ($total<=0.0)continue;
        $prediction = meteonexa_intel_clamp($weighted / $total, 0, 1);
        $observed = (float)($group['observed']??0)>=.5 ? 1.0 : 0.0;
        $losses[] = meteonexa_intelq_brier($prediction, $observed);
        $predictions[] =['targetTime'=>$group['targetTime']??null,'prediction'=>$prediction,'observed'=>$observed];
    }
    $n = count($losses);
    return[
        'samples'=>$n,
        'brier'=>$n ? array_sum($losses) / $n : null,
        'calibrationGapPct'=>meteonexa_reliability_shadow_calibration_gap($predictions),
        'losses'=>$losses,
        'predictions'=>$predictions,
    ];
}
function meteonexa_reliability_shadow_window_wins(array $championLosses, array $challengerLosses, int $windows = 3) : array {
    $n = min(count($championLosses), count($challengerLosses));
    if ($n===0)return['wins'=>0,'windows'=>0,'recentNonRegression'=>false];
    $windows = max(1, min($windows, $n));
    $size =(int)ceil($n / $windows);
    $wins = 0;
    $evaluated = 0;
    $lastChampion = null;
    $lastChallenger = null;
    for ($start = 0; $start<$n; $start+=$size) {
        $c = array_slice($championLosses, $start, $size);
        $x = array_slice($challengerLosses, $start, $size);
        if (!$c||count($c)!==count($x))continue;
        $cAvg = array_sum($c) / count($c);
        $xAvg = array_sum($x) / count($x);
        if ($xAvg<=$cAvg)$wins++;
        $evaluated++;
        $lastChampion = $cAvg;
        $lastChallenger = $xAvg;
    }
    return['wins'=>$wins,'windows'=>$evaluated,'recentNonRegression'=>$lastChampion!==null&&$lastChallenger!==null&&$lastChallenger<=$lastChampion+.005];
}
function meteonexa_reliability_max_weight_share_pct(array $weights) : float {
    $usable = array_values(array_filter(array_map(static fn($v)=>is_numeric($v) ? max(0.0,(float)$v) : 0.0, $weights), static fn($v)=>$v>0.0));
    $sum = array_sum($usable);
    return $sum>0.0 ? 100 * max($usable) / $sum : 100.0;
}
function meteonexa_reliability_shadow_bucket(array $rows, string $metric, int $horizon, ?int $referenceTime = null) : array {
    $policy = meteonexa_reliability_shadow_policy();
    $groups = meteonexa_reliability_shadow_groups($rows, $metric, $horizon);
    if (count($groups)>(int)$policy['maximumTargetsPerBucket'])$groups = array_slice($groups, -(int)$policy['maximumTargetsPerBucket']);
    $holdoutCount = min(count($groups), min(30, max((int)$policy['minimumEvaluationSamples'], (int)floor(count($groups) * .30))));
    $trainingCount = count($groups) - $holdoutCount;
    if ($trainingCount<(int)$policy['minimumTrainingTargets']||$holdoutCount<(int)$policy['minimumEvaluationSamples']) {
        return['available'=>false,'metric'=>$metric,'horizonHours'=>$horizon,'trainingTargets'=>max(0,$trainingCount),'evaluationSamples'=>max(0,$holdoutCount),'promotionEligible'=>false,'promoted'=>false,'reason'=>'insufficient_shadow_history'];
    }
    $trainingGroups = array_slice($groups, 0, $trainingCount);
    $holdout = array_slice($groups, $trainingCount);
    $cutoff = (int)($holdout[0]['timestamp']??($referenceTime??time()));
    $trainingTargets = array_fill_keys(array_map(static fn($g)=>(string)$g['targetTime'], $trainingGroups), true);
    $trainingRows = array_values(array_filter($rows, static function($row)use($metric,$horizon,$trainingTargets){
        return (string)($row['metric']??'')===$metric&&(int)($row['horizon_hours']??0)===$horizon&&isset($trainingTargets[(string)($row['target_time']??'')]);
    }));
    $championAll = meteonexa_reliability_champion_weights_from_rows($trainingRows, $cutoff);
    $challengerAll = meteonexa_reliability_skill_weights_from_rows($trainingRows, $cutoff);
    $champion = (array)($championAll[$metric][$horizon]??[]);
    $challenger = (array)($challengerAll[$metric][$horizon]??[]);
    $championScore = meteonexa_reliability_shadow_score($holdout, $champion, (int)$policy['minimumModelsPerSample']);
    $challengerScore = meteonexa_reliability_shadow_score($holdout, $challenger, (int)$policy['minimumModelsPerSample']);
    $samples = min((int)$championScore['samples'], (int)$challengerScore['samples']);
    $coverage = $holdout ? 100 * $samples / count($holdout) : 0.0;
    $championBrier = is_numeric($championScore['brier']) ? (float)$championScore['brier'] : null;
    $challengerBrier = is_numeric($challengerScore['brier']) ? (float)$challengerScore['brier'] : null;
    $improvement = $championBrier!==null&&$challengerBrier!==null ? $championBrier - $challengerBrier : null;
    $relative = $improvement!==null&&$championBrier>0.0 ? 100 * $improvement / $championBrier : 0.0;
    $windows = meteonexa_reliability_shadow_window_wins((array)$championScore['losses'], (array)$challengerScore['losses'], (int)$policy['evaluationWindows']);
    $modelCount = count($challenger);
    $maxShare = meteonexa_reliability_max_weight_share_pct($challenger);
    $calibrationRegression = is_numeric($championScore['calibrationGapPct'])&&is_numeric($challengerScore['calibrationGapPct']) ? (float)$challengerScore['calibrationGapPct'] - (float)$championScore['calibrationGapPct'] : INF;
    $checks =[
        'trainingTargets'=>$trainingCount>=(int)$policy['minimumTrainingTargets'],
        'evaluationSamples'=>$samples>=(int)$policy['minimumEvaluationSamples'],
        'coverage'=>$coverage>=(float)$policy['minimumCoveragePct'],
        'models'=>$modelCount>=(int)$policy['minimumModelsPerSample'],
        'absoluteBrier'=>$improvement!==null&&$improvement>=(float)$policy['minimumAbsoluteBrierImprovement'],
        'relativeBrier'=>$relative>=(float)$policy['minimumRelativeBrierImprovementPct'],
        'calibration'=>$calibrationRegression<=(float)$policy['maximumCalibrationGapRegressionPct'],
        'weightConcentration'=>$maxShare<=(float)$policy['maximumSingleModelSharePct'],
        'stableWindows'=>(int)$windows['wins']>=(int)$policy['minimumStableWindows'],
        'recentNonRegression'=>!empty($windows['recentNonRegression']),
    ];
    $eligible = !in_array(false, $checks, true);
    $reason = 'eligible';
    foreach ($checks as $id=>$ok)if(!$ok){$reason='guardrail_' . $id;break;}
    return[
        'available'=>true,
        'metric'=>$metric,
        'horizonHours'=>$horizon,
        'season'=>meteonexa_reliability_season($cutoff + $horizon * 3600),
        'trainingTargets'=>$trainingCount,
        'evaluationSamples'=>$samples,
        'coveragePct'=>round($coverage, 1),
        'models'=>$modelCount,
        'championBrier'=>$championBrier===null ? null : round($championBrier, 4),
        'challengerBrier'=>$challengerBrier===null ? null : round($challengerBrier, 4),
        'absoluteBrierImprovement'=>$improvement===null ? null : round($improvement, 4),
        'relativeBrierImprovementPct'=>round($relative, 1),
        'championCalibrationGapPct'=>is_numeric($championScore['calibrationGapPct']) ? round((float)$championScore['calibrationGapPct'], 1) : null,
        'challengerCalibrationGapPct'=>is_numeric($challengerScore['calibrationGapPct']) ? round((float)$challengerScore['calibrationGapPct'], 1) : null,
        'challengerMaxModelSharePct'=>round($maxShare, 1),
        'stableWindowsWon'=>(int)$windows['wins'],
        'windowsEvaluated'=>(int)$windows['windows'],
        'recentNonRegression'=>!empty($windows['recentNonRegression']),
        'promotionEligible'=>$eligible,
        'promoted'=>$eligible,
        'reason'=>$reason,
        'checks'=>$checks,
    ];
}
function meteonexa_reliability_tournament_cache_key(string $device, string $location) : string {
    return 'reliability_cc_v1_' . substr(hash('sha256',$device . '|' . $location), 0, 40);
}
function meteonexa_reliability_tournament_cache_read(PDO $pdo, string $key) : ?array {
    if (!meteonexa_db_table_exists($pdo, 'app_metadata'))return null;
    try {
        $st = $pdo->prepare('SELECT meta_value,updated_at FROM app_metadata WHERE meta_key=:k LIMIT 1');
        $st->execute([':k'=>$key]);
        $row = $st->fetch();
        if (!$row)return null;
        $value = json_decode((string)$row['meta_value'], true);
        if (!is_array($value))return null;
        $value['_cacheUpdatedAt'] = $row['updated_at']??null;
        return $value;
    } catch (Throwable $e) {
        return null;
    }
}
function meteonexa_reliability_tournament_cache_write(PDO $pdo, string $key, array $value) : void {
    if (!meteonexa_db_table_exists($pdo, 'app_metadata'))return;
    try {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver==='mysql'
            ? 'INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:k,:v,:u) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=VALUES(updated_at)'
            : 'INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:k,:v,:u) ON CONFLICT(meta_key) DO UPDATE SET meta_value=excluded.meta_value,updated_at=excluded.updated_at';
        $pdo->prepare($sql)->execute([':k'=>$key,':v'=>json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':u'=>gmdate('c')]);
    } catch (Throwable $e) {
        // Cache persistence is advisory; never block forecast delivery.
    }
}
function meteonexa_reliability_tournament_weighting_meta(array $tournament) : array {
    return[
        'mode'=>(string)($tournament['mode']??'champion'),
        'champion'=>(string)($tournament['champion']??'recency-decay-skill-v2'),
        'challenger'=>(string)($tournament['challenger']??'seasonal-decayed-skill-shrunk'),
        'promotedBuckets'=>(int)($tournament['promotedBuckets']??0),
        'promotionPolicy'=>'automatic-per-bucket-after-out-of-sample-shadow-gates',
    ];
}
function meteonexa_reliability_weight_tournament(PDO $pdo, string $device, string $location, array $championWeights, ?int $referenceTime = null, bool $forceRefresh = false) : array {
    $policy = meteonexa_reliability_shadow_policy();
    $reference = $referenceTime ?? time();
    $fallback =['available'=>false,'mode'=>'champion','champion'=>'recency-decay-skill-v2','challenger'=>'seasonal-decayed-skill-shrunk','activeWeights'=>$championWeights,'challengerWeights'=>[],'promotedBuckets'=>0,'buckets'=>[],'policy'=>$policy,'generatedAt'=>gmdate('c',$reference)];
    if (!meteonexa_db_table_exists($pdo, 'model_skill_samples'))return $fallback;
    try {
        $fingerprintStmt = $pdo->prepare("SELECT COUNT(*) samples,MAX(verified_at) newest FROM model_skill_samples WHERE device_id=:d AND location_key=:l AND verified_at<>'' AND model_name<>'consensus' AND metric IN ('rain','storm','snow')");
        $fingerprintStmt->execute([':d'=>$device,':l'=>$location]);
        $fingerprint = $fingerprintStmt->fetch() ? :[];
        $sourceSamples = (int)($fingerprint['samples']??0);
        $sourceNewest = (string)($fingerprint['newest']??'');
        if ($sourceSamples<=0)return $fallback;
        $season = meteonexa_reliability_season($reference);
        $seasonSignature = implode('|', array_map(static fn($h)=>$h . ':' . meteonexa_reliability_season($reference + $h * 3600), [1,3,6,24,48,72]));
        $key = meteonexa_reliability_tournament_cache_key($device,$location);
        $cached = !$forceRefresh ? meteonexa_reliability_tournament_cache_read($pdo,$key) : null;
        $cachedAt = is_array($cached) ? strtotime((string)($cached['evaluatedAt']??$cached['_cacheUpdatedAt']??'')) : false;
        $cacheFresh = is_array($cached)
            &&($cached['version']??'')===$policy['version']
            &&(int)($cached['sourceSamples']??-1)===$sourceSamples
            &&(string)($cached['sourceNewestVerifiedAt']??'')===$sourceNewest
            &&(string)($cached['season']??'')===$season
            &&(string)($cached['seasonSignature']??'')===$seasonSignature
            &&$cachedAt!==false
            &&$cachedAt>=$reference-(int)$policy['cacheTtlSeconds'];
        if ($cacheFresh) {
            $challengerWeights = (array)($cached['challengerWeights']??[]);
            $active = $championWeights;
            $promoted = 0;
            foreach ((array)($cached['buckets']??[]) as $bucket) {
                if (empty($bucket['promoted']))continue;
                $metric = (string)($bucket['metric']??'');
                $h = (int)($bucket['horizonHours']??0);
                if (!isset($challengerWeights[$metric][$h]))continue;
                $active[$metric][$h] = $challengerWeights[$metric][$h];
                $promoted++;
            }
            return array_replace($fallback,['available'=>true,'mode'=>$promoted>0 ? 'guarded-challenger' : 'champion','activeWeights'=>$active,'challengerWeights'=>$challengerWeights,'promotedBuckets'=>$promoted,'buckets'=>(array)($cached['buckets']??[]),'sourceSamples'=>$sourceSamples,'sourceNewestVerifiedAt'=>$sourceNewest,'cacheHit'=>true,'evaluatedAt'=>$cached['evaluatedAt']??null]);
        }
        $st = $pdo->prepare("SELECT model_name,metric,horizon_hours,target_time,predicted_value,observed_value,error_value,brier_score,verified_at FROM model_skill_samples WHERE device_id=:d AND location_key=:l AND verified_at<>'' AND model_name<>'consensus' AND metric IN ('rain','storm','snow') ORDER BY verified_at DESC LIMIT 15000");
        $st->execute([':d'=>$device,':l'=>$location]);
        $rows = $st->fetchAll();
        $challengerWeights = meteonexa_reliability_skill_weights_from_rows($rows, $reference);
        $buckets =[];
        $active = $championWeights;
        $promoted = 0;
        foreach (['rain','storm','snow'] as $metric) foreach ([1,3,6,24,48,72] as $h) {
            $bucket = meteonexa_reliability_shadow_bucket($rows,$metric,$h,$reference);
            $buckets[] = $bucket;
            if (empty($bucket['promoted'])||!isset($challengerWeights[$metric][$h]))continue;
            $active[$metric][$h] = $challengerWeights[$metric][$h];
            $promoted++;
        }
        $evaluatedAt = gmdate('c',$reference);
        $cacheValue =['version'=>$policy['version'],'season'=>$season,'seasonSignature'=>$seasonSignature,'sourceSamples'=>$sourceSamples,'sourceNewestVerifiedAt'=>$sourceNewest,'challengerWeights'=>$challengerWeights,'buckets'=>$buckets,'evaluatedAt'=>$evaluatedAt];
        meteonexa_reliability_tournament_cache_write($pdo,$key,$cacheValue);
        return array_replace($fallback,['available'=>true,'mode'=>$promoted>0 ? 'guarded-challenger' : 'champion','activeWeights'=>$active,'challengerWeights'=>$challengerWeights,'promotedBuckets'=>$promoted,'buckets'=>$buckets,'sourceSamples'=>$sourceSamples,'sourceNewestVerifiedAt'=>$sourceNewest,'cacheHit'=>false,'evaluatedAt'=>$evaluatedAt]);
    } catch (Throwable $e) {
        return $fallback;
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
                // Missing evidence is neutral relative to the available bucket,
                // never an implicit hard-coded weight of 1 that could dominate
                // a normalized challenger profile.
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
