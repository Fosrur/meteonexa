<?php
declare(strict_types=1);

/**
 * Device-bound OTP challenge helpers used by email login.
 *
 * Primary storage is auth_login_challenges. Verified Trust also has a protected
 * filesystem fallback so a partially migrated shared-hosting database cannot
 * take the login endpoint down. The fallback stores only hashes/encrypted-like
 * one-way material: email HMAC, device id, password_hash(code), IP HMAC and
 * expiry metadata. It never stores the email address or OTP in plaintext.
 */
function meteonexa_login_challenge_hash(string $token, array $config): string
{
    return hash_hmac('sha256', 'auth-login-challenge|' . $token, auth_secret($config));
}

function meteonexa_auth_fallback_directory(string $kind): string
{
    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '-', $kind) ?: 'auth';
    $dir = storage_dir() . DIRECTORY_SEPARATOR . $safe;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('AUTH_FALLBACK_STORAGE_UNAVAILABLE');
    }
    @chmod($dir, 0700);
    return $dir;
}

function meteonexa_challenge_fallback_path(string $token, array $config): string
{
    $hash = meteonexa_login_challenge_hash($token, $config);
    return meteonexa_auth_fallback_directory('auth-login-challenges') . DIRECTORY_SEPARATOR . $hash . '.json';
}

/** @param array<string,mixed> $record */
function meteonexa_write_challenge_fallback(string $token, array $config, array $record): void
{
    $payload = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($payload) || $payload === '') throw new RuntimeException('AUTH_FALLBACK_ENCODE_FAILED');
    $path = meteonexa_challenge_fallback_path($token, $config);
    if (!meteonexa_atomic_write($path, $payload . "\n", 0600)) {
        throw new RuntimeException('AUTH_FALLBACK_WRITE_FAILED');
    }
}

function meteonexa_prune_challenge_fallback(string $emailHash, int $now): void
{
    try { $dir = meteonexa_auth_fallback_directory('auth-login-challenges'); }
    catch (Throwable $ignored) { return; }
    $matching = [];
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
        $raw = @file_get_contents($path);
        $row = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($row)) { @unlink($path); continue; }
        $expires = (int)($row['expires_at'] ?? 0);
        if ($expires < $now - 3600) { @unlink($path); continue; }
        if (hash_equals((string)($row['email_hash'] ?? ''), $emailHash)) {
            $matching[] = ['path'=>$path, 'sent'=>(int)($row['sent_at'] ?? 0)];
        }
    }
    usort($matching, static fn(array $a, array $b): int => $b['sent'] <=> $a['sent']);
    foreach (array_slice($matching, 8) as $old) @unlink((string)$old['path']);
}

/**
 * File-based fixed-window limiter used only if the DB limiter is temporarily
 * unavailable. This preserves abuse protection instead of silently failing open.
 * @return array{allowed:bool,retryAfter:int,remaining:int}
 */
