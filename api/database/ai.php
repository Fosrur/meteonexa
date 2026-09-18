<?php
declare(strict_types=1);

function meteonexa_ai_settings_row(PDO $pdo): array
{
    if (!meteonexa_db_table_exists($pdo, 'ai_settings')) return [];
    $row = $pdo->query('SELECT provider,model,api_key_encrypted,site_url,site_name,updated_at FROM ai_settings WHERE id=1')->fetch();
    return is_array($row) ? $row : [];
}

function meteonexa_ai_row_is_usable(PDO $pdo, array $config): bool
{
    $row = meteonexa_ai_settings_row($pdo);
    if ($row === []) return false;
    $encrypted = trim((string)($row['api_key_encrypted'] ?? ''));
    if ($encrypted === '') return false;
    try {
        $plain = meteonexa_decrypt_value($encrypted, $config, 'ai-provider');
        return trim($plain) !== '';
    } catch (Throwable $ignored) {
        return false;
    }
}

function meteonexa_ai_validate_site_url(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';
    $parts = parse_url($value);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') return '';
    $host = (string)($parts['host'] ?? '');
    if ($host === '' || preg_match('/^[A-Za-z0-9.-]+$/', $host) !== 1) return '';
    if (isset($parts['user']) || isset($parts['pass'])) return '';
    return substr($value, 0, 512);
}

