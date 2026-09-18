<?php
declare(strict_types=1);
/** MeteoNexa verified-precision helpers. */
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
/**
 * Return an arrival observation that is independent from ETA/fusion scoring.
 * The trajectory model is never accepted as its own ground truth. Evidence is
 * either the observed radar pixel above the archived point or a subsequent
 * independent station/hyperlocal observation with adequate proximity/quality.
 */
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
    // Positive observations have precedence when their quality is close to the
    // best negative observation; this avoids hiding a real arrival because a
    // nearby source reports at a slightly different minute.
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
        $st = $pdo->prepare('INSERT OR IGNORE INTO radar_eta_predictions(' . $columns . ') VALUES(' . $values . ')');
        $params =[':d'=>$deviceId, ':l'=>$locationKey, ':k'=>$key, ':i'=>$issued, ':p'=>$pred, ':e'=>$eta, ':t'=>$tolerance, ':c'=>$confidence, ':a'=>$issued];
        if (str_contains($values, ':alg')) {
            $params[':alg'] = $algorithm;
            $params[':gt'] = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $st->execute($params);
    } catch (Throwable $ignored) {
    }
}
function meteonexa_radar_skill_update(PDO $pdo, string $deviceId, string $locationKey, array $nowcast, array $fusion, array $observations =[], array $radarObservation =[], array $hyperlocal =[], array $radar3 =[]) : array {
    if (!meteonexa_db_table_exists($pdo, 'radar_eta_predictions'))return['available'=>false, 'samples'=>0, 'groundTruth'=>'unavailable'];
    $now = time();
    $eta = is_numeric($nowcast['etaMinutes']??null) ? (int)$nowcast['etaMinutes'] : null;
    $confidence = is_numeric($nowcast['confidence']??null) ? (int)$nowcast['confidence'] : 0;
    $range = is_array($nowcast['etaRangeMinutes']??null) ? array_values($nowcast['etaRangeMinutes']) :[];
    $tol = 10;
    if (isset($range[0], $range[1])&&is_numeric($range[0])&&is_numeric($range[1]))$tol = max(5, min(45, (int)ceil(abs((float)$range[1] - (float)$range[0]) / 2)));
    meteonexa_radar_eta_queue($pdo, $deviceId, $locationKey, 'radar-v2', $eta, $confidence, $tol, $now,['method'=>$nowcast['method']??'']);
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
            meteonexa_radar_eta_queue($pdo, $deviceId, $locationKey, 'radar-v3', $eta3, $conf3, $tol3, $now,['cellId'=>$dominant['id']??null, 'mode'=>$radar3['mode']??'shadow']);
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
            // A recent, good-quality independent negative observation after the
            // expected tolerance can classify a miss. Without such evidence the
            // row becomes expired_unverified, never a false failure.
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
function meteonexa_queue_decision_verification(PDO $pdo, string $deviceId, string $locationKey, string $kind, string $subject, ? string $startsAt, ? string $endsAt, ? float $score, array $payload =[]) : void {
    if (!meteonexa_db_table_exists($pdo, 'decision_verification_samples')||!$startsAt)return;
    try {
        $key = substr(hash('sha256', $deviceId . '|' . $locationKey . '|' . $kind . '|' . $subject . '|' . $startsAt), 0, 48);
        $st = $pdo->prepare('INSERT OR IGNORE INTO decision_verification_samples(device_id,location_key,verification_key,kind,subject,starts_at,ends_at,recommendation_score,payload_json,status,observed_json,verified_at,created_at) VALUES(:d,:l,:k,:kind,:s,:a,:e,:score,:p,\'pending\',\'\',\'\',:c)');
        $st->execute([':d'=>$deviceId, ':l'=>$locationKey, ':k'=>$key, ':kind'=>substr($kind, 0, 24), ':s'=>substr($subject, 0, 80), ':a'=>$startsAt, ':e'=>$endsAt??$startsAt, ':score'=>$score, ':p'=>json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':c'=>meteonexa_verification_now_iso()]);
    } catch (Throwable $ignored) {
    }
}
function meteonexa_verify_decision_samples(PDO $pdo, string $deviceId, string $locationKey, array $observations) : int {
    if (!meteonexa_db_table_exists($pdo, 'decision_verification_samples'))return 0;
    $evidence = (array)($observations['evidence']??[]);
    if (!$evidence)return 0;
    $now = time();
    $done = 0;
    try {
        $st = $pdo->prepare("SELECT id,starts_at,ends_at FROM decision_verification_samples WHERE device_id=:d AND location_key=:l AND status='pending' AND starts_at<=:now ORDER BY id ASC LIMIT 100");
        $st->execute([':d'=>$deviceId, ':l'=>$locationKey, ':now'=>gmdate('c', $now)]);
        foreach ($st->fetchAll() as $r) {
            $start = meteonexa_verification_ts($r['starts_at']??'')??$now;
            $end = meteonexa_verification_ts($r['ends_at']??'')??($start + 10800);
            if ($now < $end)continue;
            $best = null;
            $dist = PHP_INT_MAX;
            foreach ($evidence as $ev) {
                $ts = meteonexa_verification_ts($ev['observedAt']??'');
                if ($ts===null)continue;
                $d = abs($ts - (int)(($start + $end) / 2));
                if ($d < $dist) {
                    $dist = $d;
                    $best = $ev;
                }
            }
            if (!$best||$dist > 21600)continue;
            $obs =['temperature'=>$best['temperature']??null, 'precipitation'=>$best['precipitation']??null, 'windGust'=>$best['wind']??$best['windGust']??null, 'stormEvent'=>$best['storm']??$best['stormEvent']??null, 'sourceType'=>$best['sourceType']??null, 'observedAt'=>$best['observedAt']??null, 'qualityScore'=>$best['qualityScore']??null];
            $up = $pdo->prepare("UPDATE decision_verification_samples SET status='verified',observed_json=:o,verified_at=:v WHERE id=:id AND status='pending'");
            $up->execute([':o'=>json_encode($obs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':v'=>gmdate('c'), ':id'=>(int)$r['id']]);
            $done+=$up->rowCount() ? 1 : 0;
        }
    } catch (Throwable $ignored) {
    }
    return $done;
}
function meteonexa_predictive_alert_update(PDO $pdo, string $deviceId, string $locationKey, array $events, array $observations) : array {
    if (!meteonexa_db_table_exists($pdo, 'predictive_alert_verifications'))return['queued'=>0, 'verified'=>0];
    $now = time();
    $queued = 0;
    $verified = 0;
    try {
        $ins = $pdo->prepare("INSERT OR IGNORE INTO predictive_alert_verifications(device_id,location_key,event_key,event_type,predicted_at,window_start,window_end,confidence,status,outcome,observed_at,verified_at,created_at) VALUES(:d,:l,:k,:t,:p,:s,:e,:c,'pending','','','',:a)");
        foreach ($events as $event) {
            if (!is_array($event)||empty($event['predictive'])||!in_array((string)($event['type']??''),['storm', 'wind'], true))continue;
            $start = (string)($event['startsAt']??'');
            $end = (string)($event['endsAt']??'');
            if ($start===''||$end==='')continue;
            $key = substr(hash('sha256', $deviceId . '|' . $locationKey . '|' .($event['type']??'') . '|' . $start . '|' . $end), 0, 64);
            $ins->execute([':d'=>$deviceId, ':l'=>$locationKey, ':k'=>$key, ':t'=>(string)$event['type'], ':p'=>gmdate('c'), ':s'=>$start, ':e'=>$end, ':c'=>(int)($event['confidence']??0), ':a'=>gmdate('c')]);
            $queued+=$ins->rowCount() ? 1 : 0;
        }
        $evidence = (array)($observations['evidence']??[]);
        if ($evidence) {
            $st = $pdo->prepare("SELECT id,event_type,window_start,window_end FROM predictive_alert_verifications WHERE device_id=:d AND location_key=:l AND status='pending' AND window_end<=:now ORDER BY id ASC LIMIT 100");
            $st->execute([':d'=>$deviceId, ':l'=>$locationKey, ':now'=>gmdate('c', $now)]);
            foreach ($st->fetchAll() as $r) {
                $start = meteonexa_verification_ts($r['window_start']??'')??0;
                $end = meteonexa_verification_ts($r['window_end']??'')??0;
                $hit = false;
                $bestAt = '';
                $hasEvidence = false;
                foreach ($evidence as $ev) {
                    $ts = meteonexa_verification_ts($ev['observedAt']??'');
                    if ($ts===null||$ts < $start - 3600||$ts > $end + 3600)continue;
                    $hasEvidence = true;
                    $type = (string)$r['event_type'];
                    if ($type==='storm'&&((is_numeric($ev['storm']??null)&&(float)$ev['storm']>=.5)||(is_numeric($ev['stormEvent']??null)&&(float)$ev['stormEvent']>=.5))) {
                        $hit = true;
                        $bestAt = (string)$ev['observedAt'];
                        break;
                    }
                    if ($type==='wind'&&is_numeric($ev['wind']??$ev['windGust']??null)&&(float)($ev['wind']??$ev['windGust'])>=55) {
                        $hit = true;
                        $bestAt = (string)$ev['observedAt'];
                        break;
                    }
                }
                if (!$hasEvidence)continue;
                $up = $pdo->prepare("UPDATE predictive_alert_verifications SET status='verified',outcome=:o,observed_at=:at,verified_at=:v WHERE id=:id AND status='pending'");
                $up->execute([':o'=>$hit ? 'confirmed' : 'false_positive', ':at'=>$bestAt, ':v'=>gmdate('c'), ':id'=>(int)$r['id']]);
                $verified+=$up->rowCount() ? 1 : 0;
            }
        }
    } catch (Throwable $ignored) {
    }
    return['queued'=>$queued, 'verified'=>$verified];
}
/** Queue fixed hourly opportunities so false negatives and true negatives exist. */
function meteonexa_predictive_opportunity_update(PDO $pdo, string $deviceId, string $locationKey, array $events, int $windThreshold = 55) : array {
    if (!meteonexa_db_table_exists($pdo, 'predictive_alert_opportunities'))return['queued'=>0, 'verified'=>0];
    $now = time();
    $hour = (int)(floor($now / 3600) * 3600);
    $queued = 0;
    $verified = 0;
    try {
        $ins = $pdo->prepare("INSERT OR IGNORE INTO predictive_alert_opportunities(device_id,location_key,opportunity_key,event_type,window_start,window_end,predicted,confidence,raw_score,observed,outcome,evidence_json,status,observed_at,verified_at,created_at) VALUES(:d,:l,:k,:t,:s,:e,:p,:c,:r,NULL,'','', 'pending','','',:a)");
        foreach (['storm', 'wind'] as $type) {
            for ($offset = 0; $offset < 6; $offset++) {
                $start = $hour + $offset * 3600;
                $end = $start + 3600;
                $predicted = 0;
                $confidence = 0;
                $raw = 0.0;
                foreach ($events as $event) {
                    if (!is_array($event)||empty($event['predictive'])||(string)($event['type']??'')!==$type)continue;
                    $es = meteonexa_verification_ts($event['startsAt']??'');
                    $ee = meteonexa_verification_ts($event['endsAt']??'');
                    if ($es===null||$ee===null||$ee < $start||$es > $end)continue;
                    $predicted = 1;
                    $confidence = max($confidence, (int)($event['confidence']??0));
                    $raw = max($raw, (float)($event['convectiveRisk']['score']??$event['confidence']??0));
                }
                $key = substr(hash('sha256', $deviceId . '|' . $locationKey . '|' . $type . '|' . gmdate('Y-m-d\TH:00:00\Z', $start)), 0, 64);
                $ins->execute([':d'=>$deviceId, ':l'=>$locationKey, ':k'=>$key, ':t'=>$type, ':s'=>gmdate('c', $start), ':e'=>gmdate('c', $end), ':p'=>$predicted, ':c'=>$confidence, ':r'=>$raw, ':a'=>gmdate('c')]);
                $queued+=$ins->rowCount() ? 1 : 0;
            }
        }
        // Use the persisted observation ledger, not only the current API payload,
        // so a verification can still be closed hours after the event window.
        $st = $pdo->prepare("SELECT id,event_type,window_start,window_end,predicted,confidence FROM predictive_alert_opportunities WHERE device_id=:d AND location_key=:l AND status='pending' AND window_end<=:now ORDER BY id ASC LIMIT 200");
        $st->execute([':d'=>$deviceId, ':l'=>$locationKey, ':now'=>gmdate('c', $now)]);
        $obsQ = $pdo->prepare("SELECT source_type,observed_at,wind_gust,storm_event,distance_km,quality_score FROM observation_evidence WHERE device_id=:d AND location_key=:l AND observed_at>=:s AND observed_at<=:e ORDER BY quality_score DESC,observed_at ASC");
        foreach ($st->fetchAll() as $row) {
            $ws = meteonexa_verification_ts($row['window_start']??'')??0;
            $we = meteonexa_verification_ts($row['window_end']??'')??0;
            $obsQ->execute([':d'=>$deviceId, ':l'=>$locationKey, ':s'=>gmdate('c', $ws - 1800), ':e'=>gmdate('c', $we + 1800)]);
            $evidence =[];
            $observed = false;
            foreach ($obsQ->fetchAll() as $ev) {
                $quality = (int)($ev['quality_score']??0);
                $distance = is_numeric($ev['distance_km']??null) ? (float)$ev['distance_km'] : 999;
                if ($quality < 50||$distance > 60)continue;
                $evidence[] = $ev;
                if ((string)$row['event_type']==='storm'&&is_numeric($ev['storm_event']??null)&&(float)$ev['storm_event']>=.5)$observed = true;
                if ((string)$row['event_type']==='wind'&&is_numeric($ev['wind_gust']??null)&&(float)$ev['wind_gust']>=$windThreshold)$observed = true;
            }
            if (!$evidence) {
                if ($now > $we + 86400) {
                    $pdo->prepare("UPDATE predictive_alert_opportunities SET status='insufficient_evidence',verified_at=:v WHERE id=:id AND status='pending'")->execute([':v'=>gmdate('c'), ':id'=>(int)$row['id']]);
                }
                continue;
            }
            $predicted = !empty($row['predicted']);
            $outcome = $predicted ?($observed ? 'tp' : 'fp') :($observed ? 'fn' : 'tn');
            $at = '';
            foreach ($evidence as $ev) {
                if (($observed&&(((string)$row['event_type']==='storm'&&(float)($ev['storm_event']??0)>=.5)||((string)$row['event_type']==='wind'&&(float)($ev['wind_gust']??0)>=$windThreshold)))) {
                    $at = (string)$ev['observed_at'];
                    break;
                }
            }
            $up = $pdo->prepare("UPDATE predictive_alert_opportunities SET observed=:o,outcome=:out,evidence_json=:j,status='verified',observed_at=:at,verified_at=:v WHERE id=:id AND status='pending'");
            $up->execute([':o'=>$observed ? 1 : 0, ':out'=>$outcome, ':j'=>json_encode(array_slice($evidence, 0, 12), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':at'=>$at, ':v'=>gmdate('c'), ':id'=>(int)$row['id']]);
            $verified+=$up->rowCount() ? 1 : 0;
        }
    } catch (Throwable $ignored) {
    }
    return['queued'=>$queued, 'verified'=>$verified];
}
function meteonexa_binary_metrics(array $rows) : array {
    $tp = $fp = $fn = $tn = 0;
    $brier =[];
    foreach ($rows as $r) {
        $out = (string)($r['outcome']??'');
        if ($out==='tp')$tp++;
        elseif ($out==='fp')$fp++;
        elseif ($out==='fn')$fn++;
        elseif ($out==='tn')$tn++;
        if (isset($r['confidence'], $r['observed'])&&is_numeric($r['confidence'])&&is_numeric($r['observed'])) {
            $p = meteonexa_verification_clamp((float)$r['confidence'] / 100, 0, 1);
            $o = (float)$r['observed'];
            $brier[] =($p - $o)**2;
        }
    }
    $precision =($tp + $fp) > 0 ? $tp /($tp + $fp) : null;
    $recall =($tp + $fn) > 0 ? $tp /($tp + $fn) : null;
    $far =($tp + $fp) > 0 ? $fp /($tp + $fp) : null;
    $specificity =($tn + $fp) > 0 ? $tn /($tn + $fp) : null;
    return['tp'=>$tp, 'fp'=>$fp, 'fn'=>$fn, 'tn'=>$tn, 'samples'=>$tp + $fp + $fn + $tn, 'precisionPct'=>$precision===null ? null : round($precision * 100, 1), 'recallPct'=>$recall===null ? null : round($recall * 100, 1), 'probabilityOfDetectionPct'=>$recall===null ? null : round($recall * 100, 1), 'falseAlarmRatioPct'=>$far===null ? null : round($far * 100, 1), 'specificityPct'=>$specificity===null ? null : round($specificity * 100, 1), 'brier'=>$brier ? round(array_sum($brier) / count($brier), 3) : null];
}
function meteonexa_trust_scoreboard(PDO $pdo, ? string $deviceId = null, ? string $locationKey = null) : array {
    $where =[];
    $params =[];
    if ($deviceId) {
        $where[] = 'device_id=:d';
        $params[':d'] = $deviceId;
    }
    if ($locationKey) {
        $where[] = 'location_key=:l';
        $params[':l'] = $locationKey;
    }
    $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $out =['version'=>'2.0', 'generatedAt'=>gmdate('c'), 'temperatureMae'=>null, 'precipitationBrier'=>null, 'stormBrier'=>null, 'radarEtaMaeMinutes'=>null, 'radarEtaWithinTolerancePct'=>null, 'predictiveAlertFalsePositivePct'=>null, 'radarAlgorithms'=>[], 'alertVerification'=>[], 'samples'=>[], 'period'=>['first'=>null, 'last'=>null]];
    try {
        if (meteonexa_db_table_exists($pdo, 'model_skill_samples')) {
            $st = $pdo->prepare("SELECT metric,COUNT(*) n,AVG(error_value) mae,AVG(brier_score) brier,MIN(verified_at) first_at,MAX(verified_at) last_at FROM model_skill_samples{$w}" .($w ? ' AND ' : ' WHERE ') . "verified_at<>'' GROUP BY metric");
            $st->execute($params);
            foreach ($st->fetchAll() as $r) {
                $m = (string)$r['metric'];
                $out['samples'][$m] = (int)$r['n'];
                if ($m==='temperature')$out['temperatureMae'] = is_numeric($r['mae']) ? round((float)$r['mae'], 2) : null;
                if ($m==='rain')$out['precipitationBrier'] = is_numeric($r['brier']) ? round((float)$r['brier'], 3) : null;
                if ($m==='storm')$out['stormBrier'] = is_numeric($r['brier']) ? round((float)$r['brier'], 3) : null;
                $out['period']['first'] = $out['period']['first']===null ? $r['first_at'] : min((string)$out['period']['first'], (string)$r['first_at']);
                $out['period']['last'] = $out['period']['last']===null ? $r['last_at'] : max((string)$out['period']['last'], (string)$r['last_at']);
            }
        }
        if (meteonexa_db_table_exists($pdo, 'radar_eta_predictions')) {
            $algorithmColumn = meteonexa_db_column_exists($pdo, 'radar_eta_predictions', 'algorithm');
            $st = $pdo->prepare("SELECT " .($algorithmColumn ? 'algorithm,' : '') . "absolute_error_minutes,tolerance_minutes,verified_at FROM radar_eta_predictions{$w}" .($w ? ' AND ' : ' WHERE ') . "status='verified' AND absolute_error_minutes IS NOT NULL ORDER BY id ASC");
            $st->execute($params);
            $groups =[];
            foreach ($st->fetchAll() as $r) {
                $alg = $algorithmColumn ? (string)($r['algorithm'] ? : 'radar-v2') : 'radar-v2';
                $groups[$alg][] = $r;
            }
            foreach ($groups as $alg=>$rows) {
                $errors = array_map(static fn($r)=>(float)$r['absolute_error_minutes'], $rows);
                sort($errors);
                $n = count($errors);
                $ok = count(array_filter($rows, static fn($r)=>(float)$r['absolute_error_minutes']<=(float)$r['tolerance_minutes']));
                $out['radarAlgorithms'][$alg] =['samples'=>$n, 'maeMinutes'=>round(array_sum($errors) / $n, 1), 'medianMinutes'=>round($errors[(int)floor(($n - 1) / 2)], 1), 'p90Minutes'=>round($errors[(int)floor(($n - 1) * .9)], 1), 'withinTolerancePct'=>round(100 * $ok / $n, 1)];
            }
            $primary = $out['radarAlgorithms']['radar-v2']??reset($out['radarAlgorithms']) ? : null;
            if ($primary) {
                $out['samples']['radarEta'] = $primary['samples'];
                $out['radarEtaMaeMinutes'] = $primary['maeMinutes'];
                $out['radarEtaWithinTolerancePct'] = $primary['withinTolerancePct'];
            }
        }
        if (meteonexa_db_table_exists($pdo, 'predictive_alert_opportunities')) {
            $st = $pdo->prepare("SELECT event_type,outcome,confidence,observed,verified_at FROM predictive_alert_opportunities{$w}" .($w ? ' AND ' : ' WHERE ') . "status='verified'");
            $st->execute($params);
            $by =[];
            foreach ($st->fetchAll() as $r)$by[(string)$r['event_type']][] = $r;
            foreach ($by as $type=>$rows) {
                $out['alertVerification'][$type] = meteonexa_binary_metrics($rows);
                $out['samples']['alert_' . $type] = count($rows);
            }
            if (isset($out['alertVerification']['storm'])) {
                $out['predictiveAlertFalsePositivePct'] = $out['alertVerification']['storm']['falseAlarmRatioPct'];
            }
        } elseif (meteonexa_db_table_exists($pdo, 'predictive_alert_verifications')) {
            $st = $pdo->prepare("SELECT COUNT(*) n,SUM(CASE WHEN outcome='false_positive' THEN 1 ELSE 0 END) fp FROM predictive_alert_verifications{$w}" .($w ? ' AND ' : ' WHERE ') . "status='verified'");
            $st->execute($params);
            $r = $st->fetch() ? :[];
            $n = (int)($r['n']??0);
            $out['samples']['predictiveAlerts'] = $n;
            if ($n)$out['predictiveAlertFalsePositivePct'] = round(100 * (int)$r['fp'] / $n, 1);
        }
        if (meteonexa_db_table_exists($pdo, 'decision_verification_samples')) {
            $st = $pdo->prepare("SELECT kind,COUNT(*) n FROM decision_verification_samples{$w}" .($w ? ' AND ' : ' WHERE ') . "status='verified' GROUP BY kind");
            $st->execute($params);
            foreach ($st->fetchAll() as $r)$out['samples']['decision_' . (string)$r['kind']] = (int)$r['n'];
        }
    } catch (Throwable $ignored) {
    }
    $out['observability'] = meteonexa_observability_summary($pdo);
    $out['learning'] = array_sum(array_map('intval', $out['samples'])) < 50;
    return $out;
}
function meteonexa_prune_verified_precision(PDO $pdo, array $config) : array {
    $metaKey = 'verified_precision_last_prune';
    $now = time();
    try {
        $last = (string)($pdo->query("SELECT COALESCE((SELECT meta_value FROM app_metadata WHERE meta_key='" . $metaKey . "'),'')")->fetchColumn() ? : '');
        $lastTs = meteonexa_verification_ts($last);
        if ($lastTs!==null&&$now - $lastTs < 21600)return['skipped'=>true];
    } catch (Throwable $ignored) {
    }
    $retention = (int)($config['verification']['retention_days']??180);
    $runtimeDays = (int)($config['verification']['runtime_metrics_retention_days']??30);
    $limits = (array)($config['storage_limits']??[]);
    $deleted =[];
    $tableDays =['radar_eta_predictions'=>$retention, 'decision_verification_samples'=>$retention, 'predictive_alert_verifications'=>$retention, 'predictive_alert_opportunities'=>$retention, 'runtime_metrics'=>$runtimeDays];
    foreach ($tableDays as $table=>$days) {
        if (!meteonexa_db_table_exists($pdo, $table))continue;
        try {
            $st = $pdo->prepare("DELETE FROM {$table} WHERE created_at<:cutoff");
            $st->execute([':cutoff'=>gmdate('c', $now - max(1, $days) * 86400)]);
            $deleted[$table] = $st->rowCount();
        } catch (Throwable $ignored) {
        }
        $limit = (int)($limits[$table]??0);
        if ($limit > 0&&function_exists('meteonexa_prune_rows_to_limit')) try {
            meteonexa_prune_rows_to_limit($pdo, $table, $limit);
        } catch (Throwable $ignored) {
        }
    }
    try {
        $sql = meteonexa_pdo_driver($pdo)==='mysql' ? "INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:k,:v,:u) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=VALUES(updated_at)" : "INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:k,:v,:u) ON CONFLICT(meta_key) DO UPDATE SET meta_value=excluded.meta_value,updated_at=excluded.updated_at";
        $pdo->prepare($sql)->execute([':k'=>$metaKey, ':v'=>gmdate('c', $now), ':u'=>gmdate('c', $now)]);
    } catch (Throwable $ignored) {
    }
    return['skipped'=>false, 'deleted'=>$deleted];
}
function meteonexa_observability_event(string $category, string $name, string $status, array $meta =[]) : void {
    try {
        $dir = meteonexa_storage_path();
        if (!is_dir($dir))@mkdir($dir, 0770, true);
        $path = $dir . '/runtime-observability.ndjson';
        $trace = function_exists('meteonexa_request_id') ? meteonexa_request_id() : '';
        $safe =[];
        foreach ($meta as $k=>$v) {
            if (preg_match('/email|token|secret|password|lat|lon|coordinate|message/i', (string)$k))continue;
            if (is_scalar($v)||$v===null)$safe[substr((string)$k, 0, 48)] = $v;
        }
        $row =['at'=>gmdate('c'), 'traceId'=>$trace, 'category'=>substr($category, 0, 40), 'name'=>substr($name, 0, 80), 'status'=>substr($status, 0, 24), 'meta'=>$safe];
        @file_put_contents($path, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        if (is_file($path)&&filesize($path) > 2_000_000) {
            $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines))@file_put_contents($path, implode("\n", array_slice($lines, - 2500)) . "\n", LOCK_EX);
        }
    } catch (Throwable $ignored) {
    }
}
function meteonexa_observability_summary( ? PDO $pdo = null) : array {
    $path = meteonexa_storage_path() . '/runtime-observability.ndjson';
    $status =[];
    $names =[];
    $events = 0;
    if (is_file($path)) {
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            foreach (array_slice($lines, - 500) as $line) {
                $r = json_decode($line, true);
                if (!is_array($r))continue;
                $events++;
                $s = (string)($r['status']??'unknown');
                $n = (string)($r['category']??'') . '/' . (string)($r['name']??'');
                $status[$s] =($status[$s]??0) + 1;
                $names[$n] =($names[$n]??0) + 1;
            }
        }
    }
    arsort($names);
    $out =['events'=>$events, 'byStatus'=>$status, 'byName'=>array_slice($names, 0, 12, true)];
    if ($pdo&&meteonexa_db_table_exists($pdo, 'runtime_metrics')) try {
        $st = $pdo->query("SELECT category,metric_name,status,COUNT(*) n,AVG(duration_ms) avg_ms FROM runtime_metrics WHERE created_at>='" . gmdate('c', time() - 86400) . "' GROUP BY category,metric_name,status ORDER BY n DESC LIMIT 20");
        $out['last24h'] = $st->fetchAll();
    } catch (Throwable $ignored) {
    }
    return $out;
}
