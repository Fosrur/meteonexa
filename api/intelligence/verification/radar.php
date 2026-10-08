<?php
declare(strict_types=1);
function meteonexa_verification_now_iso() : string {
    return gmdate('c');
}
function meteonexa_verification_ts(mixed $v) : ? int {
    $s = trim((string)$v);
    if ($s==='')return null;
    $t = strtotime($s);
    return $t===false ? null : $t;
}
function meteonexa_verification_clamp(float $value, float $min, float $max) : float {
    return max($min, min($max, $value));
}
function meteonexa_record_runtime_metric(PDO $pdo, string $category, string $name, string $status = 'ok', ? float $value = null, array $meta =[], ? float $durationMs = null) : void {
    if (!meteonexa_db_table_exists($pdo, 'runtime_metrics'))return;
    $trace = function_exists('meteonexa_request_id') ? meteonexa_request_id() : '';
    try {
        if (meteonexa_db_column_exists($pdo, 'runtime_metrics', 'trace_id')) {
            $st = $pdo->prepare('INSERT INTO runtime_metrics(category,metric_name,status,metric_value,meta_json,trace_id,duration_ms,created_at) VALUES(:c,:n,:s,:v,:m,:t,:d,:a)');
            $st->execute([':c'=>substr($category, 0, 40), ':n'=>substr($name, 0, 80), ':s'=>substr($status, 0, 24), ':v'=>$value, ':m'=>json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':t'=>$trace, ':d'=>$durationMs, ':a'=>meteonexa_verification_now_iso()]);
        } else {
            $st = $pdo->prepare('INSERT INTO runtime_metrics(category,metric_name,status,metric_value,meta_json,created_at) VALUES(:c,:n,:s,:v,:m,:a)');
            $st->execute([':c'=>substr($category, 0, 40), ':n'=>substr($name, 0, 80), ':s'=>substr($status, 0, 24), ':v'=>$value, ':m'=>json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':a'=>meteonexa_verification_now_iso()]);
        }
    } catch (Throwable $ignored) {
    }
}






