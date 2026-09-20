<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once dirname(__DIR__) . '/auth_session.php';

// Re-entry issues a new authenticated session, so this is deliberately POST-only
// even though the browser does not need to submit personal data. Same-origin,
// the HttpOnly trusted cookie and the browser-held device proof are all required.
assert_same_origin();
require_method('POST');
$config = load_config();
$pdo = meteonexa_db($config);
$deviceId = clean_device_id($_SERVER['HTTP_X_METEONEXA_DEVICE_ID'] ?? '');
require_ip_rate_limit($pdo, 'trusted_reentry_check_ip', 120, 3600);
require_device_rate_limit($pdo, 'trusted_reentry_check_device', $deviceId, 60, 3600);

try {
    $trusted = meteonexa_current_trusted_device_identity($pdo, $config);
    if (!is_array($trusted)) {
        respond(['ok'=>true,'trusted'=>false,'authenticated'=>false]);
    }
    $displayName = trim((string)($trusted['display_name'] ?? ''));
    $email = strtolower(trim((string)($trusted['email'] ?? '')));
    if ($displayName === '') {
        $localPart = explode('@', $email, 2)[0] ?? 'MeteoNexa';
        $displayName = ucfirst(preg_replace('/[^a-z0-9._-]/i', '', $localPart) ?: 'MeteoNexa');
    }
    $session = meteonexa_issue_auth_session($pdo, $config, $email, $displayName, (string)$trusted['device_id']);
    // Data minimization: the email is used server-side to bind the new session,
    // but is not returned by this pre-check endpoint. The client only needs a
    // verified email-session marker and the non-sensitive display name.
    respond([
        'ok'=>true,
        'trusted'=>true,
        'authenticated'=>true,
        'displayName'=>$displayName,
        'sessionExpiresAt'=>(int)$session['expiresAt'],
    ]);
} catch (Throwable $error) {
    meteonexa_log_event('trusted_reentry_check_failed', $error);
    respond(['ok'=>true,'trusted'=>false,'authenticated'=>false]);
}
