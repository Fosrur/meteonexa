<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once dirname(__DIR__) . '/auth_session.php';
require_once dirname(__DIR__) . '/diagnostics_access.php';
require_method('GET');
assert_same_origin();
$config = load_config();
$pdo = meteonexa_db($config);
$session = meteonexa_current_auth_session($pdo, $config);
meteonexa_clear_stale_trusted_device_cookie($pdo, $config);
if (is_array($session) && !meteonexa_verify_device_proof($pdo, (string)$session['device_id'])) {
    meteonexa_revoke_current_auth_session($pdo, $config);
    $session = null;
}
respond([
    'ok' => true,
    'smtpConfigured' => smtp_is_configured($config) || ((bool)($config['smtp']['native_mail_fallback'] ?? false) && function_exists('mail')),
    'emailTransport' => smtp_is_configured($config) ? 'smtp' : (((bool)($config['smtp']['native_mail_fallback'] ?? false) && function_exists('mail')) ? 'local' : 'unavailable'),
    'otpTtlSeconds' => (int)($config['auth']['otp_ttl_seconds'] ?? 600),
    'resendAfterSeconds' => (int)($config['auth']['resend_after_seconds'] ?? 60),
    'authenticated' => is_array($session),
    'displayName' => is_array($session) ? (string)$session['display_name'] : '',
    'email' => is_array($session) ? (string)($session['email'] ?? '') : '',
    'sessionExpiresAt' => is_array($session) ? (int)$session['expires_at'] : null,
    'diagnosticsAllowed' => is_array($session) ? meteonexa_diagnostics_authorized($pdo, $config, $session) : false,
]);
