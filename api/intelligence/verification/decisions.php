<?php
declare(strict_types=1);
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
