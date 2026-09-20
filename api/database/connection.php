<?php
declare(strict_types=1);
function meteonexa_db(array $config) : PDO {
    static $pdo = null;
    if ($pdo instanceof PDO)return $pdo;
    $databaseSettings = meteonexa_database_settings();
    if (($databaseSettings['driver']??'sqlite')==='mysql') {
        if (!extension_loaded('pdo_mysql'))throw new RuntimeException('PDO_MYSQL_UNAVAILABLE');
        $pdo = new MeteoNexaMySqlPDO($databaseSettings);
        if (!meteonexa_db_table_exists($pdo, 'app_metadata')) {
            $pdo = null;
            throw new RuntimeException('MYSQL_SCHEMA_MISSING');
        }
        $expectedSecretVerifier = meteonexa_secret_verifier($config);
        $storedSecretVerifier = (string)($pdo->query("SELECT COALESCE((SELECT meta_value FROM app_metadata WHERE meta_key='secret_verifier'), '')")->fetchColumn() ? : '');
        if ($storedSecretVerifier!==''&&!hash_equals($storedSecretVerifier, $expectedSecretVerifier)) {
            $pdo = null;
            throw new RuntimeException('APP_SECRET_MISMATCH');
        }
        if ($storedSecretVerifier==='')meteonexa_verify_existing_secret_material($pdo, $config);
        $schemaVersion = (int)($pdo->query("SELECT COALESCE((SELECT meta_value FROM app_metadata WHERE meta_key='schema_version'), '0')")->fetchColumn() ? : 0);
        if ($schemaVersion < 15) {
            $pdo = null;
            throw new RuntimeException('MYSQL_SCHEMA_OUTDATED');
        }
        $schemaVersion = meteonexa_run_mysql_migrations($pdo, $schemaVersion);
        // Self-heal is best-effort. Failing optional auth DDL must not make the
        // whole database connection unusable: device-bound OTP has a protected
        // fallback store and Devices/Accesses reports the missing audit table.
        try {
            if (!meteonexa_db_table_exists($pdo, 'auth_login_challenges')) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS auth_login_challenges (
                    challenge_hash VARCHAR(128) PRIMARY KEY, email_hash VARCHAR(128) NOT NULL, device_id VARCHAR(191) NOT NULL,
                    language VARCHAR(8) NOT NULL DEFAULT 'it', code_hash VARCHAR(255) NOT NULL, sent_at BIGINT NOT NULL,
                    expires_at BIGINT NOT NULL, attempts INT NOT NULL DEFAULT 0, ip_hash VARCHAR(128) NOT NULL, updated_at VARCHAR(40) NOT NULL,
                    KEY idx_auth_login_challenges_email(email_hash,sent_at), KEY idx_auth_login_challenges_device(device_id,sent_at), KEY idx_auth_login_challenges_expires(expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
        } catch (Throwable $authChallengeSelfHealError) {
            if (function_exists('meteonexa_log_event'))meteonexa_log_event('auth_challenge_selfheal_degraded', $authChallengeSelfHealError);
        }
        try {
            if (!meteonexa_db_table_exists($pdo, 'auth_access_history')) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS auth_access_history (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, session_hash VARCHAR(128) NOT NULL, email_hash VARCHAR(128) NOT NULL,
                    device_id VARCHAR(191) NOT NULL, device_type VARCHAR(40) NOT NULL DEFAULT 'Web', platform VARCHAR(80) NOT NULL DEFAULT '',
                    browser VARCHAR(80) NOT NULL DEFAULT '', client_mode VARCHAR(16) NOT NULL DEFAULT 'web', timezone VARCHAR(80) NOT NULL DEFAULT '',
                    ip_encrypted TEXT NOT NULL, location_label VARCHAR(191) NOT NULL DEFAULT '', created_at BIGINT NOT NULL, last_seen_at BIGINT NOT NULL,
                    ended_at BIGINT NOT NULL DEFAULT 0, end_reason VARCHAR(32) NOT NULL DEFAULT '',
                    KEY idx_auth_access_email(email_hash,last_seen_at), KEY idx_auth_access_session(session_hash), KEY idx_auth_access_device(device_id,last_seen_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
        } catch (Throwable $accessHistorySelfHealError) {
            if (function_exists('meteonexa_log_event'))meteonexa_log_event('auth_access_history_selfheal_degraded', $accessHistorySelfHealError);
        }
        try {
            meteonexa_sync_ui_visibility_defaults($pdo);
        } catch (Throwable $uiVisibilitySyncError) {
            if (function_exists('meteonexa_log_event'))meteonexa_log_event('ui_visibility_sync_failed', $uiVisibilitySyncError);
        }
        try {
            meteonexa_sync_packaged_translations($pdo);
        } catch (Throwable $translationPackageError) {
            if (function_exists('meteonexa_log_event'))meteonexa_log_event('translation_package_sync_failed', $translationPackageError);
        }
        try {
            meteonexa_sync_baseline_translations($pdo);
        } catch (Throwable $translationSeedError) {
            if (function_exists('meteonexa_log_event'))meteonexa_log_event('translation_seed_sync_failed', $translationSeedError);
        }
        try {
            meteonexa_sync_verification_schema($pdo);
            meteonexa_sync_trust_schema($pdo);
            meteonexa_sync_product_metrics_schema($pdo);
            meteonexa_sync_qa_admins($pdo, $config);
            meteonexa_sync_current_policy_translations($pdo);
            meteonexa_sync_current_metadata($pdo);
        } catch (Throwable $currentCapabilityError) {
            if (function_exists('meteonexa_log_event'))meteonexa_log_event('current_capability_sync_failed', $currentCapabilityError);
        }
        try {
            meteonexa_sync_packaged_smtp_profile($pdo);
        } catch (Throwable $smtpPackageProfileError) {
            if (function_exists('meteonexa_log_event'))meteonexa_log_event('smtp_package_profile_sync_failed', $smtpPackageProfileError);
        }
        try {
            meteonexa_sync_baseline_smtp_profile($pdo);
        } catch (Throwable $smtpProfileError) {
            if (function_exists('meteonexa_log_event'))meteonexa_log_event('smtp_profile_sync_failed', $smtpProfileError);
        }
        try {
            meteonexa_seed_email_templates($pdo);
        } catch (Throwable $emailTemplateSeedError) {
            if (function_exists('meteonexa_log_event'))meteonexa_log_event('email_template_seed_failed', $emailTemplateSeedError);
        }
        $verifierStatement = $pdo->prepare("INSERT OR IGNORE INTO app_metadata(meta_key,meta_value,updated_at) VALUES('secret_verifier',:value,:updated)");
        $verifierStatement->execute([':value'=>$expectedSecretVerifier, ':updated'=>gmdate('c')]);
        $pdo->exec("DELETE FROM app_metadata WHERE meta_key='deployment_unbound'");
        $statement = $pdo->prepare('INSERT INTO app_metadata(meta_key, meta_value, updated_at)
            VALUES(:key, :value, :updated_at)
            ON CONFLICT(meta_key) DO UPDATE SET meta_value = excluded.meta_value, updated_at = excluded.updated_at
            WHERE app_metadata.meta_value <> excluded.meta_value');
        $statement->execute([':key'=>'app_version', ':value'=>(string)($config['app']['version']??'unknown'), ':updated_at'=>gmdate('c')]);
        return $pdo;
    }
    $databasePath = meteonexa_sqlite_path();
    $storage = dirname($databasePath);
    if (!is_dir($storage)&&!@mkdir($storage, 0770, true)&&!is_dir($storage)) {
        throw new RuntimeException('SQLITE_DIRECTORY_UNAVAILABLE');
    }
    $pdo = new PDO('sqlite:' . $databasePath, null, null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,]);
    @chmod($databasePath, 0660);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    try {
        $pdo->exec('PRAGMA trusted_schema = OFF');
    } catch (Throwable $ignored) {
        /* older SQLite */
    }
    // app_metadata is the only table required to determine whether migrations
    // must run. All other DDL is version-gated to avoid unnecessary schema
    // locks on every API request on shared hosting.
    $pdo->exec('CREATE TABLE IF NOT EXISTS app_metadata (
        meta_key TEXT PRIMARY KEY,
        meta_value TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    $expectedSecretVerifier = meteonexa_secret_verifier($config);
    $storedSecretVerifier = (string)($pdo->query("SELECT COALESCE((SELECT meta_value FROM app_metadata WHERE meta_key='secret_verifier'), '')")->fetchColumn() ? : '');
    if ($storedSecretVerifier!==''&&!hash_equals($storedSecretVerifier, $expectedSecretVerifier)) {
        $pdo = null;
        throw new RuntimeException('APP_SECRET_MISMATCH');
    }
    if ($storedSecretVerifier==='') {
        meteonexa_verify_existing_secret_material($pdo, $config);
    }
    $currentSchema = meteonexa_current_schema_version();
    $schemaVersion = (int)($pdo->query("SELECT COALESCE((SELECT meta_value FROM app_metadata WHERE meta_key='schema_version'), '0')")->fetchColumn() ? : 0);
    $schemaVersion = meteonexa_run_sqlite_migrations($pdo, $schemaVersion, $currentSchema);
    if (!meteonexa_db_table_exists($pdo, 'auth_login_challenges')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS auth_login_challenges (challenge_hash TEXT PRIMARY KEY,email_hash TEXT NOT NULL,device_id TEXT NOT NULL,language TEXT NOT NULL DEFAULT 'it',code_hash TEXT NOT NULL,sent_at INTEGER NOT NULL,expires_at INTEGER NOT NULL,attempts INTEGER NOT NULL DEFAULT 0,ip_hash TEXT NOT NULL,updated_at TEXT NOT NULL)");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_auth_login_challenges_email ON auth_login_challenges(email_hash,sent_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_auth_login_challenges_device ON auth_login_challenges(device_id,sent_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_auth_login_challenges_expires ON auth_login_challenges(expires_at)');
    }
    if (!meteonexa_db_table_exists($pdo, 'auth_access_history')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS auth_access_history (id INTEGER PRIMARY KEY AUTOINCREMENT,session_hash TEXT NOT NULL,email_hash TEXT NOT NULL,device_id TEXT NOT NULL,device_type TEXT NOT NULL DEFAULT 'Web',platform TEXT NOT NULL DEFAULT '',browser TEXT NOT NULL DEFAULT '',client_mode TEXT NOT NULL DEFAULT 'web',timezone TEXT NOT NULL DEFAULT '',ip_encrypted TEXT NOT NULL,location_label TEXT NOT NULL DEFAULT '',created_at INTEGER NOT NULL,last_seen_at INTEGER NOT NULL,ended_at INTEGER NOT NULL DEFAULT 0,end_reason TEXT NOT NULL DEFAULT '')");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_auth_access_email ON auth_access_history(email_hash,last_seen_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_auth_access_session ON auth_access_history(session_hash)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_auth_access_device ON auth_access_history(device_id,last_seen_at)');
    }
    meteonexa_sync_ui_visibility_defaults($pdo);
    // Existing installations keep their DB-edited translations. New package
    // keys are merged only when missing, so UI additions never appear as raw
    // keys after an upgrade and operator overrides are never overwritten.
    try {
        meteonexa_sync_packaged_translations($pdo);
    } catch (Throwable $translationPackageError) {
        if (function_exists('meteonexa_log_event'))meteonexa_log_event('translation_package_sync_failed', $translationPackageError);
    }
    try {
        meteonexa_sync_baseline_translations($pdo);
    } catch (Throwable $translationSeedError) {
        if (function_exists('meteonexa_log_event'))meteonexa_log_event('translation_seed_sync_failed', $translationSeedError);
    }
    try {
        meteonexa_sync_verification_schema($pdo);
        meteonexa_sync_trust_schema($pdo);
        meteonexa_sync_product_metrics_schema($pdo);
        meteonexa_sync_qa_admins($pdo, $config);
        meteonexa_sync_current_policy_translations($pdo);
        meteonexa_sync_current_metadata($pdo);
    } catch (Throwable $currentCapabilityError) {
        if (function_exists('meteonexa_log_event'))meteonexa_log_event('current_capability_sync_failed', $currentCapabilityError);
    }
    try {
        meteonexa_sync_packaged_smtp_profile($pdo);
    } catch (Throwable $smtpPackageProfileError) {
        if (function_exists('meteonexa_log_event'))meteonexa_log_event('smtp_package_profile_sync_failed', $smtpPackageProfileError);
    }
    try {
        meteonexa_sync_baseline_smtp_profile($pdo);
    } catch (Throwable $smtpProfileError) {
        if (function_exists('meteonexa_log_event'))meteonexa_log_event('smtp_profile_sync_failed', $smtpProfileError);
    }
    try {
        meteonexa_seed_email_templates($pdo);
    } catch (Throwable $emailTemplateSeedError) {
        if (function_exists('meteonexa_log_event'))meteonexa_log_event('email_template_seed_failed', $emailTemplateSeedError);
    }
    // Persist a non-reversible deployment-key verifier after a successful
    // migration/probe. Subsequent requests fail closed immediately if a database
    // and .app-secret from different deployments are accidentally combined.
    $verifierStatement = $pdo->prepare("INSERT OR IGNORE INTO app_metadata(meta_key,meta_value,updated_at) VALUES('secret_verifier',:value,:updated)");
    $verifierStatement->execute([':value'=>$expectedSecretVerifier, ':updated'=>gmdate('c')]);
    // Once the DB is successfully bound, remove the one-shot baseline marker.
    $pdo->exec("DELETE FROM app_metadata WHERE meta_key='deployment_unbound'");
    $now = gmdate('c');
    $statement = $pdo->prepare('INSERT INTO app_metadata(meta_key, meta_value, updated_at)
        VALUES(:key, :value, :updated_at)
        ON CONFLICT(meta_key) DO UPDATE SET meta_value = excluded.meta_value, updated_at = excluded.updated_at
        WHERE app_metadata.meta_value <> excluded.meta_value');
    $statement->execute([':key'=>'app_version', ':value'=>(string)($config['app']['version']??'unknown'), ':updated_at'=>$now]);
    return $pdo;
}
