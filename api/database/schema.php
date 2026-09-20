<?php
declare(strict_types=1);
function meteonexa_db_table_exists(PDO $pdo, string $table) : bool {
    if (meteonexa_pdo_driver($pdo)==='mysql') {
        $statement = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:name LIMIT 1');
        $statement->execute([':name'=>$table]);
        return (bool)$statement->fetchColumn();
    }
    $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:name LIMIT 1");
    $statement->execute([':name'=>$table]);
    return (bool)$statement->fetchColumn();
}
function meteonexa_db_column_exists(PDO $pdo, string $table, string $column) : bool {
    if (meteonexa_pdo_driver($pdo)==='mysql') {
        $statement = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:table AND column_name=:column LIMIT 1');
        $statement->execute([':table'=>$table, ':column'=>$column]);
        return (bool)$statement->fetchColumn();
    }
    $safe = str_replace("'", "''", $table);
    $rows = $pdo->query("PRAGMA table_info('{$safe}')")->fetchAll();
    foreach ($rows as $row) {
        if (is_array($row)&&(string)($row['name']??'')===$column)return true;
    }
    return false;
}
/**
 * First upgrade to the secret-verifier schema: prove that the supplied key can
 * actually decrypt existing protected material before trusting it. This avoids
 * silently binding a copied database to the wrong .app-secret.
 */
function meteonexa_verify_existing_secret_material(PDO $pdo, array $config) : void {
    $checked = false;
    if (meteonexa_db_table_exists($pdo, 'smtp_settings')) {
        $value = (string)($pdo->query("SELECT COALESCE(password_encrypted,'') FROM smtp_settings WHERE id=1")->fetchColumn() ? : '');
        if ($value!==''&&str_starts_with($value, 'enc:v1:')) {
            $checked = true;
            try {
                meteonexa_decrypt_secret($value, $config);
            } catch (Throwable $error) {
                throw new RuntimeException('APP_SECRET_MISMATCH');
            }
        }
    }
    if (!$checked&&meteonexa_db_table_exists($pdo, 'netatmo_accounts')) {
        $row = $pdo->query("SELECT access_token_enc,refresh_token_enc FROM netatmo_accounts LIMIT 1")->fetch();
        if (is_array($row)) {
            foreach (['access_token_enc', 'refresh_token_enc'] as $column) {
                $value = (string)($row[$column]??'');
                if ($value===''||!str_starts_with($value, 'enc:v1:'))continue;
                try {
                    meteonexa_decrypt_value($value, $config, 'netatmo');
                } catch (Throwable $error) {
                    throw new RuntimeException('APP_SECRET_MISMATCH');
                }
                break;
            }
        }
    }
}
/**
 * Merge translation keys added by newer application packages into an existing
 * runtime database without overwriting operator customisations. The baseline is
 * a seed catalog, not the authoritative runtime copy: only missing locale/key
 * pairs are inserted, and a seed-version marker avoids the scan on every request.
 */
