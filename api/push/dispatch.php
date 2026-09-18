<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once dirname(__DIR__) . '/web_push.php';
require_once dirname(__DIR__) . '/http_helpers.php';
require_once dirname(__DIR__) . '/i18n.php';
require_once dirname(__DIR__) . '/intelligence/engine_helpers.php';
require_once dirname(__DIR__) . '/intelligence/quality_helpers.php';
require_once dirname(__DIR__) . '/intelligence/severe_outlook_helpers.php';
require_once dirname(__DIR__) . '/official/lifecycle_helpers.php';
require_once dirname(__DIR__) . '/pipeline/helpers.php';
$config = load_config();
$secret = trim((string)($config['push']['cron_secret']??''));
$provided = trim((string)($_SERVER['HTTP_X_CRON_KEY']??''));
if (PHP_SAPI!=='cli'&&strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')respond(['ok'=>false, 'code'=>'METHOD_NOT_ALLOWED', 'message'=>'api.backend.method_not_allowed'], 405);
if (PHP_SAPI!=='cli'&&($secret===''||!hash_equals($secret, $provided)))respond(['ok'=>false, 'code'=>'FORBIDDEN', 'message'=>'api.error.forbidden'], 403);
$pdo = meteonexa_db($config);
$dispatchLock = meteonexa_acquire_lock('smart-alert-dispatch');
if (!$dispatchLock)respond(['ok'=>true, 'engine'=>'20.1', 'skipped'=>'overlap', 'checked'=>0, 'sent'=>0, 'suppressed'=>0, 'errors'=>[]]);
register_shutdown_function(static function()use($dispatchLock) : void {
    meteonexa_release_lock($dispatchLock);
});
$interval = max(3, (int)($config['push']['check_interval_minutes']??3));
$cutoff = time() - $interval * 60;
$stmt = $pdo->prepare('SELECT * FROM push_subscriptions WHERE active=1 AND latitude IS NOT NULL AND longitude IS NOT NULL AND last_check_at < :cutoff ORDER BY last_check_at ASC LIMIT 200');
$stmt->execute([':cutoff'=>$cutoff]);
$rows = $stmt->fetchAll();
$sent = 0;
$checked = 0;
$suppressed = 0;
$errors =[];
$severityRank =['green'=>0, 'yellow'=>1, 'orange'=>2, 'red'=>3];
foreach ($rows as $row) {
    $checked++;
    $id = (int)$row['id'];
    try {
        $profile = json_decode((string)$row['profile_json'], true);
        if (!is_array($profile))$profile =[];
        $profile = meteonexa_intelligence_profile($profile);
        $language = meteonexa_language($profile['_language']??'it');
        $tr = static fn(string $key, array $params =[]) : string=>meteonexa_text($pdo, $language, $key, $params);
        $lat = (float)$row['latitude'];
        $lon = (float)$row['longitude'];
        $device = (string)$row['device_id'];
        $location = (string)$row['location_name'];
        $timezone = (string)($row['timezone']??'auto');
        // Record the attempt before provider I/O. Together with the deployment lock
        // this prevents overlapping cron invocations and provider retry storms.
        $pdo->prepare('UPDATE push_subscriptions SET last_check_at=:at,updated_at=:now WHERE id=:id')->execute([':at'=>time(), ':now'=>gmdate('c'), ':id'=>$id]);
        $weather = meteonexa_intelligence_weather($lat, $lon, false, 72);
        $air = meteonexa_intelligence_air($lat, $lon, false);
        $accuracy = meteonexa_intelligence_accuracy($pdo, $device, meteonexa_intelligence_location_key($lat, $lon));
        $motion = meteonexa_radar_motion($pdo, $device, $lat, $lon);
        $eventsCfg = (array)($profile['events']??[]);
        $lightning =((!array_key_exists('storm', $eventsCfg)||$eventsCfg['storm'])||(!array_key_exists('hail', $eventsCfg)||$eventsCfg['hail'])) ? meteonexa_intelligence_lightning($config, $lat, $lon) :['available'=>false];
        $satellite = meteonexa_intelligence_satellite($lat, $lon);
        $official =(!array_key_exists('official', $eventsCfg)||$eventsCfg['official']) ? meteonexa_official_track($pdo, $lat, $lon, $location, meteonexa_official_alerts($lat, $lon, $location, '')) :['relevant'=>[]];
        // Hyperlocal observations are fused only for authenticated devices that
        // actually connected a Netatmo account. Provider failure never blocks alerts.
        $hyperlocal = meteonexa_intelligence_hyperlocal($pdo, $config, $device, $lat, $lon);
        $analysis = meteonexa_intelligence_analyze($weather, $air, $profile,['accuracy'=>$accuracy, 'radarMotion'=>$motion, 'lightning'=>$lightning, 'satellite'=>$satellite, 'official'=>$official, 'hyperlocal'=>$hyperlocal, 'forecastWindowHours'=>72]);
        // Forecast storm/strong-wind push alerts must use the same deterministic
        // six-model Severe Outlook as Panoramica. The LLM is never consulted.
        if ((!array_key_exists('storm', $eventsCfg)||$eventsCfg['storm'])||(!array_key_exists('wind', $eventsCfg)||$eventsCfg['wind'])) {
            $models = meteonexa_intelq_fetch_models($lat, $lon, false, 72);
            $consensus = meteonexa_intelq_consensus($models);
            $outlook = meteonexa_severe_outlook($analysis, $consensus, $models, $lightning, 72, (float)($profile['thresholds']['wind']??55));
            $analysis = meteonexa_apply_severe_outlook($analysis, $outlook);
        }
        if (meteonexa_intelligence_quiet($profile, $timezone)) {
            $suppressed++;
            continue;
        }
        $minimum = (string)($profile['minimumSeverity']??'yellow');
        $minRank = $severityRank[$minimum]??1;
        $notice = null;
        foreach ((array)$analysis['events'] as $event) {
            if (($severityRank[$event['severity']??'green']??0) < $minRank)continue;
            $notice = $event;
            break;
        }
        if (!$notice)continue;
        $key = meteonexa_intelligence_event_key($notice, $location);
        $now = time();
        $cooldown = max(1800, min(21600, (int)($profile['cooldownSeconds']??10800)));
        $type = (string)($notice['type']??'weather');
        $severity = (string)($notice['severity']??'yellow');
        // Anti-spam is episode-aware rather than tied only to the 15-minute
        // fingerprint bucket. Suppress a repeated event during cooldown, but
        // allow an immediate escalation (yellow -> orange -> red).
        $recent = $pdo->prepare('SELECT severity,created_at FROM weather_alert_events WHERE device_id=:device AND event_type=:type AND location_name=:location AND created_at>=:cutoff ORDER BY created_at DESC LIMIT 1');
        $recent->execute([':device'=>$device, ':type'=>$type, ':location'=>$location, ':cutoff'=>gmdate('c', $now - $cooldown)]);
        $previous = $recent->fetch();
        $officialLifecycle = (array)($notice['officialLifecycle']??[]);
        $officialChanged = $type==='official'&&in_array((string)($officialLifecycle['changeType']??''),['new', 'escalated', 'extended', 'downgraded', 'shortened', 'updated'], true)&&strtotime((string)($officialLifecycle['changedAt']??''))>=time() - 21600;
        if ($previous&&!$officialChanged&&($severityRank[$severity]??0)<=($severityRank[(string)($previous['severity']??'green')]??0)) {
            $suppressed++;
            continue;
        }
        if ((string)$row['last_notice_key']===$key&&$now - (int)$row['last_notice_at'] < $cooldown) {
            $suppressed++;
            continue;
        }
        $horizon = (string)($notice['horizon']??'forecast');
        $forecastVariant = $horizon==='forecast'&&in_array($type,['rain', 'storm'], true);
        $titleKey = 'smart.alert.' . $type .($forecastVariant ? '.forecast' : '') . '.title';
        $bodyKey = 'smart.alert.' . $type . '.body';
        $params =['location'=>$location, 'confidence'=>(int)($notice['confidence']??0), 'value'=>''];
        if ($type==='rain') {
            if ($forecastVariant)$params['value'] = $tr('smart.alert.rain.forecast.value',['probability'=>(int)($notice['rainProbability']??0)]);
            else $params['value'] = $notice['etaMinutes']===null ? $tr('smart.alert.rain.soon') : $tr('smart.alert.rain.minutes',['minutes'=>(int)$notice['etaMinutes']]);
        } elseif ($type==='storm')$params['value'] = $forecastVariant ? $tr('smart.alert.storm.forecast.value') :(isset($notice['lightning']['nearestKm']) ? $tr('smart.alert.storm.distance',['distance'=>(string)$notice['lightning']['nearestKm']]) : $tr('smart.alert.storm.nearby'));
        elseif ($type==='hail')$params['value'] = $notice['etaMinutes']===null ? $tr('smart.alert.hail.possible') :((int)$notice['etaMinutes']<=0 ? $tr('smart.alert.hail.now') : $tr('smart.alert.hail.minutes',['minutes'=>(int)$notice['etaMinutes']]));
        elseif ($type==='wind')$params['value'] = (string)($notice['maxWind']??'--') . ' km/h';
        elseif ($type==='snow')$params['value'] = (string)($notice['maxSnow']??'--') . ' cm/h';
        elseif ($type==='ice')$params['value'] = (string)($notice['minTemp']??'--') . ' °C';
        elseif ($type==='fog')$params['value'] = (string)($notice['visibilityKm']??'--') . ' km';
        elseif ($type==='heat')$params['value'] = (string)($notice['maxTemp']??'--') . ' °C';
        elseif ($type==='aqi')$params['value'] = (string)($notice['aqi']??'--');
        elseif ($type==='official') {
            $change = (string)($officialLifecycle['changeType']??'');
            $end = (string)($notice['endsAt']??'');
            $prev = (string)($officialLifecycle['previousSeverity']??'');
            if ($change==='escalated')$params['value'] = $tr('smart.alert.official.escalated.value',['from'=>$tr('advanced.official.level.' . $prev), 'to'=>$tr('advanced.official.level.' . $severity)]);
            elseif ($change==='extended'&&$end!=='')$params['value'] = $tr('smart.alert.official.extended.value',['until'=>$end]);
            else $params['value'] = trim((string)($notice['body']??''));
        }
        $title = $tr($titleKey, $params);
        $body = $tr($bodyKey, $params);
        if ($title===$titleKey)$title = (string)$notice['title'];
        if ($body===$bodyKey)$body = (string)$notice['body'];
        $url = './#intelligence';
        $payload = json_encode(['analysis'=>$analysis, 'event'=>$notice], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $eventInsert = $pdo->prepare('INSERT OR IGNORE INTO weather_alert_events(device_id,event_key,event_type,severity,confidence,location_name,starts_at,ends_at,payload_json,created_at,updated_at) VALUES(:device,:key,:type,:severity,:confidence,:location,:starts,:ends,:payload,:now,:now)');
        $eventInsert->execute([':device'=>$device, ':key'=>$key, ':type'=>$type, ':severity'=>$severity, ':confidence'=>(int)($notice['confidence']??0), ':location'=>$location, ':starts'=>(string)($notice['startsAt']??''), ':ends'=>(string)($notice['endsAt']??''), ':payload'=>$payload ? : '{}', ':now'=>gmdate('c')]);
        $rawExpiry = (string)($notice['endsAt']??'');
        $expiryTs = $rawExpiry!=='' ? strtotime($rawExpiry) : false;
        if ($expiryTs===false||$expiryTs<=time())$expiryTs = time() + 48 * 3600;
        $expiresAt = gmdate('c', $expiryTs);
        $pdo->prepare('INSERT OR IGNORE INTO push_notifications(device_id,notice_key,title,body,target_url,tag,created_at,expires_at) VALUES(:device,:key,:title,:body,:url,:tag,:now,:expires)')->execute([':device'=>$device, ':key'=>$key, ':title'=>$title, ':body'=>$body, ':url'=>$url, ':tag'=>'meteonexa-smart-' . $type, ':now'=>gmdate('c'), ':expires'=>$expiresAt]);
        $result = meteonexa_send_empty_push((string)$row['endpoint'], $config, 600);
        if ($result['ok']) {
            $sent++;
            $pdo->prepare('UPDATE push_subscriptions SET last_notice_key=:key,last_notice_at=:at WHERE id=:id')->execute([':key'=>$key, ':at'=>$now, ':id'=>$id]);
            $pdo->prepare('UPDATE weather_alert_events SET delivered_at=:now,updated_at=:now WHERE device_id=:device AND event_key=:key')->execute([':now'=>gmdate('c'), ':device'=>$device, ':key'=>$key]);
        } elseif (in_array($result['status'],[404, 410], true)) {
            $pdo->prepare('UPDATE push_subscriptions SET active=0 WHERE id=:id')->execute([':id'=>$id]);
        }
    } catch (Throwable $e) {
        meteonexa_log_event('smart_alert_dispatch_failed', $e);
        $errors[] =['id'=>$id, 'code'=>'SMART_ALERT_CHECK_FAILED'];
    }
}
$pdo->prepare("DELETE FROM push_notifications WHERE expires_at<>'' AND expires_at<=:now")->execute([':now'=>gmdate('c')]);
$pdo->prepare("DELETE FROM push_notifications WHERE dismissed_at<>'' AND dismissed_at<:cutoff")->execute([':cutoff'=>gmdate('c', time() - 86400)]);
$pdo->prepare("DELETE FROM push_notifications WHERE read_at<>'' AND read_at<:cutoff")->execute([':cutoff'=>gmdate('c', time() - 3 * 86400)]);
$pdo->prepare("DELETE FROM push_notifications WHERE read_at='' AND created_at<:cutoff")->execute([':cutoff'=>gmdate('c', time() - 14 * 86400)]);
$pdo->prepare('DELETE FROM weather_alert_events WHERE created_at<:cutoff')->execute([':cutoff'=>gmdate('c', time() - 45 * 86400)]);
$pdo->prepare('DELETE FROM push_subscriptions WHERE active=0 AND updated_at<:cutoff')->execute([':cutoff'=>gmdate('c', time() - 90 * 86400)]);
meteonexa_prune_rows_to_limit($pdo, 'push_notifications', (int)($config['storage_limits']['push_notifications']??50000));
meteonexa_prune_rows_to_limit($pdo, 'weather_alert_events', (int)($config['storage_limits']['weather_alert_events']??50000));
respond(['ok'=>true, 'engine'=>'20.1', 'checked'=>$checked, 'sent'=>$sent, 'suppressed'=>$suppressed, 'errors'=>$errors]);
