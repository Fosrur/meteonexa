<?php
declare(strict_types=1);

/**
 * Server-side email session helpers.
 *
 * The browser receives only a random HttpOnly cookie. The database stores an
 * HMAC of that token plus the email HMAC and an AES-GCM encrypted address so
 * the authenticated user can see their full profile email after a reload. Existing guest/device
 * flows remain independent from this session.
 */
function meteonexa_auth_cookie_name(): string
{
    return 'meteonexa_auth_session';
}

function meteonexa_auth_cookie_path(array $config): string
{
    return meteonexa_app_cookie_path($config);
}

function meteonexa_auth_session_hash(string $token, array $config): string
{
    return hash_hmac('sha256', 'auth-session|' . $token, auth_secret($config));
}

function meteonexa_trusted_device_cookie_name(): string
{
    return 'meteonexa_trusted_device';
}

function meteonexa_trusted_device_hash(string $token, array $config): string
{
    return hash_hmac('sha256', 'trusted-device|' . $token, auth_secret($config));
}

/**
 * Device-bound OTP scope. This keeps the proven auth_otp storage/query path
 * while allowing the same email address to request independent OTPs from
 * different browsers at the same time. No email, device key or raw OTP is
 * stored in this identifier: only an HMAC of email + device id + key digest.
 */
function meteonexa_otp_device_scope_hash(array $config, string $email, string $deviceId, string $deviceKey): string
{
    $normalizedEmail = strtolower(trim($email));
    $keyDigest = hash('sha256', $deviceKey);
    return meteonexa_hmac_identifier('otp-device:' . $normalizedEmail . '|' . $deviceId . '|' . $keyDigest, auth_secret($config));
}

