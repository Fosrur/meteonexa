<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/i18n.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once dirname(__DIR__) . '/SmtpMailer.php';
require_once dirname(__DIR__) . '/email_templates.php';
require_once dirname(__DIR__) . '/auth_session.php';

assert_same_origin();
$config = load_config();
$data = input_json();
$language = meteonexa_auth_language($data['language'] ?? 'it');
$pdo = meteonexa_db($config);
$deviceId = clean_device_id($_SERVER['HTTP_X_METEONEXA_DEVICE_ID'] ?? '');
$deviceKey = meteonexa_device_key();
$tr = static fn(string $source, array $params = []): string => meteonexa_auth_translate($pdo, $language, $source, $params);
$email = normalize_email($data['email'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    respond(['ok' => false, 'code' => 'INVALID_EMAIL', 'message' => $tr('auth.error.invalid_email')], 422);
}

// A remembered device may re-enter the same identity without another OTP.
// The decision is not based on the email alone: the HttpOnly trusted-device
// cookie, the encrypted email binding and the browser-held device proof must
// all match. A normal logout preserves this credential; a full cache/device
// reset revokes it explicitly.
try {
    $trustedDevice = meteonexa_current_trusted_device($pdo, $config, $email);
    if (is_array($trustedDevice)) {
        $displayName = trim((string)($trustedDevice['display_name'] ?? ''));
        if ($displayName === '') {
            $localPart = explode('@', $email, 2)[0] ?? $tr('auth.user.default_name');
            $displayName = ucfirst(preg_replace('/[^a-z0-9._-]/i', '', $localPart) ?: $tr('auth.user.default_name'));
        }
        $deviceId = (string)$trustedDevice['device_id'];
        $session = meteonexa_issue_auth_session($pdo, $config, $email, $displayName, $deviceId);
        respond([
            'ok'=>true,
            'authenticated'=>true,
            'trustedDevice'=>true,
            'displayName'=>$displayName,
            'email'=>$email,
            'language'=>$language,
            'sessionExpiresAt'=>(int)$session['expiresAt'],
        ]);
    }
} catch (Throwable $trustedDeviceError) {
    meteonexa_log_event('trusted_device_reentry_failed', $trustedDeviceError);
}
// SMTP provisioning is opportunistic. On shared hosting the application also
// supports PHP's local mail transport, so a missing/stale SMTP credential must
// not block OTP delivery before the fallback has been attempted.
if (!smtp_is_configured($config)) {
    try {
        meteonexa_provision_smtp_if_missing($config);
        $databaseSmtp = meteonexa_load_smtp($config);
        if ($databaseSmtp !== []) {
            $config['smtp'] = array_replace((array)($config['smtp'] ?? []), $databaseSmtp);
        }
    } catch (Throwable $smtpProvisionError) {
        meteonexa_log_event('smtp_request_code_provision_failed', $smtpProvisionError);
    }
}
$nativeMailEnabled = (bool)($config['smtp']['native_mail_fallback'] ?? false) && function_exists('mail');
if (!smtp_is_configured($config) && !$nativeMailEnabled) {
    respond(['ok' => false, 'code' => 'EMAIL_TRANSPORT_UNAVAILABLE', 'message' => $tr('auth.error.send_failed')], 503);
}

$secret = auth_secret($config);
$auth = $config['auth'] ?? [];
$ttl = max(300, min(1800, (int)($auth['otp_ttl_seconds'] ?? 600)));
$resendAfter = max(30, min(300, (int)($auth['resend_after_seconds'] ?? 60)));
$maxPerHour = max(3, min(20, (int)($auth['max_requests_per_hour'] ?? 8)));
$maxPerIp = max($maxPerHour, min(120, (int)($auth['max_requests_per_ip_hour'] ?? 30)));
$maxGlobal = max($maxPerIp, min(5000, (int)($auth['max_requests_global_hour'] ?? 300)));
$now = time();
$emailHash = meteonexa_otp_device_scope_hash($config, $email, $deviceId, $deviceKey);

meteonexa_prune_security_state($pdo);
$previous = $pdo->prepare('SELECT sent_at FROM auth_otp WHERE email_hash=:email LIMIT 1');
$previous->execute([':email'=>$emailHash]);
$previousSent = (int)($previous->fetchColumn() ?: 0);
if ($previousSent > $now - $resendAfter) {
    $remaining = max(1, $resendAfter - ($now - $previousSent));
    respond([
        'ok' => false,
        'code' => 'TOO_SOON',
        'message' => $tr('auth.error.too_soon', ['seconds' => $remaining]),
        'retryAfter' => $remaining,
    ], 429);
}

$ipLimit = meteonexa_rate_limit($pdo, 'auth_request_ip', client_ip(), $secret, $maxPerIp, 3600);
$emailLimit = meteonexa_rate_limit($pdo, 'auth_request_email', $email, $secret, $maxPerHour, 3600);
$globalLimit = meteonexa_rate_limit($pdo, 'auth_request_global', 'all', $secret, $maxGlobal, 3600);
$pairLimit = meteonexa_rate_limit($pdo, 'auth_request_pair', client_ip() . '|' . $email, $secret, $maxPerHour, 3600);
if (!$ipLimit['allowed'] || !$emailLimit['allowed'] || !$globalLimit['allowed'] || !$pairLimit['allowed']) {
    $retryAfter = max(1, (int)$ipLimit['retryAfter'], (int)$emailLimit['retryAfter'], (int)$globalLimit['retryAfter'], (int)$pairLimit['retryAfter']);
    header('Retry-After: ' . $retryAfter);
    respond(['ok' => false, 'code' => 'RATE_LIMITED', 'message' => $tr('auth.error.rate_limited'), 'retryAfter'=>$retryAfter], 429);
}

$code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expiresAt = $now + $ttl;
$upsert = $pdo->prepare('INSERT INTO auth_otp(email_hash,language,code_hash,sent_at,expires_at,attempts,ip_hash,updated_at)
    VALUES(:email,:language,:code,:sent,:expires,0,:ip,:updated)
    ON CONFLICT(email_hash) DO UPDATE SET language=excluded.language,code_hash=excluded.code_hash,sent_at=excluded.sent_at,expires_at=excluded.expires_at,attempts=0,ip_hash=excluded.ip_hash,updated_at=excluded.updated_at');
$upsert->execute([
    ':email'=>$emailHash,
    ':language'=>$language,
    ':code'=>password_hash($code, PASSWORD_DEFAULT),
    ':sent'=>$now,
    ':expires'=>$expiresAt,
    ':ip'=>meteonexa_hmac_identifier(client_ip(), $secret),
    ':updated'=>gmdate('c'),
]);

$appName = $tr('app.name');
if ($appName === 'app.name' || trim($appName) === '') $appName = (string)($config['app']['name'] ?? 'MeteoNexa');
$minutes = (int)ceil($ttl / 60);
$template = meteonexa_email_template($pdo, 'auth_otp', $language, [
    'minutes'=>$minutes, 'app_name'=>$appName,
]);
$escapedCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$contentHtml = '<div style="padding:20px 16px;text-align:center;border:1px solid #b9dceb;border-radius:16px;background:#edf8fc;color:#087f92;font-size:34px;line-height:1.15;font-weight:900;letter-spacing:9px">' . $escapedCode . '</div>';
$contentPlain = $tr('auth.email.plain.code', ['code'=>$code]) . "\n\n"
    . $tr('auth.email.plain.validity', ['minutes'=>$minutes]) . "\n"
    . $tr('auth.email.plain.ignore');
$mail = meteonexa_render_branded_email($template, $appName, $language, $contentHtml, $contentPlain);
$html = $mail['html'];
$plain = $mail['plain'];
$subject = $mail['subject'];

$sendOtp = static function(array $smtpConfig) use ($appName, $email, $subject, $html, $plain, $nativeMailEnabled): string {
    if (trim((string)($smtpConfig['from_name'] ?? '')) === '' || trim((string)($smtpConfig['from_name'] ?? '')) === 'MeteoNexa') {
        $smtpConfig['from_name'] = $appName;
    }
    $smtpError = null;
    $smtpUsable = trim((string)($smtpConfig['host'] ?? '')) !== ''
        && filter_var(trim((string)($smtpConfig['from_email'] ?? '')), FILTER_VALIDATE_EMAIL) !== false
        && (trim((string)($smtpConfig['username'] ?? '')) === '' || (string)($smtpConfig['password'] ?? '') !== '');
    if ($smtpUsable) {
        try {
            $mailer = new SmtpMailer($smtpConfig);
            $mailer->sendHtml($email, $subject, $html, $plain, dirname(__DIR__, 2) . '/assets/icons/icon-192.png');
            return 'smtp';
        } catch (Throwable $error) {
            $smtpError = $error;
        }
    }
    if ($nativeMailEnabled) {
        try {
            SmtpMailer::sendNativeHtml($smtpConfig, $email, $subject, $html, $plain, dirname(__DIR__, 2) . '/assets/icons/icon-192.png');
            return 'mail';
        } catch (Throwable $mailError) {
            if ($smtpError === null) $smtpError = $mailError;
            else meteonexa_log_event('native_mail_fallback_failed', $mailError);
        }
    }
    if ($smtpError instanceof Throwable) throw $smtpError;
    throw new RuntimeException('EMAIL_TRANSPORT_UNAVAILABLE');
};

try {
    $mailTransport = $sendOtp((array)($config['smtp'] ?? []));
} catch (Throwable $error) {
    $pdo->prepare('DELETE FROM auth_otp WHERE email_hash=:email')->execute([':email'=>$emailHash]);
    $rawCode = (string)$error->getMessage();
    $stableError = preg_match('/^(?:SMTP|MAIL|EMAIL)_[A-Z0-9_]+$/', $rawCode) === 1 ? $rawCode : 'EMAIL_UNKNOWN';
    meteonexa_log_event('email_send_failed.' . $stableError, $error);
    respond([
        'ok' => false,
        'code' => 'EMAIL_SEND_FAILED',
        'message' => $tr('auth.error.send_failed'),
    ], 503);
}

respond(['ok'=>true,'maskedEmail'=>mask_email($email),'expiresIn'=>$ttl,'resendAfter'=>$resendAfter,'language'=>$language]);
