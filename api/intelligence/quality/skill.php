<?php
declare(strict_types=1);
function meteonexa_intelq_canonical_consensus(array $models) : array {
    $fresh = array_filter($models, static fn(array $model) : bool=>!empty($model['available'])&&empty($model['stale']));
    $available = array_filter($models, static fn(array $model) : bool=>!empty($model['available']));
    $useFresh = count($fresh)>=3;
    $selected = $useFresh ? $fresh : $available;
    $consensus = meteonexa_intelq_consensus($selected);
    $consensus['sourceMode'] = $useFresh ? 'fresh_consensus' :(count($available)>=2 ? 'stale_fallback' : 'insufficient_models');
    $consensus['degraded'] = !$useFresh;
    $consensus['freshModelsAvailable'] = count($fresh);
    $consensus['modelsAvailable'] = count($available);
    $consensus['modelsExpected'] = count(meteonexa_intelq_model_definitions());
    return $consensus;
}
function meteonexa_intelq_model_at_target(array $model, int $target) : ? array {
    $best = null;
    $delta = PHP_INT_MAX;
    foreach ((array)($model['rows']??[]) as $row) {
        $ts = (int)($row['timestamp']??0);
        $d = abs($ts - $target);
        if ($d < $delta) {
            $delta = $d;
            $best = $row;
        }
    }
    return $delta<=2700&&is_array($best) ? $best : null;
}
function meteonexa_intelq_brier(float $probability, float $outcome) : float {
    $p = meteonexa_intel_clamp($probability, 0, 1);
    $o = $outcome>=.5 ? 1.0 : 0.0;
    return($p - $o)**2;
}
function meteonexa_intelq_skill_summary(PDO $pdo, string $deviceId, string $locationKey) : array {
    if (!meteonexa_db_table_exists($pdo, 'model_skill_samples'))return['available'=>false, 'verifiedSamples'=>0, 'byMetricHorizon'=>[], 'brier'=>null];
    try {
        $sql = "SELECT model_name,metric,horizon_hours,COUNT(*) samples,AVG(error_value) mae,AVG(brier_score) brier
              FROM model_skill_samples WHERE device_id=:device AND location_key=:location AND verified_at<>''
              GROUP BY model_name,metric,horizon_hours ORDER BY metric,horizon_hours,model_name";
        $st = $pdo->prepare($sql);
        $st->execute([':device'=>$deviceId, ':location'=>$locationKey]);
        $rows = $st->fetchAll();
        $groups =[];
        $total = 0;
        $brierSum = 0.0;
        $brierN = 0;
        foreach ($rows as $row) {
            $metric = (string)$row['metric'];
            $h = (string)(int)$row['horizon_hours'];
            $samples = (int)$row['samples'];
            $total+=$samples;
            $level = function_exists('meteonexa_reliability_sample_level') ? meteonexa_reliability_sample_level($samples) :['id'=>($samples>=100 ? 'consolidated' :($samples>=30 ? 'building' :($samples>=10 ? 'preliminary' : 'initial'))), 'publishable'=>$samples>=30];
            $entry =['model'=>(string)$row['model_name'], 'samples'=>$samples, 'calibrationLevel'=>$level['id'], 'eligibleForRanking'=>!empty($level['publishable'])];
            if (in_array($metric,['rain', 'storm', 'snow'], true)) {
                $entry['brier'] = round((float)$row['brier'], 4);
                $brierSum+=(float)$row['brier'] * $samples;
                $brierN+=$samples;
            } else $entry['mae'] = round((float)$row['mae'], 2);
            $groups[$metric][$h][] = $entry;
        }
        $best =[];
        foreach ($groups as $metric=>$horizons) foreach ($horizons as $h=>$entries) {
            usort($entries, static function($a, $b)use($metric) {
                $field = in_array($metric,['rain', 'storm', 'snow'], true) ? 'brier' : 'mae'; return($a[$field]??INF)<=>($b[$field]??INF);
            });
            $groups[$metric][$h] = $entries;
            $eligible = array_values(array_filter($entries, static fn($e)=>!empty($e['eligibleForRanking'])));
            $best[$metric][$h] = $eligible[0]['model']??null;
        }
        return['available'=>$rows!==[], 'verifiedSamples'=>$total, 'byMetricHorizon'=>$groups, 'best'=>$best, 'brier'=>$brierN ? round($brierSum / $brierN, 4) : null, 'leadHours'=>[1, 3, 6, 24, 48, 72]];
    } catch (Throwable $ignored) {
        return['available'=>false, 'verifiedSamples'=>0, 'byMetricHorizon'=>[], 'brier'=>null];
    }
}
function meteonexa_intelq_snapshot_from_consensus(array $consensus, array $analysis) : array {
    $primary = (array)($consensus['primary']??[]);
    $metrics = (array)($analysis['metrics']??[]);
    return['type'=>$primary['type']??null, 'startsAt'=>$primary['startsAt']??null, 'endsAt'=>$primary['endsAt']??null, 'agreementPct'=>(int)($primary['agreementPct']??0), 'votes'=>(int)($primary['votes']??0), 'available'=>(int)($primary['available']??0), 'rainProbability'=>(int)($metrics['rainProbabilityWindow']??$metrics['rainProbability']??0), 'severity'=>(string)($analysis['severity']??'green'), 'confidence'=>(int)($analysis['confidence']??0),];
}
function meteonexa_intelq_compare_snapshots( ? array $previous, array $current) : array {
    if (!$previous)return['available'=>false, 'changed'=>false, 'stabilityPct'=>null];
    $prevStart = meteonexa_intel_time_utc($previous['startsAt']??'');
    $curStart = meteonexa_intel_time_utc($current['startsAt']??'');
    $shift =($prevStart!==null&&$curStart!==null) ? (int)round(($curStart - $prevStart) / 60) : null;
    $rainDelta = (int)($current['rainProbability']??0) - (int)($previous['rainProbability']??0);
    $agreementDelta = (int)($current['agreementPct']??0) - (int)($previous['agreementPct']??0);
    $typeChanged = (string)($previous['type']??'')!==(string)($current['type']??'');
    $changed = $typeChanged||($shift!==null&&abs($shift)>=60)||abs($rainDelta)>=15||abs($agreementDelta)>=15;
    $penalty =($typeChanged ? 35 : 0) + min(30, abs((int)($shift??0)) / 6) + min(20, abs($rainDelta)) * .5 + min(15, abs($agreementDelta)) * .4;
    return['available'=>true, 'changed'=>$changed, 'typeChanged'=>$typeChanged, 'timeShiftMinutes'=>$shift, 'rainProbabilityDelta'=>$rainDelta, 'agreementDelta'=>$agreementDelta, 'previous'=>$previous, 'current'=>$current, 'stabilityPct'=>(int)round(meteonexa_intel_clamp(100 - $penalty, 0, 100))];
}
function meteonexa_intelq_persist_run_snapshot(PDO $pdo, string $deviceId, string $locationKey, array $snapshot) : array {
    if (!meteonexa_db_table_exists($pdo, 'forecast_run_snapshots'))return['available'=>false, 'changed'=>false];
    try {
        $st = $pdo->prepare('SELECT snapshot_json,created_at FROM forecast_run_snapshots WHERE device_id=:device AND location_key=:location ORDER BY id DESC LIMIT 1');
        $st->execute([':device'=>$deviceId, ':location'=>$locationKey]);
        $row = $st->fetch();
        $previous = null;
        if (is_array($row))$previous = json_decode((string)$row['snapshot_json'], true) ? : null;
        $comparison = meteonexa_intelq_compare_snapshots(is_array($previous) ? $previous : null, $snapshot);
        $lastTs = is_array($row) ?(strtotime((string)$row['created_at']) ? : 0) : 0;
        if ($lastTs < time() - 600) {
            $ins = $pdo->prepare('INSERT INTO forecast_run_snapshots(device_id,location_key,snapshot_json,created_at) VALUES(:device,:location,:json,:created)');
            $ins->execute([':device'=>$deviceId, ':location'=>$locationKey, ':json'=>json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':created'=>gmdate('c')]);
            
            $ids = $pdo->prepare('SELECT id FROM forecast_run_snapshots WHERE device_id=:device AND location_key=:location ORDER BY id DESC');
            $ids->execute([':device'=>$deviceId, ':location'=>$locationKey]);
            $allIds = array_map('intval', array_column($ids->fetchAll(), 'id'));
            $drop = array_slice($allIds, 48);
            if ($drop) {
                $placeholders = implode(',', array_fill(0, count($drop), '?'));
                $pdo->prepare('DELETE FROM forecast_run_snapshots WHERE id IN (' . $placeholders . ')')->execute($drop);
            }
        }
        return $comparison;
    } catch (Throwable $ignored) {
        return['available'=>false, 'changed'=>false];
    }
}
function meteonexa_intelq_source_freshness(array $models, array $motion, array $lightning, array $satellite, array $official, array $hyperlocal) : array {
    $sources =[];
    foreach ($models as $model) {
        $sources[] =['id'=>(string)$model['id'], 'label'=>(string)$model['label'], 'available'=>(bool)$model['available'], 'retrievedAt'=>$model['retrievedAt']??null, 'ageMinutes'=>$model['ageMinutes']??null, 'fetchAgeMinutes'=>$model['fetchAgeMinutes']??null, 'cacheAgeMinutes'=>$model['cacheAgeMinutes']??null, 'modelRunEstimatedAt'=>$model['modelRunEstimatedAt']??null, 'modelRunAgeMinutes'=>$model['modelRunAgeMinutes']??null, 'runTimeEstimated'=>!empty($model['runTimeEstimated']), 'cacheHit'=>!empty($model['cacheHit']), 'providerLatencyMs'=>$model['providerLatencyMs']??null, 'cadenceMinutes'=>$model['cadenceMinutes']??null, 'freshnessBasis'=>'model-cycle+retrieval', 'kind'=>'model'];
    }
    $sources[] =['id'=>'radar', 'label'=>'Radar', 'available'=>(bool)($motion['available']??false), 'retrievedAt'=>$motion['latestFrameAt']??$motion['observedAt']??null, 'ageMinutes'=>$motion['ageMinutes']??null, 'freshnessBasis'=>'observation', 'kind'=>'observation'];
    $lightningAt = $lightning['observedAt']??null;
    $lightningTs = meteonexa_intel_time_utc($lightningAt??'');
    $sources[] =['id'=>'lightning', 'label'=>'Lightning', 'available'=>(bool)($lightning['available']??false), 'retrievedAt'=>$lightningAt, 'ageMinutes'=>$lightningTs===null ? null : max(0, (int)round((time() - $lightningTs) / 60)), 'freshnessBasis'=>'observation', 'kind'=>'observation'];
    $satAt = $satellite['observedAt']??null;
    $satTs = meteonexa_intel_time_utc($satAt??'');
    $sources[] =['id'=>'satellite', 'label'=>'Satellite', 'available'=>(bool)($satellite['available']??false), 'retrievedAt'=>$satAt, 'ageMinutes'=>$satTs===null ? null : max(0, (int)round((time() - $satTs) / 60)), 'freshnessBasis'=>'observation', 'kind'=>'observation'];
    $officialAt = $official['relevant'][0]['updatedAt']??$official['generatedAt']??null;
    $officialTs = meteonexa_intel_time_utc($officialAt??'');
    $sources[] =['id'=>'official', 'label'=>'MeteoAlarm', 'available'=>(bool)($official['available']??true), 'retrievedAt'=>$officialAt, 'ageMinutes'=>$officialTs===null ? null : max(0, (int)round((time() - $officialTs) / 60)), 'mode'=>$official['mode']??'atom', 'freshnessBasis'=>isset($official['relevant'][0]['updatedAt']) ? 'warning-update' : 'retrieval', 'kind'=>'official'];
    $hyperAt = $hyperlocal['observation']['observedAt']??$hyperlocal['observation']['time']??null;
    if (is_numeric($hyperAt))$hyperAt = gmdate('c', (int)$hyperAt);
    $hyperTs = meteonexa_intel_time_utc($hyperAt??'');
    $sources[] =['id'=>'hyperlocal', 'label'=>'Hyperlocal', 'available'=>(bool)($hyperlocal['available']??false), 'retrievedAt'=>$hyperAt, 'ageMinutes'=>$hyperTs===null ? null : max(0, (int)round((time() - $hyperTs) / 60)), 'freshnessBasis'=>'observation', 'kind'=>'observation'];
    return $sources;
}
function meteonexa_intelq_explainability(array $analysis, array $consensus, array $motion, array $lightning, array $satellite, array $official, array $hyperlocal, array $skill) : array {
    $parts =[];
    $score = 0;
    $agreement = (int)($consensus['primary']['agreementPct']??0);
    if ($agreement > 0) {
        $c = (int)round($agreement * .45);
        $parts[] =['source'=>'models', 'contribution'=>$c, 'status'=>$agreement>=60 ? 'support' : 'conflict', 'detail'=>['agreementPct'=>$agreement, 'votes'=>$consensus['primary']['votes']??0, 'available'=>$consensus['primary']['available']??0]];
        $score+=$c;
    }
    if (!empty($motion['available'])) {
        $c = (int)round(min(20, (float)($motion['confidence']??0) * .20));
        $parts[] =['source'=>'radar', 'contribution'=>$c, 'status'=>'support'];
        $score+=$c;
    }
    if (!empty($lightning['available'])&&(int)($lightning['recent30m']??0) > 0) {
        $c = min(15, 5 + (int)($lightning['recent30m']??0));
        $parts[] =['source'=>'lightning', 'contribution'=>$c, 'status'=>'support'];
        $score+=$c;
    }
    if (!empty($official['relevant'])) {
        $parts[] =['source'=>'official', 'contribution'=>15, 'status'=>'support'];
        $score+=15;
    }
    if (!empty($satellite['available'])) {
        $c = (int)round(min(5, (float)($satellite['support']??0) * 5));
        $parts[] =['source'=>'satellite', 'contribution'=>$c, 'status'=>'context'];
        $score+=$c;
    }
    if (!empty($hyperlocal['available'])) {
        $parts[] =['source'=>'hyperlocal', 'contribution'=>8, 'status'=>'context'];
        $score+=8;
    }
    if (!empty($skill['available'])) {
        $b = $skill['brier'];
        $parts[] =['source'=>'historicalSkill', 'contribution'=>0, 'status'=>'calibration', 'detail'=>['brier'=>$b, 'samples'=>$skill['verifiedSamples']??0]];
    }
    return['method'=>'deterministic-weighted-evidence', 'parts'=>$parts, 'evidenceScore'=>(int)round(meteonexa_intel_clamp($score, 0, 100)), 'displayConfidence'=>(int)($analysis['confidence']??0)];
}
