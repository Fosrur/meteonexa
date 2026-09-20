<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/auth/i18n.php';
require_once dirname(__DIR__) . '/SmtpMailer.php';
require_once dirname(__DIR__) . '/auth_session.php';
require_once dirname(__DIR__) . '/public_helpers.php';
assert_same_origin();
if (strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST') {
    header('Allow: POST');
    respond(['ok'=>false, 'code'=>'METHOD_NOT_ALLOWED', 'message'=>'api.backend.method_not_allowed'], 405);
}
$config = load_config();
$data = input_json();
$language = meteonexa_auth_language($data['language']??'it');
$pdo = meteonexa_db($config);
$tr = static fn(string $source, array $params =[]) : string=>meteonexa_auth_translate($pdo, $language, $source, $params);
$secret = auth_secret($config);
$action = strtolower(trim((string)($data['action']??'start')));
// This endpoint is guest-only. If a stale server session survived a browser
// reset, close it before issuing a guest verification challenge. A verified
// email session must never be silently re-used as a guest receipt identity.
$session = meteonexa_current_auth_session($pdo, $config, false);
if (is_array($session)) {
    $proofOk = meteonexa_verify_device_proof($pdo, (string)($session['device_id']??''));
    if ($proofOk) {
        // A valid authenticated session must use the server-derived recipient flow.
        // Do not revoke it merely because the guest challenge endpoint was called.
        respond(['ok'=>false, 'code'=>'AUTH_SESSION_ACTIVE', 'message'=>$tr('settings.cache.verify.auth_session_closed')], 409);
    }
    try {
        meteonexa_revoke_current_auth_session($pdo, $config);
    } catch (Throwable $error) {
        meteonexa_log_event('cache_challenge_stale_session_revoke_failed', $error);
    }
    try {
        meteonexa_revoke_current_trusted_device($pdo, $config);
    } catch (Throwable $error) {
        meteonexa_log_event('cache_challenge_stale_trusted_revoke_failed', $error);
    }
}
meteonexa_prune_security_state($pdo);
if ($action==='start') {
    $email = normalize_email((string)($data['email']??''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)||strlen($email) > 254) {
        respond(['ok'=>false, 'code'=>'INVALID_EMAIL', 'message'=>$tr('settings.cache.receipt.invalid_email')], 422);
    }
    $ipLimit = meteonexa_rate_limit($pdo, 'cache_verify_start_ip', client_ip(), $secret, 8, 3600);
    $recipientLimit = meteonexa_rate_limit($pdo, 'cache_verify_start_recipient', strtolower($email), $secret, 3, 3600);
    $pairLimit = meteonexa_rate_limit($pdo, 'cache_verify_start_pair', client_ip() . '|' . strtolower($email), $secret, 3, 3600);
    $globalLimit = meteonexa_rate_limit($pdo, 'cache_verify_start_global', 'all', $secret, 250, 3600);
    if (!$ipLimit['allowed']||!$recipientLimit['allowed']||!$pairLimit['allowed']||!$globalLimit['allowed']) {
        $retryAfter = max(1, (int)$ipLimit['retryAfter'], (int)$recipientLimit['retryAfter'], (int)$pairLimit['retryAfter'], (int)$globalLimit['retryAfter']);
        header('Retry-After: ' . $retryAfter);
        respond(['ok'=>false, 'code'=>'RATE_LIMITED', 'message'=>$tr('settings.cache.verify.rate_limited'), 'retryAfter'=>$retryAfter], 429);
    }
    if (!smtp_is_configured($config)) {
        try {
            meteonexa_provision_smtp_if_missing($config);
            $databaseSmtp = meteonexa_load_smtp($config);
            if ($databaseSmtp!==[])$config['smtp'] = array_replace((array)($config['smtp']??[]), $databaseSmtp);
        } catch (Throwable $error) {
            meteonexa_log_event('smtp_cache_challenge_provision_failed', $error);
        }
    }
    $nativeMailEnabled = (bool)($config['smtp']['native_mail_fallback']??false)&&function_exists('mail');
    if (!smtp_is_configured($config)&&!$nativeMailEnabled) {
        respond(['ok'=>false, 'code'=>'EMAIL_TRANSPORT_UNAVAILABLE', 'message'=>$tr('settings.cache.verify.send_failed')], 503);
    }
    $ttl = 600;
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $nonce = bin2hex(random_bytes(16));
    $payload = json_encode(['purpose'=>'guest-cache-reset-verify-v1', 'email'=>$email, 'codeHash'=>hash_hmac('sha256', $code, $secret), 'nonce'=>$nonce, 'issuedAt'=>time(), 'expiresAt'=>time() + $ttl,], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload))respond(['ok'=>false, 'code'=>'CHALLENGE_CREATE_FAILED', 'message'=>$tr('settings.cache.verify.send_failed')], 500);
    $challengeToken = meteonexa_encrypt_value($payload, $config, 'privacy-cache-challenge');
    $appName = $tr('app.name');
    if ($appName==='app.name'||trim($appName)==='')$appName = (string)($config['app']['name']??'MeteoNexa');
    $escapedAppName = htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $escapedCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $tagline = htmlspecialchars($tr('auth.email.tagline'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $heading = htmlspecialchars($tr('privacy.cache.verify.email.heading'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $instructions = htmlspecialchars($tr('privacy.cache.verify.email.instructions',['minutes'=>10]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safety = htmlspecialchars($tr('privacy.cache.verify.email.safety'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $subject = $tr('privacy.cache.verify.email.subject');
    $html = '<!doctype html><html lang="' . htmlspecialchars($language, ENT_QUOTES, 'UTF-8') . '"><body style="margin:0;padding:0;background:#f3f7fb;font-family:Arial,Helvetica,sans-serif;color:#15314a">' . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0;background:#f3f7fb;border-collapse:collapse"><tr><td align="center" style="padding:32px 14px">' . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:560px;margin:0 auto;border-collapse:separate;border-spacing:0;background:#fff;border:1px solid #d9e6f1;border-radius:22px;overflow:hidden">' . '<tr><td align="center" style="padding:30px 34px 25px;background:#0d3557"><img src="cid:meteonexa-logo" width="68" height="68" alt="' . $escapedAppName . '" style="display:block;width:68px;height:68px;margin:0 auto 12px;border:0"><div style="color:#fff;font-size:25px;font-weight:800">' . $escapedAppName . '</div><div style="margin-top:7px;color:#c7e4f4;font-size:13px">' . $tagline . '</div></td></tr>' . '<tr><td style="padding:30px 34px 12px"><h1 style="margin:0 0 12px;color:#102d47;font-size:24px">' . $heading . '</h1><p style="margin:0;color:#58718a;font-size:15px;line-height:1.65">' . $instructions . '</p></td></tr>' . '<tr><td style="padding:14px 34px 18px"><div style="padding:20px 16px;text-align:center;border:1px solid #b9dceb;border-radius:16px;background:#edf8fc;color:#087f92;font-size:34px;font-weight:900;letter-spacing:9px">' . $escapedCode . '</div></td></tr>' . '<tr><td style="padding:0 34px 31px"><div style="padding-top:16px;border-top:1px solid #e5edf4;color:#72879a;font-size:12px;line-height:1.6">' . $safety . '</div></td></tr>' . '</table></td></tr></table></body></html>';
    $plain = $tr('privacy.cache.verify.email.plain',['code'=>$code, 'minutes'=>10]) . "\n\n" . $tr('privacy.cache.verify.email.safety');
    $smtpConfig = (array)($config['smtp']??[]);
    if (trim((string)($smtpConfig['from_name']??''))===''||trim((string)($smtpConfig['from_name']??''))==='MeteoNexa')$smtpConfig['from_name'] = $appName;
    $sent = false;
    $lastError = null;
    try {
        $smtpUsable = trim((string)($smtpConfig['host']??''))!==''&&filter_var(trim((string)($smtpConfig['from_email']??'')), FILTER_VALIDATE_EMAIL)!==false&&(trim((string)($smtpConfig['username']??''))===''||(string)($smtpConfig['password']??'')!=='');
        if ($smtpUsable) {
            (new SmtpMailer($smtpConfig))->sendHtml($email, $subject, $html, $plain, dirname(__DIR__, 2) . '/assets/icons/icon-192.png');
            $sent = true;
        }
    } catch (Throwable $error) {
        $lastError = $error;
    }
    if (!$sent&&$nativeMailEnabled) {
        try {
            SmtpMailer::sendNativeHtml($smtpConfig, $email, $subject, $html, $plain, dirname(__DIR__, 2) . '/assets/icons/icon-192.png');
            $sent = true;
        } catch (Throwable $error) {
            $lastError = $error;
        }
    }
    if (!$sent) {
        if ($lastError instanceof Throwable)meteonexa_log_event('cache_challenge_send_failed', $lastError);
        respond(['ok'=>false, 'code'=>'EMAIL_SEND_FAILED', 'message'=>$tr('settings.cache.verify.send_failed')], 503);
    }
    respond(['ok'=>true, 'challengeToken'=>$challengeToken, 'maskedEmail'=>mask_email($email), 'expiresIn'=>$ttl]);
}
if ($action==='verify') {
    $token = trim((string)($data['challengeToken']??''));
    $code = preg_replace('/\D+/', '', (string)($data['code']??''))??'';
    if ($token===''||preg_match('/^\d{6}$/', $code)!==1) {
        respond(['ok'=>false, 'code'=>'INVALID_CODE', 'message'=>$tr('settings.cache.verify.invalid_code')], 422);
    }
    try {
        $decoded = json_decode(meteonexa_decrypt_value($token, $config, 'privacy-cache-challenge'), true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        respond(['ok'=>false, 'code'=>'CHALLENGE_INVALID', 'message'=>$tr('settings.cache.verify.expired')], 422);
    }
    if (!is_array($decoded)||($decoded['purpose']??'')!=='guest-cache-reset-verify-v1'||(int)($decoded['expiresAt']??0) < time()) {
        respond(['ok'=>false, 'code'=>'CHALLENGE_EXPIRED', 'message'=>$tr('settings.cache.verify.expired')], 422);
    }
    $nonce = (string)($decoded['nonce']??'');
    $email = normalize_email((string)($decoded['email']??''));
    if ($nonce===''||!filter_var($email, FILTER_VALIDATE_EMAIL))respond(['ok'=>false, 'code'=>'CHALLENGE_INVALID', 'message'=>$tr('settings.cache.verify.expired')], 422);
    $attempt = meteonexa_rate_limit($pdo, 'cache_verify_attempt', $nonce, $secret, 6, 900);
    if (!$attempt['allowed']) {
        header('Retry-After: ' . max(1, (int)$attempt['retryAfter']));
        respond(['ok'=>false, 'code'=>'VERIFY_LOCKED', 'message'=>$tr('settings.cache.verify.too_many_attempts'), 'retryAfter'=>max(1, (int)$attempt['retryAfter'])], 429);
    }
    $expected = (string)($decoded['codeHash']??'');
    if ($expected===''||!hash_equals($expected, hash_hmac('sha256', $code, $secret))) {
        respond(['ok'=>false, 'code'=>'INVALID_CODE', 'message'=>$tr('settings.cache.verify.invalid_code'), 'remaining'=>$attempt['remaining']], 422);
    }
    $firstVerify = meteonexa_rate_limit($pdo, 'cache_verify_success', $nonce, $secret, 1, 900);
    if (!$firstVerify['allowed'])respond(['ok'=>false, 'code'=>'CHALLENGE_USED', 'message'=>$tr('settings.cache.verify.used')], 409);
    $proofPayload = json_encode(['purpose'=>'guest-cache-reset-proof-v1', 'email'=>$email, 'nonce'=>bin2hex(random_bytes(16)), 'verifiedAt'=>time(), 'expiresAt'=>time() + 300,], JSON_UNESCAPED_SLASHES);
    if (!is_string($proofPayload))respond(['ok'=>false, 'code'=>'PROOF_CREATE_FAILED', 'message'=>$tr('settings.cache.verify.invalid_code')], 500);
    $guestProof = meteonexa_encrypt_value($proofPayload, $config, 'privacy-cache-proof');
    respond(['ok'=>true, 'verified'=>true, 'guestProof'=>$guestProof, 'maskedEmail'=>mask_email($email), 'expiresIn'=>300]);
}
respond(['ok'=>false, 'code'=>'ACTION_INVALID', 'message'=>'api.backend.invalid_request'], 422);
