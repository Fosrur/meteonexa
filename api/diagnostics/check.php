<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once dirname(__DIR__) . '/auth_session.php';
require_once dirname(__DIR__) . '/diagnostics_access.php';
require_once dirname(__DIR__) . '/SmtpMailer.php';
require_once dirname(__DIR__) . '/intelligence/quality_helpers.php';
require_once dirname(__DIR__) . '/pipeline/helpers.php';
assert_same_origin();
require_method('POST');
$config = load_config();
$pdo = meteonexa_db($config);
$input = input_json();
$action = strtolower(trim((string)($input['action']??'summary')));
$device = clean_device_id($_SERVER['HTTP_X_METEONEXA_DEVICE_ID']??'');
$session = require_authenticated_device_session($pdo, $config, $device);
require_diagnostics_admin($pdo, $config, $session);
require_ip_rate_limit($pdo, 'diagnostics_ip', 90, 3600);
require_device_rate_limit($pdo, 'diagnostics_device', $device, 60, 3600);
function meteonexa_diag_check( ? bool $ok, string $labelKey, string $detailKey = '', array $params =[], ? array $meta = null) : array {
    return['ok'=>$ok, 'labelKey'=>$labelKey, 'detailKey'=>$detailKey, 'params'=>$params, 'meta'=>$meta];
}
if ($action==='summary') {
    $schema = (string)($pdo->query("SELECT COALESCE((SELECT meta_value FROM app_metadata WHERE meta_key='schema_version'),'')")->fetchColumn() ? : '');
    $aiRow = meteonexa_ai_settings_row($pdo);
    $aiOk = meteonexa_ai_row_is_usable($pdo, $config);
    $smtpOk = smtp_is_configured($config);
    $nativeMail = (bool)($config['smtp']['native_mail_fallback']??false)&&function_exists('mail');
    $pushStatement = $pdo->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE device_id=:device AND active=1');
    $pushStatement->execute([':device'=>$device]);
    $pushCount = (int)$pushStatement->fetchColumn();
    $smtpDetail = $smtpOk ? 'diag.result.smtp.auth' :($nativeMail ? 'diag.result.smtp.local' : 'diag.result.smtp.unavailable');
    $challengeFile = dirname(__DIR__) . '/privacy/cache-reset-challenge.php';
    $receiptFile = dirname(__DIR__) . '/privacy/cache-reset-receipt.php';
    $challengeSource = is_file($challengeFile) ? (string)@file_get_contents($challengeFile) : '';
    $receiptSource = is_file($receiptFile) ? (string)@file_get_contents($receiptFile) : '';
    $cacheVerifyOk = $challengeSource!==''&&$receiptSource!==''&&str_contains($challengeSource, 'guest-cache-reset-verify-v1')&&str_contains($challengeSource, 'privacy-cache-proof')&&str_contains($receiptSource, 'GUEST_EMAIL_NOT_VERIFIED')&&str_contains($receiptSource, 'cache_receipt_guest_proof_use');
    $requestCodePath = dirname(__DIR__) . '/auth/request-code.php';
    $verifyCodePath = dirname(__DIR__) . '/auth/verify-code.php';
    $requestSource = is_file($requestCodePath) ? (string)@file_get_contents($requestCodePath) : '';
    $verifySource = is_file($verifyCodePath) ? (string)@file_get_contents($verifyCodePath) : '';
    $deviceBoundOtp = $requestSource!==''&&$verifySource!==''&&str_contains($requestSource, 'meteonexa_otp_device_scope_hash')&&str_contains($verifySource, 'meteonexa_otp_device_scope_hash')&&!str_contains($requestSource, 'challengeId');
    $pipelineHealth = meteonexa_pipeline_health_summary($pdo);
    $pipelineConfigured = trim((string)($config['pipeline']['cron_secret']??''))!=='';
    $pipelineOk = $pipelineHealth['available']===true&&in_array((string)($pipelineHealth['status']??'unknown'),['ok', 'degraded'], true);
    $expectedSchema=(string)meteonexa_current_schema_version();
    $checks =[meteonexa_diag_check(true, 'diag.result.database.title', 'diag.result.database.ok'), meteonexa_diag_check($schema===$expectedSchema, 'diag.result.schema.title', $schema===$expectedSchema ? 'diag.result.schema.ok' : 'diag.result.schema.unexpected',['schema'=>$schema]), meteonexa_diag_check($smtpOk||$nativeMail, 'diag.result.smtp.title', $smtpDetail), meteonexa_diag_check($aiOk, 'diag.result.ai.title', $aiOk ? 'diag.result.ai.ok' : 'diag.result.ai.unavailable',[], $aiOk ?['provider'=>(string)($aiRow['provider']??''), 'model'=>(string)($aiRow['model']??'')] : null), meteonexa_diag_check($pushCount > 0 ? true : null, 'diag.result.push.title', $pushCount > 0 ? 'diag.result.push.active' : 'diag.result.push.inactive'), meteonexa_diag_check(extension_loaded('openssl'), 'diag.result.openssl.title', extension_loaded('openssl') ? 'diag.result.openssl.ok' : 'diag.result.openssl.missing'), meteonexa_diag_check(extension_loaded('curl')||(bool)ini_get('allow_url_fopen'), 'diag.result.http.title', extension_loaded('curl') ? 'diag.result.http.curl' :((bool)ini_get('allow_url_fopen') ? 'diag.result.http.wrapper' : 'diag.result.http.missing')), meteonexa_diag_check(meteonexa_db_table_exists($pdo, 'model_skill_samples')&&meteonexa_db_table_exists($pdo, 'forecast_run_snapshots')&&meteonexa_db_table_exists($pdo, 'saved_locations'), 'diag.result.quality.title', 'diag.result.quality.ok'), meteonexa_diag_check($pipelineOk, 'diag.result.pipeline.title', $pipelineOk ? 'diag.result.pipeline.ok' :($pipelineConfigured ? 'diag.result.pipeline.waiting' : 'diag.result.pipeline.cli'),[],['status'=>$pipelineHealth['status']??'unknown', 'providers'=>count((array)($pipelineHealth['providers']??[])), 'cronSecretConfigured'=>$pipelineConfigured]), meteonexa_diag_check(count(meteonexa_intelq_model_definitions())===6, 'diag.result.models.title', 'diag.result.models.ok',['count'=>count(meteonexa_intelq_model_definitions())]), meteonexa_diag_check(trim((string)($config['calibration']['cron_secret']??''))!=='' ? true : null, 'diag.result.calibration.title', trim((string)($config['calibration']['cron_secret']??''))!=='' ? 'diag.result.calibration.configured' : 'diag.result.calibration.cli',[],['horizons'=>[1, 3, 6, 24, 48, 72]]), meteonexa_diag_check(trim((string)($config['official']['meteoalarm_edr_token']??''))!=='' ? true : null, 'diag.result.edr.title', trim((string)($config['official']['meteoalarm_edr_token']??''))!=='' ? 'diag.result.edr.configured' : 'diag.result.edr.fallback'), meteonexa_diag_check($cacheVerifyOk, 'diag.result.cacheverify.title', $cacheVerifyOk ? 'diag.result.cacheverify.ok' : 'diag.result.cacheverify.missing'), meteonexa_diag_check($deviceBoundOtp&&meteonexa_db_table_exists($pdo, 'auth_access_history')&&is_file(dirname(__DIR__) . '/auth/devices.php'), 'diag.result.authdevices.title', 'diag.result.authdevices.ok',[],['deviceBoundOtp'=>$deviceBoundOtp, 'parallelOtp'=>true, 'historyTable'=>meteonexa_db_table_exists($pdo, 'auth_access_history'), 'historyRetentionDays'=>30])];
    respond(['ok'=>true, 'checks'=>$checks]);
}
if ($action==='pipeline') {
    require_device_rate_limit($pdo, 'diagnostics_pipeline', $device, 20, 3600);
    $health = meteonexa_pipeline_health_summary($pdo);
    $last = null;
    if (meteonexa_db_table_exists($pdo, 'weather_pipeline_runs'))$last = $pdo->query("SELECT worker_name,status,started_at,finished_at,duration_ms,locations_processed,success_count,failure_count FROM weather_pipeline_runs ORDER BY id DESC LIMIT 1")->fetch();
    respond(['ok'=>true, 'schema'=>(string)($pdo->query("SELECT COALESCE((SELECT meta_value FROM app_metadata WHERE meta_key='schema_version'),'')")->fetchColumn() ? : ''), 'health'=>$health, 'lastRun'=>is_array($last) ? $last : null, 'cronSecretConfigured'=>trim((string)($config['pipeline']['cron_secret']??''))!=='', 'worker'=>'api/pipeline/worker.php', 'noWrites'=>true]);
}
if ($action==='weather') {
    require_device_rate_limit($pdo, 'diagnostics_weather', $device, 12, 3600);
    $started = microtime(true);
    try {
        $weather = meteonexa_intelligence_weather(45.0, 9.0, true);
        $current = is_array($weather['current']??null) ? $weather['current'] :[];
        if ($current===[])throw new RuntimeException('WEATHER_EMPTY');
        respond(['ok'=>true, 'source'=>'Open-Meteo', 'elapsedMs'=>(int)round((microtime(true) - $started) * 1000)]);
    } catch (Throwable $error) {
        meteonexa_log_event('diagnostics_weather_failed', $error);
        respond(['ok'=>false, 'code'=>'WEATHER_TEST_FAILED', 'message'=>'diag.api.weather_failed'], 502);
    }
}
if ($action==='engine') {
    $base = (int)(floor(time() / 3600) * 3600);
    $times =[];
    $zeros =[];
    $temps =[];
    $visibility =[];
    for ($i = 0; $i < 36; $i++) {
        $times[] = gmdate('Y-m-d\\TH:00', $base + $i * 3600);
        $zeros[] = 0;
        $temps[] = 18;
        $visibility[] = 10000;
    }
    $minuteTimes =[];
    $minute =[];
    for ($i = 0; $i < 8; $i++) {
        $minuteTimes[] = gmdate('Y-m-d\\TH:i', $base + $i * 900);
        $minute[] = 0;
    }
    $minute[1] = 0.9;
    $weather =['hourly'=>['time'=>$times, 'precipitation_probability'=>$zeros, 'precipitation'=>$zeros, 'wind_gusts_10m'=>$zeros, 'temperature_2m'=>$temps, 'snowfall'=>$zeros, 'visibility'=>$visibility, 'cape'=>$zeros, 'weather_code'=>$zeros], 'minutely_15'=>['time'=>$minuteTimes, 'precipitation'=>$minute]];
    $weather['hourly']['precipitation_probability'][0] = 86;
    $weather['hourly']['precipitation'][0] = 1.4;
    $profile = meteonexa_intelligence_profile([]);
    $ctx =['accuracy'=>['score'=>78], 'radarMotion'=>['available'=>true, 'direction'=>'E', 'confidence'=>82, 'etaMinutes'=>15], 'lightning'=>['available'=>false, 'recent30m'=>0, 'nearestKm'=>null], 'satellite'=>['available'=>false], 'official'=>['relevant'=>[]], 'hyperlocal'=>['available'=>false]];
    $analysis = meteonexa_intelligence_analyze($weather, null, $profile, $ctx);
    $event = is_array($analysis['events'][0]??null) ? $analysis['events'][0] : null;
    if (!is_array($event))respond(['ok'=>false, 'code'=>'ENGINE_TEST_FAILED', 'message'=>'diag.api.engine_failed'], 500);
    respond(['ok'=>true, 'event'=>array_intersect_key($event, array_flip(['type', 'horizon', 'severity', 'confidence', 'etaMinutes', 'title', 'body']))]);
}
if ($action==='quality') {
    require_device_rate_limit($pdo, 'diagnostics_quality', $device, 20, 3600);
    $base = (int)(floor(time() / 3600) * 3600);
    $definitions = meteonexa_intelq_model_definitions();
    $models =[];
    foreach ($definitions as $id=>$definition) {
        $rows =[];
        for ($i = 0; $i < 8; $i++) {
            $rows[] =['time'=>gmdate('c', $base + $i * 3600), 'timestamp'=>$base + $i * 3600, 'temperature'=>20 + $i * .2, 'precipitation'=>$i>=2&&$i<=4 ? .8 : 0, 'weatherCode'=>$i>=2&&$i<=4 ? 95 : 1, 'windGust'=>25 + $i];
        }
        $models[$id] =['id'=>$id, 'label'=>$definition['label'], 'available'=>true, 'rows'=>$rows, 'retrievedAt'=>gmdate('c'), 'ageMinutes'=>0, 'cadenceMinutes'=>$definition['cadenceMinutes']];
    }
    // Make one deterministic outlier so consensus voting can be checked against the current provider set.
    $outlierId = array_key_last($models);
    if ($outlierId!==null) {
        foreach ($models[$outlierId]['rows'] as &$row) {
            $row['precipitation'] = 0;
            $row['weatherCode'] = 1;
        }
        unset($row);
    }
    $consensus = meteonexa_intelq_consensus($models);
    $primary = (array)($consensus['primary']??[]);
    $polygon =['type'=>'Polygon', 'coordinates'=>[[[8.8, 44.0],[9.8, 44.0],[9.8, 45.0],[8.8, 45.0],[8.8, 44.0]]]];
    $checks =['modelsExpected'=>$consensus['modelsExpected']??0, 'modelsAvailable'=>$consensus['modelsAvailable']??0, 'votes'=>$primary['votes']??0, 'agreementPct'=>$primary['agreementPct']??0, 'modelVoteYesCount'=>count(array_filter((array)($primary['modelVotes']??[]))), 'agreementConsistent'=>((int)($primary['agreementPct']??0)===(int)round(100 * (int)($primary['votes']??0) / max(1, (int)($primary['available']??0))))&&count(array_filter((array)($primary['modelVotes']??[])))===(int)($primary['votes']??0), 'brierExample'=>round(meteonexa_intelq_brier(.8, 1), 4), 'pointInPolygon'=>meteonexa_intelq_geometry_contains($polygon, 44.5, 9.2), 'horizons'=>[1, 3, 6, 24, 48, 72], 'workerPresent'=>is_file(dirname(__DIR__) . '/calibration/worker.php'), 'previousRunsModule'=>function_exists('meteonexa_intelq_previous_runs'), 'cellTrackingModule'=>function_exists('meteonexa_intelq_cell_tracking'), 'decisionEngineModule'=>function_exists('meteonexa_intelq_decision_windows'), 'rainDirectionProxy'=>is_file(dirname(__DIR__) . '/intelligence/rain-direction.php'), 'providerStaleFallback'=>str_contains((string)@file_get_contents(dirname(__DIR__) . '/intelligence/engine_helpers.php'), 'staleTtl'),];
    $visibilityExpected =['feature.intelligence.consensus'=>[1, 1], 'feature.intelligence.skill'=>[0, 1], 'feature.intelligence.change'=>[0, 1], 'feature.intelligence.explainability'=>[1, 1], 'feature.intelligence.celltracking'=>[0, 1], 'feature.intelligence.decision'=>[1, 1], 'feature.intelligence.locations'=>[0, 1],];
    $visibilityOk = true;
    $visibility =[];
    foreach ($visibilityExpected as $feature=>[$guestExpected, $authExpected]) {
        $st = $pdo->prepare('SELECT guest_visible,authenticated_visible FROM ui_visibility WHERE feature_key=:feature');
        $st->execute([':feature'=>$feature]);
        $row = $st->fetch();
        $visibility[$feature] = is_array($row) ?[(int)$row['guest_visible'], (int)$row['authenticated_visible']] : null;
        if (!is_array($row)||(int)$row['guest_visible']!==$guestExpected||(int)$row['authenticated_visible']!==$authExpected)$visibilityOk = false;
    }
    $checks['uiVisibility'] = $visibility;
    $checks['uiVisibilityDefaults'] = $visibilityOk;
    $decisions = meteonexa_intelq_decision_windows($models);
    $ski = array_values(array_filter($decisions, static fn($row)=>($row['activity']??'')==='ski'));
    $checks['decisionSkiGuard'] = $ski!==[]&&(int)$ski[0]['score'] < 30;
    $expectedModels = count($definitions);
    $expectedVotes = max(0, $expectedModels - 1);
    $expectedAgreement = $expectedModels > 0 ? (int)round(100 * $expectedVotes / $expectedModels) : 0;
    $ok = $checks['modelsExpected']===$expectedModels&&$checks['modelsAvailable']===$expectedModels&&$checks['votes']===$expectedVotes&&$checks['agreementPct']===$expectedAgreement&&$checks['agreementConsistent']===true&&$checks['pointInPolygon']===true&&$checks['workerPresent']&&$checks['rainDirectionProxy']&&$checks['providerStaleFallback']&&$checks['decisionSkiGuard']&&$visibilityOk;
    respond(['ok'=>$ok, 'engine'=>'20.1', 'checks'=>$checks], $ok ? 200 : 500);
}
if ($action==='calibration') {
    require_device_rate_limit($pdo, 'diagnostics_calibration', $device, 20, 3600);
    $counts =[];
    foreach (['model_skill_samples', 'forecast_run_snapshots', 'saved_locations'] as $table) {
        $counts[$table] = meteonexa_db_table_exists($pdo, $table) ? (int)$pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() : null;
    }
    $savedForDevice = 0;
    if (meteonexa_db_table_exists($pdo, 'saved_locations')) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM saved_locations WHERE device_id=:device AND active=1');
        $st->execute([':device'=>$device]);
        $savedForDevice = (int)$st->fetchColumn();
    }
    respond(['ok'=>true, 'mode'=>'dry-run', 'worker'=>'api/calibration/worker.php', 'httpSecretConfigured'=>trim((string)($config['calibration']['cron_secret']??''))!=='', 'horizons'=>[1, 3, 6, 24, 48, 72], 'savedLocationsForDevice'=>$savedForDevice, 'counts'=>$counts, 'noWrites'=>true]);
}
if ($action==='authdevices') {
    require_device_rate_limit($pdo, 'diagnostics_authdevices', $device, 20, 3600);
    $email = strtolower(trim((string)($session['email']??'')));
    $emailHash = meteonexa_hmac_identifier('session-email:' . $email, auth_secret($config));
    $active = 0;
    $history = 0;
    if (meteonexa_db_table_exists($pdo, 'auth_sessions')) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM auth_sessions WHERE email_hash=:email');
        $st->execute([':email'=>$emailHash]);
        $active = (int)$st->fetchColumn();
    }
    if (meteonexa_db_table_exists($pdo, 'auth_access_history')) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM auth_access_history WHERE email_hash=:email');
        $st->execute([':email'=>$emailHash]);
        $history = (int)$st->fetchColumn();
    }
    $requestPath = dirname(__DIR__) . '/auth/request-code.php';
    $verifyPath = dirname(__DIR__) . '/auth/verify-code.php';
    $requestSource = is_file($requestPath) ? (string)@file_get_contents($requestPath) : '';
    $verifySource = is_file($verifyPath) ? (string)@file_get_contents($verifyPath) : '';
    $deviceBoundOtp = $requestSource!==''&&$verifySource!==''&&str_contains($requestSource, 'meteonexa_otp_device_scope_hash')&&str_contains($verifySource, 'meteonexa_otp_device_scope_hash')&&!str_contains($requestSource, 'challengeId');
    respond(['ok'=>$deviceBoundOtp, 'schema'=>(string)($pdo->query("SELECT COALESCE((SELECT meta_value FROM app_metadata WHERE meta_key='schema_version'),'')")->fetchColumn() ? : ''), 'activeSessions'=>$active, 'historyRows'=>$history, 'deviceBoundOtp'=>$deviceBoundOtp, 'otpMode'=>'device-scoped-auth_otp', 'parallelOtpChallenges'=>true, 'historyTable'=>meteonexa_db_table_exists($pdo, 'auth_access_history'), 'historyRetentionDays'=>30, 'noWrites'=>true], $deviceBoundOtp ? 200 : 500);
}
if ($action==='smtp') {
    require_device_rate_limit($pdo, 'diagnostics_smtp', $device, 3, 3600);
    $email = trim((string)($session['email']??''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))respond(['ok'=>false, 'code'=>'EMAIL_INVALID', 'message'=>'diag.api.session_email_missing'], 422);
    $smtp = (array)($config['smtp']??[]);
    $nativeMail = (bool)($smtp['native_mail_fallback']??false)&&function_exists('mail');
    $subject = meteonexa_backend_text('diag.mail.subject');
    $heading = meteonexa_backend_text('diag.mail.heading');
    $copy = meteonexa_backend_text('diag.mail.copy');
    $timestamp = gmdate('c');
    $timeLabel = meteonexa_backend_text('diag.mail.time');
    $html = '<!doctype html><html><body style="font-family:Arial,sans-serif"><h2>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h2><p>' . htmlspecialchars($copy, ENT_QUOTES, 'UTF-8') . '</p><p>' . htmlspecialchars($timeLabel, ENT_QUOTES, 'UTF-8') . ': ' . htmlspecialchars($timestamp, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
    $plain = $heading . "\n" . $copy . "\n" . $timeLabel . ': ' . $timestamp;
    try {
        if (smtp_is_configured($config)) {
            (new SmtpMailer($smtp))->sendHtml($email, $subject, $html, $plain, dirname(__DIR__, 2) . '/assets/icons/icon-192.png');
            respond(['ok'=>true, 'transport'=>'smtp']);
        }
        if ($nativeMail) {
            SmtpMailer::sendNativeHtml($smtp, $email, $subject, $html, $plain, dirname(__DIR__, 2) . '/assets/icons/icon-192.png');
            respond(['ok'=>true, 'transport'=>'mail']);
        }
        respond(['ok'=>false, 'code'=>'EMAIL_TRANSPORT_UNAVAILABLE', 'message'=>'diag.api.email_unavailable'], 503);
    } catch (Throwable $error) {
        meteonexa_log_event('diagnostics_smtp_failed', $error);
        respond(['ok'=>false, 'code'=>'EMAIL_TEST_FAILED', 'message'=>'diag.api.email_failed'], 502);
    }
}
respond(['ok'=>false, 'code'=>'DIAGNOSTIC_ACTION_INVALID', 'message'=>'diag.api.action_invalid'], 422);