function meteonexa_sync_baseline_translations(PDO $pdo) : void {
    $baselinePath = meteonexa_baseline_sqlite_path();
    if (!is_file($baselinePath)||(int)@filesize($baselinePath) < 1024)return;
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true))return;
    $baseline = new PDO('sqlite:' . $baselinePath, null, null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT=>2,]);
    try {
        $baseline->exec('PRAGMA query_only = ON');
    } catch (Throwable $ignored) {
    }
    $seedVersion = (string)($baseline->query("SELECT COALESCE((SELECT meta_value FROM app_metadata WHERE meta_key='translation_seed_version'),'')")->fetchColumn() ? : '');
    if ($seedVersion==='')return;
    $runtimeVersion = (string)($pdo->query("SELECT COALESCE((SELECT meta_value FROM app_metadata WHERE meta_key='translation_seed_version'),'')")->fetchColumn() ? : '');
    if ($runtimeVersion===$seedVersion)return;
    $rows = $baseline->query('SELECT locale,text_key,translation,updated_at FROM translations ORDER BY locale,text_key');
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $insert = $pdo->prepare('INSERT OR IGNORE INTO translations(locale,text_key,translation,updated_at) VALUES(:locale,:key,:translation,:updated)');
        while ($row = $rows->fetch()) {
            $insert->execute([':locale'=>(string)$row['locale'], ':key'=>(string)$row['text_key'], ':translation'=>(string)$row['translation'], ':updated'=>(string)$row['updated_at'],]);
        }
        $meta = $pdo->prepare('INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:key,:value,:updated)
            ON CONFLICT(meta_key) DO UPDATE SET meta_value=excluded.meta_value,updated_at=excluded.updated_at');
        $meta->execute([':key'=>'translation_seed_version', ':value'=>$seedVersion, ':updated'=>gmdate('c')]);
        $pdo->exec('COMMIT');
    } catch (Throwable $error) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable $ignored) {
        }
        throw $error;
    }
}
function meteonexa_sync_product_metrics_schema(PDO $pdo) : void {
    $mysql = meteonexa_pdo_driver($pdo)==='mysql';
    $pdo->exec($mysql ? "CREATE TABLE IF NOT EXISTS product_metrics_daily (metric_date VARCHAR(10) NOT NULL,event_name VARCHAR(64) NOT NULL,event_count BIGINT UNSIGNED NOT NULL DEFAULT 0,updated_at VARCHAR(40) NOT NULL,PRIMARY KEY(metric_date,event_name),KEY idx_product_metrics_event(event_name,metric_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" : "CREATE TABLE IF NOT EXISTS product_metrics_daily (metric_date TEXT NOT NULL,event_name TEXT NOT NULL,event_count INTEGER NOT NULL DEFAULT 0 CHECK(event_count>=0),updated_at TEXT NOT NULL,PRIMARY KEY(metric_date,event_name))");
    if (!$mysql)$pdo->exec('CREATE INDEX IF NOT EXISTS idx_product_metrics_event ON product_metrics_daily(event_name,metric_date)');
}
function meteonexa_sync_qa_admins(PDO $pdo, array $config) : void {
    if (!meteonexa_db_table_exists($pdo, 'app_metadata'))return;
    $secret = trim((string)($config['auth']['app_secret']??''));
    if (strlen($secret) < 32)return;
    $emails =[];
    foreach ((array)($config['qa']['admin_emails']??[]) as $candidate) {
        $candidate = strtolower(trim((string)$candidate));
        if (filter_var($candidate, FILTER_VALIDATE_EMAIL)!==false)$emails[$candidate] = true;
    }
    $existing =[];
    $read = $pdo->prepare("SELECT meta_value FROM app_metadata WHERE meta_key='qa_admin_email_hashes' LIMIT 1");
    $read->execute();
    foreach (preg_split('/[,;\s]+/', trim((string)($read->fetchColumn() ? : ''))) ? :[] as $stored) {
        $stored = strtolower(trim((string)$stored));
        if (preg_match('/^[a-f0-9]{64}$/', $stored)===1)$existing[$stored] = true;
    }
    foreach (array_keys($emails) as $email)$existing[meteonexa_hmac_identifier('qa-admin-email:' . $email, $secret)] = true;
    ksort($existing, SORT_STRING);
    if (!$existing)return;
    $sql = meteonexa_pdo_driver($pdo)==='mysql' ? "INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:key,:value,:updated) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=VALUES(updated_at)" : "INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:key,:value,:updated) ON CONFLICT(meta_key) DO UPDATE SET meta_value=excluded.meta_value,updated_at=excluded.updated_at";
    $meta = $pdo->prepare($sql);
    $meta->execute([':key'=>'qa_admin_email_hashes', ':value'=>implode(',', array_keys($existing)), ':updated'=>gmdate('c')]);
}
function meteonexa_sync_current_metadata(PDO $pdo) : void {
    if (!meteonexa_db_table_exists($pdo, 'app_metadata'))return;
    $sql = meteonexa_pdo_driver($pdo)==='mysql' ? "INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:key,:value,:updated) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=VALUES(updated_at)" : "INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:key,:value,:updated) ON CONFLICT(meta_key) DO UPDATE SET meta_value=excluded.meta_value,updated_at=excluded.updated_at";
    $meta = $pdo->prepare($sql);
    $now = gmdate('c');
    foreach (['schema_version'=>'28', 'app_version'=>'20.1', 'translation_seed_version'=>'20.1-semantic-i18n-v2'] as $key=>$value) {
        $meta->execute([':key'=>$key, ':value'=>$value, ':updated'=>$now]);
    }
}
function meteonexa_sync_current_policy_translations(PDO $pdo) : void {
    if (!meteonexa_db_table_exists($pdo, 'translations'))return;
    $path = __DIR__ . '/install/translations.json';
    $raw = is_file($path) ? @file_get_contents($path) : false;
    $seed = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($seed)||!is_array($seed['rows']??null))return;
    $mysql = meteonexa_pdo_driver($pdo)==='mysql';
    $sql = $mysql ? 'INSERT INTO translations(locale,text_key,translation,updated_at) VALUES(:locale,:key,:translation,:updated) ON DUPLICATE KEY UPDATE translation=VALUES(translation),updated_at=VALUES(updated_at)' : 'INSERT INTO translations(locale,text_key,translation,updated_at) VALUES(:locale,:key,:translation,:updated) ON CONFLICT(locale,text_key) DO UPDATE SET translation=excluded.translation,updated_at=excluded.updated_at';
    $write = $pdo->prepare($sql);
    $now = gmdate('c');
    foreach ($seed['rows'] as $row) {
        if (!is_array($row))continue;
        $key = (string)($row['text_key']??'');
        if (!preg_match('/^(privacy\.|cookie\.|copilot\.|ai\.system\.)/', $key))continue;
        $locale = (string)($row['locale']??'');
        $translation = (string)($row['translation']??'');
        if ($locale===''||$key===''||$translation==='')continue;
        $write->execute([':locale'=>$locale, ':key'=>$key, ':translation'=>$translation, ':updated'=>$now]);
    }
}
function meteonexa_sync_packaged_translations(PDO $pdo) : void {
    if (!meteonexa_db_table_exists($pdo, 'translations'))return;
    $path = __DIR__ . '/install/translations.json';
    if (!is_file($path))return;
    $raw = @file_get_contents($path);
    $seed = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($seed)||!is_array($seed['rows']??null))throw new RuntimeException('TRANSLATION_PACKAGE_INVALID');
    $version = trim((string)($seed['version']??''));
    if ($version==='')throw new RuntimeException('TRANSLATION_PACKAGE_INVALID');
    $markerKey = 'translation_package_18_4';
    $current = (string)($pdo->query("SELECT COALESCE((SELECT meta_value FROM app_metadata WHERE meta_key='" . $markerKey . "'),'')")->fetchColumn() ? : '');
    if ($current===$version)return;
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $insert = $pdo->prepare('INSERT OR IGNORE INTO translations(locale,text_key,translation,updated_at) VALUES(:locale,:key,:translation,:updated)');
        foreach ($seed['rows'] as $row) {
            if (!is_array($row))continue;
            $locale = trim((string)($row['locale']??''));
            $key = trim((string)($row['text_key']??''));
            $translation = (string)($row['translation']??'');
            if ($locale===''||$key===''||$translation==='')continue;
            $insert->execute([':locale'=>$locale, ':key'=>$key, ':translation'=>$translation, ':updated'=>(string)($row['updated_at']??gmdate('c'))]);
        }
        $meta = $pdo->prepare('INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:key,:value,:updated) ON CONFLICT(meta_key) DO UPDATE SET meta_value=excluded.meta_value,updated_at=excluded.updated_at');
        $meta->execute([':key'=>$markerKey, ':value'=>$version, ':updated'=>gmdate('c')]);
        $pdo->exec('COMMIT');
    } catch (Throwable $error) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable $ignored) {
        }
        throw $error;
    }
}
/**
 * Import the packaged non-secret SMTP profile directly into the active DB.
 *
 * This is intentionally independent from PDO SQLite: an installation already
 * migrated to MySQL/MariaDB must still be able to restore Aruba's local mail()
 * transport even when pdo_sqlite is unavailable. The package never contains
 * an SMTP password. Existing encrypted credentials are preserved verbatim.
 * After a valid profile is present in the runtime DB, the one-shot seed file
 * is removed.
 */