function meteonexa_clear_trusted_device_cookie(array $config): void
{
    setcookie(meteonexa_trusted_device_cookie_name(), '', [
        'expires' => time() - 3600,
        'path' => meteonexa_auth_cookie_path($config),
        'secure' => meteonexa_cookie_secure($config),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}



/**
 * Portable pruning helper used by MySQL/MariaDB and SQLite alike.
 * The previous LIMIT -1/OFFSET form was SQLite-only and could break login on MySQL.
 */
function meteonexa_prune_identity_rows(PDO $pdo, string $table, string $keyColumn, string $emailColumn, string $emailHash, int $keep = 8): void
{
    $allowed = [
        'auth_sessions'=>['session_hash','email_hash'],
        'trusted_devices'=>['trust_hash','email_hash'],
    ];
    if (!isset($allowed[$table]) || $allowed[$table] !== [$keyColumn,$emailColumn]) {
        throw new InvalidArgumentException('AUTH_PRUNE_TABLE_INVALID');
    }
    $keep = max(1, min(32, $keep));
    $statement = $pdo->prepare("SELECT {$keyColumn} AS row_key FROM {$table} WHERE {$emailColumn}=:email ORDER BY created_at DESC");
    $statement->execute([':email'=>$emailHash]);
    $rows = $statement->fetchAll();
    foreach (array_slice(is_array($rows) ? $rows : [], $keep) as $row) {
        $key = is_array($row) ? (string)($row['row_key'] ?? '') : '';
        if ($key === '') continue;
        if ($table === 'auth_sessions') meteonexa_auth_access_mark_ended($pdo, $key, 'limit_pruned');
        $delete = $pdo->prepare("DELETE FROM {$table} WHERE {$keyColumn}=:row_key");
        $delete->execute([':row_key'=>$key]);
    }
}

function meteonexa_auth_access_location_label(): string
{
    $city = trim((string)($_SERVER['GEOIP_CITY_NAME'] ?? $_SERVER['HTTP_X_GEO_CITY'] ?? ''));
    $country = strtoupper(trim((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? $_SERVER['GEOIP_COUNTRY_CODE'] ?? $_SERVER['HTTP_X_GEO_COUNTRY'] ?? '')));
    $city = preg_replace('/[^\p{L}\p{N} .,_-]/u', '', $city) ?? '';
    $country = preg_replace('/[^A-Z]/', '', $country) ?? '';
    $parts = [];
    if ($city !== '') $parts[] = meteonexa_text_substr($city, 0, 80);
    if ($country !== '' && strlen($country) <= 3) $parts[] = $country;
    if ($parts) return implode(', ', array_unique($parts));

    // When the hosting layer does not expose GeoIP headers, the
    // authenticated Devices dialog may send browser coordinates already rounded
    // to one decimal (~8–11 km in Italy). Precise coordinates are never stored in
    // access history and no third-party IP geolocation service is queried.
    $approx = trim((string)($_SERVER['HTTP_X_METEONEXA_APPROX_LOCATION'] ?? ''));
    if ($approx !== '' && preg_match('/^(-?\d{1,2}(?:\.\d)?),(-?\d{1,3}(?:\.\d)?)$/', $approx, $m) === 1) {
        $lat=(float)$m[1];$lon=(float)$m[2];
        if (abs($lat)<=90 && abs($lon)<=180) return sprintf('≈ %.1f°, %.1f°', $lat, $lon);
    }
    return '';
}

/** @return array{device_type:string,platform:string,browser:string,client_mode:string,timezone:string,ip:string,location:string} */
function meteonexa_auth_access_metadata(): array
{
    $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $deviceType = 'Web';
    $platform = 'Unknown';
    $browser = 'Browser';
    if (preg_match('/iPhone/i', $ua)) { $deviceType='iPhone'; $platform='iOS'; }
    elseif (preg_match('/iPad/i', $ua)) { $deviceType='iPad'; $platform='iPadOS'; }
    elseif (preg_match('/Android/i', $ua)) { $deviceType=preg_match('/Mobile/i',$ua)?'Android phone':'Android tablet'; $platform='Android'; }
    elseif (preg_match('/Windows NT/i', $ua)) { $deviceType='Desktop'; $platform='Windows'; }
    elseif (preg_match('/Macintosh|Mac OS X/i', $ua)) { $deviceType='Desktop'; $platform='macOS'; }
    elseif (preg_match('/Linux/i', $ua)) { $deviceType='Desktop'; $platform='Linux'; }

    if (preg_match('/Edg\//i', $ua)) $browser='Edge';
    elseif (preg_match('/OPR\//i', $ua)) $browser='Opera';
    elseif (preg_match('/SamsungBrowser\//i', $ua)) $browser='Samsung Internet';
    elseif (preg_match('/Firefox\//i', $ua)) $browser='Firefox';
    elseif (preg_match('/CriOS\//i', $ua)) $browser='Chrome iOS';
    elseif (preg_match('/Chrome\//i', $ua)) $browser='Chrome';
    elseif (preg_match('/Safari\//i', $ua) && preg_match('/Version\//i', $ua)) $browser='Safari';

    $mode = strtolower(trim((string)($_SERVER['HTTP_X_METEONEXA_CLIENT_MODE'] ?? 'web')));
    if (!in_array($mode, ['web','pwa'], true)) $mode='web';
    $timezone = trim((string)($_SERVER['HTTP_X_METEONEXA_CLIENT_TIMEZONE'] ?? ''));
    if (strlen($timezone) > 80 || preg_match('/^[A-Za-z0-9_+\-\/.]+$/', $timezone) !== 1) $timezone='';
    return [
        'device_type'=>$deviceType,
        'platform'=>$platform,
        'browser'=>$browser,
        'client_mode'=>$mode,
        'timezone'=>$timezone,
        'ip'=>client_ip(),
        'location'=>meteonexa_auth_access_location_label(),
    ];
}

function meteonexa_auth_access_mark_ended(PDO $pdo, string $sessionHash, string $reason): void
{
    try {
        if (!meteonexa_db_table_exists($pdo, 'auth_access_history')) return;
        $reason = preg_replace('/[^a-z0-9_-]/i', '', $reason) ?: 'ended';
        $pdo->prepare("UPDATE auth_access_history SET ended_at=:ended,end_reason=:reason,last_seen_at=CASE WHEN last_seen_at<:ended THEN :ended ELSE last_seen_at END WHERE session_hash=:session AND ended_at=0")
            ->execute([':ended'=>time(),':reason'=>$reason,':session'=>$sessionHash]);
    } catch (Throwable $error) {
        // Audit/history is auxiliary and must never prevent authentication.
        meteonexa_log_event('auth_access_mark_ended_failed', $error);
    }
}

function meteonexa_auth_access_record(PDO $pdo, array $config, string $sessionHash, string $emailHash, string $deviceId, int $now): void
{
    try {
        if (!meteonexa_db_table_exists($pdo, 'auth_access_history')) return;
        $m = meteonexa_auth_access_metadata();
        $insert = $pdo->prepare("INSERT INTO auth_access_history(session_hash,email_hash,device_id,device_type,platform,browser,client_mode,timezone,ip_encrypted,location_label,created_at,last_seen_at,ended_at,end_reason) VALUES(:session,:email,:device,:type,:platform,:browser,:mode,:timezone,:ip,:location,:created,:seen,0,'')");
        $insert->execute([
            ':session'=>$sessionHash, ':email'=>$emailHash, ':device'=>$deviceId,
            ':type'=>$m['device_type'], ':platform'=>$m['platform'], ':browser'=>$m['browser'], ':mode'=>$m['client_mode'], ':timezone'=>$m['timezone'],
            ':ip'=>meteonexa_encrypt_value((string)$m['ip'], $config, 'auth-access-ip'), ':location'=>$m['location'], ':created'=>$now, ':seen'=>$now,
        ]);

        // Keep a bounded audit trail per identity in addition to the 30-day global retention.
        $history = $pdo->prepare('SELECT id FROM auth_access_history WHERE email_hash=:email ORDER BY last_seen_at DESC,id DESC');
        $history->execute([':email'=>$emailHash]);
        $ids = array_map('intval', $history->fetchAll(PDO::FETCH_COLUMN));
        foreach (array_slice($ids, 100) as $id) {
            $pdo->prepare('DELETE FROM auth_access_history WHERE id=:id')->execute([':id'=>$id]);
        }
    } catch (Throwable $error) {
        // Device/access history is an audit convenience, not an auth prerequisite.
        meteonexa_log_event('auth_access_record_failed', $error);
    }
}

function meteonexa_issue_trusted_device(PDO $pdo, array $config, string $email, string $displayName, string $deviceId): array
{
    $auth = (array)($config['auth'] ?? []);
    $ttl = max(86400, min(31536000, (int)($auth['trusted_device_ttl_seconds'] ?? 2592000)));
    $now = time();
    $expiresAt = $now + $ttl;
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $trustHash = meteonexa_trusted_device_hash($token, $config);
    $normalizedEmail = strtolower(trim($email));
    $emailHash = meteonexa_hmac_identifier('trusted-email:' . $normalizedEmail, auth_secret($config));
    $displayName = meteonexa_text_substr(trim($displayName), 0, 120);

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $pdo->prepare('DELETE FROM trusted_devices WHERE expires_at < :now')->execute([':now'=>$now]);
        // One trusted identity per browser device keeps the re-entry flow
        // deterministic and prevents stale identities from accumulating locally.
        $pdo->prepare('DELETE FROM trusted_devices WHERE device_id=:device')->execute([':device'=>$deviceId]);
        $insert = $pdo->prepare('INSERT INTO trusted_devices(trust_hash,email_hash,email_encrypted,device_id,display_name,created_at,expires_at,last_seen_at) VALUES(:trust,:email_hash,:email_encrypted,:device,:name,:created,:expires,:seen)');
        $insert->execute([
            ':trust'=>$trustHash,
            ':email_hash'=>$emailHash,
            ':email_encrypted'=>meteonexa_encrypt_value($normalizedEmail, $config, 'trusted-device-email'),
            ':device'=>$deviceId,
            ':name'=>meteonexa_encrypt_value($displayName, $config, 'trusted-device-name'),
            ':created'=>$now,
            ':expires'=>$expiresAt,
            ':seen'=>$now,
        ]);
        // Limit the number of remembered browsers for one identity using portable SQL.
        meteonexa_prune_identity_rows($pdo, 'trusted_devices', 'trust_hash', 'email_hash', $emailHash, 8);
        $pdo->exec('COMMIT');
    } catch (Throwable $error) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) { }
        throw $error;
    }

    setcookie(meteonexa_trusted_device_cookie_name(), $token, [
        'expires' => $expiresAt,
        'path' => meteonexa_auth_cookie_path($config),
        'secure' => meteonexa_cookie_secure($config),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    return ['expiresAt'=>$expiresAt, 'displayName'=>$displayName];
}

function meteonexa_current_trusted_device_identity(PDO $pdo, array $config): ?array
{
    $token = trim((string)($_COOKIE[meteonexa_trusted_device_cookie_name()] ?? ''));
    if ($token === '' || preg_match('/^[A-Za-z0-9_-]{40,96}$/', $token) !== 1) return null;

    $hash = meteonexa_trusted_device_hash($token, $config);
    $statement = $pdo->prepare('SELECT trust_hash,email_hash,email_encrypted,device_id,display_name,created_at,expires_at,last_seen_at FROM trusted_devices WHERE trust_hash=:trust LIMIT 1');
    $statement->execute([':trust'=>$hash]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        meteonexa_clear_trusted_device_cookie($config);
        return null;
    }

    $now = time();
    if ((int)$row['expires_at'] <= $now) {
        $pdo->prepare('DELETE FROM trusted_devices WHERE trust_hash=:trust')->execute([':trust'=>$hash]);
        meteonexa_clear_trusted_device_cookie($config);
        return null;
    }

    $requestDeviceId = trim((string)($_SERVER['HTTP_X_METEONEXA_DEVICE_ID'] ?? ''));
    if ($requestDeviceId === '' || !hash_equals((string)$row['device_id'], $requestDeviceId)) return null;
    $requestKey = trim((string)($_SERVER['HTTP_X_METEONEXA_DEVICE_KEY'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/', $requestKey)) return null;
    $credential = $pdo->prepare('SELECT key_hash FROM device_credentials WHERE device_id=:device LIMIT 1');
    $credential->execute([':device'=>$requestDeviceId]);
    $storedKeyHash = $credential->fetchColumn();
    if (!is_string($storedKeyHash) || $storedKeyHash === '' || !hash_equals($storedKeyHash, hash('sha256', $requestKey))) return null;

    try {
        $row['email'] = meteonexa_decrypt_value((string)$row['email_encrypted'], $config, 'trusted-device-email');
        $row['display_name'] = meteonexa_decrypt_value((string)$row['display_name'], $config, 'trusted-device-name');
    } catch (Throwable $error) {
        $pdo->prepare('DELETE FROM trusted_devices WHERE trust_hash=:trust')->execute([':trust'=>$hash]);
        meteonexa_clear_trusted_device_cookie($config);
        return null;
    }
    unset($row['email_encrypted']);

    if (!filter_var((string)$row['email'], FILTER_VALIDATE_EMAIL)) {
        $pdo->prepare('DELETE FROM trusted_devices WHERE trust_hash=:trust')->execute([':trust'=>$hash]);
        meteonexa_clear_trusted_device_cookie($config);
        return null;
    }
    if ((int)$row['last_seen_at'] < $now - 300) {
        $pdo->prepare('UPDATE trusted_devices SET last_seen_at=:seen WHERE trust_hash=:trust')->execute([':seen'=>$now, ':trust'=>$hash]);
    }
    return $row;
}

function meteonexa_current_trusted_device(PDO $pdo, array $config, string $email): ?array
{
    $row = meteonexa_current_trusted_device_identity($pdo, $config);
    if (!is_array($row)) return null;
    $normalizedEmail = strtolower(trim($email));
    $expectedEmailHash = meteonexa_hmac_identifier('trusted-email:' . $normalizedEmail, auth_secret($config));
    if (!hash_equals((string)$row['email_hash'], $expectedEmailHash) || !hash_equals(strtolower(trim((string)$row['email'])), $normalizedEmail)) return null;
    return $row;
}

function meteonexa_revoke_current_trusted_device(PDO $pdo, array $config): void
{
    $token = trim((string)($_COOKIE[meteonexa_trusted_device_cookie_name()] ?? ''));
    if ($token !== '' && preg_match('/^[A-Za-z0-9_-]{40,96}$/', $token) === 1) {
        $hash = meteonexa_trusted_device_hash($token, $config);
        $pdo->prepare('DELETE FROM trusted_devices WHERE trust_hash=:trust')->execute([':trust'=>$hash]);
    }
    meteonexa_clear_trusted_device_cookie($config);
}

/**
 * A trusted-device cookie can physically remain in a remote browser after that
 * device is revoked elsewhere. It is already useless once the DB row is gone;
 * this helper also expires the stale HttpOnly cookie on the browser's next
 * server contact without touching any unrelated cookie or local cache.
 */
function meteonexa_clear_stale_trusted_device_cookie(PDO $pdo, array $config): void
{
    $token = trim((string)($_COOKIE[meteonexa_trusted_device_cookie_name()] ?? ''));
    if ($token === '') return;
    if (preg_match('/^[A-Za-z0-9_-]{40,96}$/', $token) !== 1) {
        meteonexa_clear_trusted_device_cookie($config);
        return;
    }
    try {
        $hash = meteonexa_trusted_device_hash($token, $config);
        $statement = $pdo->prepare('SELECT COUNT(*) FROM trusted_devices WHERE trust_hash=:trust');
        $statement->execute([':trust'=>$hash]);
        if ((int)$statement->fetchColumn() < 1) meteonexa_clear_trusted_device_cookie($config);
    } catch (Throwable $error) {
        // Cookie cleanup is hygiene only; an unavailable audit/auth lookup must
        // never turn status.php into a new authentication failure.
        meteonexa_log_event('trusted_device_cookie_cleanup_failed', $error);
    }
}

function meteonexa_clear_auth_cookie(array $config): void
{
    setcookie(meteonexa_auth_cookie_name(), '', [
        'expires' => time() - 3600,
        'path' => meteonexa_auth_cookie_path($config),
        'secure' => meteonexa_cookie_secure($config),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function meteonexa_issue_auth_session(PDO $pdo, array $config, string $email, string $displayName, string $deviceId): array
{
    $auth = (array)($config['auth'] ?? []);
    $ttl = max(3600, min(7776000, (int)($auth['session_ttl_seconds'] ?? 2592000)));
    $now = time();
    $expiresAt = $now + $ttl;
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $sessionHash = meteonexa_auth_session_hash($token, $config);
    $emailHash = meteonexa_hmac_identifier('session-email:' . strtolower(trim($email)), auth_secret($config));
    $displayName = meteonexa_text_substr(trim($displayName), 0, 120);

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $pdo->prepare('DELETE FROM auth_sessions WHERE expires_at < :now')->execute([':now'=>$now]);
        // A browser/device has a single current email session. Preserve a compact
        // access-history record before replacing an older session on this device.
        $oldSessions = $pdo->prepare('SELECT session_hash FROM auth_sessions WHERE device_id=:device');
        $oldSessions->execute([':device'=>$deviceId]);
        foreach ($oldSessions->fetchAll() as $oldRow) {
            if (is_array($oldRow) && (string)($oldRow['session_hash'] ?? '') !== '') meteonexa_auth_access_mark_ended($pdo, (string)$oldRow['session_hash'], 'replaced');
        }
        $pdo->prepare('DELETE FROM auth_sessions WHERE device_id=:device')->execute([':device'=>$deviceId]);
        $insert = $pdo->prepare('INSERT INTO auth_sessions(session_hash,email_hash,email_encrypted,device_id,display_name,created_at,expires_at,last_seen_at) VALUES(:session,:email_hash,:email_encrypted,:device,:name,:created,:expires,:seen)');
        $insert->execute([
            ':session'=>$sessionHash,
            ':email_hash'=>$emailHash,
            ':email_encrypted'=>meteonexa_encrypt_value(strtolower(trim($email)), $config, 'auth-session-email'),
            ':device'=>$deviceId,
            ':name'=>meteonexa_encrypt_value($displayName, $config, 'auth-session'),
            ':created'=>$now,
            ':expires'=>$expiresAt,
            ':seen'=>$now,
        ]);
        meteonexa_auth_access_record($pdo, $config, $sessionHash, $emailHash, $deviceId, $now);
        // Keep at most eight active browser sessions for the same email identity using portable SQL.
        try { meteonexa_prune_identity_rows($pdo, 'auth_sessions', 'session_hash', 'email_hash', $emailHash, 8); } catch (Throwable $pruneError) { meteonexa_log_event('auth_session_prune_failed', $pruneError); }
        $pdo->exec('COMMIT');
    } catch (Throwable $error) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) { }
        throw $error;
    }

    setcookie(meteonexa_auth_cookie_name(), $token, [
        'expires' => $expiresAt,
        'path' => meteonexa_auth_cookie_path($config),
        'secure' => meteonexa_cookie_secure($config),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    return ['expiresAt'=>$expiresAt, 'displayName'=>$displayName];
}

function meteonexa_current_auth_session(PDO $pdo, array $config, bool $touch = true): ?array
{
    $token = trim((string)($_COOKIE[meteonexa_auth_cookie_name()] ?? ''));
    if ($token === '' || strlen($token) < 40 || strlen($token) > 96 || preg_match('/^[A-Za-z0-9_-]+$/', $token) !== 1) {
        return null;
    }
    $hash = meteonexa_auth_session_hash($token, $config);
    $statement = $pdo->prepare('SELECT session_hash,device_id,email_encrypted,display_name,created_at,expires_at,last_seen_at FROM auth_sessions WHERE session_hash=:session LIMIT 1');
    $statement->execute([':session'=>$hash]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        meteonexa_clear_auth_cookie($config);
        return null;
    }
    $now = time();
    $storedDisplayName = (string)($row['display_name'] ?? '');
    $storedEmail = (string)($row['email_encrypted'] ?? '');
    if (!str_starts_with($storedDisplayName, 'enc:v1:') || !str_starts_with($storedEmail, 'enc:v1:')) {
        // Sessions created before encrypted session metadata are deliberately
        // invalidated once; this avoids retaining legacy plaintext identifiers.
        meteonexa_auth_access_mark_ended($pdo, $hash, 'invalid');
        $pdo->prepare('DELETE FROM auth_sessions WHERE session_hash=:session')->execute([':session'=>$hash]);
        meteonexa_clear_auth_cookie($config);
        return null;
    }
    try {
        $row['display_name'] = meteonexa_decrypt_value($storedDisplayName, $config, 'auth-session');
        $row['email'] = meteonexa_decrypt_value($storedEmail, $config, 'auth-session-email');
        unset($row['email_encrypted']);
    } catch (Throwable $error) {
        meteonexa_auth_access_mark_ended($pdo, $hash, 'invalid');
        $pdo->prepare('DELETE FROM auth_sessions WHERE session_hash=:session')->execute([':session'=>$hash]);
        meteonexa_clear_auth_cookie($config);
        return null;
    }
    if ((int)$row['expires_at'] <= $now) {
        meteonexa_auth_access_mark_ended($pdo, $hash, 'expired');
        $pdo->prepare('DELETE FROM auth_sessions WHERE session_hash=:session')->execute([':session'=>$hash]);
        meteonexa_clear_auth_cookie($config);
        return null;
    }
    $idleSeconds = max(900, min(2592000, (int)($config['auth']['session_idle_seconds'] ?? 604800)));
    if ((int)$row['last_seen_at'] <= $now - $idleSeconds) {
        meteonexa_auth_access_mark_ended($pdo, $hash, 'idle_timeout');
        $pdo->prepare('DELETE FROM auth_sessions WHERE session_hash=:session')->execute([':session'=>$hash]);
        meteonexa_clear_auth_cookie($config);
        return null;
    }
    if ($touch && (int)$row['last_seen_at'] < $now - 300) {
        $pdo->prepare('UPDATE auth_sessions SET last_seen_at=:seen WHERE session_hash=:session')->execute([':seen'=>$now, ':session'=>$hash]);
        if (meteonexa_db_table_exists($pdo, 'auth_access_history')) {
            $pdo->prepare('UPDATE auth_access_history SET last_seen_at=:seen WHERE session_hash=:session AND ended_at=0')->execute([':seen'=>$now, ':session'=>$hash]);
        }
        $row['last_seen_at'] = $now;
    }
    return $row;
}

function meteonexa_revoke_current_auth_session(PDO $pdo, array $config): void
{
    $token = trim((string)($_COOKIE[meteonexa_auth_cookie_name()] ?? ''));
    if ($token !== '' && preg_match('/^[A-Za-z0-9_-]{40,96}$/', $token) === 1) {
        $hash = meteonexa_auth_session_hash($token, $config);
        meteonexa_auth_access_mark_ended($pdo, $hash, 'logout');
        $pdo->prepare('DELETE FROM auth_sessions WHERE session_hash=:session')->execute([':session'=>$hash]);
    }
    meteonexa_clear_auth_cookie($config);
}
