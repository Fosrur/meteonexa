<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once dirname(__DIR__) . '/pipeline/helpers.php';
require_once dirname(__DIR__) . '/intelligence/engine_helpers.php';
require_once dirname(__DIR__) . '/intelligence/quality_helpers.php';
require_once dirname(__DIR__) . '/observations/providers.php';
require_once dirname(__DIR__) . '/official/lifecycle_helpers.php';
require_once dirname(__DIR__) . '/calibration/helpers.php';
require_once dirname(__DIR__) . '/radar/archive_helpers.php';
require_once dirname(__DIR__) . '/plans/watch_engine.php';
$config = load_config();
$secret = trim((string)($config['pipeline']['cron_secret']??''));
$provided = trim((string)($_SERVER['HTTP_X_CRON_KEY']??''));
if (PHP_SAPI!=='cli'&&strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')respond(['ok'=>false, 'code'=>'METHOD_NOT_ALLOWED', 'message'=>'api.backend.method_not_allowed'], 405);
if (PHP_SAPI!=='cli'&&($secret===''||!hash_equals($secret, $provided)))respond(['ok'=>false, 'code'=>'FORBIDDEN', 'message'=>'api.error.forbidden'], 403);
$pdo = meteonexa_db($config);
$lock = meteonexa_acquire_lock('industrial-weather-pipeline');
if (!$lock)respond(['ok'=>true, 'engine'=>'20.1', 'skipped'=>'overlap', 'locations'=>0, 'successes'=>0, 'failures'=>0]);
register_shutdown_function(static function()use($lock) : void {
    meteonexa_release_lock($lock);
});
$run = meteonexa_pipeline_run_start($pdo, 'industrial-weather-pipeline');
$success = 0;
$failure = 0;
$processed = 0;
$details =['providers'=>[], 'locations'=>[]];
$max = max(5, (int)($config['pipeline']['max_locations_per_cycle']??30));
$locations = meteonexa_pipeline_locations($pdo, $max);
$officialEvery = (int)($config['pipeline']['official_interval_seconds']??300);
$lightningEvery = (int)($config['pipeline']['lightning_interval_seconds']??180);
$observationEvery = (int)($config['pipeline']['observation_interval_seconds']??600);
$calibrationEvery = (int)($config['pipeline']['calibration_interval_seconds']??900);
$officialDue = meteonexa_pipeline_provider_due($pdo, 'official', $officialEvery);
$lightningDue = meteonexa_pipeline_provider_due($pdo, 'lightning', $lightningEvery);
$observationsDue = meteonexa_pipeline_provider_due($pdo, 'observations', $observationEvery);
$calibrationDue = meteonexa_pipeline_provider_due($pdo, 'verification-worker', $calibrationEvery);
foreach ($locations as $loc) {
    $processed++;
    $locationKey = meteonexa_intelligence_location_key($loc['latitude'], $loc['longitude']);
    $locationRef = substr(hash('sha256', (string)$loc['deviceId'] . '|' . $locationKey), 0, 12);
    $result =['locationRef'=>$locationRef, 'official'=>'skipped', 'lightning'=>'skipped', 'observations'=>'skipped', 'calibration'=>'skipped'];
    // Official source lifecycle: tracks escalation, downgrade, extension, shortening and closure.
    if ($officialDue) {
        $started = meteonexa_pipeline_ms();
        try {
            $official = meteonexa_intelq_meteoalarm_edr($config, $loc['latitude'], $loc['longitude'], 'it')??meteonexa_official_alerts($loc['latitude'], $loc['longitude'], $loc['locationName'], $loc['admin1']);
            if (!isset($official['mode'])) {
                $official['mode'] = 'atom-text-fallback';
                $official['geospatial'] = false;
            }
            $official = meteonexa_official_track($pdo, $loc['latitude'], $loc['longitude'], $loc['locationName'], $official);
            $latency = meteonexa_pipeline_ms() - $started;
            meteonexa_pipeline_provider_result($pdo, 'official', true, $latency,['mode'=>$official['mode']??'fallback', 'relevant'=>count((array)($official['relevant']??[])), 'lifecycle'=>$official['lifecycle']??null], 0);
            $result['official'] = 'ok';
            $success++;
        } catch (Throwable $e) {
            meteonexa_pipeline_provider_result($pdo, 'official', false, meteonexa_pipeline_ms() - $started,['code'=>'OFFICIAL_PIPELINE_FAILED']);
            $result['official'] = 'error';
            $failure++;
        }
    }
    // Independent lightning is persisted as compact aggregate evidence, never raw account data.
    if ($lightningDue) {
        $started = meteonexa_pipeline_ms();
        try {
            $light = meteonexa_intelligence_lightning($config, $loc['latitude'], $loc['longitude']);
            $configured = trim((string)($config['lightning']['client_id']??''))!==''&&trim((string)($config['lightning']['client_secret']??''))!=='';
            $ok = $configured&&!empty($light['available'])&&empty($light['degraded']);
            $status = $configured ?($ok ? 'ok' : 'degraded') : 'not_configured';
            $latency = meteonexa_pipeline_ms() - $started;
            $fresh = null;
            if (!empty($light['observedAt'])) {
                $ts = strtotime((string)$light['observedAt']);
                if ($ts)$fresh = max(0, time() - $ts);
            }
            meteonexa_pipeline_provider_result($pdo, 'lightning', $ok, $latency,['configured'=>$configured, 'recent30m'=>(int)($light['recent30m']??0), 'nearestKm'=>$light['nearestKm']??null], $fresh, $status);
            if ($configured&&meteonexa_db_table_exists($pdo, 'lightning_observation_snapshots')) {
                $payload = json_encode($light, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ? : '{}';
                $pdo->prepare('INSERT INTO lightning_observation_snapshots(device_id,location_key,observed_at,source,count_30m,nearest_km,approaching,payload_json,created_at) VALUES(:device,:location,:observed,:source,:count,:nearest,:approaching,:payload,:created)')->execute([':device'=>$loc['deviceId'], ':location'=>$locationKey, ':observed'=>(string)($light['observedAt']??gmdate('c')), ':source'=>'XWeather', ':count'=>(int)($light['recent30m']??0), ':nearest'=>$light['nearestKm']??null, ':approaching'=>!empty($light['approaching']) ? 1 : 0, ':payload'=>$payload, ':created'=>gmdate('c')]);
            }
            $result['lightning'] = $status;
            if (!$configured) {
                $success++;
            } elseif ($ok) {
                $success++;
            } else {
                $failure++;
            }
        } catch (Throwable $e) {
            meteonexa_pipeline_provider_result($pdo, 'lightning', false, meteonexa_pipeline_ms() - $started,['code'=>'LIGHTNING_PIPELINE_FAILED']);
            $result['lightning'] = 'error';
            $failure++;
        }
    }
    // Independent station truth is collected without waiting for PWA usage.
    if ($observationsDue) {
        $started = meteonexa_pipeline_ms();
        try {
            $obs = meteonexa_observations_collect($pdo, $config, $loc['deviceId'], $loc['latitude'], $loc['longitude'], true);
            $ok = !empty($obs['available']);
            $status = $ok ? 'ok' : 'degraded';
            meteonexa_pipeline_provider_result($pdo, 'observations', $ok, meteonexa_pipeline_ms() - $started,['sources'=>$obs['sourceTypes']??[], 'quality'=>$obs['qualityScore']??0], null, $status);
            $result['observations'] = $status;
            $ok ? $success++ : $failure++;
        } catch (Throwable $e) {
            meteonexa_pipeline_provider_result($pdo, 'observations', false, meteonexa_pipeline_ms() - $started,['code'=>'OBSERVATION_PIPELINE_FAILED']);
            $result['observations'] = 'error';
            $failure++;
        }
    }
    // Forecast verification/calibration is server-side and scheduled, independent of app opening.
    if ($calibrationDue) {
        $started = meteonexa_pipeline_ms();
        try {
            $calLoc =['deviceId'=>$loc['deviceId'], 'latitude'=>$loc['latitude'], 'longitude'=>$loc['longitude'], 'locationKey'=>$locationKey];
            $cal = meteonexa_calibration_process_location($pdo, $config, $calLoc);
            meteonexa_pipeline_provider_result($pdo, 'verification-worker', true, meteonexa_pipeline_ms() - $started,['verified'=>$cal['verified'], 'queued'=>$cal['queued'], 'sources'=>$cal['observations']['sourceTypes']??[]], 0);
            $result['calibration'] = 'ok';
            $success++;
        } catch (Throwable $e) {
            meteonexa_pipeline_provider_result($pdo, 'verification-worker', false, meteonexa_pipeline_ms() - $started,['code'=>'VERIFICATION_PIPELINE_FAILED']);
            $result['calibration'] = 'error';
            $failure++;
        }
    }
    $details['locations'][] = $result;
}
// Radar is location-registration based to preserve existing privacy semantics.
if (meteonexa_db_table_exists($pdo, 'radar_archive_locations')&&meteonexa_pipeline_provider_due($pdo, 'radar', max(300, (int)($config['radar_archive']['interval_minutes']??5) * 60))) {
    $started = meteonexa_pipeline_ms();
    $radarOk = 0;
    $radarFail = 0;
    try {
        $interval = max(5, min(60, (int)($config['radar_archive']['interval_minutes']??5))) * 60;
        $radarRows = $pdo->query('SELECT * FROM radar_archive_locations WHERE active=1 ORDER BY updated_at DESC LIMIT ' . max(1, $max))->fetchAll();
        foreach ($radarRows as $row) {
            $last = strtotime((string)($row['last_capture_at']??'')) ? : 0;
            if (time() - $last < $interval)continue;
            try {
                $capture = meteonexa_capture_radar_location($pdo, $config, $row);
                if (!empty($capture['captured'])||!empty($capture['existing']))$radarOk++;
                else $radarFail++;
            } catch (Throwable $e) {
                $radarFail++;
            }
        }
        $ok = $radarFail===0||$radarOk > 0;
        meteonexa_pipeline_provider_result($pdo, 'radar', $ok, meteonexa_pipeline_ms() - $started,['captured'=>$radarOk, 'failed'=>$radarFail, 'intervalMinutes'=>$interval / 60], 0, $ok ? 'ok' : 'degraded');
        $success+=$radarOk;
        $failure+=$radarFail;
        $details['providers']['radar'] =['captured'=>$radarOk, 'failed'=>$radarFail];
    } catch (Throwable $e) {
        meteonexa_pipeline_provider_result($pdo, 'radar', false, meteonexa_pipeline_ms() - $started,['code'=>'RADAR_PIPELINE_FAILED']);
        $failure++;
    }
}
// Watch My Plan reuses the server-owned forecast orchestrator and existing account-sync/locality records.
try {
    $watchPlans = meteonexa_watch_plans_process($pdo, $config, max(20, $max * 2));
    $details['watchPlans'] = $watchPlans;
    $success+=(int)($watchPlans['updated']??0);
    $failure+=count((array)($watchPlans['errors']??[]));
} catch (Throwable $e) {
    $details['watchPlans'] =['checked'=>0, 'updated'=>0, 'notified'=>0, 'errors'=>[['code'=>'WATCH_PLAN_PIPELINE_FAILED']]];
    $failure++;
}
// Retention keeps high-frequency evidence bounded on shared hosting.
if (meteonexa_db_table_exists($pdo, 'weather_pipeline_runs'))$pdo->prepare('DELETE FROM weather_pipeline_runs WHERE started_at<:cutoff')->execute([':cutoff'=>gmdate('c', time() - 30 * 86400)]);
if (meteonexa_db_table_exists($pdo, 'official_alert_revisions'))$pdo->prepare('DELETE FROM official_alert_revisions WHERE observed_at<:cutoff')->execute([':cutoff'=>gmdate('c', time() - 180 * 86400)]);
if (meteonexa_db_table_exists($pdo, 'official_alert_state'))$pdo->prepare('DELETE FROM official_alert_state WHERE last_seen_at<:cutoff')->execute([':cutoff'=>gmdate('c', time() - 30 * 86400)]);
if (meteonexa_db_table_exists($pdo, 'lightning_observation_snapshots'))$pdo->prepare('DELETE FROM lightning_observation_snapshots WHERE created_at<:cutoff')->execute([':cutoff'=>gmdate('c', time() - 14 * 86400)]);
$details['health'] = meteonexa_pipeline_health_summary($pdo);
$status = $failure > 0&&$success===0 ? 'failed' :($failure > 0 ? 'degraded' : 'ok');
meteonexa_pipeline_run_finish($pdo, $run, $status, $processed, $success, $failure, $details);
respond(['ok'=>true, 'engine'=>'20.1', 'pipeline'=>'industrial-weather-v1', 'status'=>$status, 'locations'=>$processed, 'successes'=>$success, 'failures'=>$failure, 'health'=>$details['health'], 'generatedAt'=>gmdate('c')]);
