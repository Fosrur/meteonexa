<?php
declare(strict_types=1);

return [
    'version' => 19,
    'name' => 'access-history',
    'drivers' => ['mysql'],
    'up' => static function (PDO $pdo, int $schemaVersion, string $driver): int {
        if ($driver !== 'mysql' || $schemaVersion >= 19) {
            return $schemaVersion;
        }

        // Access-history is an audit/convenience surface and must never make
        // OTP unavailable. Challenge and history DDL are attempted separately
        // and schema metadata is advanced only when both tables are present.
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS auth_login_challenges (
                challenge_hash VARCHAR(128) PRIMARY KEY, email_hash VARCHAR(128) NOT NULL, device_id VARCHAR(191) NOT NULL,
                language VARCHAR(8) NOT NULL DEFAULT 'it', code_hash VARCHAR(255) NOT NULL, sent_at BIGINT NOT NULL,
                expires_at BIGINT NOT NULL, attempts INT NOT NULL DEFAULT 0, ip_hash VARCHAR(128) NOT NULL, updated_at VARCHAR(40) NOT NULL,
                KEY idx_auth_login_challenges_email(email_hash,sent_at), KEY idx_auth_login_challenges_device(device_id,sent_at), KEY idx_auth_login_challenges_expires(expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $authChallengeMigrationError) {
            if (function_exists('meteonexa_log_event')) meteonexa_log_event('auth_challenge_schema_degraded', $authChallengeMigrationError);
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS auth_access_history (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, session_hash VARCHAR(128) NOT NULL, email_hash VARCHAR(128) NOT NULL,
                device_id VARCHAR(191) NOT NULL, device_type VARCHAR(40) NOT NULL DEFAULT 'Web', platform VARCHAR(80) NOT NULL DEFAULT '',
                browser VARCHAR(80) NOT NULL DEFAULT '', client_mode VARCHAR(16) NOT NULL DEFAULT 'web', timezone VARCHAR(80) NOT NULL DEFAULT '',
                ip_encrypted TEXT NOT NULL, location_label VARCHAR(191) NOT NULL DEFAULT '', created_at BIGINT NOT NULL, last_seen_at BIGINT NOT NULL,
                ended_at BIGINT NOT NULL DEFAULT 0, end_reason VARCHAR(32) NOT NULL DEFAULT '',
                KEY idx_auth_access_email(email_hash,last_seen_at), KEY idx_auth_access_session(session_hash), KEY idx_auth_access_device(device_id,last_seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $accessHistoryMigrationError) {
            if (function_exists('meteonexa_log_event')) meteonexa_log_event('auth_access_history_schema_degraded', $accessHistoryMigrationError);
        }
        try {
            if (meteonexa_db_table_exists($pdo, 'auth_login_challenges') && meteonexa_db_table_exists($pdo, 'auth_access_history')) {
                $meta=$pdo->prepare('INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:key,:value,:updated) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=VALUES(updated_at)');
                $meta->execute([':key'=>'schema_version',':value'=>'19',':updated'=>gmdate('c')]);
                $schemaVersion=19;
            }
        } catch (Throwable $schemaMetadataError) {
            if (function_exists('meteonexa_log_event')) meteonexa_log_event('auth_schema_metadata_degraded', $schemaMetadataError);
        }

        return $schemaVersion;
    },
];