function meteonexa_auth_file_rate_limit(string $scope, string $identifier, string $secret, int $limit, int $windowSeconds): array
{
    $limit = max(1, $limit);
    $windowSeconds = max(30, $windowSeconds);
    $dir = meteonexa_auth_fallback_directory('auth-rate-limits');
    $key = hash_hmac('sha256', $scope . '|' . $identifier, $secret);
    $path = $dir . DIRECTORY_SEPARATOR . $key . '.json';
    $handle = @fopen($path, 'c+');
    if (!is_resource($handle)) throw new RuntimeException('AUTH_RATE_FALLBACK_OPEN_FAILED');
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('AUTH_RATE_FALLBACK_LOCK_FAILED');
        rewind($handle);
        $raw = stream_get_contents($handle);
        $row = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
        $now = time();
        $windowStart = is_array($row) ? (int)($row['window_start'] ?? $now) : $now;
        $count = is_array($row) ? (int)($row['request_count'] ?? 0) : 0;
        if ($windowStart <= $now - $windowSeconds) { $windowStart = $now; $count = 0; }
        if ($count >= $limit) {
            return ['allowed'=>false,'retryAfter'=>max(1,$windowSeconds-($now-$windowStart)),'remaining'=>0];
        }
        $count++;
        $payload = json_encode(['window_start'=>$windowStart,'request_count'=>$count,'updated_at'=>$now], JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) throw new RuntimeException('AUTH_RATE_FALLBACK_ENCODE_FAILED');
        ftruncate($handle, 0); rewind($handle); fwrite($handle, $payload . "\n"); fflush($handle); @chmod($path, 0600);
        return ['allowed'=>true,'retryAfter'=>0,'remaining'=>max(0,$limit-$count)];
    } finally {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

/** @return array{allowed:bool,retryAfter:int,remaining:int} */
function meteonexa_auth_rate_limit(PDO $pdo, string $scope, string $identifier, string $secret, int $limit, int $windowSeconds): array
{
    try {
        return meteonexa_rate_limit($pdo, $scope, $identifier, $secret, $limit, $windowSeconds);
    } catch (Throwable $error) {
        meteonexa_log_event('auth_db_rate_limit_fallback', $error);
        return meteonexa_auth_file_rate_limit($scope, $identifier, $secret, $limit, $windowSeconds);
    }
}

/**
 * Portable best-effort bridge for a stale pre-challenge client. New clients do
 * not authenticate from this row. Avoid dialect-specific UPSERT syntax here.
 */
function meteonexa_legacy_otp_upsert(PDO $pdo, array $values): void
{
    $check = $pdo->prepare('SELECT email_hash FROM auth_otp WHERE email_hash=:email LIMIT 1');
    $check->execute([':email'=>$values['email']]);
    if ($check->fetchColumn() !== false) {
        $update = $pdo->prepare('UPDATE auth_otp SET language=:language,code_hash=:code,sent_at=:sent,expires_at=:expires,attempts=0,ip_hash=:ip,updated_at=:updated WHERE email_hash=:email');
        $update->execute([
            ':email'=>$values['email'], ':language'=>$values['language'], ':code'=>$values['code'],
            ':sent'=>$values['sent'], ':expires'=>$values['expires'], ':ip'=>$values['ip'], ':updated'=>$values['updated'],
        ]);
        return;
    }
    try {
        $insert = $pdo->prepare('INSERT INTO auth_otp(email_hash,language,code_hash,sent_at,expires_at,attempts,ip_hash,updated_at) VALUES(:email,:language,:code,:sent,:expires,0,:ip,:updated)');
        $insert->execute([
            ':email'=>$values['email'], ':language'=>$values['language'], ':code'=>$values['code'],
            ':sent'=>$values['sent'], ':expires'=>$values['expires'], ':ip'=>$values['ip'], ':updated'=>$values['updated'],
        ]);
    } catch (Throwable $race) {
        // A concurrent insert for the same legacy identity may win; update it.
        $update = $pdo->prepare('UPDATE auth_otp SET language=:language,code_hash=:code,sent_at=:sent,expires_at=:expires,attempts=0,ip_hash=:ip,updated_at=:updated WHERE email_hash=:email');
        $update->execute([
            ':email'=>$values['email'], ':language'=>$values['language'], ':code'=>$values['code'],
            ':sent'=>$values['sent'], ':expires'=>$values['expires'], ':ip'=>$values['ip'], ':updated'=>$values['updated'],
        ]);
    }
}

/** @return array{challengeId:string,expiresAt:int,storage:string} */
function meteonexa_issue_login_challenge(
    PDO $pdo,
    array $config,
    string $emailHash,
    string $deviceId,
    string $language,
    string $code,
    int $sentAt,
    int $expiresAt,
    string $ipHash
): array {
    $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $hash = meteonexa_login_challenge_hash($token, $config);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);
    try {
        if (!meteonexa_db_table_exists($pdo, 'auth_login_challenges')) throw new RuntimeException('AUTH_CHALLENGE_TABLE_MISSING');
        $statement = $pdo->prepare('INSERT INTO auth_login_challenges(challenge_hash,email_hash,device_id,language,code_hash,sent_at,expires_at,attempts,ip_hash,updated_at)
            VALUES(:challenge,:email,:device,:language,:code,:sent,:expires,0,:ip,:updated)');
        $statement->execute([
            ':challenge'=>$hash, ':email'=>$emailHash, ':device'=>$deviceId, ':language'=>$language, ':code'=>$codeHash,
            ':sent'=>$sentAt, ':expires'=>$expiresAt, ':ip'=>$ipHash, ':updated'=>gmdate('c'),
        ]);
        $rows = $pdo->prepare('SELECT challenge_hash FROM auth_login_challenges WHERE email_hash=:email ORDER BY sent_at DESC');
        $rows->execute([':email'=>$emailHash]);
        $hashes = array_values(array_filter(array_map(static fn($row): string => is_array($row) ? (string)($row['challenge_hash'] ?? '') : '', $rows->fetchAll())));
        foreach (array_slice($hashes, 8) as $oldHash) {
            $pdo->prepare('DELETE FROM auth_login_challenges WHERE challenge_hash=:challenge')->execute([':challenge'=>$oldHash]);
        }
        return ['challengeId'=>$token,'expiresAt'=>$expiresAt,'storage'=>'db'];
    } catch (Throwable $error) {
        meteonexa_log_event('auth_challenge_file_fallback', $error);
        meteonexa_write_challenge_fallback($token, $config, [
            'email_hash'=>$emailHash, 'device_id'=>$deviceId, 'language'=>$language, 'code_hash'=>$codeHash,
            'sent_at'=>$sentAt, 'expires_at'=>$expiresAt, 'attempts'=>0, 'ip_hash'=>$ipHash, 'updated_at'=>gmdate('c'),
        ]);
        meteonexa_prune_challenge_fallback($emailHash, $sentAt);
        return ['challengeId'=>$token,'expiresAt'=>$expiresAt,'storage'=>'file'];
    }
}

/**
 * @return array{ok:bool,code:string,remaining?:int}
 */
function meteonexa_consume_login_challenge(
    PDO $pdo,
    array $config,
    string $token,
    string $emailHash,
    string $deviceId,
    string $code,
    int $maxAttempts,
    int $now
): array {
    if ($token === '' || strlen($token) < 24 || strlen($token) > 96 || preg_match('/^[A-Za-z0-9_-]+$/', $token) !== 1) {
        return ['ok'=>false,'code'=>'CODE_NOT_FOUND'];
    }
    $hash = meteonexa_login_challenge_hash($token, $config);
    try {
        if (meteonexa_db_table_exists($pdo, 'auth_login_challenges')) {
            $statement = $pdo->prepare('SELECT challenge_hash,email_hash,device_id,code_hash,expires_at,attempts FROM auth_login_challenges WHERE challenge_hash=:challenge LIMIT 1');
            $statement->execute([':challenge'=>$hash]);
            $record = $statement->fetch();
            if (is_array($record)) {
                if (!hash_equals((string)$record['email_hash'], $emailHash) || !hash_equals((string)$record['device_id'], $deviceId)) {
                    return ['ok'=>false,'code'=>'CODE_NOT_FOUND'];
                }
                if ((int)$record['expires_at'] < $now) {
                    $pdo->prepare('DELETE FROM auth_login_challenges WHERE challenge_hash=:challenge')->execute([':challenge'=>$hash]);
                    return ['ok'=>false,'code'=>'CODE_EXPIRED'];
                }
                $attempts = (int)$record['attempts'];
                if ($attempts >= $maxAttempts) {
                    $pdo->prepare('DELETE FROM auth_login_challenges WHERE challenge_hash=:challenge')->execute([':challenge'=>$hash]);
                    return ['ok'=>false,'code'=>'TOO_MANY_ATTEMPTS'];
                }
                if (!password_verify($code, (string)$record['code_hash'])) {
                    $attempts++;
                    if ($attempts >= $maxAttempts) $pdo->prepare('DELETE FROM auth_login_challenges WHERE challenge_hash=:challenge')->execute([':challenge'=>$hash]);
                    else $pdo->prepare('UPDATE auth_login_challenges SET attempts=:attempts,updated_at=:updated WHERE challenge_hash=:challenge')
                        ->execute([':attempts'=>$attempts,':updated'=>gmdate('c'),':challenge'=>$hash]);
                    return ['ok'=>false,'code'=>'INVALID_CODE','remaining'=>max(0,$maxAttempts-$attempts)];
                }
                $pdo->prepare('DELETE FROM auth_login_challenges WHERE challenge_hash=:challenge')->execute([':challenge'=>$hash]);
                return ['ok'=>true,'code'=>'OK'];
            }
        }
    } catch (Throwable $error) {
        meteonexa_log_event('auth_challenge_db_consume_failed', $error);
    }

    // Protected filesystem fallback for partial DB migrations.
    $path = meteonexa_challenge_fallback_path($token, $config);
    $handle = @fopen($path, 'r+');
    if (!is_resource($handle)) return ['ok'=>false,'code'=>'CODE_NOT_FOUND'];
    try {
        if (!flock($handle, LOCK_EX)) return ['ok'=>false,'code'=>'CODE_NOT_FOUND'];
        $raw = stream_get_contents($handle);
        $record = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($record)) { @unlink($path); return ['ok'=>false,'code'=>'CODE_NOT_FOUND']; }
        if (!hash_equals((string)($record['email_hash'] ?? ''), $emailHash) || !hash_equals((string)($record['device_id'] ?? ''), $deviceId)) {
            return ['ok'=>false,'code'=>'CODE_NOT_FOUND'];
        }
        if ((int)($record['expires_at'] ?? 0) < $now) { @unlink($path); return ['ok'=>false,'code'=>'CODE_EXPIRED']; }
        $attempts = (int)($record['attempts'] ?? 0);
        if ($attempts >= $maxAttempts) { @unlink($path); return ['ok'=>false,'code'=>'TOO_MANY_ATTEMPTS']; }
        if (!password_verify($code, (string)($record['code_hash'] ?? ''))) {
            $attempts++;
            if ($attempts >= $maxAttempts) { @unlink($path); }
            else {
                $record['attempts']=$attempts; $record['updated_at']=gmdate('c');
                $payload=json_encode($record, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
                if (is_string($payload)) { ftruncate($handle,0); rewind($handle); fwrite($handle,$payload."\n"); fflush($handle); }
            }
            return ['ok'=>false,'code'=>'INVALID_CODE','remaining'=>max(0,$maxAttempts-$attempts)];
        }
        // Mark consumed before releasing the lock.
        $used = $path . '.used-' . bin2hex(random_bytes(4));
        @rename($path, $used);
        @unlink($used);
        return ['ok'=>true,'code'=>'OK'];
    } finally {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

function meteonexa_revoke_login_challenge(PDO $pdo, array $config, string $token): void
{
    if ($token === '') return;
    $hash = meteonexa_login_challenge_hash($token, $config);
    try {
        if (meteonexa_db_table_exists($pdo, 'auth_login_challenges')) {
            $pdo->prepare('DELETE FROM auth_login_challenges WHERE challenge_hash=:challenge')->execute([':challenge'=>$hash]);
        }
    } catch (Throwable $error) {
        meteonexa_log_event('auth_challenge_db_revoke_failed', $error);
    }
    try { @unlink(meteonexa_challenge_fallback_path($token, $config)); } catch (Throwable $ignored) { }
}
