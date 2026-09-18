<?php
declare(strict_types=1);

function meteonexa_smtp_row_is_complete(PDO $pdo): bool
{
    $row = $pdo->query("SELECT host,username,password_encrypted,from_email FROM smtp_settings WHERE id=1")->fetch();
    if (!is_array($row)) return false;
    return trim((string)($row['host'] ?? '')) !== ''
        && trim((string)($row['from_email'] ?? '')) !== ''
        && trim((string)($row['username'] ?? '')) !== ''
        && trim((string)($row['password_encrypted'] ?? '')) !== '';
}

/**
 * A row can look complete while still being encrypted with a superseded
 * deployment secret. Treat that state as recoverable instead of reporting a
 * false SMTP_NOT_CONFIGURED forever.
 */
function meteonexa_smtp_row_is_usable(PDO $pdo, array $config): bool
{
    if (!meteonexa_smtp_row_is_complete($pdo)) return false;
    try {
        $row = $pdo->query("SELECT password_encrypted FROM smtp_settings WHERE id=1")->fetch();
        if (!is_array($row)) return false;
        return meteonexa_decrypt_secret((string)($row['password_encrypted'] ?? ''), $config) !== '';
    } catch (Throwable $ignored) {
        return false;
    }
}

function meteonexa_smtp_upsert_encrypted(PDO $pdo, array $config, array $smtp, string $plainPassword): void
{
    if ($plainPassword === '') throw new RuntimeException('SMTP_PASSWORD_EMPTY');
    $host = trim((string)($smtp['host'] ?? ''));
    $username = trim((string)($smtp['username'] ?? ''));
    $fromEmail = trim((string)($smtp['from_email'] ?? ''));
    if ($host === '' || $username === '' || filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('SMTP_RECOVERY_INVALID');
    }
    $statement = $pdo->prepare('INSERT INTO smtp_settings(
        id, host, port, encryption, username, password_encrypted,
        from_email, from_name, timeout_seconds, updated_at
    ) VALUES(1, :host, :port, :encryption, :username, :password,
        :from_email, :from_name, :timeout_seconds, :updated_at)
    ON CONFLICT(id) DO UPDATE SET
        host=excluded.host, port=excluded.port, encryption=excluded.encryption,
        username=excluded.username, password_encrypted=excluded.password_encrypted,
        from_email=excluded.from_email, from_name=excluded.from_name,
        timeout_seconds=excluded.timeout_seconds, updated_at=excluded.updated_at');
    $statement->execute([
        ':host'=>$host,
        ':port'=>max(1, min(65535, (int)($smtp['port'] ?? 587))),
        ':encryption'=>in_array(strtolower(trim((string)($smtp['encryption'] ?? 'tls'))), ['ssl','tls'], true)
            ? strtolower(trim((string)($smtp['encryption'] ?? 'tls'))) : 'tls',
        ':username'=>$username,
        ':password'=>meteonexa_encrypt_secret($plainPassword, $config),
        ':from_email'=>$fromEmail,
        ':from_name'=>trim((string)($smtp['from_name'] ?? 'MeteoNexa')) ?: 'MeteoNexa',
        ':timeout_seconds'=>max(5, min(60, (int)($smtp['timeout_seconds'] ?? 18))),
        ':updated_at'=>gmdate('c'),
    ]);
}

