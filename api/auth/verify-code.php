<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/i18n.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once dirname(__DIR__) . '/auth_session.php';

assert_same_origin();
$config = load_config();
$data = input_json();
$language = meteonexa_auth_language($data['language'] ?? 'it');
$pdo = meteonexa_db($config);
$deviceId = clean_device_id($_SERVER['HTTP_X_METEONEXA_DEVICE_ID'] ?? '');
$deviceKey = meteonexa_device_key(); // validate proof format; enrollment occurs only after OTP success
$tr = static fn(string $source, array $params = []): string => meteonexa_auth_translate($pdo, $language, $source, $params);
$email = normalize_email($data['email'] ?? '');
$code = preg_replace('/\D+/', '', (string)($data['code'] ?? '')) ?? '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) {
    respond(['ok' => false, 'code' => 'INVALID_INPUT', 'message' => $tr('auth.error.invalid_input')], 422);
}

$secret = auth_secret($config);
$verifyLimit = max(10, min(240, (int)($config['auth']['max_verify_requests_per_ip_hour'] ?? 60)));
$rate = meteonexa_rate_limit($pdo, 'auth_verify_ip', client_ip(), $secret, $verifyLimit, 3600);
$globalVerifyLimit = max($verifyLimit, min(20000, (int)($config['auth']['max_verify_requests_global_hour'] ?? 2000)));
$globalRate = meteonexa_rate_limit($pdo, 'auth_verify_global', 'all', $secret, $globalVerifyLimit, 3600);
if (!$rate['allowed'] || !$globalRate['allowed']) {
    $retryAfter = max(1, (int)$rate['retryAfter'], (int)$globalRate['retryAfter']);
    header('Retry-After: ' . $retryAfter);
    respond(['ok'=>false,'code'=>'RATE_LIMITED','message'=>$tr('auth.error.rate_limited'),'retryAfter'=>$retryAfter],429);
}

$emailHash = meteonexa_otp_device_scope_hash($config, $email, $deviceId, $deviceKey);
$pdo->exec('BEGIN IMMEDIATE');
try {
    $statement = $pdo->prepare('SELECT code_hash,expires_at,attempts FROM auth_otp WHERE email_hash=:email LIMIT 1');
    $statement->execute([':email'=>$emailHash]);
    $record = $statement->fetch();
    if (!is_array($record)) {
        $pdo->exec('COMMIT');
        respond(['ok'=>false,'code'=>'CODE_NOT_FOUND','message'=>$tr('auth.error.code_not_found')],404);
    }
    $now = time();
    if ((int)$record['expires_at'] < $now) {
        $pdo->prepare('DELETE FROM auth_otp WHERE email_hash=:email')->execute([':email'=>$emailHash]);
        $pdo->exec('COMMIT');
        respond(['ok'=>false,'code'=>'CODE_EXPIRED','message'=>$tr('auth.error.code_expired')],410);
    }
    $maxAttempts = max(3, min(10, (int)($config['auth']['max_attempts'] ?? 5)));
    $attempts = (int)$record['attempts'];
    if ($attempts >= $maxAttempts) {
        $pdo->prepare('DELETE FROM auth_otp WHERE email_hash=:email')->execute([':email'=>$emailHash]);
        $pdo->exec('COMMIT');
        respond(['ok'=>false,'code'=>'TOO_MANY_ATTEMPTS','message'=>$tr('auth.error.too_many_attempts')],429);
    }
    if (!password_verify($code, (string)$record['code_hash'])) {
        $attempts++;
        if ($attempts >= $maxAttempts) {
            $pdo->prepare('DELETE FROM auth_otp WHERE email_hash=:email')->execute([':email'=>$emailHash]);
        } else {
            $pdo->prepare('UPDATE auth_otp SET attempts=:attempts,updated_at=:updated WHERE email_hash=:email')->execute([':attempts'=>$attempts,':updated'=>gmdate('c'),':email'=>$emailHash]);
        }
        $pdo->exec('COMMIT');
        respond(['ok'=>false,'code'=>'INVALID_CODE','message'=>$tr('auth.error.invalid_code',['remaining'=>max(0,$maxAttempts-$attempts)])],422);
    }
    $pdo->prepare('DELETE FROM auth_otp WHERE email_hash=:email')->execute([':email'=>$emailHash]);
    $pdo->exec('COMMIT');
} catch (Throwable $error) {
    try { $pdo->exec('ROLLBACK'); } catch (Throwable $rollbackError) { /* transaction may already be closed */ }
    throw $error;
}

require_device_access($pdo, $deviceId); // successful OTP now binds this browser key to the session device
$localPart = explode('@', $email, 2)[0] ?? $tr('auth.user.default_name');
$displayName = ucfirst(preg_replace('/[^a-z0-9._-]/i', '', $localPart) ?: $tr('auth.user.default_name'));
$session = meteonexa_issue_auth_session($pdo, $config, $email, $displayName, $deviceId);
$trustedExpiresAt = null;
try {
    $trusted = meteonexa_issue_trusted_device($pdo, $config, $email, $displayName, $deviceId);
    $trustedExpiresAt = (int)$trusted['expiresAt'];
} catch (Throwable $trustedDeviceError) {
    // Remembering the browser is a convenience layer, never a prerequisite for
    // a successful OTP-authenticated session.
    meteonexa_log_event('trusted_device_issue_failed', $trustedDeviceError);
}
respond(['ok'=>true,'displayName'=>$displayName,'email'=>$email,'verified'=>true,'language'=>$language,'sessionExpiresAt'=>(int)$session['expiresAt'],'trustedDeviceExpiresAt'=>$trustedExpiresAt]);
