<?php
declare(strict_types=1);

return [
    'version' => 18,
    'name' => 'device-login-challenges',
    'drivers' => ['mysql'],
    'up' => static function (PDO $pdo, int $schemaVersion, string $driver): int {
        if ($driver !== 'mysql' || $schemaVersion >= 18) {
            return $schemaVersion;
        }

        // Device-bound email-login challenges. This migration is deliberately
        // non-fatal: shared-hosting deployments can briefly expose a partially
        // migrated schema while files are being replaced. request-code.php has
        // a protected filesystem challenge fallback, so login must stay usable.
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS auth_login_challenges (
                challenge_hash VARCHAR(128) PRIMARY KEY,
                email_hash VARCHAR(128) NOT NULL,
                device_id VARCHAR(191) NOT NULL,
                language VARCHAR(8) NOT NULL DEFAULT 'it',
                code_hash VARCHAR(255) NOT NULL,
                sent_at BIGINT NOT NULL,
                expires_at BIGINT NOT NULL,
                attempts INT NOT NULL DEFAULT 0,
                ip_hash VARCHAR(128) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                KEY idx_auth_login_challenges_email(email_hash,sent_at),
                KEY idx_auth_login_challenges_device(device_id,sent_at),
                KEY idx_auth_login_challenges_expires(expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $meta=$pdo->prepare('INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:key,:value,:updated) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=VALUES(updated_at)');
            $meta->execute([':key'=>'schema_version',':value'=>'18',':updated'=>gmdate('c')]);
            $schemaVersion=18;
        } catch (Throwable $authChallengeMigrationError) {
            if (function_exists('meteonexa_log_event')) meteonexa_log_event('auth_challenge_migration_degraded', $authChallengeMigrationError);
        }

        return $schemaVersion;
    },
];