function meteonexa_sync_packaged_smtp_profile(PDO $pdo) : void {
    if (!meteonexa_db_table_exists($pdo, 'smtp_settings'))return;
    $path = __DIR__ . '/install/smtp-profile-provision.json';
    if (!is_file($path))return;
    $raw = @file_get_contents($path);
    $seed = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($seed))throw new RuntimeException('SMTP_PROFILE_PROVISION_INVALID');
    $host = trim((string)($seed['host']??''));
    $port = max(1, min(65535, (int)($seed['port']??587)));
    $encryption = strtolower(trim((string)($seed['encryption']??'tls')));
    $username = trim((string)($seed['username']??''));
    $fromEmail = trim((string)($seed['from_email']??''));
    $fromName = trim((string)($seed['from_name']??'MeteoNexa')) ? : 'MeteoNexa';
    $timeout = max(5, min(60, (int)($seed['timeout_seconds']??18)));
    if ($host===''||!in_array($encryption,['ssl', 'tls'], true)||filter_var($fromEmail, FILTER_VALIDATE_EMAIL)===false) {
        throw new RuntimeException('SMTP_PROFILE_PROVISION_INVALID');
    }
    $current = $pdo->query("SELECT id,host,port,encryption,username,password_encrypted,from_email,from_name,timeout_seconds FROM smtp_settings WHERE id=1")->fetch();
    $now = gmdate('c');
    if (!is_array($current)) {
        $insert = $pdo->prepare('INSERT INTO smtp_settings(
            id,host,port,encryption,username,password_encrypted,from_email,from_name,timeout_seconds,updated_at
        ) VALUES(1,:host,:port,:encryption,:username,:password,:from_email,:from_name,:timeout_seconds,:updated_at)');
        $insert->execute([':host'=>$host, ':port'=>$port, ':encryption'=>$encryption, ':username'=>$username, ':password'=>'', ':from_email'=>$fromEmail, ':from_name'=>$fromName, ':timeout_seconds'=>$timeout, ':updated_at'=>$now,]);
    } else {
        $merged = $current;
        foreach (['host'=>$host, 'encryption'=>$encryption, 'username'=>$username, 'from_email'=>$fromEmail, 'from_name'=>$fromName,] as $field=>$value) {
            if (trim((string)($merged[$field]??''))==='')$merged[$field] = $value;
        }
        if ((int)($merged['port']??0) < 1)$merged['port'] = $port;
        if ((int)($merged['timeout_seconds']??0) < 5)$merged['timeout_seconds'] = $timeout;
        $changed = false;
        foreach (['host', 'port', 'encryption', 'username', 'from_email', 'from_name', 'timeout_seconds'] as $field) {
            if ((string)($merged[$field]??'')!==(string)($current[$field]??'')) {
                $changed = true;
                break;
            }
        }
        if ($changed) {
            $update = $pdo->prepare('UPDATE smtp_settings SET host=:host,port=:port,encryption=:encryption,
                username=:username,from_email=:from_email,from_name=:from_name,timeout_seconds=:timeout_seconds,updated_at=:updated_at WHERE id=1');
            $update->execute([':host'=>(string)$merged['host'], ':port'=>(int)$merged['port'], ':encryption'=>(string)$merged['encryption'], ':username'=>(string)$merged['username'], ':from_email'=>(string)$merged['from_email'], ':from_name'=>(string)$merged['from_name'], ':timeout_seconds'=>(int)$merged['timeout_seconds'], ':updated_at'=>$now,]);
        }
    }
    $verified = $pdo->query("SELECT host,port,encryption,from_email FROM smtp_settings WHERE id=1")->fetch();
    if (is_array($verified)&&trim((string)($verified['host']??''))!==''&&(int)($verified['port']??0) > 0&&in_array(strtolower(trim((string)($verified['encryption']??''))),['ssl', 'tls'], true)&&filter_var(trim((string)($verified['from_email']??'')), FILTER_VALIDATE_EMAIL)!==false) {
        meteonexa_scrub_provision_file($path);
    }
}
/**
 * Merge the non-secret SMTP delivery profile from the distributable baseline
 * into the active runtime DB. This restores the legacy Aruba local-mail path
 * without putting SMTP passwords back into PHP/config files.
 *
 * Only missing/blank non-secret fields are filled. A configured runtime row,
 * and especially password_encrypted, is never overwritten.
 */