function meteonexa_radar_independent_arrival(array $observations, array $radarObservation =[], array $hyperlocal =[]) : array {
    $candidates =[];
    $now = time();
    $radarAt = meteonexa_verification_ts($radarObservation['observedAt']??'');
    $radarDistance = is_numeric($radarObservation['archiveDistanceKm']??null) ? (float)$radarObservation['archiveDistanceKm'] : 999.0;
    $center = is_numeric($radarObservation['centerSignal']??null) ? (float)$radarObservation['centerSignal'] : null;
    if ($radarAt!==null&&$center!==null&&$radarDistance<=12&&$now - $radarAt<=1200) {
        $quality = (int)round(meteonexa_verification_clamp(94 - $radarDistance * 2 - max(0,($now - $radarAt) / 120), 55, 98));
        $candidates[] =['available'=>true, 'arrived'=>$center>=.18, 'observedAt'=>gmdate('c', $radarAt), 'source'=>'radar-center-observation', 'quality'=>$quality, 'detail'=>['centerSignal'=>round($center, 3), 'distanceKm'=>round($radarDistance, 1)]];
    }
    foreach ((array)($observations['evidence']??[]) as $ev) {
        if (empty($ev['independentFromNwp']))continue;
        $ts = meteonexa_verification_ts($ev['observedAt']??'');
        if ($ts===null||$now - $ts > 10800)continue;
        $distance = is_numeric($ev['distanceKm']??null) ? (float)$ev['distanceKm'] : 999.0;
        $quality = (int)($ev['qualityScore']??0);
        if ($distance > 30||$quality < 55)continue;
        $rain =(is_numeric($ev['rain']??null)&&(float)$ev['rain']>=.5)||(is_numeric($ev['storm']??null)&&(float)$ev['storm']>=.5)||(is_numeric($ev['precipitation']??null)&&(float)$ev['precipitation']>=.1);
        $candidates[] =['available'=>true, 'arrived'=>$rain, 'observedAt'=>gmdate('c', $ts), 'source'=>'observation-' . substr((string)($ev['sourceType']??'station'), 0, 30), 'quality'=>$quality, 'detail'=>['distanceKm'=>round($distance, 1), 'rain'=>$ev['rain']??null, 'storm'=>$ev['storm']??null, 'precipitation'=>$ev['precipitation']??null]];
    }
    $hyperObs = (array)($hyperlocal['observation']??[]);
    $hyperTs = meteonexa_verification_ts($hyperObs['observedAt']??$hyperObs['time']??'');
    if ($hyperTs!==null&&$now - $hyperTs<=3600) {
        $rain =(is_numeric($hyperObs['rain']??null)&&(float)$hyperObs['rain'] > .05)||(is_numeric($hyperObs['precipitation']??null)&&(float)$hyperObs['precipitation'] > .05);
        $candidates[] =['available'=>true, 'arrived'=>$rain, 'observedAt'=>gmdate('c', $hyperTs), 'source'=>'hyperlocal-observation', 'quality'=>80, 'detail'=>['rain'=>$hyperObs['rain']??null, 'precipitation'=>$hyperObs['precipitation']??null]];
    }
    if (!$candidates)return['available'=>false, 'arrived'=>false, 'source'=>'none', 'quality'=>0, 'observedAt'=>null];
    usort($candidates, static fn($a, $b)=>($b['quality']<=>$a['quality']) ? :((meteonexa_verification_ts($b['observedAt'])??0)<=>(meteonexa_verification_ts($a['observedAt'])??0)));
    
    
    
    $best = $candidates[0];
    foreach ($candidates as $candidate) {
        if (!empty($candidate['arrived'])&&(int)$candidate['quality']>=(int)$best['quality'] - 12) {
            $best = $candidate;
            break;
        }
    }
    $best['candidateCount'] = count($candidates);
    return $best;
}
function meteonexa_radar_eta_queue(PDO $pdo, string $deviceId, string $locationKey, string $algorithm, ? int $eta, int $confidence, int $tolerance, int $now, array $meta =[]) : void {
    if ($eta===null||$eta < 1||$eta > 180||$confidence < 40)return;
    try {
        $pred = gmdate('c', $now + $eta * 60);
        $issued = gmdate('c', $now);
        $key = substr(hash('sha256', $deviceId . '|' . $locationKey . '|' . $algorithm . '|' . gmdate('Y-m-d\TH:i', $now) . '|' . $eta), 0, 40);
        $columns = 'device_id,location_key,prediction_key,issued_at,predicted_at,eta_minutes,tolerance_minutes,confidence,status,verified_at,observed_at,error_minutes,absolute_error_minutes,created_at';
        $values = ':d,:l,:k,:i,:p,:e,:t,:c,\'pending\',\'\',\'\',NULL,NULL,:a';
        if (meteonexa_db_column_exists($pdo, 'radar_eta_predictions', 'algorithm')) {
            $columns.=',algorithm,ground_truth_source,ground_truth_quality,ground_truth_json,verification_method';
            $values.=',:alg,\'\',0,:gt,\'\'';
        }
        $hasP33 = meteonexa_db_column_exists($pdo, 'radar_eta_predictions', 'area_key');
        if ($hasP33) {
            $columns.=',area_key,distance_band,coverage_band,season,weather_regime,terrain_class';
            $values.=',:area,:distance,:coverage,:season,:regime,:terrain';
        }
        $insertVerb = meteonexa_pdo_driver($pdo)==='mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $st = $pdo->prepare($insertVerb . ' INTO radar_eta_predictions(' . $columns . ') VALUES(' . $values . ')');
        $params =[':d'=>$deviceId, ':l'=>$locationKey, ':k'=>$key, ':i'=>$issued, ':p'=>$pred, ':e'=>$eta, ':t'=>$tolerance, ':c'=>$confidence, ':a'=>$issued];
        if (str_contains($values, ':alg')) {
            $params[':alg'] = $algorithm;
            $params[':gt'] = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($hasP33) {
            $params[':area'] = (string)($meta['areaKey'] ?? '');
            $params[':distance'] = (string)($meta['distanceBand'] ?? 'unknown');
            $params[':coverage'] = (string)($meta['coverageBand'] ?? 'unknown');
            $params[':season'] = (string)($meta['season'] ?? 'unknown');
            $params[':regime'] = (string)($meta['weatherRegime'] ?? 'unknown');
            $params[':terrain'] = (string)($meta['terrainClass'] ?? 'unknown');
        }
        $st->execute($params);
    } catch (Throwable $ignored) {
    }
}
function meteonexa_radar_skill_update(PDO $pdo, string $deviceId, string $locationKey, array $nowcast, array $fusion, array $observations =[], array $radarObservation =[], array $hyperlocal =[], array $radar3 =[], array $radar4 =[], array $calibrationContext =[]) : array {
    if (!meteonexa_db_table_exists($pdo, 'radar_eta_predictions'))return['available'=>false, 'samples'=>0, 'groundTruth'=>'unavailable'];
    $now = time();
    $eta = is_numeric($nowcast['etaMinutes']??null) ? (int)$nowcast['etaMinutes'] : null;
    $confidence = is_numeric($nowcast['confidence']??null) ? (int)$nowcast['confidence'] : 0;
    $range = is_array($nowcast['etaRangeMinutes']??null) ? array_values($nowcast['etaRangeMinutes']) :[];
    $tol = 10;
    if (isset($range[0], $range[1])&&is_numeric($range[0])&&is_numeric($range[1]))$tol = max(5, min(45, (int)ceil(abs((float)$range[1] - (float)$range[0]) / 2)));
    meteonexa_radar_eta_queue($pdo, $deviceId, $locationKey, 'radar-v2', $eta, $confidence, $tol, $now,array_merge($calibrationContext, ['method'=>$nowcast['method']??'']));
    if (!empty($radar3['available'])) {
        $dominant = null;
        foreach ((array)($radar3['cells']??[]) as $cell) {
            if (is_numeric($cell['etaMinutes']??null)) {
                if ($dominant===null||(float)($cell['energy']??0) > (float)($dominant['energy']??0))$dominant = $cell;
            }
        }
        if ($dominant) {
            $eta3 = (int)$dominant['etaMinutes'];
            $conf3 = (int)($dominant['trackConfidence']??0);
            $tol3 = max(5, min(45, (int)round(6 + $eta3 *(1 - $conf3 / 100) * .35)));
            meteonexa_radar_eta_queue($pdo, $deviceId, $locationKey, 'radar-v3', $eta3, $conf3, $tol3, $now,array_merge($calibrationContext, ['cellId'=>$dominant['id']??null, 'mode'=>$radar3['mode']??'shadow']));
        }
    }
    if (!empty($radar4['available']) && (($radar4['mode']??'shadow')==='shadow')) {
        $dominant4 = null;
        foreach ((array)($radar4['cells']??[]) as $cell) {
            if (!is_numeric($cell['etaMinutes']??null))continue;
            if ($dominant4===null||(float)($cell['energy']??0) > (float)($dominant4['energy']??0))$dominant4 = $cell;
        }
        if ($dominant4) {
            $eta4 = (int)$dominant4['etaMinutes'];
            $conf4 = (int)($dominant4['trackConfidence']??0);
            $spread4 = is_numeric($radar4['flow']['vectorSpread']??null) ? (float)$radar4['flow']['vectorSpread'] : 0.0;
            $tol4 = max(5, min(50, (int)round(6 + $eta4 *(1 - $conf4 / 100) * .4 + min(8, $spread4 * 30))));
            meteonexa_radar_eta_queue($pdo, $deviceId, $locationKey, 'radar-v4', $eta4, $conf4, $tol4, $now,array_merge($calibrationContext, [
                'cellId'=>$dominant4['id']??null,
                'mode'=>'shadow',
                'method'=>$radar4['method']??'optical-flow-object-tracking-v4',
                'growthDecayScore'=>$dominant4['growthDecayScore']??null,
                'flowConfidence'=>$radar4['flow']['confidence']??null,
            ]));
        }
    }
    $ground = meteonexa_radar_independent_arrival($observations, $radarObservation, $hyperlocal);
    try {
        if (!empty($ground['available'])) {
            $obsTs = meteonexa_verification_ts($ground['observedAt']??'')??$now;
            if (!empty($ground['arrived'])) {
                $from = gmdate('c', $obsTs - 7200);
                $to = gmdate('c', $obsTs + 1800);
                $st = $pdo->prepare("SELECT id,predicted_at FROM radar_eta_predictions WHERE device_id=:d AND location_key=:l AND status='pending' AND predicted_at>=:f AND predicted_at<=:t ORDER BY id DESC LIMIT 60");
                $st->execute([':d'=>$deviceId, ':l'=>$locationKey, ':f'=>$from, ':t'=>$to]);
                foreach ($st->fetchAll() as $r) {
                    $pt = meteonexa_verification_ts($r['predicted_at']??'');
                    if ($pt===null)continue;
                    $err = round(($obsTs - $pt) / 60, 1);
                    if (meteonexa_db_column_exists($pdo, 'radar_eta_predictions', 'ground_truth_source')) {
                        $up = $pdo->prepare("UPDATE radar_eta_predictions SET status='verified',verified_at=:v,observed_at=:o,error_minutes=:e,absolute_error_minutes=:a,ground_truth_source=:gs,ground_truth_quality=:gq,ground_truth_json=:gj,verification_method='independent-observation' WHERE id=:id AND status='pending'");
                        $up->execute([':v'=>gmdate('c', $now), ':o'=>gmdate('c', $obsTs), ':e'=>$err, ':a'=>abs($err), ':gs'=>(string)$ground['source'], ':gq'=>(int)$ground['quality'], ':gj'=>json_encode($ground, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':id'=>(int)$r['id']]);
                    } else {
                        $up = $pdo->prepare("UPDATE radar_eta_predictions SET status='verified',verified_at=:v,observed_at=:o,error_minutes=:e,absolute_error_minutes=:a WHERE id=:id AND status='pending'");
                        $up->execute([':v'=>gmdate('c', $now), ':o'=>gmdate('c', $obsTs), ':e'=>$err, ':a'=>abs($err), ':id'=>(int)$r['id']]);
                    }
                }
            }
            
            
            
            if (empty($ground['arrived'])&&(int)($ground['quality']??0)>=65) {
                $cut = gmdate('c', $obsTs - 2700);
                $st = $pdo->prepare("SELECT id,predicted_at,tolerance_minutes FROM radar_eta_predictions WHERE device_id=:d AND location_key=:l AND status='pending' AND predicted_at<:cut ORDER BY id ASC LIMIT 80");
                $st->execute([':d'=>$deviceId, ':l'=>$locationKey, ':cut'=>$cut]);
                foreach ($st->fetchAll() as $r) {
                    $pt = meteonexa_verification_ts($r['predicted_at']??'');
                    $t = (int)($r['tolerance_minutes']??10);
                    if ($pt===null||$obsTs < $pt + $t * 60)continue;
                    $extra = meteonexa_db_column_exists($pdo, 'radar_eta_predictions', 'ground_truth_source') ? ",ground_truth_source=:gs,ground_truth_quality=:gq,ground_truth_json=:gj,verification_method='independent-negative'" : '';
                    $up = $pdo->prepare("UPDATE radar_eta_predictions SET status='missed',verified_at=:v,observed_at=:o" . $extra . " WHERE id=:id AND status='pending'");
                    $params =[':v'=>gmdate('c', $now), ':o'=>gmdate('c', $obsTs), ':id'=>(int)$r['id']];
                    if ($extra!=='') {
                        $params[':gs'] = (string)$ground['source'];
                        $params[':gq'] = (int)$ground['quality'];
                        $params[':gj'] = json_encode($ground, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                    $up->execute($params);
                }
            }
        }
        $expire = $pdo->prepare("UPDATE radar_eta_predictions SET status='expired_unverified',verified_at=:v WHERE device_id=:d AND location_key=:l AND status='pending' AND predicted_at<:cut");
        $expire->execute([':v'=>gmdate('c', $now), ':d'=>$deviceId, ':l'=>$locationKey, ':cut'=>gmdate('c', $now - 10800)]);
        $algorithms =[];
        $algorithmColumn = meteonexa_db_column_exists($pdo, 'radar_eta_predictions', 'algorithm');
        $sql = "SELECT " .($algorithmColumn ? "algorithm," : "'radar-v2' algorithm,") . "absolute_error_minutes,tolerance_minutes FROM radar_eta_predictions WHERE device_id=:d AND location_key=:l AND status='verified' AND absolute_error_minutes IS NOT NULL ORDER BY id DESC LIMIT 2000";
        $st = $pdo->prepare($sql);
        $st->execute([':d'=>$deviceId, ':l'=>$locationKey]);
        foreach ($st->fetchAll() as $r) {
            $alg = (string)($r['algorithm'] ? : 'radar-v2');
            $algorithms[$alg]['errors'][] = (float)$r['absolute_error_minutes'];
            $algorithms[$alg]['within'][] =((float)$r['absolute_error_minutes']<=(float)$r['tolerance_minutes']) ? 1 : 0;
        }
        foreach ($algorithms as $alg=>&$data) {
            sort($data['errors']);
            $n = count($data['errors']);
            $data =['samples'=>$n, 'maeMinutes'=>$n ? round(array_sum($data['errors']) / $n, 1) : null, 'medianMinutes'=>$n ? round($data['errors'][(int)floor(($n - 1) / 2)], 1) : null, 'p90Minutes'=>$n ? round($data['errors'][(int)floor(($n - 1) * .9)], 1) : null, 'withinTolerancePct'=>$n ? round(100 * array_sum($data['within']) / $n, 1) : null, 'learning'=>$n < 20];
        }
        unset($data);
        $primary = $algorithms['radar-v2']??['samples'=>0, 'maeMinutes'=>null, 'withinTolerancePct'=>null, 'learning'=>true];
        return['available'=>($primary['samples']??0) > 0, 'samples'=>(int)($primary['samples']??0), 'maeMinutes'=>$primary['maeMinutes']??null, 'medianMinutes'=>$primary['medianMinutes']??null, 'p90Minutes'=>$primary['p90Minutes']??null, 'withinTolerancePct'=>$primary['withinTolerancePct']??null, 'learning'=>!empty($primary['learning']), 'algorithms'=>$algorithms, 'groundTruth'=>$ground];
    } catch (Throwable $ignored) {
        return['available'=>false, 'samples'=>0, 'groundTruth'=>$ground];
    }
}