function meteonexa_ai_upsert_encrypted(PDO $pdo, array $config, array $ai, string $plainKey): void
{
    $plainKey = trim($plainKey);
    if ($plainKey === '' || strlen($plainKey) > 1024) throw new RuntimeException('AI_KEY_INVALID');
    $provider = strtolower(trim((string)($ai['provider'] ?? 'openrouter')));
    if (!in_array($provider, ['openrouter','groq'], true)) $provider = 'openrouter';
    $defaultModel = $provider === 'groq' ? 'openai/gpt-oss-20b' : 'nvidia/nemotron-3-super-120b-a12b:free';
    $model = trim((string)($ai['model'] ?? $defaultModel));
    if ($model === '' || strlen($model) > 160 || preg_match('/^[A-Za-z0-9._:\/-]+$/', $model) !== 1) $model = $defaultModel;
    $freeOnly = (bool)($config['ai']['openrouter_free_only'] ?? true);
    if ($provider === 'openrouter' && $freeOnly) $model = 'nvidia/nemotron-3-super-120b-a12b:free';
    $siteUrl = meteonexa_ai_validate_site_url((string)($ai['site_url'] ?? ''));
    $siteName = trim((string)($ai['site_name'] ?? 'MeteoNexa')) ?: 'MeteoNexa';
    if (function_exists('mb_substr')) $siteName = mb_substr($siteName, 0, 80, 'UTF-8');
    else $siteName = substr($siteName, 0, 80);

    $statement = $pdo->prepare('INSERT INTO ai_settings(id,provider,model,api_key_encrypted,site_url,site_name,updated_at)
        VALUES(1,:provider,:model,:api_key,:site_url,:site_name,:updated_at)
        ON CONFLICT(id) DO UPDATE SET provider=excluded.provider,model=excluded.model,
        api_key_encrypted=excluded.api_key_encrypted,site_url=excluded.site_url,
        site_name=excluded.site_name,updated_at=excluded.updated_at');
    $statement->execute([
        ':provider'=>$provider,
        ':model'=>$model,
        ':api_key'=>meteonexa_encrypt_value($plainKey, $config, 'ai-provider'),
        ':site_url'=>$siteUrl,
        ':site_name'=>$siteName,
        ':updated_at'=>gmdate('c'),
    ]);
}

function meteonexa_load_ai(array $config): array
{
    $pdo = meteonexa_db($config);
    $row = meteonexa_ai_settings_row($pdo);
    if ($row === []) return [];
    try {
        $key = meteonexa_decrypt_value((string)($row['api_key_encrypted'] ?? ''), $config, 'ai-provider');
    } catch (Throwable $ignored) {
        return [];
    }
    if (trim($key) === '') return [];
    $provider = strtolower(trim((string)($row['provider'] ?? 'openrouter')));
    if (!in_array($provider, ['openrouter','groq'], true)) $provider = 'openrouter';
    $model = trim((string)($row['model'] ?? ''));
    if ($model === '') $model = $provider === 'groq' ? 'openai/gpt-oss-20b' : 'nvidia/nemotron-3-super-120b-a12b:free';
    $freeOnly = (bool)($config['ai']['openrouter_free_only'] ?? true);
    if ($provider === 'openrouter' && $freeOnly && $model !== 'nvidia/nemotron-3-super-120b-a12b:free') {
        // Persist the effective zero-cost router as well, so diagnostics/DB state
        // match what is actually sent to OpenRouter. The API key is untouched.
        try {
            $update = $pdo->prepare('UPDATE ai_settings SET model=:model, updated_at=:updated WHERE id=1 AND provider=:provider');
            $update->execute([':model'=>'nvidia/nemotron-3-super-120b-a12b:free', ':updated'=>gmdate('c'), ':provider'=>'openrouter']);
        } catch (Throwable $freeModelPersistError) {
            if (function_exists('meteonexa_log_event')) meteonexa_log_event('ai_free_model_persist_degraded', $freeModelPersistError);
        }
        $model = 'nvidia/nemotron-3-super-120b-a12b:free';
    }
    return [
        'provider'=>$provider,
        'api_key'=>$key,
        'openrouter_api_key'=>$provider === 'openrouter' ? $key : '',
        'openrouter_model'=>$provider === 'openrouter' ? $model : 'nvidia/nemotron-3-super-120b-a12b:free',
        'groq_api_key'=>$provider === 'groq' ? $key : '',
        'groq_model'=>$provider === 'groq' ? $model : 'openai/gpt-oss-20b',
        'site_url'=>(string)($row['site_url'] ?? ''),
        'site_name'=>(string)($row['site_name'] ?? 'MeteoNexa'),
    ];
}

function meteonexa_scrub_provision_file(string $path): void
{
    if (!is_file($path)) return;
    // Best-effort overwrite before deletion. Some shared-hosting deployments
    // may deny writes to application files; api/install is web-denied anyway.
    $size = (int)@filesize($path);
    if ($size > 0 && is_writable($path)) {
        @file_put_contents($path, str_repeat('0', min($size, 4096)), LOCK_EX);
    }
    @unlink($path);
}

function meteonexa_recover_ai_from_legacy_sqlite(PDO $target, array $config): bool
{
    // MySQL migration safety net: an existing SQLite runtime is deliberately
    // kept as rollback. If the MySQL ai_settings row is missing/unusable, copy
    // the provider credential from that rollback DB only when it can be
    // decrypted with the ACTIVE deployment secret. No ciphertext is copied
    // blindly across installations with a different .app-secret.
    if (meteonexa_pdo_driver($target) !== 'mysql') return false;
    $legacyPath = meteonexa_runtime_sqlite_path();
    if (!is_file($legacyPath) || (int)@filesize($legacyPath) < 1024) return false;
    try {
        $legacy = new PDO('sqlite:' . $legacyPath, null, null, [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT=>2,
        ]);
        try { $legacy->exec('PRAGMA query_only = ON'); } catch (Throwable $ignored) { }
        $exists = $legacy->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='ai_settings' LIMIT 1")->fetchColumn();
        if (!$exists) return false;
        $row = $legacy->query('SELECT provider,model,api_key_encrypted,site_url,site_name FROM ai_settings WHERE id=1')->fetch();
        if (!is_array($row)) return false;
        $sealed = trim((string)($row['api_key_encrypted'] ?? ''));
        if ($sealed === '') return false;
        $plain = meteonexa_decrypt_value($sealed, $config, 'ai-provider');
        if (trim($plain) === '') return false;
        meteonexa_ai_upsert_encrypted($target, $config, [
            'provider'=>(string)($row['provider'] ?? 'openrouter'),
            'model'=>(string)($row['model'] ?? 'nvidia/nemotron-3-super-120b-a12b:free'),
            'site_url'=>(string)($row['site_url'] ?? ''),
            'site_name'=>(string)($row['site_name'] ?? 'MeteoNexa'),
        ], $plain);
        $plain = '';
        return true;
    } catch (Throwable $error) {
        if (function_exists('meteonexa_log_event')) meteonexa_log_event('ai_legacy_sqlite_recovery_failed', $error);
        return false;
    }
}

function meteonexa_provision_ai_if_missing(array $config): bool
{
    $pdo = meteonexa_db($config);
    $sealedBootstrapPath = __DIR__ . '/install/ai-provider-bootstrap.json';
    $provisionPath = __DIR__ . '/install/ai-provider-provision.json';

    // Runtime DB is authoritative. Once a usable row exists, remove any
    // one-shot provisioning material left by the update package.
    if (meteonexa_ai_row_is_usable($pdo, $config)) {
        meteonexa_scrub_provision_file($sealedBootstrapPath);
        meteonexa_scrub_provision_file($provisionPath);
        return false;
    }

    // One-shot shared-hosting provisioning. The update package may carry a
    // server-only credential file under api/install (Require all denied). It is
    // read only when ai_settings is not usable, immediately encrypted with the
    // ACTIVE deployment secret and persisted to ai_settings, then scrubbed.
    // This avoids binding the bootstrap ciphertext to a different .app-secret.
    if (is_file($provisionPath)) {
        $raw = @file_get_contents($provisionPath);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($data) && ($data['provision_once'] ?? false) === true) {
            $plain = trim((string)($data['api_key'] ?? ''));
            try {
                if ($plain === '' || strlen($plain) > 1024) throw new RuntimeException('AI_KEY_INVALID');
                meteonexa_ai_upsert_encrypted($pdo, $config, [
                    'provider'=>(string)($data['provider'] ?? 'openrouter'),
                    'model'=>(string)($data['model'] ?? 'nvidia/nemotron-3-super-120b-a12b:free'),
                    'site_url'=>(string)($data['site_url'] ?? ''),
                    'site_name'=>(string)($data['site_name'] ?? 'MeteoNexa'),
                ], $plain);
                // Clear the in-memory copy as soon as possible as well.
                $plain = '';
                meteonexa_scrub_provision_file($provisionPath);
                meteonexa_scrub_provision_file($sealedBootstrapPath);
                return true;
            } catch (Throwable $error) {
                $plain = '';
                if (function_exists('meteonexa_log_event')) meteonexa_log_event('ai_provision_import_failed', $error);
            }
        }
    }

    // Backward-compatible sealed bootstrap. This works only when the archive
    // was prepared for the same deployment secret.
    if (is_file($sealedBootstrapPath)) {
        $raw = @file_get_contents($sealedBootstrapPath);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($data)) {
            $sealed = trim((string)($data['sealed_key'] ?? ''));
            try {
                $plain = meteonexa_decrypt_value($sealed, $config, 'ai-provider-bootstrap');
                meteonexa_ai_upsert_encrypted($pdo, $config, [
                    'provider'=>(string)($data['provider'] ?? 'openrouter'),
                    'model'=>(string)($data['model'] ?? 'nvidia/nemotron-3-super-120b-a12b:free'),
                    'site_url'=>(string)($data['site_url'] ?? ''),
                    'site_name'=>(string)($data['site_name'] ?? 'MeteoNexa'),
                ], $plain);
                meteonexa_scrub_provision_file($sealedBootstrapPath);
                meteonexa_scrub_provision_file($provisionPath);
                return true;
            } catch (Throwable $error) {
                if (function_exists('meteonexa_log_event')) meteonexa_log_event('ai_bootstrap_import_failed', $error);
            }
        }
    }

    // Migration recovery: when MySQL became authoritative but its AI row was
    // not carried over, recover from the intentionally retained SQLite rollback
    // database before requiring any operator action.
    if (meteonexa_recover_ai_from_legacy_sqlite($pdo, $config)) {
        meteonexa_scrub_provision_file($sealedBootstrapPath);
        meteonexa_scrub_provision_file($provisionPath);
        return true;
    }

    // Generic deployment fallback: environment configuration is imported once
    // and re-encrypted into the runtime DB, exactly like the SMTP provisioning
    // path. The DB remains authoritative afterwards.
    $runtimeAi = (array)($config['ai'] ?? []);
    $provider = strtolower(trim((string)($runtimeAi['provider'] ?? 'openrouter')));
    if (!in_array($provider, ['openrouter','groq'], true)) $provider = 'openrouter';
    $plain = $provider === 'groq'
        ? trim((string)($runtimeAi['groq_api_key'] ?? ''))
        : trim((string)($runtimeAi['openrouter_api_key'] ?? $runtimeAi['api_key'] ?? ''));
    if ($plain === '') return false;
    meteonexa_ai_upsert_encrypted($pdo, $config, [
        'provider'=>$provider,
        'model'=>$provider === 'groq'
            ? (string)($runtimeAi['groq_model'] ?? 'openai/gpt-oss-20b')
            : (string)($runtimeAi['openrouter_model'] ?? 'nvidia/nemotron-3-super-120b-a12b:free'),
        'site_url'=>(string)($runtimeAi['site_url'] ?? $config['app']['base_url'] ?? ''),
        'site_name'=>(string)($runtimeAi['site_name'] ?? $config['app']['name'] ?? 'MeteoNexa'),
    ], $plain);
    $plain = '';
    return true;
}
