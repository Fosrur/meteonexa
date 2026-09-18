<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/account/account_helpers.php';
require_once __DIR__ . '/watch_helpers.php';
require_once dirname(__DIR__) . '/intelligence/engine_helpers.php';
require_once dirname(__DIR__) . '/intelligence/quality_helpers.php';
require_once dirname(__DIR__) . '/intelligence/decision_timeline_helpers.php';
require_once dirname(__DIR__) . '/i18n.php';
require_once dirname(__DIR__) . '/web_push.php';
function meteonexa_watch_plan_due_rows(PDO $pdo, int $limit = 80) : array {
    if (!meteonexa_db_table_exists($pdo, 'account_sync_state'))return[];
    $limit = max(1, min(200, $limit));
    $sql = "SELECT account_hash,item_key,payload_json,revision,updated_by_device,updated_at FROM account_sync_state WHERE namespace='watch_plan' AND deleted=0 ORDER BY updated_at ASC LIMIT " . $limit;
    $out =[];
    foreach ($pdo->query($sql)->fetchAll() as $row) {
        $p = json_decode((string)($row['payload_json']??''), true);
        if (!is_array($p)||empty($p['enabled']))continue;
        $start = strtotime((string)($p['startsAt']??''));
        $duration = (int)($p['durationMinutes']??120);
        if (!$start)continue;
        $end = $start + max(30, $duration) * 60;
        if ($end < time() - 1800||$start > time() + 72 * 3600)continue;
        $row['plan'] = $p;
        $out[] = $row;
    }
    return $out;
}
function meteonexa_watch_plan_latest_nowcast(PDO $pdo, string $device, float $lat, float $lon) : array {
    if ($device===''||!meteonexa_db_table_exists($pdo, 'nowcast_fusion_snapshots'))return[];
    $key = meteonexa_intelligence_location_key($lat, $lon);
    $st = $pdo->prepare('SELECT snapshot_json,created_at FROM nowcast_fusion_snapshots WHERE device_id=:d AND location_key=:l ORDER BY id DESC LIMIT 1');
    $st->execute([':d'=>$device, ':l'=>$key]);
    $row = $st->fetch();
    if (!is_array($row)||(strtotime((string)$row['created_at']) ? : 0) < time() - 900)return[];
    $p = json_decode((string)$row['snapshot_json'], true);
    return is_array($p) ? $p :[];
}
function meteonexa_watch_plan_evaluate(PDO $pdo, string $account, array $plan, array $location, string $device) : array {
    $lat = (float)$location['latitude'];
    $lon = (float)$location['longitude'];
    $target = strtotime((string)$plan['startsAt']);
    if (!$target)throw new RuntimeException('PLAN_TIME_INVALID');
    $models = meteonexa_intelq_fetch_models($lat, $lon, false, 84);
    $consensus = meteonexa_intelq_canonical_consensus($models);
    $hourly = (array)($consensus['hourly']??[]);
    if (!$hourly)throw new RuntimeException('PLAN_FORECAST_UNAVAILABLE');
    $closest = null;
    $distance = PHP_INT_MAX;
    foreach ($hourly as $row) {
        $ts = meteonexa_intel_time_utc((string)($row['time']??''));
        if ($ts===null)continue;
        $d = abs($ts - $target);
        if ($d < $distance) {
            $closest = $row;
            $distance = $d;
        }
    }
    if (!is_array($closest)||$distance > 5400)throw new RuntimeException('PLAN_FORECAST_OUT_OF_RANGE');
    $profiles = meteonexa_decision_profiles();
    $activity = (string)($plan['activity']??'run');
    $profile = $profiles[$activity]??$profiles['run'];
    $customProfiles = meteonexa_account_activity_load($pdo, $account);
    $customKey = (string)($profile['custom']??'');
    $custom = is_array($customProfiles[$customKey]??null) ? $customProfiles[$customKey] : null;
    $nowcast = meteonexa_watch_plan_latest_nowcast($pdo, $device, $lat, $lon);
    $point = meteonexa_decision_hour_score($closest, $profile, $custom, $nowcast);
    $modelsExpected = max(1, (int)($consensus['modelsExpected']??count(meteonexa_intelq_model_definitions())));
    $modelsAvailable = max(0, (int)($consensus['modelsAvailable']??$point['modelsAvailable']??0));
    $confidence = (int)round(max(20, min(96, 35 + 61 *($modelsAvailable / $modelsExpected))));
    if (($consensus['sourceMode']??'')!=='fresh_consensus')$confidence = max(20, $confidence - 18);
    $near =[];
    foreach ($hourly as $row) {
        $ts = meteonexa_intel_time_utc((string)($row['time']??''));
        if ($ts===null||$ts < time()||abs($ts - $target) > 8 * 3600)continue;
        $near[] = meteonexa_decision_hour_score($row, $profile, $custom, $nowcast);
    }
    $ranges = meteonexa_decision_best_ranges($near);
    return['evaluatedAt'=>gmdate('c'), 'score'=>(int)$point['score'], 'status'=>(string)$point['status'], 'risk'=>(string)$point['risk'], 'rainPct'=>(int)$point['rainPct'], 'stormPct'=>(int)$point['stormPct'], 'gustKmh'=>(int)$point['gustKmh'], 'temperature'=>$point['temperature'], 'confidence'=>$confidence, 'modelsAvailable'=>$modelsAvailable, 'modelsExpected'=>$modelsExpected, 'sourceMode'=>(string)($consensus['sourceMode']??'unknown'), 'bestAlternative'=>$ranges['best']??null, 'nowcastUsed'=>$nowcast!==[]];
}
function meteonexa_watch_plan_should_notify(array $plan, array $evaluation) : array {
    $previous = is_array($plan['lastEvaluation']??null) ? $plan['lastEvaluation'] : null;
    $baseline = is_array($plan['baseline']??null) ? $plan['baseline'] : null;
    if (!$baseline||!$previous)return['notify'=>false, 'kind'=>'baseline'];
    $rank =['good'=>0, 'caution'=>1, 'avoid'=>2];
    $prevRank = $rank[(string)($previous['status']??'caution')]??1;
    $newRank = $rank[(string)($evaluation['status']??'caution')]??1;
    $drop = (int)($previous['score']??0) - (int)($evaluation['score']??0);
    $rise = - $drop;
    $kind = '';
    if ($newRank > $prevRank&&($drop>=8||$newRank===2))$kind = 'degraded';
    elseif ($drop>=15)$kind = 'degraded';
    elseif ($prevRank > 0&&$newRank===0&&$rise>=15&&(string)($plan['lastNotifiedStatus']??'')!=='')$kind = 'recovered';
    if ($kind==='')return['notify'=>false, 'kind'=>'stable'];
    $last = strtotime((string)($plan['lastNotifiedAt']??'')) ? : 0;
    if ($last&&time() - $last < 10800&&!($kind==='degraded'&&$newRank===2&&($plan['lastNotifiedStatus']??'')!=='avoid'))return['notify'=>false, 'kind'=>'cooldown'];
    return['notify'=>true, 'kind'=>$kind, 'scoreDelta'=>(int)$evaluation['score'] - (int)($previous['score']??0)];
}
function meteonexa_watch_plan_push(PDO $pdo, array $config, string $device, array $plan, array $evaluation, string $kind, array $location) : bool {
    if ($device===''||!meteonexa_db_table_exists($pdo, 'push_subscriptions')||!meteonexa_db_table_exists($pdo, 'push_notifications'))return false;
    $st = $pdo->prepare('SELECT * FROM push_subscriptions WHERE device_id=:d AND active=1 ORDER BY updated_at DESC LIMIT 1');
    $st->execute([':d'=>$device]);
    $sub = $st->fetch();
    if (!is_array($sub))return false;
    $profile = json_decode((string)($sub['profile_json']??''), true);
    $language = meteonexa_language(is_array($profile) ?($profile['_language']??'it') : 'it');
    $tr = static fn(string $key, array $params =[]) : string=>meteonexa_text($pdo, $language, $key, $params);
    $locationName = (string)($location['name']??$location['label']??'');
    $activity = $tr('decision.activity.' . (string)$plan['activity']);
    $params =['activity'=>$activity, 'location'=>$locationName, 'score'=>(int)$evaluation['score']];
    $title = $tr('watch.push.' . $kind . '.title', $params);
    $body = $tr('watch.push.' . $kind . '.body', $params);
    $bucket = gmdate('YmdH');
    $key = 'watch:' . $plan['id'] . ':' . $kind . ':' . $bucket;
    $expires = gmdate('c',(strtotime((string)$plan['startsAt']) ? : time()) + (int)$plan['durationMinutes'] * 60 + 21600);
    try {
        $sql = meteonexa_pdo_driver($pdo)==='mysql' ? 'INSERT IGNORE INTO push_notifications(device_id,notice_key,title,body,target_url,tag,created_at,expires_at) VALUES(:d,:k,:t,:b,:u,:g,:c,:e)' : 'INSERT OR IGNORE INTO push_notifications(device_id,notice_key,title,body,target_url,tag,created_at,expires_at) VALUES(:d,:k,:t,:b,:u,:g,:c,:e)';
        $pdo->prepare($sql)->execute([':d'=>$device, ':k'=>$key, ':t'=>$title, ':b'=>$body, ':u'=>'./#intelligence', ':g'=>'meteonexa-watch-plan', ':c'=>gmdate('c'), ':e'=>$expires]);
    } catch (Throwable $e) {
        return false;
    }
    try {
        $result = meteonexa_send_empty_push((string)$sub['endpoint'], $config, 120);
        return !empty($result['ok']);
    } catch (Throwable $ignored) {
        return false;
    }
}
function meteonexa_watch_plans_process(PDO $pdo, array $config, int $limit = 80) : array {
    $rows = meteonexa_watch_plan_due_rows($pdo, $limit);
    $checked = 0;
    $updated = 0;
    $notified = 0;
    $errors =[];
    foreach ($rows as $row) {
        $checked++;
        $account = (string)$row['account_hash'];
        $device = (string)($row['updated_by_device']??'');
        $plan = (array)$row['plan'];
        try {
            $locationRow = meteonexa_account_sync_read($pdo, $account, 'location', (string)($plan['locationKey']??''));
            $location = is_array($locationRow) ? (array)($locationRow['payload']??[]) :[];
            if (!is_numeric($location['latitude']??null)||!is_numeric($location['longitude']??null))throw new RuntimeException('PLAN_LOCATION_UNAVAILABLE');
            $evaluation = meteonexa_watch_plan_evaluate($pdo, $account, $plan, $location, $device);
            $decision = meteonexa_watch_plan_should_notify($plan, $evaluation);
            if (!is_array($plan['baseline']??null))$plan['baseline'] = $evaluation;
            $plan['lastEvaluation'] = $evaluation;
            if (!empty($decision['notify'])) {
                $sent = meteonexa_watch_plan_push($pdo, $config, $device, $plan, $evaluation, (string)$decision['kind'], $location);
                $plan['lastNotifiedAt'] = gmdate('c');
                $plan['lastNotifiedStatus'] = (string)$evaluation['status'];
                $plan['lastNotificationKind'] = (string)$decision['kind'];
                $plan['lastNotificationDelivered'] = $sent;
                $notified++;
            }
            $plan['updatedAt'] = gmdate('c');
            meteonexa_account_sync_upsert($pdo, $account, 'watch_plan', (string)$plan['id'], $plan, $device);
            $updated++;
        } catch (Throwable $e) {
            $errors[] =['planRef'=>substr(hash('sha256', (string)($plan['id']??'')), 0, 10), 'code'=>$e->getMessage()];
        }
    }
    return['checked'=>$checked, 'updated'=>$updated, 'notified'=>$notified, 'errors'=>$errors];
}