function meteonexa_sync_baseline_smtp_profile(PDO $pdo) : void {
    if (!meteonexa_db_table_exists($pdo, 'smtp_settings'))return;
    $current = $pdo->query("SELECT id,host,port,encryption,username,password_encrypted,from_email,from_name,timeout_seconds FROM smtp_settings WHERE id=1")->fetch();
    // Fast path: after the first successful seed, do not reopen the baseline on
    // every API request. Password completeness is intentionally irrelevant here;
    // this profile also exists to support Aruba's local PHP mail transport.
    if (is_array($current)&&trim((string)($current['host']??''))!==''&&(int)($current['port']??0) > 0&&in_array(strtolower(trim((string)($current['encryption']??''))),['ssl', 'tls'], true)&&trim((string)($current['username']??''))!==''&&filter_var(trim((string)($current['from_email']??'')), FILTER_VALIDATE_EMAIL)!==false&&trim((string)($current['from_name']??''))!==''&&(int)($current['timeout_seconds']??0)>=5) {
        return;
    }
    $baselinePath = meteonexa_baseline_sqlite_path();
    if (!is_file($baselinePath)||(int)@filesize($baselinePath) < 1024)return;
    try {
        $baseline = new PDO('sqlite:' . $baselinePath, null, null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT=>2,]);
        $seed = $baseline->query("SELECT host,port,encryption,username,from_email,from_name,timeout_seconds FROM smtp_settings WHERE id=1")->fetch();
        if (!is_array($seed))return;
    } catch (Throwable $ignored) {
        // MySQL-only deployments may intentionally lack PDO SQLite. In that
        // case the already-migrated runtime DB remains authoritative.
        return;
    }
    $now = gmdate('c');
    if (!is_array($current)) {
        $insert = $pdo->prepare('INSERT INTO smtp_settings(
            id,host,port,encryption,username,password_encrypted,from_email,from_name,timeout_seconds,updated_at
        ) VALUES(1,:host,:port,:encryption,:username,\'\',:from_email,:from_name,:timeout_seconds,:updated_at)');
        $insert->execute([':host'=>(string)$seed['host'], ':port'=>(int)$seed['port'], ':encryption'=>(string)$seed['encryption'], ':username'=>(string)$seed['username'], ':from_email'=>(string)$seed['from_email'], ':from_name'=>(string)$seed['from_name'], ':timeout_seconds'=>(int)$seed['timeout_seconds'], ':updated_at'=>$now,]);
        return;
    }
    $merged = $current;
    foreach (['host', 'encryption', 'username', 'from_email', 'from_name'] as $field) {
        if (trim((string)($merged[$field]??''))==='')$merged[$field] = (string)($seed[$field]??'');
    }
    if ((int)($merged['port']??0) < 1)$merged['port'] = (int)($seed['port']??587);
    if ((int)($merged['timeout_seconds']??0) < 5)$merged['timeout_seconds'] = (int)($seed['timeout_seconds']??18);
    $changed = false;
    foreach (['host', 'port', 'encryption', 'username', 'from_email', 'from_name', 'timeout_seconds'] as $field) {
        if ((string)($merged[$field]??'')!==(string)($current[$field]??'')) {
            $changed = true;
            break;
        }
    }
    if (!$changed)return;
    $update = $pdo->prepare('UPDATE smtp_settings SET host=:host,port=:port,encryption=:encryption,
        username=:username,from_email=:from_email,from_name=:from_name,timeout_seconds=:timeout_seconds,updated_at=:updated_at WHERE id=1');
    $update->execute([':host'=>(string)$merged['host'], ':port'=>(int)$merged['port'], ':encryption'=>(string)$merged['encryption'], ':username'=>(string)$merged['username'], ':from_email'=>(string)$merged['from_email'], ':from_name'=>(string)$merged['from_name'], ':timeout_seconds'=>(int)$merged['timeout_seconds'], ':updated_at'=>$now,]);
}
function meteonexa_sync_ui_visibility_defaults(PDO $pdo) : void {
    if (!meteonexa_db_table_exists($pdo, 'ui_visibility'))return;
    $defaults =['feature.intelligence.consensus'=>[1, 1], 'feature.intelligence.skill'=>[0, 1], 'feature.intelligence.change'=>[0, 1], 'feature.intelligence.explainability'=>[1, 1], 'feature.intelligence.celltracking'=>[0, 1], 'feature.intelligence.decision'=>[1, 1], 'feature.intelligence.locations'=>[0, 1], 'page.feedback'=>[1, 1],];
    $insert = $pdo->prepare('INSERT OR IGNORE INTO ui_visibility(feature_key,guest_visible,authenticated_visible,updated_at) VALUES(:feature,:guest,:authenticated,:updated)');
    foreach ($defaults as $featureKey=>[$guestVisible, $authenticatedVisible]) {
        $insert->execute([':feature'=>$featureKey, ':guest'=>$guestVisible, ':authenticated'=>$authenticatedVisible, ':updated'=>gmdate('c'),]);
    }
}
function meteonexa_sync_verification_schema(PDO $pdo) : void {
    $driver = meteonexa_pdo_driver($pdo);
    $mysql = $driver==='mysql';
    $ddl = $mysql ?["CREATE TABLE IF NOT EXISTS radar_eta_predictions (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,device_id VARCHAR(191) NOT NULL,location_key VARCHAR(191) NOT NULL,prediction_key VARCHAR(64) NOT NULL,issued_at VARCHAR(40) NOT NULL,predicted_at VARCHAR(40) NOT NULL,eta_minutes INT NOT NULL,tolerance_minutes INT NOT NULL DEFAULT 10,confidence INT NOT NULL DEFAULT 0,status VARCHAR(24) NOT NULL DEFAULT 'pending',verified_at VARCHAR(40) NOT NULL DEFAULT '',observed_at VARCHAR(40) NOT NULL DEFAULT '',error_minutes DOUBLE NULL,absolute_error_minutes DOUBLE NULL,created_at VARCHAR(40) NOT NULL,UNIQUE KEY uq_radar_eta_prediction(device_id,location_key,prediction_key),KEY idx_radar_eta_verify(device_id,location_key,status,predicted_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "CREATE TABLE IF NOT EXISTS decision_verification_samples (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,device_id VARCHAR(191) NOT NULL,location_key VARCHAR(191) NOT NULL,verification_key VARCHAR(64) NOT NULL,kind VARCHAR(24) NOT NULL,subject VARCHAR(80) NOT NULL,starts_at VARCHAR(40) NOT NULL,ends_at VARCHAR(40) NOT NULL,recommendation_score DOUBLE NULL,payload_json MEDIUMTEXT NOT NULL,status VARCHAR(24) NOT NULL DEFAULT 'pending',observed_json MEDIUMTEXT NOT NULL,verified_at VARCHAR(40) NOT NULL DEFAULT '',created_at VARCHAR(40) NOT NULL,UNIQUE KEY uq_decision_verification(device_id,location_key,verification_key),KEY idx_decision_verify(device_id,location_key,status,starts_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "CREATE TABLE IF NOT EXISTS predictive_alert_verifications (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,device_id VARCHAR(191) NOT NULL,location_key VARCHAR(191) NOT NULL,event_key VARCHAR(96) NOT NULL,event_type VARCHAR(32) NOT NULL,predicted_at VARCHAR(40) NOT NULL,window_start VARCHAR(40) NOT NULL,window_end VARCHAR(40) NOT NULL,confidence INT NOT NULL DEFAULT 0,status VARCHAR(24) NOT NULL DEFAULT 'pending',outcome VARCHAR(32) NOT NULL DEFAULT '',observed_at VARCHAR(40) NOT NULL DEFAULT '',verified_at VARCHAR(40) NOT NULL DEFAULT '',created_at VARCHAR(40) NOT NULL,UNIQUE KEY uq_predictive_alert_verification(device_id,location_key,event_key),KEY idx_predictive_alert_verify(device_id,location_key,status,window_end)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "CREATE TABLE IF NOT EXISTS runtime_metrics (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,category VARCHAR(40) NOT NULL,metric_name VARCHAR(80) NOT NULL,status VARCHAR(24) NOT NULL,metric_value DOUBLE NULL,meta_json MEDIUMTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,KEY idx_runtime_metrics_name(category,metric_name,id),KEY idx_runtime_metrics_time(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"] :["CREATE TABLE IF NOT EXISTS radar_eta_predictions (id INTEGER PRIMARY KEY AUTOINCREMENT,device_id TEXT NOT NULL,location_key TEXT NOT NULL,prediction_key TEXT NOT NULL,issued_at TEXT NOT NULL,predicted_at TEXT NOT NULL,eta_minutes INTEGER NOT NULL,tolerance_minutes INTEGER NOT NULL DEFAULT 10,confidence INTEGER NOT NULL DEFAULT 0,status TEXT NOT NULL DEFAULT 'pending',verified_at TEXT NOT NULL DEFAULT '',observed_at TEXT NOT NULL DEFAULT '',error_minutes REAL NULL,absolute_error_minutes REAL NULL,created_at TEXT NOT NULL,UNIQUE(device_id,location_key,prediction_key))", "CREATE INDEX IF NOT EXISTS idx_radar_eta_verify ON radar_eta_predictions(device_id,location_key,status,predicted_at)", "CREATE TABLE IF NOT EXISTS decision_verification_samples (id INTEGER PRIMARY KEY AUTOINCREMENT,device_id TEXT NOT NULL,location_key TEXT NOT NULL,verification_key TEXT NOT NULL,kind TEXT NOT NULL,subject TEXT NOT NULL,starts_at TEXT NOT NULL,ends_at TEXT NOT NULL,recommendation_score REAL NULL,payload_json TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'pending',observed_json TEXT NOT NULL DEFAULT '',verified_at TEXT NOT NULL DEFAULT '',created_at TEXT NOT NULL,UNIQUE(device_id,location_key,verification_key))", "CREATE INDEX IF NOT EXISTS idx_decision_verify ON decision_verification_samples(device_id,location_key,status,starts_at)", "CREATE TABLE IF NOT EXISTS predictive_alert_verifications (id INTEGER PRIMARY KEY AUTOINCREMENT,device_id TEXT NOT NULL,location_key TEXT NOT NULL,event_key TEXT NOT NULL,event_type TEXT NOT NULL,predicted_at TEXT NOT NULL,window_start TEXT NOT NULL,window_end TEXT NOT NULL,confidence INTEGER NOT NULL DEFAULT 0,status TEXT NOT NULL DEFAULT 'pending',outcome TEXT NOT NULL DEFAULT '',observed_at TEXT NOT NULL DEFAULT '',verified_at TEXT NOT NULL DEFAULT '',created_at TEXT NOT NULL,UNIQUE(device_id,location_key,event_key))", "CREATE INDEX IF NOT EXISTS idx_predictive_alert_verify ON predictive_alert_verifications(device_id,location_key,status,window_end)", "CREATE TABLE IF NOT EXISTS runtime_metrics (id INTEGER PRIMARY KEY AUTOINCREMENT,category TEXT NOT NULL,metric_name TEXT NOT NULL,status TEXT NOT NULL,metric_value REAL NULL,meta_json TEXT NOT NULL,created_at TEXT NOT NULL)", "CREATE INDEX IF NOT EXISTS idx_runtime_metrics_name ON runtime_metrics(category,metric_name,id)", "CREATE INDEX IF NOT EXISTS idx_runtime_metrics_time ON runtime_metrics(created_at)"];
    foreach ($ddl as $sql)$pdo->exec($sql);
    $meta = $pdo->prepare('INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:key,:value,:updated) ON CONFLICT(meta_key) DO UPDATE SET meta_value=excluded.meta_value,updated_at=excluded.updated_at');
    $meta->execute([':key'=>'schema_version', ':value'=>'24', ':updated'=>gmdate('c')]);
}
function meteonexa_sync_trust_schema(PDO $pdo) : void {
    $mysql = meteonexa_pdo_driver($pdo)==='mysql';
    if (meteonexa_db_table_exists($pdo, 'radar_eta_predictions')) {
        $columns = $mysql ?['algorithm'=>"ALTER TABLE radar_eta_predictions ADD COLUMN algorithm VARCHAR(32) NOT NULL DEFAULT 'radar-v2'", 'ground_truth_source'=>"ALTER TABLE radar_eta_predictions ADD COLUMN ground_truth_source VARCHAR(64) NOT NULL DEFAULT ''", 'ground_truth_quality'=>"ALTER TABLE radar_eta_predictions ADD COLUMN ground_truth_quality INT NOT NULL DEFAULT 0", 'ground_truth_json'=>"ALTER TABLE radar_eta_predictions ADD COLUMN ground_truth_json MEDIUMTEXT NOT NULL", 'verification_method'=>"ALTER TABLE radar_eta_predictions ADD COLUMN verification_method VARCHAR(48) NOT NULL DEFAULT ''",] :['algorithm'=>"ALTER TABLE radar_eta_predictions ADD COLUMN algorithm TEXT NOT NULL DEFAULT 'radar-v2'", 'ground_truth_source'=>"ALTER TABLE radar_eta_predictions ADD COLUMN ground_truth_source TEXT NOT NULL DEFAULT ''", 'ground_truth_quality'=>"ALTER TABLE radar_eta_predictions ADD COLUMN ground_truth_quality INTEGER NOT NULL DEFAULT 0", 'ground_truth_json'=>"ALTER TABLE radar_eta_predictions ADD COLUMN ground_truth_json TEXT NOT NULL DEFAULT ''", 'verification_method'=>"ALTER TABLE radar_eta_predictions ADD COLUMN verification_method TEXT NOT NULL DEFAULT ''",];
        foreach ($columns as $name=>$ddl) if (!meteonexa_db_column_exists($pdo, 'radar_eta_predictions', $name))$pdo->exec($ddl);
        try {
            $pdo->exec($mysql ? "CREATE INDEX idx_radar_eta_algorithm ON radar_eta_predictions(device_id,location_key,algorithm,status,predicted_at)" : "CREATE INDEX IF NOT EXISTS idx_radar_eta_algorithm ON radar_eta_predictions(device_id,location_key,algorithm,status,predicted_at)");
        } catch (Throwable $ignored) {
        }
    }
    if (meteonexa_db_table_exists($pdo, 'runtime_metrics')) {
        if (!meteonexa_db_column_exists($pdo, 'runtime_metrics', 'trace_id'))$pdo->exec($mysql ? "ALTER TABLE runtime_metrics ADD COLUMN trace_id VARCHAR(64) NOT NULL DEFAULT ''" : "ALTER TABLE runtime_metrics ADD COLUMN trace_id TEXT NOT NULL DEFAULT ''");
        if (!meteonexa_db_column_exists($pdo, 'runtime_metrics', 'duration_ms'))$pdo->exec($mysql ? "ALTER TABLE runtime_metrics ADD COLUMN duration_ms DOUBLE NULL" : "ALTER TABLE runtime_metrics ADD COLUMN duration_ms REAL NULL");
        try {
            $pdo->exec($mysql ? "CREATE INDEX idx_runtime_metrics_trace ON runtime_metrics(trace_id,id)" : "CREATE INDEX IF NOT EXISTS idx_runtime_metrics_trace ON runtime_metrics(trace_id,id)");
        } catch (Throwable $ignored) {
        }
    }
    if ($mysql) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS predictive_alert_opportunities (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,device_id VARCHAR(191) NOT NULL,location_key VARCHAR(191) NOT NULL,opportunity_key VARCHAR(96) NOT NULL,event_type VARCHAR(32) NOT NULL,window_start VARCHAR(40) NOT NULL,window_end VARCHAR(40) NOT NULL,predicted TINYINT(1) NOT NULL DEFAULT 0,confidence INT NOT NULL DEFAULT 0,raw_score DOUBLE NOT NULL DEFAULT 0,observed TINYINT(1) NULL,outcome VARCHAR(8) NOT NULL DEFAULT '',evidence_json MEDIUMTEXT NOT NULL,status VARCHAR(32) NOT NULL DEFAULT 'pending',observed_at VARCHAR(40) NOT NULL DEFAULT '',verified_at VARCHAR(40) NOT NULL DEFAULT '',created_at VARCHAR(40) NOT NULL,UNIQUE KEY uq_predictive_opportunity(device_id,location_key,opportunity_key),KEY idx_predictive_opportunity_verify(device_id,location_key,status,window_end),KEY idx_predictive_opportunity_type(event_type,status,verified_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS predictive_alert_opportunities (id INTEGER PRIMARY KEY AUTOINCREMENT,device_id TEXT NOT NULL,location_key TEXT NOT NULL,opportunity_key TEXT NOT NULL,event_type TEXT NOT NULL,window_start TEXT NOT NULL,window_end TEXT NOT NULL,predicted INTEGER NOT NULL DEFAULT 0,confidence INTEGER NOT NULL DEFAULT 0,raw_score REAL NOT NULL DEFAULT 0,observed INTEGER NULL,outcome TEXT NOT NULL DEFAULT '',evidence_json TEXT NOT NULL DEFAULT '',status TEXT NOT NULL DEFAULT 'pending',observed_at TEXT NOT NULL DEFAULT '',verified_at TEXT NOT NULL DEFAULT '',created_at TEXT NOT NULL,UNIQUE(device_id,location_key,opportunity_key))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_predictive_opportunity_verify ON predictive_alert_opportunities(device_id,location_key,status,window_end)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_predictive_opportunity_type ON predictive_alert_opportunities(event_type,status,verified_at)");
    }
    $sql = $mysql ? "INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:key,:value,:updated) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=VALUES(updated_at)" : "INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:key,:value,:updated) ON CONFLICT(meta_key) DO UPDATE SET meta_value=excluded.meta_value,updated_at=excluded.updated_at";
    $meta = $pdo->prepare($sql);
    $meta->execute([':key'=>'schema_version', ':value'=>'25', ':updated'=>gmdate('c')]);
}