function meteonexa_provision_smtp_if_missing(array $config): bool
{
    $pdo = meteonexa_db($config);
    if (meteonexa_smtp_row_is_usable($pdo, $config)) return false;

    // Deployment environment configuration is authoritative. If a password is
    // supplied there, persist it only after encryption with this installation's
    // secret. Otherwise shared hosting can use the local PHP mail transport.
    $runtimeSmtp = (array)($config['smtp'] ?? []);
    // A partial DB row intentionally contains the deployment's non-secret SMTP
    // profile so Aruba's local mail() fallback has a valid From address. When
    // only METEONEXA_SMTP_PASSWORD is supplied, combine it with that profile and
    // persist the resulting full SMTP credential encrypted in the DB.
    $profile = $pdo->query("SELECT host,port,encryption,username,from_email,from_name,timeout_seconds FROM smtp_settings WHERE id=1")->fetch();
    if (is_array($profile)) {
        foreach (['host','port','encryption','username','from_email','from_name','timeout_seconds'] as $field) {
            $runtimeValue = $runtimeSmtp[$field] ?? null;
            if ($runtimeValue === null || trim((string)$runtimeValue) === '' || ($field === 'port' && (int)$runtimeValue < 1)) {
                $runtimeSmtp[$field] = $profile[$field] ?? $runtimeValue;
            }
        }
    }
    $runtimePassword = (string)($runtimeSmtp['password'] ?? '');
    if ($runtimePassword !== ''
        && trim((string)($runtimeSmtp['host'] ?? '')) !== ''
        && trim((string)($runtimeSmtp['username'] ?? '')) !== ''
        && filter_var(trim((string)($runtimeSmtp['from_email'] ?? '')), FILTER_VALIDATE_EMAIL) !== false) {
        meteonexa_smtp_upsert_encrypted($pdo, $config, $runtimeSmtp, $runtimePassword);
        return true;
    }
    return false;
}

function meteonexa_seed_smtp(array $config, array $smtp): void
{
    $pdo = meteonexa_db($config);
    $exists = (int)$pdo->query('SELECT COUNT(*) FROM smtp_settings WHERE id = 1')->fetchColumn();
    if ($exists > 0) return;

    $statement = $pdo->prepare('INSERT INTO smtp_settings(
        id, host, port, encryption, username, password_encrypted,
        from_email, from_name, timeout_seconds, updated_at
    ) VALUES(1, :host, :port, :encryption, :username, :password,
        :from_email, :from_name, :timeout_seconds, :updated_at)');
    $statement->execute([
        ':host' => trim((string)($smtp['host'] ?? '')),
        ':port' => (int)($smtp['port'] ?? 587),
        ':encryption' => trim((string)($smtp['encryption'] ?? 'tls')),
        ':username' => trim((string)($smtp['username'] ?? '')),
        ':password' => meteonexa_encrypt_secret((string)($smtp['password'] ?? ''), $config),
        ':from_email' => trim((string)($smtp['from_email'] ?? '')),
        ':from_name' => trim((string)($smtp['from_name'] ?? 'MeteoNexa')),
        ':timeout_seconds' => max(5, (int)($smtp['timeout_seconds'] ?? 18)),
        ':updated_at' => gmdate('c'),
    ]);
}

function meteonexa_load_smtp(array $config): array
{
    $pdo = meteonexa_db($config);
    $row = $pdo->query('SELECT * FROM smtp_settings WHERE id = 1')->fetch();
    if (!is_array($row)) return [];

    // A stale encrypted SMTP password must never hide the non-secret delivery
    // profile. In that condition authenticated SMTP is disabled, but Aruba's
    // local PHP mail() fallback can still use the DB-backed From identity.
    $password = '';
    $encrypted = trim((string)($row['password_encrypted'] ?? ''));
    if ($encrypted !== '') {
        try {
            $password = meteonexa_decrypt_secret($encrypted, $config);
        } catch (Throwable $smtpDecryptError) {
            if (function_exists('meteonexa_log_event')) meteonexa_log_event('smtp_credential_decrypt_failed_fallback_local_mail', $smtpDecryptError);
            $password = '';
        }
    }
    return [
        'host' => (string)$row['host'],
        'port' => (int)$row['port'],
        'encryption' => (string)$row['encryption'],
        'username' => (string)$row['username'],
        'password' => $password,
        'from_email' => (string)$row['from_email'],
        'from_name' => (string)$row['from_name'],
        'timeout_seconds' => (int)$row['timeout_seconds'],
    ];
}
