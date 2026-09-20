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
$session = meteonexa_current_auth_session($pdo, $config, false);
if (is_array($session)&&!meteonexa_verify_device_proof($pdo, (string)($session['device_id']??''))) {
    meteonexa_revoke_current_auth_session($pdo, $config);
    $session = null;
}
// Guest receipts require_once a short-lived proof issued only after the guest has
// entered the six-digit code delivered to the requested mailbox. The endpoint
// deliberately ignores arbitrary guest email fields, so it cannot be abused to
// send a false "your data were deleted" receipt to an unverified address.
$guestRequested =($data['guest']??false)===true;
if ($guestRequested&&is_array($session)) {
    try {
        meteonexa_revoke_current_auth_session($pdo, $config);
    } catch (Throwable $error) {
        meteonexa_log_event('cache_receipt_guest_stale_session_revoke_failed', $error);
    }
    try {
        meteonexa_revoke_current_trusted_device($pdo, $config);
    } catch (Throwable $error) {
        meteonexa_log_event('cache_receipt_guest_stale_trusted_revoke_failed', $error);
    }
    $session = null;
}
$authenticated = is_array($session);
$email = $authenticated ? normalize_email((string)($session['email']??'')) : '';
$guestProofNonce = '';
if (!$authenticated) {
    $proofToken = trim((string)($data['guestProof']??''));
    if (!$guestRequested||$proofToken==='') {
        respond(['ok'=>false, 'code'=>'GUEST_EMAIL_NOT_VERIFIED', 'message'=>$tr('settings.cache.verify.required'), 'sessionRevoked'=>false], 403);
    }
    try {
        $proof = json_decode(meteonexa_decrypt_value($proofToken, $config, 'privacy-cache-proof'), true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        respond(['ok'=>false, 'code'=>'GUEST_PROOF_INVALID', 'message'=>$tr('settings.cache.verify.expired'), 'sessionRevoked'=>false], 403);
    }
    if (!is_array($proof)||($proof['purpose']??'')!=='guest-cache-reset-proof-v1'||(int)($proof['expiresAt']??0) < time()) {
        respond(['ok'=>false, 'code'=>'GUEST_PROOF_EXPIRED', 'message'=>$tr('settings.cache.verify.expired'), 'sessionRevoked'=>false], 403);
    }
    $email = normalize_email((string)($proof['email']??''));
    $guestProofNonce = trim((string)($proof['nonce']??''));
    if ($guestProofNonce==='')respond(['ok'=>false, 'code'=>'GUEST_PROOF_INVALID', 'message'=>$tr('settings.cache.verify.expired'), 'sessionRevoked'=>false], 403);
}
// For an authenticated reset the privacy boundary is closed before validation
// and before any email I/O. Even damaged legacy session metadata must never keep
// a session or trusted-device cookie alive after a privacy-reset request.
if ($authenticated) {
    try {
        meteonexa_revoke_current_auth_session($pdo, $config);
    } catch (Throwable $error) {
        meteonexa_log_event('cache_receipt_session_revoke_failed', $error);
    }
    try {
        meteonexa_revoke_current_trusted_device($pdo, $config);
    } catch (Throwable $error) {
        meteonexa_log_event('cache_receipt_trusted_revoke_failed', $error);
    }
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)||strlen($email) > 254) {
    respond(['ok'=>false, 'code'=>'INVALID_EMAIL', 'message'=>$tr('settings.cache.receipt.invalid_email'), 'sessionRevoked'=>$authenticated], 422);
}
$secret = auth_secret($config);
meteonexa_prune_security_state($pdo);
if (!$authenticated) {
    $proofUse = meteonexa_rate_limit($pdo, 'cache_receipt_guest_proof_use', $guestProofNonce, $secret, 1, 900);
    if (!$proofUse['allowed']) {
        respond(['ok'=>false, 'code'=>'GUEST_PROOF_USED', 'message'=>$tr('settings.cache.verify.used'), 'sessionRevoked'=>false], 409);
    }
}
$ipLimit = meteonexa_rate_limit($pdo, 'cache_receipt_ip', client_ip(), $secret, 10, 3600);
$recipientLimit = meteonexa_rate_limit($pdo, 'cache_receipt_recipient', strtolower($email), $secret, 4, 3600);
$globalLimit = meteonexa_rate_limit($pdo, 'cache_receipt_global', 'all', $secret, 300, 3600);
if (!$ipLimit['allowed']||!$recipientLimit['allowed']||!$globalLimit['allowed']) {
    $retryAfter = max(1, (int)$ipLimit['retryAfter'], (int)$recipientLimit['retryAfter'], (int)$globalLimit['retryAfter']);
    header('Retry-After: ' . $retryAfter);
    respond(['ok'=>false, 'code'=>'RATE_LIMITED', 'message'=>$tr('settings.cache.receipt.rate_limited'), 'retryAfter'=>$retryAfter, 'sessionRevoked'=>$authenticated], 429);
}
// Keep the same SMTP provisioning/fallback behaviour as the OTP path without
// modifying the hardened OTP implementation itself.
if (!smtp_is_configured($config)) {
    try {
        meteonexa_provision_smtp_if_missing($config);
        $databaseSmtp = meteonexa_load_smtp($config);
        if ($databaseSmtp!==[]) {
            $config['smtp'] = array_replace((array)($config['smtp']??[]), $databaseSmtp);
        }
    } catch (Throwable $smtpProvisionError) {
        meteonexa_log_event('smtp_cache_receipt_provision_failed', $smtpProvisionError);
    }
}
$nativeMailEnabled = (bool)($config['smtp']['native_mail_fallback']??false)&&function_exists('mail');
if (!smtp_is_configured($config)&&!$nativeMailEnabled) {
    respond(['ok'=>false, 'code'=>'EMAIL_TRANSPORT_UNAVAILABLE', 'message'=>$tr('settings.cache.receipt.send_failed'), 'sessionRevoked'=>$authenticated], 503);
}
$appName = $tr('app.name');
if ($appName==='app.name'||trim($appName)==='')$appName = (string)($config['app']['name']??'MeteoNexa');
$escapedAppName = htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$tagline = htmlspecialchars($tr('auth.email.tagline'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$heading = htmlspecialchars($tr('privacy.cache.email.heading'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$intro = htmlspecialchars($tr($authenticated ? 'privacy.cache.email.intro.auth' : 'privacy.cache.email.intro.guest'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$statusTitle = htmlspecialchars($tr('privacy.cache.email.card.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$statusCopy = htmlspecialchars($tr($authenticated ? 'privacy.cache.email.card.copy.auth' : 'privacy.cache.email.card.copy.guest'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$retention = htmlspecialchars($tr('privacy.cache.email.retention'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$addressUse = htmlspecialchars($tr($authenticated ? 'privacy.cache.email.address_use.auth' : 'privacy.cache.email.address_use.guest'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$footer = htmlspecialchars($tr('privacy.cache.email.footer'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$subject = $tr('privacy.cache.email.subject');
// Same visual template as the OTP email: branded navy header, embedded logo,
// white rounded card and cyan evidence panel. Only the message content changes.
$html = '<!doctype html><html lang="' . htmlspecialchars($language, ENT_QUOTES, 'UTF-8') . '"><body style="margin:0;padding:0;background:#f3f7fb;font-family:Arial,Helvetica,sans-serif;color:#15314a">' . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0;background:#f3f7fb;border-collapse:collapse"><tr><td align="center" style="padding:32px 14px">' . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:560px;margin:0 auto;border-collapse:separate;border-spacing:0;background:#ffffff;border:1px solid #d9e6f1;border-radius:22px;overflow:hidden">' . '<tr><td align="center" style="padding:30px 34px 25px;background:#0d3557"><img src="cid:meteonexa-logo" width="68" height="68" alt="' . $escapedAppName . '" style="display:block;width:68px;height:68px;margin:0 auto 12px;border:0"><div style="margin:0;color:#ffffff;font-size:25px;line-height:1.2;font-weight:800">' . $escapedAppName . '</div><div style="margin-top:7px;color:#c7e4f4;font-size:13px;line-height:1.45">' . $tagline . '</div></td></tr>' . '<tr><td style="padding:30px 34px 12px;background:#ffffff"><h1 style="margin:0 0 12px;color:#102d47;font-size:24px;line-height:1.25;font-weight:800">' . $heading . '</h1><p style="margin:0;color:#58718a;font-size:15px;line-height:1.65">' . $intro . '</p></td></tr>' . '<tr><td style="padding:14px 34px 18px;background:#ffffff"><div style="padding:20px 18px;text-align:left;border:1px solid #b9dceb;border-radius:16px;background:#edf8fc;color:#087f92"><div style="font-size:18px;line-height:1.25;font-weight:900">✓ ' . $statusTitle . '</div><div style="margin-top:8px;color:#34677b;font-size:13px;line-height:1.6">' . $statusCopy . '</div></div></td></tr>' . '<tr><td style="padding:0 34px 31px;background:#ffffff"><p style="margin:0 0 10px;color:#58718a;font-size:12px;line-height:1.6">' . $addressUse . '</p><div style="padding-top:16px;border-top:1px solid #e5edf4;color:#72879a;font-size:12px;line-height:1.6">' . $retention . '<br><br>' . $footer . '</div></td></tr>' . '</table>' . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:560px;margin:0 auto;border-collapse:collapse"><tr><td align="center" style="padding:15px 12px 0;color:#8295a7;font-size:11px;line-height:1.5">© ' . date('Y') . ' ' . $escapedAppName . '</td></tr></table>' . '</td></tr></table></body></html>';
$plain = $tr('privacy.cache.email.heading') . "\n\n" . $tr($authenticated ? 'privacy.cache.email.intro.auth' : 'privacy.cache.email.intro.guest') . "\n\n" . $tr('privacy.cache.email.card.title') . ': ' . $tr($authenticated ? 'privacy.cache.email.card.copy.auth' : 'privacy.cache.email.card.copy.guest') . "\n\n" . $tr($authenticated ? 'privacy.cache.email.address_use.auth' : 'privacy.cache.email.address_use.guest') . "\n\n" . $tr('privacy.cache.email.retention') . "\n\n" . $tr('privacy.cache.email.footer');
$sendReceipt = static function(array $smtpConfig)use($appName, $email, $subject, $html, $plain, $nativeMailEnabled) : string {
    if (trim((string)($smtpConfig['from_name']??''))===''||trim((string)($smtpConfig['from_name']??''))==='MeteoNexa') {
        $smtpConfig['from_name'] = $appName;
    }
    $smtpError = null;
    $smtpUsable = trim((string)($smtpConfig['host']??''))!==''&&filter_var(trim((string)($smtpConfig['from_email']??'')), FILTER_VALIDATE_EMAIL)!==false&&(trim((string)($smtpConfig['username']??''))===''||(string)($smtpConfig['password']??'')!=='');
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
            if ($smtpError===null)$smtpError = $mailError;
            else meteonexa_log_event('cache_receipt_native_mail_fallback_failed', $mailError);
        }
    }
    if ($smtpError instanceof Throwable)throw $smtpError;
    throw new RuntimeException('EMAIL_TRANSPORT_UNAVAILABLE');
};
try {
    $transport = $sendReceipt((array)($config['smtp']??[]));
} catch (Throwable $error) {
    $rawCode = (string)$error->getMessage();
    $stableError = preg_match('/^(?:SMTP|MAIL|EMAIL)_[A-Z0-9_]+$/', $rawCode)===1 ? $rawCode : 'EMAIL_UNKNOWN';
    meteonexa_log_event('cache_receipt_send_failed.' . $stableError, $error);
    respond(['ok'=>false, 'code'=>'EMAIL_SEND_FAILED', 'message'=>$tr('settings.cache.receipt.send_failed'), 'sessionRevoked'=>$authenticated], 503);
}
respond(['ok'=>true, 'receiptSent'=>true, 'audience'=>$authenticated ? 'authenticated' : 'guest', 'maskedEmail'=>mask_email($email), 'sessionRevoked'=>$authenticated, 'transport'=>$transport,]);
